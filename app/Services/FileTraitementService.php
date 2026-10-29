<?php

namespace App\Services;

use App\Models\DossierCorrection;
use App\Models\DossierVerification;
use App\Models\Etudiant;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\SituationEtudiant;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Service de pilotage de la file de traitement des dossiers d'inscription (LOOP 18).
 *
 * Principes et règles métier :
 * 1. Scoping faculté strict : chaque métrique et requête est bornée au périmètre de l'agent.
 * 2. Actions attendues objectives :
 *    - A_INSTRUIRE : dossier en attente, sans blocage ni complément en cours.
 *    - COMPLEMENT_ATTENDU : dossier en attente avec demande de complément ou pièce rejetée en cours.
 *    - ANOMALIE_DETECTEE : dossier en attente avec verdict non conforme (BLOQUANT / A_VERIFIER).
 *    - A_AFFECTER : dossier validé administrativement mais sans groupe rattaché.
 *    - TRAITE : dossier validé avec groupe ou rejeté définitivement.
 * 3. Mesure des volumes et délais : indicateurs réels (dossiers > 48h, délais moyens),
 *    SANS classement opaque ni notation subjective opposable aux candidats.
 * 4. Fiche d'instruction et motifs normalisés pour le suivi administratif.
 * 5. Zéro N+1 : enrichissement par lots de clés pré-calculées.
 */
class FileTraitementService
{
    public const ACTION_A_INSTRUIRE = 'A_INSTRUIRE';

    public const ACTION_COMPLEMENT_ATTENDU = 'COMPLEMENT_ATTENDU';

    public const ACTION_ANOMALIE_DETECTEE = 'ANOMALIE_DETECTEE';

    public const ACTION_A_AFFECTER = 'A_AFFECTER';

    public const ACTION_TRAITE = 'TRAITE';

    public const ACTIONS_ATTENDUES = [
        self::ACTION_A_INSTRUIRE => 'À instruire',
        self::ACTION_COMPLEMENT_ATTENDU => 'Complément attendu',
        self::ACTION_ANOMALIE_DETECTEE => 'Anomalie détectée',
        self::ACTION_A_AFFECTER => 'À affecter en groupe',
        self::ACTION_TRAITE => 'Traité',
    ];

    public const MOTIFS_NORMALISES = [
        'PIECE_IDENTITE_NON_CONFORME' => 'Pièce d’identité illisible, périmée ou non conforme',
        'RELEVE_NOTES_INCOMPLET' => 'Relevé de notes officiel ou attestation manquant(e)',
        'DIPLOME_NON_AUTHENTIFIE' => 'Diplôme d’accès non certifié conforme',
        'INCOHERENCE_ETAT_CIVIL' => 'Incohérence des informations d’état-civil',
        'PAIEMENT_NON_CONFIRME' => 'Justificatif de paiement manquant ou invalide',
        'ORIENTATION_INCOMPATIBLE' => 'Prérequis académiques non satisfaits pour la filière',
        'DOSSIER_COMPLET_VALABLE' => 'Dossier complet et vérifié conforme aux exigences',
        'AUTRE' => 'Autre observation circonstanciée',
    ];

    /**
     * LOOP C (§7) — catégories de file DÉRIVÉES : aucun statut persisté
     * nouveau, raffinement de l'action attendue par famille de règles en
     * échec du dernier run (codes persistés — migration additive). Miroir
     * strict de la priorité existante (`determinerActionAttendue`) :
     * complément > familles spécialisées (mens > identite > doublon >
     * documents) > verdict (BLOCKED si BLOQUANT, sinon examen humain).
     * `TECHNICAL_RETRY` : aucune donnée par étudiant — les erreurs
     * techniques vivent dans les lots/jobs (NOT_APPLICABLE documenté).
     */
    public const CATEGORIE_MENS_REVIEW = 'MENS_REVIEW';

    public const CATEGORIE_IDENTITY_REVIEW = 'IDENTITY_REVIEW';

    public const CATEGORIE_DUPLICATE_REVIEW = 'DUPLICATE_REVIEW';

    public const CATEGORIE_MISSING_DOCUMENTS = 'MISSING_DOCUMENTS';

    public const CATEGORIE_BLOCKED = 'BLOCKED';

    public const CATEGORIE_READY_FOR_REVIEW = 'READY_FOR_REVIEW';

    public const CATEGORIE_READY_FOR_ASSIGNMENT = 'READY_FOR_ASSIGNMENT';

    public const CATEGORIE_ASSIGNED = 'ASSIGNED';

    public const CATEGORIE_COMPLETED = 'COMPLETED';

    public const CATEGORIES_FILE = [
        self::CATEGORIE_MENS_REVIEW => 'Divergence MENS à traiter',
        self::CATEGORIE_IDENTITY_REVIEW => 'Identité à vérifier',
        self::CATEGORIE_DUPLICATE_REVIEW => 'Doublon potentiel à examiner',
        self::CATEGORIE_MISSING_DOCUMENTS => 'Documents à compléter',
        self::CATEGORIE_BLOCKED => 'Blocage métier confirmé',
        self::CATEGORIE_READY_FOR_REVIEW => 'Prêt pour décision humaine',
        self::CATEGORIE_READY_FOR_ASSIGNMENT => 'Prêt pour affectation',
        self::CATEGORIE_ASSIGNED => 'Affecté (groupe valide)',
        self::CATEGORIE_COMPLETED => 'Traitement terminé',
    ];

    public const PROCHAINES_ACTIONS = [
        self::CATEGORIE_MENS_REVIEW => 'Résoudre la divergence via le workflow 4-yeux (corrections approuvées).',
        self::CATEGORIE_IDENTITY_REVIEW => 'Vérifier les pièces d’identité / l’extraction OCR.',
        self::CATEGORIE_DUPLICATE_REVIEW => 'Comparer les dossiers doublons — jamais de fusion automatique.',
        self::CATEGORIE_MISSING_DOCUMENTS => 'Exiger / compléter les pièces obligatoires du dossier.',
        self::CATEGORIE_BLOCKED => 'Traiter le blocage au centre de résolution.',
        self::CATEGORIE_READY_FOR_REVIEW => 'Instruire le dossier (vérification, validation ou rejet motivé).',
        self::CATEGORIE_READY_FOR_ASSIGNMENT => 'Affecter un groupe compatible (capacité et niveau vérifiés).',
        self::CATEGORIE_ASSIGNED => '—',
        self::CATEGORIE_COMPLETED => '—',
    ];

    /** Familles spécialisées : préfixe de code de règle → catégorie. */
    private const FAMILLES_REGLES = [
        'mens.' => self::CATEGORIE_MENS_REVIEW,
        'identite.' => self::CATEGORIE_IDENTITY_REVIEW,
        'doublons.' => self::CATEGORIE_DUPLICATE_REVIEW,
        'documents.' => self::CATEGORIE_MISSING_DOCUMENTS,
        'pieces.' => self::CATEGORIE_MISSING_DOCUMENTS,
    ];

    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * Indicateurs consolidés de la file de traitement avec scoping faculté strict.
     *
     * Le troisième argument permet de réutiliser exactement le builder de
     * la liste filtrée (D-01) : les KPI décrivent alors la population
     * affichée, jamais un périmètre faculté seul. Les colonnes, jointures
     * de tri et l'ordre de présentation sont neutralisés avant agrégation.
     *
     * @return array{
     *     total: int,
     *     en_attente: int,
     *     valides: int,
     *     rejetes: int,
     *     souffrance_48h: int,
     *     souffrance_7j: int,
     *     delai_moyen_heures: int,
     *     delai_moyen_jours: float,
     *     valides_aujourdhui: int,
     *     rejetes_aujourdhui: int,
     *     actions: array<string, int>
     * }
     */
    public function kpis(User $utilisateur, ?int $faculteId = null, ?Builder $base = null): array
    {
        $faculteRestreinteId = $utilisateur->faculteRestreinteId();
        $faculteCible = $faculteRestreinteId ?? $faculteId;

        $base = $base !== null ? clone $base : Etudiant::query();
        $base->reorder();
        $base->getQuery()->columns = null;
        $base->setEagerLoads([]);
        $base->when($faculteCible !== null, fn (Builder $q) => $q->where('etudiants.faculte_id', $faculteCible));

        // 1. Volumes généraux en une seule requête agrégée
        $statsVolumes = (clone $base)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(etudiants.statut = 'en_attente') as en_attente")
            ->selectRaw("SUM(etudiants.statut = 'valide') as valides")
            ->selectRaw("SUM(etudiants.statut = 'rejete') as rejetes")
            ->first();

        $maintenant = Carbon::now();
        $seuil48h = (clone $maintenant)->subHours(48);
        $seuil7j = (clone $maintenant)->subDays(7);

        $enAttenteIds = (clone $base)
            ->where('etudiants.statut', 'en_attente')
            ->pluck('etudiants.id')
            ->all();

        [$souffrance48h, $souffrance7j, $delaiMoyenHeures] = $this->metriquesAttente(
            $enAttenteIds,
            $seuil48h,
            $seuil7j,
            $maintenant
        );

        // 2. Flux du jour : dernière transition de statut, jamais un simple
        // updated_at. Fallback documenté pour les lignes historiques sans
        // SituationEtudiant (imports/écritures directes antérieures).
        $debutJour = Carbon::today();
        $decisions = $this->decisionsDuJour($base, $debutJour);
        $validesAujourdhui = (int) ($decisions['valide'] ?? 0);
        $rejetesAujourdhui = (int) ($decisions['rejete'] ?? 0);

        // 3. Répartition estimée des actions attendues (sur le même périmètre)
        $avecComplementCount = 0;
        $avecAnomalieCount = 0;
        $avecSuiviCount = 0;

        if (! empty($enAttenteIds)) {
            $idsComplement = DossierCorrection::whereIn('etudiant_id', $enAttenteIds)
                ->where('statut', 'proposee')
                ->distinct('etudiant_id')
                ->pluck('etudiant_id')
                ->all();
            $avecComplementCount = count($idsComplement);

            // Dernier run non conforme pour ces étudiants
            $idsAnomalie = DossierVerification::whereIn('etudiant_id', $enAttenteIds)
                ->whereIn('id', function ($sub) {
                    $sub->selectRaw('MAX(id)')->from('dossier_verifications')->groupBy('etudiant_id');
                })
                ->whereIn('verdict', ['BLOQUANT', 'A_VERIFIER'])
                ->distinct('etudiant_id')
                ->pluck('etudiant_id')
                ->all();
            $avecAnomalieCount = count($idsAnomalie);

            // Union : un dossier cumulant complément + anomalie ne compte
            // qu'une fois (cohérence avec le filtre A_INSTRUIRE de la liste).
            $avecSuiviCount = count(array_unique(array_merge($idsComplement, $idsAnomalie)));
        }

        $aAffecterCount = (clone $base)
            ->where('etudiants.statut', 'valide')
            ->whereNull('etudiants.groupe_id')
            ->count();

        $totalEnAttente = (int) ($statsVolumes->en_attente ?? 0);
        $aInstruireCount = max(0, $totalEnAttente - $avecSuiviCount);
        $traitesCount = (int) ($statsVolumes->rejetes ?? 0) + ((int) ($statsVolumes->valides ?? 0) - $aAffecterCount);

        // 4. LOOP D (§14) — métriques d'automatisation dérivées : agrégats
        // SANS PII sur l'historique académique append-only, restreints aux
        // étudiants du périmètre filtré. Ensemble vide = taux null explicite.
        $baseIds = (clone $base)->select('etudiants.id')->toBase();
        $compteursAuto = HistoriqueAcademique::query()
            ->join('etudiants', 'etudiants.id', '=', 'historique_academique.etudiant_id')
            ->whereIn('historique_academique.etudiant_id', $baseIds)
            ->whereIn('historique_academique.action', [
                'affectation.auto_assignee', 'affectation.auto_non_assignee', 'affectation.changee',
            ])
            ->groupBy('historique_academique.action')
            ->selectRaw('historique_academique.action as action, COUNT(*) as total')
            ->pluck('total', 'action');

        $reussiesAuto = (int) ($compteursAuto['affectation.auto_assignee'] ?? 0);
        $nonAssigneesAuto = (int) ($compteursAuto['affectation.auto_non_assignee'] ?? 0);
        $tentativesAuto = $reussiesAuto + $nonAssigneesAuto;
        $changementsEffectues = (int) ($compteursAuto['affectation.changee'] ?? 0);

        return [
            'total' => (int) ($statsVolumes->total ?? 0),
            'en_attente' => $totalEnAttente,
            'valides' => (int) ($statsVolumes->valides ?? 0),
            'rejetes' => (int) ($statsVolumes->rejetes ?? 0),
            'souffrance_48h' => $souffrance48h,
            'souffrance_7j' => $souffrance7j,
            'delai_moyen_heures' => $delaiMoyenHeures,
            'delai_moyen_jours' => round($delaiMoyenHeures / 24, 1),
            'valides_aujourdhui' => $validesAujourdhui,
            'rejetes_aujourdhui' => $rejetesAujourdhui,
            'actions' => [
                self::ACTION_A_INSTRUIRE => $aInstruireCount,
                self::ACTION_COMPLEMENT_ATTENDU => $avecComplementCount,
                self::ACTION_ANOMALIE_DETECTEE => $avecAnomalieCount,
                self::ACTION_A_AFFECTER => $aAffecterCount,
                self::ACTION_TRAITE => max(0, $traitesCount),
            ],
            'affectations_auto' => [
                'reussies' => $reussiesAuto,
                'non_assignees' => $nonAssigneesAuto,
                'tentatives' => $tentativesAuto,
                'taux_reussite' => $tentativesAuto > 0
                    ? round($reussiesAuto * 100 / $tentativesAuto, 1)
                    : null,
            ],
            'changements_groupe' => [
                'effectues' => $changementsEffectues,
            ],
        ];
    }

    /**
     * Souffrance et délai moyen à partir de la dernière transition vers
     * `en_attente` (SituationEtudiant), avec repli documenté sur
     * `etudiants.created_at` pour les dossiers historiques sans situation.
     *
     * @param  array<int, int>  $enAttenteIds
     * @return array{0: int, 1: int, 2: int}
     */
    private function metriquesAttente(array $enAttenteIds, Carbon $seuil48h, Carbon $seuil7j, Carbon $maintenant): array
    {
        if ($enAttenteIds === []) {
            return [0, 0, 0];
        }

        $situations = SituationEtudiant::whereIn('etudiant_id', $enAttenteIds)
            ->where('type', 'statut')
            ->where('nouvelle_valeur', 'en_attente')
            ->whereIn('id', function ($sub) use ($enAttenteIds) {
                $sub->selectRaw('MAX(id)')
                    ->from('situations_etudiant')
                    ->whereIn('etudiant_id', $enAttenteIds)
                    ->groupBy('etudiant_id');
            })
            ->pluck('created_at', 'etudiant_id');

        $creations = Etudiant::whereIn('id', $enAttenteIds)->pluck('created_at', 'id');

        $souffrance48h = 0;
        $souffrance7j = 0;
        $totalHeures = 0;
        $compteur = 0;

        foreach ($enAttenteIds as $id) {
            $debut = $situations[$id] ?? $creations[$id] ?? null;

            if ($debut !== null) {
                $carbon = Carbon::parse($debut);
                if ($carbon->lessThanOrEqualTo($seuil48h)) {
                    $souffrance48h++;
                }
                if ($carbon->lessThanOrEqualTo($seuil7j)) {
                    $souffrance7j++;
                }
                $totalHeures += max(0, $carbon->diffInHours($maintenant));
            }

            // Une date nulle reste comptée au dénominateur (0h), comme avant.
            $compteur++;
        }

        return [$souffrance48h, $souffrance7j, (int) round($totalHeures / max(1, $compteur))];
    }

    /**
     * Décisions (valide/rejete) enregistrées aujourd'hui, sur la dernière
     * situation de statut. Repli `updated_at` uniquement pour les dossiers
     * sans aucune SituationEtudiant (données historiques/imports).
     *
     * @return array<string, int>
     */
    private function decisionsDuJour(Builder $base, Carbon $debutJour): array
    {
        $baseIds = (clone $base)->select('etudiants.id')->toBase();

        $decisions = SituationEtudiant::query()
            ->whereIn('etudiant_id', $baseIds)
            ->where('type', 'statut')
            ->whereIn('id', function ($sub) use ($baseIds) {
                $sub->selectRaw('MAX(id)')
                    ->from('situations_etudiant')
                    ->whereIn('etudiant_id', $baseIds)
                    ->groupBy('etudiant_id');
            })
            ->whereDate('date_effet', $debutJour->toDateString())
            ->whereIn('nouvelle_valeur', ['valide', 'rejete'])
            ->selectRaw('nouvelle_valeur, COUNT(*) as total')
            ->groupBy('nouvelle_valeur')
            ->pluck('total', 'nouvelle_valeur');

        // Repli historique : aucune situation de statut pour ce dossier.
        $legacy = (clone $base)
            ->whereIn('etudiants.statut', ['valide', 'rejete'])
            ->where('etudiants.updated_at', '>=', $debutJour)
            ->whereNotExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('situations_etudiant')
                    ->whereColumn('situations_etudiant.etudiant_id', 'etudiants.id')
                    ->where('type', 'statut');
            })
            ->selectRaw('etudiants.statut as statut, COUNT(*) as total')
            ->groupBy('etudiants.statut')
            ->pluck('total', 'statut');

        return [
            'valide' => (int) ($decisions['valide'] ?? 0) + (int) ($legacy['valide'] ?? 0),
            'rejete' => (int) ($decisions['rejete'] ?? 0) + (int) ($legacy['rejete'] ?? 0),
        ];
    }

    /**
     * Détermine l'action attendue unitaire pour un étudiant.
     */
    public function determinerActionAttendue(Etudiant $etudiant, bool $aComplement = false, ?string $dernierVerdict = null): string
    {
        if ($etudiant->statut === 'rejete') {
            return self::ACTION_TRAITE;
        }

        if ($etudiant->statut === 'valide') {
            return $etudiant->groupe_id === null ? self::ACTION_A_AFFECTER : self::ACTION_TRAITE;
        }

        // Cas statut === 'en_attente'
        if ($aComplement) {
            return self::ACTION_COMPLEMENT_ATTENDU;
        }

        if ($dernierVerdict !== null && in_array($dernierVerdict, ['BLOQUANT', 'A_VERIFIER'], true)) {
            return self::ACTION_ANOMALIE_DETECTEE;
        }

        return self::ACTION_A_INSTRUIRE;
    }

    /**
     * Enrichit une collection ou un paginateur d'étudiants en pré-chargeant
     * les compléments et verdicts en 2 requêtes globales (zéro N+1).
     *
     * @param  Collection<int, Etudiant>|LengthAwarePaginator<int, Etudiant>  $etudiants
     * @return Collection<int, Etudiant>|LengthAwarePaginator<int, Etudiant>
     */
    public function enrichirEtudiants(Collection|LengthAwarePaginator $etudiants): Collection|LengthAwarePaginator
    {
        $items = $etudiants instanceof LengthAwarePaginator ? $etudiants->items() : $etudiants->all();

        if (empty($items)) {
            return $etudiants;
        }

        $ids = array_map(fn (Etudiant $e) => (int) $e->id, $items);

        // 1. Corrections ouvertes (proposées ou approuvées non appliquées).
        // Une correction approuvée reste visible même si elle n'est pas
        // encore appliquée (D-07) ; seule une proposition ouvre l'action
        // « complément attendu » tant que M-03 n'est pas arbitré.
        $correctionsOuvertes = DossierCorrection::whereIn('etudiant_id', $ids)
            ->whereIn('statut', ['proposee', 'approuvee'])
            ->get(['id', 'etudiant_id', 'champ', 'statut'])
            ->groupBy('etudiant_id');

        // 2. Derniers verdicts + codes de règles en échec du dernier run
        // (LOOP C : source de la catégorisation dérivée — 1 requête batch,
        // jamais de ré-exécution du moteur).
        $derniersRuns = DossierVerification::whereIn('etudiant_id', $ids)
            ->whereIn('id', function ($sub) use ($ids) {
                $sub->selectRaw('MAX(id)')
                    ->from('dossier_verifications')
                    ->whereIn('etudiant_id', $ids)
                    ->groupBy('etudiant_id');
            })
            ->get(['etudiant_id', 'verdict', 'regles_echouees'])
            ->keyBy('etudiant_id');

        $maintenant = Carbon::now();

        foreach ($items as $etudiant) {
            $corrections = $correctionsOuvertes->get($etudiant->id, collect());
            $aComplement = $corrections->contains('statut', 'proposee');
            $run = $derniersRuns->get($etudiant->id);
            $dernierVerdict = $run?->verdict;
            $reglesEchouees = $run?->regles_echouees ?? [];

            $action = $this->determinerActionAttendue($etudiant, $aComplement, $dernierVerdict);

            $heures = $etudiant->created_at !== null ? max(0, $etudiant->created_at->diffInHours($maintenant)) : 0;
            $jours = $etudiant->created_at !== null ? max(0, (int) $etudiant->created_at->diffInDays($maintenant)) : 0;

            $etudiant->action_attendue = $action;
            $etudiant->action_attendue_libelle = self::ACTIONS_ATTENDUES[$action] ?? $action;
            $etudiant->anciennete_heures = $heures;
            $etudiant->anciennete_jours = $jours;
            $etudiant->est_en_souffrance = ($etudiant->statut === 'en_attente' && $heures >= 48);
            $etudiant->dernier_verdict = $dernierVerdict;
            $etudiant->corrections_ouvertes = $corrections;
            $etudiant->corrections_proposees_count = $corrections->where('statut', 'proposee')->count();
            $etudiant->corrections_approuvees_count = $corrections->where('statut', 'approuvee')->count();

            // LOOP C : catégorie dérivée + motif (codes seuls, sans PII)
            // + prochaine action déterministe.
            [$categorie, $motif] = $this->determinerCategorie(
                $etudiant, $aComplement, $dernierVerdict, $reglesEchouees
            );
            $etudiant->categorie_file = $categorie;
            $etudiant->categorie_libelle = self::CATEGORIES_FILE[$categorie] ?? $categorie;
            $etudiant->categorie_motif = $motif;
            $etudiant->prochaine_action = self::PROCHAINES_ACTIONS[$categorie] ?? '—';

            // Attributs calculés = lecture seule (AUDIT LOOP C) : ils ne
            // doivent JAMAIS fuiter dans une sauvegarde ultérieure du
            // modèle (statut, affectation, colonnes inexistantes). Les
            // modèles arrivent fraîchement chargés de la requête de liste.
            $etudiant->syncOriginal();
        }

        return $etudiants;
    }

    /**
     * Catégorie dérivée (LOOP C) — miroir de `determinerActionAttendue`,
     * raffinée par famille de règles en échec. Retour [categorie, motif].
     *
     * @param  array<int, string>  $reglesEchouees
     * @return array{0: string, 1: string}
     */
    private function determinerCategorie(
        Etudiant $etudiant,
        bool $aComplement,
        ?string $dernierVerdict,
        array $reglesEchouees
    ): array {
        if ($etudiant->statut === 'rejete') {
            return [self::CATEGORIE_COMPLETED, 'Dossier rejeté (workflow terminé).'];
        }

        if ($etudiant->statut === 'valide') {
            return $etudiant->groupe_id !== null
                ? [self::CATEGORIE_ASSIGNED, 'Inscription validée, groupe affecté.']
                : [self::CATEGORIE_READY_FOR_ASSIGNMENT, 'Inscription validée, aucun groupe affecté.'];
        }

        // statut en_attente — priorité miroir : complément d'abord.
        if ($aComplement) {
            return [self::CATEGORIE_MISSING_DOCUMENTS, 'Complément ouvert en attente de pièces.'];
        }

        $estAnomalie = $dernierVerdict !== null
            && in_array($dernierVerdict, ['BLOQUANT', 'A_VERIFIER'], true);

        if ($estAnomalie) {
            foreach (self::FAMILLES_REGLES as $prefixe => $categorie) {
                foreach ($reglesEchouees as $code) {
                    if (str_starts_with((string) $code, $prefixe)) {
                        return [$categorie, 'Règles en échec : '.implode(', ', $reglesEchouees).". Verdict {$dernierVerdict}."];
                    }
                }
            }

            if ($dernierVerdict === 'BLOQUANT') {
                // Runs antérieurs à la migration (codes absents) ou règles
                // non spécialisées : repli documenté sur le verdict seul.
                return [self::CATEGORIE_BLOCKED, 'Verdict bloquant du dernier run de vérification.'];
            }

            return [self::CATEGORIE_READY_FOR_REVIEW, 'Points à examiner signalés par le dernier run (aucune famille spécialisée).'];
        }

        return [self::CATEGORIE_READY_FOR_REVIEW, 'Aucun signal bloquant : dossier à instruire.'];
    }

    /**
     * Filtre SQL par catégorie dérivée (LOOP C) — traduction serveur des
     * MÊMES prédicats que `determinerCategorie` (jamais de recalcul
     * applicatif post-pagination). Le sous-requête « dernier run par
     * étudiant » est le pattern déjà utilisé par l'action attendue.
     *
     * @param  Builder<Etudiant>  $query
     * @return Builder<Etudiant>
     */
    public function contrainteCategorie($query, string $categorie)
    {
        $dernierRun = function ($sub, ?string $likeCode = null, $verdict = null, bool $sansFamilles = false) {
            $sub->select('etudiant_id')
                ->from('dossier_verifications')
                ->whereColumn('etudiant_id', 'etudiants.id')
                ->where('id', function ($sq) {
                    $sq->selectRaw('MAX(id)')->from('dossier_verifications', 'dv2')
                        ->whereColumn('dv2.etudiant_id', 'dossier_verifications.etudiant_id');
                });

            if ($likeCode !== null) {
                $sub->where('regles_echouees', 'like', '%'.$likeCode.'%');
            }

            if (is_array($verdict)) {
                $sub->whereIn('verdict', $verdict);
            } elseif ($verdict !== null) {
                $sub->where('verdict', $verdict);
            }

            if ($sansFamilles) {
                $sub->where(function ($q) {
                    $q->whereNull('regles_echouees')
                        ->orWhereNot(function ($qq) {
                            $qq->where('regles_echouees', 'like', '%"mens.%')
                                ->orWhere('regles_echouees', 'like', '%"identite.%')
                                ->orWhere('regles_echouees', 'like', '%"doublons.%')
                                ->orWhere('regles_echouees', 'like', '%"documents.%')
                                ->orWhere('regles_echouees', 'like', '%"pieces.%');
                        });
                });
            }
        };

        // Une famille n'est retenue que pour un dernier run non conforme.
        $famille = fn (string $prefixe) => fn ($sub) => $dernierRun(
            $sub, $prefixe, ['BLOQUANT', 'A_VERIFIER']
        );

        $complementExiste = function ($sub) {
            $sub->select('etudiant_id')->from('dossier_corrections')
                ->whereColumn('etudiant_id', 'etudiants.id')
                ->where('statut', 'proposee');
        };

        $anomalieRun = function ($sub) {
            $sub->select('etudiant_id')->from('dossier_verifications')
                ->whereColumn('etudiant_id', 'etudiants.id')
                ->where('id', function ($sq) {
                    $sq->selectRaw('MAX(id)')->from('dossier_verifications', 'dv2')
                        ->whereColumn('dv2.etudiant_id', 'dossier_verifications.etudiant_id');
                })
                ->whereIn('verdict', ['BLOQUANT', 'A_VERIFIER']);
        };

        switch ($categorie) {
            case self::CATEGORIE_MENS_REVIEW:
                // Le complément est prioritaire sur toute famille (miroir PHP).
                return $query->where('statut', 'en_attente')
                    ->whereNotExists($complementExiste)
                    ->whereExists($famille('mens.'));

            case self::CATEGORIE_IDENTITY_REVIEW:
                return $query->where('statut', 'en_attente')
                    ->whereNotExists($complementExiste)
                    ->whereExists($famille('identite.'))
                    ->whereNotExists($famille('mens.'));

            case self::CATEGORIE_DUPLICATE_REVIEW:
                return $query->where('statut', 'en_attente')
                    ->whereNotExists($complementExiste)
                    ->whereExists($famille('doublons.'))
                    ->whereNotExists($famille('mens.'))
                    ->whereNotExists($famille('identite.'));

            case self::CATEGORIE_MISSING_DOCUMENTS:
                return $query->where('statut', 'en_attente')->where(function ($q) use ($complementExiste, $famille) {
                    // Complément ouvert : catégorie prioritaire absolue.
                    $q->whereExists($complementExiste)
                        ->orWhere(function ($q2) use ($famille) {
                            // Sinon documents/pièces, uniquement si aucune
                            // famille de priorité supérieure n'est présente.
                            $q2->whereNotExists($famille('mens.'))
                                ->whereNotExists($famille('identite.'))
                                ->whereNotExists($famille('doublons.'))
                                ->where(function ($q3) use ($famille) {
                                    $q3->whereExists($famille('documents.'))
                                        ->orWhereExists($famille('pieces.'));
                                });
                        });
                });

            case self::CATEGORIE_BLOCKED:
                return $query->where('statut', 'en_attente')
                    ->whereNotExists($complementExiste)
                    ->whereExists(fn ($q) => $dernierRun($q, null, 'BLOQUANT', true));

            case self::CATEGORIE_READY_FOR_REVIEW:
                // D-03 : inclut le repli A_VERIFIER sans famille, comme le
                // classificateur applicatif. BLOQUANT sans famille reste
                // BLOCKED ; toute famille spécialisée reste dans sa catégorie.
                return $query->where('statut', 'en_attente')
                    ->whereNotExists($complementExiste)
                    ->where(function ($q) use ($anomalieRun, $dernierRun) {
                        $q->whereNotExists($anomalieRun)
                            ->orWhereExists(fn ($sq) => $dernierRun($sq, null, 'A_VERIFIER', true));
                    });

            case self::CATEGORIE_READY_FOR_ASSIGNMENT:
                return $query->where('statut', 'valide')->whereNull('groupe_id');

            case self::CATEGORIE_ASSIGNED:
                return $query->where('statut', 'valide')->whereNotNull('groupe_id');

            case self::CATEGORIE_COMPLETED:
                return $query->where('statut', 'rejete');
        }

        return $query;
    }

    /**
     * Enregistre un commentaire d'instruction administrative avec motif normalisé
     * et traçabilité complète dans l'audit académique.
     *
     * @throws DomainException
     */
    public function ajouterCommentaireInstruction(
        Etudiant $etudiant,
        User $agent,
        string $commentaire,
        ?string $motifCle = null
    ): void {
        Gate::authorize('update', $etudiant);

        if (method_exists($agent, 'peutAccederFaculte') && ! $agent->peutAccederFaculte((int) $etudiant->faculte_id)) {
            abort(403, 'Accès interdit : étudiant hors de votre faculté.');
        }

        $texte = trim($commentaire);
        if ($texte === '') {
            throw new DomainException('Le commentaire d’instruction ne peut pas être vide.');
        }

        $motifLibelle = null;
        if ($motifCle !== null && isset(self::MOTIFS_NORMALISES[$motifCle])) {
            $motifLibelle = self::MOTIFS_NORMALISES[$motifCle];
        }

        DB::transaction(function () use ($etudiant, $agent, $texte, $motifCle, $motifLibelle) {
            // 1. Mettre à jour l'observation sur la dernière inscription académique si présente
            /** @var InscriptionAcademique|null $inscription */
            $inscription = $etudiant->inscriptionsAcademiques()->latest('annee_universitaire_id')->first();
            if ($inscription !== null) {
                $observationPrecedente = trim((string) $inscription->observation);
                $datePrefixe = Carbon::now()->format('d/m/Y H:i');
                $nouvelleObservation = "[{$datePrefixe} - {$agent->name}] {$texte}";
                if ($motifLibelle !== null) {
                    $nouvelleObservation = "({$motifLibelle}) ".$nouvelleObservation;
                }

                $inscription->update([
                    'observation' => $observationPrecedente !== ''
                        ? $nouvelleObservation."\n---\n".$observationPrecedente
                        : $nouvelleObservation,
                ]);
            }

            // 2. Traçabilité d'audit officielle (sans PII sensible)
            $this->audit->tracer(
                $etudiant,
                HistoriqueAcademique::ACTION_COMMENTAIRE_INSTRUCTION,
                'etudiant',
                $etudiant->id,
                null,
                [
                    'agent_nom' => $agent->name,
                    'motif_cle' => $motifCle,
                    'motif_libelle' => $motifLibelle,
                    'longueur_commentaire' => mb_strlen($texte),
                    'apercu' => mb_substr($texte, 0, 80),
                ],
                'web'
            );
        });
    }
}
