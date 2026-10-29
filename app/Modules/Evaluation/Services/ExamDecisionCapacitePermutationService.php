<?php

namespace App\Modules\Evaluation\Services;

use App\Models\AnneeUniversitaire;
use App\Models\IntegrationLog;
use App\Models\User;
use App\Modules\Evaluation\Events\EvaluationChanged;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\PlanningValidation;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\ScheduleAvailability;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Services\ExamScheduleEngineService;
use App\Modules\Versioning\Models\AuditVersioning;
use App\Modules\Versioning\Services\AuditVersioningService;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Décision D-04 V2 — capacité utile, permutations et combinaisons de salles.
 *
 * Principes opposables :
 *  - la jauge utile (`ExamScheduleEngineService::capaciteUtileExamen`) est la
 *    SEULE limite opérationnelle ; la capacité nominale n'autorise jamais un
 *    dépassement ;
 *  - une proposition n'est jamais officielle : l'application exige une
 *    décision humaine, une justification, une revalidation serveur et ne
 *    touche que les épreuves non figées par la gouvernance ;
 *  - seuls les étudiants éligibles à la matière et aux groupes concernés
 *    peuvent être répartis (`CorrespondanceExamenService`) ;
 *  - l'historique est durable (`audit_versioning`) et l'annulation d'une
 *    décision non publiée restaure exactement l'état antérieur.
 *
 * Aucune migration : les colonnes existantes `evaluation_epreuves.salle_id`,
 * `evaluation_candidats.salle_id` et `evaluation_candidats.place` suffisent
 * (Candidat::salleEffective() propage vers convocations, émargement et PDF).
 */
class ExamDecisionCapacitePermutationService
{
    public const PREFIXE_CACHE = 'examens.d04.capacite';

    public const ENTITE = 'evaluation_epreuve_capacite';

    public const ACTION_PROPOSEE = 'examen_capacite_proposee';

    public const ACTION_ACCEPTEE = 'examen_capacite_acceptee';

    public const ACTION_REFUSEE = 'examen_capacite_refusee';

    public const ACTION_ANNULEE = 'examen_capacite_annulee';

    public const STATUT_PROPOSEE = 'proposee';

    public const STATUT_ACCEPTEE = 'acceptee';

    public const STATUT_REFUSEE = 'refusee';

    public const STATUT_ANNULEE = 'annulee';

    /** Types de variantes comparables (ordre de préférence métier). */
    public const TYPES_VARIANTES = [
        'reaffectation' => 'Réaffectation simple',
        'permutation' => 'Permutation croisée',
        'combinaison' => 'Combinaison de salles',
        'mixte' => 'Stratégie mixte',
    ];

    /** Statuts d'épreuve autorisant une écriture directe (hors circuit de gel). */
    public const STATUTS_EPREUVE_MODIFIABLES = ['draft', 'scheduled'];

    /** Statuts d'examen autorisant une écriture directe. */
    public const STATUTS_EXAMEN_MODIFIABLES = ['draft', 'planned', 'scheduled'];

    public function __construct(
        private ExamScheduleEngineService $moteurPlanning,
        private ConflitEpreuveService $conflits,
        private SurveillanceConstraintResolver $surveillances,
        private AuditVersioningService $audit,
        private CorrespondanceExamenService $correspondance,
    ) {}

    /* ---------------------------------------------------------------------
     | Lecture / payload de vue
     * ------------------------------------------------------------------- */

    /**
     * Payload complet de la section « Décision de capacité et permutations ».
     * Ne réalise AUCUNE écriture.
     */
    public function payloadVue(
        ?int $anneeId,
        ?int $faculteId,
        ?int $sessionId = null,
        ?int $examenId = null,
        ?int $epreuveId = null,
        ?int $utilisateurId = null,
    ): array {
        $utilisateur = $utilisateurId !== null ? User::find($utilisateurId) : auth()->user();
        $peutAgir = $utilisateur !== null && Gate::forUser($utilisateur)->allows('epreuves.update');

        $base = [
            'disponible' => false,
            'motif_indisponibilite' => null,
            'annee_id' => $anneeId,
            'session_id' => $sessionId,
            'examen_id' => $examenId,
            'epreuve_id' => $epreuveId,
            'faculte_id' => $faculteId,
            'peut_agir' => $peutAgir,
            'ratio' => $this->ratio(),
            'parametres' => $this->parametres(),
            'annees' => $this->optionsAnnees(),
            'sessions' => $this->optionsSessions($anneeId, $faculteId),
            'examens' => $this->optionsExamens($anneeId, $faculteId),
            'epreuves' => $this->optionsEpreuves($anneeId, $faculteId, $sessionId, $examenId),
            'kpis' => $this->kpisVides(),
            'lignes' => [],
            'conflits' => [],
            'propositions_recentes' => [],
            'historiographie' => [],
            'derniere_decision' => null,
            'empreinte' => null,
            'portee' => $this->portee($anneeId, $faculteId, $sessionId, $examenId, $epreuveId),
        ];

        if ($anneeId === null) {
            $base['motif_indisponibilite'] = 'Sélectionnez une année universitaire pour lancer l’analyse de capacité.';

            return $base;
        }

        try {
            $this->verifierPortee($faculteId, $examenId, $epreuveId);
        } catch (DomainException $exception) {
            $base['motif_indisponibilite'] = $exception->getMessage();

            return $base;
        }

        $analyse = $this->analyserPortee($anneeId, $faculteId, $sessionId, $examenId, $epreuveId);

        if ($analyse['contexte']['epreuves'] === []) {
            $base['motif_indisponibilite'] = 'Aucune épreuve non annulée ne correspond au périmètre sélectionné.';

            return $base;
        }

        $base['disponible'] = true;
        $base['kpis'] = $analyse['kpis'];
        $base['lignes'] = array_values($analyse['lignes']);
        $base['conflits'] = $this->agregerConflits($analyse['lignes']);
        $base['empreinte'] = $analyse['contexte']['empreinte'];
        $base['tronque'] = $analyse['contexte']['tronque'];
        $base['propositions_recentes'] = $this->propositionsRecentes(
            $anneeId,
            $faculteId,
            $sessionId,
            $examenId,
            $epreuveId,
            $analyse['contexte']['empreinte'],
        );
        $base['historiographie'] = $this->historique($anneeId, $faculteId, $examenId, $epreuveId, 15);
        $base['derniere_decision'] = $this->derniereDecisionAnnulable($anneeId, $faculteId, $examenId, $epreuveId);

        return $base;
    }

    /**
     * Diagnostic opposable du périmètre : jauges, dépassements, conflits et variantes D-04.
     * Aucune écriture.
     */
    public function diagnostic(
        ?int $anneeId,
        ?int $faculteId,
        ?int $sessionId = null,
        ?int $examenId = null,
        ?int $epreuveId = null,
    ): array {
        if ($anneeId === null) {
            return [
                'disponible' => false,
                'motif_indisponibilite' => 'Année universitaire requise.',
                'kpis' => $this->kpisVides(),
                'lignes' => [],
                'conflits' => [],
                'empreinte' => null,
            ];
        }

        $this->verifierPortee($faculteId, $examenId, $epreuveId);

        $analyse = $this->analyserPortee($anneeId, $faculteId, $sessionId, $examenId, $epreuveId);

        return [
            'disponible' => $analyse['contexte']['epreuves'] !== [],
            'motif_indisponibilite' => $analyse['contexte']['epreuves'] === []
                ? 'Aucune épreuve non annulée ne correspond au périmètre sélectionné.'
                : null,
            'annee_id' => $anneeId,
            'session_id' => $sessionId,
            'examen_id' => $examenId,
            'epreuve_id' => $epreuveId,
            'faculte_id' => $faculteId,
            'ratio' => $analyse['contexte']['ratio'],
            'kpis' => $analyse['kpis'],
            'lignes' => $analyse['lignes'],
            'conflits' => $this->agregerConflits($analyse['lignes']),
            'empreinte' => $analyse['contexte']['empreinte'],
            'tronque' => $analyse['contexte']['tronque'],
            'portee' => $this->portee($anneeId, $faculteId, $sessionId, $examenId, $epreuveId),
        ];
    }

    /**
     * Génère l'ensemble des propositions D-04 (réaffectation, permutation, combinaison, mixte)
     * pour le périmètre sélectionné, sans effectuer aucune modification en base.
     */
    public function genererPropositions(
        ?int $anneeId,
        ?int $faculteId,
        ?int $sessionId = null,
        ?int $examenId = null,
        ?int $epreuveId = null,
    ): array {
        if ($anneeId === null) {
            return [
                'disponible' => false,
                'compte_total' => 0,
                'variantes' => [],
                'par_epreuve' => [],
                'empreinte' => null,
            ];
        }

        $this->verifierPortee($faculteId, $examenId, $epreuveId);

        $analyse = $this->analyserPortee($anneeId, $faculteId, $sessionId, $examenId, $epreuveId);
        $variantes = [];
        $parEpreuve = [];

        foreach ($analyse['lignes'] as $epId => $ligne) {
            $parEpreuve[$epId] = $ligne['variantes'] ?? [];
            foreach ($ligne['variantes'] ?? [] as $v) {
                $variantes[] = $v;
            }
        }

        return [
            'disponible' => $analyse['contexte']['epreuves'] !== [],
            'annee_id' => $anneeId,
            'faculte_id' => $faculteId,
            'session_id' => $sessionId,
            'examen_id' => $examenId,
            'epreuve_id' => $epreuveId,
            'empreinte' => $analyse['contexte']['empreinte'],
            'compte_total' => count($variantes),
            'variantes' => $variantes,
            'par_epreuve' => $parEpreuve,
        ];
    }

    /**
     * Prévisualise une proposition D-04 (vérifie la faisabilité et la répartition des places)
     * sans persister de modification officielle.
     */
    public function previsualiser(array $proposition, ?int $utilisateurId = null): array
    {
        $epreuveId = (int) ($proposition['epreuve_id'] ?? 0);
        if ($epreuveId <= 0) {
            throw new DomainException('Identifiant d’épreuve manquant pour la prévisualisation.');
        }

        $epreuve = Epreuve::find($epreuveId);
        if ($epreuve === null) {
            throw new DomainException("Épreuve #{$epreuveId} introuvable.");
        }

        $salleIds = $this->extraireSalleIdsDepuisProposition($proposition);

        return $this->proposer($epreuve, [
            'type' => $proposition['type'] ?? 'combinaison',
            'salle_ids' => $salleIds,
            'epreuve_partenaire_id' => $proposition['epreuve_partenaire_id'] ?? null,
            'salle_partenaire_ids' => $proposition['salle_partenaire_ids'] ?? (isset($proposition['salle_partenaire_cible']) ? [(int) $proposition['salle_partenaire_cible']] : []),
            'motif' => $proposition['motif'] ?? 'Prévisualisation D-04',
        ], $utilisateurId);
    }

    /**
     * Compare deux propositions D-04 candidates.
     */
    public function comparerPropositions(array $a, array $b): array
    {
        $capA = (int) ($a['capacite_utile_totale'] ?? $a['capacite_utile_cible'] ?? 0);
        $capB = (int) ($b['capacite_utile_totale'] ?? $b['capacite_utile_cible'] ?? 0);
        $scoreA = (int) ($a['score'] ?? 50);
        $scoreB = (int) ($b['score'] ?? 50);

        return [
            'a' => $a,
            'b' => $b,
            'ecart_capacite_utile' => $capA - $capB,
            'ecart_score' => $scoreA - $scoreB,
            'recommandee' => $scoreA >= $scoreB ? 'a' : 'b',
        ];
    }

    /**
     * Calcule la capacité réelle utile d'une salle selon la règle D-04 (ratio 50% par défaut).
     * Toute salle inactive ou à capacité nominale nulle/inconnue renvoie 0.
     */
    public function capaciteUtileSalle(Room $salle, ?float $ratio = null): int
    {
        if (! $salle->actif || $salle->capacite === null || (int) $salle->capacite <= 0) {
            return 0;
        }

        return $this->moteurPlanning->capaciteUtileExamen($salle, $ratio ?? $this->ratio());
    }

    /**
     * Détermine si une salle est un amphithéâtre (par type, code, nom ou capacité nominale >= 100).
     */
    public function estAmphitheatre(Room $salle): bool
    {
        return $salle->estAmphitheatre()
            || in_array(strtolower((string) ($salle->type ?? '')), ['amphi', 'amphitheatre'], true)
            || in_array(strtolower((string) ($salle->type_salle ?? '')), ['amphi', 'amphitheatre'], true)
            || (int) ($salle->capacite ?? 0) >= 100;
    }

    /* ---------------------------------------------------------------------
     | Chargement du contexte (lecture groupée, aucune N+1)
     * ------------------------------------------------------------------- */

    /**
     * @return array{contexte: array, lignes: array<int, array>, kpis: array}
     */
    private function analyserPortee(
        int $anneeId,
        ?int $faculteId,
        ?int $sessionId,
        ?int $examenId,
        ?int $epreuveId,
    ): array {
        $contexte = $this->chargerContexte($anneeId, $faculteId, $sessionId, $examenId, $epreuveId);

        $lignes = [];
        foreach ($contexte['epreuves'] as $epreuve) {
            $lignes[$epreuve->id] = $this->analyserEpreuve($epreuve, $contexte);
        }

        return [
            'contexte' => $contexte,
            'lignes' => $lignes,
            'kpis' => $this->calculerKpis($lignes, $contexte),
        ];
    }

    /**
     * Charge en un nombre borné de requêtes : épreuves du périmètre,
     * occupation globale des salles/groupes/étudiants, indisponibilités,
     * cours réguliers et besoins de surveillance.
     */
    private function chargerContexte(
        int $anneeId,
        ?int $faculteId,
        ?int $sessionId,
        ?int $examenId,
        ?int $epreuveId,
        bool $verrouiller = false,
    ): array {
        $limite = $this->parametres()['max_epreuves'];

        $requete = Epreuve::query()
            ->where('annee_universitaire_id', $anneeId)
            ->where('statut', '!=', 'cancelled')
            ->when($sessionId !== null, fn ($q) => $q->where('session_id', $sessionId))
            ->when($examenId !== null, fn ($q) => $q->where('examen_id', $examenId))
            ->when($epreuveId !== null, fn ($q) => $q->where('id', $epreuveId))
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->when($verrouiller, fn ($q) => $q->lockForUpdate())
            ->with([
                'matiere:id,code,nom,ue_id,niveau,semestre',
                'groupe:id,code,nom,faculte_id,programme_id,niveau,niveau_id,capacite,capacite_illimitee',
                'salle',
                'examen:id,code,libelle,statut,programme_id,niveau_id,groupe_id',
                'session:id,code,nom,statut,verrouillee_at',
            ])
            ->orderBy('date')
            ->orderBy('heure_debut')
            ->orderBy('id');

        $total = (clone $requete)->count();
        $epreuves = $requete->limit($limite)->get();
        $tronque = $total > $limite;

        $epreuves->load([
            'candidats' => fn ($q) => $q->select('id', 'epreuve_id', 'etudiant_id', 'salle_id', 'place', 'statut')
                ->when($verrouiller, fn ($sq) => $sq->lockForUpdate())
                ->with('etudiant:id,nom,prenom,num_inscription,groupe_id,faculte_id')
                ->orderBy('id'),
        ]);

        $salles = Room::query()->orderBy('code')->get()->keyBy('id');

        $contexte = [
            'annee_id' => $anneeId,
            'faculte_id' => $faculteId,
            'session_id' => $sessionId,
            'examen_id' => $examenId,
            'epreuve_id' => $epreuveId,
            'ratio' => $this->ratio(),
            'epreuves' => $epreuves->keyBy('id')->all(),
            'toutes_epreuves_jour' => [],
            'salles' => $salles->all(),
            'salles_ordonnees' => $salles->keys()->all(),
            'occupation_salles' => [],
            'occupation_groupes' => [],
            'occupation_etudiants' => [],
            'indisponibilites' => [],
            'cours' => [],
            'surveillances' => [],
            'examens_publies' => [],
            'tronque' => $tronque,
            'empreinte' => '',
        ];

        if ($epreuves->isEmpty()) {
            $contexte['empreinte'] = hash('sha256', (string) json_encode(['vide', $anneeId, $faculteId]));

            return $contexte;
        }

        $dates = $epreuves->map(fn (Epreuve $e) => $e->date->format('Y-m-d'))->unique()->values()->all();
        $datesRequete = array_values(array_unique(array_merge(
            $dates,
            array_map(fn (string $d) => $d.' 00:00:00', $dates),
        )));
        $idsPortee = $epreuves->pluck('id')->all();

        // Occupation globale : toutes les épreuves des mêmes journées, afin
        // qu'une salle occupée par une autre composante ne soit jamais
        // proposée, mais puisse être identifiée pour une permutation croisée.
        $toutes = Epreuve::query()
            ->whereIn('date', $datesRequete)
            ->where('statut', '!=', 'cancelled')
            ->when($verrouiller, fn ($q) => $q->lockForUpdate())
            ->with([
                'matiere:id,code,nom,ue_id,niveau,semestre',
                'groupe:id,code,nom,faculte_id,programme_id,niveau,niveau_id,capacite',
                'salle',
                'examen:id,code,libelle,statut,programme_id,niveau_id,groupe_id',
                'session:id,code,nom,statut,verrouillee_at',
                'candidats' => fn ($q) => $q->select('id', 'epreuve_id', 'etudiant_id', 'salle_id', 'place', 'statut')
                    ->when($verrouiller, fn ($sq) => $sq->lockForUpdate())
                    ->with('etudiant:id,nom,prenom,num_inscription,groupe_id,faculte_id')
                    ->orderBy('id'),
            ])
            ->get();

        $contexte['toutes_epreuves_jour'] = $toutes->keyBy('id')->all();

        foreach ($toutes as $autre) {
            $entree = [
                'epreuve_id' => (int) $autre->id,
                'code' => (string) $autre->code,
                'date' => $autre->date->format('Y-m-d'),
                'debut' => $this->heure($autre->heure_debut),
                'fin' => $this->heure($autre->heure_fin),
            ];

            $sallesAutre = [];
            if ($autre->salle_id !== null) {
                $sallesAutre[(int) $autre->salle_id] = true;
            }

            foreach ($autre->candidats as $candidat) {
                if ($candidat->salle_id !== null) {
                    $sallesAutre[(int) $candidat->salle_id] = true;
                }

                if ($candidat->etudiant_id !== null) {
                    $contexte['occupation_etudiants'][(int) $candidat->etudiant_id][] = $entree;
                }
            }

            foreach (array_keys($sallesAutre) as $sid) {
                $contexte['occupation_salles'][$sid][] = $entree;
            }

            if ($autre->groupe_id !== null) {
                $contexte['occupation_groupes'][(int) $autre->groupe_id][] = $entree;
            }
        }

        $roomIds = $salles->keys()->all();

        $contexte['indisponibilites'] = ScheduleAvailability::query()
            ->indisponibles()
            ->whereIn('room_id', $roomIds)
            ->get()
            ->groupBy('room_id')
            ->map(fn ($liste) => $liste->map(fn (ScheduleAvailability $dispo) => [
                'day' => strtolower((string) $dispo->day_of_week),
                'debut' => $this->heure($dispo->start_time),
                'fin' => $this->heure($dispo->end_time),
                'date_debut' => $dispo->effective_from?->format('Y-m-d'),
                'date_fin' => $dispo->effective_to?->format('Y-m-d'),
                'motif' => $dispo->reason,
            ])->all())
            ->all();

        $contexte['cours'] = ScheduleSlot::query()
            ->whereIn('room_id', $roomIds)
            ->where('status', '!=', 'cancelled')
            ->with(['schedule:id,groupe_id,starts_at,ends_at', 'matiere:id,code,nom'])
            ->get()
            ->groupBy('room_id')
            ->map(fn ($liste) => $liste->map(fn (ScheduleSlot $slot) => [
                'day' => strtolower((string) $slot->day_of_week),
                'debut' => $this->heure($slot->start_time),
                'fin' => $this->heure($slot->end_time),
                'date_debut' => $slot->schedule?->starts_at?->format('Y-m-d'),
                'date_fin' => $slot->schedule?->ends_at?->format('Y-m-d'),
                'libelle' => $slot->matiere?->nom ?? $slot->type ?? 'cours',
                'groupe' => $slot->schedule?->groupe?->code,
            ])->all())
            ->all();

        $contexte['surveillances'] = Surveillance::query()
            ->whereIn('epreuve_id', $idsPortee)
            ->where('statut', '!=', 'annule')
            ->selectRaw('epreuve_id, COUNT(*) as total')
            ->groupBy('epreuve_id')
            ->pluck('total', 'epreuve_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $tousExamensIds = $toutes->pluck('examen_id')->filter()->unique()->values()->all();
        $contexte['examens_publies'] = $tousExamensIds === []
            ? []
            : PlanningValidation::query()
                ->whereIn('examen_id', $tousExamensIds)
                ->where('statut', PlanningValidation::STATUT_PUBLIE)
                ->pluck('examen_id')
                ->map(fn ($v) => (int) $v)
                ->all();

        $contexte['empreinte'] = $this->calculerEmpreinte($contexte);

        return $contexte;
    }

    /* ---------------------------------------------------------------------
     | Analyse d'une épreuve & Génération des variantes D-04
     * ------------------------------------------------------------------- */

    /**
     * Analyse la conformité capacitaire D-04 d'une épreuve et calcule les propositions
     * de réaffectation, permutation croisée (amphi ↔ salle normale), combinaison multi-salles et mixte.
     */
    private function analyserEpreuve(Epreuve $epreuve, array $contexte): array
    {
        $analyseCorrespondance = $this->correspondance->analyserEpreuve($epreuve);
        $idsEligiblesRef = array_flip($this->correspondance->identifiantsEligiblesReference($epreuve));
        $modeStrict = $this->correspondance->estModePedagogiqueStrict($epreuve);

        $candidats = collect($epreuve->candidats);
        $candidatsEligibles = $candidats
            ->filter(function (Candidat $c) use ($modeStrict, $idsEligiblesRef, $analyseCorrespondance) {
                if ($modeStrict) {
                    return isset($idsEligiblesRef[(int) $c->etudiant_id]);
                }

                $nonEligiblesIds = array_column($analyseCorrespondance['ecarts']['candidats_non_eligibles'] ?? [], 'etudiant_id');

                return ! in_array((int) $c->etudiant_id, array_map('intval', $nonEligiblesIds), true);
            })
            ->sortBy(fn (Candidat $c) => ($c->etudiant?->nom ?? '').' '.($c->etudiant?->prenom ?? '').' '.str_pad((string) $c->id, 8, '0', STR_PAD_LEFT))
            ->values();

        $effectifCandidatsEligibles = $candidatsEligibles->count();
        $effectifReference = (int) ($analyseCorrespondance['compteurs']['eligibles'] ?? 0);
        $effectifRequis = $effectifCandidatsEligibles > 0
            ? $effectifCandidatsEligibles
            : ($effectifReference > 0
                ? $effectifReference
                : max((int) $epreuve->capacite_necessaire, (int) ($epreuve->groupe?->capacite ?? 0)));

        // Salles actuellement mobilisées par l'épreuve
        $sallesIdsActuelles = [];
        if ($epreuve->salle_id !== null) {
            $sallesIdsActuelles[] = (int) $epreuve->salle_id;
        }
        foreach ($candidats as $c) {
            if ($c->salle_id !== null) {
                $sallesIdsActuelles[] = (int) $c->salle_id;
            }
        }
        $sallesIdsActuelles = array_values(array_unique($sallesIdsActuelles));

        $sallesActuelles = [];
        $capaciteNominaleTotale = 0;
        $capaciteUtileTotale = 0;
        $conflitsEpreuve = [];
        $capAbsolueOk = $sallesIdsActuelles !== [];
        $motifBloquant = null;

        // 1. Contrôle de correspondance pédagogique
        if (($analyseCorrespondance['ecarts']['candidats_non_eligibles'] ?? []) !== []) {
            $nbNonEligibles = count($analyseCorrespondance['ecarts']['candidats_non_eligibles']);
            $conflitsEpreuve[] = [
                'type' => 'correspondance_candidats',
                'critique' => true,
                'epreuve_id' => $epreuve->id,
                'message' => "{$nbNonEligibles} étudiant(s) planifié(s) ne sont pas inscrits ou éligibles à la matière {$epreuve->matiere?->code}.",
            ];
        }

        if (($analyseCorrespondance['ecarts']['anomalies_groupes'] ?? []) !== []) {
            $conflitsEpreuve[] = [
                'type' => 'correspondance_groupe',
                'critique' => true,
                'epreuve_id' => $epreuve->id,
                'message' => $analyseCorrespondance['ecarts']['anomalies_groupes'][0] ?? CorrespondanceExamenService::ALERTE_GROUPE_INCOHERENT,
            ];
        }

        if (($analyseCorrespondance['ecarts']['eligibles_omis'] ?? []) !== [] && $candidats->isNotEmpty()) {
            $nbOmis = count($analyseCorrespondance['ecarts']['eligibles_omis']);
            $conflitsEpreuve[] = [
                'type' => 'eligibles_omis',
                'critique' => true,
                'epreuve_id' => $epreuve->id,
                'message' => "{$nbOmis} étudiant(s) éligible(s) à la matière {$epreuve->matiere?->code} sont absents de la planification.",
            ];
        }

        // 2. Contrôle des salles actuelles et des jauges utiles D-04
        $surchargeIndividuelleMessage = null;
        if ($sallesIdsActuelles === []) {
            $capAbsolueOk = false;
            $msg = "Épreuve {$epreuve->code} : aucune salle affectée ({$effectifRequis} candidat(s) à placer).";
            $motifBloquant = $msg;
            $conflitsEpreuve[] = [
                'type' => 'salle_absente',
                'critique' => true,
                'epreuve_id' => $epreuve->id,
                'message' => $msg,
            ];
        } else {
            foreach ($sallesIdsActuelles as $sid) {
                /** @var Room|null $salleObj */
                $salleObj = $contexte['salles'][$sid] ?? Room::find($sid);
                if ($salleObj === null) {
                    $capAbsolueOk = false;
                    $msg = "Salle #{$sid} introuvable.";
                    $motifBloquant ??= $msg;
                    $conflitsEpreuve[] = [
                        'type' => 'salle_inexistante',
                        'critique' => true,
                        'epreuve_id' => $epreuve->id,
                        'message' => $msg,
                    ];

                    continue;
                }

                $dispo = $this->evaluerDisponibiliteSallePourEpreuve($salleObj, $epreuve, $contexte, [$epreuve->id]);
                $nbDansSalle = $candidats->filter(fn (Candidat $c) => (int) ($c->salle_id ?? $epreuve->salle_id) === $sid)->count();
                if ($candidats->isEmpty() && count($sallesIdsActuelles) === 1) {
                    $nbDansSalle = $effectifRequis;
                }

                $jaugeUtile = $dispo['capacite_utile_d04'];
                $capaciteNominaleTotale += $dispo['capacite_nominale'];
                $capaciteUtileTotale += $jaugeUtile;

                if ($jaugeUtile <= 0 || ! $salleObj->actif) {
                    $capAbsolueOk = false;
                }

                foreach ($dispo['motifs'] as $motif) {
                    $motifBloquant ??= $motif['message'];
                    $conflitsEpreuve[] = [
                        'type' => $motif['type'],
                        'critique' => true,
                        'epreuve_id' => $epreuve->id,
                        'salle_id' => $sid,
                        'message' => $motif['message'],
                    ];
                }

                if ($jaugeUtile > 0 && $nbDansSalle > $jaugeUtile) {
                    $surchargeIndividuelleMessage ??= "Dépassement de jauge utile D-04 dans {$salleObj->code} : {$nbDansSalle} candidats pour {$jaugeUtile} places utiles (nominale : {$dispo['capacite_nominale']}).";
                }

                $sallesActuelles[] = [
                    'id' => $salleObj->id,
                    'code' => $salleObj->code,
                    'nom' => $salleObj->nom,
                    'est_amphitheatre' => $dispo['est_amphitheatre'],
                    'capacite_nominale' => $dispo['capacite_nominale'],
                    'capacite_utile_d04' => $jaugeUtile,
                    'candidats_affectes' => $nbDansSalle,
                    'surcharge_d04' => $jaugeUtile <= 0 || $nbDansSalle > $jaugeUtile,
                ];
            }

            if ($capaciteUtileTotale < $effectifRequis || $surchargeIndividuelleMessage !== null) {
                $deficit = max(0, $effectifRequis - $capaciteUtileTotale);
                $msgDepassement = $surchargeIndividuelleMessage
                    ?? "Dépassement de jauge utile D-04 pour {$epreuve->code} : {$capaciteUtileTotale} places utiles pour {$effectifRequis} candidats (déficit de {$deficit} place(s)).";
                $motifBloquant ??= $msgDepassement;
                $conflitsEpreuve[] = [
                    'type' => 'depassement',
                    'critique' => true,
                    'epreuve_id' => $epreuve->id,
                    'message' => $msgDepassement,
                ];
            }
        }

        if ($motifBloquant === null && $conflitsEpreuve !== []) {
            $motifBloquant = $conflitsEpreuve[0]['message'] ?? null;
        }

        $deficitPlaces = max(0, $effectifRequis - $capaciteUtileTotale);
        $placesRestantes = max(0, $capaciteUtileTotale - $effectifRequis);
        $enSurcharge = $deficitPlaces > 0 || ! $capAbsolueOk || $surchargeIndividuelleMessage !== null;
        $modifiable = $this->peutModifierEpreuve($epreuve, $contexte);
        $codesSalles = array_column($sallesActuelles, 'code');

        $etatActuel = [
            'epreuve_id' => $epreuve->id,
            'code' => $epreuve->code,
            'matiere' => $epreuve->matiere?->nom ?? $epreuve->matiere?->code ?? '—',
            'matiere_id' => $epreuve->matiere_id,
            'matiere_code' => $epreuve->matiere?->code ?? '—',
            'matiere_nom' => $epreuve->matiere?->nom ?? '—',
            'groupe' => $epreuve->groupe?->code ?? '—',
            'groupe_id' => $epreuve->groupe_id,
            'groupe_code' => $epreuve->groupe?->code ?? 'Tous groupes éligibles',
            'salle' => $codesSalles !== [] ? implode(' + ', $codesSalles) : ($epreuve->salle?->code ?? '—'),
            'date' => $epreuve->date->format('Y-m-d'),
            'heure_debut' => $this->heure($epreuve->heure_debut),
            'heure_fin' => $this->heure($epreuve->heure_fin),
            'effectif_total' => $effectifRequis,
            'effectif_requis' => $effectifRequis,
            'candidats_eligibles_count' => $effectifCandidatsEligibles,
            'capacite_nominale_totale' => $capaciteNominaleTotale,
            'capacite_utile' => $capaciteUtileTotale,
            'capacite_utile_totale' => $capaciteUtileTotale,
            'places_restantes' => $placesRestantes,
            'depassement' => $deficitPlaces,
            'deficit_places' => $deficitPlaces,
            'en_surcharge' => $enSurcharge,
            'cap_absolue_ok' => $capAbsolueOk,
            'motif_bloquant' => $motifBloquant,
            'salles_actuelles' => $sallesActuelles,
            'conforme_d04' => $conflitsEpreuve === [],
            'correspondance_conforme' => (bool) ($analyseCorrespondance['conforme'] ?? true),
            'modifiable' => $modifiable['autorise'],
            'motif_verrouillage' => $modifiable['motif'],
            'conflits' => $conflitsEpreuve,
            'surveillants_affectes' => $contexte['surveillances'][$epreuve->id] ?? 0,
        ];

        $etatActuel['variantes'] = $this->genererVariantesPourEpreuve($epreuve, $etatActuel, $contexte);

        return $etatActuel;
    }

    /**
     * Génère les variantes D-04 pour une épreuve :
     * 1. Réaffectation simple (amphi ou salle ordinaire)
     * 2. Permutation croisée (amphi ↔ salle ordinaire avec une autre épreuve)
     * 3. Combinaison de salles (plusieurs combinaisons d'amphithéâtres et de salles ordinaires)
     * 4. Stratégie mixte (permutation d'amphi + salle(s) ordinaire(s) d'appoint)
     */
    private function genererVariantesPourEpreuve(Epreuve $epreuve, array $etatActuel, array $contexte): array
    {
        $effectifRequis = max(1, (int) $etatActuel['effectif_requis']);
        $sallesIdsActuelles = array_column($etatActuel['salles_actuelles'], 'id');
        $variantes = [];

        // Collecte des salles libres et compatibles sur le créneau de l'épreuve
        $sallesDisponibles = [];
        foreach ($contexte['salles'] as $salle) {
            $eval = $this->evaluerDisponibiliteSallePourEpreuve($salle, $epreuve, $contexte, [$epreuve->id]);
            if ($eval['disponible'] && $eval['capacite_utile_d04'] > 0) {
                $sallesDisponibles[$salle->id] = [
                    'salle' => $salle,
                    'id' => $salle->id,
                    'code' => $salle->code,
                    'nom' => $salle->nom,
                    'batiment_id' => $salle->batiment_id,
                    'batiment' => $salle->batiment ?? 'Campus',
                    'est_amphitheatre' => $eval['est_amphitheatre'],
                    'capacite_nominale' => $eval['capacite_nominale'],
                    'capacite_utile_d04' => $eval['capacite_utile_d04'],
                ];
            }
        }

        // -----------------------------------------------------------------
        // 1. RÉAFFECTATION SIMPLE (une salle seule suffit en jauge utile D-04)
        // -----------------------------------------------------------------
        $sallesUnitaires = array_filter(
            $sallesDisponibles,
            fn (array $s) => $s['capacite_utile_d04'] >= $effectifRequis
                && ! (count($sallesIdsActuelles) === 1 && (int) $sallesIdsActuelles[0] === (int) $s['id'])
        );

        usort($sallesUnitaires, function (array $a, array $b) use ($effectifRequis) {
            $ecartA = $a['capacite_utile_d04'] - $effectifRequis;
            $ecartB = $b['capacite_utile_d04'] - $effectifRequis;

            return $ecartA <=> $ecartB ?: ($a['id'] <=> $b['id']);
        });

        foreach (array_slice($sallesUnitaires, 0, $this->parametres()['max_variantes_par_type']) as $idx => $salleUnitaire) {
            $typeLocal = $salleUnitaire['est_amphitheatre'] ? 'Amphithéâtre' : 'Salle ordinaire';
            $excedent = $salleUnitaire['capacite_utile_d04'] - $effectifRequis;
            $variantes[] = [
                'cle' => 'reaffectation_'.$epreuve->id.'_'.$salleUnitaire['id'],
                'epreuve_id' => $epreuve->id,
                'type' => 'reaffectation',
                'type_libelle' => self::TYPES_VARIANTES['reaffectation'],
                'titre' => "Réaffectation vers {$typeLocal} {$salleUnitaire['code']} ({$salleUnitaire['capacite_utile_d04']} places utiles D-04)",
                'salle_cible' => $salleUnitaire['id'],
                'code_cible' => $salleUnitaire['code'],
                'salles_cibles' => [$salleUnitaire['id']],
                'salle_ids' => [$salleUnitaire['id']],
                'salles' => [$this->resumerSalleVariante($salleUnitaire, $effectifRequis)],
                'capacite_nominale_totale' => $salleUnitaire['capacite_nominale'],
                'capacite_utile_cible' => $salleUnitaire['capacite_utile_d04'],
                'capacite_utile_totale' => $salleUnitaire['capacite_utile_d04'],
                'effectif_couvert' => $effectifRequis,
                'places_restantes_apres' => $excedent,
                'excedent_utile' => $excedent,
                'score' => max(10, 100 - $excedent),
                'obsolete' => false,
                'viable' => true,
                'nb_salles' => 1,
                'nb_amphis' => $salleUnitaire['est_amphitheatre'] ? 1 : 0,
                'nb_salles_ordinaires' => $salleUnitaire['est_amphitheatre'] ? 0 : 1,
                'epreuve_partenaire_id' => null,
                'epreuve_partenaire_code' => null,
                'salle_partenaire_ids' => [],
                'recommandee' => $idx === 0,
                'empreinte' => $contexte['empreinte'],
            ];
        }

        // -----------------------------------------------------------------
        // 2. PERMUTATION CROISÉE (Amphithéâtre ↔ Salle ordinaire)
        // -----------------------------------------------------------------
        if ($epreuve->salle_id !== null && isset($contexte['salles'][$epreuve->salle_id])) {
            /** @var Room $salleCourante */
            $salleCourante = $contexte['salles'][$epreuve->salle_id];
            $jaugeCourante = $this->capaciteUtileSalle($salleCourante);
            $couranteEstAmphi = $this->estAmphitheatre($salleCourante);

            foreach ($contexte['toutes_epreuves_jour'] as $autreId => $autre) {
                if ((int) $autreId === (int) $epreuve->id || $autre->salle_id === null) {
                    continue;
                }
                if ((int) $autre->salle_id === (int) $epreuve->salle_id) {
                    continue;
                }
                if ($autre->date->format('Y-m-d') !== $epreuve->date->format('Y-m-d')) {
                    continue;
                }
                if (! $this->peutModifierEpreuve($autre, $contexte)['autorise']) {
                    continue;
                }

                /** @var Room|null $salleAutre */
                $salleAutre = $contexte['salles'][$autre->salle_id] ?? null;
                if ($salleAutre === null) {
                    continue;
                }

                $jaugeAutre = $this->capaciteUtileSalle($salleAutre);
                if ($jaugeAutre <= 0 || $jaugeCourante <= 0) {
                    continue;
                }

                $effectifAutre = $this->effectifRequisSimple($autre);
                $autreEstAmphi = $this->estAmphitheatre($salleAutre);

                // La permutation est valide si les deux épreuves respectent la jauge D-04 après échange
                if ($jaugeAutre < $effectifRequis || $jaugeCourante < $effectifAutre) {
                    continue;
                }

                // Vérifier que salleAutre est valide pour Epreuve (en ignorant Autre)
                $dispoAutrePourEp = $this->evaluerDisponibiliteSallePourEpreuve($salleAutre, $epreuve, $contexte, [$epreuve->id, $autre->id]);
                // Vérifier que salleCourante est valide pour Autre (en ignorant Epreuve)
                $dispoCourantePourAutre = $this->evaluerDisponibiliteSallePourEpreuve($salleCourante, $autre, $contexte, [$epreuve->id, $autre->id]);

                if (! $dispoAutrePourEp['disponible'] || ! $dispoCourantePourAutre['disponible']) {
                    continue;
                }

                $excedent = $jaugeAutre - $effectifRequis;
                $variantes[] = [
                    'cle' => 'permutation_'.$epreuve->id.'_'.$autre->id,
                    'epreuve_id' => $epreuve->id,
                    'type' => 'permutation',
                    'type_libelle' => self::TYPES_VARIANTES['permutation'],
                    'titre' => "Permutation D-04 : {$epreuve->code} → {$salleAutre->code} ({$jaugeAutre} pl. utiles) ↔ {$autre->code} → {$salleCourante->code} ({$jaugeCourante} pl. utiles)",
                    'salle_cible' => $salleAutre->id,
                    'code_cible' => $salleAutre->code,
                    'salles_cibles' => [$salleAutre->id],
                    'salle_ids' => [$salleAutre->id],
                    'salles' => [[
                        'id' => $salleAutre->id,
                        'code' => $salleAutre->code,
                        'nom' => $salleAutre->nom,
                        'est_amphitheatre' => $autreEstAmphi,
                        'capacite_nominale' => (int) $salleAutre->capacite,
                        'capacite_utile_d04' => $jaugeAutre,
                        'places_attribuees' => $effectifRequis,
                    ]],
                    'capacite_nominale_totale' => (int) $salleAutre->capacite,
                    'capacite_utile_cible' => $jaugeAutre,
                    'capacite_utile_totale' => $jaugeAutre,
                    'effectif_couvert' => $effectifRequis,
                    'places_restantes_apres' => $excedent,
                    'excedent_utile' => $excedent,
                    'score' => max(10, 95 - $excedent),
                    'obsolete' => false,
                    'viable' => true,
                    'nb_salles' => 1,
                    'nb_amphis' => $autreEstAmphi ? 1 : 0,
                    'nb_salles_ordinaires' => $autreEstAmphi ? 0 : 1,
                    'epreuve_partenaire_id' => $autre->id,
                    'epreuve_partenaire_code' => $autre->code,
                    'epreuve_partenaire_effectif' => $effectifAutre,
                    'salle_partenaire_cible' => $salleCourante->id,
                    'salle_partenaire_ids' => [$salleCourante->id],
                    'salles_partenaire' => [[
                        'id' => $salleCourante->id,
                        'code' => $salleCourante->code,
                        'nom' => $salleCourante->nom,
                        'est_amphitheatre' => $couranteEstAmphi,
                        'capacite_nominale' => (int) $salleCourante->capacite,
                        'capacite_utile_d04' => $jaugeCourante,
                        'places_attribuees' => $effectifAutre,
                    ]],
                    'recommandee' => empty($sallesUnitaires),
                    'empreinte' => $contexte['empreinte'],
                ];
            }
        }

        // -----------------------------------------------------------------
        // 3. COMBINAISONS DE SALLES (Amphithéâtres + Salles ordinaires)
        // -----------------------------------------------------------------
        $combinaisons = $this->genererCombinaisonsSalles(array_values($sallesDisponibles), $effectifRequis);
        foreach ($combinaisons as $idx => $comb) {
            $excedent = $comb['capacite_utile_totale'] - $effectifRequis;
            $variantes[] = [
                'cle' => 'combinaison_'.$epreuve->id.'_'.implode('_', $comb['salle_ids']),
                'epreuve_id' => $epreuve->id,
                'type' => 'combinaison',
                'type_libelle' => self::TYPES_VARIANTES['combinaison'],
                'strategie_combinaison' => $comb['strategie'],
                'titre' => $comb['titre'],
                'salle_cible' => $comb['salle_ids'][0] ?? null,
                'code_cible' => implode(' + ', array_column($comb['salles'], 'code')),
                'salles_cibles' => $comb['salle_ids'],
                'salle_ids' => $comb['salle_ids'],
                'salles' => $comb['salles'],
                'capacite_nominale_totale' => $comb['capacite_nominale_totale'],
                'capacite_utile_cible' => $comb['capacite_utile_totale'],
                'capacite_utile_totale' => $comb['capacite_utile_totale'],
                'effectif_couvert' => $effectifRequis,
                'places_restantes_apres' => $excedent,
                'excedent_utile' => $excedent,
                'score' => max(5, 90 - $excedent - ((count($comb['salle_ids']) - 1) * 5)),
                'obsolete' => false,
                'viable' => true,
                'nb_salles' => count($comb['salle_ids']),
                'nb_amphis' => $comb['nb_amphis'],
                'nb_salles_ordinaires' => $comb['nb_salles_ordinaires'],
                'epreuve_partenaire_id' => null,
                'epreuve_partenaire_code' => null,
                'salle_partenaire_ids' => [],
                'recommandee' => empty($sallesUnitaires) && $idx === 0,
                'empreinte' => $contexte['empreinte'],
            ];
        }

        // -----------------------------------------------------------------
        // 4. STRATÉGIE MIXTE (Permutation d'amphi + Salle(s) d'appoint)
        // -----------------------------------------------------------------
        if ($epreuve->salle_id !== null && isset($contexte['salles'][$epreuve->salle_id])) {
            /** @var Room $salleCourante */
            $salleCourante = $contexte['salles'][$epreuve->salle_id];
            $jaugeCourante = $this->capaciteUtileSalle($salleCourante);

            foreach ($contexte['toutes_epreuves_jour'] as $autreId => $autre) {
                if ((int) $autreId === (int) $epreuve->id || $autre->salle_id === null) {
                    continue;
                }
                if ($autre->date->format('Y-m-d') !== $epreuve->date->format('Y-m-d')) {
                    continue;
                }
                if (! $this->peutModifierEpreuve($autre, $contexte)['autorise']) {
                    continue;
                }

                /** @var Room|null $salleAutre */
                $salleAutre = $contexte['salles'][$autre->salle_id] ?? null;
                if ($salleAutre === null) {
                    continue;
                }

                $jaugeAutre = $this->capaciteUtileSalle($salleAutre);
                $effectifAutre = $this->effectifRequisSimple($autre);

                if ($jaugeAutre > $jaugeCourante && $jaugeAutre < $effectifRequis && $jaugeCourante >= $effectifAutre) {
                    $dispoAutrePourEp = $this->evaluerDisponibiliteSallePourEpreuve($salleAutre, $epreuve, $contexte, [$epreuve->id, $autre->id]);
                    $dispoCourantePourAutre = $this->evaluerDisponibiliteSallePourEpreuve($salleCourante, $autre, $contexte, [$epreuve->id, $autre->id]);

                    if (! $dispoAutrePourEp['disponible'] || ! $dispoCourantePourAutre['disponible']) {
                        continue;
                    }

                    $manquant = $effectifRequis - $jaugeAutre;
                    $appoints = array_filter(
                        $sallesDisponibles,
                        fn (array $s) => (int) $s['id'] !== (int) $salleCourante->id
                            && (int) $s['id'] !== (int) $salleAutre->id
                            && $s['capacite_utile_d04'] >= $manquant
                    );

                    usort($appoints, fn (array $a, array $b) => $a['capacite_utile_d04'] <=> $b['capacite_utile_d04']);

                    if ($appoints !== []) {
                        $appoint = $appoints[0];
                        $utileTotale = $jaugeAutre + $appoint['capacite_utile_d04'];
                        $nominaleTotale = (int) $salleAutre->capacite + $appoint['capacite_nominale'];
                        $autreEstAmphi = $this->estAmphitheatre($salleAutre);
                        $excedent = $utileTotale - $effectifRequis;

                        $variantes[] = [
                            'cle' => 'mixte_'.$epreuve->id.'_'.$autre->id.'_'.$appoint['id'],
                            'epreuve_id' => $epreuve->id,
                            'type' => 'mixte',
                            'type_libelle' => self::TYPES_VARIANTES['mixte'],
                            'titre' => "Mixte D-04 : Permutation {$salleAutre->code} + appoint {$appoint['code']} ({$utileTotale} pl. utiles) ↔ {$autre->code} → {$salleCourante->code}",
                            'salle_cible' => $salleAutre->id,
                            'code_cible' => $salleAutre->code.' + '.$appoint['code'],
                            'salles_cibles' => [$salleAutre->id, $appoint['id']],
                            'salle_ids' => [$salleAutre->id, $appoint['id']],
                            'salles' => [
                                [
                                    'id' => $salleAutre->id,
                                    'code' => $salleAutre->code,
                                    'nom' => $salleAutre->nom,
                                    'est_amphitheatre' => $autreEstAmphi,
                                    'capacite_nominale' => (int) $salleAutre->capacite,
                                    'capacite_utile_d04' => $jaugeAutre,
                                    'places_attribuees' => $jaugeAutre,
                                ],
                                $this->resumerSalleVariante($appoint, $manquant),
                            ],
                            'capacite_nominale_totale' => $nominaleTotale,
                            'capacite_utile_cible' => $utileTotale,
                            'capacite_utile_totale' => $utileTotale,
                            'effectif_couvert' => $effectifRequis,
                            'places_restantes_apres' => $excedent,
                            'excedent_utile' => $excedent,
                            'score' => max(5, 85 - $excedent),
                            'obsolete' => false,
                            'viable' => true,
                            'nb_salles' => 2,
                            'nb_amphis' => ($autreEstAmphi ? 1 : 0) + ($appoint['est_amphitheatre'] ? 1 : 0),
                            'nb_salles_ordinaires' => ($autreEstAmphi ? 0 : 1) + ($appoint['est_amphitheatre'] ? 0 : 1),
                            'epreuve_partenaire_id' => $autre->id,
                            'epreuve_partenaire_code' => $autre->code,
                            'epreuve_partenaire_effectif' => $effectifAutre,
                            'salle_partenaire_cible' => $salleCourante->id,
                            'salle_partenaire_ids' => [$salleCourante->id],
                            'salles_partenaire' => [[
                                'id' => $salleCourante->id,
                                'code' => $salleCourante->code,
                                'nom' => $salleCourante->nom,
                                'est_amphitheatre' => $this->estAmphitheatre($salleCourante),
                                'capacite_nominale' => (int) $salleCourante->capacite,
                                'capacite_utile_d04' => $jaugeCourante,
                                'places_attribuees' => $effectifAutre,
                            ]],
                            'recommandee' => false,
                            'empreinte' => $contexte['empreinte'],
                        ];
                    }
                }
            }
        }

        // 5. Si aucune variante viable ne peut couvrir l'effectif total (capacité totale du parc insuffisante),
        // exposer une proposition diagnostique non viable traçant le plafond utile maximal mobilisable.
        if ($variantes === [] && $sallesDisponibles !== []) {
            $toutes = array_values($sallesDisponibles);
            usort($toutes, fn (array $a, array $b) => $b['capacite_utile_d04'] <=> $a['capacite_utile_d04']);
            $cumulUtile = array_sum(array_column($toutes, 'capacite_utile_d04'));
            $cumulNom = array_sum(array_column($toutes, 'capacite_nominale'));
            $idsParc = array_column($toutes, 'id');

            $variantes[] = [
                'cle' => 'insuffisant_'.$epreuve->id,
                'epreuve_id' => $epreuve->id,
                'type' => 'combinaison',
                'type_libelle' => 'Capacité du parc insuffisante',
                'titre' => "Parc insuffisant : {$cumulUtile} places utiles D-04 au total pour {$effectifRequis} candidats",
                'salle_cible' => $idsParc[0] ?? null,
                'code_cible' => implode(' + ', array_column($toutes, 'code')),
                'salles_cibles' => $idsParc,
                'salle_ids' => $idsParc,
                'salles' => array_map(fn (array $s) => $this->resumerSalleVariante($s, $s['capacite_utile_d04']), $toutes),
                'capacite_nominale_totale' => $cumulNom,
                'capacite_utile_cible' => $cumulUtile,
                'capacite_utile_totale' => $cumulUtile,
                'effectif_couvert' => $cumulUtile,
                'places_restantes_apres' => $cumulUtile - $effectifRequis,
                'excedent_utile' => 0,
                'score' => 0,
                'obsolete' => false,
                'viable' => false,
                'nb_salles' => count($idsParc),
                'nb_amphis' => count(array_filter($toutes, fn (array $s) => $s['est_amphitheatre'])),
                'nb_salles_ordinaires' => count(array_filter($toutes, fn (array $s) => ! $s['est_amphitheatre'])),
                'epreuve_partenaire_id' => null,
                'epreuve_partenaire_code' => null,
                'salle_partenaire_ids' => [],
                'recommandee' => false,
                'empreinte' => $contexte['empreinte'],
            ];
        }

        return $variantes;
    }

    /**
     * Génère plusieurs combinaisons distinctes d'amphithéâtres et de salles ordinaires
     * lorsque plusieurs salles sont mobilisables pour couvrir l'effectif en jauge utile D-04.
     *
     * @param  array<int, array<string, mixed>>  $sallesDisponibles
     * @return array<int, array<string, mixed>>
     */
    private function genererCombinaisonsSalles(array $sallesDisponibles, int $effectifRequis): array
    {
        if (count($sallesDisponibles) < 2) {
            return [];
        }

        $maxSalles = $this->parametres()['max_salles_combinaison'];
        $combinaisonsVues = [];
        $resultats = [];

        $enregistrerCombinaison = function (array $selection, string $strategie, string $labelStrategie) use (
            $effectifRequis,
            &$combinaisonsVues,
            &$resultats
        ): void {
            if (count($selection) < 2) {
                return;
            }

            // Trier la sélection par capacité utile décroissante pour remplir les plus grandes salles d'abord
            usort($selection, fn (array $a, array $b) => $b['capacite_utile_d04'] <=> $a['capacite_utile_d04'] ?: ($a['id'] <=> $b['id']));

            // Élaguer les salles surnuméraires en fin de liste si les précédentes suffisent déjà
            $retenues = [];
            $cumulUtile = 0;
            $cumulNominale = 0;
            foreach ($selection as $s) {
                if ($cumulUtile >= $effectifRequis) {
                    break;
                }
                $retenues[] = $s;
                $cumulUtile += $s['capacite_utile_d04'];
                $cumulNominale += $s['capacite_nominale'];
            }

            if (count($retenues) < 2 || $cumulUtile < $effectifRequis) {
                return;
            }

            $ids = array_column($retenues, 'id');
            $idsTries = $ids;
            sort($idsTries);
            $signature = implode('-', $idsTries);

            if (isset($combinaisonsVues[$signature])) {
                return;
            }
            $combinaisonsVues[$signature] = true;

            $restant = $effectifRequis;
            $sallesResume = [];
            $nbAmphis = 0;
            $nbOrdinaires = 0;
            $codes = [];

            foreach ($retenues as $s) {
                $attribue = min($restant, $s['capacite_utile_d04']);
                $restant = max(0, $restant - $attribue);
                $sallesResume[] = $this->resumerSalleVariante($s, $attribue);
                $codes[] = $s['code'].' ('.$attribue.'/'.$s['capacite_utile_d04'].')';
                if ($s['est_amphitheatre']) {
                    $nbAmphis++;
                } else {
                    $nbOrdinaires++;
                }
            }

            $resultats[] = [
                'strategie' => $strategie,
                'titre' => "{$labelStrategie} : ".implode(' + ', $codes)." — {$cumulUtile} places utiles D-04",
                'salle_ids' => $ids,
                'salles' => $sallesResume,
                'capacite_nominale_totale' => $cumulNominale,
                'capacite_utile_totale' => $cumulUtile,
                'nb_amphis' => $nbAmphis,
                'nb_salles_ordinaires' => $nbOrdinaires,
            ];
        };

        // Stratégie 1 : Amphithéâtre(s) + Salle(s) ordinaire(s) d'appoint
        $amphis = array_values(array_filter($sallesDisponibles, fn (array $s) => $s['est_amphitheatre']));
        $ordinaires = array_values(array_filter($sallesDisponibles, fn (array $s) => ! $s['est_amphitheatre']));

        usort($amphis, fn (array $a, array $b) => $b['capacite_utile_d04'] <=> $a['capacite_utile_d04']);
        usort($ordinaires, fn (array $a, array $b) => $b['capacite_utile_d04'] <=> $a['capacite_utile_d04']);

        if ($amphis !== [] && $ordinaires !== []) {
            foreach ($amphis as $amphi) {
                if ($amphi['capacite_utile_d04'] >= $effectifRequis) {
                    continue;
                }
                $manquant = $effectifRequis - $amphi['capacite_utile_d04'];
                // Chercher une salle ordinaire qui couvre exactement ou au plus près le reliquat
                $ordinairesAsc = $ordinaires;
                usort($ordinairesAsc, fn (array $a, array $b) => $a['capacite_utile_d04'] <=> $b['capacite_utile_d04']);
                foreach ($ordinairesAsc as $ord) {
                    if ($ord['capacite_utile_d04'] >= $manquant) {
                        $enregistrerCombinaison([$amphi, $ord], 'amphi_plus_ordinaire', 'Combinaison Amphi + Salle ordinaire');
                        break;
                    }
                }
                // Si une seule salle ordinaire ne suffit pas, combiner l'amphi + plusieurs salles ordinaires
                $selection = [$amphi];
                $cumul = $amphi['capacite_utile_d04'];
                foreach ($ordinaires as $ord) {
                    if (count($selection) >= $maxSalles || $cumul >= $effectifRequis) {
                        break;
                    }
                    $selection[] = $ord;
                    $cumul += $ord['capacite_utile_d04'];
                }
                if ($cumul >= $effectifRequis) {
                    $enregistrerCombinaison($selection, 'amphi_plus_ordinaires', 'Combinaison Amphi + Salles ordinaires');
                }
            }
        }

        // Stratégie 2 : Regroupement de salles ordinaires uniquement
        if (count($ordinaires) >= 2) {
            $selectionOrd = array_slice($ordinaires, 0, $maxSalles);
            $enregistrerCombinaison($selectionOrd, 'salles_ordinaires', 'Combinaison de salles ordinaires');

            // Variante best-fit sur paires de salles ordinaires
            $meilleurePaire = null;
            $meilleurExcedent = PHP_INT_MAX;
            $nOrd = count($ordinaires);
            for ($i = 0; $i < $nOrd; $i++) {
                for ($j = $i + 1; $j < $nOrd; $j++) {
                    $somme = $ordinaires[$i]['capacite_utile_d04'] + $ordinaires[$j]['capacite_utile_d04'];
                    if ($somme >= $effectifRequis && ($somme - $effectifRequis) < $meilleurExcedent) {
                        $meilleurExcedent = $somme - $effectifRequis;
                        $meilleurePaire = [$ordinaires[$i], $ordinaires[$j]];
                    }
                }
            }
            if ($meilleurePaire !== null) {
                $enregistrerCombinaison($meilleurePaire, 'salles_ordinaires_ajustee', 'Combinaison ajustée de salles ordinaires');
            }
        }

        // Stratégie 3 : Combinaison toutes salles (ajustement optimal / grandes capacités)
        $toutesDesc = $sallesDisponibles;
        usort($toutesDesc, fn (array $a, array $b) => $b['capacite_utile_d04'] <=> $a['capacite_utile_d04']);
        $enregistrerCombinaison(array_slice($toutesDesc, 0, $maxSalles), 'capacite_maximale', 'Combinaison haute capacité');

        // Recherche de la meilleure paire toutes salles confondues (amphi + amphi ou amphi + salle)
        $meilleurePaireGlobale = null;
        $meilleurExcedentGlobal = PHP_INT_MAX;
        $nTot = count($toutesDesc);
        for ($i = 0; $i < $nTot; $i++) {
            for ($j = $i + 1; $j < $nTot; $j++) {
                if ($toutesDesc[$i]['capacite_utile_d04'] >= $effectifRequis || $toutesDesc[$j]['capacite_utile_d04'] >= $effectifRequis) {
                    continue;
                }
                $somme = $toutesDesc[$i]['capacite_utile_d04'] + $toutesDesc[$j]['capacite_utile_d04'];
                if ($somme >= $effectifRequis && ($somme - $effectifRequis) < $meilleurExcedentGlobal) {
                    $meilleurExcedentGlobal = $somme - $effectifRequis;
                    $meilleurePaireGlobale = [$toutesDesc[$i], $toutesDesc[$j]];
                }
            }
        }
        if ($meilleurePaireGlobale !== null) {
            $enregistrerCombinaison($meilleurePaireGlobale, 'ajustement_optimal', 'Combinaison ajustée D-04');
        }

        return array_slice($resultats, 0, $this->parametres()['max_variantes_par_type'] + 1);
    }

    /* ---------------------------------------------------------------------
     | Workflow de Validation Humaine : Proposition, Application, Refus, Annulation
     * ------------------------------------------------------------------- */

    /**
     * Étape 1 (Validation humaine obligatoire) : Produit une proposition D-04
     * vérifiée et horodatée SANS modifier les affectations officielles.
     *
     * @param  array{
     *     type?: string,
     *     salle_ids?: array<int, int>,
     *     epreuve_partenaire_id?: int|null,
     *     salle_partenaire_ids?: array<int, int>,
     *     variante_cle?: string|null,
     *     motif?: string|null
     * }  $donnees
     */
    public function proposer(Epreuve $epreuve, array $donnees, ?int $utilisateurId = null): array
    {
        $faculteId = $epreuve->faculteId();
        $this->verifierPortee($faculteId, $epreuve->examen_id, $epreuve->id);

        $contexte = $this->chargerContexte(
            (int) $epreuve->annee_universitaire_id,
            $faculteId,
            $epreuve->session_id ? (int) $epreuve->session_id : null,
            $epreuve->examen_id ? (int) $epreuve->examen_id : null,
            (int) $epreuve->id,
        );

        $modifiable = $this->peutModifierEpreuve($epreuve, $contexte);
        if (! $modifiable['autorise']) {
            throw new DomainException($modifiable['motif'] ?? 'Épreuve verrouillée ou non modifiable.');
        }

        $analyseEpreuve = $this->analyserEpreuve($epreuve, $contexte);

        // Résolution depuis une variante précalculée si `variante_cle` est fourni
        if (! empty($donnees['variante_cle'])) {
            $varianteTrouvee = collect($analyseEpreuve['variantes'])
                ->firstWhere('cle', (string) $donnees['variante_cle']);

            if ($varianteTrouvee === null) {
                throw new DomainException("Variante D-04 « {$donnees['variante_cle']} » introuvable ou obsolète.");
            }

            $type = (string) $varianteTrouvee['type'];
            $salleIds = array_map('intval', $varianteTrouvee['salle_ids']);
            $partenaireId = $varianteTrouvee['epreuve_partenaire_id'] !== null
                ? (int) $varianteTrouvee['epreuve_partenaire_id']
                : null;
            $sallePartenaireIds = array_map('intval', $varianteTrouvee['salle_partenaire_ids'] ?? []);
        } else {
            $type = (string) ($donnees['type'] ?? 'combinaison');
            if (! array_key_exists($type, self::TYPES_VARIANTES)) {
                throw new DomainException("Type de variante D-04 invalide : « {$type} ».");
            }
            $salleIds = array_values(array_unique(array_filter(array_map('intval', $donnees['salle_ids'] ?? []), fn (int $id) => $id > 0)));
            $partenaireId = ! empty($donnees['epreuve_partenaire_id']) ? (int) $donnees['epreuve_partenaire_id'] : null;
            $sallePartenaireIds = array_values(array_unique(array_filter(array_map('intval', $donnees['salle_partenaire_ids'] ?? []), fn (int $id) => $id > 0)));
        }

        if ($salleIds === []) {
            throw new DomainException('Au moins une salle cible est requise pour établir une proposition D-04.');
        }

        // Vérification stricte de la correspondance pédagogique avant toute proposition
        $this->verifierCorrespondancePedagogiqueStricte($epreuve);

        $ignorerIds = [$epreuve->id];
        $epreuvePartenaire = null;
        if (in_array($type, ['permutation', 'mixte'], true)) {
            if ($partenaireId === null) {
                throw new DomainException("Une épreuve partenaire est obligatoire pour une proposition de type « {$type} ».");
            }
            $epreuvePartenaire = Epreuve::with(['matiere', 'groupe', 'salle', 'examen', 'session', 'candidats.etudiant'])->find($partenaireId);
            if ($epreuvePartenaire === null) {
                throw new DomainException("Épreuve partenaire #{$partenaireId} introuvable.");
            }
            $modifPartenaire = $this->peutModifierEpreuve($epreuvePartenaire, $contexte);
            if (! $modifPartenaire['autorise']) {
                throw new DomainException("Épreuve partenaire {$epreuvePartenaire->code} non modifiable : {$modifPartenaire['motif']}");
            }
            $this->verifierCorrespondancePedagogiqueStricte($epreuvePartenaire);
            if ($sallePartenaireIds === [] && $epreuve->salle_id !== null) {
                $sallePartenaireIds = [(int) $epreuve->salle_id];
            }
            if ($sallePartenaireIds === []) {
                throw new DomainException("Salle cible manquante pour l'épreuve partenaire {$epreuvePartenaire->code}.");
            }
            $ignorerIds[] = $epreuvePartenaire->id;
        }

        $repartitionPrincipale = $this->construireEtValiderRepartitionD04($epreuve, $salleIds, $contexte, $ignorerIds, false);

        $repartitionPartenaire = null;
        if ($epreuvePartenaire !== null) {
            $repartitionPartenaire = $this->construireEtValiderRepartitionD04($epreuvePartenaire, $sallePartenaireIds, $contexte, $ignorerIds, false);
        }

        $uuid = (string) Str::uuid();
        $proposition = [
            'uuid' => $uuid,
            'statut' => self::STATUT_PROPOSEE,
            'type' => $type,
            'type_libelle' => self::TYPES_VARIANTES[$type] ?? $type,
            'annee_id' => (int) $epreuve->annee_universitaire_id,
            'session_id' => $epreuve->session_id ? (int) $epreuve->session_id : null,
            'examen_id' => $epreuve->examen_id ? (int) $epreuve->examen_id : null,
            'epreuve_id' => (int) $epreuve->id,
            'epreuve_code' => (string) $epreuve->code,
            'matiere_code' => (string) ($epreuve->matiere?->code ?? '—'),
            'groupe_code' => (string) ($epreuve->groupe?->code ?? '—'),
            'salle_ids' => $salleIds,
            'salles' => $repartitionPrincipale['salles'],
            'capacite_nominale_totale' => $repartitionPrincipale['capacite_nominale_totale'],
            'capacite_utile_totale' => $repartitionPrincipale['capacite_utile_totale'],
            'effectif_eligible' => $repartitionPrincipale['effectif_eligible'],
            'repartition' => $repartitionPrincipale['repartition'],
            'epreuve_partenaire_id' => $epreuvePartenaire?->id,
            'epreuve_partenaire_code' => $epreuvePartenaire?->code,
            'salle_partenaire_ids' => $sallePartenaireIds,
            'salles_partenaire' => $repartitionPartenaire['salles'] ?? [],
            'repartition_partenaire' => $repartitionPartenaire['repartition'] ?? [],
            'empreinte' => $contexte['empreinte'],
            'motif' => trim((string) ($donnees['motif'] ?? 'Proposition de répartition conforme D-04')),
            'propose_par' => $utilisateurId ?? auth()->id(),
            'proposee_at' => now()->toIso8601String(),
        ];

        $this->stockerPropositionCache($proposition);

        $this->audit->tracer(
            self::ACTION_PROPOSEE,
            self::ENTITE,
            (int) $epreuve->id,
            [
                'salle_id' => $epreuve->salle_id,
                'salles_actuelles' => array_column($analyseEpreuve['salles_actuelles'], 'code'),
            ],
            [
                'uuid' => $uuid,
                'statut' => self::STATUT_PROPOSEE,
                'type' => $type,
                'salle_ids' => $salleIds,
                'capacite_utile_totale' => $repartitionPrincipale['capacite_utile_totale'],
                'effectif_eligible' => $repartitionPrincipale['effectif_eligible'],
                'epreuve_partenaire_id' => $epreuvePartenaire?->id,
                'salle_partenaire_ids' => $sallePartenaireIds,
                'empreinte' => $contexte['empreinte'],
                'motif' => $proposition['motif'],
            ],
        );

        return $proposition;
    }

    /**
     * Accepte et applique une proposition D-04 (format tableau issue de `genererPropositions` ou de l'interface).
     */
    public function accepter(array $proposition, string $justification, ?int $utilisateurId = null): array
    {
        $epreuveId = (int) ($proposition['epreuve_id'] ?? 0);
        if ($epreuveId <= 0) {
            throw new DomainException('Identifiant d’épreuve manquant dans la proposition D-04.');
        }

        $epreuve = Epreuve::find($epreuveId);
        if ($epreuve === null) {
            throw new DomainException("Épreuve #{$epreuveId} introuvable.");
        }

        $uid = $utilisateurId ?? auth()->id();
        if ($uid === null) {
            throw new DomainException('Utilisateur non authentifié pour valider la décision D-04.');
        }

        $salleIds = $this->extraireSalleIdsDepuisProposition($proposition);
        $sallePartenaireIds = $proposition['salle_partenaire_ids']
            ?? (isset($proposition['salle_partenaire_cible']) ? [(int) $proposition['salle_partenaire_cible']] : []);

        $premiereDistribution = $proposition['distribution'][0] ?? null;
        $autoriserIdempotence = is_array($premiereDistribution) && array_key_exists('places', $premiereDistribution);

        return $this->appliquer($epreuve, [
            'proposition_uuid' => $proposition['proposition_uuid'] ?? null,
            'type' => $proposition['type'] ?? 'combinaison',
            'salle_ids' => $salleIds,
            'epreuve_partenaire_id' => $proposition['epreuve_partenaire_id'] ?? null,
            'salle_partenaire_ids' => $sallePartenaireIds,
            'motif' => $justification,
            'empreinte' => $proposition['empreinte'] ?? null,
            'autoriser_idempotence' => $autoriserIdempotence,
        ], (int) $uid);
    }

    /**
     * Étape 2 (Application officielle après validation humaine) :
     * Applique de manière transactionnelle avec verrouillage pessimiste `lockForUpdate()`
     * une proposition D-04 (réaffectation, permutation croisée, combinaison ou mixte),
     * propage les salles et places aux candidats, et journalise dans `audit_versioning`.
     *
     * @param  array{
     *     proposition_uuid?: string|null,
     *     type?: string,
     *     salle_ids?: array<int, int>,
     *     epreuve_partenaire_id?: int|null,
     *     salle_partenaire_ids?: array<int, int>,
     *     motif: string,
     *     empreinte?: string|null,
     *     autoriser_idempotence?: bool
     * }  $donnees
     */
    public function appliquer(Epreuve $epreuve, array $donnees, int $utilisateurId): array
    {
        $acteur = User::find($utilisateurId);
        if ($acteur === null || ! Gate::forUser($acteur)->allows('epreuves.update')) {
            throw new DomainException('Autorisation refusée : la permission epreuves.update est requise pour valider une affectation D-04.');
        }

        $motif = trim((string) ($donnees['motif'] ?? ''));
        if ($motif === '') {
            throw new DomainException('Une justification humaine explicite est obligatoire pour appliquer une décision D-04.');
        }

        $faculteActeur = $acteur->faculteRestreinteId();
        $this->verifierPortee($faculteActeur, $epreuve->examen_id, $epreuve->id);

        // Si un UUID de proposition est fourni, charger la proposition générée à l'étape 1
        $propositionSource = null;
        if (! empty($donnees['proposition_uuid'])) {
            $propositionSource = $this->recupererPropositionCache((string) $donnees['proposition_uuid'], (int) $epreuve->id);
            if ($propositionSource === null) {
                throw new DomainException("Proposition D-04 « {$donnees['proposition_uuid']} » introuvable ou expirée. Veuillez régénérer une proposition avant validation.");
            }
            if (($propositionSource['statut'] ?? '') !== self::STATUT_PROPOSEE) {
                throw new DomainException("La proposition « {$donnees['proposition_uuid']} » a déjà été traitée (statut : {$propositionSource['statut']}).");
            }
        }

        $type = (string) ($propositionSource['type'] ?? $donnees['type'] ?? 'combinaison');
        if (! array_key_exists($type, self::TYPES_VARIANTES)) {
            throw new DomainException("Type de décision D-04 invalide : « {$type} ».");
        }

        $salleIds = array_values(array_unique(array_filter(
            array_map('intval', $propositionSource['salle_ids'] ?? $donnees['salle_ids'] ?? []),
            fn (int $id) => $id > 0
        )));

        if ($salleIds === []) {
            throw new DomainException('Aucune salle cible spécifiée pour l’application D-04.');
        }

        $partenaireId = $propositionSource['epreuve_partenaire_id'] ?? ($donnees['epreuve_partenaire_id'] ?? null);
        $partenaireId = $partenaireId !== null ? (int) $partenaireId : null;

        $sallePartenaireIds = array_values(array_unique(array_filter(
            array_map('intval', $propositionSource['salle_partenaire_ids'] ?? $donnees['salle_partenaire_ids'] ?? []),
            fn (int $id) => $id > 0
        )));

        $empreinteAttendue = $propositionSource['empreinte'] ?? ($donnees['empreinte'] ?? null);
        $autoriserIdempotence = (bool) ($donnees['autoriser_idempotence'] ?? false);

        return DB::transaction(function () use (
            $epreuve,
            $type,
            $salleIds,
            $partenaireId,
            $sallePartenaireIds,
            $motif,
            $empreinteAttendue,
            $propositionSource,
            $utilisateurId,
            $faculteActeur,
            $autoriserIdempotence
        ) {
            // 1. Déterminer tous les IDs d'épreuves et de salles à verrouiller dans un ordre déterministe
            $epreuveIdsAVerrouiller = [(int) $epreuve->id];
            if ($partenaireId !== null) {
                $epreuveIdsAVerrouiller[] = $partenaireId;
            }
            sort($epreuveIdsAVerrouiller);

            // Pré-lecture des salles actuelles pour les inclure dans le verrouillage ordonné
            $sallesActuellesEpreuves = Epreuve::whereIn('id', $epreuveIdsAVerrouiller)
                ->pluck('salle_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->all();

            $sallesActuellesCandidats = Candidat::whereIn('epreuve_id', $epreuveIdsAVerrouiller)
                ->whereNotNull('salle_id')
                ->pluck('salle_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $toutesSallesIds = array_values(array_unique([
                ...$salleIds,
                ...$sallePartenaireIds,
                ...$sallesActuellesEpreuves,
                ...$sallesActuellesCandidats,
            ]));
            sort($toutesSallesIds);

            // 2. Verrouillage pessimiste déterministe : Salles (ID croissant) → Épreuves (ID croissant) → Candidats
            $sallesVerrouillees = Room::whereIn('id', $toutesSallesIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $epreuvesVerrouillees = Epreuve::whereIn('id', $epreuveIdsAVerrouiller)
                ->with(['matiere', 'groupe', 'examen', 'session'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var Epreuve|null $epreuveVerrouillee */
            $epreuveVerrouillee = $epreuvesVerrouillees->get((int) $epreuve->id);
            if ($epreuveVerrouillee === null) {
                throw new DomainException('Épreuve introuvable.');
            }

            /** @var Epreuve|null $partenaireVerrouillee */
            $partenaireVerrouillee = $partenaireId !== null ? $epreuvesVerrouillees->get($partenaireId) : null;
            if (in_array($type, ['permutation', 'mixte'], true) && $partenaireVerrouillee === null) {
                throw new DomainException('Épreuve partenaire introuvable pour la permutation D-04.');
            }

            if ($partenaireVerrouillee !== null && $sallePartenaireIds === [] && $epreuveVerrouillee->salle_id !== null) {
                $sallePartenaireIds = [(int) $epreuveVerrouillee->salle_id];
            }

            // Verrouiller également toutes les épreuves chevauchantes des mêmes dates pour sérialiser
            // deux transactions concurrentes sous MySQL visant les mêmes salles
            $datesImpliquees = array_values(array_unique(array_filter([
                $epreuveVerrouillee->date?->format('Y-m-d'),
                $partenaireVerrouillee?->date?->format('Y-m-d'),
            ])));
            $datesRequeteVerrou = array_values(array_unique(array_merge(
                $datesImpliquees,
                array_map(fn (string $d) => $d.' 00:00:00', $datesImpliquees),
            )));

            Epreuve::whereIn('date', $datesRequeteVerrou)
                ->where('statut', '!=', 'cancelled')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'salle_id', 'date', 'heure_debut', 'heure_fin']);

            $candidatsVerrouilles = Candidat::whereIn('epreuve_id', $epreuveIdsAVerrouiller)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'epreuve_id', 'etudiant_id', 'salle_id', 'place']);

            // 2bis. Détection d'une soumission répétée lorsque l'état cible est déjà exactement appliqué
            $candidatsEpCourante = $candidatsVerrouilles->where('epreuve_id', $epreuveVerrouillee->id);
            $sallesCourantesCand = $candidatsEpCourante->pluck('salle_id')->filter()->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
            $salleIdsTries = $salleIds;
            sort($salleIdsTries);

            $dejaApplique = $partenaireVerrouillee === null
                && (int) $epreuveVerrouillee->salle_id === (int) $salleIds[0]
                && $sallesCourantesCand === $salleIdsTries
                && $candidatsEpCourante->every(fn (Candidat $c) => $c->place !== null);

            if ($dejaApplique) {
                if (! $autoriserIdempotence) {
                    throw new DomainException("Cette proposition D-04 a déjà été appliquée sur l'épreuve {$epreuveVerrouillee->code} : l'état du planning a changé.");
                }

                return [
                    'audit_id' => null,
                    'statut' => self::STATUT_ACCEPTEE,
                    'idempotent' => true,
                    'type' => $type,
                    'epreuve_id' => $epreuveVerrouillee->id,
                    'salle_principale_id' => $salleIds[0],
                    'salle_ids' => $salleIds,
                    'salles' => [],
                    'capacite_utile_totale' => 0,
                    'candidats_affectes' => $candidatsEpCourante->count(),
                    'repartition' => [],
                    'epreuve_partenaire_id' => null,
                    'salle_partenaire_ids' => [],
                    'repartition_partenaire' => [],
                    'motif' => $motif,
                ];
            }

            // 3. Recharger le contexte frais SOUS VERROU et vérifier l'empreinte si fournie
            $contexteFrais = $this->chargerContexte(
                (int) $epreuveVerrouillee->annee_universitaire_id,
                $faculteActeur,
                $epreuveVerrouillee->session_id ? (int) $epreuveVerrouillee->session_id : null,
                $epreuveVerrouillee->examen_id ? (int) $epreuveVerrouillee->examen_id : null,
                null,
                true,
            );

            if ($empreinteAttendue !== null && $empreinteAttendue !== '' && $empreinteAttendue !== $contexteFrais['empreinte']) {
                $epreuveDansContexte = $contexteFrais['toutes_epreuves_jour'][$epreuveVerrouillee->id] ?? $epreuveVerrouillee;
                foreach ($salleIds as $sid) {
                    $sObj = $sallesVerrouillees->get($sid);
                    if ($sObj === null) {
                        throw new DomainException("Salle #{$sid} introuvable.");
                    }
                    $dispoCheck = $this->evaluerDisponibiliteSallePourEpreuve($sObj, $epreuveDansContexte, $contexteFrais, $epreuveIdsAVerrouiller);
                    if (! $dispoCheck['disponible']) {
                        throw new DomainException($dispoCheck['motifs'][0]['message'] ?? "La salle {$sObj->code} n'est plus disponible (modification concurrente détectée).");
                    }
                }
            }

            // 4. Vérifier que les épreuves sont modifiables (non verrouillées, non publiées, non gelées)
            $modifPrincipale = $this->peutModifierEpreuve($epreuveVerrouillee, $contexteFrais);
            if (! $modifPrincipale['autorise']) {
                throw new DomainException($modifPrincipale['motif'] ?? 'Épreuve verrouillée : modification interdite.');
            }

            if ($partenaireVerrouillee !== null) {
                $this->verifierPortee($faculteActeur, $partenaireVerrouillee->examen_id, $partenaireVerrouillee->id);
                $modifPartenaire = $this->peutModifierEpreuve($partenaireVerrouillee, $contexteFrais);
                if (! $modifPartenaire['autorise']) {
                    throw new DomainException($modifPartenaire['motif'] ?? 'Épreuve partenaire verrouillée : permutation interdite.');
                }
            }

            // 5. Vérifier la correspondance pédagogique stricte sous verrou
            $this->verifierCorrespondancePedagogiqueStricte($epreuveVerrouillee);
            if ($partenaireVerrouillee !== null) {
                $this->verifierCorrespondancePedagogiqueStricte($partenaireVerrouillee);
            }

            // 6. Construire et valider la répartition nominative des candidats selon la jauge utile D-04
            $planPrincipal = $this->construireEtValiderRepartitionD04(
                $epreuveVerrouillee,
                $salleIds,
                $contexteFrais,
                $epreuveIdsAVerrouiller
            );

            $planPartenaire = null;
            if ($partenaireVerrouillee !== null) {
                $chevauchent = $epreuveVerrouillee->date->format('Y-m-d') === $partenaireVerrouillee->date->format('Y-m-d')
                    && $this->chevaucheHeures(
                        $this->heure($epreuveVerrouillee->heure_debut),
                        $this->heure($epreuveVerrouillee->heure_fin),
                        $this->heure($partenaireVerrouillee->heure_debut),
                        $this->heure($partenaireVerrouillee->heure_fin)
                    );

                if ($chevauchent && array_intersect($salleIds, $sallePartenaireIds) !== []) {
                    throw new DomainException('Permutation invalide : une même salle ne peut pas être affectée simultanément aux deux épreuves sur le même créneau.');
                }

                $planPartenaire = $this->construireEtValiderRepartitionD04(
                    $partenaireVerrouillee,
                    $sallePartenaireIds,
                    $contexteFrais,
                    $epreuveIdsAVerrouiller
                );
            }

            // 7. Snapshot complet de l'état antérieur pour audit et réversibilité (rollback avant publication)
            $snapshotAvant = [
                'epreuve' => $this->capturerEtatEpreuve($epreuveVerrouillee),
                'partenaire' => $partenaireVerrouillee !== null ? $this->capturerEtatEpreuve($partenaireVerrouillee) : null,
            ];

            // 8. Écriture atomique des affectations de salles et des places individuelles
            $this->persisterRepartitionEpreuve($epreuveVerrouillee, $salleIds[0], $planPrincipal['repartition']);

            if ($partenaireVerrouillee !== null && $planPartenaire !== null) {
                $this->persisterRepartitionEpreuve($partenaireVerrouillee, $sallePartenaireIds[0], $planPartenaire['repartition']);
            }

            $snapshotApres = [
                'uuid_proposition' => $propositionSource['uuid'] ?? null,
                'statut' => self::STATUT_ACCEPTEE,
                'type' => $type,
                'motif' => $motif,
                'decide_par' => $utilisateurId,
                'decide_at' => now()->toIso8601String(),
                'epreuve' => $this->capturerEtatEpreuve($epreuveVerrouillee->fresh(['candidats'])),
                'partenaire' => $partenaireVerrouillee !== null
                    ? $this->capturerEtatEpreuve($partenaireVerrouillee->fresh(['candidats']))
                    : null,
                'salles_mobilisees' => $planPrincipal['salles'],
                'salles_partenaire' => $planPartenaire['salles'] ?? [],
                'capacite_utile_totale' => $planPrincipal['capacite_utile_totale'],
                'effectif_eligible' => $planPrincipal['effectif_eligible'],
            ];

            // 9. Journalisation dans `audit_versioning` et `integration_logs`
            $traceAudit = $this->audit->tracer(
                self::ACTION_ACCEPTEE,
                self::ENTITE,
                (int) $epreuveVerrouillee->id,
                $snapshotAvant,
                $snapshotApres,
            );

            IntegrationLog::tracer(
                'evaluation',
                'internal',
                'exam.d04.proposition_accepted',
                'completed',
                null,
                200,
                null,
                null,
                (string) Str::uuid(),
                'EPR-'.$epreuveVerrouillee->id
            );

            if ($propositionSource !== null) {
                $propositionSource['statut'] = self::STATUT_ACCEPTEE;
                $propositionSource['audit_id'] = $traceAudit->id;
                $this->stockerPropositionCache($propositionSource);
            }

            EvaluationChanged::dispatch('epreuve.d04_capacite_appliquee', $epreuveVerrouillee->id, [
                'type' => $type,
                'audit_id' => $traceAudit->id,
                'user_id' => $utilisateurId,
            ]);

            if ($partenaireVerrouillee !== null) {
                EvaluationChanged::dispatch('epreuve.d04_capacite_appliquee', $partenaireVerrouillee->id, [
                    'type' => $type,
                    'audit_id' => $traceAudit->id,
                    'user_id' => $utilisateurId,
                ]);
            }

            return [
                'audit_id' => $traceAudit->id,
                'statut' => self::STATUT_ACCEPTEE,
                'type' => $type,
                'epreuve_id' => $epreuveVerrouillee->id,
                'salle_principale_id' => $salleIds[0],
                'salle_ids' => $salleIds,
                'salles' => $planPrincipal['salles'],
                'capacite_utile_totale' => $planPrincipal['capacite_utile_totale'],
                'candidats_affectes' => count($planPrincipal['repartition']),
                'repartition' => $planPrincipal['repartition'],
                'epreuve_partenaire_id' => $partenaireVerrouillee?->id,
                'salle_partenaire_ids' => $sallePartenaireIds,
                'repartition_partenaire' => $planPartenaire['repartition'] ?? [],
                'motif' => $motif,
            ];
        });
    }

    /**
     * Refus humain motivé d'une proposition D-04 : aucune écriture sur les épreuves ni les candidats,
     * mais journalisation complète dans `audit_versioning`.
     */
    public function refuser(Epreuve|array $epreuveOuProposition, array|string $donneesOuJustification = [], ?int $utilisateurId = null): array
    {
        if (is_array($epreuveOuProposition)) {
            $epId = (int) ($epreuveOuProposition['epreuve_id'] ?? 0);
            $epreuve = $epId > 0 ? Epreuve::find($epId) : null;
            if ($epreuve === null) {
                throw new DomainException("Épreuve #{$epId} introuvable pour le refus D-04.");
            }
            $donnees = [
                'proposition_uuid' => $epreuveOuProposition['uuid'] ?? ($epreuveOuProposition['proposition_uuid'] ?? null),
                'type' => $epreuveOuProposition['type'] ?? 'combinaison',
                'salle_ids' => $this->extraireSalleIdsDepuisProposition($epreuveOuProposition),
                'motif' => is_string($donneesOuJustification) ? $donneesOuJustification : ($donneesOuJustification['motif'] ?? ''),
            ];
        } else {
            $epreuve = $epreuveOuProposition;
            $donnees = is_array($donneesOuJustification) ? $donneesOuJustification : ['motif' => $donneesOuJustification];
        }

        $uid = $utilisateurId ?? auth()->id();
        $acteur = $uid !== null ? User::find($uid) : null;
        if ($acteur === null || ! Gate::forUser($acteur)->allows('epreuves.update')) {
            throw new DomainException('Autorisation refusée : la permission epreuves.update est requise.');
        }

        $motif = trim((string) ($donnees['motif'] ?? ''));
        if ($motif === '') {
            throw new DomainException('Un motif explicite est obligatoire pour refuser une proposition D-04.');
        }

        $this->verifierPortee($acteur->faculteRestreinteId(), $epreuve->examen_id, $epreuve->id);

        $propositionSource = null;
        if (! empty($donnees['proposition_uuid'])) {
            $propositionSource = $this->recupererPropositionCache((string) $donnees['proposition_uuid'], (int) $epreuve->id);
        }

        $traceAudit = $this->audit->tracer(
            self::ACTION_REFUSEE,
            self::ENTITE,
            (int) $epreuve->id,
            [
                'salle_id' => $epreuve->salle_id,
                'uuid_proposition' => $propositionSource['uuid'] ?? ($donnees['proposition_uuid'] ?? null),
            ],
            [
                'statut' => self::STATUT_REFUSEE,
                'type' => $propositionSource['type'] ?? ($donnees['type'] ?? 'combinaison'),
                'salle_ids' => $propositionSource['salle_ids'] ?? ($donnees['salle_ids'] ?? []),
                'motif' => $motif,
                'refuse_par' => (int) $uid,
                'refuse_at' => now()->toIso8601String(),
            ],
        );

        if ($propositionSource !== null) {
            $propositionSource['statut'] = self::STATUT_REFUSEE;
            $propositionSource['audit_id'] = $traceAudit->id;
            $this->stockerPropositionCache($propositionSource);
        }

        return [
            'audit_id' => $traceAudit->id,
            'statut' => self::STATUT_REFUSEE,
            'epreuve_id' => $epreuve->id,
            'motif' => $motif,
        ];
    }

    /**
     * Annule la dernière décision D-04 acceptée sur le périmètre / l'épreuve (avant publication)
     * et restaure l'état antérieur des salles et des places des candidats.
     */
    public function annulerDerniereDecision(
        ?int $anneeId,
        ?int $faculteId,
        ?int $examenId,
        int $epreuveId,
        ?int $utilisateurId = null,
        string $motif = 'Annulation de la dernière décision D-04 avant publication.',
    ): array {
        $derniere = $this->derniereDecisionAnnulable($anneeId, $faculteId, $examenId, $epreuveId);
        if ($derniere === null) {
            throw new DomainException("Aucune décision D-04 annulable trouvée pour l'épreuve #{$epreuveId}.");
        }

        $uid = $utilisateurId ?? auth()->id();
        if ($uid === null) {
            throw new DomainException('Utilisateur non authentifié pour annuler la décision D-04.');
        }

        return $this->annulerDecision((int) $derniere['audit_id'], (int) $uid, $motif);
    }

    /**
     * Annule une décision D-04 précédemment acceptée (tant que l'examen n'est pas publié/figé)
     * et restaure exactement l'état antérieur des salles et des places des candidats.
     */
    public function annulerDecision(int $auditId, int $utilisateurId, string $motif): array
    {
        $acteur = User::find($utilisateurId);
        if ($acteur === null || ! Gate::forUser($acteur)->allows('epreuves.update')) {
            throw new DomainException('Autorisation refusée : la permission epreuves.update est requise pour annuler une décision D-04.');
        }

        $motif = trim($motif);
        if ($motif === '') {
            throw new DomainException('Un motif explicite est obligatoire pour annuler une décision D-04.');
        }

        /** @var AuditVersioning|null $entreeAudit */
        $entreeAudit = AuditVersioning::where('id', $auditId)
            ->where('entite', self::ENTITE)
            ->where('action', self::ACTION_ACCEPTEE)
            ->first();

        if ($entreeAudit === null) {
            throw new DomainException("Décision D-04 #{$auditId} introuvable ou non annulable.");
        }

        $ancienneValeur = $entreeAudit->ancienne_valeur ?? [];
        $nouvelleValeur = $entreeAudit->nouvelle_valeur ?? [];

        if (($nouvelleValeur['statut'] ?? '') === self::STATUT_ANNULEE) {
            throw new DomainException("La décision D-04 #{$auditId} a déjà été annulée.");
        }

        $dejaAnnulee = AuditVersioning::where('entite', self::ENTITE)
            ->where('action', self::ACTION_ANNULEE)
            ->get()
            ->contains(fn (AuditVersioning $a) => (int) ($a->ancienne_valeur['audit_id_annule'] ?? 0) === $auditId);

        if ($dejaAnnulee) {
            throw new DomainException("La décision D-04 #{$auditId} a déjà fait l'objet d'une annulation.");
        }

        $etatEpreuveAvant = $ancienneValeur['epreuve'] ?? null;
        if (! is_array($etatEpreuveAvant) || empty($etatEpreuveAvant['epreuve_id'])) {
            throw new DomainException("Snapshot antérieur invalide sur la décision #{$auditId}.");
        }

        $epreuveId = (int) $etatEpreuveAvant['epreuve_id'];
        $etatPartenaireAvant = $ancienneValeur['partenaire'] ?? null;
        $partenaireId = is_array($etatPartenaireAvant) && ! empty($etatPartenaireAvant['epreuve_id'])
            ? (int) $etatPartenaireAvant['epreuve_id']
            : null;

        return DB::transaction(function () use (
            $auditId,
            $epreuveId,
            $partenaireId,
            $etatEpreuveAvant,
            $etatPartenaireAvant,
            $utilisateurId,
            $motif,
            $acteur
        ) {
            $idsEpreuves = array_values(array_filter([$epreuveId, $partenaireId]));
            sort($idsEpreuves);

            $epreuvesVerrouillees = Epreuve::whereIn('id', $idsEpreuves)
                ->with(['examen', 'session'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var Epreuve|null $epreuve */
            $epreuve = $epreuvesVerrouillees->get($epreuveId);
            if ($epreuve === null) {
                throw new DomainException("Épreuve #{$epreuveId} introuvable.");
            }

            $this->verifierPortee($acteur->faculteRestreinteId(), $epreuve->examen_id, $epreuve->id);

            $contexte = $this->chargerContexte(
                (int) $epreuve->annee_universitaire_id,
                $acteur->faculteRestreinteId(),
                $epreuve->session_id ? (int) $epreuve->session_id : null,
                $epreuve->examen_id ? (int) $epreuve->examen_id : null,
                null,
            );

            $modif = $this->peutModifierEpreuve($epreuve, $contexte);
            if (! $modif['autorise']) {
                throw new DomainException("Annulation refusée : {$modif['motif']}");
            }

            /** @var Epreuve|null $partenaire */
            $partenaire = $partenaireId !== null ? $epreuvesVerrouillees->get($partenaireId) : null;
            if ($partenaire !== null) {
                $modifPart = $this->peutModifierEpreuve($partenaire, $contexte);
                if (! $modifPart['autorise']) {
                    throw new DomainException("Annulation refusée sur l'épreuve partenaire : {$modifPart['motif']}");
                }
            }

            $avantAnnulation = [
                'audit_id_annule' => $auditId,
                'epreuve' => $this->capturerEtatEpreuve($epreuve),
                'partenaire' => $partenaire !== null ? $this->capturerEtatEpreuve($partenaire) : null,
            ];

            $this->restaurerEtatEpreuve($epreuve, $etatEpreuveAvant);
            if ($partenaire !== null && is_array($etatPartenaireAvant)) {
                $this->restaurerEtatEpreuve($partenaire, $etatPartenaireAvant);
            }

            $apresAnnulation = [
                'statut' => self::STATUT_ANNULEE,
                'audit_id_origine' => $auditId,
                'motif' => $motif,
                'annule_par' => $utilisateurId,
                'annule_at' => now()->toIso8601String(),
                'epreuve' => $this->capturerEtatEpreuve($epreuve->fresh(['candidats'])),
                'partenaire' => $partenaire !== null ? $this->capturerEtatEpreuve($partenaire->fresh(['candidats'])) : null,
            ];

            $traceAnnulation = $this->audit->tracer(
                self::ACTION_ANNULEE,
                self::ENTITE,
                (int) $epreuve->id,
                $avantAnnulation,
                $apresAnnulation,
            );

            IntegrationLog::tracer(
                'evaluation',
                'internal',
                'exam.d04.decision_reverted',
                'completed',
                null,
                200,
                null,
                null,
                (string) Str::uuid(),
                'EPR-'.$epreuve->id
            );

            EvaluationChanged::dispatch('epreuve.d04_capacite_annulee', $epreuve->id, [
                'audit_id' => $traceAnnulation->id,
                'user_id' => $utilisateurId,
            ]);

            return [
                'audit_id' => $traceAnnulation->id,
                'statut' => self::STATUT_ANNULEE,
                'epreuve_id' => $epreuve->id,
                'epreuve_partenaire_id' => $partenaire?->id,
                'motif' => $motif,
            ];
        });
    }

    /* ---------------------------------------------------------------------
     | Construction et validation stricte d'une répartition D-04
     * ------------------------------------------------------------------- */

    /**
     * Vérifie que les salles demandées respectent toutes les contraintes D-04
     * (activité, capacité utile 50%, horaires, indisponibilités, cours, non-collision)
     * et répartit nominativement uniquement les candidats éligibles avec numérotation `1..N` dans chaque salle.
     *
     * @param  array<int, int>  $salleIds
     * @param  array<int, int>  $ignorerEpreuveIds
     * @return array{
     *     salles: array<int, array<string, mixed>>,
     *     capacite_nominale_totale: int,
     *     capacite_utile_totale: int,
     *     effectif_eligible: int,
     *     repartition: array<int, array{candidat_id: int, etudiant_id: int, etudiant_nom: string, salle_id: int, salle_code: string, place: string}>
     * }
     */
    private function construireEtValiderRepartitionD04(
        Epreuve $epreuve,
        array $salleIds,
        array $contexte,
        array $ignorerEpreuveIds,
        bool $bloquerCollisionOccupation = true,
    ): array {
        $salles = [];
        $capaciteNominaleTotale = 0;
        $capaciteUtileTotale = 0;

        foreach ($salleIds as $sid) {
            /** @var Room|null $salle */
            $salle = $contexte['salles'][$sid] ?? Room::find($sid);
            if ($salle === null) {
                throw new DomainException("Salle #{$sid} introuvable.");
            }

            $dispo = $this->evaluerDisponibiliteSallePourEpreuve($salle, $epreuve, $contexte, $ignorerEpreuveIds);
            $motifsBloquants = $bloquerCollisionOccupation
                ? $dispo['motifs']
                : array_values(array_filter($dispo['motifs'], fn (array $m) => $m['type'] !== 'collision_salle'));

            if ($motifsBloquants !== []) {
                $premierMotif = $motifsBloquants[0]['message'] ?? "Salle {$salle->code} indisponible ou incompatible.";
                throw new DomainException($premierMotif);
            }

            if ($dispo['capacite_utile_d04'] < 1) {
                throw new DomainException("Salle {$salle->code} : jauge utile D-04 nulle ou inconnue, affectation interdite.");
            }

            $salles[] = [
                'modele' => $salle,
                'id' => $salle->id,
                'code' => $salle->code,
                'nom' => $salle->nom,
                'est_amphitheatre' => $dispo['est_amphitheatre'],
                'capacite_nominale' => $dispo['capacite_nominale'],
                'capacite_utile_d04' => $dispo['capacite_utile_d04'],
            ];

            $capaciteNominaleTotale += $dispo['capacite_nominale'];
            $capaciteUtileTotale += $dispo['capacite_utile_d04'];
        }

        $epreuve->loadMissing(['candidats.etudiant']);
        $idsEligiblesRef = array_flip($this->correspondance->identifiantsEligiblesReference($epreuve));
        $modeStrict = $this->correspondance->estModePedagogiqueStrict($epreuve);

        $candidatsEligibles = $epreuve->candidats
            ->filter(fn (Candidat $c) => ! $modeStrict || isset($idsEligiblesRef[(int) $c->etudiant_id]))
            ->sortBy('id')
            ->values();

        $effectifCandidats = $candidatsEligibles->count();
        $effectifRequis = $effectifCandidats > 0
            ? $effectifCandidats
            : ($modeStrict && count($idsEligiblesRef) > 0
                ? count($idsEligiblesRef)
                : max(1, (int) $epreuve->capacite_necessaire, (int) ($epreuve->groupe?->capacite ?? 0)));

        if ($capaciteUtileTotale < $effectifRequis) {
            throw new DomainException(
                "Capacité utile D-04 insuffisante pour l'épreuve {$epreuve->code} : {$capaciteUtileTotale} places utiles disponibles pour {$effectifRequis} candidats éligibles."
            );
        }

        $repartition = [];
        $sallesResume = [];
        $indexCandidat = 0;
        $restantTheorique = $effectifRequis;

        foreach ($salles as $infoSalle) {
            $jaugeUtile = (int) $infoSalle['capacite_utile_d04'];
            $placeDansSalle = 1;
            $affectesSalle = 0;

            if ($effectifCandidats > 0) {
                while ($indexCandidat < $effectifCandidats && $placeDansSalle <= $jaugeUtile) {
                    /** @var Candidat $candidat */
                    $candidat = $candidatsEligibles[$indexCandidat];
                    $placeStr = (string) $placeDansSalle;

                    $repartition[] = [
                        'candidat_id' => (int) $candidat->id,
                        'etudiant_id' => (int) $candidat->etudiant_id,
                        'etudiant_nom' => trim(($candidat->etudiant?->nom ?? '').' '.($candidat->etudiant?->prenom ?? '')) ?: 'Candidat #'.$candidat->id,
                        'salle_id' => (int) $infoSalle['id'],
                        'salle_code' => (string) $infoSalle['code'],
                        'place' => $placeStr,
                    ];

                    $placeDansSalle++;
                    $indexCandidat++;
                    $affectesSalle++;
                }
            } else {
                $affectesSalle = min($restantTheorique, $jaugeUtile);
                $restantTheorique = max(0, $restantTheorique - $affectesSalle);
            }

            /** @var Room $modeleSalle */
            $modeleSalle = $infoSalle['modele'];
            $minSurv = max(1, (int) ($modeleSalle->min_surveillants ?? ($infoSalle['est_amphitheatre'] ? 2 : 1)));

            $sallesResume[] = [
                'id' => (int) $infoSalle['id'],
                'code' => (string) $infoSalle['code'],
                'nom' => (string) $infoSalle['nom'],
                'est_amphitheatre' => (bool) $infoSalle['est_amphitheatre'],
                'capacite_nominale' => (int) $infoSalle['capacite_nominale'],
                'capacite_utile_d04' => $jaugeUtile,
                'places_attribuees' => $affectesSalle,
                'taux_occupation_d04' => $jaugeUtile > 0 ? round(($affectesSalle / $jaugeUtile) * 100, 1) : 0.0,
                'surveillants_requis' => $minSurv,
            ];
        }

        if ($effectifCandidats > 0 && $indexCandidat < $effectifCandidats) {
            throw new DomainException('Répartition D-04 incomplète : certains candidats éligibles n’ont pas pu recevoir de place utile.');
        }

        return [
            'salles' => $sallesResume,
            'capacite_nominale_totale' => $capaciteNominaleTotale,
            'capacite_utile_totale' => $capaciteUtileTotale,
            'effectif_eligible' => $effectifRequis,
            'repartition' => $repartition,
        ];
    }

    /**
     * Vérifie que la correspondance Matière ↔ Groupes ↔ Étudiants de l'épreuve est valide.
     * Lève une `DomainException` si des candidats non éligibles sont présents, si des éligibles sont omis,
     * ou si le groupe / programme / niveau de l'épreuve est incohérent.
     */
    private function verifierCorrespondancePedagogiqueStricte(Epreuve $epreuve): void
    {
        $analyse = $this->correspondance->analyserEpreuve($epreuve, ignorerDeriveSnapshot: true);

        if (($analyse['ecarts']['candidats_non_eligibles'] ?? []) !== []) {
            throw new DomainException(
                $analyse['bloquants'][0] ?? CorrespondanceExamenService::ALERTE_PUBLICATION_BLOQUEE
            );
        }

        if (($analyse['ecarts']['anomalies_groupes'] ?? []) !== []) {
            throw new DomainException(
                $analyse['ecarts']['anomalies_groupes'][0] ?? CorrespondanceExamenService::ALERTE_GROUPE_INCOHERENT
            );
        }

        if (($analyse['ecarts']['eligibles_omis'] ?? []) !== [] && $epreuve->candidats()->exists()) {
            throw new DomainException(
                $analyse['bloquants'][0] ?? CorrespondanceExamenService::ALERTE_PUBLICATION_BLOQUEE
            );
        }

        if ($this->correspondance->estModePedagogiqueStrict($epreuve) && $epreuve->candidats()->exists() && (int) ($analyse['compteurs']['eligibles'] ?? 0) === 0) {
            throw new DomainException(
                "Aucun étudiant éligible à la matière {$epreuve->matiere?->code} pour l'épreuve {$epreuve->code} : répartition interdite."
            );
        }
    }

    /**
     * Extrait les identifiants de salles cibles depuis les différents formats de proposition D-04.
     *
     * @return array<int, int>
     */
    private function extraireSalleIdsDepuisProposition(array $proposition): array
    {
        $ids = $proposition['salle_ids'] ?? ($proposition['salles_cibles'] ?? []);
        if (! is_array($ids)) {
            $ids = [];
        }

        if ($ids === [] && ! empty($proposition['salle_cible'])) {
            $ids = [(int) $proposition['salle_cible']];
        }

        if ($ids === [] && ! empty($proposition['distribution']) && is_array($proposition['distribution'])) {
            foreach ($proposition['distribution'] as $bloc) {
                if (! empty($bloc['salle_id'])) {
                    $ids[] = (int) $bloc['salle_id'];
                }
            }
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id) => $id > 0)));
    }

    /**
     * Évalue si une salle est active, calibrée, ouverte, disponible et non occupée pour une épreuve donnée.
     *
     * @param  array<int, int>  $ignorerEpreuveIds
     * @return array{disponible: bool, motifs: array<int, array{type: string, message: string}>, capacite_nominale: int, capacite_utile_d04: int, est_amphitheatre: bool}
     */
    private function evaluerDisponibiliteSallePourEpreuve(
        Room $salle,
        Epreuve $epreuve,
        array $contexte,
        array $ignorerEpreuveIds = [],
    ): array {
        $motifs = [];
        $capNominale = max(0, (int) ($salle->capacite ?? 0));
        $capUtile = $this->capaciteUtileSalle($salle, $contexte['ratio'] ?? $this->ratio());
        $estAmphi = $this->estAmphitheatre($salle);

        if (! $salle->actif) {
            $motifs[] = [
                'type' => 'salle_inactive',
                'message' => "Salle {$salle->code} fermée ou inactive : affectation interdite.",
            ];
        }

        if ($salle->capacite === null || (int) $salle->capacite <= 0 || $capUtile <= 0) {
            $motifs[] = [
                'type' => 'capacite_inconnue',
                'message' => "Capacité de la salle {$salle->code} inconnue ou nulle : affectation interdite (Règle D-04).",
            ];
        }

        $faculteEpreuve = $epreuve->faculteId();
        if ($salle->faculte_id !== null && $faculteEpreuve !== null && (int) $salle->faculte_id !== (int) $faculteEpreuve) {
            $motifs[] = [
                'type' => 'salle_hors_faculte',
                'message' => "Salle {$salle->code} rattachée à une autre faculté : affectation interdite.",
            ];
        }

        $dateStr = $epreuve->date->format('Y-m-d');
        $jourSemaine = strtolower($epreuve->date->format('l'));
        $debut = $this->heure($epreuve->heure_debut);
        $fin = $this->heure($epreuve->heure_fin);

        if (($salle->heure_ouverture !== null || $salle->heure_fermeture !== null) && ! $salle->estOuvertePlage($debut, $fin)) {
            $motifs[] = [
                'type' => 'salle_fermee_horaire',
                'message' => "Salle {$salle->code} fermée sur une partie du créneau {$debut} → {$fin}.",
            ];
        }

        foreach ($contexte['indisponibilites'][$salle->id] ?? [] as $indispo) {
            if ($indispo['day'] !== $jourSemaine) {
                continue;
            }
            if (! $this->chevaucheHeures($debut, $fin, $indispo['debut'], $indispo['fin'])) {
                continue;
            }
            if ($indispo['date_debut'] !== null && $dateStr < $indispo['date_debut']) {
                continue;
            }
            if ($indispo['date_fin'] !== null && $dateStr > $indispo['date_fin']) {
                continue;
            }

            $detailMotif = ! empty($indispo['motif']) ? " ({$indispo['motif']})" : '';
            $motifs[] = [
                'type' => 'salle_indisponible',
                'message' => "Salle {$salle->code} déclarée indisponible sur le créneau {$debut} → {$fin}{$detailMotif}.",
            ];
        }

        foreach ($contexte['cours'][$salle->id] ?? [] as $cours) {
            if ($cours['day'] !== $jourSemaine) {
                continue;
            }
            if (! $this->chevaucheHeures($debut, $fin, $cours['debut'], $cours['fin'])) {
                continue;
            }
            if ($cours['date_debut'] !== null && $dateStr < $cours['date_debut']) {
                continue;
            }
            if ($cours['date_fin'] !== null && $dateStr > $cours['date_fin']) {
                continue;
            }

            $motifs[] = [
                'type' => 'conflit_cours',
                'message' => "Salle {$salle->code} occupée par un cours régulier « {$cours['libelle']} » sur le créneau {$debut} → {$fin}.",
            ];
        }

        foreach ($contexte['occupation_salles'][$salle->id] ?? [] as $occ) {
            if (in_array((int) $occ['epreuve_id'], array_map('intval', $ignorerEpreuveIds), true)) {
                continue;
            }
            if ($occ['date'] !== $dateStr) {
                continue;
            }
            if ($this->chevaucheHeures($debut, $fin, $occ['debut'], $occ['fin'])) {
                $motifs[] = [
                    'type' => 'collision_salle',
                    'message' => "Salle {$salle->code} déjà occupée sur le créneau {$debut} → {$fin} par l'épreuve {$occ['code']} (collision d'occupation).",
                ];
                break;
            }
        }

        return [
            'disponible' => $motifs === [],
            'motifs' => $motifs,
            'capacite_nominale' => $capNominale,
            'capacite_utile_d04' => $capUtile,
            'est_amphitheatre' => $estAmphi,
        ];
    }

    /**
     * Persiste la salle principale sur l'épreuve et les salles/places individuelles sur les candidats.
     *
     * @param  array<int, array{candidat_id: int, salle_id: int, place: string}>  $repartition
     */
    private function persisterRepartitionEpreuve(Epreuve $epreuve, int $sallePrincipaleId, array $repartition): void
    {
        $epreuve->update(['salle_id' => $sallePrincipaleId]);

        foreach ($repartition as $ligne) {
            Candidat::where('id', (int) $ligne['candidat_id'])
                ->where('epreuve_id', $epreuve->id)
                ->update([
                    'salle_id' => (int) $ligne['salle_id'],
                    'place' => (string) $ligne['place'],
                ]);
        }
    }

    /**
     * Capture l'état exact d'une épreuve et de ses candidats pour l'audit et la réversibilité.
     */
    private function capturerEtatEpreuve(Epreuve $epreuve): array
    {
        $epreuve->loadMissing(['candidats']);

        return [
            'epreuve_id' => (int) $epreuve->id,
            'code' => (string) $epreuve->code,
            'salle_id' => $epreuve->salle_id !== null ? (int) $epreuve->salle_id : null,
            'candidats' => $epreuve->candidats
                ->sortBy('id')
                ->map(fn (Candidat $c) => [
                    'candidat_id' => (int) $c->id,
                    'etudiant_id' => (int) $c->etudiant_id,
                    'salle_id' => $c->salle_id !== null ? (int) $c->salle_id : null,
                    'place' => $c->place,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Restaure l'état antérieur d'une épreuve et de ses candidats lors d'une annulation.
     */
    private function restaurerEtatEpreuve(Epreuve $epreuve, array $snapshot): void
    {
        $epreuve->update([
            'salle_id' => isset($snapshot['salle_id']) && $snapshot['salle_id'] !== null
                ? (int) $snapshot['salle_id']
                : null,
        ]);

        foreach ($snapshot['candidats'] ?? [] as $cSnap) {
            Candidat::where('id', (int) $cSnap['candidat_id'])
                ->where('epreuve_id', $epreuve->id)
                ->update([
                    'salle_id' => isset($cSnap['salle_id']) && $cSnap['salle_id'] !== null
                        ? (int) $cSnap['salle_id']
                        : null,
                    'place' => $cSnap['place'] ?? null,
                ]);
        }
    }

    /* ---------------------------------------------------------------------
     | Utilitaires, KPIs, Historique et Cache des Propositions
     * ------------------------------------------------------------------- */

    private function ratio(): float
    {
        return ExamScheduleEngineService::RATIO_CAPACITE_EXAMEN_DEFAUT;
    }

    /**
     * @return array{max_epreuves: int, max_salles_combinaison: int, max_variantes_par_type: int, ttl_proposition_secondes: int}
     */
    private function parametres(): array
    {
        return [
            'max_epreuves' => 100,
            'max_salles_combinaison' => 4,
            'max_variantes_par_type' => 3,
            'ttl_proposition_secondes' => 3600,
        ];
    }

    /**
     * @return array{autorise: bool, motif: string|null}
     */
    private function peutModifierEpreuve(Epreuve $epreuve, array $contexte): array
    {
        if ($epreuve->estVerrouillee()) {
            return ['autorise' => false, 'motif' => "L'épreuve {$epreuve->code} est verrouillée."];
        }

        if (! in_array($epreuve->statut, self::STATUTS_EPREUVE_MODIFIABLES, true)) {
            return ['autorise' => false, 'motif' => "Le statut « {$epreuve->statut} » de l'épreuve {$epreuve->code} interdit toute réaffectation directe."];
        }

        if ($epreuve->examen !== null && ! in_array($epreuve->examen->statut, self::STATUTS_EXAMEN_MODIFIABLES, true)) {
            return ['autorise' => false, 'motif' => "L'examen {$epreuve->examen->code} est au statut « {$epreuve->examen->statut} »."];
        }

        if ($epreuve->examen_id !== null && in_array((int) $epreuve->examen_id, $contexte['examens_publies'] ?? [], true)) {
            return ['autorise' => false, 'motif' => "L'examen possède déjà une version officielle publiée par la gouvernance."];
        }

        if ($epreuve->session !== null && ($epreuve->session->verrouillee_at !== null || in_array($epreuve->session->statut, ['closed', 'locked', 'archived'], true))) {
            return ['autorise' => false, 'motif' => "La session d'examen est clôturée ou verrouillée."];
        }

        return ['autorise' => true, 'motif' => null];
    }

    private function effectifRequisSimple(Epreuve $epreuve): int
    {
        $nbCandidats = $epreuve->relationLoaded('candidats')
            ? $epreuve->candidats->count()
            : $epreuve->candidats()->count();

        if ($nbCandidats > 0) {
            return $nbCandidats;
        }

        return max(1, (int) $epreuve->capacite_necessaire, (int) ($epreuve->groupe?->capacite ?? 0));
    }

    private function resumerSalleVariante(array $salleInfo, int $placesAttribuees): array
    {
        $jauge = (int) $salleInfo['capacite_utile_d04'];

        return [
            'id' => (int) $salleInfo['id'],
            'code' => (string) $salleInfo['code'],
            'nom' => (string) $salleInfo['nom'],
            'est_amphitheatre' => (bool) $salleInfo['est_amphitheatre'],
            'capacite_nominale' => (int) $salleInfo['capacite_nominale'],
            'capacite_utile_d04' => $jauge,
            'places_attribuees' => min($placesAttribuees, $jauge),
        ];
    }

    private function calculerKpis(array $lignes, array $contexte): array
    {
        $totalEpreuves = count($lignes);
        $conformes = 0;
        $enSurcharge = 0;
        $sansSalle = 0;
        $avecVariantes = 0;
        $nonAffectables = 0;
        $totalCandidats = 0;
        $capaciteUtileAffectee = 0;
        $placesRestantes = 0;

        foreach ($lignes as $ligne) {
            $totalCandidats += (int) $ligne['effectif_requis'];
            $capaciteUtileAffectee += (int) $ligne['capacite_utile_totale'];
            $placesRestantes += (int) ($ligne['places_restantes'] ?? 0);

            if ($ligne['conforme_d04']) {
                $conformes++;
            }
            if ($ligne['salles_actuelles'] === []) {
                $sansSalle++;
            } elseif ($ligne['deficit_places'] > 0) {
                $enSurcharge++;
            }
            if ($ligne['variantes'] !== []) {
                $avecVariantes++;
            } elseif (! $ligne['conforme_d04']) {
                $nonAffectables++;
            }
        }

        $sallesActives = collect($contexte['salles'])->filter(fn (Room $s) => $s->actif && (int) ($s->capacite ?? 0) > 0);
        $amphisActifs = $sallesActives->filter(fn (Room $s) => $this->estAmphitheatre($s));
        $ordinairesActives = $sallesActives->reject(fn (Room $s) => $this->estAmphitheatre($s));
        $capaciteUtileParc = $sallesActives->sum(fn (Room $s) => $this->capaciteUtileSalle($s));

        return [
            'epreuves_analysees' => $totalEpreuves,
            'epreuves_conformes_d04' => $conformes,
            'epreuves_en_surcharge_d04' => $enSurcharge,
            'epreuves_sans_salle' => $sansSalle,
            'epreuves_avec_variantes' => $avecVariantes,
            'epreuves_non_affectables' => $nonAffectables,
            'total_candidats_eligibles' => $totalCandidats,
            'total_candidats' => $totalCandidats,
            'capacite_utile_affectee' => $capaciteUtileAffectee,
            'capacite_utile_totale' => $capaciteUtileParc,
            'places_restantes' => $placesRestantes,
            'salles_en_surcharge' => $enSurcharge,
            'salles_libres' => $sallesActives->count(),
            'amphis_disponibles' => $amphisActifs->count(),
            'salles_ordinaires_disponibles' => $ordinairesActives->count(),
            'capacite_utile_parc' => $capaciteUtileParc,
        ];
    }

    private function kpisVides(): array
    {
        return [
            'epreuves_analysees' => 0,
            'epreuves_conformes_d04' => 0,
            'epreuves_en_surcharge_d04' => 0,
            'epreuves_sans_salle' => 0,
            'epreuves_avec_variantes' => 0,
            'epreuves_non_affectables' => 0,
            'total_candidats_eligibles' => 0,
            'total_candidats' => 0,
            'capacite_utile_affectee' => 0,
            'capacite_utile_totale' => 0,
            'places_restantes' => 0,
            'salles_en_surcharge' => 0,
            'salles_libres' => 0,
            'amphis_disponibles' => 0,
            'salles_ordinaires_disponibles' => 0,
            'capacite_utile_parc' => 0,
        ];
    }

    private function agregerConflits(array $lignes): array
    {
        $conflits = [];
        foreach ($lignes as $ligne) {
            foreach ($ligne['conflits'] ?? [] as $conflit) {
                $conflits[] = $conflit;
            }
        }

        return $conflits;
    }

    private function calculerEmpreinte(array $contexte): string
    {
        $donnees = [
            'annee_id' => $contexte['annee_id'],
            'faculte_id' => $contexte['faculte_id'],
            'ratio' => $contexte['ratio'],
            'epreuves' => collect($contexte['epreuves'])
                ->sortBy('id')
                ->map(fn (Epreuve $e) => [
                    'id' => (int) $e->id,
                    'statut' => (string) $e->statut,
                    'salle_id' => $e->salle_id !== null ? (int) $e->salle_id : null,
                    'date' => $e->date->format('Y-m-d'),
                    'debut' => $this->heure($e->heure_debut),
                    'fin' => $this->heure($e->heure_fin),
                    'candidats' => $e->candidats->map(fn (Candidat $c) => [
                        'id' => (int) $c->id,
                        'salle_id' => $c->salle_id !== null ? (int) $c->salle_id : null,
                        'place' => $c->place,
                    ])->values()->all(),
                ])
                ->values()
                ->all(),
        ];

        return hash('sha256', (string) json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function stockerPropositionCache(array $proposition): void
    {
        $ttl = $this->parametres()['ttl_proposition_secondes'];
        $cle = self::PREFIXE_CACHE.'.prop.'.$proposition['uuid'];
        Cache::put($cle, $proposition, $ttl);

        $cleListe = self::PREFIXE_CACHE.'.epreuve.'.$proposition['epreuve_id'];
        $liste = Cache::get($cleListe, []);
        if (! is_array($liste)) {
            $liste = [];
        }
        $liste[$proposition['uuid']] = $proposition;
        Cache::put($cleListe, $liste, $ttl);
    }

    private function recupererPropositionCache(string $uuid, int $epreuveId): ?array
    {
        $prop = Cache::get(self::PREFIXE_CACHE.'.prop.'.$uuid);
        if (is_array($prop) && (int) ($prop['epreuve_id'] ?? 0) === $epreuveId) {
            return $prop;
        }

        // Fallback depuis audit_versioning si le cache a été vidé
        $auditProp = AuditVersioning::where('entite', self::ENTITE)
            ->where('action', self::ACTION_PROPOSEE)
            ->where('entite_id', $epreuveId)
            ->latest('id')
            ->get()
            ->first(fn (AuditVersioning $a) => ($a->nouvelle_valeur['uuid'] ?? '') === $uuid);

        if ($auditProp !== null) {
            $val = $auditProp->nouvelle_valeur;

            return [
                'uuid' => $uuid,
                'statut' => $val['statut'] ?? self::STATUT_PROPOSEE,
                'type' => $val['type'] ?? 'combinaison',
                'epreuve_id' => $epreuveId,
                'salle_ids' => $val['salle_ids'] ?? [],
                'epreuve_partenaire_id' => $val['epreuve_partenaire_id'] ?? null,
                'salle_partenaire_ids' => $val['salle_partenaire_ids'] ?? [],
                'empreinte' => $val['empreinte'] ?? null,
                'motif' => $val['motif'] ?? '',
            ];
        }

        return null;
    }

    public function propositionsRecentes(
        ?int $anneeId,
        ?int $faculteId,
        ?int $sessionId = null,
        ?int $examenId = null,
        ?int $epreuveId = null,
        ?string $empreinteCourante = null,
    ): array {
        if ($anneeId === null) {
            $anneeId = AnneeUniversitaire::where('active', true)->value('id')
                ?? AnneeUniversitaire::orderByDesc('date_debut')->value('id');
        }
        if ($anneeId === null) {
            return [];
        }

        if ($empreinteCourante === null) {
            $contexte = $this->chargerContexte($anneeId, $faculteId, $sessionId, $examenId, $epreuveId);
            $empreinteCourante = $this->calculerEmpreinte($contexte);
        }

        $idsEpreuves = Epreuve::query()
            ->where('annee_universitaire_id', $anneeId)
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->when($sessionId !== null, fn ($q) => $q->where('session_id', $sessionId))
            ->when($examenId !== null, fn ($q) => $q->where('examen_id', $examenId))
            ->when($epreuveId !== null, fn ($q) => $q->where('id', $epreuveId))
            ->pluck('id')
            ->all();

        if ($idsEpreuves === []) {
            return [];
        }

        return AuditVersioning::query()
            ->where('entite', self::ENTITE)
            ->where('action', self::ACTION_PROPOSEE)
            ->whereIn('entite_id', $idsEpreuves)
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(function (AuditVersioning $a) use ($empreinteCourante) {
                $nv = $a->nouvelle_valeur ?? [];
                $estFraiche = ($nv['empreinte'] ?? null) === $empreinteCourante;
                $salleIds = $nv['salle_ids'] ?? [];
                $codesSalles = $salleIds !== [] ? Room::whereIn('id', $salleIds)->pluck('code')->implode(' + ') : '—';
                $capTotale = (int) ($nv['capacite_utile_totale'] ?? 0);
                $eff = (int) ($nv['effectif_eligible'] ?? 0);

                return [
                    'audit_id' => $a->id,
                    'uuid' => $nv['uuid'] ?? null,
                    'epreuve_id' => $a->entite_id,
                    'type' => $nv['type'] ?? 'combinaison',
                    'salle_ids' => $salleIds,
                    'code_cible' => $codesSalles,
                    'capacite_utile_totale' => $capTotale,
                    'capacite_utile_cible' => $capTotale,
                    'effectif_eligible' => $eff,
                    'places_restantes_apres' => max(0, $capTotale - $eff),
                    'score' => 90,
                    'statut' => $nv['statut'] ?? self::STATUT_PROPOSEE,
                    'est_fraiche' => $estFraiche,
                    'obsolete' => ! $estFraiche,
                    'created_at' => $a->created_at?->format('d/m/Y H:i'),
                ];
            })
            ->all();
    }

    public function historique(
        ?int $anneeId,
        ?int $faculteId,
        ?int $examenId = null,
        ?int $epreuveId = null,
        int $limite = 15,
    ): array {
        $idsEpreuves = Epreuve::query()
            ->when($anneeId !== null, fn ($q) => $q->where('annee_universitaire_id', $anneeId))
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->when($examenId !== null, fn ($q) => $q->where('examen_id', $examenId))
            ->when($epreuveId !== null, fn ($q) => $q->where('id', $epreuveId))
            ->pluck('id')
            ->all();

        if ($idsEpreuves === []) {
            return [];
        }

        return AuditVersioning::query()
            ->with('utilisateur:id,name,email')
            ->where('entite', self::ENTITE)
            ->whereIn('entite_id', $idsEpreuves)
            ->latest('id')
            ->limit($limite)
            ->get()
            ->map(fn (AuditVersioning $a) => [
                'id' => $a->id,
                'action' => $a->action,
                'epreuve_id' => $a->entite_id,
                'utilisateur' => $a->utilisateur?->name ?? 'Système',
                'ancienne_valeur' => $a->ancienne_valeur,
                'nouvelle_valeur' => $a->nouvelle_valeur,
                'created_at' => $a->created_at?->format('d/m/Y H:i:s'),
                'cree_a' => $a->created_at?->format('d/m/Y H:i:s'),
            ])
            ->all();
    }

    private function derniereDecisionAnnulable(
        ?int $anneeId,
        ?int $faculteId,
        ?int $examenId,
        ?int $epreuveId,
    ): ?array {
        $idsEpreuves = Epreuve::query()
            ->when($anneeId !== null, fn ($q) => $q->where('annee_universitaire_id', $anneeId))
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->when($examenId !== null, fn ($q) => $q->where('examen_id', $examenId))
            ->when($epreuveId !== null, fn ($q) => $q->where('id', $epreuveId))
            ->pluck('id')
            ->all();

        if ($idsEpreuves === []) {
            return null;
        }

        $annuleesIds = AuditVersioning::query()
            ->where('entite', self::ENTITE)
            ->where('action', self::ACTION_ANNULEE)
            ->whereIn('entite_id', $idsEpreuves)
            ->get()
            ->map(fn (AuditVersioning $a) => (int) ($a->ancienne_valeur['audit_id_annule'] ?? 0))
            ->filter()
            ->all();

        $derniere = AuditVersioning::query()
            ->where('entite', self::ENTITE)
            ->where('action', self::ACTION_ACCEPTEE)
            ->whereIn('entite_id', $idsEpreuves)
            ->when($annuleesIds !== [], fn ($q) => $q->whereNotIn('id', $annuleesIds))
            ->latest('id')
            ->first();

        if ($derniere === null) {
            return null;
        }

        return [
            'audit_id' => $derniere->id,
            'epreuve_id' => $derniere->entite_id,
            'type' => $derniere->nouvelle_valeur['type'] ?? 'combinaison',
            'motif' => $derniere->nouvelle_valeur['motif'] ?? '',
            'created_at' => $derniere->created_at?->format('d/m/Y H:i'),
        ];
    }

    private function verifierPortee(?int $faculteId, ?int $examenId, ?int $epreuveId): void
    {
        if ($faculteId === null) {
            return;
        }

        if ($examenId !== null) {
            $examen = Examen::find($examenId);
            if ($examen === null || ($examen->faculteId() !== null && (int) $examen->faculteId() !== (int) $faculteId)) {
                throw new DomainException('Examen hors de votre périmètre facultaire.');
            }
        }

        if ($epreuveId !== null) {
            $epreuve = Epreuve::find($epreuveId);
            if ($epreuve === null || ($epreuve->faculteId() !== null && (int) $epreuve->faculteId() !== (int) $faculteId)) {
                throw new DomainException('Épreuve hors de votre périmètre facultaire.');
            }
        }
    }

    private function optionsAnnees(): array
    {
        return AnneeUniversitaire::orderByDesc('date_debut')
            ->get(['id', 'libelle', 'active'])
            ->map(fn (AnneeUniversitaire $a) => [
                'id' => $a->id,
                'libelle' => $a->libelle,
                'active' => (bool) $a->active,
            ])
            ->all();
    }

    private function optionsSessions(?int $anneeId, ?int $faculteId): array
    {
        if ($anneeId === null) {
            return [];
        }

        return SessionExamen::query()
            ->where('annee_universitaire_id', $anneeId)
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->orderByDesc('date_debut')
            ->get(['id', 'code', 'nom', 'statut'])
            ->map(fn (SessionExamen $s) => [
                'id' => $s->id,
                'code' => $s->code,
                'nom' => $s->nom,
                'statut' => $s->statut,
            ])
            ->all();
    }

    private function optionsExamens(?int $anneeId, ?int $faculteId): array
    {
        if ($anneeId === null) {
            return [];
        }

        return Examen::query()
            ->where('annee_universitaire_id', $anneeId)
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'code', 'libelle', 'statut'])
            ->map(fn (Examen $e) => [
                'id' => $e->id,
                'code' => $e->code,
                'libelle' => $e->libelle,
                'statut' => $e->statut,
            ])
            ->all();
    }

    private function optionsEpreuves(?int $anneeId, ?int $faculteId, ?int $sessionId, ?int $examenId): array
    {
        if ($anneeId === null) {
            return [];
        }

        return Epreuve::query()
            ->where('annee_universitaire_id', $anneeId)
            ->where('statut', '!=', 'cancelled')
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->when($sessionId !== null, fn ($q) => $q->where('session_id', $sessionId))
            ->when($examenId !== null, fn ($q) => $q->where('examen_id', $examenId))
            ->with('matiere:id,code,nom')
            ->orderBy('date')
            ->orderBy('heure_debut')
            ->limit(100)
            ->get(['id', 'code', 'matiere_id', 'date', 'heure_debut', 'heure_fin'])
            ->map(fn (Epreuve $ep) => [
                'id' => $ep->id,
                'code' => $ep->code,
                'libelle' => ($ep->matiere?->code ?? $ep->code).' — '.($ep->matiere?->nom ?? ''),
                'date' => $ep->date->format('Y-m-d'),
            ])
            ->all();
    }

    private function portee(?int $anneeId, ?int $faculteId, ?int $sessionId, ?int $examenId, ?int $epreuveId): array
    {
        return [
            'annee_id' => $anneeId,
            'faculte_id' => $faculteId,
            'session_id' => $sessionId,
            'examen_id' => $examenId,
            'epreuve_id' => $epreuveId,
        ];
    }

    private function heure(mixed $valeur): string
    {
        return substr((string) $valeur, 0, 5);
    }

    private function chevaucheHeures(string $debutA, string $finA, string $debutB, string $finB): bool
    {
        return $debutA < $finB && $debutB < $finA;
    }
}
