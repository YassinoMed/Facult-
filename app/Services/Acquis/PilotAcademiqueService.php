<?php

namespace App\Services\Acquis;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use DateTimeInterface;
use Throwable;

/**
 * PILOTE ACADÉMIQUE CONTRÔLÉ (LOOP 42).
 *
 * Objet : recalculer un dossier avec le moteur central et confronter le
 * résultat à la référence officielle publiée, sans jamais écrire.
 *
 * Garanties :
 * - LECTURE SEULE : aucun create/save/update/delete n'est appelé.
 * - FAIL-CLOSED : pilote inactif, validation absente, périmètre vide ou mode
 *   non supporté ⇒ rapport BLOQUÉ, zéro dossier traité.
 * - ISOLATION : un dossier hors faculté / hors programme est exclu, jamais
 *   réaffecté à un autre périmètre.
 * - DÉNOMINATEURS EXPLICITES : chaque taux expose numérateur, dénominateur et
 *   nombre d'exclus ; un dossier non comparable n'entre jamais au dénominateur
 *   du taux de concordance.
 * - AUCUNE PII : les résultats ne contiennent ni nom, ni prénom, ni CIN.
 *
 * Les 7 classes de divergence sont :
 *   ECART_CREDITS, ECART_UES, ECART_DECISION, ECART_MOYENNE, ECART_MULTIPLE,
 *   REFERENCE_NON_PUBLIEE, REFERENCE_ABSENTE.
 * La concordance n'est pas une classe : c'est l'absence des cinq classes
 * d'écart mesurable, sur une référence publiée.
 */
class PilotAcademiqueService
{
    public const CLASSE_ECART_CREDITS = 'ECART_CREDITS';

    public const CLASSE_ECART_UES = 'ECART_UES';

    public const CLASSE_ECART_DECISION = 'ECART_DECISION';

    public const CLASSE_ECART_MOYENNE = 'ECART_MOYENNE';

    public const CLASSE_ECART_MULTIPLE = 'ECART_MULTIPLE';

    public const CLASSE_REFERENCE_NON_PUBLIEE = 'REFERENCE_NON_PUBLIEE';

    public const CLASSE_REFERENCE_ABSENTE = 'REFERENCE_ABSENTE';

    /** @var list<string> */
    public const CLASSES_DIVERGENCE = [
        self::CLASSE_ECART_CREDITS,
        self::CLASSE_ECART_UES,
        self::CLASSE_ECART_DECISION,
        self::CLASSE_ECART_MOYENNE,
        self::CLASSE_ECART_MULTIPLE,
        self::CLASSE_REFERENCE_NON_PUBLIEE,
        self::CLASSE_REFERENCE_ABSENTE,
    ];

    // Les 7 typologies institutionnelles formelles (PHASE 42.4)
    public const TYPOLOGIE_DIVERGENCE_REGLEMENTAIRE = 'DIVERGENCE_REGLEMENTAIRE';

    public const TYPOLOGIE_DIVERGENCE_HISTORIQUE = 'DIVERGENCE_HISTORIQUE';

    public const TYPOLOGIE_DIVERGENCE_CALCUL = 'DIVERGENCE_CALCUL';

    public const TYPOLOGIE_DIVERGENCE_MAQUETTE = 'DIVERGENCE_MAQUETTE';

    public const TYPOLOGIE_DIVERGENCE_DONNEES = 'DIVERGENCE_DONNEES';

    public const TYPOLOGIE_DIVERGENCE_INTERPRETATION = 'DIVERGENCE_INTERPRETATION';

    public const TYPOLOGIE_SOURCE_INSUFFISANTE = 'SOURCE_INSUFFISANTE';

    /** @var list<string> */
    public const TYPOLOGIES_DIVERGENCE = [
        self::TYPOLOGIE_DIVERGENCE_REGLEMENTAIRE,
        self::TYPOLOGIE_DIVERGENCE_HISTORIQUE,
        self::TYPOLOGIE_DIVERGENCE_CALCUL,
        self::TYPOLOGIE_DIVERGENCE_MAQUETTE,
        self::TYPOLOGIE_DIVERGENCE_DONNEES,
        self::TYPOLOGIE_DIVERGENCE_INTERPRETATION,
        self::TYPOLOGIE_SOURCE_INSUFFISANTE,
    ];

    // Les 5 sous-ensembles du corpus historique (ADDENDUM R42.1)
    public const CORPUS_PV_OFFICIEL_EXPLOITABLE = 'PV_OFFICIEL_EXPLOITABLE';

    public const CORPUS_RESULTAT_NORMALISE = 'RESULTAT_NORMALISE';

    public const CORPUS_NOTE_SANS_DELIBERATION_NORMALISEE = 'NOTE_SANS_DELIBERATION_NORMALISEE';

    public const CORPUS_SOURCE_JUSTIFICATIVE_ABSENTE = 'SOURCE_JUSTIFICATIVE_ABSENTE';

    public const CORPUS_REGLE_INCERTAINE = 'REGLE_INCERTAINE';

    /** Statuts d'UE considérés comme validés par le moteur central. */
    private const STATUTS_UE_VALIDEE = ['CAPITALISEE', 'VALIDEE_PAR_COMPENSATION'];

    public function __construct(
        private readonly MoteurDecisionAcademiqueService $moteurDecision,
        private readonly ReglementationAcquisService $reglementation,
    ) {}

    /**
     * État de configuration du pilote, sans exécution.
     *
     * @return array<string, mixed>
     */
    public function etat(): array
    {
        $perimetre = (array) config('pilote_academique.perimetre', []);

        return [
            'actif' => (bool) config('pilote_academique.actif', false),
            'statut_validation' => (string) config('pilote_academique.statut_validation', 'A_VALIDER'),
            'reference_validation' => config('pilote_academique.reference_validation'),
            'mode' => (string) config('pilote_academique.mode', 'SIMULATION'),
            'lecture_seule' => true,
            'perimetre_configure' => $this->perimetreEstConfigure($perimetre),
            'perimetre_resume' => [
                'facultes' => count($perimetre['facultes'] ?? []),
                'programmes' => count($perimetre['programmes'] ?? []),
                'annees' => count($perimetre['annees'] ?? []),
                'niveaux' => count($perimetre['niveaux'] ?? []),
                'etudiants' => count($perimetre['etudiants'] ?? []),
            ],
        ];
    }

    /**
     * Vérifie toutes les préconditions et retourne l'ensemble des blocages.
     * Aucun blocage n'est levé par défaut : chaque condition doit être
     * explicitement satisfaite.
     *
     * @return array{autorise: bool, blocages: list<array{code: string, message: string}>, etat: array<string, mixed>}
     */
    public function verifierPreconditions(): array
    {
        $etat = $this->etat();
        $blocages = [];

        if (! $etat['actif']) {
            $blocages[] = [
                'code' => 'PILOTE_INACTIF',
                'message' => 'Le pilote académique est désactivé (PILOTE_ACADEMIQUE_ACTIF=false).',
            ];
        }

        if ($etat['statut_validation'] !== 'VALIDE') {
            $blocages[] = [
                'code' => 'VALIDATION_INSTITUTIONNELLE_MANQUANTE',
                'message' => "Statut de validation « {$etat['statut_validation']} » : aucune exécution métier autorisée.",
            ];
        }

        if (blank($etat['reference_validation'])) {
            $blocages[] = [
                'code' => 'REFERENCE_VALIDATION_MANQUANTE',
                'message' => 'Aucune référence de décision institutionnelle ne fonde le pilote.',
            ];
        }

        if (! $etat['perimetre_configure']) {
            $blocages[] = [
                'code' => 'PERIMETRE_VIDE',
                'message' => 'Aucun filtre de périmètre renseigné : la cohorte est indéterminée.',
            ];
        }

        if ($etat['mode'] !== 'SIMULATION') {
            $blocages[] = [
                'code' => 'MODE_NON_SUPPORTE',
                'message' => "Mode « {$etat['mode']} » non supporté : LOOP 42 est strictement en simulation.",
            ];
        }

        return [
            'autorise' => $blocages === [],
            'blocages' => $blocages,
            'etat' => $etat,
        ];
    }

    /**
     * Construit le périmètre réel : dossiers retenus et dossiers exclus avec
     * leur motif. L'isolation est revérifiée dossier par dossier.
     *
     * @return array{dossiers: list<array{inscription: InscriptionAcademique, etudiant: Etudiant}>, exclus: list<array{inscription_id: int, etudiant_id: int, motif: string}>, denominateur: int, tronque: bool, limite: int|null}
     */
    public function construirePerimetre(): array
    {
        $perimetre = (array) config('pilote_academique.perimetre', []);
        $facultes = array_map('intval', (array) ($perimetre['facultes'] ?? []));
        $programmes = array_map('intval', (array) ($perimetre['programmes'] ?? []));
        $annees = array_map('intval', (array) ($perimetre['annees'] ?? []));
        $niveaux = array_values(array_filter(array_map('strval', (array) ($perimetre['niveaux'] ?? []))));
        $etudiants = array_map('intval', (array) ($perimetre['etudiants'] ?? []));

        $query = InscriptionAcademique::query()
            ->with(['etudiant', 'programmeRef'])
            ->orderBy('id');

        if ($facultes !== []) {
            $query->whereHas('etudiant', fn ($q) => $q->whereIn('faculte_id', $facultes));
        }

        if ($programmes !== []) {
            $query->whereIn('programme_id', $programmes);
        }

        if ($annees !== []) {
            $query->whereIn('annee_universitaire_id', $annees);
        }

        if ($niveaux !== []) {
            $query->where(function ($q) use ($niveaux) {
                foreach ($niveaux as $niveau) {
                    $q->orWhereRaw('LOWER(niveau) = ?', [mb_strtolower($niveau)]);
                }
            });
        }

        if ($etudiants !== []) {
            $query->whereIn('etudiant_id', $etudiants);
        }

        $dossiers = [];
        $exclus = [];

        foreach ($query->get() as $inscription) {
            $etudiant = $inscription->etudiant;

            if ($etudiant === null) {
                $exclus[] = [
                    'inscription_id' => (int) $inscription->id,
                    'etudiant_id' => (int) $inscription->etudiant_id,
                    'motif' => 'DOSSIER_ORPHELIN',
                ];

                continue;
            }

            // Défense en profondeur : le filtre SQL ne suffit pas à garantir
            // l'isolation si la donnée a changé entre la requête et la lecture.
            if ($facultes !== [] && ! in_array((int) $etudiant->faculte_id, $facultes, true)) {
                $exclus[] = [
                    'inscription_id' => (int) $inscription->id,
                    'etudiant_id' => (int) $etudiant->id,
                    'motif' => 'HORS_PERIMETRE_FACULTE',
                ];

                continue;
            }

            if ($programmes !== [] && ! in_array((int) $inscription->programme_id, $programmes, true)) {
                $exclus[] = [
                    'inscription_id' => (int) $inscription->id,
                    'etudiant_id' => (int) $etudiant->id,
                    'motif' => 'HORS_PERIMETRE_PROGRAMME',
                ];

                continue;
            }

            $dossiers[] = [
                'inscription' => $inscription,
                'etudiant' => $etudiant,
            ];
        }

        $limite = config('pilote_academique.limite_dossiers');
        $limite = is_numeric($limite) && (int) $limite > 0 ? (int) $limite : null;
        $tronque = false;

        if ($limite !== null && count($dossiers) > $limite) {
            $dossiers = array_slice($dossiers, 0, $limite);
            $tronque = true;
        }

        return [
            'dossiers' => $dossiers,
            'exclus' => $exclus,
            'denominateur' => count($dossiers),
            'tronque' => $tronque,
            'limite' => $limite,
        ];
    }

    /**
     * Double calcul d'un dossier : moteur central vs référence officielle.
     * Ne lève jamais d'exception métier : les cas non comparables sont
     * classés, pas interprétés.
     *
     * @return array<string, mixed>
     */
    public function comparerDossier(InscriptionAcademique $inscription): array
    {
        $etudiant = $inscription->etudiant;

        if ($etudiant === null) {
            return $this->resultatNonComparable(
                $inscription,
                self::CLASSE_REFERENCE_ABSENTE,
                'DOSSIER_ORPHELIN'
            );
        }

        $moteur = $this->moteurDecision->determinerDecision($etudiant, $inscription);

        $creditsMoteur = (int) ($moteur['credits']['capitalises'] ?? 0);
        $uesMoteur = $this->compterUesValidees($moteur['unites'] ?? []);

        $programme = $inscription->programmeRef ?? $etudiant->programmeRef;
        $couverture = $programme !== null
            ? $this->reglementation->evaluerCouvertureReglementaire($programme)
            : ['niveau_couverture' => 'AUCUNE'];

        $reference = DecisionDeliberation::query()
            ->where('etudiant_id', $etudiant->id)
            ->where('inscription_academique_id', $inscription->id)
            ->latest('id')
            ->first();

        if ($reference === null) {
            return $this->resultatNonComparable(
                $inscription,
                self::CLASSE_REFERENCE_ABSENTE,
                'AUCUNE_DECISION_OFFICIELLE',
                $moteur,
                $couverture
            );
        }

        if (! $reference->publie) {
            return $this->resultatNonComparable(
                $inscription,
                self::CLASSE_REFERENCE_NON_PUBLIEE,
                'DECISION_OFFICIELLE_NON_PUBLIEE',
                $moteur,
                $couverture,
                (int) $reference->id
            );
        }

        $creditsReference = (int) $reference->credits_obtenus;
        $uesReference = (int) $reference->ues_validees;

        $ecarts = [];

        if ($creditsReference !== $creditsMoteur) {
            $ecarts['credits'] = [
                'moteur' => $creditsMoteur,
                'reference' => $creditsReference,
                'delta' => $creditsReference - $creditsMoteur,
            ];
        }

        if ($uesReference !== $uesMoteur) {
            $ecarts['ues'] = [
                'moteur' => $uesMoteur,
                'reference' => $uesReference,
                'delta' => $uesReference - $uesMoteur,
            ];
        }

        $ecartDecision = $this->comparerDecision($reference->decision, $inscription->decision);
        if ($ecartDecision !== null) {
            $ecarts['decision'] = $ecartDecision;
        }

        $ecartMoyenne = $this->comparerMoyenne($reference->moyenne, $inscription->moyenne_annuelle);
        if ($ecartMoyenne !== null) {
            $ecarts['moyenne'] = $ecartMoyenne;
        }

        $classe = $this->classerEcarts($ecarts);

        $typologie = $this->qualifierTypologieDivergence([
            'concordant' => $classe === null,
            'classe' => $classe,
            'ecarts' => $ecarts,
            'couverture_reglementaire' => $couverture['niveau_couverture'] ?? 'AUCUNE',
        ]);

        $empreinte = $this->genererEmpreinteCanonique([
            'inscription_id' => (int) $inscription->id,
            'etudiant_id' => (int) $etudiant->id,
            'decision_officielle_id' => (int) $reference->id,
            'credits_moteur' => $creditsMoteur,
            'credits_reference' => $creditsReference,
            'ues_moteur' => $uesMoteur,
            'ues_reference' => $uesReference,
            'classe' => $classe,
            'typologie' => $typologie,
        ]);

        return [
            'inscription_id' => (int) $inscription->id,
            'etudiant_id' => (int) $etudiant->id,
            'faculte_id' => (int) $etudiant->faculte_id,
            'programme_id' => $inscription->programme_id !== null ? (int) $inscription->programme_id : null,
            'annee_universitaire_id' => (int) $inscription->annee_universitaire_id,
            'niveau' => $inscription->niveau,
            'decision_officielle_id' => (int) $reference->id,
            'comparable' => true,
            'concordant' => $classe === null,
            'classe' => $classe,
            'typologie_divergence' => $typologie,
            'empreinte_canonique' => $empreinte,
            'ecarts' => $ecarts,
            'dimensions_comparees' => $this->dimensionsComparees($reference, $inscription),
            'mesures' => [
                'credits_moteur' => $creditsMoteur,
                'credits_reference' => $creditsReference,
                'ues_moteur' => $uesMoteur,
                'ues_reference' => $uesReference,
            ],
            'couverture_reglementaire' => $couverture['niveau_couverture'] ?? 'AUCUNE',
            'regle_formation_confirmee' => ($couverture['niveau_couverture'] ?? 'AUCUNE') === 'FORMATION',
        ];
    }

    /**
     * Exécute le pilote en simulation. Retourne toujours un rapport ;
     * un blocage n'est pas une exception.
     *
     * @return array<string, mixed>
     */
    public function executerSimulation(): array
    {
        $preconditions = $this->verifierPreconditions();

        if (! $preconditions['autorise']) {
            return [
                'statut' => 'BLOQUE',
                'mode' => 'SIMULATION',
                'lecture_seule' => true,
                'execute_le' => now()->toIso8601String(),
                'blocages' => $preconditions['blocages'],
                'etat' => $preconditions['etat'],
                'perimetre' => [
                    'denominateur' => 0,
                    'tronque' => false,
                    'limite' => null,
                    'exclus' => [],
                ],
                'resultats' => [],
                'erreurs_techniques' => [],
                'indicateurs' => $this->indicateurs([], []),
            ];
        }

        $perimetre = $this->construirePerimetre();
        $resultats = [];
        $erreurs = [];

        foreach ($perimetre['dossiers'] as $dossier) {
            try {
                $resultats[] = $this->comparerDossier($dossier['inscription']);
            } catch (Throwable $e) {
                // Aucun message d'exception n'est exposé : il peut contenir
                // des valeurs de données. Seuls l'identifiant et le type sont
                // conservés pour le diagnostic.
                $erreurs[] = [
                    'inscription_id' => (int) $dossier['inscription']->id,
                    'type' => $e::class,
                ];
            }
        }

        return [
            'statut' => 'EXECUTE',
            'mode' => 'SIMULATION',
            'lecture_seule' => true,
            'execute_le' => now()->toIso8601String(),
            'blocages' => [],
            'etat' => $preconditions['etat'],
            'perimetre' => [
                'denominateur' => $perimetre['denominateur'],
                'tronque' => $perimetre['tronque'],
                'limite' => $perimetre['limite'],
                'exclus' => $perimetre['exclus'],
            ],
            'resultats' => $resultats,
            'erreurs_techniques' => $erreurs,
            'indicateurs' => $this->indicateurs($resultats, $erreurs, $perimetre),
        ];
    }

    /**
     * Indicateurs à dénominateur explicite. Aucun taux n'est calculé sur un
     * dénominateur nul : la valeur est `null`, jamais 0 ou 100.
     *
     * @param  list<array<string, mixed>>  $resultats
     * @param  list<array<string, mixed>>  $erreurs
     * @param  array<string, mixed>|null  $perimetre
     * @return array<string, mixed>
     */
    public function indicateurs(array $resultats, array $erreurs = [], ?array $perimetre = null): array
    {
        $analyses = count($resultats);
        $comparables = 0;
        $concordants = 0;
        $repartition = array_fill_keys(self::CLASSES_DIVERGENCE, 0);
        $repartitionTypologies = array_fill_keys(self::TYPOLOGIES_DIVERGENCE, 0);

        foreach ($resultats as $resultat) {
            if (($resultat['comparable'] ?? false) === true) {
                $comparables++;
            }

            if (($resultat['concordant'] ?? false) === true) {
                $concordants++;
            }

            $classe = $resultat['classe'] ?? null;
            if (is_string($classe) && array_key_exists($classe, $repartition)) {
                $repartition[$classe]++;
            }

            $typologie = $resultat['typologie_divergence'] ?? null;
            if (is_string($typologie) && array_key_exists($typologie, $repartitionTypologies)) {
                $repartitionTypologies[$typologie]++;
            }
        }

        $nonComparables = $analyses - $comparables;

        return [
            'dossiers_perimetre' => $perimetre['denominateur'] ?? $analyses,
            'dossiers_analyses' => $analyses,
            'dossiers_comparables' => $comparables,
            'dossiers_concordants' => $concordants,
            'dossiers_non_comparables' => $nonComparables,
            'dossiers_exclus_hors_perimetre' => count($perimetre['exclus'] ?? []),
            'erreurs_techniques' => count($erreurs),
            'taux_concordance' => [
                'numerateur' => $concordants,
                'denominateur' => $comparables,
                'valeur' => $comparables > 0 ? round($concordants / $comparables, 4) : null,
                'exclus_non_comparables' => $nonComparables,
                'formule' => 'concordants / comparables',
            ],
            'taux_couverture_reference' => [
                'numerateur' => $comparables,
                'denominateur' => $analyses,
                'valeur' => $analyses > 0 ? round($comparables / $analyses, 4) : null,
                'exclus_non_comparables' => $nonComparables,
                'formule' => 'comparables / analyses',
            ],
            'repartition_classes' => $repartition,
            'repartition_typologies' => $repartitionTypologies,
            'dossiers_avec_regle_formation_confirmee' => count(array_filter(
                $resultats,
                fn (array $r) => ($r['regle_formation_confirmee'] ?? false) === true
            )),
        ];
    }

    /**
     * Qualifie un résultat de comparaison sous l'une des 7 typologies institutionnelles (PHASE 42.4).
     *
     * @param  array<string, mixed>  $resultat
     */
    public function qualifierTypologieDivergence(array $resultat): ?string
    {
        if (($resultat['concordant'] ?? false) === true) {
            return null;
        }

        $classe = $resultat['classe'] ?? null;
        $couverture = $resultat['couverture_reglementaire'] ?? 'AUCUNE';
        $ecarts = $resultat['ecarts'] ?? [];

        if ($classe === self::CLASSE_REFERENCE_ABSENTE) {
            return self::TYPOLOGIE_SOURCE_INSUFFISANTE;
        }

        if ($couverture !== 'FORMATION' && $couverture !== 'NATIONALE') {
            return self::TYPOLOGIE_DIVERGENCE_REGLEMENTAIRE;
        }

        if ($classe === self::CLASSE_REFERENCE_NON_PUBLIEE) {
            return self::TYPOLOGIE_DIVERGENCE_INTERPRETATION;
        }

        if (isset($ecarts['decision'])) {
            return self::TYPOLOGIE_DIVERGENCE_HISTORIQUE;
        }

        if (isset($ecarts['moyenne'])) {
            return self::TYPOLOGIE_DIVERGENCE_CALCUL;
        }

        if (isset($ecarts['credits']) || isset($ecarts['ues'])) {
            return self::TYPOLOGIE_DIVERGENCE_MAQUETTE;
        }

        return self::TYPOLOGIE_DIVERGENCE_DONNEES;
    }

    /**
     * Sépare le corpus historique sous les 5 sous-ensembles canoniques (ADDENDUM R42.1).
     *
     * @param  iterable<InscriptionAcademique>|null  $inscriptions
     * @return array<string, mixed>
     */
    public function classifierCorpusHistorique(?iterable $inscriptions = null): array
    {
        if ($inscriptions === null) {
            $inscriptions = InscriptionAcademique::query()
                ->with(['etudiant', 'programmeRef', 'notes'])
                ->get();
        }

        $classes = [
            self::CORPUS_PV_OFFICIEL_EXPLOITABLE => [],
            self::CORPUS_RESULTAT_NORMALISE => [],
            self::CORPUS_NOTE_SANS_DELIBERATION_NORMALISEE => [],
            self::CORPUS_SOURCE_JUSTIFICATIVE_ABSENTE => [],
            self::CORPUS_REGLE_INCERTAINE => [],
        ];

        foreach ($inscriptions as $inscription) {
            $etudiant = $inscription->etudiant;
            $programme = $inscription->programmeRef ?? $etudiant?->programmeRef;

            $statutRegle = 'SOURCE_MANQUANTE';
            if ($programme !== null) {
                try {
                    $statutRegle = $this->reglementation->evaluerStatutRegle($programme)['statut'] ?? 'SOURCE_MANQUANTE';
                } catch (Throwable) {
                    $statutRegle = 'SOURCE_MANQUANTE';
                }
            }

            $decision = DecisionDeliberation::query()
                ->where('inscription_academique_id', $inscription->id)
                ->first();

            $nbNotes = $inscription->notes?->count() ?? 0;

            if ($statutRegle === 'REGLE_A_VALIDER' || $statutRegle === 'SOURCE_MANQUANTE' || $statutRegle === 'REGLE_EXPIREE') {
                $classes[self::CORPUS_REGLE_INCERTAINE][] = [
                    'inscription_id' => (int) $inscription->id,
                    'etudiant_id' => (int) $inscription->etudiant_id,
                    'statut_regle' => $statutRegle,
                    'motif' => "Règle réglementaire non confirmée au JORT ({$statutRegle}).",
                ];
            } elseif ($decision !== null && $decision->publie) {
                $classes[self::CORPUS_PV_OFFICIEL_EXPLOITABLE][] = [
                    'inscription_id' => (int) $inscription->id,
                    'etudiant_id' => (int) $inscription->etudiant_id,
                    'decision_id' => (int) $decision->id,
                    'deliberation_id' => (int) $decision->deliberation_id,
                ];
            } elseif ($decision !== null && ! $decision->publie) {
                $classes[self::CORPUS_RESULTAT_NORMALISE][] = [
                    'inscription_id' => (int) $inscription->id,
                    'etudiant_id' => (int) $inscription->etudiant_id,
                    'decision_id' => (int) $decision->id,
                    'statut' => 'NON_PUBLIE',
                ];
            } elseif ($nbNotes > 0) {
                // R42.1 : Ne pas considérer l'absence de délibération normalisée comme une preuve d'invalidité de l'inscription
                $classes[self::CORPUS_NOTE_SANS_DELIBERATION_NORMALISEE][] = [
                    'inscription_id' => (int) $inscription->id,
                    'etudiant_id' => (int) $inscription->etudiant_id,
                    'nb_notes' => $nbNotes,
                    'statut_inscription' => $inscription->statut,
                    'inscription_valide' => true,
                    'motif' => 'Notes historiques présentes sans délibération normalisée (inscription réputée valide).',
                ];
            } else {
                $classes[self::CORPUS_SOURCE_JUSTIFICATIVE_ABSENTE][] = [
                    'inscription_id' => (int) $inscription->id,
                    'etudiant_id' => (int) $inscription->etudiant_id,
                    'motif' => 'Aucune note ni PV de délibération répertorié.',
                ];
            }
        }

        return [
            'total_dossiers' => count($classes[self::CORPUS_PV_OFFICIEL_EXPLOITABLE])
                + count($classes[self::CORPUS_RESULTAT_NORMALISE])
                + count($classes[self::CORPUS_NOTE_SANS_DELIBERATION_NORMALISEE])
                + count($classes[self::CORPUS_SOURCE_JUSTIFICATIVE_ABSENTE])
                + count($classes[self::CORPUS_REGLE_INCERTAINE]),
            'repartition' => [
                self::CORPUS_PV_OFFICIEL_EXPLOITABLE => count($classes[self::CORPUS_PV_OFFICIEL_EXPLOITABLE]),
                self::CORPUS_RESULTAT_NORMALISE => count($classes[self::CORPUS_RESULTAT_NORMALISE]),
                self::CORPUS_NOTE_SANS_DELIBERATION_NORMALISEE => count($classes[self::CORPUS_NOTE_SANS_DELIBERATION_NORMALISEE]),
                self::CORPUS_SOURCE_JUSTIFICATIVE_ABSENTE => count($classes[self::CORPUS_SOURCE_JUSTIFICATIVE_ABSENTE]),
                self::CORPUS_REGLE_INCERTAINE => count($classes[self::CORPUS_REGLE_INCERTAINE]),
            ],
            'details' => $classes,
            'garantie_absence_deliberation' => 'L\'absence de délibération normalisée ne préjuge pas de l\'invalidité de l\'inscription administrative.',
        ];
    }

    /**
     * Audit d'investigation des 52 matières sans UE (ADDENDUM R42.2).
     *
     * @return array<string, mixed>
     */
    public function auditerMatieresSansUe(): array
    {
        $matieresSansUe = Matiere::query()
            ->whereNull('ue_id')
            ->withCount('notes')
            ->get();

        $audit = [];
        $totalNotesLiees = 0;

        foreach ($matieresSansUe as $m) {
            $totalNotesLiees += $m->notes_count;
            $audit[] = [
                'matiere_id' => (int) $m->id,
                'code' => $m->code,
                'nom' => $m->nom,
                'niveau_historique' => $m->niveau,
                'semestre_historique' => $m->semestre,
                'filiere_historique' => $m->filiere,
                'notes_liees' => (int) $m->notes_count,
                'ue_id_actuel' => null,
                'statut_cartographie' => 'ATTENTE_AFFECTATION_PEDAGOGIQUE',
                'action_requise' => 'ARBITRAGE_COMMISSION_PEDAGOGIQUE',
            ];
        }

        return [
            'total_matieres_sans_ue' => $matieresSansUe->count(),
            'total_notes_impactees' => $totalNotesLiees,
            'interdiction_affectation_automatique_par_libelle' => true,
            'principe_securite' => 'Aucune UE ne doit être attribuée automatiquement sur la seule base du libellé d\'une matière (R42.2).',
            'matieres' => $audit,
        ];
    }

    /**
     * Génère une empreinte SHA-256 canonique normalisée (ADDENDUM R42.3).
     */
    public function genererEmpreinteCanonique(mixed $donnees): string
    {
        $normalise = $this->normaliserPourEmpreinte($donnees);

        return hash('sha256', (string) json_encode($normalise, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Normalisation récursive déterministe pour empreinte SHA-256.
     */
    private function normaliserPourEmpreinte(mixed $donnees): mixed
    {
        if (is_array($donnees)) {
            $exclus = ['created_at', 'updated_at', 'session_id', 'token', 'remember_token', 'execute_le'];
            $resultat = [];

            foreach ($donnees as $cle => $valeur) {
                if (in_array((string) $cle, $exclus, true)) {
                    continue;
                }
                $resultat[$cle] = $this->normaliserPourEmpreinte($valeur);
            }

            ksort($resultat);

            return $resultat;
        }

        if (is_float($donnees)) {
            return number_format($donnees, 2, '.', '');
        }

        if ($donnees instanceof DateTimeInterface) {
            return $donnees->format(DateTimeInterface::ATOM);
        }

        return $donnees;
    }

    /**
     * @param  array<string, mixed>  $perimetre
     */
    private function perimetreEstConfigure(array $perimetre): bool
    {
        foreach (['facultes', 'programmes', 'annees', 'niveaux', 'etudiants'] as $cle) {
            if (! empty($perimetre[$cle])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $unites
     */
    private function compterUesValidees(iterable $unites): int
    {
        $total = 0;

        foreach ($unites as $unite) {
            if (in_array($unite['statut'] ?? null, self::STATUTS_UE_VALIDEE, true)) {
                $total++;
            }
        }

        return $total;
    }

    /**
     * @return array{moteur: string|null, reference: string|null}|null
     */
    private function comparerDecision(?string $decisionReference, ?string $decisionLegacy): ?array
    {
        $reference = $this->normaliserDecision($decisionReference);
        $legacy = $this->normaliserDecision($decisionLegacy);

        if ($reference === null || $legacy === null) {
            return null;
        }

        if ($reference === $legacy) {
            return null;
        }

        return [
            'reference' => $reference,
            'legacy' => $legacy,
        ];
    }

    /**
     * @return array{reference: float, legacy: float, delta: float}|null
     */
    private function comparerMoyenne(mixed $moyenneReference, mixed $moyenneLegacy): ?array
    {
        if (! is_numeric($moyenneReference) || ! is_numeric($moyenneLegacy)) {
            return null;
        }

        $reference = (float) $moyenneReference;
        $legacy = (float) $moyenneLegacy;
        $tolerance = (float) config('pilote_academique.tolerance_moyenne', 0.01);

        if (abs($reference - $legacy) <= $tolerance) {
            return null;
        }

        return [
            'reference' => $reference,
            'legacy' => $legacy,
            'delta' => round($reference - $legacy, 4),
        ];
    }

    private function normaliserDecision(?string $decision): ?string
    {
        if ($decision === null) {
            return null;
        }

        $normalisee = mb_strtolower(trim($decision));

        return $normalisee === '' ? null : $normalisee;
    }

    /**
     * @param  array<string, array<string, mixed>>  $ecarts
     */
    private function classerEcarts(array $ecarts): ?string
    {
        if ($ecarts === []) {
            return null;
        }

        if (count($ecarts) >= 2) {
            return self::CLASSE_ECART_MULTIPLE;
        }

        return match (array_key_first($ecarts)) {
            'credits' => self::CLASSE_ECART_CREDITS,
            'ues' => self::CLASSE_ECART_UES,
            'decision' => self::CLASSE_ECART_DECISION,
            'moyenne' => self::CLASSE_ECART_MOYENNE,
            default => self::CLASSE_ECART_MULTIPLE,
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $ecarts
     * @return list<string>
     */
    private function dimensionsComparees(
        DecisionDeliberation $reference,
        InscriptionAcademique $inscription
    ): array {
        $dimensions = ['credits', 'ues'];

        if ($this->normaliserDecision($reference->decision) !== null
            && $this->normaliserDecision($inscription->decision) !== null) {
            $dimensions[] = 'decision';
        }

        if (is_numeric($reference->moyenne) && is_numeric($inscription->moyenne_annuelle)) {
            $dimensions[] = 'moyenne';
        }

        return $dimensions;
    }

    /**
     * @return array<string, mixed>
     */
    private function resultatNonComparable(
        InscriptionAcademique $inscription,
        string $classe,
        string $motif,
        ?array $moteur = null,
        ?array $couverture = null,
        ?int $referenceId = null
    ): array {
        $etudiant = $inscription->etudiant;

        return [
            'inscription_id' => (int) $inscription->id,
            'etudiant_id' => (int) $inscription->etudiant_id,
            'faculte_id' => $etudiant !== null ? (int) $etudiant->faculte_id : null,
            'programme_id' => $inscription->programme_id !== null ? (int) $inscription->programme_id : null,
            'annee_universitaire_id' => (int) $inscription->annee_universitaire_id,
            'niveau' => $inscription->niveau,
            'decision_officielle_id' => $referenceId,
            'comparable' => false,
            'concordant' => false,
            'classe' => $classe,
            'typologie_divergence' => $this->qualifierTypologieDivergence([
                'concordant' => false,
                'classe' => $classe,
                'couverture_reglementaire' => $couverture['niveau_couverture'] ?? 'AUCUNE',
                'ecarts' => [],
            ]),
            'empreinte_canonique' => $this->genererEmpreinteCanonique([
                'inscription_id' => (int) $inscription->id,
                'etudiant_id' => (int) $inscription->etudiant_id,
                'classe' => $classe,
                'motif' => $motif,
            ]),
            'motif' => $motif,
            'ecarts' => [],
            'dimensions_comparees' => [],
            'mesures' => [
                'credits_moteur' => (int) ($moteur['credits']['capitalises'] ?? 0),
                'credits_reference' => null,
                'ues_moteur' => $this->compterUesValidees($moteur['unites'] ?? []),
                'ues_reference' => null,
            ],
            'couverture_reglementaire' => $couverture['niveau_couverture'] ?? 'AUCUNE',
            'regle_formation_confirmee' => ($couverture['niveau_couverture'] ?? 'AUCUNE') === 'FORMATION',
        ];
    }
}
