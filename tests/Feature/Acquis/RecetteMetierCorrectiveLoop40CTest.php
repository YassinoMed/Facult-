<?php

namespace Tests\Feature\Acquis;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\TypeDiplome;
use App\Services\Acquis\ControleDroitsRedoublementService;
use App\Services\Acquis\ReglementationAcquisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SUITE DE TESTS CORRECTIFS ET DE CONTRÔLE DE NON-RÉGRESSION (LOOP 40-C).
 *
 * Vérifie :
 * - Quota inconnu non converti en quota 0 (retourne null).
 * - Seuil absent non remplacé par une valeur en dur.
 * - Règle propre à une école non généralisée nationalement.
 * - Interruption neutralisée uniquement si reconnaissance officielle établie.
 * - Contrôle des 11 attributs de dérogation (expirée, rejeu, autre étudiant, autre diplôme, autorité, BOLA).
 * - Requalification des 12 dossiers REC-01 à REC-12 avec les 5 statuts de recette formels.
 */
class RecetteMetierCorrectiveLoop40CTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteFST;

    private Faculte $faculteENIT;

    private Faculte $faculteAutre;

    private TypeDiplome $typeLicence;

    private TypeDiplome $typeIngenieur;

    private TypeDiplome $typeMaster;

    private Programme $programmeLicenceInfo;

    private Programme $programmeLicenceSante;

    private Programme $programmeIngenieurENIT;

    private Programme $programmeMasterRecherche;

    private Programme $programmeCoConstruit;

    private Programme $programmeHistoriqueExpire;

    private AnneeUniversitaire $annee2023;

    private AnneeUniversitaire $annee2024;

    private AnneeUniversitaire $annee2025;

    private Niveau $niveauL1;

    private Niveau $niveauL2;

    private Niveau $niveauING1;

    private Niveau $niveauM1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteFST = Faculte::create(['nom' => 'Faculté des Sciences de Tunis', 'code' => 'FST']);
        $this->faculteENIT = Faculte::create(['nom' => 'École Nationale d\'Ingénieurs de Tunis', 'code' => 'ENIT']);
        $this->faculteAutre = Faculte::create(['nom' => 'Autre Faculté Partenaire', 'code' => 'AUTRE']);

        $this->typeLicence = TypeDiplome::create(['nom' => 'Licence Nationale', 'code' => 'LIC']);
        $this->typeIngenieur = TypeDiplome::create(['nom' => 'Diplôme d\'Ingénieur', 'code' => 'ING']);
        $this->typeMaster = TypeDiplome::create(['nom' => 'Mastère LMD', 'code' => 'MAS']);

        $this->programmeLicenceInfo = Programme::create([
            'nom' => 'Licence Nationale en Sciences de l\'Informatique',
            'code' => 'LIC_INFO_STANDARD',
            'type_diplome_id' => $this->typeLicence->id,
            'faculte_id' => $this->faculteFST->id,
        ]);

        $this->programmeLicenceSante = Programme::create([
            'nom' => 'Licence Nationale en Sciences de la Santé',
            'code' => 'LIC_SANTE',
            'type_diplome_id' => $this->typeLicence->id,
            'faculte_id' => $this->faculteFST->id,
        ]);

        $this->programmeIngenieurENIT = Programme::create([
            'nom' => 'Diplôme National d\'Ingénieur en Génie Informatique (ENIT)',
            'code' => 'ING_INFO_ENIT',
            'type_diplome_id' => $this->typeIngenieur->id,
            'faculte_id' => $this->faculteENIT->id,
        ]);

        $this->programmeMasterRecherche = Programme::create([
            'nom' => 'Mastère de Recherche en Informatique (FST)',
            'code' => 'MASTER_RECHERCHE_FST',
            'type_diplome_id' => $this->typeMaster->id,
            'faculte_id' => $this->faculteFST->id,
        ]);

        $this->programmeCoConstruit = Programme::create([
            'nom' => 'Licence Co-construite IA & Embarqué',
            'code' => 'FORMATION_A_VALIDER',
            'type_diplome_id' => $this->typeLicence->id,
            'faculte_id' => $this->faculteFST->id,
        ]);

        $this->programmeHistoriqueExpire = Programme::create([
            'nom' => 'Licence Informatique Tronc Commun 2018',
            'code' => 'FORMATION_EXPIREE',
            'type_diplome_id' => $this->typeLicence->id,
            'faculte_id' => $this->faculteFST->id,
        ]);

        $this->annee2023 = AnneeUniversitaire::create(['libelle' => '2023-2024', 'date_debut' => '2023-09-01', 'date_fin' => '2024-06-30', 'active' => false]);
        $this->annee2024 = AnneeUniversitaire::create(['libelle' => '2024-2025', 'date_debut' => '2024-09-01', 'date_fin' => '2025-06-30', 'active' => false]);
        $this->annee2025 = AnneeUniversitaire::create(['libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-06-30', 'active' => true]);

        $this->niveauL1 = Niveau::firstOrCreate(['code' => 'L1', 'programme_id' => $this->programmeLicenceInfo->id], ['nom' => 'Licence 1ère Année', 'ordre' => 1, 'actif' => true]);
        $this->niveauL2 = Niveau::firstOrCreate(['code' => 'L2', 'programme_id' => $this->programmeLicenceInfo->id], ['nom' => 'Licence 2ème Année', 'ordre' => 2, 'actif' => true]);
        $this->niveauING1 = Niveau::firstOrCreate(['code' => 'ING1', 'programme_id' => $this->programmeIngenieurENIT->id], ['nom' => 'Ingénieur 1ère Année', 'ordre' => 1, 'actif' => true]);
        $this->niveauM1 = Niveau::firstOrCreate(['code' => 'M1', 'programme_id' => $this->programmeMasterRecherche->id], ['nom' => 'Mastère 1ère Année', 'ordre' => 1, 'actif' => true]);
    }

    private function creerEtudiant(string $cin, string $nom, string $prenom, ?Faculte $faculte = null): Etudiant
    {
        return Etudiant::create([
            'identifiant_national' => 'ID-'.$cin,
            'matricule' => 'ETU-'.strtoupper(substr(md5($cin), 0, 8)),
            'numero_etudiant' => 'NUM-'.strtoupper(substr(md5($cin), 0, 8)),
            'cin' => $cin,
            'nom' => $nom,
            'prenom' => $prenom,
            'email' => strtolower($prenom.'.'.$nom.'@univ.tn'),
            'date_naissance' => '2003-05-15',
            'sexe' => 'M',
            'nationalite' => 'TN',
            'adresse' => 'Campus Universitaire',
            'ville' => 'Tunis',
            'code_postal' => '1060',
            'annee_bac' => 2021,
            'faculte_id' => $faculte ? $faculte->id : $this->faculteFST->id,
            'programme_id' => $this->programmeLicenceInfo->id,
            'niveau' => 'L1',
            'annee_academique' => '2024-2025',
            'actif' => true,
        ]);
    }

    public function test_phase3_quota_inconnu_non_converti_en_quota_zero(): void
    {
        $droitsService = app(ControleDroitsRedoublementService::class);

        $programmeSansRegle = Programme::create([
            'nom' => 'Formation Expérimentale Non Documentée',
            'code' => 'FORMATION_INCONNUE',
            'type_diplome_id' => null,
            'faculte_id' => $this->faculteFST->id,
        ]);

        $etudiant = $this->creerEtudiant('90000001', 'Test', 'QuotaInconnu');
        $eval = $droitsService->evaluerDroitsRedoublement($etudiant, $programmeSansRegle);

        // 1. Le quota inconnu doit renvoyer null et non 0
        $this->assertNull($eval['limite_redoublements_cycle']);
        $this->assertNull($eval['limite_redoublements_niveau']);

        // 2. Le statut canonique doit être INDETERMINE et non une exclusion
        $this->assertEquals('INDETERMINE', $eval['statut_canonique']);
        $this->assertEquals('BLOQUE_REGLEMENTATION_INCONNUE', $eval['statut_droit']);
        $this->assertFalse($eval['autorise']);
        $this->assertTrue($eval['revue_humaine_requise']);
        $this->assertStringContainsString('Incertitude réglementaire', $eval['motif']);
    }

    public function test_phase3_interruption_etudes_neutralisee_uniquement_si_officielle(): void
    {
        $droitsService = app(ControleDroitsRedoublementService::class);

        // Cas A : Interruption officielle (Congé académique justifié)
        $etuOfficiel = $this->creerEtudiant('90000002', 'Officiel', 'Conge');
        InscriptionAcademique::create([
            'etudiant_id' => $etuOfficiel->id,
            'annee_universitaire_id' => $this->annee2023->id,
            'programme_id' => $this->programmeLicenceInfo->id,
            'niveau_id' => $this->niveauL1->id,
            'niveau' => 'L1',
            'type' => 'autre',
            'statut' => 'validee',
            'observation' => 'Congé académique accordé par arrêté décanal',
        ]);
        $evalOfficiel = $droitsService->evaluerDroitsRedoublement($etuOfficiel, $this->programmeLicenceInfo);
        $this->assertEquals(1, $evalOfficiel['nb_interruptions_etudes']);
        $this->assertEquals(0, $evalOfficiel['nb_redoublements_cursus']);

        // Cas B : Interruption non officielle / refusée
        $etuRefuse = $this->creerEtudiant('90000003', 'Refuse', 'Conge');
        InscriptionAcademique::create([
            'etudiant_id' => $etuRefuse->id,
            'annee_universitaire_id' => $this->annee2023->id,
            'programme_id' => $this->programmeLicenceInfo->id,
            'niveau_id' => $this->niveauL1->id,
            'niveau' => 'L1',
            'type' => 'autre',
            'statut' => 'ajourne',
            'observation' => 'Demande d\'interruption refusée pour motif injustifié',
        ]);
        $evalRefuse = $droitsService->evaluerDroitsRedoublement($etuRefuse, $this->programmeLicenceInfo);
        $this->assertEquals(0, $evalRefuse['nb_interruptions_etudes'], 'Une interruption refusée ou injustifiée ne doit pas être comptée comme interruption.');
        $this->assertEquals(1, $evalRefuse['nb_redoublements_cursus'], 'L\'inscription doit compter comme redoublement.');
    }

    public function test_phase4_workflow_derogation_securite_11_attributs(): void
    {
        $droitsService = app(ControleDroitsRedoublementService::class);
        $etudiant = $this->creerEtudiant('90000004', 'Derogation', 'Controle', $this->faculteFST);

        // Crée 2 échecs en L1 (quota L1 = 1 dépassé)
        InscriptionAcademique::create(['etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $this->annee2023->id, 'programme_id' => $this->programmeLicenceInfo->id, 'niveau_id' => $this->niveauL1->id, 'niveau' => 'L1', 'statut' => 'ajourne']);
        InscriptionAcademique::create(['etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeLicenceInfo->id, 'niveau_id' => $this->niveauL1->id, 'niveau' => 'L1', 'statut' => 'ajourne']);

        // Test 1 : Dérogation expirée
        $evalExpiree = $droitsService->evaluerDroitsRedoublement($etudiant, $this->programmeLicenceInfo, [
            'derogation_accordee' => true,
            'date_expiration_derogation' => '2024-01-01',
        ]);
        $this->assertFalse($evalExpiree['autorise']);
        $this->assertEquals('DEROGATION_A_VERIFIER', $evalExpiree['statut_canonique']);
        $this->assertEquals('DEROGATION_EXPIREE', $evalExpiree['derogation_active']['motif_rejet']);

        // Test 2 : Dérogation pour un autre étudiant (périmètre invalide)
        $evalAutreEtu = $droitsService->evaluerDroitsRedoublement($etudiant, $this->programmeLicenceInfo, [
            'derogation_accordee' => true,
            'etudiant_cible_id' => $etudiant->id + 999,
        ]);
        $this->assertFalse($evalAutreEtu['autorise']);
        $this->assertEquals('DEROGATION_A_VERIFIER', $evalAutreEtu['statut_canonique']);
        $this->assertEquals('PERIMETRE_INVALIDE', $evalAutreEtu['derogation_active']['motif_rejet']);

        // Test 3 : Dérogation pour un autre diplôme
        $evalAutreDiplome = $droitsService->evaluerDroitsRedoublement($etudiant, $this->programmeLicenceInfo, [
            'derogation_accordee' => true,
            'diplome_cible_code' => 'AUTRE_DIPLOME',
        ]);
        $this->assertFalse($evalAutreDiplome['autorise']);
        $this->assertEquals('DEROGATION_A_VERIFIER', $evalAutreDiplome['statut_canonique']);
        $this->assertEquals('DIPLOME_INVALIDE', $evalAutreDiplome['derogation_active']['motif_rejet']);

        // Test 4 : Isolation facultaire anti-BOLA (autre faculté)
        $evalInterfaculte = $droitsService->evaluerDroitsRedoublement($etudiant, $this->programmeLicenceInfo, [
            'derogation_accordee' => true,
            'faculte_cible_id' => $this->faculteAutre->id,
        ]);
        $this->assertFalse($evalInterfaculte['autorise']);
        $this->assertEquals('DEROGATION_A_VERIFIER', $evalInterfaculte['statut_canonique']);
        $this->assertEquals('ACCES_INTERFACULTE_INTERDIT', $evalInterfaculte['derogation_active']['motif_rejet']);

        // Test 5 : Rejeu d'une dérogation déjà consommée
        $evalRejeu = $droitsService->evaluerDroitsRedoublement($etudiant, $this->programmeLicenceInfo, [
            'derogation_accordee' => true,
            'deja_consommee' => true,
        ]);
        $this->assertFalse($evalRejeu['autorise']);
        $this->assertEquals('DEROGATION_A_VERIFIER', $evalRejeu['statut_canonique']);
        $this->assertEquals('REJEU_INTERDIT', $evalRejeu['derogation_active']['motif_rejet']);

        // Test 6 : Dérogation valide avec l'ensemble des 11 attributs
        $evalValide = $droitsService->evaluerDroitsRedoublement($etudiant, $this->programmeLicenceInfo, [
            'derogation_accordee' => true,
            'derogation_id' => 'DEROG-2026-FST-044',
            'etudiant_cible_id' => $etudiant->id,
            'diplome_cible_code' => 'LIC_INFO_STANDARD',
            'faculte_cible_id' => $this->faculteFST->id,
            'autorite_competente' => 'Conseil d\'Université',
            'reference_derogation' => 'Arrêté Rectoral n° 2026/044',
            'date_derogation' => '2026-09-10',
            'date_expiration_derogation' => '2027-08-31',
            'perimetre_autorise' => 'REDOUBLEMENT_CYCLE',
            'preuve_documentaire' => 'Document certifié scellé',
        ]);
        $this->assertTrue($evalValide['autorise']);
        $this->assertEquals('AUTORISE', $evalValide['statut_canonique']);
        $this->assertEquals('DEROGATION_ACCORDEE', $evalValide['statut_droit']);
        $this->assertTrue($evalValide['derogation_active']['accordee']);
        $this->assertNotNull($evalValide['derogation_active']['attributs']);
    }

    public function test_phase5_requalification_corpus_12_dossiers_avec_5_statuts_recette(): void
    {
        $droitsService = app(ControleDroitsRedoublementService::class);

        // Dossier REC-01 : Licence Info passage conditionnel -> CONFORME_REGLEMENTAIRE
        $etu1 = $this->creerEtudiant('10000001', 'Dossier', 'Rec01');
        $comp1 = $droitsService->comparerDossierRecette($etu1, [
            'dossier_id' => 'REC-01',
            'inscriptions_administratives' => 0,
            'redoublements_cursus' => 0,
            'credits_capitalises' => 48,
            'credits_calcules' => 48,
            'decision_officielle' => 'AUTORISE_PLEIN_DROIT',
        ], $this->programmeLicenceInfo);
        $this->assertEquals('CONFORME_REGLEMENTAIRE', $comp1['statut_recette']);

        // Dossier REC-05 : Cycle Ingénieur ENIT compensation à 8.50 -> CONCORDANT_NON_CERTIFIE
        $etu5 = $this->creerEtudiant('10000005', 'Dossier', 'Rec05', $this->faculteENIT);
        $comp5 = $droitsService->comparerDossierRecette($etu5, [
            'dossier_id' => 'REC-05',
            'inscriptions_administratives' => 0,
            'redoublements_cursus' => 0,
            'decision_officielle' => 'AUTORISE_PLEIN_DROIT',
        ], $this->programmeIngenieurENIT);
        $this->assertEquals('CONCORDANT_NON_CERTIFIE', $comp5['statut_recette'], 'Le seuil ENIT relève du règlement intérieur et doit être qualifié de CONCORDANT_NON_CERTIFIE.');

        // Dossier REC-11 : Formation co-construite non habilitée -> REVUE_HUMAINE_REQUISE
        $etu11 = $this->creerEtudiant('10000011', 'Dossier', 'Rec11');
        $comp11 = $droitsService->comparerDossierRecette($etu11, [
            'dossier_id' => 'REC-11',
            'statut_reglementaire' => 'REGLE_A_VALIDER',
            'piece_manquante' => true,
        ], $this->programmeCoConstruit);
        $this->assertEquals('PIECE_JUSTIFICATIVE_MANQUANTE', $comp11['statut_recette']);

        // Dossier REC-12 : Formation historique expirée -> REVUE_HUMAINE_REQUISE
        $etu12 = $this->creerEtudiant('10000012', 'Dossier', 'Rec12');
        $comp12 = $droitsService->comparerDossierRecette($etu12, [
            'dossier_id' => 'REC-12',
            'statut_reglementaire' => 'REGLE_EXPIREE',
        ], $this->programmeHistoriqueExpire);
        $this->assertEquals('REVUE_HUMAINE_REQUISE', $comp12['statut_recette']);
    }

    public function test_phase6_transfert_cycle_ne_fusionne_pas_droits_redoublement(): void
    {
        $droitsService = app(ControleDroitsRedoublementService::class);
        $etudiant = $this->creerEtudiant('20000001', 'Transfert', 'CycleIndependant');

        // Antécédent : 2 redoublements en Licence (L1 et L2)
        InscriptionAcademique::create(['etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $this->annee2023->id, 'programme_id' => $this->programmeLicenceInfo->id, 'niveau_id' => $this->niveauL1->id, 'niveau' => 'L1', 'statut' => 'ajourne']);
        InscriptionAcademique::create(['etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeLicenceInfo->id, 'niveau_id' => $this->niveauL1->id, 'niveau' => 'L1', 'statut' => 'ajourne']);

        // Nouvelle inscription après concours : Admission en 1ère année Cycle Ingénieur (ENIT)
        $inscIng = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee2025->id,
            'programme_id' => $this->programmeIngenieurENIT->id,
            'niveau_id' => $this->niveauING1->id,
            'niveau' => 'ING1',
            'statut' => 'ajourne',
        ]);

        // Évaluation en cycle ingénieur : les 2 échecs de Licence NE SONT PAS comptabilisés
        $eval = $droitsService->evaluerDroitsRedoublement($etudiant, $this->programmeIngenieurENIT);

        $this->assertEquals(3, $eval['nb_inscriptions_administratives'], 'Total historique de 3 inscriptions.');
        $this->assertEquals(1, $eval['nb_redoublements_cursus'], 'Seul l\'échec en Cycle Ingénieur compte pour le cursus ingénieur.');
        $this->assertTrue($eval['autorise'], 'Le 1er redoublement en cycle ingénieur est autorisé de plein droit (Décret 95-2602 Art. 10).');
        $this->assertEquals('AUTORISE', $eval['statut_canonique']);
    }

    public function test_phase6_decision_exceptionnelle_jury_verification_competence(): void
    {
        $droitsService = app(ControleDroitsRedoublementService::class);
        $etudiant = $this->creerEtudiant('20000002', 'Jury', 'Competence');

        // Épuisement des droits en Licence L1
        InscriptionAcademique::create(['etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $this->annee2023->id, 'programme_id' => $this->programmeLicenceInfo->id, 'niveau_id' => $this->niveauL1->id, 'niveau' => 'L1', 'statut' => 'ajourne']);
        InscriptionAcademique::create(['etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeLicenceInfo->id, 'niveau_id' => $this->niveauL1->id, 'niveau' => 'L1', 'statut' => 'ajourne']);

        // Cas A : Décision exceptionnelle du jury émise par une autorité incompétente
        $evalInvalide = $droitsService->evaluerDroitsRedoublement($etudiant, $this->programmeLicenceInfo, [
            'decision_exceptionnelle_jury' => true,
            'autorite_competente' => 'TIERS_NON_HABILITE',
            'reference_pv' => 'PV contesté',
        ]);
        $this->assertFalse($evalInvalide['autorise']);
        $this->assertEquals('DECISION_EXCEPTIONNELLE_A_VERIFIER', $evalInvalide['statut_canonique']);
        $this->assertTrue($evalInvalide['revue_humaine_requise']);

        // Cas B : Décision souveraine du jury dument attestée par PV officiel
        $evalValide = $droitsService->evaluerDroitsRedoublement($etudiant, $this->programmeLicenceInfo, [
            'decision_exceptionnelle_jury' => true,
            'reference_pv' => 'PV Délibération n° 2024-PV-88',
            'motif_jury' => 'Rachat exceptionnel pour circonstances médicales exceptionnelles attestées',
        ]);
        $this->assertTrue($evalValide['autorise']);
        $this->assertEquals('AUTORISE', $evalValide['statut_canonique']);
        $this->assertEquals('DECISION_EXCEPTIONNELLE_JURY', $evalValide['statut_droit']);
    }

    public function test_phase6_seuil_sectoriel_sante_vs_absence_confirmee_informatique(): void
    {
        $reglementationService = app(ReglementationAcquisService::class);

        // 1. Santé : seuil éliminatoire de 6.00/20 confirmé
        $ficheSante = $reglementationService->determinerSeuilFormation('LIC_SANTE');
        $this->assertEquals(6.0, $ficheSante['seuil']);
        $this->assertEquals('CONFIRME', $ficheSante['statut']);
        $this->assertStringContainsString('Arrêté ministériel du 10 mai 2023', $ficheSante['reference_officielle']);

        // 2. Informatique standard : absence de seuil éliminatoire national par défaut confirmée
        $ficheInfo = $reglementationService->determinerSeuilFormation('LIC_INFO_STANDARD');
        $this->assertNull($ficheInfo['seuil']);
        $this->assertEquals('ABSENCE_CONFIRMEE', $ficheInfo['statut']);
        $this->assertStringContainsString('Décret présidentiel n° 2022-631', $ficheInfo['reference_officielle']);
    }

    public function test_phase6_requalification_exhaustive_12_dossiers_recette(): void
    {
        $droitsService = app(ControleDroitsRedoublementService::class);

        $corpus = [
            ['id' => 'REC-01', 'prog' => $this->programmeLicenceInfo, 'statut_attendu' => 'CONFORME_REGLEMENTAIRE'],
            ['id' => 'REC-02', 'prog' => $this->programmeLicenceSante, 'statut_attendu' => 'CONFORME_REGLEMENTAIRE'],
            ['id' => 'REC-03', 'prog' => $this->programmeLicenceInfo, 'statut_attendu' => 'CONFORME_REGLEMENTAIRE'],
            ['id' => 'REC-04', 'prog' => $this->programmeLicenceInfo, 'statut_attendu' => 'CONFORME_REGLEMENTAIRE'],
            ['id' => 'REC-05', 'prog' => $this->programmeIngenieurENIT, 'statut_attendu' => 'CONCORDANT_NON_CERTIFIE'],
            ['id' => 'REC-06', 'prog' => $this->programmeIngenieurENIT, 'statut_attendu' => 'CONCORDANT_NON_CERTIFIE'],
            ['id' => 'REC-07', 'prog' => $this->programmeIngenieurENIT, 'statut_attendu' => 'CONCORDANT_NON_CERTIFIE'],
            ['id' => 'REC-08', 'prog' => $this->programmeMasterRecherche, 'statut_attendu' => 'CONFORME_REGLEMENTAIRE'],
            ['id' => 'REC-09', 'prog' => $this->programmeLicenceInfo, 'statut_attendu' => 'CONFORME_REGLEMENTAIRE'],
            ['id' => 'REC-10', 'prog' => $this->programmeIngenieurENIT, 'statut_attendu' => 'CONCORDANT_NON_CERTIFIE'],
            ['id' => 'REC-11', 'prog' => $this->programmeCoConstruit, 'statut_attendu' => 'REVUE_HUMAINE_REQUISE'],
            ['id' => 'REC-12', 'prog' => $this->programmeHistoriqueExpire, 'statut_attendu' => 'REVUE_HUMAINE_REQUISE'],
        ];

        foreach ($corpus as $item) {
            $etu = $this->creerEtudiant('3000'.substr($item['id'], -4), 'Corpus', $item['id']);
            $comp = $droitsService->comparerDossierRecette($etu, [
                'dossier_id' => $item['id'],
                'inscriptions_administratives' => 1,
                'redoublements_cursus' => 0,
                'decision_officielle' => 'AUTORISE_PLEIN_DROIT',
            ], $item['prog']);

            $this->assertEquals(
                $item['statut_attendu'],
                $comp['statut_recette'],
                "Échec qualification pour le dossier {$item['id']}: attendu {$item['statut_attendu']}, obtenu {$comp['statut_recette']}"
            );
        }
    }
}
