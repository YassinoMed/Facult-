<?php

namespace App\Services;

use App\Models\AffectationProposal;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Modules\Attendance\Models\Presence;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\StudentArchive\Services\ArchiveTimelineRegistry;
use App\Modules\StudentDossiers\Models\StudentDossier;
use App\Services\Affectations\AffectationEligibilityService;
use Illuminate\Support\Collection;

/**
 * Dossier étudiant intelligent (§11) : résumé chiffré depuis les
 * données réelles, timeline unifiée, complétude, anomalies,
 * doublons potentiels. Aucun calcul navigateur.
 */
class FicheEtudiantService
{
    /** @var array<int, bool> Mémoïse l'existence d'une inscription active par étudiant (requête unique/fiche). */
    private array $inscriptionsActives = [];

    /** @var array<int, bool> Mémoïse l'existence d'un dossier documentaire par étudiant. */
    private array $dossiersDocumentaires = [];

    public function __construct(private AcademicCalculationService $calculs) {}

    private function aInscriptionActive(Etudiant $etudiant): bool
    {
        return $this->inscriptionsActives[(int) $etudiant->id]
            ??= $etudiant->inscriptionsAcademiques()
                ->whereIn('statut', ['en_cours', 'valide'])->exists();
    }

    private function aDossierDocumentaire(Etudiant $etudiant): bool
    {
        if (! class_exists(StudentDossier::class)) {
            return true;
        }

        return $this->dossiersDocumentaires[(int) $etudiant->id]
            ??= StudentDossier::where('etudiant_id', $etudiant->id)->exists();
    }

    /**
     * @return array{moyenne_generale: ?float, credits_obtenus: int, credits_total: int, annees_validees: int, inscriptions_actives: int, demandes_ouvertes: int, absences: int}
     */
    public function resume(Etudiant $etudiant): array
    {
        $inscriptions = $etudiant->inscriptionsAcademiques()->get();
        $synthese = $this->calculs->synthese($inscriptions);

        $demandesOuvertes = 0;
        if (config('modules.online-requests.active') && class_exists(OnlineRequest::class)) {
            $demandesOuvertes = OnlineRequest::where('etudiant_id', $etudiant->id)
                ->whereIn('status', ['submitted', 'received', 'in_review', 'waiting_student'])
                ->count();
        }

        $absences = 0;
        if (config('modules.attendance.active') && class_exists(Presence::class)) {
            $absences = Presence::where('etudiant_id', $etudiant->id)
                ->whereIn('statut', ['absent', 'retard'])
                ->count();
        }

        return [
            'moyenne_generale' => $synthese['moyenne_generale'],
            'credits_obtenus' => $synthese['credits_obtenus'],
            'credits_total' => $synthese['credits_total'],
            'annees_validees' => $synthese['annees_validees'],
            'inscriptions_actives' => $inscriptions->whereIn('statut', ['en_cours', 'valide'])->count(),
            'demandes_ouvertes' => $demandesOuvertes,
            'absences' => $absences,
        ];
    }

    /**
     * Timeline unifiée (Phase 8 §9) : déléguée au registre d'archive
     * (8 sources : inscriptions, situations, groupes, délibérations,
     * documents, demandes, absences — jamais de seconde source).
     * Forme legacy {date Y-m-d, titre, detail} pour la vue 360°.
     *
     * @return array<int, array{date: string, titre: string, detail: string}>
     */
    public function timeline(Etudiant $etudiant, int $limite = 30, int $offset = 0): array
    {
        $resultat = app(ArchiveTimelineRegistry::class)->evenements($etudiant, [
            'limite' => max(1, min(100, $limite)),
            'offset' => max(0, $offset),
        ]);

        return $resultat['elements']->map(fn (array $e) => [
            'date' => mb_substr((string) $e['date'], 0, 10),
            'titre' => (string) $e['titre'],
            'detail' => (string) ($e['detail'] ?? ''),
        ])->values()->all();
    }

    /**
     * @return array{pourcentage: int, items: array<int, array{libelle: string, ok: bool}>}
     */
    public function completude(Etudiant $etudiant): array
    {
        $aInscriptionActive = $this->aInscriptionActive($etudiant);
        $aDossier = $this->aDossierDocumentaire($etudiant);

        $items = [
            ['libelle' => 'Identité (nom, prénom, CIN ou email)', 'ok' => $etudiant->nom !== null && $etudiant->prenom !== null && ($etudiant->cin !== null || $etudiant->email !== null)],
            ['libelle' => 'Contact (email, téléphone)', 'ok' => $etudiant->email !== null && $etudiant->telephone !== null],
            ['libelle' => 'Affectation groupe', 'ok' => $etudiant->groupe_id !== null],
            ['libelle' => 'Programme', 'ok' => $etudiant->programme_id !== null],
            ['libelle' => 'Inscription active', 'ok' => $aInscriptionActive],
            ['libelle' => 'Dossier documentaire', 'ok' => $aDossier],
        ];

        $ok = count(array_filter($items, fn (array $i) => $i['ok']));

        return ['pourcentage' => (int) round($ok / count($items) * 100), 'items' => $items];
    }

    /** @return array<int, array{message: string, action: string, lien: string}> */
    public function anomalies(Etudiant $etudiant): array
    {
        $anomalies = [];
        $inscriptionActive = $this->aInscriptionActive($etudiant);

        if (! $inscriptionActive) {
            $anomalies[] = [
                'message' => 'Aucune inscription active.',
                'action' => 'Créer une inscription',
                'lien' => route('admin.archives.create'),
            ];
        }

        if ($etudiant->groupe_id === null) {
            $anomalies[] = [
                'message' => 'Étudiant sans groupe : planning indisponible.',
                'action' => 'Affecter à un groupe',
                'lien' => route('admin.etudiants.show', $etudiant).'#parcours',
            ];
        }

        if ($etudiant->statut === 'valide' && ! $inscriptionActive) {
            $anomalies[] = [
                'message' => 'Statut validé mais sans inscription active (incohérence).',
                'action' => 'Vérifier les inscriptions',
                'lien' => route('admin.etudiants.show', $etudiant).'#parcours',
            ];
        }

        if ($etudiant->programme_id === null) {
            $anomalies[] = [
                'message' => 'Programme non renseigné.',
                'action' => 'Compléter le dossier',
                'lien' => route('admin.etudiants.show', $etudiant),
            ];
        }

        return $anomalies;
    }

    /**
     * Doublons potentiels : JAMAIS de fusion automatique — proposition
     * de comparaison à un agent autorisé. $faculteId restreint
     * l'affichage au périmètre de l'agent (anti-fuite inter-facultés) ;
     * null = global (moteur de vérification central).
     *
     * @return Collection<int, Etudiant>
     */
    public function doublonsPotentiels(Etudiant $etudiant, ?int $faculteId = null): Collection
    {
        $cinNorm = mb_strtoupper(preg_replace('/\s+/', '', (string) $etudiant->cin) ?? '');
        $emailNorm = mb_strtolower(trim((string) $etudiant->email));
        $nomNorm = mb_strtoupper(trim((string) $etudiant->nom));
        $prenomNorm = mb_strtoupper(trim((string) $etudiant->prenom));

        return Etudiant::where('id', '!=', $etudiant->id)
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->where(function ($q) use ($etudiant, $cinNorm, $emailNorm, $nomNorm, $prenomNorm) {
                if ($cinNorm !== '') {
                    $q->whereRaw("REPLACE(UPPER(TRIM(cin)), ' ', '') = ?", [$cinNorm]);
                }
                if ($emailNorm !== '') {
                    $q->orWhereRaw('LOWER(TRIM(email)) = ?', [$emailNorm]);
                }
                if ($etudiant->telephone !== null && trim((string) $etudiant->telephone) !== '') {
                    $q->orWhere('telephone', $etudiant->telephone);
                }
                if ($nomNorm !== '' && $prenomNorm !== '' && $etudiant->date_naissance !== null) {
                    $q->orWhere(function ($w) use ($nomNorm, $prenomNorm, $etudiant) {
                        $w->whereRaw('UPPER(TRIM(nom)) = ?', [$nomNorm])
                            ->whereRaw('UPPER(TRIM(prenom)) = ?', [$prenomNorm])
                            ->whereDate('date_naissance', $etudiant->date_naissance);
                    });
                }
            })
            ->limit(5)
            ->get();
    }

    /**
     * Éligibilité administrative et état d'affectation (Phase 22).
     * Contrôle les 5 piliers d'éligibilité + statut d'affectation (groupe / proposition / bloquée).
     *
     * @return array{eligible: bool, blocking_reasons: array<int, string>, warnings: array<int, string>, statut_affectation: array<string, mixed>, axes: array<string, array<string, mixed>>}
     */
    public function eligibiliteAffectation(Etudiant $etudiant, ?AnneeUniversitaire $annee = null): array
    {
        /** @var AffectationEligibilityService $service */
        $service = app(AffectationEligibilityService::class);
        $eval = $service->evaluer($etudiant, $annee);

        $etudiant->loadMissing('groupe');
        $statutAffectation = [
            'etat' => 'non_affecte',
            'badge' => 'slate',
            'label' => 'Non affecté',
            'icone' => 'circle',
            'detail' => 'En attente d\'affectation',
        ];

        if ($etudiant->groupe_id !== null) {
            $statutAffectation = [
                'etat' => 'affecte',
                'badge' => 'emerald',
                'label' => ($etudiant->groupe?->code ?? 'Groupe #'.$etudiant->groupe_id),
                'icone' => 'check',
                'detail' => $etudiant->groupe?->nom ?? 'Affectation active',
            ];
        } else {
            // Proposition en attente ?
            $proposition = AffectationProposal::where('student_id', $etudiant->id)
                ->where('statut', AffectationProposal::STATUT_EN_ATTENTE)
                ->with('proposedGroup')
                ->latest()
                ->first();

            if ($proposition) {
                $statutAffectation = [
                    'etat' => 'proposition',
                    'badge' => 'sky',
                    'label' => 'Proposition : '.($proposition->proposedGroup?->code ?? '#'.$proposition->proposed_group_id),
                    'icone' => 'clock',
                    'detail' => 'Score : '.$proposition->score.'/100 (validation requise)',
                ];
            } elseif (! $eval['eligible']) {
                $statutAffectation = [
                    'etat' => 'bloquee',
                    'badge' => 'rose',
                    'label' => 'Bloquée ('.count($eval['blocking_reasons']).' anomalie(s))',
                    'icone' => 'cross',
                    'detail' => implode(', ', array_slice($eval['blocking_reasons'], 0, 2)),
                ];
            }
        }

        $axes = [
            'identite' => [
                'label' => 'Identité',
                'valide' => $eval['checks']['identity'],
                'etat' => $eval['statuses']['identity'],
                'texte' => $eval['checks']['identity'] ? 'Validée' : 'Incomplète',
                'badge' => $eval['checks']['identity'] ? 'emerald' : 'rose',
                'detail' => $eval['details']['identity']['message'] ?? '',
            ],
            'contact' => [
                'label' => 'Contact',
                'valide' => $eval['checks']['contact'],
                'etat' => $eval['statuses']['contact'],
                'texte' => $eval['checks']['contact'] ? 'Validé' : ($eval['statuses']['contact'] === 'CONTACT_INCOMPLETE' ? 'Partiel' : 'Manquant'),
                'badge' => $eval['checks']['contact'] ? 'emerald' : ($eval['statuses']['contact'] === 'CONTACT_INCOMPLETE' ? 'amber' : 'rose'),
                'detail' => $eval['details']['contact']['message'] ?? '',
            ],
            'programme' => [
                'label' => 'Programme',
                'valide' => $eval['checks']['program'],
                'etat' => $eval['statuses']['program'],
                'texte' => $eval['checks']['program'] ? 'Validé' : 'Incomplet',
                'badge' => $eval['checks']['program'] ? 'emerald' : 'rose',
                'detail' => $eval['details']['program']['message'] ?? '',
            ],
            'inscription' => [
                'label' => 'Inscription',
                'valide' => $eval['checks']['registration'],
                'etat' => $eval['statuses']['registration'],
                'texte' => $eval['checks']['registration'] ? 'Active' : 'Inactive / Absente',
                'badge' => $eval['checks']['registration'] ? 'emerald' : 'rose',
                'detail' => $eval['details']['registration']['message'] ?? '',
            ],
            'documents' => [
                'label' => 'Documents',
                'valide' => $eval['checks']['documents'],
                'etat' => $eval['statuses']['documents'],
                'texte' => $eval['checks']['documents'] ? 'Complets' : 'Incomplets',
                'badge' => $eval['checks']['documents'] ? 'emerald' : 'rose',
                'detail' => $eval['details']['documents']['message'] ?? '',
            ],
            'affectation' => [
                'label' => 'Affectation',
                'valide' => $statutAffectation['etat'] === 'affecte',
                'etat' => $statutAffectation['etat'],
                'texte' => $statutAffectation['label'],
                'badge' => $statutAffectation['badge'],
                'detail' => $statutAffectation['detail'],
            ],
        ];

        return [
            'eligible' => $eval['eligible'],
            'blocking_reasons' => $eval['blocking_reasons'],
            'warnings' => $eval['warnings'],
            'statut_affectation' => $statutAffectation,
            'axes' => $axes,
        ];
    }
}
