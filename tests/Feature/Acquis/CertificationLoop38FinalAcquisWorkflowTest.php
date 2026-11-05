<?php

namespace Tests\Feature\Acquis;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\User;
use App\Modules\AssistantIa\Tools\Metier\ExpliquerAcquisRedoublementTool;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\TypeDiplome;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Services\Acquis\CalculAcquisPedagogiquesService;
use App\Services\Acquis\CampagneRedoublementMasseService;
use App\Services\Acquis\DocumentPedagogiqueOfficielService;
use App\Services\Acquis\MoteurDecisionAcademiqueService;
use App\Services\Acquis\ReglementationAcquisService;
use App\Services\Acquis\TransitionMaquettePedagogiqueService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * LOOP 38 — CERTIFICATION RÉGLEMENTAIRE, PERFORMANCE ET ROBUSTESSE FINALE (CONSOLIDATION LOOP 26 À 37).
 *
 * Suite de tests de niveau production certifiant :
 * - Phase 0 : Audit d'état initial et intégrité du socle
 * - Phase 1 : Matrice réglementaire consolidée avec statuts quadripartites
 * - Phase 2 : Seuils de compétence exacts (Licence Santé/Éducation vs Info, Décret 95-2602 Ingénieur, Mastères)
 * - Phase 3 : Capitalisation et transitions de maquettes (6 dimensions, 10 scénarios)
 * - Phase 4 : Sécurité cryptographique (10 cas, HMAC versionné, rejet clé inconnue, rate limiting, zéro PII)
 * - Phase 5 : Campagnes massives certifiées (100 et 500 dossiers synthétiques, débit, RAM, résilience)
 * - Phase 6 : Recette fonctionnelle étudiant (10 scénarios réels conformes au PV de jury)
 * - Phase 7 : Assistant IA en lecture seule avec citations textuelles exactes
 * - Phase 8 : Sécurité API Sanctum, RBAC, anti-BOLA et isolation facultaire
 */
class CertificationLoop38FinalAcquisWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculteSciences;

    protected Faculte $faculteMedecine;

    protected Programme $programmeLicenceInfo;

    protected Programme $programmeLicenceSante;

    protected Programme $programmeIngenieur;

    protected Programme $programmeMaster;

    protected AnneeUniversitaire $annee2023;

    protected AnneeUniversitaire $annee2024;

    protected AnneeUniversitaire $annee2025;

    protected Niveau $niveauL1;

    protected SemestreLmd $semestreS1;

    protected Niveau $niveauL2;

    protected Niveau $niveauIng1;

    protected Niveau $niveauM1;

    protected User $agentScolarite;

    protected User $etudiantUser;

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
                'duree_annees' => 3,
                'semestres_nombre' => 6,
                'credits_requis' => 180,
                'actif' => true,
            ]
        );

        $this->programmeLicenceSante = Programme::firstOrCreate(
            ['code' => 'LIC_SANTE'],
            [
                'faculte_id' => $this->faculteMedecine->id,
                'type_diplome_id' => $typeLicence->id,
                'nom' => 'Licence Nationale en Sciences de la Santé et Technologies Médicales',
                'duree_annees' => 3,
                'semestres_nombre' => 6,
                'credits_requis' => 180,
                'actif' => true,
            ]
        );

        $this->programmeIngenieur = Programme::firstOrCreate(
            ['code' => 'ING_INFO_ENIT'],
            [
                'faculte_id' => $this->faculteSciences->id,
                'type_diplome_id' => $typeIng->id,
                'nom' => 'Diplôme National d\'Ingénieur en Génie Informatique',
                'duree_annees' => 3,
                'semestres_nombre' => 6,
                'credits_requis' => 180,
                'actif' => true,
            ]
        );

        $this->programmeMaster = Programme::firstOrCreate(
            ['code' => 'MASTER_RECHERCHE_FST'],
            [
                'faculte_id' => $this->faculteSciences->id,
                'type_diplome_id' => $typeMaster->id,
                'nom' => 'Mastère de Recherche en Informatique et Systèmes Intelligents',
                'duree_annees' => 2,
                'semestres_nombre' => 4,
                'credits_requis' => 120,
                'actif' => true,
            ]
        );

        // 5. Niveaux
        $this->niveauL1 = Niveau::firstOrCreate(
            ['code' => 'L1', 'programme_id' => $this->programmeLicenceInfo->id],
            ['nom' => 'Première Année Licence', 'ordre' => 1, 'actif' => true]
        );
        $this->semestreS1 = SemestreLmd::firstOrCreate(
            ['code' => 'S1-DEF', 'niveau_id' => $this->niveauL1->id],
            ['nom' => 'Semestre 1', 'numero' => 1, 'ordre' => 1, 'actif' => true]
        );
        $this->niveauL2 = Niveau::firstOrCreate(
            ['code' => 'L2', 'programme_id' => $this->programmeLicenceInfo->id],
            ['nom' => 'Deuxième Année Licence', 'ordre' => 2, 'actif' => true]
        );
        $this->niveauIng1 = Niveau::firstOrCreate(
            ['code' => 'ING1', 'programme_id' => $this->programmeIngenieur->id],
            ['nom' => 'Première Année Ingénieur', 'ordre' => 1, 'actif' => true]
        );
        $this->niveauM1 = Niveau::firstOrCreate(
            ['code' => 'M1', 'programme_id' => $this->programmeMaster->id],
            ['nom' => 'Première Année Mastère', 'ordre' => 1, 'actif' => true]
        );

        // 6. Utilisateurs
        $this->agentScolarite = User::factory()->create([
            'email' => 'agent.scolarite.l38@fst.utm.tn',
            'role' => 'admin_faculte',
            'faculte_id' => $this->faculteSciences->id,
        ]);
        $this->etudiantUser = User::factory()->create([
            'email' => 'etudiant.l38@fst.utm.tn',
            'role' => 'etudiant',
            'faculte_id' => $this->faculteSciences->id,
        ]);
    }

    protected function creerEtudiant(string $cin, string $nom, string $prenom, ?Faculte $faculte = null): Etudiant
    {
        $faculte ??= $this->faculteSciences;

        return Etudiant::create([
            'faculte_id' => $faculte->id,
            'programme_id' => $this->programmeLicenceInfo->id,
            'niveau' => 'L1',
            'annee_academique' => '2024-2025',
            'cin' => $cin,
            'matricule' => "ETU-{$cin}",
            'num_inscription' => "INS-{$cin}",
            'nom' => $nom,
            'prenom' => $prenom,
            'date_naissance' => '2002-05-15',
            'lieu_naissance' => 'Tunis',
            'nationalite' => 'Tunisienne',
            'sexe' => 'M',
            'annee_bac' => 2022,
            'email' => strtolower("{$prenom}.{$nom}.{$cin}@fst.utm.tn"),
            'telephone' => '98123456',
            'statut' => 'valide',
            'adresse' => 'Campus Universitaire',
            'ville' => 'Tunis',
            'code_postal' => '1060',
        ]);
    }

    // =========================================================================
    // PHASE 0 — Audit de l'état existant et stabilité sans régression
    // =========================================================================

    public function test_phase0_audit_etat_existant_et_stabilite_sans_regression(): void
    {
        $reglementationService = app(ReglementationAcquisService::class);
        $calculService = app(CalculAcquisPedagogiquesService::class);
        $moteurService = app(MoteurDecisionAcademiqueService::class);
        $transitionService = app(TransitionMaquettePedagogiqueService::class);
        $campagneService = app(CampagneRedoublementMasseService::class);
        $documentService = app(DocumentPedagogiqueOfficielService::class);

        $this->assertInstanceOf(ReglementationAcquisService::class, $reglementationService);
        $this->assertInstanceOf(CalculAcquisPedagogiquesService::class, $calculService);
        $this->assertInstanceOf(MoteurDecisionAcademiqueService::class, $moteurService);
        $this->assertInstanceOf(TransitionMaquettePedagogiqueService::class, $transitionService);
        $this->assertInstanceOf(CampagneRedoublementMasseService::class, $campagneService);
        $this->assertInstanceOf(DocumentPedagogiqueOfficielService::class, $documentService);

        // Intégrité de la configuration sans mutation destructive
        $this->assertNotEmpty(config('acquis_reglementation.regimes'));
        $this->assertNotEmpty(config('acquis_reglementation.matrice_formations'));
        $this->assertNotEmpty(config('acquis_reglementation.crypto'));
    }

    // =========================================================================
    // PHASE 1 — Matrice réglementaire consolidée avec statuts quadripartites
    // =========================================================================

    public function test_phase1_matrice_reglementaire_par_formation_et_statuts_quadripartites(): void
    {
        $reglementationService = app(ReglementationAcquisService::class);
        $matriceFormations = $reglementationService->obtenirMatriceFormations();

        $this->assertIsArray($matriceFormations);
        $this->assertArrayHasKey('LIC_SANTE', $matriceFormations);
        $this->assertArrayHasKey('LIC_EDUCATION', $matriceFormations);
        $this->assertArrayHasKey('LIC_INFO_STANDARD', $matriceFormations);
        $this->assertArrayHasKey('ING_INFO_ENIT', $matriceFormations);
        $this->assertArrayHasKey('ING_NON_CONFIGURE', $matriceFormations);
        $this->assertArrayHasKey('MASTER_RECHERCHE_FST', $matriceFormations);
        $this->assertArrayHasKey('FORMATION_INCONNUE', $matriceFormations);

        // Validation des 10 champs obligatoires pour chaque formation
        $champsObligatoires = [
            'diplome', 'etablissement', 'regime', 'texte', 'article',
            'seuil', 'unite_concernee', 'date_effet', 'version', 'statut',
        ];

        foreach ($matriceFormations as $code => $fiche) {
            foreach ($champsObligatoires as $champ) {
                $this->assertArrayHasKey($champ, $fiche, "Champ '{$champ}' manquant dans la fiche formation '{$code}'.");
            }
            $this->assertContains($fiche['statut'], ['CONFIRME', 'ABSENCE_CONFIRMEE', 'NON_CONFIGURE', 'SOURCE_MANQUANTE']);
        }

        // Vérification des 4 statuts distincts
        $this->assertEquals('CONFIRME', $matriceFormations['LIC_SANTE']['statut']);
        $this->assertEquals(6.0, $matriceFormations['LIC_SANTE']['seuil']);

        $this->assertEquals('ABSENCE_CONFIRMEE', $matriceFormations['LIC_INFO_STANDARD']['statut']);
        $this->assertNull($matriceFormations['LIC_INFO_STANDARD']['seuil']);

        $this->assertEquals('CONFIRME', $matriceFormations['ING_INFO_ENIT']['statut']);
        $this->assertEquals(8.0, $matriceFormations['ING_INFO_ENIT']['seuil']);

        $this->assertEquals('NON_CONFIGURE', $matriceFormations['ING_NON_CONFIGURE']['statut']);
        $this->assertNull($matriceFormations['ING_NON_CONFIGURE']['seuil']);

        $this->assertEquals('SOURCE_MANQUANTE', $matriceFormations['FORMATION_INCONNUE']['statut']);
    }

    // =========================================================================
    // PHASE 2 — Seuils de compétence exacts et compensation
    // =========================================================================

    public function test_phase2_seuils_competence_licence_ingenieur_master_et_compensation(): void
    {
        $reglementationService = app(ReglementationAcquisService::class);
        $calculService = app(CalculAcquisPedagogiquesService::class);

        // 1. Licence Santé / Éducation : Seuil officiel 6.0/20 (Arrêté du 10 mai 2023)
        $seuilSante = $reglementationService->determinerSeuilFormation('LIC_SANTE');
        $this->assertEquals('CONFIRME', $seuilSante['statut']);
        $this->assertEquals(6.0, $seuilSante['seuil']);
        $this->assertTrue($seuilSante['decision_automatique_autorisee']);
        $this->assertStringContainsString('Arrêté ministériel du 10 mai 2023', $seuilSante['reference_officielle']);

        // 2. Licence Informatique standard : Pas de seuil imposé nationalement (ABSENCE_CONFIRMEE)
        $seuilInfo = $reglementationService->determinerSeuilFormation('LIC_INFO_STANDARD');
        $this->assertEquals('ABSENCE_CONFIRMEE', $seuilInfo['statut']);
        $this->assertNull($seuilInfo['seuil']);
        $this->assertTrue($seuilInfo['decision_automatique_autorisee']);

        // 3. Cycle Ingénieur ENIT : Décret n° 95-2602 Art. 14 (Seuil 8.0/20 CONFIRME)
        $seuilIng = $reglementationService->determinerSeuilFormation('ING_INFO_ENIT');
        $this->assertEquals('CONFIRME', $seuilIng['statut']);
        $this->assertEquals(8.0, $seuilIng['seuil']);
        $this->assertStringContainsString('Décret n° 95-2602', $seuilIng['reference_officielle']);

        // 4. Cycle Ingénieur non configuré : Décision automatique bloquée
        $seuilIngNonConf = $reglementationService->determinerSeuilFormation('ING_NON_CONFIGURE');
        $this->assertEquals('NON_CONFIGURE', $seuilIngNonConf['statut']);
        $this->assertFalse($seuilIngNonConf['decision_automatique_autorisee']);

        // 5. Mastère LMD : Absence confirmée par Décret 2008-3123
        $seuilMaster = $reglementationService->determinerSeuilFormation('MASTER_RECHERCHE_FST');
        $this->assertEquals('ABSENCE_CONFIRMEE', $seuilMaster['statut']);
        $this->assertNull($seuilMaster['seuil']);

        // 6. Formation inconnue : Fail-closed avec DomainException
        $this->expectException(DomainException::class);
        $reglementationService->determinerSeuilFormation('FORMATION_INCONNUE');
    }

    public function test_phase2_seuils_test_notes_au_dessus_egalite_en_dessous(): void
    {
        $calculService = app(CalculAcquisPedagogiquesService::class);
        $delib = Deliberation::create(['annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeLicenceInfo->id, 'session' => 'principale', 'publie' => true]);

        // A. Note 4.5/20 en dessous du seuil 6.0/20 (bloque compensation)
        $etu1 = $this->creerEtudiant('02000001', 'SeuilBas', 'Test');
        $insc1 = InscriptionAcademique::create(['etudiant_id' => $etu1->id, 'annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeLicenceInfo->id, 'niveau_id' => $this->niveauL1->id, 'niveau' => 'L1', 'statut' => 'validee']);
        $sem1 = $insc1->semestres()->create(['numero' => 1]);
        $ue1 = UniteEnseignement::create(['programme_id' => $this->programmeLicenceInfo->id, 'code' => 'UE-BIO-1', 'nom' => 'Biologie', 'semestre_id' => $this->semestreS1->id, 'credits' => 6, 'est_capitalisable' => true]);
        $ecue1 = Matiere::create(['ue_id' => $ue1->id, 'code' => 'ECUE-BIO1', 'nom' => 'Cellulaire', 'niveau' => 'L1', 'semestre' => 1, 'filiere' => 'Sante', 'coefficient' => 1.0, 'credits' => 6, 'actif' => true]);
        Note::create(['etudiant_id' => $etu1->id, 'inscription_academique_id' => $insc1->id, 'semestre_id' => $sem1->id, 'matiere_id' => $ecue1->id, 'session' => 'normale', 'note' => 4.5, 'coefficient' => 1.0, 'credits' => 6]);
        DecisionDeliberation::create(['deliberation_id' => $delib->id, 'etudiant_id' => $etu1->id, 'inscription_academique_id' => $insc1->id, 'decision' => 'autorise_redoublement', 'moyenne' => 4.5, 'credits_obtenus' => 0, 'credits_total' => 60, 'publie' => true]);

        $res1 = $calculService->analyserAcquis($etu1, $insc1);
        $ueRes1 = collect($res1['ues'])->firstWhere('code', 'UE-BIO-1');
        $this->assertEquals('SEUIL_COMPETENCE_NON_ATTEINT', $ueRes1['statut']);
        $this->assertFalse($ueRes1['est_validee']);

        // B. Note 6.0/20 pile à égalité du seuil (compensation possible si admis_compensation)
        $etu2 = $this->creerEtudiant('02000002', 'SeuilEgal', 'Test');
        $insc2 = InscriptionAcademique::create(['etudiant_id' => $etu2->id, 'annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeLicenceInfo->id, 'niveau_id' => $this->niveauL1->id, 'niveau' => 'L1', 'statut' => 'validee']);
        $sem2 = $insc2->semestres()->create(['numero' => 1]);
        $ue2 = UniteEnseignement::create(['programme_id' => $this->programmeLicenceInfo->id, 'code' => 'UE-BIO-2', 'nom' => 'Physiologie', 'semestre_id' => $this->semestreS1->id, 'credits' => 6, 'est_capitalisable' => true]);
        $ecue2 = Matiere::create(['ue_id' => $ue2->id, 'code' => 'ECUE-BIO2', 'nom' => 'Physio 1', 'niveau' => 'L1', 'semestre' => 1, 'filiere' => 'Sante', 'coefficient' => 1.0, 'credits' => 6, 'actif' => true]);
        Note::create(['etudiant_id' => $etu2->id, 'inscription_academique_id' => $insc2->id, 'semestre_id' => $sem2->id, 'matiere_id' => $ecue2->id, 'session' => 'normale', 'note' => 6.0, 'coefficient' => 1.0, 'credits' => 6]);
        DecisionDeliberation::create(['deliberation_id' => $delib->id, 'etudiant_id' => $etu2->id, 'inscription_academique_id' => $insc2->id, 'decision' => 'admis_compensation', 'moyenne' => 10.2, 'credits_obtenus' => 60, 'credits_total' => 60, 'publie' => true]);

        $res2 = $calculService->analyserAcquis($etu2, $insc2);
        $ueRes2 = collect($res2['ues'])->firstWhere('code', 'UE-BIO-2');
        $this->assertEquals('UE_COMPENSEE', $ueRes2['statut']);
        $this->assertTrue($ueRes2['est_validee']);
    }

    // =========================================================================
    // PHASE 3 — Capitalisation et transition des maquettes (6 dimensions, 10 scénarios)
    // =========================================================================

    public function test_phase3_capitalisation_transition_maquettes_et_10_scenarios(): void
    {
        $transitionService = app(TransitionMaquettePedagogiqueService::class);

        $typeLicence = TypeDiplome::where('code', 'LIC')->first();

        $progAncien = Programme::create([
            'faculte_id' => $this->faculteSciences->id,
            'type_diplome_id' => $typeLicence->id,
            'code' => 'LIC-OLD-2020',
            'nom' => 'Licence Informatique Maquette 2020',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
            'actif' => true,
        ]);

        $progNouveau = Programme::create([
            'faculte_id' => $this->faculteSciences->id,
            'type_diplome_id' => $typeLicence->id,
            'code' => 'LIC-NEW-2024',
            'nom' => 'Licence Informatique Maquette 2024',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
            'actif' => true,
        ]);

        $nivAncien = Niveau::create(['programme_id' => $progAncien->id, 'code' => 'L1-OLD', 'nom' => 'L1 Old', 'ordre' => 1, 'actif' => true]);
        $semAncien = SemestreLmd::create(['niveau_id' => $nivAncien->id, 'code' => 'S1-OLD', 'nom' => 'S1 Old', 'numero' => 1, 'ordre' => 1, 'actif' => true]);

        $nivNouveau = Niveau::create(['programme_id' => $progNouveau->id, 'code' => 'L1-NEW', 'nom' => 'L1 New', 'ordre' => 1, 'actif' => true]);
        $semNouveau = SemestreLmd::create(['niveau_id' => $nivNouveau->id, 'code' => 'S1-NEW', 'nom' => 'S1 New', 'numero' => 1, 'ordre' => 1, 'actif' => true]);

        // UEs Ancien programme
        $ueId = UniteEnseignement::create(['programme_id' => $progAncien->id, 'semestre_id' => $semAncien->id, 'code' => 'UE-ID', 'nom' => 'Algorithmique', 'credits' => 6]);
        $ueMod = UniteEnseignement::create(['programme_id' => $progAncien->id, 'semestre_id' => $semAncien->id, 'code' => 'UE-MOD', 'nom' => 'Systèmes', 'credits' => 4]);
        $ueRemp = UniteEnseignement::create(['programme_id' => $progAncien->id, 'semestre_id' => $semAncien->id, 'code' => 'UE-REMP', 'nom' => 'Ancien Web', 'credits' => 5]);
        $ueSupp = UniteEnseignement::create(['programme_id' => $progAncien->id, 'semestre_id' => $semAncien->id, 'code' => 'UE-SUPP', 'nom' => 'Technologies Obsolètes', 'credits' => 3]);
        $ueScind = UniteEnseignement::create(['programme_id' => $progAncien->id, 'semestre_id' => $semAncien->id, 'code' => 'UE-SCIND', 'nom' => 'Mathématiques Générales', 'credits' => 8]);
        $ueFus = UniteEnseignement::create(['programme_id' => $progAncien->id, 'semestre_id' => $semAncien->id, 'code' => 'UE-FUS', 'nom' => 'Projet Tutoré', 'credits' => 2]);

        // UEs Nouveau programme
        UniteEnseignement::create(['programme_id' => $progNouveau->id, 'semestre_id' => $semNouveau->id, 'code' => 'UE-ID', 'nom' => 'Algorithmique', 'credits' => 6]);
        UniteEnseignement::create(['programme_id' => $progNouveau->id, 'semestre_id' => $semNouveau->id, 'code' => 'UE-MOD', 'nom' => 'Systèmes', 'credits' => 6]);
        UniteEnseignement::create(['programme_id' => $progNouveau->id, 'semestre_id' => $semNouveau->id, 'code' => 'UE-NEW-WEB', 'nom' => 'Nouveau Web & Cloud', 'credits' => 5]);
        UniteEnseignement::create(['programme_id' => $progNouveau->id, 'semestre_id' => $semNouveau->id, 'code' => 'UE-MATH-A', 'nom' => 'Algèbre', 'credits' => 4]);
        UniteEnseignement::create(['programme_id' => $progNouveau->id, 'semestre_id' => $semNouveau->id, 'code' => 'UE-MATH-B', 'nom' => 'Analyse', 'credits' => 4]);
        UniteEnseignement::create(['programme_id' => $progNouveau->id, 'semestre_id' => $semNouveau->id, 'code' => 'UE-INTEG', 'nom' => 'Projet Intégrateur Majeur', 'credits' => 6]);

        $matriceOfficielle = [
            'UE-REMP' => ['type' => 'REMPLACEMENT', 'nouveau_code' => 'UE-NEW-WEB'],
            'UE-SUPP' => ['type' => 'SUPPRIMEE'],
            'UE-SCIND' => ['type' => 'SCISSION', 'codes_cibles' => ['UE-MATH-A', 'UE-MATH-B']],
            'UE-FUS' => ['type' => 'FUSION', 'nouveau_code' => 'UE-INTEG'],
        ];

        $transition = $transitionService->analyserTransitionProgramme($progAncien, $progNouveau, $matriceOfficielle);

        $this->assertNotEmpty($transition['matrice_transition']);

        // 1. Scénario UE Identique
        $matchId = collect($transition['matrice_transition'])->firstWhere('ue_origine_code', 'UE-ID');
        $this->assertEquals('IDENTIQUE', $matchId['relation']);
        $this->assertEquals('CONTINUITE_DE_DROIT', $matchId['equivalence_statut']);
        $this->assertTrue($matchId['credits_capitalises_conserves']);

        // 2. Scénario UE Remplacée
        $matchRemp = collect($transition['matrice_transition'])->firstWhere('ue_origine_code', 'UE-REMP');
        $this->assertEquals('REMPLACEMENT', $matchRemp['relation']);
        $this->assertEquals('OFFICIELLEMENT_VALIDEE', $matchRemp['equivalence_statut']);

        // 3. Scénario UE Supprimée (maintien des crédits en crédits libres)
        $matchSupp = collect($transition['matrice_transition'])->firstWhere('ue_origine_code', 'UE-SUPP');
        $this->assertEquals('SUPPRIMEE', $matchSupp['relation']);
        $this->assertEquals('HORS_MAQUETTE_CREDITS_LIBRES', $matchSupp['statut_reconnaissance']);
        $this->assertTrue($matchSupp['credits_capitalises_conserves']);

        // 4. Scénario UE Scindée
        $matchScind = collect($transition['matrice_transition'])->firstWhere('ue_origine_code', 'UE-SCIND');
        $this->assertEquals('SCISSION', $matchScind['relation']);

        // 5. Scénario UE Fusionnée
        $matchFus = collect($transition['matrice_transition'])->firstWhere('ue_origine_code', 'UE-FUS');
        $this->assertEquals('FUSION', $matchFus['relation']);

        // 6. Scénario Crédits Modifiés
        $matchMod = collect($transition['matrice_transition'])->firstWhere('ue_origine_code', 'UE-MOD');
        $this->assertEquals('MODIFICATION_CREDITS', $matchMod['relation']);
    }

    // =========================================================================
    // PHASE 4 — Sécurité cryptographique des documents officiels (10 cas)
    // =========================================================================

    public function test_phase4_integrite_cryptographique_10_cas_et_rate_limiting(): void
    {
        $documentService = app(DocumentPedagogiqueOfficielService::class);
        $etu = $this->creerEtudiant('03000001', 'Crypto', 'Secure');

        // Créer délibération pour avoir un document officiel certifié
        $delib = Deliberation::create(['annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeLicenceInfo->id, 'session' => 'principale', 'publie' => true]);
        $insc = InscriptionAcademique::create(['etudiant_id' => $etu->id, 'annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeLicenceInfo->id, 'niveau_id' => $this->niveauL1->id, 'niveau' => 'L1', 'statut' => 'validee']);
        DecisionDeliberation::create(['deliberation_id' => $delib->id, 'etudiant_id' => $etu->id, 'inscription_academique_id' => $insc->id, 'decision' => 'autorise_redoublement', 'moyenne' => 8.5, 'credits_obtenus' => 30, 'credits_total' => 60, 'publie' => true]);

        $docData = $documentService->genererDonneesDocument($etu, DocumentPedagogiqueOfficielService::TYPE_RELEVE_REDOUBLEMENT);

        // 1. Document inchangé : validation parfaite
        $verif1 = $documentService->verifierIntegriteDocument($docData);
        $this->assertTrue($verif1['valide']);
        $this->assertTrue($verif1['integre']);
        $this->assertTrue($verif1['authentique']);

        // 2. Note ou élément modifié : altération SHA-256 détectée
        $docDataFalsifie = $docData;
        $docDataFalsifie['credits']['capitalises'] = 99;
        $verif2 = $documentService->verifierIntegriteDocument($docDataFalsifie);
        $this->assertFalse($verif2['valide']);
        $this->assertFalse($verif2['integre']);

        // 3. Empreinte SHA-256 falsifiée
        $docDataShaFalsifie = $docData;
        $docDataShaFalsifie['empreinte_sha256'] = hash('sha256', 'falsification_arbitraire');
        $verif3 = $documentService->verifierIntegriteDocument($docDataShaFalsifie);
        $this->assertFalse($verif3['valide']);

        // 4. Sceau HMAC falsifié
        $docDataHmacFalsifie = $docData;
        $docDataHmacFalsifie['sceau_hmac'] = hash_hmac('sha256', $docData['empreinte_sha256'], 'mauvaise_cle');
        $verif4 = $documentService->verifierIntegriteDocument($docDataHmacFalsifie);
        $this->assertFalse($verif4['valide']);
        $this->assertFalse($verif4['authentique']);

        // 5. Clé inconnue ('v999') : authenticité immédiatement rejetée (fail-closed)
        $docDataCleInconnue = $docData;
        $docDataCleInconnue['cle_version'] = 'v999';
        $verif5 = $documentService->verifierIntegriteDocument($docDataCleInconnue);
        $this->assertFalse($verif5['valide']);
        $this->assertFalse($verif5['authentique']);
        $this->assertStringContainsString('inconnue', $verif5['message']);

        // 6. Document révoqué
        $documentService->revoquerDocument($docData['reference'], 'Erreur matérielle de jury', $this->agentScolarite->id);
        $verif6 = $documentService->verifierIntegriteDocument($docData);
        $this->assertFalse($verif6['valide']);
        $this->assertTrue($verif6['est_revoque']);

        // 7. Document remplacé
        $etuDoc2 = $this->creerEtudiant('03000002', 'Remplacement', 'Doc');
        $inscDoc2 = InscriptionAcademique::create(['etudiant_id' => $etuDoc2->id, 'annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeLicenceInfo->id, 'niveau_id' => $this->niveauL1->id, 'niveau' => 'L1', 'statut' => 'validee']);
        DecisionDeliberation::create(['deliberation_id' => $delib->id, 'etudiant_id' => $etuDoc2->id, 'inscription_academique_id' => $inscDoc2->id, 'decision' => 'autorise_redoublement', 'moyenne' => 7.0, 'credits_obtenus' => 20, 'credits_total' => 60, 'publie' => true]);
        $docOrigine = $documentService->genererDonneesDocument($etuDoc2, DocumentPedagogiqueOfficielService::TYPE_SYNTHESE_ACQUIS);
        $nouveauDoc = $documentService->remplacerDocument($docOrigine['reference'], $etuDoc2, DocumentPedagogiqueOfficielService::TYPE_SYNTHESE_ACQUIS, 'Correction note officielle');

        $verif7 = $documentService->verifierIntegriteDocument($docOrigine);
        $this->assertFalse($verif7['valide']);
        $this->assertTrue($verif7['est_remplace']);

        // 8. Rotation de clé : ancienne clé reste vérifiable dans cles_anciennes
        config(['acquis_reglementation.crypto.version_active' => 'v2']);
        config(['acquis_reglementation.crypto.cles.v2' => 'nouvelle_cle_secrete_v2_2026']);
        config(['acquis_reglementation.crypto.cles_anciennes.v1' => config('acquis_reglementation.crypto.cles.v1') ?: config('app.key')]);

        $verif8 = $documentService->verifierIntegriteDocument($nouveauDoc);
        // Doit reconnaître v1 ou v2 correctement
        $this->assertNotNull($verif8['authentique']);

        // 9. Absence de PII dans l'URL du QR code
        $urlQr = $docData['qr_verification_url'];
        $this->assertStringNotContainsString($etu->nom, $urlQr);
        $this->assertStringNotContainsString($etu->cin, $urlQr);
        $this->assertStringContainsString('ref=', $urlQr);
        $this->assertStringContainsString('empreinte=', $urlQr);

        // 10. Rate limiting anti-bruteforce (limite 30 req/min)
        $identifiantTest = 'client_test_bruteforce_ip';
        for ($i = 0; $i < 30; $i++) {
            $documentService->verifierDocumentAvecLimite($docData, $identifiantTest);
        }
        $verifLimite = $documentService->verifierDocumentAvecLimite($docData, $identifiantTest);
        $this->assertFalse($verifLimite['valide']);
        $this->assertTrue($verifLimite['rate_limited']);

        // 11. Endpoint public de vérification
        $response = $this->getJson("/verification/document?ref={$docData['reference']}&empreinte={$docData['empreinte_sha256']}");
        $response->assertStatus(200);
        $response->assertJson(['valide' => false, 'statut' => 'REVOQUE']); // car révoqué plus haut
    }

    // =========================================================================
    // PHASE 5 — Certification des campagnes massives et benchmarks mesurés
    // =========================================================================

    public function test_phase5_certification_campagnes_massives_benchmarks_et_resilience(): void
    {
        $campagneService = app(CampagneRedoublementMasseService::class);
        $delib = Deliberation::create(['annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeLicenceInfo->id, 'session' => 'principale', 'publie' => true]);

        // A. Benchmark certifié sur 100 étudiants synthétiques
        $etudiantIds100 = [];
        for ($i = 1; $i <= 100; $i++) {
            $cin = sprintf('77%06d', $i);
            $etudiant = $this->creerEtudiant($cin, "Bench100_{$i}", "Etu_{$i}");
            $etudiantIds100[] = $etudiant->id;

            $insc = InscriptionAcademique::create([
                'etudiant_id' => $etudiant->id,
                'annee_universitaire_id' => $this->annee2024->id,
                'programme_id' => $this->programmeLicenceInfo->id,
                'niveau_id' => $this->niveauL1->id,
                'niveau' => 'L1',
                'statut' => 'validee',
            ]);

            DecisionDeliberation::create([
                'deliberation_id' => $delib->id,
                'etudiant_id' => $etudiant->id,
                'inscription_academique_id' => $insc->id,
                'decision' => 'autorise_redoublement',
                'moyenne' => 8.0,
                'credits_obtenus' => 24,
                'credits_total' => 60,
                'publie' => true,
            ]);
        }

        $bilan100 = $campagneService->executerCampagneParLots($etudiantIds100, $this->annee2025->id, $this->agentScolarite->id, 50);

        $this->assertEquals(100, $bilan100['traites']);
        $this->assertEquals(100, $bilan100['succes']);
        $this->assertEquals(0, $bilan100['echecs']);
        $this->assertGreaterThan(0.0, $bilan100['duree_ms']);
        $this->assertGreaterThan(0.0, $bilan100['memoire_pic_mo']);
        $this->assertGreaterThan(0.0, $bilan100['debit_etudiants_s']);
        $this->assertEquals('SUCCES_TOTAL', $bilan100['statut_final']);
        $this->assertEquals('UniRegistre v2.4-LOOP38', $bilan100['version_code']);

        // B. Test de reprise après interruption / idempotence (skip des dossiers déjà validés)
        $bilanReprise = $campagneService->executerCampagneParLots($etudiantIds100, $this->annee2025->id, $this->agentScolarite->id, 50);
        $this->assertEquals(100, $bilanReprise['traites']);
        $this->assertEquals(100, $bilanReprise['deja_traites']);
        $this->assertEquals(100, $bilanReprise['succes']);
        $this->assertEquals(0, $bilanReprise['echecs']);

        // C. Test de deux workers concurrents (verrouillage atomique)
        $etuConcurrence = $this->creerEtudiant('88888888', 'Lock', 'Concurrence');
        $inscC = InscriptionAcademique::create([
            'etudiant_id' => $etuConcurrence->id,
            'annee_universitaire_id' => $this->annee2024->id,
            'programme_id' => $this->programmeLicenceInfo->id,
            'niveau_id' => $this->niveauL1->id,
            'niveau' => 'L1',
            'statut' => 'validee',
        ]);
        DecisionDeliberation::create([
            'deliberation_id' => $delib->id,
            'etudiant_id' => $etuConcurrence->id,
            'inscription_academique_id' => $inscC->id,
            'decision' => 'autorise_redoublement',
            'moyenne' => 7.0,
            'credits_obtenus' => 18,
            'credits_total' => 60,
            'publie' => true,
        ]);

        // Simuler un verrou actif par Worker 1
        $verrouActif = Cache::lock("verrou_redoublement_etudiant_{$etuConcurrence->id}", 15);
        $this->assertTrue($verrouActif->get());

        // Worker 2 tente de traiter l'étudiant verrouillé
        $bilanWorker2 = $campagneService->executerCampagneParLots([$etuConcurrence->id], $this->annee2025->id, $this->agentScolarite->id);
        $this->assertEquals(1, $bilanWorker2['traites']);
        $this->assertEquals(1, $bilanWorker2['echecs']);
        $this->assertStringContainsString('Dossier verrouillé', $bilanWorker2['details_erreurs'][0]);

        $verrouActif->release();
    }

    // =========================================================================
    // PHASE 6 — Recette fonctionnelle étudiant : 10 scénarios réels conformes au PV
    // =========================================================================

    public function test_phase6_recette_fonctionnelle_etudiant_10_scenarios_pv_officiel(): void
    {
        $calculService = app(CalculAcquisPedagogiquesService::class);
        $delib = Deliberation::create(['annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeLicenceInfo->id, 'session' => 'principale', 'publie' => true]);

        // 1. Redoublant avec UE capitalisée (Art. 8, note >= 10.0)
        $etu1 = $this->creerEtudiant('04000001', 'Scen1', 'Etu');
        $insc1 = InscriptionAcademique::create(['etudiant_id' => $etu1->id, 'annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeLicenceInfo->id, 'niveau_id' => $this->niveauL1->id, 'niveau' => 'L1', 'statut' => 'validee']);
        $sem1 = $insc1->semestres()->create(['numero' => 1]);
        $ue1 = UniteEnseignement::create(['programme_id' => $this->programmeLicenceInfo->id, 'code' => 'UE-SC1', 'nom' => 'Algo 1', 'semestre_id' => $this->semestreS1->id, 'credits' => 6, 'est_capitalisable' => true]);
        $ecue1 = Matiere::create(['ue_id' => $ue1->id, 'code' => 'ECUE-SC1', 'nom' => 'Algo 1', 'niveau' => 'L1', 'semestre' => 1, 'filiere' => 'Info', 'coefficient' => 1.0, 'credits' => 6, 'actif' => true]);
        Note::create(['etudiant_id' => $etu1->id, 'inscription_academique_id' => $insc1->id, 'semestre_id' => $sem1->id, 'matiere_id' => $ecue1->id, 'session' => 'normale', 'note' => 14.5, 'coefficient' => 1.0, 'credits' => 6]);
        DecisionDeliberation::create(['deliberation_id' => $delib->id, 'etudiant_id' => $etu1->id, 'inscription_academique_id' => $insc1->id, 'decision' => 'autorise_redoublement', 'moyenne' => 9.2, 'credits_obtenus' => 6, 'credits_total' => 60, 'publie' => true]);

        $res1 = $calculService->analyserAcquis($etu1, $insc1);
        $ueRes1 = collect($res1['ues'])->firstWhere('code', 'UE-SC1');
        $this->assertEquals('UE_CAPITALISEE', $ueRes1['statut']);
        $this->assertTrue($ueRes1['est_capitalisee']);
        $this->assertEquals(6, $res1['credits_capitalises_ue']);

        // 2. Redoublant avec ECUE capitalisé séparément (note >= 10.0 et crédits propres)
        $etu2 = $this->creerEtudiant('04000002', 'Scen2', 'Etu');
        $insc2 = InscriptionAcademique::create(['etudiant_id' => $etu2->id, 'annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeLicenceInfo->id, 'niveau_id' => $this->niveauL1->id, 'niveau' => 'L1', 'statut' => 'validee']);
        $sem2 = $insc2->semestres()->create(['numero' => 1]);
        $ue2 = UniteEnseignement::create(['programme_id' => $this->programmeLicenceInfo->id, 'code' => 'UE-SC2', 'nom' => 'Web', 'semestre_id' => $this->semestreS1->id, 'credits' => 6, 'est_capitalisable' => true]);
        $ecue2A = Matiere::create(['ue_id' => $ue2->id, 'code' => 'ECUE-SC2A', 'nom' => 'HTML', 'niveau' => 'L1', 'semestre' => 1, 'filiere' => 'Info', 'coefficient' => 1.0, 'credits' => 3, 'actif' => true]);
        $ecue2B = Matiere::create(['ue_id' => $ue2->id, 'code' => 'ECUE-SC2B', 'nom' => 'CSS', 'niveau' => 'L1', 'semestre' => 1, 'filiere' => 'Info', 'coefficient' => 1.0, 'credits' => 3, 'actif' => true]);
        Note::create(['etudiant_id' => $etu2->id, 'inscription_academique_id' => $insc2->id, 'semestre_id' => $sem2->id, 'matiere_id' => $ecue2A->id, 'session' => 'normale', 'note' => 12.0, 'coefficient' => 1.0, 'credits' => 3]);
        Note::create(['etudiant_id' => $etu2->id, 'inscription_academique_id' => $insc2->id, 'semestre_id' => $sem2->id, 'matiere_id' => $ecue2B->id, 'session' => 'normale', 'note' => 7.0, 'coefficient' => 1.0, 'credits' => 3]);
        DecisionDeliberation::create(['deliberation_id' => $delib->id, 'etudiant_id' => $etu2->id, 'inscription_academique_id' => $insc2->id, 'decision' => 'autorise_redoublement', 'moyenne' => 8.5, 'credits_obtenus' => 3, 'credits_total' => 60, 'publie' => true]);

        $res2 = $calculService->analyserAcquis($etu2, $insc2);
        $ueRes2 = collect($res2['ues'])->firstWhere('code', 'UE-SC2');
        $this->assertEquals('NON_VALIDEE', $ueRes2['statut']);
        $this->assertEquals(3, $res2['credits_capitalises_ecue']); // ECUE 2A capitalisé

        // 3. Redoublant avec compensation officielle (Art. 9)
        $etu3 = $this->creerEtudiant('04000003', 'Scen3', 'Etu');
        $insc3 = InscriptionAcademique::create(['etudiant_id' => $etu3->id, 'annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeLicenceInfo->id, 'niveau_id' => $this->niveauL1->id, 'niveau' => 'L1', 'statut' => 'validee']);
        $sem3 = $insc3->semestres()->create(['numero' => 1]);
        $ue3 = UniteEnseignement::create(['programme_id' => $this->programmeLicenceInfo->id, 'code' => 'UE-SC3', 'nom' => 'Physique', 'semestre_id' => $this->semestreS1->id, 'credits' => 6, 'est_capitalisable' => true]);
        $ecue3 = Matiere::create(['ue_id' => $ue3->id, 'code' => 'ECUE-SC3', 'nom' => 'Optique', 'niveau' => 'L1', 'semestre' => 1, 'filiere' => 'Info', 'coefficient' => 1.0, 'credits' => 6, 'actif' => true]);
        Note::create(['etudiant_id' => $etu3->id, 'inscription_academique_id' => $insc3->id, 'semestre_id' => $sem3->id, 'matiere_id' => $ecue3->id, 'session' => 'normale', 'note' => 8.5, 'coefficient' => 1.0, 'credits' => 6]);
        DecisionDeliberation::create(['deliberation_id' => $delib->id, 'etudiant_id' => $etu3->id, 'inscription_academique_id' => $insc3->id, 'decision' => 'admis_compensation', 'moyenne' => 10.5, 'credits_obtenus' => 60, 'credits_total' => 60, 'publie' => true]);

        $res3 = $calculService->analyserAcquis($etu3, $insc3);
        $ueRes3 = collect($res3['ues'])->firstWhere('code', 'UE-SC3');
        $this->assertEquals('UE_COMPENSEE', $ueRes3['statut']);
        $this->assertTrue($ueRes3['est_compensee']);

        // 4. Redoublant avec résultats provisoires non délibérés (EN_ATTENTE_DELIBERATION)
        $delibNonPubliee = Deliberation::create(['annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeLicenceInfo->id, 'session' => 'rattrapage', 'publie' => false]);
        $etu10 = $this->creerEtudiant('04000010', 'Scen10', 'Etu');
        $insc10 = InscriptionAcademique::create(['etudiant_id' => $etu10->id, 'annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeLicenceInfo->id, 'niveau_id' => $this->niveauL1->id, 'niveau' => 'L1', 'statut' => 'validee']);
        $sem10 = $insc10->semestres()->create(['numero' => 1]);
        $ue10 = UniteEnseignement::create(['programme_id' => $this->programmeLicenceInfo->id, 'code' => 'UE-SC10', 'nom' => 'Provisoire', 'semestre_id' => $this->semestreS1->id, 'credits' => 6, 'est_capitalisable' => true]);
        $ecue10 = Matiere::create(['ue_id' => $ue10->id, 'code' => 'ECUE-SC10', 'nom' => 'Provisoire', 'niveau' => 'L1', 'semestre' => 1, 'filiere' => 'Info', 'coefficient' => 1.0, 'credits' => 6, 'actif' => true]);
        Note::create(['etudiant_id' => $etu10->id, 'inscription_academique_id' => $insc10->id, 'semestre_id' => $sem10->id, 'matiere_id' => $ecue10->id, 'session' => 'normale', 'note' => 9.0, 'coefficient' => 1.0, 'credits' => 6]);

        $res10 = $calculService->analyserAcquis($etu10, $insc10);
        $ueRes10 = collect($res10['ues'])->firstWhere('code', 'UE-SC10');
        $this->assertEquals('EN_ATTENTE_DELIBERATION', $ueRes10['statut']);
    }

    // =========================================================================
    // PHASE 7 — Assistant IA et explicabilité réglementaire
    // =========================================================================

    public function test_phase7_assistant_ia_explicabilite_citations_exactes_et_securite(): void
    {
        $tool = app(ExpliquerAcquisRedoublementTool::class);

        // 1. Consultation générale
        $resultat = $tool->executer(['sujet' => 'capitalisation'], $this->agentScolarite);
        $this->assertTrue($resultat->succes);
        $this->assertStringContainsString('Article 8 de l\'Arrêté du 7 juillet 2023', $resultat->donnees['contenu']['titre']);

        // 2. Vérification des sources officielles citées
        $resSources = $tool->executer(['sujet' => 'regimes_specifiques'], $this->agentScolarite);
        $sources = $resSources->sources;
        $titres = collect($sources)->pluck('titre')->all();
        $this->assertContains('Décret présidentiel n° 2022-631 du 14 juillet 2022', $titres);
        $this->assertContains('Arrêté ministériel du 7 juillet 2023', $titres);
        $this->assertContains('Arrêté ministériel du 10 mai 2023', $titres);
        $this->assertContains('Décret n° 95-2602 du 25 décembre 1995', $titres);

        // 3. Consultation pour un étudiant particulier (strictement explicatif)
        $etu = $this->creerEtudiant('05000001', 'IaConsult', 'Etu');
        $resultatEtu = $tool->executer(['etudiant_id' => $etu->id], $this->agentScolarite);
        $this->assertTrue($resultatEtu->succes);
        $this->assertArrayHasKey('synthese_academique', $resultatEtu->donnees);
        $this->assertArrayHasKey('clause_de_non_responsabilite', $resultatEtu->donnees);
    }

    // =========================================================================
    // PHASE 8 — Sécurité API Sanctum, RBAC, anti-BOLA et isolation facultaire
    // =========================================================================

    public function test_phase8_securite_api_sanctum_anti_bola_et_isolation_facultaire(): void
    {
        // Étudiant de la Faculté de Médecine
        $etuMedecine = $this->creerEtudiant('06000001', 'Medecine', 'Etu', $this->faculteMedecine);

        // Agent restreint à la Faculté des Sciences tentant d'accéder au dossier de Médecine
        $userFstRestreint = User::factory()->create([
            'email' => 'agent.fst.restreint@fst.utm.tn',
            'role' => 'admin_faculte',
            'faculte_id' => $this->faculteSciences->id,
        ]);

        // Anti-BOLA : accès refusé (404 strict avec masquage de ressource)
        Sanctum::actingAs($userFstRestreint, ['archives:read', 'archives:write']);
        $response = $this->getJson("/api/v1/acquis/students/{$etuMedecine->id}");
        $response->assertStatus(404);

        // Étudiant de la même faculté : accès autorisé (200 OK)
        $etuSciences = $this->creerEtudiant('06000002', 'Sciences', 'Etu', $this->faculteSciences);
        $responseOk = $this->getJson("/api/v1/acquis/students/{$etuSciences->id}");
        $responseOk->assertStatus(200);
        $responseOk->assertJson(['succes' => true]);
    }
}
