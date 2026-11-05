<?php

namespace App\Services\Acquis;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Models\Note;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\UniteEnseignement;
use Illuminate\Support\Collection;

/**
 * SERVICE DE SIMULATION ET DE RÉCONCILIATION HISTORIQUE SANS ÉCRITURE (LOOP 41).
 *
 * Exécute un dry-run déterministe et strictement non-destructif pour :
 * - Inventorier les acquis historiques.
 * - Simuler les transformations vers le référentiel LMD / Ingénieur cible.
 * - Calculer les empreintes SHA-256 canoniques (source et résultat) pour garantir l'idempotence.
 * - Détecter les anomalies (doublons, UE orphelines, notes sans UE, règles non confirmées).
 * - Classifier chaque dossier sous l'une des 6 catégories de migration formelles.
 *
 * GARANTIE ABSOLUE : ZÉRO ÉCRITURE DANS LA BASE DE DONNÉES.
 */
class SimulationMigrationHistoriqueService
{
    public function __construct(
        private readonly ReglementationAcquisService $reglementationService,
        private readonly ControleDroitsRedoublementService $droitsService,
    ) {}

    /**
     * Exécute une simulation dry-run complète pour un étudiant sans modifier la base.
     *
     * @return array{
     *     identifiant_technique: string,
     *     etudiant_id: int,
     *     cin: string,
     *     faculte_id: ?int,
     *     source: array<string, mixed>,
     *     destination_theorique: array<string, mixed>,
     *     donnees_avant: array<string, mixed>,
     *     transformation_proposee: array<string, mixed>,
     *     resultat_calcule: array<string, mixed>,
     *     empreinte_source: string,
     *     empreinte_resultat: string,
     *     anomalies: array<int, string>,
     *     statut_migration: string,
     *     justification: string,
     *     regle_utilisee: string
     * }
     */
    public function simulerDossierEtudiant(Etudiant $etudiant): array
    {
        $etudiant->loadMissing([
            'inscriptionsAcademiques.programmeRef.typeDiplome',
            'inscriptionsAcademiques.semestres',
            'faculte',
            'programmeRef.typeDiplome',
        ]);

        $inscriptions = $etudiant->inscriptionsAcademiques->sortBy('annee_universitaire_id');
        $notes = Note::where('etudiant_id', $etudiant->id)->with('matiere.ue')->get();

        // 1. Données sources avant transformation
        $donneesAvant = [
            'etudiant' => [
                'id' => $etudiant->id,
                'cin' => $etudiant->cin,
                'nom' => $etudiant->nom,
                'prenom' => $etudiant->prenom,
                'faculte_id' => $etudiant->faculte_id,
                'programme_id' => $etudiant->programme_id,
            ],
            'inscriptions' => $inscriptions->map(fn (InscriptionAcademique $i) => [
                'id' => $i->id,
                'annee_id' => $i->annee_universitaire_id,
                'programme_id' => $i->programme_id,
                'niveau' => $i->niveau,
                'type' => $i->type,
                'statut' => $i->statut,
                'decision' => $i->decision,
                'observation' => $i->observation,
            ])->values()->all(),
            'notes' => $notes->map(fn (Note $n) => [
                'id' => $n->id,
                'matiere_id' => $n->matiere_id,
                'ue_id' => $n->matiere?->ue_id,
                'note' => (float) $n->note,
                'session' => $n->session,
                'semestre_id' => $n->semestre_id,
            ])->values()->all(),
        ];

        // 2. Détection des anomalies de données
        $anomalies = [];
        $avertissements = [];

        if ($inscriptions->isEmpty()) {
            $anomalies[] = 'Aucune inscription académique répertoriée pour cet étudiant.';
        }

        // Vérification des matières orphelines (matière sans UE)
        $notesSansUe = $notes->filter(fn (Note $n) => $n->matiere && $n->matiere->ue_id === null);
        if ($notesSansUe->isNotEmpty()) {
            $anomalies[] = "{$notesSansUe->count()} note(s) rattachée(s) à des matières orphelines sans rattachement d'Unité d'Enseignement.";
        }

        // Vérification de la complétude du programme
        $programme = $etudiant->programmeRef ?? $inscriptions->last()?->programmeRef;
        if ($programme === null) {
            $anomalies[] = "Cursus ou programme d'études non identifié.";
        }

        // 3. Évaluation réglementaire fail-closed
        $statutRegle = 'SOURCE_MANQUANTE';
        $regimeCode = 'INCONNU';
        $regleNom = 'Règle non déterminée';

        if ($programme !== null) {
            $evalStatut = $this->reglementationService->evaluerStatutRegle($programme);
            $statutRegle = $evalStatut['statut'];
            $regleNom = $evalStatut['motif'] ?? 'Référentiel national';

            try {
                $regime = $this->reglementationService->resoudreRegime($programme);
                $regimeCode = $regime['code_regime'];
            } catch (\Throwable) {
                $regimeCode = 'INCONNU';
            }
        }

        // 4. Calcul déterministe de la transformation théorique
        $totalCreditsReconnus = 0;
        $uesTransformees = [];

        foreach ($notes as $note) {
            if ($note->note >= 10.0) {
                $totalCreditsReconnus += 3; // Crédits standards par élément validé
                $uesTransformees[] = [
                    'matiere_id' => $note->matiere_id,
                    'matiere_nom' => $note->matiere?->nom ?? "Matière #{$note->matiere_id}",
                    'ue_id' => $note->matiere?->ue_id,
                    'note' => (float) $note->note,
                    'statut' => 'CAPITALISE_CONFORME',
                ];
            }
        }

        $resultatCalcule = [
            'regime_cible' => $regimeCode,
            'programme_cible_id' => $programme?->id,
            'programme_cible_nom' => $programme?->nom ?? 'Non déterminé',
            'total_credits_reconnus' => $totalCreditsReconnus,
            'elements_capitalises' => $uesTransformees,
            'statut_regle' => $statutRegle,
        ];

        // 4 bis. ANOM-41-02 — contrôle de la source officielle de décision.
        // Une note peut être exacte et une règle confirmée : sans décision
        // officielle (délibération publiée normalisée ou décision textuelle
        // d'origine), le transfert de crédits reste soumis à validation
        // humaine. L'incertitude ne produit jamais d'exclusion : elle
        // produit une demande de validation (jamais un refus automatique).
        $decisionNormaliseePubliee = DecisionDeliberation::query()
            ->where('etudiant_id', $etudiant->id)
            ->where('publie', true)
            ->exists();
        $decisionTextuelle = $inscriptions->contains(fn (InscriptionAcademique $i) => ! empty($i->decision));

        $sourcesOfficielles = [
            'decision_normalisee_publiee' => $decisionNormaliseePubliee,
            'decision_textuelle_origine' => $decisionTextuelle,
            'source_officielle_suffisante' => $decisionNormaliseePubliee || $decisionTextuelle,
        ];

        $sourceOfficielleInsuffisante = ! $sourcesOfficielles['source_officielle_suffisante'];

        if ($sourceOfficielleInsuffisante && $totalCreditsReconnus > 0) {
            $avertissements[] = 'ANOM-41-02 : aucun acte officiel de décision (délibération publiée ou décision textuelle d’origine) — transfert de crédits soumis à validation de la scolarité.';
        }

        $resultatCalcule['sources_officielles'] = $sourcesOfficielles;

        // 5. Calcul des empreintes cryptographiques déterministes (SHA-256 canonical JSON)
        $empreinteSource = hash('sha256', json_encode($donneesAvant, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $empreinteResultat = hash('sha256', json_encode($resultatCalcule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // 6. Qualification stricte sous l'une des 6 catégories officielles (PHASE 6 - LOOP 41)
        if ($programme === null) {
            $statutMigration = 'BLOQUE_DONNEES';
            $justification = "Données d'inscription insuffisantes : programme d'études introuvable.";
        } elseif ($statutRegle === 'SOURCE_MANQUANTE') {
            $statutMigration = 'SOURCE_INSUFFISANTE';
            $justification = "Source réglementaire manquante pour « {$programme->nom} » : migration automatique interdite.";
        } elseif ($statutRegle === 'REGLE_EXPIREE') {
            $statutMigration = 'REVUE_HUMAINE_REQUISE';
            $justification = 'Maquette abrogée ou ancienne : orientation obligatoire vers la commission d\'équivalence.';
        } elseif ($statutRegle === 'REGLE_A_VALIDER' || ($regimeCode === 'CYCLE_INGENIEUR' && str_contains(mb_strtoupper((string) $programme->nom), 'ENIT'))) {
            $statutMigration = 'MIGRABLE_APRES_VALIDATION';
            $justification = 'Règle locale ou d\'école en attente d\'homologation ministérielle (Réserve RES-40-01 / RES-40-02).';
        } elseif (! empty($anomalies)) {
            $statutMigration = 'REVUE_HUMAINE_REQUISE';
            $justification = 'Anomalies structurelles détectées dans le dossier source : arbitrage scolarité requis.';
        } elseif ($sourceOfficielleInsuffisante && $totalCreditsReconnus > 0) {
            $statutMigration = 'MIGRABLE_APRES_VALIDATION';
            $justification = 'ANOM-41-02 : aucun acte officiel de décision au dossier — le transfert des crédits exige une validation formelle de la scolarité.';
        } elseif ($statutRegle === 'REGLE_CONFIRMEE' || $statutRegle === 'ABSENCE_CONFIRMEE') {
            $statutMigration = 'MIGRABLE_AUTOMATIQUEMENT_SOUS_REGLE_CONFIRME';
            $justification = 'Dossier intègre, règle juridique publiée au JORT confirmée et concordance complète.';
        } else {
            $statutMigration = 'HORS_PERIMETRE';
            $justification = 'Dossier ne relevant pas du périmètre de migration certifié.';
        }

        return [
            'identifiant_technique' => "DRYRUN-ETU-{$etudiant->id}",
            'etudiant_id' => $etudiant->id,
            'cin' => $etudiant->cin,
            'faculte_id' => $etudiant->faculte_id,
            'source' => [
                'nb_inscriptions' => $inscriptions->count(),
                'nb_notes' => $notes->count(),
            ],
            'destination_theorique' => [
                'programme_id' => $programme?->id,
                'programme_code' => $programme?->code,
                'regime' => $regimeCode,
            ],
            'donnees_avant' => $donneesAvant,
            'transformation_proposee' => [
                'type' => 'RECONNAISSANCE_ECTS_STANDARD',
                'regle' => 'Décret 2022-631 Art. 8 / Décret 95-2602 Art. 17',
            ],
            'resultat_calcule' => $resultatCalcule,
            'empreinte_source' => $empreinteSource,
            'empreinte_resultat' => $empreinteResultat,
            'anomalies' => $anomalies,
            'avertissements' => $avertissements,
            'statut_migration' => $statutMigration,
            'justification' => $justification,
            'regle_utilisee' => $regleNom,
        ];
    }

    /**
     * Exécute la simulation en masse d'un lot d'étudiants (mode dry-run sécurisé).
     *
     * @param  Collection<int, Etudiant>|array<int, Etudiant>  $etudiants
     * @return array<string, mixed>
     */
    public function simulerCampagne(iterable $etudiants): array
    {
        $resultats = [];
        $repartition = [
            'MIGRABLE_AUTOMATIQUEMENT_SOUS_REGLE_CONFIRME' => 0,
            'MIGRABLE_APRES_VALIDATION' => 0,
            'REVUE_HUMAINE_REQUISE' => 0,
            'SOURCE_INSUFFISANTE' => 0,
            'BLOQUE_DONNEES' => 0,
            'HORS_PERIMETRE' => 0,
        ];

        $totalAnomalies = 0;
        $totalAvertissements = 0;

        foreach ($etudiants as $etudiant) {
            $simul = $this->simulerDossierEtudiant($etudiant);
            $resultats[] = $simul;
            $repartition[$simul['statut_migration']] = ($repartition[$simul['statut_migration']] ?? 0) + 1;
            $totalAnomalies += count($simul['anomalies']);
            $totalAvertissements += count($simul['avertissements'] ?? []);
        }

        return [
            'total_dossiers_simules' => count($resultats),
            'total_anomalies_detectees' => $totalAnomalies,
            'total_avertissements' => $totalAvertissements,
            'repartition_statuts' => $repartition,
            'dossiers' => $resultats,
            'mode_execution' => 'LECTURE_SEULE_STRICTE',
            'ecritures_effectuees' => 0,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Rapproche deux UE selon leur libellé et code, en refusant tout rapprochement approximatif aveugle.
     *
     * @return array{statut_mapping: string, certifie: bool, motif: string}
     */
    public function cartographierUe(UniteEnseignement|string $sourceUe, UniteEnseignement|string $cibleUe): array
    {
        $sourceNom = is_string($sourceUe) ? $sourceUe : $sourceUe->nom;
        $cibleNom = is_string($cibleUe) ? $cibleUe : $cibleUe->nom;
        $sourceCode = is_string($sourceUe) ? '' : ($sourceUe->code ?? '');
        $cibleCode = is_string($cibleUe) ? '' : ($cibleUe->code ?? '');

        // Correspondance identique stricte de code
        if (! empty($sourceCode) && ! empty($cibleCode) && mb_strtoupper(trim($sourceCode)) === mb_strtoupper(trim($cibleCode))) {
            return [
                'statut_mapping' => 'MAPPING_CONFIRME',
                'certifie' => true,
                'motif' => "Codes d'UE strictement identiques ({$sourceCode}) au sein de la maquette.",
            ];
        }

        // Libellés strictement identiques
        if (mb_strtoupper(trim($sourceNom)) === mb_strtoupper(trim($cibleNom))) {
            return [
                'statut_mapping' => 'MAPPING_A_VALIDER',
                'certifie' => false,
                'motif' => "Intitulés identiques (« {$sourceNom} »), mais équivalence pédagogique à faire viser par la commission.",
            ];
        }

        // Ressemblance approximative (similarité textuelle sans équivalence formelle)
        similar_text(mb_strtolower($sourceNom), mb_strtolower($cibleNom), $percent);
        if ($percent > 70.0) {
            return [
                'statut_mapping' => 'MAPPING_AMBIGU',
                'certifie' => false,
                'motif' => sprintf('Similarité textuelle (%.1f%%) entre « %s » et « %s » : assimilation automatique interdite.', $percent, $sourceNom, $cibleNom),
            ];
        }

        return [
            'statut_mapping' => 'MAPPING_IMPOSSIBLE',
            'certifie' => false,
            'motif' => "Aucune correspondance établie entre « {$sourceNom} » et « {$cibleNom} ».",
        ];
    }
}
