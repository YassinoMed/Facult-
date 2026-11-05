<?php

namespace App\Services\Acquis;

use App\Models\InscriptionAcademique;
use App\Models\Note;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use DomainException;

/**
 * LOOP 46 — EXPLICATION EN LECTURE SEULE D'UNE DÉCISION ACADÉMIQUE PUBLIÉE.
 *
 * Ce service ne décide rien, ne note rien et ne modifie rien :
 * - il refuse toute décision non publiée (fail-closed) ;
 * - il confronte uniquement des faits mesurables de l'acte officiel à la
 *   lecture du moteur d'acquis, sans réinterpréter la décision du jury ;
 * - il marque explicitement les faits non comparables (notes cumulées sur
 *   plusieurs inscriptions, nombre d'UE différent, moyenne non recalculée) ;
 * - il ne produit ni recommandation, ni sanction, ni plan d'action ;
 * - il ne diffuse aucune donnée identifiante (nom, CIN, email, matricule en
 *   clair) : seul un matricule masqué est exposé.
 *
 * La décision officielle reste l'acte souverain du jury ; la lecture du
 * moteur est une aide à la compréhension, jamais une décision parallèle.
 */
class ExplicationDecisionAcademiqueService
{
    public const VERSION = 'LOOP46-1';

    /**
     * Statuts de délibération considérés comme publiés.
     * `published` est le statut de publication ; `locked` et `archived`
     * conservent l'historique d'un acte déjà publié.
     */
    private const STATUTS_DELIBERATION_PUBLIEE = ['published', 'locked', 'archived'];

    private const MAX_CITATIONS = 50;

    public function __construct(
        private readonly MoteurDecisionAcademiqueService $moteurDecision,
    ) {}

    /**
     * Produit l'explication structurée d'une décision académique.
     *
     * @return array<string, mixed>
     */
    public function expliquer(DecisionDeliberation $decision): array
    {
        $decision->loadMissing(['etudiant', 'deliberation', 'inscriptionAcademique']);

        $refus = $this->verifierExplicabilite($decision);
        if ($refus !== null) {
            return $this->reponseRefus($decision, $refus['motif'], $refus['explication'], $refus['alertes']);
        }

        try {
            $lecture = $this->moteurDecision->determinerDecision(
                $decision->etudiant,
                $decision->inscriptionAcademique
            );
        } catch (DomainException $exception) {
            return $this->reponseRefus(
                $decision,
                'REGIME_INDETERMINE',
                'Le régime réglementaire du programme ne peut pas être établi de manière certaine : aucune explication chiffrée n\'est produite.',
                [$exception->getMessage()]
            );
        }

        $alertes = $this->alertesIntegrite($decision);
        $comparaison = $this->comparer($decision, $lecture);
        $citations = $this->construireCitations($decision, $lecture);
        $canonique = $this->canonique($decision, $lecture);

        return [
            'version' => self::VERSION,
            'lecture_seule' => true,
            'explicable' => true,
            'motif_indisponibilite' => null,
            'etudiant' => [
                'id' => (int) $decision->etudiant_id,
                'matricule_masque' => self::masquerIdentifiant($decision->etudiant?->num_inscription),
            ],
            'decision_officielle' => [
                'id' => (int) $decision->id,
                'deliberation_id' => (int) $decision->deliberation_id,
                'inscription_academique_id' => $decision->inscription_academique_id === null
                    ? null
                    : (int) $decision->inscription_academique_id,
                'reference' => 'evaluation_decisions#'.$decision->id,
                'deliberation_reference' => 'evaluation_deliberations#'.$decision->deliberation_id,
                'inscription_reference' => $decision->inscription_academique_id !== null
                    ? 'inscriptions_academiques#'.$decision->inscription_academique_id
                    : null,
                'decision' => (string) $decision->decision,
                'portee' => 'acte_souverain_du_jury',
                'publiee' => true,
                'publiee_le' => $decision->deliberation?->published_at?->toIso8601String(),
                'moyenne' => $decision->moyenne === null ? null : round((float) $decision->moyenne, 2),
                'credits_obtenus' => (int) $decision->credits_obtenus,
                'credits_total' => (int) $decision->credits_total,
                'ues_validees' => (int) $decision->ues_validees,
                'ues_total' => (int) $decision->ues_total,
                'commentaire_present' => $decision->commentaire !== null && trim((string) $decision->commentaire) !== '',
                'decision_officielle_fait_foi' => true,
            ],
            'lecture_moteur' => $this->extraireLectureMoteur($lecture),
            'concordance' => $comparaison,
            'citations' => $citations,
            'alertes_integrite' => $alertes,
            'limites' => $this->limites(),
            'garanties' => [
                'aucune_modification' => true,
                'aucune_recommandation' => true,
                'aucune_sanction' => true,
                'aucune_consequence_automatique' => true,
                'aucune_donnee_identifiante_en_clair' => true,
            ],
            'empreinte' => $this->calculerEmpreinte($canonique),
            'empreinte_portee' => 'sha256 des faits officiels et de la lecture du moteur, sans donnée identifiante',
        ];
    }

    /**
     * Vérifie qu'une explication n'a pas été altérée après production.
     *
     * @param  array<string, mixed>  $explication
     */
    public function verifierEmpreinte(array $explication): bool
    {
        $empreinte = $explication['empreinte'] ?? null;
        if (! is_string($empreinte) || $empreinte === '') {
            return false;
        }

        $canonique = $this->canoniqueDepuisExplication($explication);
        if ($canonique === null) {
            return false;
        }

        return hash_equals($empreinte, $this->calculerEmpreinte($canonique));
    }

    /**
     * Masque un identifiant sans jamais le restituer en clair.
     * Exemple : « R4500001 » devient « R4****01 ».
     */
    public static function masquerIdentifiant(?string $valeur): ?string
    {
        if ($valeur === null || trim($valeur) === '') {
            return null;
        }

        $valeur = trim($valeur);
        $longueur = mb_strlen($valeur);

        if ($longueur <= 4) {
            return str_repeat('*', $longueur);
        }

        return mb_substr($valeur, 0, 2)
            .str_repeat('*', max(1, $longueur - 4))
            .mb_substr($valeur, -2);
    }

    /**
     * @return array{motif: string, explication: string, alertes: array<int, string>}|null
     */
    private function verifierExplicabilite(DecisionDeliberation $decision): ?array
    {
        if (! (bool) $decision->publie) {
            return [
                'motif' => 'DECISION_NON_PUBLIEE',
                'explication' => 'La décision n\'est pas publiée : aucun résultat non officiel n\'est expliqué.',
                'alertes' => [],
            ];
        }

        if ($decision->deliberation === null) {
            return [
                'motif' => 'DELIBERATION_INTROUVABLE',
                'explication' => 'La délibération rattachée à la décision est introuvable : l\'acte officiel ne peut pas être cité.',
                'alertes' => [],
            ];
        }

        if (! in_array((string) $decision->deliberation->statut, self::STATUTS_DELIBERATION_PUBLIEE, true)) {
            return [
                'motif' => 'DELIBERATION_NON_PUBLIEE',
                'explication' => 'La délibération n\'est pas dans un statut publié : la décision est incohérente avec son acte porteur et n\'est pas expliquée.',
                'alertes' => ['Statut de délibération observé : '.$decision->deliberation->statut],
            ];
        }

        if ($decision->inscription_academique_id === null || $decision->inscriptionAcademique === null) {
            return [
                'motif' => 'INSCRIPTION_NON_RATTACHEE',
                'explication' => 'La décision n\'est rattachée à aucune inscription académique : le périmètre des notes ne peut pas être déterminé sans risque d\'expliquer une autre année.',
                'alertes' => [],
            ];
        }

        if ($decision->etudiant === null) {
            return [
                'motif' => 'ETUDIANT_INTROUVABLE',
                'explication' => 'L\'étudiant rattaché à la décision est introuvable.',
                'alertes' => [],
            ];
        }

        if ((int) $decision->inscriptionAcademique->etudiant_id !== (int) $decision->etudiant_id) {
            return [
                'motif' => 'INSCRIPTION_INCOHERENTE',
                'explication' => 'L\'inscription rattachée à la décision appartient à un autre étudiant : aucune explication n\'est produite.',
                'alertes' => [],
            ];
        }

        if ($decision->etudiant->programmeRef === null && $decision->inscriptionAcademique->programmeRef === null) {
            return [
                'motif' => 'PROGRAMME_INDETERMINE',
                'explication' => 'Aucun programme d\'études n\'est rattaché au dossier : le régime réglementaire ne peut pas être établi.',
                'alertes' => [],
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $lecture
     * @return array<string, mixed>
     */
    private function comparer(DecisionDeliberation $decision, array $lecture): array
    {
        $perimetreIdentique = $this->perimetreIdentique($decision);

        $creditsOfficiels = (int) $decision->credits_obtenus;
        $creditsMoteur = (int) ($lecture['credits']['capitalises'] ?? 0);

        $faits = [[
            'fait' => 'credits_acquis',
            'libelle' => 'Crédits acquis',
            'officiel' => $creditsOfficiels,
            'moteur' => $creditsMoteur,
            'comparable' => $perimetreIdentique,
            'concordant' => $perimetreIdentique ? $creditsOfficiels === $creditsMoteur : null,
            'ecart' => $perimetreIdentique ? $creditsMoteur - $creditsOfficiels : null,
            'raison_non_comparable' => $perimetreIdentique
                ? null
                : 'La lecture du moteur agrège les notes de toutes les inscriptions (capitalisation à vie), alors que la décision officielle est rattachée à une seule inscription.',
        ]];

        $unitesMoteur = is_array($lecture['unites'] ?? null) ? $lecture['unites'] : [];
        $nombreUesMoteur = count($unitesMoteur);
        $nombreUesValideesMoteur = count(array_filter(
            $unitesMoteur,
            fn ($unite) => (int) ($unite['credits_potentiels'] ?? 0) > 0
                && (int) ($unite['credits_capitalises'] ?? 0) >= (int) ($unite['credits_potentiels'] ?? 0)
        ));

        $uesComparables = $perimetreIdentique
            && $nombreUesMoteur > 0
            && $nombreUesMoteur === (int) $decision->ues_total;

        $raisonUes = null;
        if (! $perimetreIdentique) {
            $raisonUes = 'Périmètre de notes cumulé sur plusieurs inscriptions : le décompte d\'UE n\'est pas directement comparable.';
        } elseif ($nombreUesMoteur === 0) {
            $raisonUes = 'Le moteur n\'a lu aucune UE pour ce programme et ce niveau.';
        } elseif ($nombreUesMoteur !== (int) $decision->ues_total) {
            $raisonUes = "Le nombre d'UE de la décision ({$decision->ues_total}) diffère du nombre d'UE lues par le moteur ({$nombreUesMoteur}).";
        }

        $faits[] = [
            'fait' => 'ues_validees',
            'libelle' => 'UE acquises',
            'officiel' => (int) $decision->ues_validees,
            'moteur' => $nombreUesValideesMoteur,
            'comparable' => $uesComparables,
            'concordant' => $uesComparables ? (int) $decision->ues_validees === $nombreUesValideesMoteur : null,
            'ecart' => $uesComparables ? $nombreUesValideesMoteur - (int) $decision->ues_validees : null,
            'raison_non_comparable' => $raisonUes,
        ];

        $faitsComparables = array_values(array_filter($faits, fn ($fait) => $fait['comparable'] === true));
        $ecarts = array_values(array_filter($faitsComparables, fn ($fait) => $fait['concordant'] === false));

        if ($faitsComparables === []) {
            $statut = 'NON_COMPARABLE';
        } elseif ($ecarts !== []) {
            $statut = 'ECART_MESURE';
        } else {
            $statut = 'CONCORDANTE';
        }

        return [
            'statut' => $statut,
            'perimetre_comparable' => $perimetreIdentique,
            'faits_compares' => $faits,
            'faits_non_compares' => [
                [
                    'fait' => 'moyenne',
                    'valeur_officielle' => $decision->moyenne === null ? null : round((float) $decision->moyenne, 2),
                    'raison' => 'Le moteur ne recalcule pas de moyenne générale : aucune comparaison n\'est revendiquée.',
                ],
                [
                    'fait' => 'credits_total',
                    'valeur_officielle' => (int) $decision->credits_total,
                    'raison' => 'Le total officiel des crédits de l\'année n\'est pas le total requis par le programme : la comparaison serait trompeuse.',
                ],
            ],
            'ecarts' => $ecarts,
            'explication' => $this->texteConcordance($statut, $faitsComparables, $ecarts),
            'consequence_automatique' => false,
            'nature' => 'constat_technique',
        ];
    }

    /**
     * Le moteur lit toutes les notes de l'étudiant. La comparaison chiffrée
     * n'est légitime que si le dossier ne comporte qu'une inscription et
     * aucune note hors de cette inscription.
     */
    private function perimetreIdentique(DecisionDeliberation $decision): bool
    {
        $inscriptionId = (int) $decision->inscription_academique_id;

        $nombreInscriptions = InscriptionAcademique::where('etudiant_id', $decision->etudiant_id)->count();
        if ($nombreInscriptions !== 1) {
            return false;
        }

        $notesHorsInscription = Note::where('etudiant_id', $decision->etudiant_id)
            ->where(function ($requete) use ($inscriptionId) {
                $requete->whereNull('inscription_academique_id')
                    ->orWhere('inscription_academique_id', '!=', $inscriptionId);
            })
            ->count();

        return $notesHorsInscription === 0;
    }

    /**
     * @param  array<int, array<string, mixed>>  $faitsComparables
     * @param  array<int, array<string, mixed>>  $ecarts
     */
    private function texteConcordance(string $statut, array $faitsComparables, array $ecarts): string
    {
        if ($statut === 'CONCORDANTE') {
            return 'Les faits comparables concordent avec la lecture du moteur. Ce constat ne modifie ni la décision officielle ni le statut de l\'étudiant.';
        }

        if ($statut === 'ECART_MESURE') {
            $details = array_map(
                fn ($ecart) => "{$ecart['libelle']} : officiel {$ecart['officiel']}, moteur {$ecart['moteur']} (écart {$ecart['ecart']})",
                $ecarts
            );

            return 'Un ou plusieurs faits comparables diffèrent : '.implode(' ; ', $details).'. Il s\'agit d\'un constat technique sans conséquence automatique ; la décision officielle fait foi.';
        }

        return 'Aucun fait n\'est directement comparable : la lecture du moteur couvre un périmètre plus large que la décision officielle ou ne fournit pas les mêmes agrégats. Aucune conclusion chiffrée n\'est tirée.';
    }

    /**
     * @param  array<string, mixed>  $lecture
     * @return array<string, mixed>
     */
    private function extraireLectureMoteur(array $lecture): array
    {
        $regime = is_array($lecture['regime'] ?? null) ? $lecture['regime'] : [];

        return [
            'statut_analyse' => $lecture['statut_analyse'] ?? null,
            'sources_valides' => (bool) ($lecture['sources_valides'] ?? false),
            'decision_definitive' => (bool) ($lecture['decision_definitive'] ?? false),
            'regime' => [
                'code_regime' => $regime['code_regime'] ?? null,
                'nom' => $regime['nom'] ?? null,
                'compensation_autorisee' => $regime['compensation_autorisee'] ?? null,
                'seuil_competence_defaut' => $regime['seuil_competence_defaut'] ?? null,
                'reference_seuil' => $regime['reference_seuil'] ?? null,
                'textes_officiels' => $regime['textes_officiels'] ?? [],
            ],
            'credits' => [
                'capitalises' => (int) ($lecture['credits']['capitalises'] ?? 0),
                'non_acquis' => (int) ($lecture['credits']['non_acquis'] ?? 0),
                'restants' => (int) ($lecture['credits']['restants'] ?? 0),
                'total_requis' => (int) ($lecture['credits']['total_requis'] ?? 0),
            ],
            'unites' => array_map(fn ($unite) => [
                'ue_id' => (int) ($unite['ue_id'] ?? 0),
                'code' => $unite['code'] ?? null,
                'nom' => $unite['nom'] ?? null,
                'statut' => $unite['statut'] ?? null,
                'moyenne' => $unite['moyenne'] ?? null,
                'credits_capitalises' => (int) ($unite['credits_capitalises'] ?? 0),
                'credits_potentiels' => (int) ($unite['credits_potentiels'] ?? 0),
                'reprise_requise' => (bool) ($unite['reprise_requise'] ?? false),
                'motif' => $unite['motif'] ?? null,
                'regle_appliquee' => $unite['regle_appliquee'] ?? null,
                'ecues' => array_map(fn ($ecue) => [
                    'matiere_id' => (int) ($ecue['matiere_id'] ?? 0),
                    'code' => $ecue['code'] ?? null,
                    'nom' => $ecue['nom'] ?? null,
                    'note_retenue' => $ecue['note_retenue'] ?? null,
                    'statut' => $ecue['statut'] ?? null,
                    'credits_acquis' => (int) ($ecue['credits_acquis'] ?? 0),
                    'reprise_requise' => (bool) ($ecue['reprise_requise'] ?? false),
                    'motif' => $ecue['motif'] ?? null,
                ], is_array($unite['ecues'] ?? null) ? $unite['ecues'] : []),
            ], is_array($lecture['unites'] ?? null) ? $lecture['unites'] : []),
            'anomalies' => array_values($lecture['anomalies'] ?? []),
            'references_regles' => array_values($lecture['references_regles'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $lecture
     * @return array<int, array<string, mixed>>
     */
    private function construireCitations(DecisionDeliberation $decision, array $lecture): array
    {
        $citations = [[
            'type' => 'decision_officielle',
            'reference' => 'evaluation_decisions#'.$decision->id,
            'origine' => 'base_officielle',
            'verifiee' => true,
            'extrait' => 'Décision « '.$decision->decision.' » enregistrée pour l\'étudiant #'.$decision->etudiant_id
                .', crédits officiels '.$decision->credits_obtenus.'/'.$decision->credits_total.'.',
        ], [
            'type' => 'deliberation',
            'reference' => 'evaluation_deliberations#'.$decision->deliberation_id,
            'origine' => 'base_officielle',
            'verifiee' => true,
            'extrait' => 'Délibération au statut « '.$decision->deliberation?->statut.' », publiée le '
                .($decision->deliberation?->published_at?->toIso8601String() ?? 'date non renseignée').'.',
        ]];

        if ($decision->inscription_academique_id !== null) {
            $citations[] = [
                'type' => 'inscription',
                'reference' => 'inscriptions_academiques#'.$decision->inscription_academique_id,
                'origine' => 'base_officielle',
                'verifiee' => true,
                'extrait' => 'Inscription académique servant de périmètre à la lecture du moteur.',
            ];
        }

        $referencesVues = [];
        foreach (array_values($lecture['references_regles'] ?? []) as $reference) {
            if (! is_string($reference) || $reference === '' || isset($referencesVues[$reference])) {
                continue;
            }
            $referencesVues[$reference] = true;

            $citations[] = [
                'type' => 'reference_regle',
                'reference' => $reference,
                'origine' => 'moteur_decision',
                'verifiee' => false,
                'extrait' => 'Référence déclarée par le moteur d\'acquis ; le texte réglementaire n\'est pas rouvert dans cette explication.',
            ];
        }

        foreach (array_values($lecture['unites'] ?? []) as $unite) {
            $reference = $unite['regle_appliquee'] ?? null;
            $code = $unite['code'] ?? null;
            if (! is_string($reference) || $reference === '' || ! is_string($code) || $code === '') {
                continue;
            }

            $cle = $code.'|'.$reference;
            if (isset($referencesVues[$cle])) {
                continue;
            }
            $referencesVues[$cle] = true;

            $citations[] = [
                'type' => 'regle_ue',
                'reference' => 'UE '.$code,
                'origine' => 'moteur_decision',
                'verifiee' => false,
                'extrait' => $reference,
            ];
        }

        return array_slice($citations, 0, self::MAX_CITATIONS);
    }

    /**
     * @return array<int, string>
     */
    private function alertesIntegrite(DecisionDeliberation $decision): array
    {
        $alertes = [];

        if ((string) $decision->deliberation?->statut === 'published' && $decision->deliberation?->verrouillee_at === null) {
            $alertes[] = 'La délibération est publiée mais non verrouillée : l\'acte officiel peut encore évoluer.';
        }

        $nombreDecisions = DecisionDeliberation::where('etudiant_id', $decision->etudiant_id)
            ->where('inscription_academique_id', $decision->inscription_academique_id)
            ->count();

        if ($nombreDecisions > 1) {
            $alertes[] = "Plusieurs décisions ({$nombreDecisions}) sont rattachées à la même inscription : l'explication porte sur la décision demandée, mais le moteur peut retenir la plus récente.";
        }

        if ($decision->moyenne === null) {
            $alertes[] = 'La moyenne officielle n\'est pas renseignée dans la décision.';
        }

        return $alertes;
    }

    /**
     * @return array<int, string>
     */
    private function limites(): array
    {
        return [
            'La décision officielle du jury reste l\'unique acte qui fait foi ; cette explication ne la remplace pas.',
            'Aucune note, décision, inscription, délibération ou statut n\'est modifié par cette lecture.',
            'Aucune recommandation, sanction, orientation ou mesure corrective n\'est produite.',
            'Les références réglementaires sont celles déclarées par le moteur d\'acquis ; leur texte n\'est pas rouvert ici.',
            'Lorsque les notes couvrent plusieurs inscriptions, la comparaison chiffrée est marquée non comparable au lieu d\'être extrapolée.',
        ];
    }

    /**
     * @param  array<int, string>  $alertes
     * @return array<string, mixed>
     */
    private function reponseRefus(DecisionDeliberation $decision, string $motif, string $explication, array $alertes = []): array
    {
        return [
            'version' => self::VERSION,
            'lecture_seule' => true,
            'explicable' => false,
            'motif_indisponibilite' => $motif,
            'etudiant' => [
                'id' => (int) $decision->etudiant_id,
                'matricule_masque' => self::masquerIdentifiant($decision->etudiant?->num_inscription),
            ],
            'decision_officielle' => [
                'reference' => $decision->exists ? 'evaluation_decisions#'.$decision->id : null,
                'publiee' => (bool) $decision->publie,
                'decision_officielle_fait_foi' => true,
            ],
            'lecture_moteur' => null,
            'concordance' => [
                'statut' => 'NON_EXPLICABLE',
                'perimetre_comparable' => false,
                'faits_compares' => [],
                'faits_non_compares' => [],
                'ecarts' => [],
                'explication' => $explication,
                'consequence_automatique' => false,
                'nature' => 'refus_fail_closed',
            ],
            'citations' => [],
            'alertes_integrite' => array_values($alertes),
            'limites' => $this->limites(),
            'garanties' => [
                'aucune_modification' => true,
                'aucune_recommandation' => true,
                'aucune_sanction' => true,
                'aucune_consequence_automatique' => true,
                'aucune_donnee_identifiante_en_clair' => true,
            ],
            'empreinte' => null,
            'empreinte_portee' => null,
            'motif_refus' => $motif,
        ];
    }

    /**
     * Faits stables entrant dans l'empreinte : aucune donnée identifiante.
     *
     * @param  array<string, mixed>  $lecture
     * @return array<string, mixed>
     */
    private function canonique(DecisionDeliberation $decision, array $lecture): array
    {
        $unites = array_map(fn ($unite) => [
            'ue_id' => (int) ($unite['ue_id'] ?? 0),
            'code' => (string) ($unite['code'] ?? ''),
            'statut' => (string) ($unite['statut'] ?? ''),
            'credits_capitalises' => (int) ($unite['credits_capitalises'] ?? 0),
            'credits_potentiels' => (int) ($unite['credits_potentiels'] ?? 0),
        ], is_array($lecture['unites'] ?? null) ? $lecture['unites'] : []);

        usort($unites, fn ($a, $b) => $a['ue_id'] <=> $b['ue_id']);

        return [
            'version' => self::VERSION,
            'decision' => [
                'id' => (int) $decision->id,
                'deliberation_id' => (int) $decision->deliberation_id,
                'etudiant_id' => (int) $decision->etudiant_id,
                'inscription_academique_id' => $decision->inscription_academique_id === null
                    ? null
                    : (int) $decision->inscription_academique_id,
                'decision' => (string) $decision->decision,
                'moyenne' => $decision->moyenne === null ? null : round((float) $decision->moyenne, 2),
                'credits_obtenus' => (int) $decision->credits_obtenus,
                'credits_total' => (int) $decision->credits_total,
                'ues_validees' => (int) $decision->ues_validees,
                'ues_total' => (int) $decision->ues_total,
                'publie' => (bool) $decision->publie,
            ],
            'moteur' => [
                'statut_analyse' => (string) ($lecture['statut_analyse'] ?? ''),
                'sources_valides' => (bool) ($lecture['sources_valides'] ?? false),
                'credits' => [
                    'capitalises' => (int) ($lecture['credits']['capitalises'] ?? 0),
                    'non_acquis' => (int) ($lecture['credits']['non_acquis'] ?? 0),
                    'restants' => (int) ($lecture['credits']['restants'] ?? 0),
                    'total_requis' => (int) ($lecture['credits']['total_requis'] ?? 0),
                ],
                'unites' => $unites,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $canonique
     */
    private function calculerEmpreinte(array $canonique): string
    {
        return hash('sha256', json_encode($canonique, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $explication
     * @return array<string, mixed>|null
     */
    private function canoniqueDepuisExplication(array $explication): ?array
    {
        $officiel = $explication['decision_officielle'] ?? null;
        $moteur = $explication['lecture_moteur'] ?? null;

        if (! is_array($officiel) || ! is_array($moteur)) {
            return null;
        }

        $unites = is_array($moteur['unites'] ?? null) ? $moteur['unites'] : [];
        $unites = array_map(fn ($unite) => [
            'ue_id' => (int) ($unite['ue_id'] ?? 0),
            'code' => (string) ($unite['code'] ?? ''),
            'statut' => (string) ($unite['statut'] ?? ''),
            'credits_capitalises' => (int) ($unite['credits_capitalises'] ?? 0),
            'credits_potentiels' => (int) ($unite['credits_potentiels'] ?? 0),
        ], $unites);
        usort($unites, fn ($a, $b) => $a['ue_id'] <=> $b['ue_id']);

        $decision = [
            'id' => (int) ($officiel['id'] ?? 0),
            'deliberation_id' => (int) ($officiel['deliberation_id'] ?? 0),
            'etudiant_id' => (int) ($explication['etudiant']['id'] ?? 0),
            'inscription_academique_id' => isset($officiel['inscription_academique_id'])
                ? (int) $officiel['inscription_academique_id']
                : null,
            'decision' => (string) ($officiel['decision'] ?? ''),
            'moyenne' => $officiel['moyenne'] ?? null,
            'credits_obtenus' => (int) ($officiel['credits_obtenus'] ?? 0),
            'credits_total' => (int) ($officiel['credits_total'] ?? 0),
            'ues_validees' => (int) ($officiel['ues_validees'] ?? 0),
            'ues_total' => (int) ($officiel['ues_total'] ?? 0),
            'publie' => (bool) ($officiel['publiee'] ?? false),
        ];

        $credits = is_array($moteur['credits'] ?? null) ? $moteur['credits'] : [];

        return [
            'version' => (string) ($explication['version'] ?? ''),
            'decision' => $decision,
            'moteur' => [
                'statut_analyse' => (string) ($moteur['statut_analyse'] ?? ''),
                'sources_valides' => (bool) ($moteur['sources_valides'] ?? false),
                'credits' => [
                    'capitalises' => (int) ($credits['capitalises'] ?? 0),
                    'non_acquis' => (int) ($credits['non_acquis'] ?? 0),
                    'restants' => (int) ($credits['restants'] ?? 0),
                    'total_requis' => (int) ($credits['total_requis'] ?? 0),
                ],
                'unites' => $unites,
            ],
        ];
    }
}
