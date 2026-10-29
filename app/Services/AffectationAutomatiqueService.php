<?php

namespace App\Services;

use App\Models\AffectationGroupe;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Modules\Schedule\Models\Group;
use App\Services\Affectations\AssignmentSimulationService;
use Throwable;

/**
 * Affectation automatique après validation d'inscription (LOOP) :
 * systématique quand possible, jamais bloquante pour l'inscription.
 *
 * Réutilise UNIQUEMENT le moteur existant (`AffectationService::controler`
 * + `affecter` transactionnel/idempotent/verrouillé) — aucun second
 * moteur, aucun niveau inventé (le niveau vient de l'inscription validée
 * et du dossier officiel). TOUS les groupes compatibles sont évalués,
 * le moins rempli est choisi (tie-break id, déterministe).
 *
 * Statuts : ASSIGNED | EXISTING | LOCKED | NOT_ASSIGNED | NOT_ELIGIBLE | ERROR.
 * Raisons : codes métier explicites (NO_COMPATIBLE_GROUP, CAPACITY_EXCEEDED,
 * EXISTING_ASSIGNMENT, LOCKED_ASSIGNMENT, NOT_ELIGIBLE, SCOPE_RESTRICTION,
 * MISSING_ACADEMIC_DATA, SYSTEM_ERROR).
 */
final class AffectationAutomatiqueService
{
    public function __construct(
        private readonly AffectationService $affectations,
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
        private readonly InscriptionService $inscriptions,
    ) {}

    /**
     * @return array{statut: string, raison: string, message: string, groupe_id: ?int, groupe_code: ?string, candidats: array<int, string>, exclus: array<int, array{code_groupe: string, code: string, message: string}>, manque: int, groupes_supplementaires: int}
     */
    public function tenter(InscriptionAcademique $inscription, ?int $auteurId, ?int $faculteRestreinteId = null): array
    {
        try {
            $inscription->loadMissing(['etudiant', 'anneeUniversitaire']);
            $etudiant = $inscription->etudiant;
            $annee = $inscription->anneeUniversitaire;

            if ($etudiant === null || $annee === null) {
                return $this->echec($inscription, $etudiant, $auteurId, 'NOT_ELIGIBLE', 'MISSING_ACADEMIC_DATA', 'Inscription ou année introuvable : affectation impossible.');
            }

            if ($faculteRestreinteId !== null && (int) $etudiant->faculte_id !== $faculteRestreinteId) {
                return $this->echec($inscription, $etudiant, $auteurId, 'NOT_ELIGIBLE', 'SCOPE_RESTRICTION', 'Hors périmètre faculté : affectation non tentée.');
            }

            if ($inscription->statut !== 'valide') {
                return $this->echec($inscription, $etudiant, $auteurId, 'NOT_ELIGIBLE', 'NOT_ELIGIBLE', "Inscription « {$inscription->statut} » : seule une inscription validée déclenche l'affectation automatique.");
            }

            if ($etudiant->statut !== 'valide') {
                return $this->echec($inscription, $etudiant, $auteurId, 'NOT_ELIGIBLE', 'NOT_ELIGIBLE', "Dossier étudiant « {$etudiant->statut} » : affectation impossible.");
            }

            if ($etudiant->estSuspendu()) {
                return $this->echec($inscription, $etudiant, $auteurId, 'NOT_ELIGIBLE', 'ETUDIANT_SUSPENDU', 'Étudiant suspendu : affectation impossible.');
            }

            $existante = AffectationGroupe::where('etudiant_id', $etudiant->id)
                ->where('annee_universitaire_id', $annee->id)
                ->where('statut', 'active')
                ->first();

            if ($existante !== null && (bool) $existante->verrouille) {
                $this->audit->tracer($etudiant, 'affectation.auto_verrouillee', 'affectation', $existante->id, null, [
                    'inscription_id' => $inscription->id, 'annee' => $annee->libelle, 'groupe' => $existante->groupe?->code,
                ]);

                return [
                    'statut' => 'LOCKED', 'raison' => 'LOCKED_ASSIGNMENT',
                    'message' => "Affectation verrouillée ({$existante->groupe?->code}) conservée : aucune modification automatique.",
                    'groupe_id' => $existante->groupe_id, 'groupe_code' => $existante->groupe?->code,
                    'candidats' => [], 'exclus' => [], 'manque' => 0, 'groupes_supplementaires' => 0,
                ];
            }

            if ($existante !== null) {
                $this->audit->tracer($etudiant, 'affectation.auto_existante', 'affectation', $existante->id, null, [
                    'inscription_id' => $inscription->id, 'annee' => $annee->libelle, 'groupe' => $existante->groupe?->code,
                ]);

                return [
                    'statut' => 'EXISTING', 'raison' => 'EXISTING_ASSIGNMENT',
                    'message' => "Déjà affecté au groupe {$existante->groupe?->code} : aucune nouvelle affectation.",
                    'groupe_id' => $existante->groupe_id, 'groupe_code' => $existante->groupe?->code,
                    'candidats' => [], 'exclus' => [], 'manque' => 0, 'groupes_supplementaires' => 0,
                ];
            }

            // Niveau officiel uniquement : inscription puis dossier —
            // jamais d'incrément aveugle (L1+1=L2 interdit).
            $niveau = $inscription->niveau ?: $etudiant->niveau;

            if (trim((string) $niveau) === '') {
                return $this->echec($inscription, $etudiant, $auteurId, 'NOT_ELIGIBLE', 'MISSING_ACADEMIC_DATA', 'Niveau officiel introuvable (inscription + dossier) : affectation impossible sans niveau validé.');
            }

            $groupes = Group::where('actif', true)
                ->where('faculte_id', $etudiant->faculte_id)
                ->where(fn ($q) => $q->whereNull('annee_universitaire_id')->orWhere('annee_universitaire_id', $annee->id))
                ->orderBy('id')
                ->get();

            if ($groupes->isEmpty()) {
                return $this->echec($inscription, $etudiant, $auteurId, 'NOT_ASSIGNED', 'NO_COMPATIBLE_GROUP',
                    "Aucun groupe compatible avec : année {$annee->libelle}, faculté, formation, niveau {$niveau}. Aucun groupe créé automatiquement.");
            }

            $compatibles = [];
            $exclus = [];

            foreach ($groupes as $groupe) {
                if ($faculteRestreinteId !== null && (int) $groupe->faculte_id !== $faculteRestreinteId) {
                    $exclus[] = ['code_groupe' => $groupe->code, 'code' => 'FACULTY_SCOPE', 'message' => 'Hors périmètre faculté.'];

                    continue;
                }

                $bloquantes = array_values(array_filter(
                    $this->affectations->controler($etudiant, $groupe, $annee),
                    fn (array $c) => $c['bloquant']
                ));

                if ($bloquantes === []) {
                    $compatibles[] = $groupe;
                } else {
                    foreach ($bloquantes as $contrainte) {
                        $exclus[] = ['code_groupe' => $groupe->code, 'code' => $contrainte['code'], 'message' => $contrainte['message']];
                    }
                }
            }

            $candidats = $compatibles === [] ? [] : array_map(fn (Group $g) => $g->code, $compatibles);

            if ($compatibles === []) {
                $toutComplet = $exclus !== [] && collect($exclus)->every(fn (array $e) => in_array($e['code'], ['GROUP_FULL', 'CAPACITY'], true));

                if ($toutComplet) {
                    $plan = app(AssignmentSimulationService::class)->planCapacite($groupes, 1);

                    $this->audit->tracer($etudiant, 'affectation.auto_non_assignee', 'inscription', $inscription->id, null, [
                        'inscription_id' => $inscription->id, 'annee' => $annee->libelle,
                        'raison' => 'CAPACITY_EXCEEDED', 'exclus' => $exclus,
                    ]);
                    $this->notifierAttente($etudiant, $inscription, 'Tous les groupes compatibles sont complets.');

                    return [
                        'statut' => 'NOT_ASSIGNED', 'raison' => 'CAPACITY_EXCEEDED',
                        'message' => 'Tous les groupes compatibles sont complets. Capacité manquante : 1 place'
                            .($plan['groupes_supplementaires'] > 0 ? " — {$plan['groupes_supplementaires']} groupe(s) supplémentaire(s) nécessaire(s)." : '.'),
                        'groupe_id' => null, 'groupe_code' => null, 'candidats' => [], 'exclus' => $exclus,
                        'manque' => 1, 'groupes_supplementaires' => $plan['groupes_supplementaires'],
                    ];
                }

                $this->audit->tracer($etudiant, 'affectation.auto_non_assignee', 'inscription', $inscription->id, null, [
                    'inscription_id' => $inscription->id, 'annee' => $annee->libelle,
                    'raison' => 'NO_COMPATIBLE_GROUP', 'exclus' => $exclus,
                ]);
                $this->notifierAttente($etudiant, $inscription, 'Aucun groupe compatible trouvé.');

                return [
                    'statut' => 'NOT_ASSIGNED', 'raison' => 'NO_COMPATIBLE_GROUP',
                    'message' => "Aucun groupe compatible avec : année {$annee->libelle}, faculté, formation, niveau {$niveau}.",
                    'groupe_id' => null, 'groupe_code' => null, 'candidats' => [], 'exclus' => $exclus,
                    'manque' => 0, 'groupes_supplementaires' => 0,
                ];
            }

            // Équilibrage : moins rempli d'abord, tie-break id (déterministe).
            $effectifs = $this->affectations->contexteBulk([$etudiant->id], array_map(fn (Group $g) => $g->id, $compatibles), $annee)['effectifs'];
            usort($compatibles, fn (Group $a, Group $b) => [($effectifs[$a->id] ?? 0), $a->id] <=> [($effectifs[$b->id] ?? 0), $b->id]);

            $erreurs = [];

            foreach ($compatibles as $groupe) {
                try {
                    $affectation = $this->affectations->affecter(
                        $etudiant->fresh(), $groupe, $annee, $auteurId,
                        "Affectation automatique après validation de l'inscription #{$inscription->id}."
                    );

                    $this->audit->tracer($etudiant, 'affectation.auto_assignee', 'affectation', $affectation->id, null, [
                        'inscription_id' => $inscription->id, 'annee' => $annee->libelle,
                        'groupe' => $groupe->code, 'candidats' => $candidats,
                    ]);

                    return [
                        'statut' => 'ASSIGNED', 'raison' => 'ASSIGNED',
                        'message' => "Groupe attribué : {$groupe->code}.",
                        'groupe_id' => $groupe->id, 'groupe_code' => $groupe->code,
                        'candidats' => $candidats, 'exclus' => $exclus,
                        'manque' => 0, 'groupes_supplementaires' => 0,
                    ];
                } catch (Throwable $e) {
                    // Course perdue (groupe devenu complet) : prochain candidat.
                    $erreurs[] = ['code_groupe' => $groupe->code, 'code' => 'CAPACITY_RACE', 'message' => $e->getMessage()];
                }
            }

            $this->audit->tracer($etudiant, 'affectation.auto_non_assignee', 'inscription', $inscription->id, null, [
                'inscription_id' => $inscription->id, 'annee' => $annee->libelle,
                'raison' => 'CAPACITY_EXCEEDED', 'erreurs' => array_column($erreurs, 'message'),
            ]);
            $this->notifierAttente($etudiant, $inscription, 'Capacité épuisée pendant la validation.');

            return [
                'statut' => 'NOT_ASSIGNED', 'raison' => 'CAPACITY_EXCEEDED',
                'message' => 'Tous les groupes compatibles sont devenus complets pendant la validation.',
                'groupe_id' => null, 'groupe_code' => null, 'candidats' => $candidats,
                'exclus' => array_merge($exclus, $erreurs), 'manque' => 1, 'groupes_supplementaires' => 0,
            ];
        } catch (Throwable $e) {
            report($e);

            $etudiantSain = isset($etudiant) && $etudiant instanceof Etudiant && $etudiant->exists
                ? $etudiant
                : InscriptionAcademique::find($inscription->id)?->etudiant;

            if ($etudiantSain !== null) {
                $this->audit->tracer($etudiantSain, 'affectation.auto_erreur', 'inscription', $inscription->id, null, [
                    'inscription_id' => $inscription->id, 'erreur' => mb_substr($e->getMessage(), 0, 200),
                ]);
            }

            return [
                'statut' => 'ERROR', 'raison' => 'SYSTEM_ERROR',
                'message' => 'Affectation à traiter : erreur technique (inscription conservée).',
                'groupe_id' => null, 'groupe_code' => null, 'candidats' => [], 'exclus' => [],
                'manque' => 0, 'groupes_supplementaires' => 0,
            ];
        }
    }

    /**
     * Relance manuelle (§26) : mêmes règles, jamais d'écrasement d'un
     * verrou sans exception explicite.
     *
     * @return array{statut: string, raison: string, message: string, groupe_id: ?int, groupe_code: ?string, candidats: array<int, string>, exclus: array<int, array{code_groupe: string, code: string, message: string}>, manque: int, groupes_supplementaires: int}
     */
    public function reessayer(InscriptionAcademique $inscription, ?int $auteurId, ?int $faculteRestreinteId = null): array
    {
        return $this->tenter($inscription, $auteurId, $faculteRestreinteId);
    }

    /**
     * Simulation d'affectation SANS écriture (LOOP 14) :
     * Évalue les contraintes et détermine le groupe compatible qui serait retenu
     * sans modifier la base de données ni créer d'affectation.
     *
     * @return array{statut: string, raison: string, message: string, groupe_id: ?int, groupe_code: ?string, candidats: array<int, string>, exclus: array<int, array{code_groupe: string, code: string, message: string}>, manque: int, groupes_supplementaires: int}
     */
    public function simuler(InscriptionAcademique $inscription, ?int $faculteRestreinteId = null): array
    {
        $inscription->loadMissing(['etudiant', 'anneeUniversitaire']);
        $etudiant = $inscription->etudiant;
        $annee = $inscription->anneeUniversitaire;

        if ($etudiant === null || $annee === null) {
            return [
                'statut' => 'NOT_ELIGIBLE', 'raison' => 'MISSING_ACADEMIC_DATA',
                'message' => 'Inscription ou année introuvable : simulation impossible.',
                'groupe_id' => null, 'groupe_code' => null, 'candidats' => [], 'exclus' => [],
                'manque' => 0, 'groupes_supplementaires' => 0,
            ];
        }

        if ($faculteRestreinteId !== null && (int) $etudiant->faculte_id !== $faculteRestreinteId) {
            return [
                'statut' => 'NOT_ELIGIBLE', 'raison' => 'SCOPE_RESTRICTION',
                'message' => 'Hors périmètre faculté : simulation non autorisée.',
                'groupe_id' => null, 'groupe_code' => null, 'candidats' => [], 'exclus' => [],
                'manque' => 0, 'groupes_supplementaires' => 0,
            ];
        }

        if ($etudiant->estSuspendu()) {
            return [
                'statut' => 'NOT_ELIGIBLE', 'raison' => 'ETUDIANT_SUSPENDU',
                'message' => 'Étudiant suspendu : affectation impossible.',
                'groupe_id' => null, 'groupe_code' => null, 'candidats' => [], 'exclus' => [],
                'manque' => 0, 'groupes_supplementaires' => 0,
            ];
        }

        $existante = AffectationGroupe::where('etudiant_id', $etudiant->id)
            ->where('annee_universitaire_id', $annee->id)
            ->where('statut', 'active')
            ->first();

        if ($existante !== null) {
            return [
                'statut' => 'EXISTING', 'raison' => 'EXISTING_ASSIGNMENT',
                'message' => "Déjà affecté au groupe {$existante->groupe?->code} (simulation : aucun changement).",
                'groupe_id' => $existante->groupe_id, 'groupe_code' => $existante->groupe?->code,
                'candidats' => [], 'exclus' => [], 'manque' => 0, 'groupes_supplementaires' => 0,
            ];
        }

        $niveau = $inscription->niveau ?: $etudiant->niveau;

        if (trim((string) $niveau) === '') {
            return [
                'statut' => 'NOT_ELIGIBLE', 'raison' => 'MISSING_ACADEMIC_DATA',
                'message' => 'Niveau officiel introuvable.',
                'groupe_id' => null, 'groupe_code' => null, 'candidats' => [], 'exclus' => [],
                'manque' => 0, 'groupes_supplementaires' => 0,
            ];
        }

        $groupes = Group::where('actif', true)
            ->where('faculte_id', $etudiant->faculte_id)
            ->where(fn ($q) => $q->whereNull('annee_universitaire_id')->orWhere('annee_universitaire_id', $annee->id))
            ->orderBy('id')
            ->get();

        if ($groupes->isEmpty()) {
            return [
                'statut' => 'NOT_ASSIGNED', 'raison' => 'NO_COMPATIBLE_GROUP',
                'message' => "Aucun groupe compatible avec : année {$annee->libelle}, faculté, formation, niveau {$niveau}.",
                'groupe_id' => null, 'groupe_code' => null, 'candidats' => [], 'exclus' => [],
                'manque' => 0, 'groupes_supplementaires' => 0,
            ];
        }

        $compatibles = [];
        $exclus = [];

        foreach ($groupes as $groupe) {
            if ($faculteRestreinteId !== null && (int) $groupe->faculte_id !== $faculteRestreinteId) {
                $exclus[] = ['code_groupe' => $groupe->code, 'code' => 'FACULTY_SCOPE', 'message' => 'Hors périmètre faculté.'];

                continue;
            }

            $bloquantes = array_values(array_filter(
                $this->affectations->controler($etudiant, $groupe, $annee),
                fn (array $c) => $c['bloquant']
            ));

            if ($bloquantes === []) {
                $compatibles[] = $groupe;
            } else {
                foreach ($bloquantes as $contrainte) {
                    $exclus[] = ['code_groupe' => $groupe->code, 'code' => $contrainte['code'], 'message' => $contrainte['message']];
                }
            }
        }

        $candidats = $compatibles === [] ? [] : array_map(fn (Group $g) => $g->code, $compatibles);

        if ($compatibles === []) {
            $toutComplet = $exclus !== [] && collect($exclus)->every(fn (array $e) => in_array($e['code'], ['GROUP_FULL', 'CAPACITY'], true));

            return [
                'statut' => 'NOT_ASSIGNED',
                'raison' => $toutComplet ? 'CAPACITY_EXCEEDED' : 'NO_COMPATIBLE_GROUP',
                'message' => $toutComplet
                    ? 'Tous les groupes compatibles sont complets.'
                    : "Aucun groupe compatible avec : année {$annee->libelle}, faculté, formation, niveau {$niveau}.",
                'groupe_id' => null, 'groupe_code' => null, 'candidats' => [], 'exclus' => $exclus,
                'manque' => $toutComplet ? 1 : 0, 'groupes_supplementaires' => 0,
            ];
        }

        // Équilibrage : moins rempli d'abord, tie-break id (déterministe).
        $effectifs = $this->affectations->contexteBulk([$etudiant->id], array_map(fn (Group $g) => $g->id, $compatibles), $annee)['effectifs'];
        usort($compatibles, fn (Group $a, Group $b) => [($effectifs[$a->id] ?? 0), $a->id] <=> [($effectifs[$b->id] ?? 0), $b->id]);

        $choisi = $compatibles[0];

        return [
            'statut' => 'SIMULATED', 'raison' => 'COMPATIBLE_GROUP_FOUND',
            'message' => "Simulation : le groupe {$choisi->code} serait attribué (effectif actuel : ".($effectifs[$choisi->id] ?? 0).').',
            'groupe_id' => $choisi->id, 'groupe_code' => $choisi->code,
            'candidats' => $candidats, 'exclus' => $exclus,
            'manque' => 0, 'groupes_supplementaires' => 0,
        ];
    }

    /**
     * Tente l'affectation automatique directement depuis le dossier étudiant (LOOP 14).
     * Résout l'inscription académique correspondante ou initialise une inscription si validée.
     *
     * @return array{statut: string, raison: string, message: string, groupe_id: ?int, groupe_code: ?string, candidats: array<int, string>, exclus: array<int, array{code_groupe: string, code: string, message: string}>, manque: int, groupes_supplementaires: int}
     */
    public function tenterPourEtudiant(Etudiant $etudiant, ?int $auteurId = null, ?int $faculteRestreinteId = null): array
    {
        $annee = AnneeUniversitaire::where('active', true)->first()
            ?? AnneeUniversitaire::where('libelle', $etudiant->annee_academique)->first()
            ?? AnneeUniversitaire::orderByDesc('date_debut')->first();

        if ($annee === null) {
            return [
                'statut' => 'NOT_ELIGIBLE', 'raison' => 'MISSING_ACADEMIC_DATA',
                'message' => 'Aucune année universitaire active configurée.',
                'groupe_id' => null, 'groupe_code' => null, 'candidats' => [], 'exclus' => [],
                'manque' => 0, 'groupes_supplementaires' => 0,
            ];
        }

        // Règle d'unicité annuelle centralisée (InscriptionService) :
        // lecture via la même source, création sous garde — une création
        // concurrente (double clic, double listener) fait retomber sur la
        // ligne gagnante, jamais de doublon ni d'erreur technique.
        $inscription = $this->inscriptions->inscriptionAnnuelle($etudiant->id, $annee->id);

        if ($inscription === null && $etudiant->statut === 'valide') {
            try {
                $inscription = $this->inscriptions->creerInscriptionAnnuelle(
                    (int) $etudiant->id,
                    (int) $annee->id,
                    [
                        'programme_id' => $etudiant->programme_id,
                        'niveau_id' => $etudiant->niveau_id,
                        'niveau' => $etudiant->niveau ?? 'L1',
                        'statut' => 'valide',
                        'type' => 'premiere',
                        'regime' => 'initiale',
                    ],
                );
            } catch (\DomainException) {
                // Course concurrence : l'autre requête a créé la ligne —
                // idempotence, on poursuit avec l'inscription existante.
                $inscription = $this->inscriptions->inscriptionAnnuelle($etudiant->id, $annee->id);
            }
        } elseif ($inscription !== null && $etudiant->statut === 'valide' && $inscription->statut === 'en_cours') {
            $inscription->update(['statut' => 'valide']);
        }

        if ($inscription === null) {
            return [
                'statut' => 'NOT_ELIGIBLE', 'raison' => 'NOT_ELIGIBLE',
                'message' => 'Dossier non validé : inscription académique absente.',
                'groupe_id' => null, 'groupe_code' => null, 'candidats' => [], 'exclus' => [],
                'manque' => 0, 'groupes_supplementaires' => 0,
            ];
        }

        return $this->tenter($inscription->fresh(), $auteurId, $faculteRestreinteId);
    }

    /** @return array{statut: string, raison: string, message: string, groupe_id: null, groupe_code: null, candidats: array<int, string>, exclus: array<int, array{code_groupe: string, code: string, message: string}>, manque: int, groupes_supplementaires: int} */
    private function echec(InscriptionAcademique $inscription, ?Etudiant $etudiant, ?int $auteurId, string $statut, string $raison, string $message): array
    {
        if ($etudiant !== null) {
            $this->audit->tracer($etudiant, 'affectation.auto_non_assignee', 'inscription', $inscription->id, null, [
                'inscription_id' => $inscription->id, 'raison' => $raison,
            ]);
        }

        return [
            'statut' => $statut, 'raison' => $raison, 'message' => $message,
            'groupe_id' => null, 'groupe_code' => null, 'candidats' => [], 'exclus' => [],
            'manque' => 0, 'groupes_supplementaires' => 0,
        ];
    }

    private function notifierAttente(Etudiant $etudiant, InscriptionAcademique $inscription, string $raison): void
    {
        try {
            $this->notifications->notifierEtudiant(
                $etudiant, 'affectation.en_attente', 'Inscription validée — affectation en attente',
                "Votre inscription {$inscription->anneeUniversitaire?->libelle} est validée. {$raison}",
                route('etudiant.dashboard'),
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
