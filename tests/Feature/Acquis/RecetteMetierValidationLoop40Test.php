<?php

namespace Tests\Feature\Acquis;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\TypeDiplome;
use App\Services\Acquis\ControleDroitsRedoublementService;
use App\Services\Acquis\PlanRedoublementService;
use App\Services\Acquis\ReglementationAcquisService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * LOOP 40 — RECETTE MÉTIER ET VALIDATION INSTITUTIONNELLE.
 *
 * Suite de tests pour la validation métier, le double calcul en parallèle,
 * la gestion des dérogations et les 8 vérifications impératives de non-régression.
 */
class RecetteMetierValidationLoop40Test extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculteSciences;

    protected Faculte $faculteMedecine;

    protected Programme $programmeLicenceInfo;

    protected Programme $programmeLicenceSante;

    protected Programme $programmeIngenieur;

    protected Programme $programmeMaster;

    protected AnneeUniversitaire $annee2022;

    protected AnneeUniversitaire $annee2023;

    protected AnneeUniversitaire $annee2024;

    protected AnneeUniversitaire $annee2025;

    protected Niveau $niveauL1;

    protected Niveau $niveauL2;

    protected Niveau $niveauIng1;

    protected SemestreLmd $semestreS1;

    protected User $agentScolariteFst;

    protected User $agentScolariteMedecine;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        // 1. Facultés
        $this->faculteSciences = Faculte::firstOrCreate(['code' => 'FST'], ['nom' => 'Faculté des Sciences de Tunis', 'actif' => true]);
        $this->faculteMedecine = Faculte::firstOrCreate(['code' => 'FMT'], ['nom' => 'Faculté de Médecine de Tunis', 'actif' => true]);

        // 2. Années universitaires
        $this->annee2022 = AnneeUniversitaire::create(['libelle' => '2022-2023', 'date_debut' => '2022-09-01', 'date_fin' => '2023-06-30', 'active' => false]);
        $this->annee2023 = AnneeUniversitaire::create(['libelle' => '2023-2024', 'date_debut' => '2023-09-01', 'date_fin' => '2024-06-30', 'active' => false]);
        $this->annee2024 = AnneeUniversitaire::create(['libelle' => '2024-2025', 'date_debut' => '2024-09-01', 'date_fin' => '2025-06-30', 'active' => false]);
        $this->annee2025 = AnneeUniversitaire::create(['libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-06-30', 'active' => true]);

        // 3. Types de diplômes
        $typeLicence = TypeDiplome::firstOrCreate(['code' => 'LIC'], ['nom' => 'Licence Nationale LMD', 'cycle' => 'licence', 'actif' => true]);
        $typeIng = TypeDiplome::firstOrCreate(['code' => 'ING'], ['nom' => 'Diplôme National d\'Ingénieur', 'cycle' => 'ingenieur', 'actif' => true]);
        $typeMaster = TypeDiplome::firstOrCreate(['code' => 'MR'], ['nom' => 'Mastère de Recherche', 'cycle' => 'master', 'actif' => true]);

        // 4. Programmes
        $this->programmeLicenceInfo = Programme::firstOrCreate(
            ['code' => 'LIC_INFO_STANDARD'],
            ['faculte_id' => $this->faculteSciences->id, 'type_diplome_id' => $typeLicence->id, 'nom' => 'Licence Nationale en Sciences de l\'Informatique', 'actif' => true, 'credits_requis' => 60, 'version' => '2022.1']
        );
        $this->programmeLicenceSante = Programme::firstOrCreate(
            ['code' => 'LIC_SANTE'],
            ['faculte_id' => $this->faculteMedecine->id, 'type_diplome_id' => $typeLicence->id, 'nom' => 'Licence Nationale en Sciences de la Santé', 'actif' => true, 'credits_requis' => 60, 'version' => '2023.1']
        );
        $this->programmeIngenieur = Programme::firstOrCreate(
            ['code' => 'ING_INFO_ENIT'],
            ['faculte_id' => $this->faculteSciences->id, 'type_diplome_id' => $typeIng->id, 'nom' => 'Diplôme National d\'Ingénieur en Génie Informatique (ENIT)', 'actif' => true, 'credits_requis' => 60, 'version' => '1995.1']
        );
        $this->programmeMaster = Programme::firstOrCreate(
            ['code' => 'MASTER_RECHERCHE_FST'],
            ['faculte_id' => $this->faculteSciences->id, 'type_diplome_id' => $typeMaster->id, 'nom' => 'Mastère de Recherche en Informatique (FST)', 'actif' => true, 'credits_requis' => 60, 'version' => '2008.1']
        );

        // 5. Niveaux & Semestres
        $this->niveauL1 = Niveau::firstOrCreate(['code' => 'L1', 'programme_id' => $this->programmeLicenceInfo->id], ['nom' => 'Licence 1ère Année', 'ordre' => 1, 'actif' => true]);
        $this->niveauL2 = Niveau::firstOrCreate(['code' => 'L2', 'programme_id' => $this->programmeLicenceInfo->id], ['nom' => 'Licence 2ème Année', 'ordre' => 2, 'actif' => true]);
        $this->niveauIng1 = Niveau::firstOrCreate(['code' => 'ING1', 'programme_id' => $this->programmeIngenieur->id], ['nom' => 'Ingénieur 1ère Année', 'ordre' => 1, 'actif' => true]);

        $this->semestreS1 = SemestreLmd::firstOrCreate(
            ['code' => 'S1-DEF', 'niveau_id' => $this->niveauL1->id],
            ['nom' => 'Semestre 1', 'numero' => 1, 'ordre' => 1, 'actif' => true]
        );

        // 6. Agents
        $this->agentScolariteFst = User::firstOrCreate(
            ['email' => 'agent.fst.l40@fst.utm.tn'],
            ['name' => 'Agent Scolarité FST', 'password' => bcrypt('Secret123!'), 'role' => 'admin_faculte', 'faculte_id' => $this->faculteSciences->id]
        );
        $this->agentScolariteMedecine = User::firstOrCreate(
            ['email' => 'agent.fmt.l40@fmt.utm.tn'],
            ['name' => 'Agent Scolarité FMT', 'password' => bcrypt('Secret123!'), 'role' => 'admin_faculte', 'faculte_id' => $this->faculteMedecine->id]
        );
    }

    private function creerDossierRecette(string $identifiant, string $nom, string $prenom, ?Faculte $faculte = null): Etudiant
    {
        return Etudiant::create([
            'cin' => 'REC-'.substr(md5($identifiant), 0, 7),
            'matricule' => $identifiant,
            'numero_etudiant' => 'ETU-'.$identifiant,
            'nom' => $nom,
            'prenom' => $prenom,
            'email' => strtolower($identifiant).'@recette.uniregistre.tn',
            'faculte_id' => ($faculte ?? $this->faculteSciences)->id,
            'programme_id' => $this->programmeLicenceInfo->id,
            'date_naissance' => '2003-01-01',
            'nationalite' => 'TN',
            'sexe' => 'M',
            'adresse' => 'Espace de Recette Métier',
            'ville' => 'Tunis',
            'code_postal' => '1060',
            'annee_bac' => 2021,
            'niveau' => 'L1',
            'annee_academique' => '2024-2025',
            'actif' => true,
        ]);
    }

    // =========================================================================
    // EXIGENCE 1 — Une règle sans source ne devient pas confirmée
    // =========================================================================

    public function test_exigence1_regle_sans_source_ne_devient_pas_confirmee(): void
    {
        $reglementationService = app(ReglementationAcquisService::class);
        $statutInconnue = $reglementationService->evaluerStatutRegle('FORMATION_INCONNUE');

        $this->assertFalse($statutInconnue['applicable']);
        $this->assertEquals('SOURCE_MANQUANTE', $statutInconnue['statut']);
        $this->assertNotEquals('REGLE_CONFIRMEE', $statutInconnue['statut']);

        // Vérification fail-closed : l'appel direct lève une DomainException
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Source réglementaire manquante');
        $reglementationService->determinerSeuilFormation('FORMATION_INCONNUE');
    }

    // =========================================================================
    // EXIGENCE 2 — Une limite incertaine ne conduit pas à une exclusion automatique
    // =========================================================================

    public function test_exigence2_limite_incertaine_ne_conduit_pas_a_exclusion_automatique(): void
    {
        $droitsService = app(ControleDroitsRedoublementService::class);
        $etu = $this->creerDossierRecette('REC-INCERTAIN', 'Incertain', 'Test');

        // Programme en attente de validation (règle non confirmée)
        $progAValider = Programme::firstOrCreate(
            ['code' => 'FORMATION_A_VALIDER'],
            ['faculte_id' => $this->faculteSciences->id, 'nom' => 'Formation en attente de texte', 'actif' => true]
        );

        $eval = $droitsService->evaluerDroitsRedoublement($etu, $progAValider);

        $this->assertFalse($eval['autorise']);
        $this->assertEquals('BLOQUE_REGLEMENTATION_INCONNUE', $eval['statut_droit']);
        $this->assertTrue($eval['revue_humaine_requise'], 'Une règle incertaine doit impérativement déclencher une revue humaine.');
        $this->assertStringContainsString('Incertitude réglementaire : aucune décision d\'exclusion automatique n\'est prise', $eval['motif']);
    }

    // =========================================================================
    // EXIGENCE 3 — Une maquette ancienne ne modifie pas rétroactivement les droits
    // =========================================================================

    public function test_exigence3_maquette_ancienne_ne_modifie_pas_retroactivement_les_droits(): void
    {
        $reglementationService = app(ReglementationAcquisService::class);

        // FORMATION_EXPIREE a une date de fin au 2022-08-31
        $evalExpiree = $reglementationService->evaluerStatutRegle('FORMATION_EXPIREE', '2024-09-01');

        $this->assertFalse($evalExpiree['applicable']);
        $this->assertEquals('REGLE_EXPIREE', $evalExpiree['statut']);
        $this->assertStringContainsString('La règle a expiré le 2022-08-31', $evalExpiree['motif']);
    }

    // =========================================================================
    // EXIGENCE 4 — Une décision de jury non compétente ne contourne pas une limite
    // =========================================================================

    public function test_exigence4_decision_jury_non_competente_rejetee(): void
    {
        $droitsService = app(ControleDroitsRedoublementService::class);
        $etu = $this->creerDossierRecette('REC-JURY-INVALIDE', 'Ben Fredj', 'Walid');
        $etu->programme_id = $this->programmeIngenieur->id;
        $etu->save();

        // 2 échecs en ingénieur (limite dépassée)
        InscriptionAcademique::create(['etudiant_id' => $etu->id, 'annee_universitaire_id' => $this->annee2023->id, 'programme_id' => $this->programmeIngenieur->id, 'niveau_id' => $this->niveauIng1->id, 'niveau' => 'ING1', 'statut' => 'ajourne']);
        InscriptionAcademique::create(['etudiant_id' => $etu->id, 'annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeIngenieur->id, 'niveau_id' => $this->niveauIng1->id, 'niveau' => 'ING1', 'statut' => 'ajourne']);

        // Tentative de contournement par une autorité non compétente
        $eval = $droitsService->evaluerDroitsRedoublement($etu, $this->programmeIngenieur, [
            'decision_exceptionnelle_jury' => true,
            'autorite_competente' => 'TIERS_NON_HABILITE',
            'motif_jury' => 'Décision par une instance non habilitée',
        ]);

        $this->assertFalse($eval['autorise'], 'Une autorité non habilitée ne doit pas pouvoir autoriser une réinscription dérogatoire.');
        $this->assertEquals('LIMITE_DEPASSEE', $eval['statut_droit']);
    }

    // =========================================================================
    // EXIGENCE 5 — Une dérogation hors période ou hors périmètre est rejetée
    // =========================================================================

    public function test_exigence5_derogation_hors_periode_ou_hors_perimetre_rejetee(): void
    {
        $droitsService = app(ControleDroitsRedoublementService::class);
        $etu = $this->creerDossierRecette('REC-DEROG-INVALIDE', 'Gannoun', 'Leila');
        $etu->programme_id = $this->programmeIngenieur->id;
        $etu->save();

        // 2 échecs en ingénieur
        InscriptionAcademique::create(['etudiant_id' => $etu->id, 'annee_universitaire_id' => $this->annee2023->id, 'programme_id' => $this->programmeIngenieur->id, 'niveau_id' => $this->niveauIng1->id, 'niveau' => 'ING1', 'statut' => 'ajourne']);
        InscriptionAcademique::create(['etudiant_id' => $etu->id, 'annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeIngenieur->id, 'niveau_id' => $this->niveauIng1->id, 'niveau' => 'ING1', 'statut' => 'ajourne']);

        // Test A : Dérogation expirée (date antérieure)
        $evalExpiree = $droitsService->evaluerDroitsRedoublement($etu, $this->programmeIngenieur, [
            'derogation_accordee' => true,
            'date_expiration_derogation' => '2023-12-31',
            'reference_derogation' => 'Arrêté caduc',
        ]);
        $this->assertFalse($evalExpiree['autorise'], 'Une dérogation expirée doit être rejetée.');
        $this->assertEquals('LIMITE_DEPASSEE', $evalExpiree['statut_droit']);

        // Test B : Dérogation d'un autre étudiant (périmètre frauduleux)
        $evalAutreEtu = $droitsService->evaluerDroitsRedoublement($etu, $this->programmeIngenieur, [
            'derogation_accordee' => true,
            'etudiant_cible_id' => 99999, // ID différent de $etu->id
            'reference_derogation' => 'Arrêté d\'un autre étudiant',
        ]);
        $this->assertFalse($evalAutreEtu['autorise'], 'Une dérogation adressée à un tiers ne peut pas bénéficier à cet étudiant.');
        $this->assertEquals('LIMITE_DEPASSEE', $evalAutreEtu['statut_droit']);
    }

    // =========================================================================
    // EXIGENCE 6 — Les dossiers d'une autre faculté restent inaccessibles (anti-BOLA)
    // =========================================================================

    public function test_exigence6_dossiers_autre_faculte_inaccessibles_anti_bola(): void
    {
        // Agent de scolarité FST connecté
        Sanctum::actingAs($this->agentScolariteFst);

        // Étudiant de Médecine (FMT)
        $etuMedecine = $this->creerDossierRecette('REC-BOLA', 'Khelifi', 'Sarra', $this->faculteMedecine);

        // Tentative d'accès au dossier de redoublement
        $response = $this->get("/admin/redoublement/{$etuMedecine->id}");
        $response->assertStatus(404);
    }

    // =========================================================================
    // EXIGENCE 7 — Une simulation ou erreur de calcul ne modifie pas le relevé officiel
    // =========================================================================

    public function test_exigence7_simulation_ne_modifie_pas_le_releve_officiel(): void
    {
        $planService = app(PlanRedoublementService::class);
        $etu = $this->creerDossierRecette('REC-IMMUABLE', 'Chaouachi', 'Anis');

        $insc = InscriptionAcademique::create([
            'etudiant_id' => $etu->id,
            'annee_universitaire_id' => $this->annee2024->id,
            'programme_id' => $this->programmeLicenceInfo->id,
            'niveau_id' => $this->niveauL1->id,
            'niveau' => 'L1',
            'moyenne_annuelle' => 9.45,
            'credits_obtenus' => 24,
            'statut' => 'ajourne',
        ]);

        $delib = Deliberation::create(['annee_universitaire_id' => $this->annee2024->id, 'session' => 'principale', 'date_deliberation' => '2025-06-25', 'cloturee' => true, 'publiee' => true]);
        DecisionDeliberation::create([
            'deliberation_id' => $delib->id,
            'etudiant_id' => $etu->id,
            'inscription_academique_id' => $insc->id,
            'decision' => 'autorise_redoublement',
            'moyenne' => 9.45,
            'credits_obtenus' => 24,
            'publie' => true,
        ]);

        // Exécution de 5 simulations successives
        for ($i = 0; $i < 5; $i++) {
            $simulation = $planService->simulerPlan($etu, $insc);
            $this->assertNotNull($simulation);
        }

        // Vérification de la stricte immuabilité des données d'origine
        $inscFraiche = InscriptionAcademique::find($insc->id);
        $this->assertEquals(9.45, (float) $inscFraiche->moyenne_annuelle);
        $this->assertEquals(24, (int) $inscFraiche->credits_obtenus);
        $this->assertEquals('ajourne', $inscFraiche->statut);
    }

    // =========================================================================
    // EXIGENCE 8 — La reprise d'une recette ne produit pas de doublons (Idempotence)
    // =========================================================================

    public function test_exigence8_reprise_recette_sans_doublons_idempotence(): void
    {
        $droitsService = app(ControleDroitsRedoublementService::class);
        $etu = $this->creerDossierRecette('REC-IDEMPOTENT', 'Mathlouthi', 'Bilel');

        $situationOfficielle = [
            'dossier_id' => 'REC-IDEMPOTENT',
            'inscriptions_administratives' => 1,
            'redoublements_cursus' => 0,
            'decision_officielle' => 'AUTORISE_PLEIN_DROIT',
        ];

        // 1ère évaluation de recette
        $comp1 = $droitsService->comparerDossierRecette($etu, $situationOfficielle, $this->programmeLicenceInfo);
        // 2ème évaluation identique
        $comp2 = $droitsService->comparerDossierRecette($etu, $situationOfficielle, $this->programmeLicenceInfo);

        $this->assertTrue($comp1['conforme']);
        $this->assertTrue($comp2['conforme']);
        $this->assertEquals($comp1['type_divergence'], $comp2['type_divergence']);
        $this->assertEquals('CONFORME_SANS_DIVERGENCE', $comp2['type_divergence']);
    }

    // =========================================================================
    // PHASE 3 — Double calcul parallèle sur le corpus de 12 dossiers représentatifs
    // =========================================================================

    public function test_phase3_double_calcul_parallele_corpus_12_dossiers(): void
    {
        $droitsService = app(ControleDroitsRedoublementService::class);

        // Dossier REC-01 : Étudiant admis sans redoublement
        $etu01 = $this->creerDossierRecette('REC-01', 'Admis', 'Karim');
        InscriptionAcademique::create(['etudiant_id' => $etu01->id, 'annee_universitaire_id' => $this->annee2023->id, 'programme_id' => $this->programmeLicenceInfo->id, 'niveau_id' => $this->niveauL1->id, 'niveau' => 'L1', 'statut' => 'validee']);
        $res01 = $droitsService->comparerDossierRecette($etu01, [
            'dossier_id' => 'REC-01',
            'inscriptions_administratives' => 1,
            'redoublements_cursus' => 0,
            'decision_officielle' => 'AUTORISE_PLEIN_DROIT',
        ], $this->programmeLicenceInfo);
        $this->assertTrue($res01['conforme']);
        $this->assertFalse($res01['revue_humaine_requise']);

        // Dossier REC-03 : Ingénieur ayant atteint sa limite de 1 redoublement
        $etu03 = $this->creerDossierRecette('REC-03', 'IngenieurLimite', 'Tahar');
        $etu03->programme_id = $this->programmeIngenieur->id;
        $etu03->save();
        InscriptionAcademique::create(['etudiant_id' => $etu03->id, 'annee_universitaire_id' => $this->annee2023->id, 'programme_id' => $this->programmeIngenieur->id, 'niveau_id' => $this->niveauIng1->id, 'niveau' => 'ING1', 'statut' => 'ajourne']);
        InscriptionAcademique::create(['etudiant_id' => $etu03->id, 'annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeIngenieur->id, 'niveau_id' => $this->niveauIng1->id, 'niveau' => 'ING1', 'statut' => 'ajourne']);
        $res03 = $droitsService->comparerDossierRecette($etu03, [
            'dossier_id' => 'REC-03',
            'inscriptions_administratives' => 2,
            'redoublements_cursus' => 2,
            'decision_officielle' => 'LIMITE_DEPASSEE',
        ], $this->programmeIngenieur);
        $this->assertTrue($res03['conforme']);
        $this->assertTrue($res03['revue_humaine_requise'], 'Atteinte de limite sans dérogation -> revue humaine pour recours éventuel.');

        // Dossier REC-06 : Étudiant avec interruption d'études neutralisée
        $etu06 = $this->creerDossierRecette('REC-06', 'Interruption', 'Monia');
        InscriptionAcademique::create(['etudiant_id' => $etu06->id, 'annee_universitaire_id' => $this->annee2023->id, 'programme_id' => $this->programmeLicenceInfo->id, 'niveau_id' => $this->niveauL1->id, 'niveau' => 'L1', 'statut' => 'validee']);
        InscriptionAcademique::create(['etudiant_id' => $etu06->id, 'annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeLicenceInfo->id, 'niveau_id' => $this->niveauL2->id, 'niveau' => 'L2', 'type' => 'autre', 'statut' => 'abandon', 'observation' => 'Congé académique justifié']);
        $res06 = $droitsService->comparerDossierRecette($etu06, [
            'dossier_id' => 'REC-06',
            'inscriptions_administratives' => 2,
            'redoublements_cursus' => 0,
            'decision_officielle' => 'AUTORISE_PLEIN_DROIT',
        ], $this->programmeLicenceInfo);
        $this->assertTrue($res06['conforme']);
        $this->assertEquals(0, $res06['evaluation_uniregistre']['nb_redoublements_cursus']);

        // Dossier REC-11 : Dérogation rectorale officielle enregistrée
        $etu11 = $this->creerDossierRecette('REC-11', 'Deroge', 'Nader');
        $etu11->programme_id = $this->programmeIngenieur->id;
        $etu11->save();
        InscriptionAcademique::create(['etudiant_id' => $etu11->id, 'annee_universitaire_id' => $this->annee2023->id, 'programme_id' => $this->programmeIngenieur->id, 'niveau_id' => $this->niveauIng1->id, 'niveau' => 'ING1', 'statut' => 'ajourne']);
        InscriptionAcademique::create(['etudiant_id' => $etu11->id, 'annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeIngenieur->id, 'niveau_id' => $this->niveauIng1->id, 'niveau' => 'ING1', 'statut' => 'ajourne']);
        HistoriqueAcademique::create([
            'etudiant_id' => $etu11->id,
            'action' => 'decision_derogation',
            'entite' => 'derogation',
            'nouvelle_valeur' => json_encode(['decision' => 'accordee', 'reference_acte' => 'Arrêté rectoral n° 2025/L40-REC', 'autorite' => 'Président UTM']),
            'source_systeme' => 'derogations',
        ]);
        $res11 = $droitsService->comparerDossierRecette($etu11, [
            'dossier_id' => 'REC-11',
            'inscriptions_administratives' => 2,
            'redoublements_cursus' => 2,
            'decision_officielle' => 'DEROGATION_ACCORDEE',
        ], $this->programmeIngenieur);
        $this->assertTrue($res11['conforme']);
        $this->assertEquals('DEROGATION_ACCORDEE', $res11['evaluation_uniregistre']['statut_droit']);

        // Dossier REC-12 : Source manquante déclenchant revue humaine fail-closed
        $progInconnu = Programme::firstOrCreate(['code' => 'FORMATION_INCONNUE'], ['faculte_id' => $this->faculteSciences->id, 'nom' => 'Certificat Transversal', 'actif' => true]);
        $etu12 = $this->creerDossierRecette('REC-12', 'SourceManquante', 'Yassine');
        $res12 = $droitsService->comparerDossierRecette($etu12, [
            'dossier_id' => 'REC-12',
            'statut_reglementaire' => 'SOURCE_MANQUANTE',
        ], $progInconnu);
        $this->assertEquals('SOURCE_INSUFFISANTE', $res12['type_divergence']);
        $this->assertTrue($res12['revue_humaine_requise']);
    }
}
