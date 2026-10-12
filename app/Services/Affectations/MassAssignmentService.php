<?php

namespace App\Services\Affectations;

use App\Models\AffectationMasseLot;
use App\Models\AffectationProposal;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Services\AffectationService;
use App\Services\AuditService;
use App\Services\Io\Exports\ExportColumnPolicy;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Service d'affectation automatique intelligente en masse par niveau (LOOP MATURE §1-15).
 *
 * Moteur de sélection académique, équilibrage par minimisation d'écart,
 * simulation sans écriture, rapport d'anomalies, transaction avec
 * lockForUpdate, idempotence et audit.
 *
 * S'appuie sur `AffectationService` comme SOURCE UNIQUE DE VÉRITÉ pour
 * les contrôles de contraintes et l'exécution transactionnelle unitaire.
 */
class MassAssignmentService
{
    /**
     * Catégories du centre d'exceptions (§23) : chaque code d'anomalie
     * bloquante est rattaché à une catégorie d'action pour la scolarité.
     */
    public const CATEGORIES_ANOMALIES = [
        'ETUDIANT_SUSPENDU' => 'Discipline',
        'INSCRIPTION_ABSENTE' => 'Inscription',
        'INSCRIPTION_INVALIDE' => 'Inscription',
        'NIVEAU_MANQUANT' => 'Programme',
        'AFFECTATION_VERROUILLEE' => 'Groupe',
        'AUCUN_GROUPE_COMPATIBLE' => 'Groupe',
        'CAPACITE_EPUISEE' => 'Capacité',
    ];

    public function __construct(
        private readonly AffectationService $affectationService,
        private readonly AuditService $auditService,
        private readonly AffectationEngine $moteur,
    ) {}

    /**
     * Recherche et précharge les étudiants candidats selon les critères du cockpit.
     *
     * @param  array{annee_universitaire_id: int, faculte_id?: int|null, programme_id?: int|null, niveau?: string|null, uniquement_non_affectes?: bool, etudiant_ids?: array<int>|null}  $criteres
     * @return Collection<int, Etudiant>
     */
    public function chargerCandidats(array $criteres, User $utilisateur): Collection
    {
        $anneeId = (int) $criteres['annee_universitaire_id'];
        $faculteRestreinteId = $utilisateur->faculteRestreinteId();
        $faculteId = $faculteRestreinteId ?? ($criteres['faculte_id'] ?? null);

        $annee = AnneeUniversitaire::findOrFail($anneeId);

        $query = Etudiant::query()
            ->where('statut', 'valide');

        // IDs spécifiques collés prioritaires
        if (! empty($criteres['etudiant_ids'])) {
            $query->whereIn('id', $criteres['etudiant_ids']);
            if ($faculteRestreinteId !== null) {
                $query->where('faculte_id', $faculteRestreinteId);
            }
        } else {
            // Filtrer sur la cohorte académique de l'année sélectionnée (évite de mélanger des promotions d'années différentes)
            $query->where(function ($q) use ($anneeId, $annee) {
                $q->whereHas('inscriptionsAcademiques', fn ($sub) => $sub->where('annee_universitaire_id', $anneeId))
                    ->orWhere('annee_academique', $annee->libelle);
            });

            $query->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
                ->when(! empty($criteres['programme_id']), fn ($q) => $q->where('programme_id', $criteres['programme_id']))
                ->when(! empty($criteres['niveau']), fn ($q) => $q->where('niveau', $criteres['niveau']));

            if (! empty($criteres['uniquement_non_affectes'])) {
                // Uniquement les étudiants sans affectation active pour l'année
                $query->whereDoesntHave('affectations', function ($q) use ($anneeId) {
                    $q->where('annee_universitaire_id', $anneeId)
                        ->where('statut', 'active');
                });
            }
        }

        return $query->with([
            'faculte',
            'programmeRef',
            'niveauRef',
            'situations',
            'inscriptionsAcademiques' => fn ($q) => $q->where('annee_universitaire_id', $anneeId),
            'affectations' => fn ($q) => $q->where('annee_universitaire_id', $anneeId)->where('statut', 'active')->with('groupe'),
        ])
            ->orderBy('nom')
            ->orderBy('prenom')
            ->limit(5000)
            ->get();
    }

    /**
     * Recherche les groupes éligibles selon les critères académiques.
     *
     * @param  array{faculte_id?: int|null, programme_id?: int|null, niveau?: string|null}  $criteres
     * @return Collection<int, Group>
     */
    public function chargerGroupesCandidats(array $criteres, User $utilisateur): Collection
    {
        $faculteRestreinteId = $utilisateur->faculteRestreinteId();
        $faculteId = $faculteRestreinteId ?? ($criteres['faculte_id'] ?? null);

        return Group::query()
            ->where('actif', true)
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->when(! empty($criteres['programme_id']), fn ($q) => $q->where('programme_id', $criteres['programme_id']))
            ->when(! empty($criteres['niveau']), fn ($q) => $q->where('niveau', $criteres['niveau']))
            ->with(['programme', 'niveauRef', 'faculte'])
            ->orderBy('code')
            ->get();
    }

    /**
     * SIMULATION SANS ÉCRITURE (LOOP 5 & 6) :
     * Analyse les critères, vérifie la hiérarchie académique, équilibre les groupes,
     * et produit le rapport complet des anomalies (bloquants vs alertes).
     *
     * @param  array{annee_universitaire_id: int, faculte_id?: int|null, programme_id?: int|null, niveau?: string|null, uniquement_non_affectes?: bool, etudiant_ids?: array<int>|null}  $criteres
     * @return array{
     *     total_students: int,
     *     eligible_count: int,
     *     assigned_count: int,
     *     unassigned_count: int,
     *     warnings_count: int,
     *     groups_summary: array<int, array{id: int, code: string, nom: string, niveau: string|null, programme: string|null, capacity: int|null, before: int, assigned: int, after: int, fill_rate: float}>,
     *     groups_map: array<int, string>,
     *     propositions: array<int, array{student_id: int, student_cin: string, student_nom: string, student_prenom: string, student_numero: string, niveau: string|null, programme: string|null, current_group: string|null, proposed_group_id: int|null, proposed_group_code: string|null, compatible_groups: array<int, int|string>, warnings: array<string>, score: int, certitude: string, reasons: array<int, string>, scoring_breakdown: array<string, int>, alternatives: array<int, array{group_id: int, code: string, score: int}>, selected: bool}>,
     *     anomalies: array<int, array{student_id: int, student_nom: string, code: string, categorie: string, type: string, message: string}>,
     *     categories: array<string, int>
     * }
     */
    public function analyser(array $criteres, User $utilisateur): array
    {
        $annee = AnneeUniversitaire::findOrFail((int) $criteres['annee_universitaire_id']);
        $etudiants = $this->chargerCandidats($criteres, $utilisateur);
        $groupes = $this->chargerGroupesCandidats($criteres, $utilisateur);

        $etudiantIds = $etudiants->pluck('id')->all();
        $groupeIds = $groupes->pluck('id')->all();

        // Contexte préchargé sans N+1 (inscriptions, suspensions, existantes, effectifs réels)
        $ctx = $this->affectationService->contexteBulk($etudiantIds, $groupeIds, $annee);

        // État initial des capacités
        $effectifsSimules = [];
        $capacitesSimulees = [];

        foreach ($groupes as $g) {
            $effectifActuel = $ctx['effectifs'][$g->id] ?? 0;
            $effectifsSimules[$g->id] = $effectifActuel;
            $capacitesSimulees[$g->id] = $g->capacite !== null ? max(0, (int) $g->capacite - $effectifActuel) : PHP_INT_MAX;
        }

        $propositions = [];
        $anomalies = [];
        $etudiantsEligiblesParCle = [];

        // 1. PHASE DE CONTRÔLE ACADÉMIQUE STRICT POUR CHAQUE ÉTUDIANT (LOOP 2 & 6)
        foreach ($etudiants as $etudiant) {
            $inscriptionStatut = $ctx['inscriptions'][$etudiant->id] ?? null;
            $estSuspendu = isset($ctx['suspendus'][$etudiant->id]);
            $affectationExistante = $ctx['existantes'][$etudiant->id] ?? null;

            // Déduction / confirmation du niveau officiel (relation déjà
            // préchargée : zéro requête, même sémantique que le repli).
            $niveauEtudiant = $etudiant->niveau
                ?: $etudiant->inscriptionsAcademiques->first()?->niveau;

            // Vérifications bloquantes
            if ($estSuspendu) {
                $anomalies[] = $this->construireAnomalie(
                    $etudiant,
                    $niveauEtudiant,
                    'ETUDIANT_SUSPENDU',
                    'Étudiant actuellement suspendu : affectation impossible.'
                );

                continue;
            }

            if ($inscriptionStatut === null) {
                $anomalies[] = $this->construireAnomalie(
                    $etudiant,
                    $niveauEtudiant,
                    'INSCRIPTION_ABSENTE',
                    "Aucune inscription active pour l'année universitaire {$annee->libelle}."
                );

                continue;
            }

            if (! in_array($inscriptionStatut, ['en_cours', 'valide'], true)) {
                $anomalies[] = $this->construireAnomalie(
                    $etudiant,
                    $niveauEtudiant,
                    'INSCRIPTION_INVALIDE',
                    "Inscription {$inscriptionStatut} (requise : en_cours ou valide)."
                );

                continue;
            }

            if (empty($niveauEtudiant)) {
                $anomalies[] = $this->construireAnomalie(
                    $etudiant,
                    $niveauEtudiant,
                    'NIVEAU_MANQUANT',
                    'Niveau académique LMD non renseigné sur le dossier ou l’inscription.'
                );

                continue;
            }

            // Vérification de verrou
            $affectationActive = $etudiant->affectations->first();
            if ($affectationActive !== null && $affectationActive->verrouille) {
                $anomalies[] = $this->construireAnomalie(
                    $etudiant,
                    $niveauEtudiant,
                    'AFFECTATION_VERROUILLEE',
                    "Affectation active verrouillée sur {$affectationActive->groupe?->code} : action administrative requise."
                );

                continue;
            }

            // Clé académique pour l'équilibrage et le partage mémoire des groupes
            $cleAcademique = "{$etudiant->faculte_id}|".($etudiant->programme_id ?? '0')."|{$niveauEtudiant}";

            if (! isset($groupesCompatiblesParCle[$cleAcademique])) {
                $groupesCompatiblesParCle[$cleAcademique] = $groupes->filter(function (Group $g) use ($etudiant, $niveauEtudiant) {
                    // Règle 1 : Faculté identique
                    if ($g->faculte_id !== null && (int) $g->faculte_id !== (int) $etudiant->faculte_id) {
                        return false;
                    }
                    // Règle 2 : Niveau identique
                    if ($g->niveau !== null && $g->niveau !== $niveauEtudiant) {
                        return false;
                    }
                    // Règle 3 : Parcours / Programme identique (si défini sur le groupe)
                    if ($g->programme_id !== null && $etudiant->programme_id !== null && (int) $g->programme_id !== (int) $etudiant->programme_id) {
                        return false;
                    }

                    return true;
                });
                $compatibleGroupIdsParCle[$cleAcademique] = array_values($groupesCompatiblesParCle[$cleAcademique]->pluck('id')->all());
            }

            $groupesCompatibles = $groupesCompatiblesParCle[$cleAcademique];

            if ($groupesCompatibles->isEmpty()) {
                $anomalies[] = $this->construireAnomalie(
                    $etudiant,
                    $niveauEtudiant,
                    'AUCUN_GROUPE_COMPATIBLE',
                    "Aucun groupe actif compatible pour le niveau « {$niveauEtudiant} » (Faculté #{$etudiant->faculte_id})."
                );

                continue;
            }

            $etudiantsEligiblesParCle[$cleAcademique][] = [
                'etudiant' => $etudiant,
                'niveau' => $niveauEtudiant,
                'groupes_compatibles' => $groupesCompatibles,
                'affectation_existante' => $affectationExistante,
            ];
        }

        // 2. ALGORITHME D'ÉQUILIBRAGE PAR LOT ACADÉMIQUE (LOOP 4)
        $totalEligibles = 0;
        $totalAffectes = 0;
        $basesStatiques = []; // mémo (signature×groupe) des notes statiques.

        foreach ($etudiantsEligiblesParCle as $cle => $items) {
            $totalEligibles += count($items);

            foreach ($items as $item) {
                /** @var Etudiant $etudiant */
                $etudiant = $item['etudiant'];
                $niveauEtudiant = $item['niveau'];
                /** @var Collection<int, Group> $groupesCompatibles */
                $groupesCompatibles = $item['groupes_compatibles'];
                $affectationExistante = $item['affectation_existante'];

                // Cœur d'équilibrage déterministe (LOOP 4) :
                // Minimise max(effectif) - min(effectif) sans allocation
                // de Collection ni sortBy lourd (D-005) :
                // 1) Moins rempli d'abord (effectifSimule minimal)
                // 2) Places restantes maximales
                // 3) ID déterministe
                $meilleurGroupe = null;
                $meilleurCritere = null;

                foreach ($groupesCompatibles as $g) {
                    if ($capacitesSimulees[$g->id] <= 0) {
                        continue;
                    }
                    $critere = [
                        $effectifsSimules[$g->id],
                        -min($capacitesSimulees[$g->id], 1 << 30),
                        $g->id,
                    ];
                    if ($meilleurCritere === null || $critere < $meilleurCritere) {
                        $meilleurCritere = $critere;
                        $meilleurGroupe = $g;
                    }
                }

                if ($meilleurGroupe === null) {
                    $anomalies[] = $this->construireAnomalie(
                        $etudiant,
                        $niveauEtudiant,
                        'CAPACITE_EPUISEE',
                        "Capacité épuisée sur tous les groupes compatibles du niveau {$niveauEtudiant}."
                    );

                    continue;
                }

                // Proposition validée
                $effectifsSimules[$meilleurGroupe->id]++;
                $capacitesSimulees[$meilleurGroupe->id] = max(0, $capacitesSimulees[$meilleurGroupe->id] - 1);
                $totalAffectes++;

                // Avertissements éventuels
                $warnings = [];
                if ($affectationExistante !== null) {
                    if ((int) $affectationExistante['groupe_id'] === (int) $meilleurGroupe->id) {
                        $warnings[] = "Déjà affecté au groupe {$meilleurGroupe->code} : affectation maintenue.";
                    } else {
                        $warnings[] = "Transfert : actuellement dans le groupe {$affectationExistante['code']} (sera clôturé).";
                    }
                }

                $tauxRemplissage = $meilleurGroupe->capacite > 0
                    ? ($effectifsSimules[$meilleurGroupe->id] / $meilleurGroupe->capacite) * 100
                    : 0;

                if ($tauxRemplissage >= 90) {
                    $warnings[] = "Groupe à capacité tendue ({$effectifsSimules[$meilleurGroupe->id]}/{$meilleurGroupe->capacite}).";
                }

                // Explicabilité (§21-22) : même barème central que le
                // moteur, sans second moteur. Classement par score léger
                // (arithmétique identique, sans tableaux/chaînes) sur
                // tous les compatibles, puis notes complètes pour le
                // seul choisi et ses 5 meilleures alternatives. Le choix
                // reste l'équilibrage ; le score l'explique.
                $effs = [];
                foreach ($groupesCompatibles as $candidat) {
                    $effs[$candidat->id] = $effectifsSimules[$candidat->id];
                }
                $minSimule = min($effs);
                $maxSimule = max($effs);
                // Alias `programme` = même objet que `programmeRef` déjà
                // préchargé (zéro requête dans noterStatique) + base
                // statique mémoïsée par signature (résultat identique).
                $etudiant->setRelation('programme', $etudiant->getRelation('programmeRef'));
                $cleSignature = $etudiant->faculte_id.'|'.($etudiant->programme_id ?? 0).'|'.($etudiant->filiere ?? '');
                $scoresLegers = [];
                foreach ($groupesCompatibles as $candidat) {
                    $cleBase = $cleSignature.'|'.$candidat->id;
                    $basesStatiques[$cleBase] ??= $this->moteur->noterStatique($etudiant, $candidat);
                    $scoresLegers[$candidat->id] = $this->moteur->scoreLeger(
                        $basesStatiques[$cleBase]['points'], $candidat,
                        $effs[$candidat->id], $minSimule, $maxSimule
                    );
                }
                $ordre = array_keys($scoresLegers);
                usort($ordre, fn (int $a, int $b) => [$scoresLegers[$b], $effs[$b], $a] <=> [$scoresLegers[$a], $effs[$a], $b]);

                $cleChoisie = $cleSignature.'|'.$meilleurGroupe->id;
                $noteChoisie = $this->moteur->noterAvecBase(
                    $etudiant, $meilleurGroupe, $effs[$meilleurGroupe->id],
                    $minSimule, $maxSimule, $basesStatiques[$cleChoisie]
                );
                $meilleurAutre = 0;
                $alternatives = [];
                foreach ($ordre as $gid) {
                    if ($gid === $meilleurGroupe->id) {
                        continue;
                    }
                    if ($scoresLegers[$gid] > $meilleurAutre) {
                        $meilleurAutre = $scoresLegers[$gid];
                    }
                    if (count($alternatives) < 5) {
                        // Score léger seul (arithmétique identique à la
                        // note complète, prouvée LOOP 2) : les raisons
                        // détaillées par alternative ne sont consommées
                        // par aucune vue (D-005).
                        $alternatives[] = [
                            'group_id' => $gid,
                            'code' => $groupesCompatibles->find($gid)->code,
                            'score' => $scoresLegers[$gid],
                        ];
                    }
                }
                $certitude = $this->moteur->certitudePour(
                    $noteChoisie['score'],
                    $noteChoisie['score'] - $meilleurAutre,
                    $noteChoisie['taux_remplissage'],
                    $warnings === []
                );

                $propositions[] = [
                    'student_id' => $etudiant->id,
                    'student_cin' => $etudiant->cin ?: '—',
                    'student_nom' => $etudiant->nom,
                    'student_prenom' => $etudiant->prenom,
                    'student_numero' => $etudiant->num_inscription,
                    'niveau' => $niveauEtudiant,
                    'programme' => $etudiant->programmeRef?->nom ?? 'Tronc commun',
                    'current_group' => $affectationExistante['code'] ?? null,
                    'proposed_group_id' => $meilleurGroupe->id,
                    'proposed_group_code' => $meilleurGroupe->code,
                    'compatible_groups' => $compatibleGroupIdsParCle[$cle],
                    'warnings' => $warnings,
                    'score' => $noteChoisie['score'],
                    'certitude' => $certitude,
                    'reasons' => $noteChoisie['reasons'],
                    'scoring_breakdown' => $noteChoisie['scoring_breakdown'],
                    'alternatives' => $alternatives,
                    'selected' => true,
                ];
            }
        }

        // 3. SYNTHÈSE DES GROUPES AVANT / APRÈS + DICTIONNAIRE PARTAGÉ (D-005)
        $groupsSummary = [];
        $groupsMap = [];
        foreach ($groupes as $g) {
            $before = $ctx['effectifs'][$g->id] ?? 0;
            $after = $effectifsSimules[$g->id];
            $assigned = $after - $before;
            $fillRate = $g->capacite !== null && $g->capacite > 0 ? round(($after / $g->capacite) * 100, 1) : 0.0;
            $label = "{$g->code} ({$after}/{$g->capacite})";

            $groupsSummary[] = [
                'id' => $g->id,
                'code' => $g->code,
                'nom' => $g->nom,
                'niveau' => $g->niveau,
                'programme' => $g->programme?->nom ?? 'Général',
                'capacity' => $g->capacite,
                'before' => $before,
                'assigned' => $assigned,
                'after' => $after,
                'fill_rate' => $fillRate,
            ];
            $groupsMap[$g->id] = $label;
        }

        $bloquantsCount = count(array_filter($anomalies, fn ($a) => $a['type'] === 'bloquant'));
        $warningsCount = count(array_filter($anomalies, fn ($a) => $a['type'] === 'avertissement'))
            + count(array_filter($propositions, fn ($p) => ! empty($p['warnings'])));

        // Centre d'exceptions (§23) : synthèse des anomalies triée par volume.
        $categories = [];
        foreach ($anomalies as $a) {
            $categories[$a['categorie']] = ($categories[$a['categorie']] ?? 0) + 1;
        }
        arsort($categories);

        // P0 (LOOP 2) — Confidentialité : la CIN ne sort de l'analyse que
        // masquée, sauf permission `exports.personal`. Point centralisé
        // couvrant la vue, le JSON et l'export CSV (même source).
        $cinCompleteAutorisee = $utilisateur->hasPermission('exports.personal');
        $masquerCin = static fn (array $ligne): array => ! isset($ligne['student_cin'])
            || $ligne['student_cin'] === '—'
            || $cinCompleteAutorisee
            ? $ligne
            : [...$ligne, 'student_cin' => ExportColumnPolicy::masquerCin($ligne['student_cin'], $utilisateur)];
        $propositions = array_map($masquerCin, $propositions);
        $anomalies = array_map($masquerCin, $anomalies);

        return [
            'total_students' => $etudiants->count(),
            'eligible_count' => $totalEligibles,
            'assigned_count' => $totalAffectes,
            'unassigned_count' => $etudiants->count() - $totalAffectes,
            'warnings_count' => $warningsCount,
            'groups_summary' => $groupsSummary,
            'groups_map' => $groupsMap,
            'propositions' => $propositions,
            'anomalies' => $anomalies,
            'categories' => $categories,
        ];
    }

    /**
     * Crée un ordre de travail persistant pour exécution différée
     * (LOOP P2) : sélections normalisées + compteurs à zéro. Ne
     * décide ni ne vérifie rien — `confirmer()` reste seul juge.
     *
     * @param  array<int, array{student_id: int, groupe_id: int}>  $selections
     */
    public function creerLot(
        array $selections,
        int $anneeId,
        int $auteurId,
        string $motif = 'Affectation automatique en masse',
        bool $force = false,
    ): AffectationMasseLot {
        $annee = AnneeUniversitaire::findOrFail($anneeId);

        $normalisees = [];
        foreach ($selections as $selection) {
            $studentId = (int) ($selection['student_id'] ?? 0);
            $groupeId = (int) ($selection['groupe_id'] ?? 0);

            if ($studentId > 0 && $groupeId > 0) {
                $normalisees[] = ['student_id' => $studentId, 'groupe_id' => $groupeId];
            }
        }

        abort_if($normalisees === [], 422, 'Aucune proposition d’affectation sélectionnée.');
        $auteurExiste = User::whereKey($auteurId)->exists();

        return AffectationMasseLot::create([
            'reference' => 'AFF-'.$annee->libelle.'-'.strtoupper(Str::random(6)),
            'annee_universitaire_id' => $annee->id,
            'auteur_id' => $auteurExiste ? $auteurId : null,
            'statut' => AffectationMasseLot::STATUT_EN_ATTENTE,
            'selections' => array_values($normalisees),
            'motif' => mb_substr($motif, 0, 255),
            'force' => $force,
            'total' => count($normalisees),
        ]);
    }

    /**
     * CONFIRMATION TRANSACTIONNELLE AVEC VERROUILLAGE ANTI-CONCURRENCE (LOOP 8, 9 & 10) :
     *
     * 1. lockForUpdate() sur chaque groupe concerné
     * 2. Recalcul temps-réel des capacités
     * 3. Affectation unitaire via `AffectationService::affecter()`
     * 4. Idempotence garantie
     * 5. Audit complet traçable
     *
     * @param  array<int, array{student_id: int, groupe_id: int}>  $selections
     * @return array{total_demande: int, affectes: int, ignores: int, erreurs: array<int, string>}
     */
    public function confirmer(
        array $selections,
        int $anneeId,
        User $auteur,
        string $motif = 'Affectation automatique en masse',
        bool $force = false,
    ): array {
        if ($selections === []) {
            return ['total_demande' => 0, 'affectes' => 0, 'ignores' => 0, 'erreurs' => []];
        }

        $annee = AnneeUniversitaire::findOrFail($anneeId);
        $totalDemande = count($selections);
        $affectes = 0;
        $ignores = 0;
        $erreurs = [];

        // Regrouper par groupe pour verrouiller efficacement
        $groupeIds = array_values(array_unique(array_filter(array_map(fn ($s) => (int) ($s['groupe_id'] ?? 0), $selections))));

        DB::transaction(function () use (
            $selections, $groupeIds, $annee, $auteur, $motif, $force,
            &$affectes, &$ignores, &$erreurs
        ) {
            // Verrouille les groupes pour synchroniser les capacités face aux accès concurrents
            $groupesVerrouilles = Group::whereIn('id', $groupeIds)->lockForUpdate()->get()->keyBy('id');

            // Préchargements lot (benchmark LOOP 2) : étudiants en une
            // requête + contexte bulk (4 requêtes). La sécurité
            // capacitaire reste revérifiée sous verrou dans affecter().
            $idsDemandes = array_values(array_unique(array_filter(
                array_map(fn ($s) => (int) ($s['student_id'] ?? 0), $selections)
            )));
            $contexte = $this->affectationService->contexteBulk($idsDemandes, $groupeIds, $annee);
            $etudiants = $idsDemandes === []
                ? collect()
                : Etudiant::whereIn('id', $idsDemandes)->get()->keyBy('id');

            foreach ($selections as $selection) {
                $studentId = (int) ($selection['student_id'] ?? 0);
                $groupeId = (int) ($selection['groupe_id'] ?? 0);

                if ($studentId <= 0 || $groupeId <= 0) {
                    $ignores++;

                    continue;
                }

                $groupe = $groupesVerrouilles->get($groupeId);
                if ($groupe === null) {
                    $ignores++;
                    $erreurs[$studentId] = 'Groupe cible introuvable.';

                    continue;
                }

                // Vérification du périmètre de faculté
                if (! $auteur->peutAccederFaculte((int) $groupe->faculte_id)) {
                    $ignores++;
                    $erreurs[$studentId] = 'Accès non autorisé pour cette faculté.';

                    continue;
                }

                $etudiant = $etudiants->get($studentId);
                if (! $etudiant instanceof Etudiant) {
                    $ignores++;
                    $erreurs[$studentId] = 'Étudiant introuvable.';

                    continue;
                }

                if (! $auteur->peutAccederFaculte((int) $etudiant->faculte_id)) {
                    $ignores++;
                    $erreurs[$studentId] = "Étudiant hors du périmètre d'accès faculté.";

                    continue;
                }

                try {
                    // Délègue au moteur source de vérité (affecter() vérifie lockForUpdate sur l'affectation active)
                    $this->affectationService->affecter(
                        $etudiant,
                        $groupe,
                        $annee,
                        $auteur->id,
                        $motif,
                        $force,
                        null,
                        $contexte,
                        false // Notifications gérées en lot après
                    );
                    $affectes++;
                } catch (DomainException $e) {
                    $ignores++;
                    $erreurs[$studentId] = $e->getMessage();
                }
            }
        });

        // Audit de l'opération de masse (LOOP 10)
        $premierEtudiant = Etudiant::find($selections[0]['student_id'] ?? 0);
        if ($premierEtudiant !== null) {
            $this->auditService->tracer(
                $premierEtudiant,
                'assignment.mass_executed',
                'groupe',
                $selections[0]['groupe_id'] ?? null,
                null,
                [
                    'annee' => $annee->libelle,
                    'total_demande' => $totalDemande,
                    'affectes' => $affectes,
                    'ignores' => $ignores,
                    'motif' => $motif,
                    'auteur' => $auteur->email,
                ],
                'web'
            );
        }

        return [
            'total_demande' => $totalDemande,
            'affectes' => $affectes,
            'ignores' => $ignores,
            'erreurs' => $erreurs,
        ];
    }

    /**
     * Génère et persiste des propositions d'affectation pour validation administrative ultérieure (LOOP 7 & 10).
     *
     * @param  array<int, array{student_id: int, groupe_id: int, score?: int, certitude?: string, reasons?: array, alternatives?: array, groupes_compatibles?: array}>  $selections
     * @return array{total_demande: int, creees: int, erreurs: array<int, string>}
     */
    public function creerPropositions(
        array $selections,
        int $anneeId,
        User $auteur,
        string $motif = 'Génération de propositions via cockpit'
    ): array {
        if ($selections === []) {
            return ['total_demande' => 0, 'creees' => 0, 'erreurs' => []];
        }

        $annee = AnneeUniversitaire::findOrFail($anneeId);
        $totalDemande = count($selections);
        $creees = 0;
        $erreurs = [];

        DB::transaction(function () use ($selections, $annee, $auteur, $motif, &$creees, &$erreurs) {
            // Préchargements lot (benchmark LOOP 2) : groupes + étudiants
            // avec inscriptions (zéro lazy en boucle).
            $idsEtudiants = array_values(array_unique(array_filter(
                array_map(fn ($s) => (int) ($s['student_id'] ?? 0), $selections)
            )));
            $idsGroupes = array_values(array_unique(array_filter(
                array_map(fn ($s) => (int) ($s['groupe_id'] ?? 0), $selections)
            )));
            $groupes = $idsGroupes === [] ? collect() : Group::whereIn('id', $idsGroupes)->get()->keyBy('id');
            $etudiants = $idsEtudiants === [] ? collect() : Etudiant::whereIn('id', $idsEtudiants)
                ->with('inscriptionsAcademiques')
                ->get()->keyBy('id');

            foreach ($selections as $selection) {
                $studentId = (int) ($selection['student_id'] ?? 0);
                $groupeId = (int) ($selection['groupe_id'] ?? 0);

                if ($studentId <= 0 || $groupeId <= 0) {
                    continue;
                }

                $groupe = $groupes->get($groupeId);
                $etudiant = $etudiants->get($studentId);

                if ($groupe === null || $etudiant === null) {
                    $erreurs[$studentId] = 'Étudiant ou groupe introuvable.';

                    continue;
                }

                if (! $auteur->peutAccederFaculte((int) $groupe->faculte_id) || ! $auteur->peutAccederFaculte((int) $etudiant->faculte_id)) {
                    $erreurs[$studentId] = 'Périmètre faculté non autorisé.';

                    continue;
                }

                AffectationProposal::updateOrCreate(
                    [
                        'student_id' => $studentId,
                        'annee_universitaire_id' => $annee->id,
                        'statut' => AffectationProposal::STATUT_EN_ATTENTE,
                    ],
                    [
                        'inscription_id' => $etudiant->inscriptionsAcademiques->where('annee_universitaire_id', $annee->id)->first()?->id,
                        'proposed_group_id' => $groupeId,
                        'mode' => AffectationProposal::MODE_SEMI_AUTOMATIQUE,
                        'score' => (int) ($selection['score'] ?? 85),
                        'certitude' => (string) ($selection['certitude'] ?? AffectationProposal::CERTITUDE_MOYENNE),
                        'reasons' => $selection['reasons'] ?? ['Proposition générée par cockpit de masse'],
                        'metadata' => [
                            'motif' => $motif,
                            'source' => 'masse',
                            'auteur' => $auteur->email,
                            'alternatives' => array_slice($selection['alternatives'] ?? [], 0, 5),
                            'groupes_compatibles' => $selection['groupes_compatibles'] ?? [],
                        ],
                    ]
                );

                $creees++;
            }
        });

        // Audit de l'opération
        $premier = Etudiant::find($selections[0]['student_id'] ?? 0);
        if ($premier !== null) {
            $this->auditService->tracer(
                $premier,
                'assignment.proposals_bulk_generated',
                'affectation_proposal',
                null,
                null,
                [
                    'annee' => $annee->libelle,
                    'total_demande' => $totalDemande,
                    'creees' => $creees,
                    'auteur' => $auteur->email,
                ],
                'web'
            );
        }

        return [
            'total_demande' => $totalDemande,
            'creees' => $creees,
            'erreurs' => $erreurs,
        ];
    }

    /**
     * Construit une anomalie enrichie pour le centre d'exceptions (LOOP P3).
     *
     * @return array{student_id: int, student_nom: string, student_cin: string, student_numero: string, niveau: string, code: string, categorie: string, type: string, message: string, url_action: string}
     */
    private function construireAnomalie(
        Etudiant $etudiant,
        ?string $niveauEtudiant,
        string $code,
        string $message,
        string $type = 'bloquant'
    ): array {
        $categorie = self::CATEGORIES_ANOMALIES[$code] ?? 'Autre';
        $onglet = match ($categorie) {
            'Discipline' => 'discipline',
            'Inscription' => 'inscriptions',
            'Programme' => 'academique',
            default => '',
        };

        $urlAction = route('admin.etudiants.show', $etudiant->id);
        if ($onglet !== '') {
            $urlAction .= '?onglet='.$onglet;
        }

        return [
            'student_id' => $etudiant->id,
            'student_nom' => $etudiant->nom_complet,
            'student_cin' => (string) ($etudiant->cin ?: '—'),
            'student_numero' => (string) ($etudiant->num_inscription ?: '—'),
            'niveau' => $niveauEtudiant ?: ($etudiant->niveau ?: '—'),
            'code' => $code,
            'categorie' => $categorie,
            'type' => $type,
            'message' => $message,
            'url_action' => $urlAction,
        ];
    }
}
