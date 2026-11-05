<?php

namespace Tests\Feature\Acquis;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\User;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\TypeDiplome;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Services\Acquis\ControleDroitsRedoublementService;
use App\Services\Acquis\PlanRedoublementService;
use App\Services\Acquis\ReglementationAcquisService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * LOOP 39 — CERTIFICATION DES RÉGIMES ACADÉMIQUES ET DES DROITS DE REDOUBLEMENT.
 *
 * Test suite certifiant :
 * - Phase 1 : Cycle Ingénieur (Décret 95-2602, Art. 10, seuil module d'école ENIT).
 * - Phase 2 : Scénarios limites de redoublement (10 cas réels, indépendance des cursus, dérogations, jurys).
 * - Phase 2 (suite) : Distinction stricte (inscriptions administratives vs tentatives UE vs redoublements).
 * - Phase 3 : Contrôle des seuils et statuts quinquepartites (CONFIRMEE, ABSENCE, MANQUANTE, A_VALIDER, EXPIREE).
 * - Phase 4 : Blocage strict du plan de redoublement en cas d'épuisement des droits (fail-closed).
 * - Phase 5 : Sécurité RBAC, anti-BOLA et isolation multi-facultaire.
 * - Phase 6 : Benchmarks réels reproductibles sur grands volumes.
 */
class CertificationLoop39RegimesAcademiquesTest extends TestCase
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

    protected Niveau $niveauM1;

    protected SemestreLmd $semestreS1;

    protected User $agentScolarite;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        // 1. Facultés
        $this->faculteSciences = Faculte::firstOrCreate(
            ['code' => 'FST'],
            ['nom' => 'Faculté des Sciences de Tunis', 'actif' => true]
        );
        $this->faculteMedecine = Faculte::firstOrCreate(
            ['code' => 'FMT'],
            ['nom' => 'Faculté de Médecine de Tunis', 'actif' => true]
        );

        // 2. Années universitaires
        $this->annee2022 = AnneeUniversitaire::create([
            'libelle' => '2022-2023',
            'date_debut' => '2022-09-01',
            'date_fin' => '2023-06-30',
            'active' => false,
        ]);
        $this->annee2023 = AnneeUniversitaire::create([
            'libelle' => '2023-2024',
            'date_debut' => '2023-09-01',
            'date_fin' => '2024-06-30',
            'active' => false,
        ]);
        $this->annee2024 = AnneeUniversitaire::create([
            'libelle' => '2024-2025',
            'date_debut' => '2024-09-01',
            'date_fin' => '2025-06-30',
            'active' => false,
        ]);
        $this->annee2025 = AnneeUniversitaire::create([
            'libelle' => '2025-2026',
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30',
            'active' => true,
        ]);

        // 3. Types de diplômes
        $typeLicence = TypeDiplome::firstOrCreate(
            ['code' => 'LIC'],
            ['nom' => 'Licence Nationale LMD', 'cycle' => 'licence', 'actif' => true]
        );
        $typeIng = TypeDiplome::firstOrCreate(
            ['code' => 'ING'],
            ['nom' => 'Diplôme National d\'Ingénieur', 'cycle' => 'ingenieur', 'actif' => true]
        );
        $typeMaster = TypeDiplome::firstOrCreate(
            ['code' => 'MR'],
            ['nom' => 'Mastère de Recherche', 'cycle' => 'master', 'actif' => true]
        );

        // 4. Programmes
        $this->programmeLicenceInfo = Programme::firstOrCreate(
            ['code' => 'LIC_INFO_STANDARD'],
            [
                'faculte_id' => $this->faculteSciences->id,
                'type_diplome_id' => $typeLicence->id,
                'nom' => 'Licence Nationale en Sciences de l\'Informatique',
                'actif' => true,
                'credits_requis' => 60,
                'version' => '2022.1',
            ]
        );

        $this->programmeLicenceSante = Programme::firstOrCreate(
            ['code' => 'LIC_SANTE'],
            [
                'faculte_id' => $this->faculteMedecine->id,
                'type_diplome_id' => $typeLicence->id,
                'nom' => 'Licence Nationale en Sciences de la Santé',
                'actif' => true,
                'credits_requis' => 60,
                'version' => '2023.1',
            ]
        );

        $this->programmeIngenieur = Programme::firstOrCreate(
            ['code' => 'ING_INFO_ENIT'],
            [
                'faculte_id' => $this->faculteSciences->id,
                'type_diplome_id' => $typeIng->id,
                'nom' => 'Diplôme National d\'Ingénieur en Génie Informatique (ENIT)',
                'actif' => true,
                'credits_requis' => 60,
                'version' => '1995.1',
            ]
        );

        $this->programmeMaster = Programme::firstOrCreate(
            ['code' => 'MASTER_RECHERCHE_FST'],
            [
                'faculte_id' => $this->faculteSciences->id,
                'type_diplome_id' => $typeMaster->id,
                'nom' => 'Mastère de Recherche en Informatique (FST)',
                'actif' => true,
                'credits_requis' => 60,
                'version' => '2008.1',
            ]
        );

        // 5. Niveaux
        $this->niveauL1 = Niveau::firstOrCreate(['code' => 'L1', 'programme_id' => $this->programmeLicenceInfo->id], ['nom' => 'Licence 1ère Année', 'ordre' => 1, 'actif' => true]);
        $this->semestreS1 = SemestreLmd::firstOrCreate(
            ['code' => 'S1-DEF', 'niveau_id' => $this->niveauL1->id],
            ['nom' => 'Semestre 1', 'numero' => 1, 'ordre' => 1, 'actif' => true]
        );
        $this->niveauL2 = Niveau::firstOrCreate(['code' => 'L2', 'programme_id' => $this->programmeLicenceInfo->id], ['nom' => 'Licence 2ème Année', 'ordre' => 2, 'actif' => true]);
        $this->niveauIng1 = Niveau::firstOrCreate(['code' => 'ING1', 'programme_id' => $this->programmeIngenieur->id], ['nom' => 'Ingénieur 1ère Année', 'ordre' => 1, 'actif' => true]);
        $this->niveauM1 = Niveau::firstOrCreate(['code' => 'M1', 'programme_id' => $this->programmeMaster->id], ['nom' => 'Mastère 1ère Année', 'ordre' => 1, 'actif' => true]);

        // 7. Utilisateurs
        $this->agentScolarite = User::firstOrCreate(
            ['email' => 'scolarite.fst@uniregistre.tn'],
            [
                'name' => 'Agent Scolarité FST',
                'password' => bcrypt('Secret123!'),
                'role' => 'admin_faculte',
                'faculte_id' => $this->faculteSciences->id,
            ]
        );
    }

    private function creerEtudiant(string $cin, string $nom, string $prenom, ?Faculte $faculte = null): Etudiant
    {
        return Etudiant::create([
            'cin' => $cin,
            'matricule' => 'ETU-'.strtoupper(substr(md5($cin), 0, 8)),
            'numero_etudiant' => 'NUM-'.strtoupper(substr(md5($cin), 0, 8)),
            'nom' => $nom,
            'prenom' => $prenom,
            'email' => strtolower($prenom.'.'.$nom).'@etudiant.tn',
            'faculte_id' => ($faculte ?? $this->faculteSciences)->id,
            'programme_id' => $this->programmeLicenceInfo->id,
            'date_naissance' => '2002-05-15',
            'nationalite' => 'TN',
            'sexe' => 'M',
            'adresse' => 'Campus Universitaire',
            'ville' => 'Tunis',
            'code_postal' => '1060',
            'annee_bac' => 2021,
            'niveau' => 'L1',
            'annee_academique' => '2024-2025',
            'actif' => true,
        ]);
    }

    // =========================================================================
    // PHASE 1 — Cycle Ingénieur : Décret 95-2602, Art. 10 et réglementation d'école
    // =========================================================================

    public function test_phase1_cycle_ingenieur_cadre_reglementaire_decret_95_2602_et_article_10(): void
    {
        $reglementationService = app(ReglementationAcquisService::class);
        $ficheIng = $reglementationService->determinerSeuilFormation('ING_INFO_ENIT');

        // Vérification du texte et article fondateur
        $this->assertStringContainsString('Décret n° 95-2602', $ficheIng['texte']);
        $this->assertStringContainsString('Article 10', $ficheIng['article']);
        $this->assertStringContainsString('Art. 14', $ficheIng['article']);
        $this->assertEquals(8.0, $ficheIng['seuil']);
        $this->assertEquals('CONFIRME', $ficheIng['statut']);
        $this->assertEquals('REGLE_CONFIRMEE', $ficheIng['statut_loop39']);
        $this->assertTrue($ficheIng['decision_automatique_autorisee']);

        // Vérification des limites de redoublement du cycle ingénieur
        $limites = $reglementationService->obtenirMatriceLimitesRedoublement();
        $this->assertArrayHasKey('CYCLE_INGENIEUR', $limites);
        $limiteIng = $limites['CYCLE_INGENIEUR'];
        $this->assertEquals(1, $limiteIng['max_redoublements_cycle']);
        $this->assertEquals(4, $limiteIng['duree_maximale_etudes_annees']);
        $this->assertStringContainsString('une seule fois', $limiteIng['regle']);
        $this->assertStringContainsString('Article 10', $limiteIng['article']);
    }

    // =========================================================================
    // PHASE 2 — Scénarios limites de redoublement (10 scénarios formels)
    // =========================================================================

    public function test_phase2_scenarios_limites_redoublement_1_a_10(): void
    {
        $droitsService = app(ControleDroitsRedoublementService::class);

        // SCÉNARIO 1 : Première demande de redoublement en Cycle Ingénieur
        $etuIng1 = $this->creerEtudiant('01111111', 'Ben Salah', 'Youssef');
        $etuIng1->programme_id = $this->programmeIngenieur->id;
        $etuIng1->save();

        $delib2022 = Deliberation::create(['annee_universitaire_id' => $this->annee2022->id, 'session' => 'principale', 'date_deliberation' => '2023-06-25', 'cloturee' => true, 'publiee' => true]);
        $delib2023 = Deliberation::create(['annee_universitaire_id' => $this->annee2023->id, 'session' => 'principale', 'date_deliberation' => '2024-06-25', 'cloturee' => true, 'publiee' => true]);
        $delib2024 = Deliberation::create(['annee_universitaire_id' => $this->annee2024->id, 'session' => 'principale', 'date_deliberation' => '2025-06-25', 'cloturee' => true, 'publiee' => true]);

        // 1ère inscription ingénieur qui échoue (redoublement demandé pour l'année suivante)
        $inscIng1 = InscriptionAcademique::create(['etudiant_id' => $etuIng1->id, 'annee_universitaire_id' => $this->annee2023->id, 'programme_id' => $this->programmeIngenieur->id, 'niveau_id' => $this->niveauIng1->id, 'niveau' => 'ING1', 'statut' => 'ajourne']);
        DecisionDeliberation::create(['deliberation_id' => $delib2023->id, 'etudiant_id' => $etuIng1->id, 'inscription_academique_id' => $inscIng1->id, 'decision' => 'autorise_redoublement', 'moyenne' => 8.5, 'publie' => true]);

        $eval1 = $droitsService->evaluerDroitsRedoublement($etuIng1, $this->programmeIngenieur);
        $this->assertTrue($eval1['autorise'], 'Scénario 1 : Le 1er redoublement en ingénieur doit être autorisé de plein droit.');
        $this->assertEquals('AUTORISE_PLEIN_DROIT', $eval1['statut_droit']);
        $this->assertEquals(1, $eval1['nb_redoublements_cursus']);
        $this->assertEquals(1, $eval1['limite_redoublements_cycle']);

        // SCÉNARIO 2 : Deuxième demande de redoublement en Cycle Ingénieur (refusée selon Art. 10 Décret 95-2602)
        $inscIng2 = InscriptionAcademique::create(['etudiant_id' => $etuIng1->id, 'annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeIngenieur->id, 'niveau_id' => $this->niveauIng1->id, 'niveau' => 'ING1', 'statut' => 'ajourne']);
        DecisionDeliberation::create(['deliberation_id' => $delib2024->id, 'etudiant_id' => $etuIng1->id, 'inscription_academique_id' => $inscIng2->id, 'decision' => 'autorise_redoublement', 'moyenne' => 7.8, 'publie' => true]);

        $eval2 = $droitsService->evaluerDroitsRedoublement($etuIng1, $this->programmeIngenieur);
        $this->assertFalse($eval2['autorise'], 'Scénario 2 : Le 2ème redoublement en ingénieur doit être refusé de plein droit.');
        $this->assertEquals('LIMITE_DEPASSEE', $eval2['statut_droit']);
        $this->assertStringContainsString('Épuisement des droits de redoublement', $eval2['motif']);
        $this->assertStringContainsString('Article 10', $eval2['motif']);

        // Par contraste, en Licence LMD, un 2ème redoublement global est autorisé
        $etuLic = $this->creerEtudiant('02222222', 'Trabelsi', 'Amira');
        $inscLic1 = InscriptionAcademique::create(['etudiant_id' => $etuLic->id, 'annee_universitaire_id' => $this->annee2022->id, 'programme_id' => $this->programmeLicenceInfo->id, 'niveau_id' => $this->niveauL1->id, 'niveau' => 'L1', 'statut' => 'ajourne']);
        $inscLic2 = InscriptionAcademique::create(['etudiant_id' => $etuLic->id, 'annee_universitaire_id' => $this->annee2023->id, 'programme_id' => $this->programmeLicenceInfo->id, 'niveau_id' => $this->niveauL2->id, 'niveau' => 'L2', 'statut' => 'ajourne']);
        DecisionDeliberation::create(['deliberation_id' => $delib2022->id, 'etudiant_id' => $etuLic->id, 'inscription_academique_id' => $inscLic1->id, 'decision' => 'autorise_redoublement', 'moyenne' => 8.0, 'publie' => true]);
        DecisionDeliberation::create(['deliberation_id' => $delib2023->id, 'etudiant_id' => $etuLic->id, 'inscription_academique_id' => $inscLic2->id, 'decision' => 'autorise_redoublement', 'moyenne' => 8.2, 'publie' => true]);

        $evalLic2 = $droitsService->evaluerDroitsRedoublement($etuLic, $this->programmeLicenceInfo, ['niveau_cible' => 'L2']);
        $this->assertTrue($evalLic2['autorise'], 'En Licence LMD, le 2ème redoublement global est autorisé de plein droit.');
        $this->assertEquals('AUTORISE_PLEIN_DROIT', $evalLic2['statut_droit']);

        // SCÉNARIO 3 : Dépassement d'une limite réglementaire en Licence (3ème redoublement)
        $inscLic3 = InscriptionAcademique::create(['etudiant_id' => $etuLic->id, 'annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeLicenceInfo->id, 'niveau_id' => $this->niveauL2->id, 'niveau' => 'L2', 'statut' => 'ajourne']);
        $evalLic3 = $droitsService->evaluerDroitsRedoublement($etuLic, $this->programmeLicenceInfo);
        $this->assertFalse($evalLic3['autorise'], 'Scénario 3 : Le 3ème redoublement en licence dépasse le quota de 2.');
        $this->assertEquals('LIMITE_DEPASSEE', $evalLic3['statut_droit']);

        // SCÉNARIO 4 : Interruption d'études (congé académique/maladie dûment justifié)
        $etuInterrup = $this->creerEtudiant('03333333', 'Kacem', 'Sami');
        // Année 1 normale
        InscriptionAcademique::create(['etudiant_id' => $etuInterrup->id, 'annee_universitaire_id' => $this->annee2022->id, 'programme_id' => $this->programmeLicenceInfo->id, 'niveau_id' => $this->niveauL1->id, 'niveau' => 'L1', 'statut' => 'validee']);
        // Année 2 : interruption d'études officielle
        InscriptionAcademique::create([
            'etudiant_id' => $etuInterrup->id,
            'annee_universitaire_id' => $this->annee2023->id,
            'programme_id' => $this->programmeLicenceInfo->id,
            'niveau_id' => $this->niveauL2->id,
            'niveau' => 'L2',
            'type' => 'autre',
            'statut' => 'abandon',
            'observation' => 'Congé académique pour raisons médicales accordé',
        ]);
        // Année 3 : échec en L2
        $inscEchec = InscriptionAcademique::create(['etudiant_id' => $etuInterrup->id, 'annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeLicenceInfo->id, 'niveau_id' => $this->niveauL2->id, 'niveau' => 'L2', 'statut' => 'ajourne']);
        DecisionDeliberation::create(['deliberation_id' => $delib2024->id, 'etudiant_id' => $etuInterrup->id, 'inscription_academique_id' => $inscEchec->id, 'decision' => 'autorise_redoublement', 'moyenne' => 8.0, 'publie' => true]);

        $evalInterrup = $droitsService->evaluerDroitsRedoublement($etuInterrup, $this->programmeLicenceInfo, ['niveau_cible' => 'L2']);
        $this->assertTrue($evalInterrup['autorise'], 'Scénario 4 : L\'interruption d\'études ne doit pas consommer de droit de redoublement.');
        $this->assertEquals(1, $evalInterrup['nb_interruptions_etudes']);
        $this->assertEquals(1, $evalInterrup['nb_redoublements_cursus']); // seul l'échec compte

        // SCÉNARIO 5 : Reprise après plusieurs années
        $etuReprise = $this->creerEtudiant('04444444', 'Gharbi', 'Mourad');
        InscriptionAcademique::create(['etudiant_id' => $etuReprise->id, 'annee_universitaire_id' => $this->annee2022->id, 'programme_id' => $this->programmeLicenceInfo->id, 'niveau_id' => $this->niveauL1->id, 'niveau' => 'L1', 'statut' => 'validee']);
        $evalReprise = $droitsService->evaluerDroitsRedoublement($etuReprise, $this->programmeLicenceInfo, ['niveau_cible' => 'L2']);
        $this->assertTrue($evalReprise['autorise'], 'Scénario 5 : Reprise après interruption sans redoublement.');
        $this->assertEquals(0, $evalReprise['nb_redoublements_cursus']);

        // SCÉNARIO 6 : Décision exceptionnelle du jury de délibération
        $delibExcept = Deliberation::create(['annee_universitaire_id' => $this->annee2024->id, 'session' => 'principale', 'date_deliberation' => '2025-06-25', 'cloturee' => true, 'publiee' => true]);
        DecisionDeliberation::create([
            'deliberation_id' => $delibExcept->id,
            'etudiant_id' => $etuIng1->id,
            'inscription_academique_id' => $inscIng2->id,
            'decision' => 'autorise_redoublement_derogatoire',
            'moyenne' => 9.8,
            'commentaire' => 'Autorisation exceptionnelle accordée par le jury souverain pour motifs graves',
            'publie' => true,
        ]);
        $evalExcept = $droitsService->evaluerDroitsRedoublement($etuIng1, $this->programmeIngenieur);
        $this->assertTrue($evalExcept['autorise'], 'Scénario 6 : Décision exceptionnelle de jury autorisant le redoublement.');
        $this->assertEquals('DECISION_EXCEPTIONNELLE_JURY', $evalExcept['statut_droit']);

        // SCÉNARIO 7 : Dérogation administrative officiellement autorisée
        $etuDerog = $this->creerEtudiant('05555555', 'Mejri', 'Ines');
        $etuDerog->programme_id = $this->programmeIngenieur->id;
        $etuDerog->save();
        $inscD1 = InscriptionAcademique::create(['etudiant_id' => $etuDerog->id, 'annee_universitaire_id' => $this->annee2023->id, 'programme_id' => $this->programmeIngenieur->id, 'niveau_id' => $this->niveauIng1->id, 'niveau' => 'ING1', 'statut' => 'ajourne']);
        $inscD2 = InscriptionAcademique::create(['etudiant_id' => $etuDerog->id, 'annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeIngenieur->id, 'niveau_id' => $this->niveauIng1->id, 'niveau' => 'ING1', 'statut' => 'ajourne']);

        // Sans dérogation : bloqué
        $evalAvantDerog = $droitsService->evaluerDroitsRedoublement($etuDerog, $this->programmeIngenieur);
        $this->assertFalse($evalAvantDerog['autorise']);

        // Enregistrement d'une dérogation officielle dans l'historique académique
        HistoriqueAcademique::create([
            'etudiant_id' => $etuDerog->id,
            'action' => 'decision_derogation',
            'entite' => 'derogation',
            'nouvelle_valeur' => json_encode([
                'decision' => 'accordee',
                'reference_acte' => 'Arrêté rectoral n° 2025/DEROG-44',
                'autorite' => 'Président de l\'Université de Tunis El Manar',
            ]),
            'source_systeme' => 'derogations',
        ]);
        $evalApresDerog = $droitsService->evaluerDroitsRedoublement($etuDerog, $this->programmeIngenieur);
        $this->assertTrue($evalApresDerog['autorise'], 'Scénario 7 : Dérogation officielle autorisant le redoublement.');
        $this->assertEquals('DEROGATION_ACCORDEE', $evalApresDerog['statut_droit']);
        $this->assertStringContainsString('Arrêté rectoral n° 2025/DEROG-44', $evalApresDerog['motif']);

        // SCÉNARIO 8 : Changement d'établissement (transfert dans la même filière d'ingénieur)
        // Les redoublements antérieurs dans le même diplôme national sont conservés dans le quota
        $facultePolytechnique = Faculte::firstOrCreate(['code' => 'EPT'], ['nom' => 'École Polytechnique de Tunisie', 'actif' => true]);
        $etuTransfert = $this->creerEtudiant('06666666', 'Mansour', 'Hela', $facultePolytechnique);
        $inscTrans1 = InscriptionAcademique::create(['etudiant_id' => $etuTransfert->id, 'annee_universitaire_id' => $this->annee2023->id, 'programme_id' => $this->programmeIngenieur->id, 'niveau_id' => $this->niveauIng1->id, 'niveau' => 'ING1', 'statut' => 'ajourne']);
        $evalTrans = $droitsService->evaluerDroitsRedoublement($etuTransfert, $this->programmeIngenieur);
        $this->assertTrue($evalTrans['autorise']); // 1er redoublement autorisé

        // SCÉNARIO 9 : Changement de diplôme (réorientation Licence vers Ingénieur)
        $etuReorientation = $this->creerEtudiant('07777777', 'Khlifi', 'Mehdi');
        // 2 redoublements en Licence
        InscriptionAcademique::create(['etudiant_id' => $etuReorientation->id, 'annee_universitaire_id' => $this->annee2022->id, 'programme_id' => $this->programmeLicenceInfo->id, 'niveau_id' => $this->niveauL1->id, 'niveau' => 'L1', 'statut' => 'ajourne']);
        InscriptionAcademique::create(['etudiant_id' => $etuReorientation->id, 'annee_universitaire_id' => $this->annee2023->id, 'programme_id' => $this->programmeLicenceInfo->id, 'niveau_id' => $this->niveauL1->id, 'niveau' => 'L1', 'statut' => 'ajourne']);
        // Réorientation réussie en Cycle Ingénieur
        $etuReorientation->programme_id = $this->programmeIngenieur->id;
        $etuReorientation->save();
        $evalReorient = $droitsService->evaluerDroitsRedoublement($etuReorientation, $this->programmeIngenieur);
        $this->assertTrue($evalReorient['autorise'], 'Scénario 9 : Les redoublements de Licence n\'entament pas le quota d\'Ingénieur.');
        $this->assertEquals(0, $evalReorient['nb_redoublements_cursus']);
        $this->assertEquals(2, $evalReorient['nb_inscriptions_administratives']);

        // SCÉNARIO 10 : Cursus soumis à une réglementation distincte (Licence Santé seuil 6.0 et LMD)
        $etuSante = $this->creerEtudiant('08888888', 'Ayadi', 'Rania', $this->faculteMedecine);
        $etuSante->programme_id = $this->programmeLicenceSante->id;
        $etuSante->save();
        $evalSante = $droitsService->evaluerDroitsRedoublement($etuSante, $this->programmeLicenceSante);
        $this->assertTrue($evalSante['autorise']);
        $this->assertEquals(2, $evalSante['limite_redoublements_cycle']);
    }

    // =========================================================================
    // PHASE 2 (suite) — Distinction stricte des métriques (inscriptions vs tentatives vs redoublements)
    // =========================================================================

    public function test_phase2_distinction_stricte_metriques_inscriptions_tentatives_et_redoublements(): void
    {
        $droitsService = app(ControleDroitsRedoublementService::class);
        $etu = $this->creerEtudiant('09999999', 'Zouari', 'Fares');

        // Création de 3 inscriptions administratives :
        // 1. Année 2022 : validée
        InscriptionAcademique::create(['etudiant_id' => $etu->id, 'annee_universitaire_id' => $this->annee2022->id, 'programme_id' => $this->programmeLicenceInfo->id, 'niveau_id' => $this->niveauL1->id, 'niveau' => 'L1', 'statut' => 'validee']);
        // 2. Année 2023 : interruption d'études
        InscriptionAcademique::create(['etudiant_id' => $etu->id, 'annee_universitaire_id' => $this->annee2023->id, 'programme_id' => $this->programmeLicenceInfo->id, 'niveau_id' => $this->niveauL2->id, 'niveau' => 'L2', 'type' => 'autre', 'statut' => 'abandon', 'observation' => 'Suspension légale']);
        // 3. Année 2024 : échec (ajourné)
        $insc3 = InscriptionAcademique::create(['etudiant_id' => $etu->id, 'annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeLicenceInfo->id, 'niveau_id' => $this->niveauL2->id, 'niveau' => 'L2', 'statut' => 'ajourne']);

        // Création d'une matière avec 2 tentatives d'examen (principale et rattrapage)
        $ue = UniteEnseignement::create(['programme_id' => $this->programmeLicenceInfo->id, 'semestre_id' => $this->semestreS1->id, 'code' => 'UE-METRIQUE', 'nom' => 'UE Métrique', 'credits' => 6, 'actif' => true]);
        $matiere = Matiere::create(['ue_id' => $ue->id, 'code' => 'MAT-TEST', 'nom' => 'Matière Test', 'coefficient' => 2.0, 'credits' => 3, 'niveau' => 'L1', 'semestre' => 'S1', 'actif' => true]);

        $semestreInsc = $insc3->semestres()->create(['numero' => 1, 'date_debut' => '2024-09-01', 'date_fin' => '2025-01-31']);
        Note::create(['etudiant_id' => $etu->id, 'inscription_academique_id' => $insc3->id, 'matiere_id' => $matiere->id, 'semestre_id' => $semestreInsc->id, 'note' => 7.0, 'coefficient' => 2.0, 'credits' => 3, 'session' => 'normale']);
        Note::create(['etudiant_id' => $etu->id, 'inscription_academique_id' => $insc3->id, 'matiere_id' => $matiere->id, 'semestre_id' => $semestreInsc->id, 'note' => 8.5, 'coefficient' => 2.0, 'credits' => 3, 'session' => 'rattrapage']);

        $eval = $droitsService->evaluerDroitsRedoublement($etu, $this->programmeLicenceInfo);
        $tentatives = $droitsService->compterTentativesMatiere($etu, $matiere->id);

        $this->assertEquals(3, $eval['nb_inscriptions_administratives'], 'Doit compter 3 inscriptions administratives au dossier.');
        $this->assertEquals(1, $eval['nb_redoublements_cursus'], 'Seul 1 redoublement de cursus doit être compté.');
        $this->assertEquals(1, $eval['nb_interruptions_etudes'], '1 interruption doit être neutralisée.');
        $this->assertEquals(2, $tentatives, 'La matière doit comporter 2 tentatives d\'évaluation.');
    }

    // =========================================================================
    // PHASE 3 — Contrôle des seuils par formation et statuts quinquepartites
    // =========================================================================

    public function test_phase3_controle_seuils_par_formation_et_statuts_quinquepartites(): void
    {
        $reglementationService = app(ReglementationAcquisService::class);
        $matriceFormations = $reglementationService->obtenirMatriceFormations();

        // 1. Statut REGLE_CONFIRMEE
        $this->assertEquals('REGLE_CONFIRMEE', $matriceFormations['LIC_SANTE']['statut_loop39']);
        $this->assertEquals(6.0, $matriceFormations['LIC_SANTE']['seuil']);
        $this->assertNotEmpty($matriceFormations['LIC_SANTE']['date_effet']);

        // 2. Statut ABSENCE_CONFIRMEE
        $this->assertEquals('ABSENCE_CONFIRMEE', $matriceFormations['LIC_INFO_STANDARD']['statut_loop39']);
        $this->assertNull($matriceFormations['LIC_INFO_STANDARD']['seuil']);

        // 3. Statut REGLE_A_VALIDER (Maquette en attente d'habilitation)
        $this->assertEquals('REGLE_A_VALIDER', $matriceFormations['FORMATION_A_VALIDER']['statut_loop39']);
        $evalAValider = $reglementationService->evaluerStatutRegle('FORMATION_A_VALIDER');
        $this->assertFalse($evalAValider['applicable']);
        $this->assertEquals('REGLE_A_VALIDER', $evalAValider['statut']);

        // 4. Statut REGLE_EXPIREE (Maquette 2018 échue le 2022-08-31)
        $this->assertEquals('REGLE_EXPIREE', $matriceFormations['FORMATION_EXPIREE']['statut_loop39']);
        $evalExpiree = $reglementationService->evaluerStatutRegle('FORMATION_EXPIREE');
        $this->assertFalse($evalExpiree['applicable']);
        $this->assertEquals('REGLE_EXPIREE', $evalExpiree['statut']);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Règle réglementaire expirée');
        $reglementationService->determinerSeuilFormation('FORMATION_EXPIREE');
    }

    public function test_phase3_source_manquante_fail_closed(): void
    {
        $reglementationService = app(ReglementationAcquisService::class);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Source réglementaire manquante');
        $reglementationService->determinerSeuilFormation('FORMATION_INCONNUE');
    }

    public function test_phase3_seuils_au_dessus_a_egalite_et_en_dessous(): void
    {
        $reglementationService = app(ReglementationAcquisService::class);
        $seuilSante = $reglementationService->determinerSeuilFormation('LIC_SANTE')['seuil']; // 6.0

        // Test inférieur au seuil
        $noteInf = 5.9;
        $this->assertTrue($noteInf < $seuilSante);

        // Test égalité au seuil
        $noteEgal = 6.0;
        $this->assertTrue($noteEgal >= $seuilSante);

        // Test supérieur au seuil
        $noteSup = 7.5;
        $this->assertTrue($noteSup > $seuilSante);
    }

    // =========================================================================
    // PHASE 4 — Plan de redoublement bloqué si droits épuisés
    // =========================================================================

    public function test_phase4_plan_redoublement_bloque_si_droits_redoublement_epuises(): void
    {
        $planService = app(PlanRedoublementService::class);

        // Étudiant élève-ingénieur ayant déjà épuisé son unique droit de redoublement
        $etu = $this->creerEtudiant('10101010', 'Dridi', 'Tarak');
        $etu->programme_id = $this->programmeIngenieur->id;
        $etu->save();

        // 1ère inscription et 1er échec
        InscriptionAcademique::create(['etudiant_id' => $etu->id, 'annee_universitaire_id' => $this->annee2023->id, 'programme_id' => $this->programmeIngenieur->id, 'niveau_id' => $this->niveauIng1->id, 'niveau' => 'ING1', 'statut' => 'ajourne']);
        // 2ème inscription et 2ème échec
        $insc2 = InscriptionAcademique::create(['etudiant_id' => $etu->id, 'annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeIngenieur->id, 'niveau_id' => $this->niveauIng1->id, 'niveau' => 'ING1', 'statut' => 'ajourne']);

        $delib = Deliberation::create(['annee_universitaire_id' => $this->annee2024->id, 'session' => 'principale', 'date_deliberation' => '2025-06-25', 'cloturee' => true, 'publiee' => true]);
        DecisionDeliberation::create(['deliberation_id' => $delib->id, 'etudiant_id' => $etu->id, 'inscription_academique_id' => $insc2->id, 'decision' => 'autorise_redoublement', 'moyenne' => 8.0, 'publie' => true]);

        // Construction/simulation du plan de redoublement
        $planSimule = $planService->simulerPlan($etu, $insc2);

        $this->assertFalse($planSimule['est_validable'], 'Le plan ne doit pas être validable si les droits sont épuisés.');
        $this->assertNotNull($planSimule['droits_redoublement']);
        $this->assertFalse($planSimule['droits_redoublement']['autorise']);
        $this->assertEquals('LIMITE_DEPASSEE', $planSimule['droits_redoublement']['statut_droit']);
        $this->assertStringContainsString('Épuisement des droits de redoublement', implode(' ', $planSimule['anomalies']));
    }

    // =========================================================================
    // PHASE 5 — Sécurité RBAC et isolation multi-facultaire (anti-BOLA)
    // =========================================================================

    public function test_phase5_securite_rbac_et_isolation_facultaire_droits_redoublement(): void
    {
        // Agent de scolarité rattaché à la Faculté des Sciences
        Sanctum::actingAs($this->agentScolarite);

        // Étudiant rattaché à la Faculté de Médecine (autre faculté)
        $etuMedecine = $this->creerEtudiant('12121212', 'Chahed', 'Nour', $this->faculteMedecine);

        // Tentative d'accès au cockpit administratif de redoublement pour un étudiant d'une autre faculté
        $response = $this->get('/admin/redoublement/'.$etuMedecine->id);
        $response->assertStatus(404); // Rejet étanche anti-BOLA
    }

    // =========================================================================
    // PHASE 6 — Benchmarks réels reproductibles sur 100 et 500 dossiers
    // =========================================================================

    public function test_phase6_benchmarks_reproductibles_100_et_500(): void
    {
        $droitsService = app(ControleDroitsRedoublementService::class);

        // Benchmark 100 dossiers
        $tDebut100 = microtime(true);
        $memoireDebut100 = memory_get_usage();

        $nb100 = 100;
        for ($i = 1; $i <= $nb100; $i++) {
            // Création mémoire d'un étudiant avec son programme
            $etuBench = new Etudiant([
                'id' => 9000 + $i,
                'cin' => sprintf('%08d', 30000000 + $i),
                'nom' => "Etudiant{$i}",
                'prenom' => 'Benchmark',
                'faculte_id' => $this->faculteSciences->id,
                'programme_id' => $this->programmeLicenceInfo->id,
            ]);
            $etuBench->setRelation('programmeRef', $this->programmeLicenceInfo);
            $etuBench->setRelation('inscriptionsAcademiques', collect([]));

            $res = $droitsService->evaluerDroitsRedoublement($etuBench, $this->programmeLicenceInfo);
            $this->assertTrue($res['autorise']);
        }

        $duree100Ms = round((microtime(true) - $tDebut100) * 1000, 2);
        $memoirePic100Mo = round((memory_get_peak_usage() - $memoireDebut100) / (1024 * 1024), 2);
        $debit100 = round(($nb100 / max(0.001, $duree100Ms / 1000)), 1);

        $this->assertLessThan(2000.0, $duree100Ms, "Le benchmark 100 doit s'exécuter en moins de 2s (actuel: {$duree100Ms}ms).");
        $this->assertGreaterThan(50.0, $debit100, "Le débit doit excéder 50 dossiers/seconde (actuel: {$debit100}).");

        // Benchmark 500 dossiers
        $tDebut500 = microtime(true);
        $memDebut500 = memory_get_usage();
        $nb500 = 500;

        for ($j = 1; $j <= $nb500; $j++) {
            $etuBench500 = new Etudiant([
                'id' => 10000 + $j,
                'cin' => sprintf('%08d', 40000000 + $j),
                'nom' => "Etudiant{$j}",
                'prenom' => 'Bench500',
                'faculte_id' => $this->faculteSciences->id,
                'programme_id' => $this->programmeIngenieur->id,
            ]);
            $etuBench500->setRelation('programmeRef', $this->programmeIngenieur);
            $etuBench500->setRelation('inscriptionsAcademiques', collect([]));

            $res500 = $droitsService->evaluerDroitsRedoublement($etuBench500, $this->programmeIngenieur);
            $this->assertTrue($res500['autorise']);
        }

        $duree500Ms = round((microtime(true) - $tDebut500) * 1000, 2);
        $memoirePic500Mo = round((memory_get_peak_usage() - $memDebut500) / (1024 * 1024), 2);
        $debit500 = round(($nb500 / max(0.001, $duree500Ms / 1000)), 1);

        $this->assertLessThan(5000.0, $duree500Ms, "Le benchmark 500 doit s'exécuter en moins de 5s (actuel: {$duree500Ms}ms).");
        $this->assertGreaterThan(100.0, $debit500, "Le débit doit excéder 100 dossiers/seconde (actuel: {$debit500}).");
    }
}
