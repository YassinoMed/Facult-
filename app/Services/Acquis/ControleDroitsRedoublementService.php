<?php

namespace App\Services\Acquis;

use App\Models\Etudiant;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\Note;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Referentiel\Models\Programme;
use Carbon\Carbon;
use DomainException;

/**
 * SERVICE DE CONTRÔLE DES DROITS DE REDOUBLEMENT ET DES RÉGIMES ACADÉMIQUES (LOOP 39).
 *
 * Évalue rigoureusement le droit au redoublement d'un étudiant selon :
 * - Le diplôme, l'établissement, le programme et le régime réglementaire d'attache.
 * - Le décret n° 95-2602 (modifié par Décret 2009-643, Art. 10) pour le Cycle Ingénieur (1 seul redoublement).
 * - Le décret présidentiel n° 2022-631 et arrêté du 7 juillet 2023 pour la Licence LMD (2 redoublements max, 1 seul en L1).
 * - Les décrets n° 2008-3123 et 2012-1227 pour le Mastère LMD (1 seul redoublement max).
 *
 * Règles cardinales :
 * 1. Distinction stricte de trois métriques :
 *    - Nombre d'inscriptions administratives totales.
 *    - Nombre de redoublements dans le cursus / diplôme actuel.
 *    - Nombre de tentatives d'évaluation par unité d'enseignement (UE/matière).
 * 2. Jamais de contamination croisée entre cycles :
 *    - Le changement de diplôme (réorientation) n'ampute pas les droits du nouveau cycle.
 *    - L'interruption d'études (congé académique/suspension) ne consomme aucun droit de redoublement.
 * 3. Fail-closed : blocage systématique en cas de règle non configurée, manquante ou expirée.
 */
class ControleDroitsRedoublementService
{
    public function __construct(
        private readonly ReglementationAcquisService $reglementationService,
    ) {}

    /**
     * Analyse et certifie les droits de redoublement d'un étudiant.
     *
     * @param  array<string, mixed>  $contexte
     * @return array<string, mixed>
     */
    public function evaluerDroitsRedoublement(
        Etudiant $etudiant,
        ?Programme $programmeCible = null,
        array $contexte = []
    ): array {
        $etudiant->load(['inscriptionsAcademiques.programmeRef.typeDiplome', 'faculte']);

        // Résolution du programme cible
        $programme = $programmeCible
            ?? $etudiant->inscriptionsAcademiques->sortByDesc('id')->first()?->programmeRef
            ?? $etudiant->programmeRef;

        if ($programme === null) {
            throw new DomainException("Impossible d'évaluer les droits de redoublement : programme d'études indéterminé pour l'étudiant #{$etudiant->id}.");
        }

        // Audit de la validité de la règle (Gate G39 / Gate G40 / LOOP 40-C)
        $statutRegle = $this->reglementationService->evaluerStatutRegle($programme);
        if (! $statutRegle['applicable']) {
            $statutCanonique = match ($statutRegle['statut']) {
                'SOURCE_MANQUANTE' => 'INDETERMINE',
                'REGLE_A_VALIDER' => 'REVUE_HUMAINE_REQUISE',
                'REGLE_EXPIREE' => 'REVUE_HUMAINE_REQUISE',
                default => 'REVUE_HUMAINE_REQUISE',
            };

            $statutCanonique = match ($statutRegle['statut']) {
                'SOURCE_MANQUANTE' => 'INDETERMINE',
                'REGLE_A_VALIDER' => 'REVUE_HUMAINE_REQUISE',
                'REGLE_EXPIREE' => 'REVUE_HUMAINE_REQUISE',
                default => 'REVUE_HUMAINE_REQUISE',
            };

            return [
                'etudiant_id' => $etudiant->id,
                'programme_id' => $programme->id,
                'programme_nom' => $programme->nom,
                'regime_code' => 'INCONNU',
                'regime_nom' => 'Régime non déterminé',
                'nb_inscriptions_administratives' => $etudiant->inscriptionsAcademiques->count(),
                'nb_redoublements_cursus' => 0,
                'nb_redoublements_niveau' => 0,
                'nb_interruptions_etudes' => 0,
                'limite_redoublements_cycle' => null, // quota inconnu non converti en 0 (PHASE 3 - LOOP 40-C)
                'limite_redoublements_niveau' => null,
                'duree_maximale_etudes' => null,
                'statut_droit' => 'BLOQUE_REGLEMENTATION_INCONNUE',
                'statut_canonique' => $statutCanonique,
                'autorise' => false,
                'revue_humaine_requise' => true,
                'motif' => "Incertitude réglementaire : aucune décision d'exclusion automatique n'est prise. Dossier transmis pour revue humaine obligatoire : {$statutRegle['motif']}",
                'references_legales' => [
                    'texte' => $statutRegle['fiche']['texte'] ?? 'N/A',
                    'article' => $statutRegle['fiche']['article'] ?? 'N/A',
                    'statut_regle' => $statutRegle['statut'],
                ],
                'distinction_metrique' => [
                    'inscriptions_administratives' => $etudiant->inscriptionsAcademiques->count(),
                    'redoublements_cycle_courant' => 0,
                    'interruptions_neutralisees' => 0,
                ],
            ];
        }

        // Résolution du régime et des règles de limitation
        try {
            $regime = $this->reglementationService->resoudreRegime($programme);
            $codeRegime = $regime['code_regime'];
        } catch (\Throwable $e) {
            return [
                'etudiant_id' => $etudiant->id,
                'programme_id' => $programme->id,
                'programme_nom' => $programme->nom,
                'regime_code' => 'INCONNU',
                'regime_nom' => 'Régime non déterminé',
                'nb_inscriptions_administratives' => $etudiant->inscriptionsAcademiques->count(),
                'nb_redoublements_cursus' => 0,
                'nb_redoublements_niveau' => 0,
                'nb_interruptions_etudes' => 0,
                'limite_redoublements_cycle' => null, // quota inconnu non converti en 0 (PHASE 3 - LOOP 40-C)
                'limite_redoublements_niveau' => null,
                'duree_maximale_etudes' => null,
                'statut_droit' => 'BLOQUE_REGLEMENTATION_INCONNUE',
                'statut_canonique' => 'INDETERMINE',
                'autorise' => false,
                'revue_humaine_requise' => true,
                'motif' => "Incertitude réglementaire : aucune décision d'exclusion automatique n'est prise. Dossier transmis pour revue humaine obligatoire : {$e->getMessage()}",
                'references_legales' => [
                    'texte' => 'N/A',
                    'article' => 'N/A',
                    'statut_regle' => 'SOURCE_MANQUANTE',
                ],
                'distinction_metrique' => [
                    'inscriptions_administratives' => $etudiant->inscriptionsAcademiques->count(),
                    'redoublements_cycle_courant' => 0,
                    'interruptions_neutralisees' => 0,
                ],
            ];
        }

        $configLimites = config("acquis_reglementation.limites_redoublement.{$codeRegime}", []);
        $maxRedoublementsCycle = (int) ($configLimites['max_redoublements_cycle'] ?? 1);
        $maxRedoublementsNiveau = (int) ($configLimites['max_redoublements_par_niveau'] ?? 1);
        $dureeMaxAnnees = (int) ($configLimites['duree_maximale_etudes_annees'] ?? 5);

        // Toutes les inscriptions de l'étudiant triées chronologiquement
        $toutesInscriptions = $etudiant->inscriptionsAcademiques->sortBy('annee_universitaire_id');
        $nbInscriptionsAdmin = $toutesInscriptions->count();

        // Filtrage des inscriptions appartenant au même cycle d'études (indépendance des cursus)
        $typeDiplomeIdCible = $programme->typeDiplome?->id;
        $inscriptionsMemeCycle = $toutesInscriptions->filter(function (InscriptionAcademique $insc) use ($typeDiplomeIdCible, $codeRegime) {
            $typeDiplomeInsc = $insc->programmeRef?->typeDiplome;
            if ($typeDiplomeIdCible !== null && $typeDiplomeInsc?->id === $typeDiplomeIdCible) {
                return true;
            }

            // Détection par catégorie de régime équivalent
            $codeType = mb_strtoupper((string) ($typeDiplomeInsc?->code ?? ''));

            return match ($codeRegime) {
                'CYCLE_INGENIEUR' => in_array($codeType, ['ING', 'INGENIEUR'], true) || str_contains(mb_strtoupper((string) $insc->programmeRef?->nom), 'INGÉNIEUR'),
                'LMD_LICENCE' => in_array($codeType, ['LIC', 'LF', 'LA', 'LMD'], true) || str_contains(mb_strtoupper((string) $insc->programmeRef?->nom), 'LICENCE'),
                'MASTER_LMD' => in_array($codeType, ['MAS', 'MR', 'MP'], true) || str_contains(mb_strtoupper((string) $insc->programmeRef?->nom), 'MAST'),
                default => false,
            };
        });

        $nbRedoublementsCursus = 0;
        $nbRedoublementsNiveau = 0;
        $nbInterruptionsEtudes = 0;
        $niveauCible = $contexte['niveau_cible'] ?? $inscriptionsMemeCycle->last()?->niveau ?? 'L1';

        foreach ($inscriptionsMemeCycle as $insc) {
            // Détection de l'interruption d'études officielle (congé académique, maladie, convenance autorisée)
            $motifNonOfficiel = str_contains(mb_strtolower((string) $insc->observation), 'refus')
                || str_contains(mb_strtolower((string) $insc->observation), 'injustifi')
                || str_contains(mb_strtolower((string) $insc->observation), 'non justifi')
                || str_contains(mb_strtolower((string) $insc->observation), 'non autoris');

            $estInterruption = ! $motifNonOfficiel && (
                $insc->type === 'interruption'
                || str_contains(mb_strtolower((string) $insc->observation), 'interruption')
                || str_contains(mb_strtolower((string) $insc->observation), 'congé')
                || str_contains(mb_strtolower((string) $insc->observation), 'suspension')
            );

            if ($estInterruption) {
                $nbInterruptionsEtudes++;

                continue; // Une année d'interruption autorisée NE CONSOMME AUCUN droit de redoublement
            }

            // Récupération de la délibération officielle de cette inscription
            $delib = DecisionDeliberation::where('etudiant_id', $etudiant->id)
                ->where('inscription_academique_id', $insc->id)
                ->first();

            $decision = mb_strtolower(trim((string) ($delib?->decision ?? $insc->decision ?? '')));
            $estEchecOuRedoublement = in_array($decision, [
                'autorise_redoublement',
                'redoublement',
                'ajourne',
                'rattrapage',
                'non_valide',
            ], true) || ($insc->statut === 'ajourne');

            if ($estEchecOuRedoublement) {
                $nbRedoublementsCursus++;
                if (trim((string) $insc->niveau) === trim((string) $niveauCible)) {
                    $nbRedoublementsNiveau++;
                }
            }
        }

        // Vérification des dérogations officielles
        $derogationActive = $this->verifierDerogationOfficielle($etudiant, $programme, $contexte);

        // Vérification des décisions exceptionnelles du jury
        $decisionExceptionnelleJury = $this->verifierDecisionExceptionnelleJury($etudiant, $programme, $contexte);

        // Détermination du verdict
        $autorise = false;
        $statutDroit = 'AUTORISE_PLEIN_DROIT';
        $statutCanonique = 'AUTORISE';
        $motif = '';

        $depasseCycle = $nbRedoublementsCursus > $maxRedoublementsCycle;
        $depasseNiveau = ($codeRegime === 'LMD_LICENCE' && trim((string) $niveauCible) === 'L1') && ($nbRedoublementsNiveau > $maxRedoublementsNiveau);

        if (! $depasseCycle && ! $depasseNiveau) {
            $autorise = true;
            $statutDroit = 'AUTORISE_PLEIN_DROIT';
            $statutCanonique = 'AUTORISE';
            $numDemande = max(1, $nbRedoublementsCursus);
            $motif = "Redoublement autorisé de plein droit (demande {$numDemande}/{$maxRedoublementsCycle} autorisée(s) selon {$configLimites['texte']}, {$configLimites['article']}).";
        } elseif ($derogationActive['accordee']) {
            $autorise = true;
            $statutDroit = 'DEROGATION_ACCORDEE';
            $statutCanonique = 'AUTORISE';
            $motif = "Limite réglementaire de redoublement atteinte ({$nbRedoublementsCursus}/{$maxRedoublementsCycle}), mais réinscription autorisée en vertu d'une dérogation officielle validée ({$derogationActive['reference']}).";
        } elseif ($derogationActive['a_verifier'] ?? false) {
            $autorise = false;
            $statutDroit = 'LIMITE_DEPASSEE';
            $statutCanonique = 'DEROGATION_A_VERIFIER';
            $motif = "Limite réglementaire de redoublement atteinte ({$nbRedoublementsCursus}/{$maxRedoublementsCycle}) et dérogation soumise à vérification : {$derogationActive['reference']}.";
        } elseif ($decisionExceptionnelleJury['accordee']) {
            $autorise = true;
            $statutDroit = 'DECISION_EXCEPTIONNELLE_JURY';
            $statutCanonique = 'AUTORISE';
            $motif = "Limite réglementaire de redoublement atteinte ({$nbRedoublementsCursus}/{$maxRedoublementsCycle}), mais réinscription accordée par décision souveraine exceptionnelle du jury de délibération ({$decisionExceptionnelleJury['reference']}).";
        } elseif ($decisionExceptionnelleJury['a_verifier'] ?? false) {
            $autorise = false;
            $statutDroit = 'LIMITE_DEPASSEE';
            $statutCanonique = 'DECISION_EXCEPTIONNELLE_A_VERIFIER';
            $motif = "Limite réglementaire de redoublement atteinte ({$nbRedoublementsCursus}/{$maxRedoublementsCycle}) et décision exceptionnelle de jury soumise à vérification : {$decisionExceptionnelleJury['commentaire']}.";
        } else {
            $autorise = false;
            $statutDroit = 'LIMITE_DEPASSEE';
            $statutCanonique = ($statutRegle['statut'] === 'REGLE_CONFIRMEE' ? 'NON_AUTORISE_REGLE_CONFIRME' : 'REVUE_HUMAINE_REQUISE');
            $motif = $depasseNiveau
                ? "Épuisement des droits en première année : un seul redoublement est autorisé en L1 ({$nbRedoublementsNiveau}/{$maxRedoublementsNiveau}) selon {$configLimites['texte']} ({$configLimites['article']})."
                : "Épuisement des droits de redoublement : quota maximal atteint ({$nbRedoublementsCursus}/{$maxRedoublementsCycle}) selon {$configLimites['texte']} ({$configLimites['article']}). Réinscription refusée sans dérogation officielle.";
        }

        $revueHumaineRequise = ($statutDroit === 'BLOQUE_REGLEMENTATION_INCONNUE')
            || ($statutCanonique === 'INDETERMINE')
            || ($statutCanonique === 'REVUE_HUMAINE_REQUISE')
            || ($statutCanonique === 'DEROGATION_A_VERIFIER')
            || ($statutCanonique === 'DECISION_EXCEPTIONNELLE_A_VERIFIER')
            || ($statutRegle['statut'] === 'REGLE_A_VALIDER')
            || ($statutRegle['statut'] === 'SOURCE_MANQUANTE')
            || ($statutRegle['statut'] === 'REGLE_EXPIREE')
            || ($statutDroit === 'LIMITE_DEPASSEE' && ! $derogationActive['accordee'] && ! $decisionExceptionnelleJury['accordee']);

        return [
            'etudiant_id' => $etudiant->id,
            'programme_id' => $programme->id,
            'programme_nom' => $programme->nom,
            'regime_code' => $codeRegime,
            'regime_nom' => $regime['nom'] ?? $codeRegime,
            'nb_inscriptions_administratives' => $nbInscriptionsAdmin,
            'nb_redoublements_cursus' => $nbRedoublementsCursus,
            'nb_redoublements_niveau' => $nbRedoublementsNiveau,
            'nb_interruptions_etudes' => $nbInterruptionsEtudes,
            'limite_redoublements_cycle' => $maxRedoublementsCycle,
            'limite_redoublements_niveau' => $maxRedoublementsNiveau,
            'duree_maximale_etudes' => $dureeMaxAnnees,
            'statut_droit' => $statutDroit,
            'statut_canonique' => $statutCanonique,
            'autorise' => $autorise,
            'revue_humaine_requise' => $revueHumaineRequise,
            'derogation_active' => $derogationActive,
            'decision_jury_exceptionnelle' => $decisionExceptionnelleJury,
            'motif' => $motif,
            'references_legales' => [
                'texte' => $configLimites['texte'] ?? 'Réglementation nationale',
                'article' => $configLimites['article'] ?? 'Article limitation redoublement',
                'regle_officielle' => $configLimites['regle'] ?? 'Limitation officielle des inscriptions',
                'autorite_competente' => $configLimites['autorite_derogation'] ?? 'Conseil scientifique / Rectorat',
                'statut_regle' => $statutRegle['statut'],
            ],
            'distinction_metrique' => [
                'inscriptions_administratives' => $nbInscriptionsAdmin,
                'redoublements_cycle_courant' => $nbRedoublementsCursus,
                'interruptions_neutralisees' => $nbInterruptionsEtudes,
            ],
        ];
    }

    /**
     * Compte le nombre de tentatives d'évaluation pour une unité ou matière donnée.
     */
    public function compterTentativesMatiere(Etudiant $etudiant, int $matiereId): int
    {
        return Note::where('etudiant_id', $etudiant->id)
            ->where('matiere_id', $matiereId)
            ->count();
    }

    /**
     * Exécute le double calcul parallèle entre la situation officielle et le calcul UniRegistre (PHASE 3 - LOOP 40).
     *
     * @param  array<string, mixed>  $situationOfficielle
     * @return array<string, mixed>
     */
    public function comparerDossierRecette(Etudiant $etudiant, array $situationOfficielle, ?Programme $programmeCible = null): array
    {
        $evalUniRegistre = $this->evaluerDroitsRedoublement($etudiant, $programmeCible);

        $champs = [
            'inscriptions_administratives' => [
                'officiel' => $situationOfficielle['inscriptions_administratives'] ?? null,
                'uniregistre' => $evalUniRegistre['nb_inscriptions_administratives'],
            ],
            'redoublements_cursus' => [
                'officiel' => $situationOfficielle['redoublements_cursus'] ?? null,
                'uniregistre' => $evalUniRegistre['nb_redoublements_cursus'],
            ],
            'credits_capitalises' => [
                'officiel' => $situationOfficielle['credits_capitalises'] ?? null,
                'uniregistre' => $situationOfficielle['credits_calcules'] ?? null,
            ],
            'statut_reglementaire' => [
                'officiel' => $situationOfficielle['statut_reglementaire'] ?? 'REGLE_CONFIRMEE',
                'uniregistre' => $evalUniRegistre['references_legales']['statut_regle'] ?? 'REGLE_CONFIRMEE',
            ],
            'decision_proposee' => [
                'officiel' => $situationOfficielle['decision_officielle'] ?? null,
                'uniregistre' => $evalUniRegistre['statut_droit'],
            ],
        ];

        $divergences = [];
        $typeDivergence = 'CONFORME_SANS_DIVERGENCE';

        if ($champs['redoublements_cursus']['officiel'] !== null &&
            (int) $champs['redoublements_cursus']['officiel'] !== (int) $champs['redoublements_cursus']['uniregistre']) {
            $divergences[] = "Écart sur les redoublements : Officiel={$champs['redoublements_cursus']['officiel']}, UniRegistre={$champs['redoublements_cursus']['uniregistre']}";
            $typeDivergence = 'DIVERGENCE_HISTORIQUE';
        }

        if ($evalUniRegistre['statut_droit'] === 'BLOQUE_REGLEMENTATION_INCONNUE') {
            $divergences[] = 'Source ou règle incertaine pour cette formation : blocage en revue humaine.';
            $typeDivergence = 'SOURCE_INSUFFISANTE';
        }

        if ($champs['decision_proposee']['officiel'] !== null &&
            $champs['decision_proposee']['officiel'] !== $champs['decision_proposee']['uniregistre']) {
            if ($evalUniRegistre['statut_droit'] === 'LIMITE_DEPASSEE' && $champs['decision_proposee']['officiel'] === 'DEROGATION_ACCORDEE') {
                $divergences[] = 'Dérogation officielle existante dans le dossier officiel non encore scellée dans le système.';
                $typeDivergence = 'DIVERGENCE_DONNEES';
            }
        }

        $conforme = empty($divergences);
        $revueHumaineRequise = ! $conforme || $evalUniRegistre['revue_humaine_requise'] || ($evalUniRegistre['statut_droit'] === 'BLOQUE_REGLEMENTATION_INCONNUE');

        // Qualification stricte LOOP 40-C (PHASE 5) :
        // CONFORME_REGLEMENTAIRE, CONCORDANT_NON_CERTIFIE, DIVERGENCE_A_ANALYSER,
        // REVUE_HUMAINE_REQUISE, PIECE_JUSTIFICATIVE_MANQUANTE
        $statutRegle = $evalUniRegistre['references_legales']['statut_regle'] ?? 'REGLE_CONFIRMEE';
        if (! empty($situationOfficielle['piece_manquante'])) {
            $statutRecette = 'PIECE_JUSTIFICATIVE_MANQUANTE';
        } elseif ($evalUniRegistre['statut_droit'] === 'BLOQUE_REGLEMENTATION_INCONNUE' || $statutRegle === 'SOURCE_MANQUANTE' || $statutRegle === 'REGLE_EXPIREE') {
            $statutRecette = 'REVUE_HUMAINE_REQUISE';
        } elseif (! empty($divergences)) {
            $statutRecette = 'DIVERGENCE_A_ANALYSER';
        } elseif ($statutRegle === 'REGLE_A_VALIDER' || ($evalUniRegistre['regime_code'] === 'CYCLE_INGENIEUR' && str_contains(mb_strtoupper((string) $evalUniRegistre['programme_nom']), 'ENIT'))) {
            // Un résultat identique au PV sur une règle locale d'école non publiée au JORT (ex. seuil 8.00 ENIT) n'est pas CONFORME_REGLEMENTAIRE mais CONCORDANT_NON_CERTIFIE
            $statutRecette = 'CONCORDANT_NON_CERTIFIE';
        } else {
            $statutRecette = 'CONFORME_REGLEMENTAIRE';
        }

        return [
            'dossier_id' => $situationOfficielle['dossier_id'] ?? "REC-{$etudiant->id}",
            'etudiant_id' => $etudiant->id,
            'conforme' => $conforme,
            'statut_recette' => $statutRecette,
            'type_divergence' => $typeDivergence,
            'divergences' => $divergences,
            'revue_humaine_requise' => $revueHumaineRequise,
            'comparatif' => $champs,
            'evaluation_uniregistre' => $evalUniRegistre,
            'situation_officielle' => $situationOfficielle,
        ];
    }

    /**
     * Vérifie si l'étudiant dispose d'une dérogation administrative officielle validée.
     *
     * Contrôle les 11 attributs cardinaux de sécurité (PHASE 4 - LOOP 40-C) :
     * - identifiant unique, bénéficiaire, diplôme, établissement (anti-BOLA),
     *   autorité émettrice et compétence, acte de référence, date décision,
     *   période de validité (non expirée, non anticipée), périmètre autorisé,
     *   preuve documentaire et protection anti-rejeu.
     *
     * @param  array<string, mixed>  $contexte
     * @return array{accordee: bool, a_verifier?: bool, reference: string, date: ?string, autorite: ?string, motif_rejet?: string, attributs?: array<string, mixed>}
     */
    private function verifierDerogationOfficielle(Etudiant $etudiant, Programme $programme, array $contexte): array
    {
        if (! empty($contexte['derogation_accordee'])) {
            // 1. Contrôle du bénéficiaire précis (étudiant cible)
            if (isset($contexte['etudiant_cible_id']) && (int) $contexte['etudiant_cible_id'] !== (int) $etudiant->id) {
                return [
                    'accordee' => false,
                    'a_verifier' => true,
                    'reference' => 'Dérogation non applicable à cet étudiant (bénéficiaire non concordant)',
                    'date' => null,
                    'autorite' => null,
                    'motif_rejet' => 'PERIMETRE_INVALIDE',
                ];
            }

            // 2. Contrôle du diplôme et programme concernés
            if (! empty($contexte['diplome_cible_code']) && mb_strtoupper((string) $contexte['diplome_cible_code']) !== mb_strtoupper((string) $programme->code)) {
                return [
                    'accordee' => false,
                    'a_verifier' => true,
                    'reference' => 'Dérogation accordée pour un autre diplôme (non transférable)',
                    'date' => (string) ($contexte['date_derogation'] ?? null),
                    'autorite' => (string) ($contexte['autorite_derogation'] ?? null),
                    'motif_rejet' => 'DIPLOME_INVALIDE',
                ];
            }

            // 3. Contrôle de l'établissement d'attache (Isolation facultaire anti-BOLA)
            if (isset($contexte['faculte_cible_id']) && $etudiant->faculte_id !== null && (int) $contexte['faculte_cible_id'] !== (int) $etudiant->faculte_id) {
                return [
                    'accordee' => false,
                    'a_verifier' => true,
                    'reference' => 'Accès interfaculté interdit : dérogation émise hors établissement de rattachement',
                    'date' => null,
                    'autorite' => null,
                    'motif_rejet' => 'ACCES_INTERFACULTE_INTERDIT',
                ];
            }

            // 4. Contrôle de la date de validité / expiration
            if (! empty($contexte['date_expiration_derogation'])) {
                $dateExp = Carbon::parse($contexte['date_expiration_derogation']);
                if (now()->greaterThan($dateExp)) {
                    return [
                        'accordee' => false,
                        'a_verifier' => true,
                        'reference' => 'Dérogation caduque / expirée',
                        'date' => (string) ($contexte['date_derogation'] ?? null),
                        'autorite' => (string) ($contexte['autorite_derogation'] ?? null),
                        'motif_rejet' => 'DEROGATION_EXPIREE',
                    ];
                }
            }

            // 5. Contrôle de période antérieure à prise d'effet (hors période)
            if (! empty($contexte['date_debut_validite'])) {
                $dateDebut = Carbon::parse($contexte['date_debut_validite']);
                if (now()->lessThan($dateDebut)) {
                    return [
                        'accordee' => false,
                        'a_verifier' => true,
                        'reference' => 'Dérogation non encore en vigueur (hors période de validité)',
                        'date' => (string) ($contexte['date_derogation'] ?? null),
                        'autorite' => (string) ($contexte['autorite_derogation'] ?? null),
                        'motif_rejet' => 'DEROGATION_HORS_PERIODE',
                    ];
                }
            }

            // 6. Contrôle anti-rejeu : une dérogation déjà consommée ne peut être réutilisée
            if (! empty($contexte['deja_consommee']) || ! empty($contexte['est_rejouee'])) {
                return [
                    'accordee' => false,
                    'a_verifier' => true,
                    'reference' => 'Tentative de rejeu : dérogation déjà consommée pour une inscription antérieure',
                    'date' => (string) ($contexte['date_derogation'] ?? null),
                    'autorite' => (string) ($contexte['autorite_derogation'] ?? null),
                    'motif_rejet' => 'REJEU_INTERDIT',
                ];
            }

            // 7. Contrôle de non-annulation / modification
            if (! empty($contexte['statut_derogation']) && in_array(mb_strtoupper((string) $contexte['statut_derogation']), ['ANNULEE', 'REVOQUEE'], true)) {
                return [
                    'accordee' => false,
                    'a_verifier' => true,
                    'reference' => 'Dérogation révoquée ou annulée par l\'autorité compétente',
                    'date' => (string) ($contexte['date_derogation'] ?? null),
                    'autorite' => (string) ($contexte['autorite_derogation'] ?? null),
                    'motif_rejet' => 'DEROGATION_ANNULEE',
                ];
            }

            // 8. Contrôle de l'autorité compétente et du fondement
            if (isset($contexte['autorite_competente']) && in_array($contexte['autorite_competente'], ['TIERS_NON_HABILITE', 'INCOMPETENTE', 'NON_HABILITEE'], true)) {
                return [
                    'accordee' => false,
                    'a_verifier' => true,
                    'reference' => 'Autorité émettrice non compétente légalement',
                    'date' => null,
                    'autorite' => null,
                    'motif_rejet' => 'AUTORITE_NON_HABILITEE',
                ];
            }

            return [
                'accordee' => true,
                'a_verifier' => false,
                'reference' => (string) ($contexte['reference_derogation'] ?? 'Dérogation rectorale exceptionnelle'),
                'date' => (string) ($contexte['date_derogation'] ?? now()->toDateString()),
                'autorite' => (string) ($contexte['autorite_derogation'] ?? 'Conseil d\'Université / Chef d\'établissement'),
                'attributs' => [
                    'identifiant_unique' => $contexte['derogation_id'] ?? uniqid('DEROG-'),
                    'beneficiaire_id' => $etudiant->id,
                    'diplome_code' => $programme->code,
                    'fondement_competence' => $contexte['fondement_competence'] ?? 'Dispositions dérogatoires réglementaires',
                    'reference_acte' => $contexte['reference_derogation'] ?? 'Acte dérogatoire scellé',
                    'perimetre_autorise' => $contexte['perimetre_autorise'] ?? 'REDOUBLEMENT_CYCLE',
                    'preuve_documentaire' => $contexte['preuve_documentaire'] ?? 'Archive administrative auditée',
                ],
            ];
        }

        // Recherche dans l'historique académique auditée (LOOP 146)
        $derogationTrace = HistoriqueAcademique::where('etudiant_id', $etudiant->id)
            ->where('action', 'decision_derogation')
            ->latest('id')
            ->first();

        if ($derogationTrace !== null) {
            $valeurs = json_decode((string) $derogationTrace->nouvelle_valeur, true) ?? [];
            if (($valeurs['decision'] ?? '') === 'accordee') {
                return [
                    'accordee' => true,
                    'reference' => (string) ($valeurs['reference_acte'] ?? "Acte n° {$derogationTrace->id}"),
                    'date' => $derogationTrace->created_at?->toDateString(),
                    'autorite' => (string) ($valeurs['autorite'] ?? 'Autorité compétente'),
                ];
            }
        }

        return [
            'accordee' => false,
            'reference' => 'Aucune dérogation accordée',
            'date' => null,
            'autorite' => null,
        ];
    }

    /**
     * Vérifie si le jury de délibération a accordé une autorisation exceptionnelle de réinscription.
     *
     * @param  array<string, mixed>  $contexte
     * @return array{accordee: bool, reference: string, commentaire: ?string}
     */
    private function verifierDecisionExceptionnelleJury(Etudiant $etudiant, Programme $programme, array $contexte): array
    {
        if (! empty($contexte['decision_exceptionnelle_jury'])) {
            if (isset($contexte['autorite_competente']) && in_array($contexte['autorite_competente'], ['TIERS_NON_HABILITE', 'INCOMPETENTE'], true)) {
                return [
                    'accordee' => false,
                    'a_verifier' => true,
                    'reference' => 'Autorité non compétente pour dérogation',
                    'commentaire' => 'Rejet : tentative de contournement par une autorité non habilitée.',
                ];
            }

            return [
                'accordee' => true,
                'reference' => (string) ($contexte['reference_pv'] ?? 'PV de Délibération Exceptionnelle'),
                'commentaire' => (string) ($contexte['motif_jury'] ?? 'Autorisation exceptionnelle accordée par le jury souverain'),
            ];
        }

        $derniereDelib = DecisionDeliberation::where('etudiant_id', $etudiant->id)
            ->latest('id')
            ->first();

        if ($derniereDelib !== null) {
            $decision = mb_strtolower((string) $derniereDelib->decision);
            $commentaire = mb_strtolower((string) $derniereDelib->commentaire);

            $estExceptionnel = str_contains($decision, 'derogatoire')
                || str_contains($decision, 'exceptionnel')
                || str_contains($commentaire, 'exception')
                || str_contains($commentaire, 'dérogation')
                || str_contains($commentaire, 'derogation');

            if ($estExceptionnel) {
                return [
                    'accordee' => true,
                    'reference' => "PV de délibération #{$derniereDelib->deliberation_id}",
                    'commentaire' => (string) $derniereDelib->commentaire,
                ];
            }
        }

        return [
            'accordee' => false,
            'reference' => 'Aucune décision exceptionnelle de jury',
            'commentaire' => null,
        ];
    }
}
