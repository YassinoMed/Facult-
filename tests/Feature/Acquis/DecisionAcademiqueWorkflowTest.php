<?php

namespace Tests\Feature\Acquis;

use App\Models\AnneeUniversitaire;
use App\Models\DocumentAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\User;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\ParcoursAcademique\Models\ParcoursDispense;
use App\Modules\ParcoursAcademique\Models\ParcoursPlan;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\TypeDiplome;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Services\Acquis\ControlePrerequisService;
use App\Services\Acquis\DocumentPedagogiqueOfficielService;
use App\Services\Acquis\GestionAnomaliesAcademiquesService;
use App\Services\Acquis\GestionnaireCreditsService;
use App\Services\Acquis\GestionStatutsAcademiquesService;
use App\Services\Acquis\HistoriqueTentativesService;
use App\Services\Acquis\MoteurDecisionAcademiqueService;
use App\Services\Acquis\PlanRedoublementService;
use App\Services\Acquis\SimulationRedoublementService;
use App\Services\Acquis\ValidationApprobationPlanService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * Suite de tests exhaustive du Moteur Central de Décision Académique et Workflows Détaillés (LOOP 16 à 25).
 * Conforme au Décret présidentiel n° 2022-631 et à l'Arrêté du 7 juillet 2023.
 */
class DecisionAcademiqueWorkflowTest extends TestCase
{
    use ActAsScolarite;
    use RefreshDatabase;

    private Faculte $faculte;

    private TypeDiplome $typeLicence;

    private Programme $programmeLicence;

    private Niveau $niveauL1;

    private SemestreLmd $semestre1;

    private SemestreLmd $semestre2;

    private AnneeUniversitaire $annee2025;

    private AnneeUniversitaire $annee2026;

    private MoteurDecisionAcademiqueService $moteurDecision;

    private GestionStatutsAcademiquesService $statutsService;

    private GestionnaireCreditsService $creditsService;

    private HistoriqueTentativesService $tentativesService;

    private ControlePrerequisService $prerequisService;

    private SimulationRedoublementService $simulationService;

    private ValidationApprobationPlanService $validationService;

    private DocumentPedagogiqueOfficielService $documentService;

    private GestionAnomaliesAcademiquesService $anomaliesService;

    private PlanRedoublementService $planService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences Économiques et de Gestion',
            'code' => 'FSEGT',
            'description' => 'Campus Universitaire El Manar',
            'active' => true,
        ]);

        $this->typeLicence = TypeDiplome::firstOrCreate(
            ['code' => 'L'],
            ['nom' => 'Licence Nationale LMD', 'cycle' => 'premier_cycle', 'actif' => true]
        );

        $this->programmeLicence = Programme::create([
            'faculte_id' => $this->faculte->id,
            'type_diplome_id' => $this->typeLicence->id,
            'code' => 'LIC-ECO',
            'nom' => 'Licence en Sciences Économiques',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 60,
            'actif' => true,
        ]);

        $this->niveauL1 = Niveau::create([
            'programme_id' => $this->programmeLicence->id,
            'code' => 'L1',
            'nom' => 'Licence 1ère Année',
            'ordre' => 1,
            'actif' => true,
        ]);

        $this->semestre1 = SemestreLmd::create([
            'niveau_id' => $this->niveauL1->id,
            'code' => 'S1',
            'nom' => 'Semestre 1',
            'numero' => 1,
            'ordre' => 1,
            'actif' => true,
        ]);

        $this->semestre2 = SemestreLmd::create([
            'niveau_id' => $this->niveauL1->id,
            'code' => 'S2',
            'nom' => 'Semestre 2',
            'numero' => 2,
            'ordre' => 2,
            'actif' => true,
        ]);

        $this->annee2025 = AnneeUniversitaire::create([
            'libelle' => '2025-2026',
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30',
            'active' => false,
        ]);

        $this->annee2026 = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->moteurDecision = app(MoteurDecisionAcademiqueService::class);
        $this->statutsService = app(GestionStatutsAcademiquesService::class);
        $this->creditsService = app(GestionnaireCreditsService::class);
        $this->tentativesService = app(HistoriqueTentativesService::class);
        $this->prerequisService = app(ControlePrerequisService::class);
        $this->simulationService = app(SimulationRedoublementService::class);
        $this->validationService = app(ValidationApprobationPlanService::class);
        $this->documentService = app(DocumentPedagogiqueOfficielService::class);
        $this->anomaliesService = app(GestionAnomaliesAcademiquesService::class);
        $this->planService = app(PlanRedoublementService::class);
    }

    private function creerEtudiant(string $cin = '09876543', string $nom = 'Ben Salem', string $prenom = 'Sami'): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => $nom,
            'prenom' => $prenom,
            'email' => strtolower($prenom.'.'.$nom).'@universite.test',
            'date_naissance' => '2004-03-15',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programmeLicence->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Campus Tunis',
            'ville' => 'Tunis',
            'code_postal' => '1002',
            'annee_bac' => '2023',
            'password' => 'Secret@123',
        ]);
        $etudiant->definirStatut('valide');

        return $etudiant;
    }

    private function creerInscription(Etudiant $etudiant, AnneeUniversitaire $annee): InscriptionAcademique
    {
        $insc = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $annee->id,
            'programme_id' => $this->programmeLicence->id,
            'niveau_id' => $this->niveauL1->id,
            'filiere' => 'Sciences Économiques',
            'niveau' => 'L1',
            'statut' => 'en_cours',
        ]);
        $insc->semestres()->create(['numero' => 1]);
        $insc->semestres()->create(['numero' => 2]);

        return $insc;
    }

    private function creerUe(string $code, string $nom, int $credits = 6, float $coeff = 3.0, ?SemestreLmd $semestre = null): UniteEnseignement
    {
        return UniteEnseignement::create([
            'programme_id' => $this->programmeLicence->id,
            'semestre_id' => ($semestre ?? $this->semestre1)->id,
            'code' => $code,
            'nom' => $nom,
            'credits' => $credits,
            'coefficient' => $coeff,
            'ordre' => 1,
            'actif' => true,
        ]);
    }

    private function creerEcue(UniteEnseignement $ue, string $code, string $nom, int $credits = 3, float $coeff = 1.5): Matiere
    {
        return Matiere::create([
            'code' => $code,
            'nom' => $nom,
            'coefficient' => $coeff,
            'credits' => $credits,
            'ordre' => 1,
            'niveau' => 'L1',
            'filiere' => 'Sciences Économiques',
            'semestre' => $ue->semestre?->numero ?? 1,
            'ue_id' => $ue->id,
            'actif' => true,
        ]);
    }

    private function publierDeliberation(Etudiant $etudiant, InscriptionAcademique $inscription, string $decision = 'autorise_redoublement', ?float $moyenne = 9.20): DecisionDeliberation
    {
        $delib = Deliberation::create([
            'annee_universitaire_id' => $inscription->annee_universitaire_id,
            'semestre' => 1,
            'session' => 'principale',
            'statut' => 'validee',
            'date_deliberation' => now()->toDateString(),
        ]);

        return DecisionDeliberation::create([
            'deliberation_id' => $delib->id,
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'moyenne' => $moyenne,
            'credits_obtenus' => 12,
            'credits_total' => 60,
            'ues_validees' => 2,
            'ues_total' => 10,
            'decision' => $decision,
            'publie' => true,
        ]);
    }

    private function creerNote(InscriptionAcademique $inscription, Matiere $matiere, float $note, string $session = 'normale', ?int $credits = null, ?float $coeff = null, bool $locked = false): Note
    {
        return Note::create([
            'etudiant_id' => $inscription->etudiant_id,
            'inscription_academique_id' => $inscription->id,
            'semestre_id' => $matiere->ue?->semestre_id ?? $this->semestre1->id,
            'matiere_id' => $matiere->id,
            'note' => $note,
            'coefficient' => $coeff ?? $matiere->coefficient ?? 1.5,
            'credits' => $credits ?? $matiere->credits ?? 3,
            'session' => $session,
            'locked_at' => $locked ? now() : null,
        ]);
    }

    // =========================================================================
    // LOOP 16 — MOTEUR CENTRAL DE DÉCISION & PRIORITÉS 1 À 8
    // =========================================================================

    public function test_loop_16_moteur_decision_priorites_1_a_8_et_zero_mutation(): void
    {
        $etudiant = $this->creerEtudiant('01122334', 'Gharbi', 'Leila');
        $insc = $this->creerInscription($etudiant, $this->annee2025);

        $ue1 = $this->creerUe('UE-ECO1', 'Microéconomie I', 6);
        $m1 = $this->creerEcue($ue1, 'MIC1', 'Théorie du Consommateur', 3, 1.5);
        $m2 = $this->creerEcue($ue1, 'MIC2', 'Théorie du Producteur', 3, 1.5);

        // Note m1 = 12, Note m2 = 14 -> Moyenne UE = 13.0 >= 10 (Capitalisation UE)
        $this->creerNote($insc, $m1, 12.0, 'normale', 3, 1.5, true);
        $this->creerNote($insc, $m2, 14.0, 'normale', 3, 1.5, true);

        $ue2 = $this->creerUe('UE-MATH1', 'Mathématiques I', 6);
        $m3 = $this->creerEcue($ue2, 'MAT1', 'Algèbre Linéaire', 3, 1.5);
        $m4 = $this->creerEcue($ue2, 'MAT2', 'Analyse Mathématique', 3, 1.5);

        // Note m3 = 11 (crédits propres = 3), Note m4 = 4 (< 6.0 : seuil éliminatoire)
        $this->creerNote($insc, $m3, 11.0, 'normale', 3, 1.5, true);
        $this->creerNote($insc, $m4, 4.0, 'normale', 3, 1.5, true);

        $this->publierDeliberation($etudiant, $insc, 'autorise_redoublement', 9.50);

        // Snapshot DB avant exécution (vérification zéro mutation)
        $nbNotesAvant = Note::count();
        $nbInscAvant = InscriptionAcademique::count();
        $nbDelibAvant = DecisionDeliberation::count();

        // Exécution du moteur de décision
        $decision = $this->moteurDecision->determinerDecision($etudiant);

        // Vérifications de structure conceptuelle (§16.4)
        $this->assertEquals($etudiant->id, $decision['etudiant_id']);
        $this->assertEquals('ANALYSE_TERMINEE', $decision['statut_analyse']);
        $this->assertTrue($decision['sources_valides']);
        $this->assertTrue($decision['decision_definitive']);
        $this->assertCount(2, $decision['unites']);

        // UE1 : Capitalisée de plein droit
        $ue1Res = collect($decision['unites'])->firstWhere('code', 'UE-ECO1');
        $this->assertEquals('CAPITALISEE', $ue1Res['statut']);
        $this->assertEquals(6, $ue1Res['credits_capitalises']);
        $this->assertFalse($ue1Res['reprise_requise']);

        // UE2 : Seuil éliminatoire bloquant la compensation
        $ue2Res = collect($decision['unites'])->firstWhere('code', 'UE-MATH1');
        $this->assertEquals('SEUIL_COMPETENCE_NON_ATTEINT', $ue2Res['statut']);
        $this->assertTrue($ue2Res['reprise_requise']);

        // Références des règles réglementaires citées
        $this->assertNotEmpty($decision['references_regles']);
        $this->assertStringContainsString('Art. 8', $decision['references_regles'][0]);
        $this->assertNotEmpty($decision['explications']);

        // Zéro mutation en base de données (§16.5)
        $this->assertEquals($nbNotesAvant, Note::count());
        $this->assertEquals($nbInscAvant, InscriptionAcademique::count());
        $this->assertEquals($nbDelibAvant, DecisionDeliberation::count());
    }

    public function test_loop_16_fail_closed_si_deliberation_non_publiee(): void
    {
        $etudiant = $this->creerEtudiant('02233445', 'Trabelsi', 'Amir');
        $insc = $this->creerInscription($etudiant, $this->annee2025);

        $ue = $this->creerUe('UE-HIST', 'Histoire Économique', 6);
        $m = $this->creerEcue($ue, 'HIS1', 'Histoire', 6, 3.0);
        $this->creerNote($insc, $m, 14.0, 'normale', 6, 3.0);

        // Délibération non publiée
        $delib = Deliberation::create(['annee_universitaire_id' => $insc->annee_universitaire_id, 'semestre' => 1, 'session' => 'principale', 'statut' => 'brouillon']);
        DecisionDeliberation::create([
            'deliberation_id' => $delib->id,
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $insc->id,
            'moyenne' => 14.0,
            'credits_obtenus' => 6,
            'credits_total' => 60,
            'ues_validees' => 1,
            'ues_total' => 1,
            'decision' => 'admis',
            'publie' => false, // Non publiée
        ]);

        $decision = $this->moteurDecision->determinerDecision($etudiant);

        $this->assertFalse($decision['sources_valides']);
        $this->assertFalse($decision['decision_definitive']);
        $this->assertEquals('ANALYSE_AVEC_ANOMALIES', $decision['statut_analyse']);
        $this->assertNotEmpty($decision['anomalies']);
    }

    // =========================================================================
    // LOOP 17 — GESTION DES STATUTS ACADÉMIQUES ET TRANSITIONS INTERDITES
    // =========================================================================

    public function test_loop_17_transitions_interdites_et_audit(): void
    {
        // 1. Transition interdite UE : EN_ATTENTE_DELIBERATION -> CAPITALISEE sans délibération
        $res1 = $this->statutsService->validerTransitionUe(
            GestionStatutsAcademiquesService::UE_EN_ATTENTE_DELIBERATION,
            GestionStatutsAcademiquesService::UE_CAPITALISEE,
            ['deliberation_publiee' => false]
        );
        $this->assertFalse($res1['autorise']);
        $this->assertStringContainsString('délibération officielle', $res1['motif_rejet']);

        // 2. Transition interdite UE : EQUIVALENCE_EN_ATTENTE -> CAPITALISEE sans décision
        $res2 = $this->statutsService->validerTransitionUe(
            GestionStatutsAcademiquesService::UE_EQUIVALENCE_EN_ATTENTE,
            GestionStatutsAcademiquesService::UE_CAPITALISEE,
            ['equivalence_validee' => false]
        );
        $this->assertFalse($res2['autorise']);

        // 3. Transition interdite UE : NON_VALIDEE -> CAPITALISEE par simple forçage
        $res3 = $this->statutsService->validerTransitionUe(
            GestionStatutsAcademiquesService::UE_NON_VALIDEE,
            GestionStatutsAcademiquesService::UE_CAPITALISEE,
            []
        );
        $this->assertFalse($res3['autorise']);

        // 4. Transition dossier : BROUILLON -> INSCRIPTION_PEDAGOGIQUE_CREEE direct (interdit)
        $etudiant = $this->creerEtudiant('03344556', 'Bouazizi', 'Nadia');
        $plan = ParcoursPlan::create([
            'etudiant_id' => $etudiant->id,
            'objectif' => 'Plan de test',
            'contenu' => ['anomalies' => []],
            'statut' => 'brouillon',
        ]);

        $this->expectException(DomainException::class);
        $this->statutsService->transitionnerDossier(
            $plan,
            GestionStatutsAcademiquesService::DOSSIER_INSCRIPTION_CREEE,
            null,
            'Tentative directe'
        );
    }

    public function test_loop_17_transition_valide_et_trace_audit(): void
    {
        $etudiant = $this->creerEtudiant('04455667', 'Kacem', 'Walid');
        $agent = User::factory()->create();

        $plan = ParcoursPlan::create([
            'etudiant_id' => $etudiant->id,
            'objectif' => 'Plan de redoublement',
            'contenu' => ['anomalies' => []],
            'statut' => 'brouillon',
        ]);

        // Transition légale : BROUILLON -> ANALYSE_EN_COURS
        $this->statutsService->transitionnerDossier(
            $plan,
            GestionStatutsAcademiquesService::DOSSIER_ANALYSE_EN_COURS,
            $agent->id,
            'Lancement de l\'audit des acquis'
        );

        $this->assertEquals('analyse_en_cours', $plan->fresh()->statut);

        // Vérification de la trace d'audit dans HistoriqueAcademique
        $trace = HistoriqueAcademique::where('etudiant_id', $etudiant->id)
            ->where('action', 'transition_statut_redoublement')
            ->first();

        $this->assertNotNull($trace);
        $this->assertEquals($agent->id, $trace->utilisateur_id);
        $this->assertEquals('BROUILLON', $trace->ancienne_valeur);
        $this->assertStringContainsString('ANALYSE_EN_COURS', $trace->nouvelle_valeur);
    }

    // =========================================================================
    // LOOP 18 — PRÉVENTION DES DOUBLONS & SCÉNARIOS A, B, C, D, E
    // =========================================================================

    public function test_loop_18_scenarios_a_b_c_d_e_gestion_credits(): void
    {
        $etudiant = $this->creerEtudiant('05566778', 'Hamdi', 'Youssef');
        $insc = $this->creerInscription($etudiant, $this->annee2025);

        // Scénario A : UE avec crédits validée
        $ue1 = $this->creerUe('UE-A', 'Comptabilité', 6);
        $m1 = $this->creerEcue($ue1, 'CPT1', 'Compta Générale', 6, 3.0);
        $this->creerNote($insc, $m1, 15.0, 'normale', 6, 3.0, true);

        // Scénario B : ECUE avec crédits propres validé au sein d'une UE non acquise
        $ue2 = $this->creerUe('UE-B', 'Statistiques', 6);
        $m2 = $this->creerEcue($ue2, 'STA1', 'Stats Descriptives', 3, 1.5);
        $m3 = $this->creerEcue($ue2, 'STA2', 'Probabilités', 3, 1.5);
        $this->creerNote($insc, $m2, 12.0, 'normale', 3, 1.5, true);
        $this->creerNote($insc, $m3, 7.0, 'normale', 3, 1.5, true);

        $this->publierDeliberation($etudiant, $insc, 'autorise_redoublement', 10.5);

        // Audit des crédits
        $audit = $this->creditsService->auditerCreditsEtudiant($etudiant, $this->programmeLicence, $insc);

        // Vérification Scénario A & B : Total = 6 (UE1) + 3 (ECUE m2) = 9 ECTS
        $this->assertEquals(6, $audit['credits_capitalises_ue']);
        $this->assertEquals(3, $audit['credits_capitalises_ecue']);
        $this->assertEquals(9, $audit['total_credits_acquis']);
        $this->assertEmpty($audit['doublons_detectes']);

        // Scénario E : Idempotence face au calcul répété
        $this->assertTrue($audit['idempotence_validee']);

        // Scénario D : Rejet fail-closed si discordance de crédits avec la maquette officielle
        $this->expectException(DomainException::class);
        $this->creditsService->validerCreditsPv($this->programmeLicence, ['credits_total' => 80]); // Maquette = 60
    }

    // =========================================================================
    // LOOP 19 — GESTION DES NOUVELLES TENTATIVES SANS ÉCRASEMENT
    // =========================================================================

    public function test_loop_19_nouvelles_tentatives_et_regle_meilleure_note(): void
    {
        $etudiant = $this->creerEtudiant('06677889', 'Masmoudi', 'Rania');
        $insc = $this->creerInscription($etudiant, $this->annee2025);

        $ue = $this->creerUe('UE-DROIT', 'Droit des Affaires', 6);
        $matiere = $this->creerEcue($ue, 'DRO1', 'Droit Commercial', 6, 3.0);

        // 1. Session normale : 8.0/20
        $note1 = $this->tentativesService->enregistrerTentative($etudiant, $matiere, 8.0, 'normale');
        $this->assertEquals(8.0, $note1->note);

        // 2. Session rattrapage : 13.5/20
        $note2 = $this->tentativesService->enregistrerTentative($etudiant, $matiere, 13.5, 'rattrapage');
        $this->assertEquals(13.5, $note2->note);

        // Vérification non-écrasement : 2 notes conservées en base
        $historique = $this->tentativesService->recupererHistoriqueTentatives($etudiant, $matiere->id);
        $this->assertCount(2, $historique);

        // 3. Détermination de la note retenue (LMD Licence : Art. 14 Arrêté 2023 -> Meilleure note)
        $regimeLmd = ['regle_calcul_rattrapage' => 'MEILLEURE_NOTE'];
        $arbitrage = $this->tentativesService->determinerNoteRetenue($etudiant, $matiere, $regimeLmd);

        $this->assertEquals(13.5, $arbitrage['note_retenue']);
        $this->assertEquals('rattrapage', $arbitrage['session_retenue']);
        $this->assertStringContainsString('Art. 14', $arbitrage['regle_appliquee']);

        // Si la session de rattrapage avait été inférieure à la normale (ex: normale = 9.0, rattrapage = 6.0), la note normale doit être préservée
        $matiere2 = $this->creerEcue($ue, 'DRO2', 'Droit des Sociétés', 6, 3.0);
        $this->tentativesService->enregistrerTentative($etudiant, $matiere2, 9.0, 'normale');
        $this->tentativesService->enregistrerTentative($etudiant, $matiere2, 6.0, 'rattrapage');

        $arbitrage2 = $this->tentativesService->determinerNoteRetenue($etudiant, $matiere2, $regimeLmd);
        $this->assertEquals(9.0, $arbitrage2['note_retenue']);
        $this->assertEquals('normale', $arbitrage2['session_retenue']);
    }

    // =========================================================================
    // LOOP 20 — GESTION DES PRÉREQUIS ET DÉPENDANCES PÉDAGOGIQUES
    // =========================================================================

    public function test_loop_20_prerequis_cas_a_b_d_e_et_garde_fou_serveur(): void
    {
        $etudiant = $this->creerEtudiant('07788990', 'Jlassi', 'Ines');
        $insc = $this->creerInscription($etudiant, $this->annee2025);

        $ue = $this->creerUe('UE-ALGO', 'Algorithmique', 6);
        $algo1 = $this->creerEcue($ue, 'ALG1', 'Algorithmique I', 3, 1.5);
        $algo2 = $this->creerEcue($ue, 'ALG2', 'Algorithmique II', 3, 1.5);

        // ALG2 requiert ALG1
        $algo2->prerequis()->attach($algo1->id);

        // CAS B : Prérequis non acquis (Note ALG1 = 5.0/20)
        $this->creerNote($insc, $algo1, 5.0, 'normale');

        $verifB = $this->prerequisService->verifierEligibiliteMatiere($etudiant, $algo2);
        $this->assertFalse($verifB['eligible']);
        $this->assertEquals('CAS_B_NON_ACQUIS', $verifB['cas']);

        // Garde-fou serveur : lève une exception bloquante
        $this->expectException(DomainException::class);
        $this->prerequisService->exigerPrerequisSatisfaits($etudiant, $algo2);
    }

    public function test_loop_20_prerequis_satisfait_par_dispense_officielle(): void
    {
        $etudiant = $this->creerEtudiant('08899001', 'Baccouche', 'Mehdi');
        $ue = $this->creerUe('UE-BD', 'Bases de Données', 6);
        $bd1 = $this->creerEcue($ue, 'BD1', 'Bases de Données I', 3, 1.5);
        $bd2 = $this->creerEcue($ue, 'BD2', 'Bases de Données II', 3, 1.5);
        $bd2->prerequis()->attach($bd1->id);

        // CAS D : Prérequis dispensé officiellement
        ParcoursDispense::create([
            'etudiant_id' => $etudiant->id,
            'matiere_id' => $bd1->id,
            'cible_libelle' => 'Bases de Données I',
            'motif' => 'Validation d\'acquis professionnels',
            'decision' => 'validee',
            'statut' => 'validee',
        ]);

        $verifD = $this->prerequisService->verifierEligibiliteMatiere($etudiant, $bd2);
        $this->assertTrue($verifD['eligible']);
        $this->assertEquals('CAS_D_DISPENSE', $verifD['cas']);
    }

    // =========================================================================
    // LOOP 21 — SIMULATION AVANT VALIDATION & DÉTERMINISME
    // =========================================================================

    public function test_loop_21_simulation_individuelle_et_cohorte_deterministe(): void
    {
        $etudiant1 = $this->creerEtudiant('09900112', 'Mejri', 'Bilel');
        $insc1 = $this->creerInscription($etudiant1, $this->annee2025);
        $ue = $this->creerUe('UE-GES', 'Gestion', 6);
        $m = $this->creerEcue($ue, 'GES1', 'Comptabilité Analytique', 6, 3.0);
        $this->creerNote($insc1, $m, 12.0, 'normale', 6, 3.0, true);
        $this->publierDeliberation($etudiant1, $insc1, 'autorise_redoublement', 10.0);

        // Simulation individuelle
        $sim1 = $this->simulationService->simulerIndividuel($etudiant1);
        $this->assertEquals('INDIVIDUEL', $sim1['mode']);
        $this->assertTrue($sim1['est_validable']);
        $this->assertNotEmpty($sim1['hash_determinisme']);

        // Répétition pour tester le strict déterminisme
        $sim2 = $this->simulationService->simulerIndividuel($etudiant1);
        $this->assertEquals($sim1['hash_determinisme'], $sim2['hash_determinisme']);

        // Simulation de cohorte
        $simCohorte = $this->simulationService->simulerCohorte([$etudiant1->id]);
        $this->assertEquals('COHORTE', $simCohorte['mode']);
        $this->assertEquals(1, $simCohorte['effectif_total']);
        $this->assertEquals(1, $simCohorte['synthese']['etudiants_validables']);
    }

    // =========================================================================
    // LOOP 22 — VALIDATION ADMINISTRATIVE ET PRINCIPE DES 4 YEUX
    // =========================================================================

    public function test_loop_22_principe_des_4_yeux_et_interdiction_auto_approbation(): void
    {
        $etudiant = $this->creerEtudiant('10011223', 'Oueslati', 'Kais');
        $insc = $this->creerInscription($etudiant, $this->annee2025);
        $this->publierDeliberation($etudiant, $insc, 'autorise_redoublement', 9.0);

        $agentInitiateur = User::factory()->create();
        $agentSecondaire = User::factory()->create();

        // Ajout d'une dispense (qui déclenche la règle des 4 yeux)
        $ue = $this->creerUe('UE-DISP', 'Unité Dispensée', 6);
        ParcoursDispense::create([
            'etudiant_id' => $etudiant->id,
            'ue_id' => $ue->id,
            'cible_libelle' => 'Unité Dispensée',
            'motif' => 'Validation d\'études antérieures',
            'statut' => 'validee',
        ]);

        $plan = ParcoursPlan::create([
            'etudiant_id' => $etudiant->id,
            'inscription_source_id' => $insc->id,
            'objectif' => 'Plan avec clause sensible 4 yeux',
            'contenu' => ['anomalies' => []],
            'statut' => 'en_validation',
            'auteur_id' => $agentInitiateur->id,
        ]);

        // Auto-approbation par l'initiateur : rejet strict avec DomainException
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Auto-approbation strictement interdite');
        $this->validationService->validerPlan($plan, $agentInitiateur->id);
    }

    public function test_loop_22_approbation_reussie_par_second_acteur(): void
    {
        $etudiant = $this->creerEtudiant('11122334', 'Zouari', 'Meriem');
        $insc = $this->creerInscription($etudiant, $this->annee2025);
        $this->publierDeliberation($etudiant, $insc, 'autorise_redoublement', 10.0);

        $agentInitiateur = User::factory()->create();
        $agentSecondaire = User::factory()->create();

        $plan = ParcoursPlan::create([
            'etudiant_id' => $etudiant->id,
            'inscription_source_id' => $insc->id,
            'objectif' => 'Plan standard',
            'contenu' => ['anomalies' => []],
            'statut' => 'brouillon',
            'auteur_id' => $agentInitiateur->id,
        ]);

        // Approbation par un second acteur distinct
        $planValide = $this->validationService->validerPlan($plan, $agentSecondaire->id, 'Accord conforme scolarité');
        $this->assertEquals('valide', $planValide->statut);

        // Activation et création de la réinscription ciblée
        $nouvelleInsc = $this->creerInscription($etudiant, $this->annee2026);
        $res = $this->validationService->activerPlanEtCreerInscription($planValide, $nouvelleInsc, $agentSecondaire->id);

        $this->assertEquals('inscription_pedagogique_creee', $planValide->fresh()->statut);
        $this->assertArrayHasKey('elements_reinscrits', $res);
    }

    // =========================================================================
    // LOOP 23 — GÉNÉRATION DES DOCUMENTS OFFICIELS & EMPREINTE SHA256
    // =========================================================================

    public function test_loop_23_generation_documents_officiels_et_empreinte_sha256(): void
    {
        $etudiant = $this->creerEtudiant('12233445', 'Triki', 'Hichem');
        $insc = $this->creerInscription($etudiant, $this->annee2025);
        $this->publierDeliberation($etudiant, $insc, 'autorise_redoublement', 11.0);

        $plan = ParcoursPlan::create([
            'etudiant_id' => $etudiant->id,
            'inscription_source_id' => $insc->id,
            'objectif' => 'Plan officiel',
            'contenu' => ['anomalies' => []],
            'statut' => 'valide',
        ]);

        $agent = User::factory()->create();

        // 1. Génération des données de relevé de redoublement
        $docData = $this->documentService->genererDonneesDocument(
            $etudiant,
            DocumentPedagogiqueOfficielService::TYPE_RELEVE_REDOUBLEMENT,
            $plan
        );

        $this->assertTrue($docData['est_officiel']);
        $this->assertStringContainsString('DOCUMENT OFFICIEL CERTIFIÉ', $docData['mention_juridique']);
        $this->assertNotEmpty($docData['reference']);
        $this->assertNotEmpty($docData['empreinte_sha256']);
        $this->assertEquals(64, strlen($docData['empreinte_sha256'])); // Hash SHA256

        // 2. Persistance dans le registre des documents académiques
        $doc = $this->documentService->persisterDocumentOfficiel(
            $etudiant,
            DocumentPedagogiqueOfficielService::TYPE_RELEVE_REDOUBLEMENT,
            $plan,
            $agent->id
        );

        $this->assertInstanceOf(DocumentAcademique::class, $doc);
        $this->assertEquals($docData['empreinte_sha256'], $doc->hash_fichier);
        $this->assertEquals('releve_redoublement', $doc->type_document);
    }

    // =========================================================================
    // LOOP 24 — DÉTECTION ET CORRECTION AUDITÉE DES ANOMALIES
    // =========================================================================

    public function test_loop_24_detection_et_correction_auditee_anomalies(): void
    {
        $etudiant = $this->creerEtudiant('13344556', 'Abdelkader', 'Sarra');
        $insc = $this->creerInscription($etudiant, $this->annee2025);

        $ue = $this->creerUe('UE-MARK', 'Marketing', 6);
        $m = $this->creerEcue($ue, 'MRK1', 'Principes de Marketing', 6, 3.0);

        // Note éliminatoire (< 6.0)
        $note = $this->creerNote($insc, $m, 4.5, 'normale', 6, 3.0);

        // Détection des anomalies
        $anomalies = $this->anomaliesService->detecterAnomalies($etudiant);
        $this->assertNotEmpty($anomalies);
        $hasEliminatoire = collect($anomalies)->contains(fn ($a) => $a['code'] === 'SEUIL_ELIMINATOIRE_ATTEINT');
        $this->assertTrue($hasEliminatoire);

        // Correction auditée de la note suite à rectification de PV (de 4.5 à 12.0)
        $agent = User::factory()->create();
        $resCorrection = $this->anomaliesService->corrigerNoteAvecAudit(
            $note,
            12.0,
            $agent->id,
            'Rectification d\'erreur matérielle de saisie',
            'PV-RECTIF-2026-08'
        );

        $this->assertTrue($resCorrection['succes']);
        $this->assertEquals(4.5, $resCorrection['ancienne_valeur']);
        $this->assertEquals(12.0, $resCorrection['nouvelle_valeur']);

        // Vérification de la note mise à jour et de l'historique d'audit
        $this->assertEquals(12.0, (float) $note->fresh()->note);
        $trace = HistoriqueAcademique::where('etudiant_id', $etudiant->id)
            ->where('action', 'rectification_officielle_note')
            ->first();

        $this->assertNotNull($trace);
        $this->assertStringContainsString('PV-RECTIF-2026-08', $trace->nouvelle_valeur);
    }

    // =========================================================================
    // LOOP 25 — RECETTE HTTP DES NOUVEAUX ENDPOINTS DE GESTION
    // =========================================================================

    public function test_loop_25_recette_http_endpoints(): void
    {
        $this->scolarite('super_admin');

        $etudiant = $this->creerEtudiant('14455667', 'Naffati', 'Zied');
        $insc = $this->creerInscription($etudiant, $this->annee2025);
        $this->publierDeliberation($etudiant, $insc, 'autorise_redoublement', 10.0);

        // 1. Endpoint Document JSON (LOOP 23)
        $responseDoc = $this->get(route('admin.redoublement.document', [
            'etudiant' => $etudiant->id,
            'type' => 'releve_redoublement',
        ]));
        $responseDoc->assertOk();
        $responseDoc->assertJsonStructure(['reference', 'empreinte_sha256', 'etudiant', 'credits']);

        // 2. Endpoint Simulation Cohorte (LOOP 21)
        $responseCohorte = $this->post(route('admin.redoublement.simuler-cohorte'), [
            'etudiant_ids' => [$etudiant->id],
        ]);
        $responseCohorte->assertOk();
        $responseCohorte->assertJsonFragment(['mode' => 'COHORTE']);

        // 3. Endpoint Correction de Note (LOOP 24)
        $ue = $this->creerUe('UE-FIN', 'Finance', 6);
        $m = $this->creerEcue($ue, 'FIN1', 'Finance de Marché', 6, 3.0);
        $note = $this->creerNote($insc, $m, 8.0, 'normale', 6, 3.0);

        $responseCorrection = $this->post(route('admin.redoublement.corriger-note', ['etudiant' => $etudiant->id]), [
            'note_id' => $note->id,
            'nouvelle_note' => 11.5,
            'motif' => 'Erreur matérielle rectifiée',
            'pv_reference' => 'PV-RECTIF-001',
        ]);
        $responseCorrection->assertRedirect(route('admin.redoublement.show', $etudiant));
        $this->assertEquals(11.5, (float) $note->fresh()->note);
    }
}
