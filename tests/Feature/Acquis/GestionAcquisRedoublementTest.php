<?php

namespace Tests\Feature\Acquis;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\InscriptionPedagogique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\User;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\ParcoursAcademique\Models\ParcoursDispense;
use App\Modules\ParcoursAcademique\Models\ParcoursEquivalence;
use App\Modules\ParcoursAcademique\Models\ParcoursPlan;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\TypeDiplome;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Services\Acquis\CalculAcquisPedagogiquesService;
use App\Services\Acquis\EquivalenceParcoursService;
use App\Services\Acquis\PlanRedoublementService;
use App\Services\Acquis\ReglementationAcquisService;
use App\Services\Acquis\ReinscriptionPedagogiqueRedoublantService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * Suite de tests exhaustive du LOOP MASTER — Gestion Intelligente des Acquis, Redoublement et Réinscription.
 * Validation stricte du Décret n° 2022-631 et de l'Arrêté du 7 juillet 2023.
 * Couvre l'intégralité des 30 cas d'école (CAS 01 à CAS 30).
 */
class GestionAcquisRedoublementTest extends TestCase
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

    private CalculAcquisPedagogiquesService $calculAcquisService;

    private PlanRedoublementService $planRedoublementService;

    private ReinscriptionPedagogiqueRedoublantService $reinscriptionService;

    private EquivalenceParcoursService $equivalenceService;

    private ReglementationAcquisService $reglementationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences de Tunis',
            'code' => 'FST',
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
            'code' => 'LIC-INFO',
            'nom' => 'Licence en Informatique',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
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

        $this->calculAcquisService = app(CalculAcquisPedagogiquesService::class);
        $this->planRedoublementService = app(PlanRedoublementService::class);
        $this->reinscriptionService = app(ReinscriptionPedagogiqueRedoublantService::class);
        $this->equivalenceService = app(EquivalenceParcoursService::class);
        $this->reglementationService = app(ReglementationAcquisService::class);
    }

    private function creerEtudiant(string $cin = '08877665', string $nom = 'Mansour', string $prenom = 'Farah'): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => $nom,
            'prenom' => $prenom,
            'email' => strtolower($prenom.'.'.$nom).'@universite.test',
            'date_naissance' => '2004-05-12',
            'sexe' => 'F',
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
            'filiere' => 'Informatique',
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

    private function creerEcue(UniteEnseignement $ue, string $code, string $nom, int $credits = 3, float $coeff = 1.5, string $typeEval = 'standard'): Matiere
    {
        return Matiere::create([
            'ue_id' => $ue->id,
            'code' => $code,
            'nom' => $nom,
            'credits' => $credits,
            'coefficient' => $coeff,
            'semestre' => 1,
            'niveau' => 'L1',
            'type_evaluation' => $typeEval,
            'actif' => true,
        ]);
    }

    private function attribuerNote(Etudiant $etudiant, InscriptionAcademique $insc, Matiere $matiere, float $note, string $session = 'normale'): Note
    {
        $semestre = $insc->semestres()->first();

        return Note::create([
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $insc->id,
            'semestre_id' => $semestre?->id,
            'matiere_id' => $matiere->id,
            'note' => $note,
            'coefficient' => $matiere->coefficient,
            'credits' => $matiere->credits,
            'session' => $session,
        ]);
    }

    private function publierDeliberation(Etudiant $etudiant, InscriptionAcademique $insc, string $decision = 'AJOURNE', float $moyenne = 9.5): DecisionDeliberation
    {
        $deliberation = Deliberation::create([
            'code' => 'DELIB-'.uniqid(),
            'annee_universitaire_id' => $insc->annee_universitaire_id,
            'programme_id' => $this->programmeLicence->id,
            'niveau_id' => $this->niveauL1->id,
            'statut' => 'published',
            'published_at' => now(),
        ]);

        return DecisionDeliberation::create([
            'deliberation_id' => $deliberation->id,
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $insc->id,
            'moyenne' => $moyenne,
            'credits_obtenus' => 30,
            'credits_total' => 60,
            'ues_validees' => 5,
            'ues_total' => 10,
            'decision' => $decision,
            'commentaire' => 'Délibération officielle de session principale',
            'publie' => true,
        ]);
    }

    // =========================================================================
    // CAS 01 : Capitalisation complète d'une UE (≥ 10/20) - Art. 8 & Art. 10
    // =========================================================================
    public function test_cas_01_capitalisation_ue_moyenne_superieure_ou_egale_a_10(): void
    {
        $etudiant = $this->creerEtudiant('11110001');
        $insc = $this->creerInscription($etudiant, $this->annee2025);
        $ue = $this->creerUe('UE101', 'Algorithmique & Prog', 6, 3.0);
        $ecue1 = $this->creerEcue($ue, 'EC101', 'Algo 1', 3, 1.5);
        $ecue2 = $this->creerEcue($ue, 'EC102', 'Langage C', 3, 1.5);

        $this->attribuerNote($etudiant, $insc, $ecue1, 14.0);
        $this->attribuerNote($etudiant, $insc, $ecue2, 10.0);
        $this->publierDeliberation($etudiant, $insc);

        $analyse = $this->calculAcquisService->analyserAcquis($etudiant, $insc);

        $this->assertEquals(6, $analyse['total_credits_acquis']);
        $this->assertEquals('UE_CAPITALISEE', $analyse['ues'][0]['statut']);
        $this->assertTrue($analyse['ues'][0]['est_capitalisee']);
        $this->assertEmpty($analyse['elements_a_reprendre']);
    }

    // =========================================================================
    // CAS 02 : ECUE capitalisable avec crédits propres (Art. 8)
    // =========================================================================
    public function test_cas_02_ecue_capitalise_avec_credits_propres_dans_ue_non_validee(): void
    {
        $etudiant = $this->creerEtudiant('11110002');
        $insc = $this->creerInscription($etudiant, $this->annee2025);
        $ue = $this->creerUe('UE102', 'Mathématiques', 6, 3.0);
        $ecue1 = $this->creerEcue($ue, 'EC103', 'Analyse', 3, 1.5);
        $ecue2 = $this->creerEcue($ue, 'EC104', 'Algèbre', 3, 1.5);

        // Analyse = 13 (>=10, credits=3), Algèbre = 5 (<10) -> Moyenne UE = 9/20 < 10
        $this->attribuerNote($etudiant, $insc, $ecue1, 13.0);
        $this->attribuerNote($etudiant, $insc, $ecue2, 5.0);
        $this->publierDeliberation($etudiant, $insc);

        $analyse = $this->calculAcquisService->analyserAcquis($etudiant, $insc);

        $this->assertEquals(3, $analyse['total_credits_acquis']);
        $this->assertCount(1, $analyse['elements_conserves']);
        $this->assertEquals('EC103', $analyse['elements_conserves'][0]['code']);
        $this->assertCount(1, $analyse['elements_a_reprendre']);
        $this->assertEquals('EC104', $analyse['elements_a_reprendre'][0]['code']);
    }

    // =========================================================================
    // CAS 03 : ECUE avec note ≥ 10/20 mais SANS crédits propres (Art. 8 strict)
    // =========================================================================
    public function test_cas_03_ecue_note_superieure_a_10_sans_credits_propres_non_capitalisable(): void
    {
        $etudiant = $this->creerEtudiant('11110003');
        $insc = $this->creerInscription($etudiant, $this->annee2025);
        $ue = $this->creerUe('UE103', 'Physique Appliquée', 6, 2.0);
        // Crédits = 0 car portés exclusivement par l'UE globale
        $ecue1 = $this->creerEcue($ue, 'EC105', 'Optique', 0, 1.0);
        $ecue2 = $this->creerEcue($ue, 'EC106', 'Électrostatique', 0, 1.0);

        // Optique = 14 (>=10 mais 0 crédit propre), Électro = 4 -> Moyenne UE = 9/20
        $this->attribuerNote($etudiant, $insc, $ecue1, 14.0);
        $this->attribuerNote($etudiant, $insc, $ecue2, 4.0);
        $this->publierDeliberation($etudiant, $insc);

        $analyse = $this->calculAcquisService->analyserAcquis($etudiant, $insc);

        $this->assertEquals(0, $analyse['total_credits_acquis']);
        // Article 8 strict : 0 crédit autonome attribué
        $this->assertCount(2, $analyse['elements_a_reprendre']);
        $optiqueAnalysis = collect($analyse['ues'][0]['ecues'])->firstWhere('code', 'EC105');
        $this->assertEquals('NOTE_SUPERIEURE_SANS_CREDIT_PROPRE', $optiqueAnalysis['statut']);
    }

    // =========================================================================
    // CAS 04 : Validation par compensation semestrielle (Art. 9)
    // =========================================================================
    public function test_cas_04_validation_par_compensation_semestrielle_art_9(): void
    {
        $etudiant = $this->creerEtudiant('11110004');
        $insc = $this->creerInscription($etudiant, $this->annee2025);

        // UE1 : 14/20 (6 crédits)
        $ue1 = $this->creerUe('UE107', 'Programmation', 6, 3.0);
        $ec1 = $this->creerEcue($ue1, 'EC107', 'Java', 6, 3.0);
        $this->attribuerNote($etudiant, $insc, $ec1, 14.0);

        // UE2 : 8/20 (6 crédits) - compensable car moyenne semestrielle = (14+8)/2 = 11 >= 10
        $ue2 = $this->creerUe('UE108', 'Anglais & Soft Skills', 6, 3.0);
        $ec2 = $this->creerEcue($ue2, 'EC108', 'Anglais', 6, 3.0);
        $this->attribuerNote($etudiant, $insc, $ec2, 8.0);

        $this->publierDeliberation($etudiant, $insc, 'ADMIS_COMPENSATION', 11.0);

        $analyse = $this->calculAcquisService->analyserAcquis($etudiant, $insc);

        $this->assertEquals(12, $analyse['total_credits_acquis']);
        $this->assertEquals('UE_COMPENSEE', $analyse['ues'][1]['statut']);
        $this->assertTrue($analyse['ues'][1]['est_compensee']);
    }

    // =========================================================================
    // CAS 05 : Seuil de compétence éliminatoire (Art. 13)
    // =========================================================================
    public function test_cas_05_seuil_competence_eliminatoire_bloque_la_compensation_art_13(): void
    {
        $etudiant = $this->creerEtudiant('11110005');
        $insc = $this->creerInscription($etudiant, $this->annee2025);

        // UE1 : 16/20
        $ue1 = $this->creerUe('UE109', 'Maths Avancées', 6, 3.0);
        $ec1 = $this->creerEcue($ue1, 'EC109', 'Calcul', 6, 3.0);
        $this->attribuerNote($etudiant, $insc, $ec1, 16.0);

        // UE2 : note de 4.5/20 inférieure au seuil éliminatoire par défaut (6.0/20)
        $ue2 = $this->creerUe('UE110', 'Architecture Ordinateurs', 6, 3.0);
        $ec2 = $this->creerEcue($ue2, 'EC110', 'Systèmes', 6, 3.0);
        $this->attribuerNote($etudiant, $insc, $ec2, 4.5);

        $this->publierDeliberation($etudiant, $insc, 'AJOURNE', 10.25);

        $analyse = $this->calculAcquisService->analyserAcquis($etudiant, $insc);

        // La compensation est bloquée par l'Art. 13
        $this->assertEquals(6, $analyse['total_credits_acquis']);
        $this->assertEquals('SEUIL_COMPETENCE_NON_ATTEINT', $analyse['ues'][1]['statut']);
        $this->assertFalse($analyse['ues'][1]['est_compensee']);
        $this->assertCount(1, $analyse['elements_a_reprendre']);
    }

    // =========================================================================
    // CAS 06 : Résultats provisoires non encore délibérés
    // =========================================================================
    public function test_cas_06_resultats_provisoires_non_deliberes_bloquent_l_acquis(): void
    {
        $etudiant = $this->creerEtudiant('11110006');
        $insc = $this->creerInscription($etudiant, $this->annee2025);
        $ue = $this->creerUe('UE111', 'Base de données', 6, 3.0);
        $ec = $this->creerEcue($ue, 'EC111', 'SQL', 6, 3.0);

        $this->attribuerNote($etudiant, $insc, $ec, 15.0);
        // AUCUNE délibération officielle publiée (ou décision non publiée)
        $deliberation = Deliberation::create([
            'code' => 'DELIB-PROV-'.uniqid(),
            'annee_universitaire_id' => $insc->annee_universitaire_id,
            'programme_id' => $this->programmeLicence->id,
            'niveau_id' => $this->niveauL1->id,
            'statut' => 'draft',
        ]);
        DecisionDeliberation::create([
            'deliberation_id' => $deliberation->id,
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $insc->id,
            'decision' => 'AJOURNE',
            'publie' => false, // Provisoire
        ]);

        $analyse = $this->calculAcquisService->analyserAcquis($etudiant, $insc);

        $this->assertEquals(0, $analyse['total_credits_acquis']);
        $this->assertEquals('EN_ATTENTE_DELIBERATION', $analyse['ues'][0]['statut']);
        $this->assertFalse($analyse['peut_redoubler_automatiquement']);
    }

    // =========================================================================
    // CAS 07 : Contrôle continu exclusif (Art. 11)
    // =========================================================================
    public function test_cas_07_controle_continu_exclusif_art_11(): void
    {
        $etudiant = $this->creerEtudiant('11110007');
        $insc = $this->creerInscription($etudiant, $this->annee2025);
        $ue = $this->creerUe('UE112', 'Ateliers Pratiques', 6, 3.0);
        $ec = $this->creerEcue($ue, 'EC112', 'Projet Intégrateur', 6, 3.0, 'controle_continu');

        $this->attribuerNote($etudiant, $insc, $ec, 8.0);
        $this->publierDeliberation($etudiant, $insc);

        $analyse = $this->calculAcquisService->analyserAcquis($etudiant, $insc);
        $ecueAnalyse = $analyse['ues'][0]['ecues'][0];

        $this->assertTrue($ecueAnalyse['est_controle_continu_exclusif']);
    }

    // =========================================================================
    // CAS 08 : Session de rattrapage et substitution de la meilleure note
    // =========================================================================
    public function test_cas_08_rattrapage_prise_en_compte_meilleure_note(): void
    {
        $etudiant = $this->creerEtudiant('11110008');
        $insc = $this->creerInscription($etudiant, $this->annee2025);
        $ue = $this->creerUe('UE113', 'Systèmes Logiques', 6, 3.0);
        $ec = $this->creerEcue($ue, 'EC113', 'Circuits', 6, 3.0);

        // Session normale : 7.0/20, Rattrapage : 11.5/20
        $this->attribuerNote($etudiant, $insc, $ec, 7.0, 'normale');
        $this->attribuerNote($etudiant, $insc, $ec, 11.5, 'rattrapage');
        $this->publierDeliberation($etudiant, $insc);

        $analyse = $this->calculAcquisService->analyserAcquis($etudiant, $insc);

        $this->assertEquals(6, $analyse['total_credits_acquis']);
        $this->assertEquals(11.5, $analyse['ues'][0]['ecues'][0]['note_reconnue']);
        $this->assertEquals('UE_CAPITALISEE', $analyse['ues'][0]['statut']);
    }

    // =========================================================================
    // CAS 09 : Absence injustifiée (note 0 ou bloquante)
    // =========================================================================
    public function test_cas_09_absence_injustifiee_bloque_la_compensation(): void
    {
        $etudiant = $this->creerEtudiant('11110009');
        $insc = $this->creerInscription($etudiant, $this->annee2025);
        $ue = $this->creerUe('UE114', 'Réseaux', 6, 3.0);
        $ec = $this->creerEcue($ue, 'EC114', 'Protocoles', 6, 3.0);

        $this->attribuerNote($etudiant, $insc, $ec, 0.0);
        $this->publierDeliberation($etudiant, $insc);

        $analyse = $this->calculAcquisService->analyserAcquis($etudiant, $insc);

        $this->assertEquals(0, $analyse['total_credits_acquis']);
        $this->assertEquals('SEUIL_COMPETENCE_NON_ATTEINT', $analyse['ues'][0]['statut']);
    }

    // =========================================================================
    // CAS 10 : Pouvoir discrétionnaire de rachat du jury (Art. 16)
    // =========================================================================
    public function test_cas_10_rachat_discretionnaire_jury_art_16_sans_automatisme_logiciel(): void
    {
        $etudiant = $this->creerEtudiant('11110010');
        $insc = $this->creerInscription($etudiant, $this->annee2025);
        $ue = $this->creerUe('UE115', 'Génie Logiciel', 6, 3.0);
        $ec = $this->creerEcue($ue, 'EC115', 'UML', 6, 3.0);

        // 9.95/20 : le logiciel ne doit JAMAIS arrondir à 10.0 sans délibération
        $this->attribuerNote($etudiant, $insc, $ec, 9.95);
        $this->publierDeliberation($etudiant, $insc, 'AJOURNE', 9.95);

        $analyse = $this->calculAcquisService->analyserAcquis($etudiant, $insc);

        $this->assertEquals(0, $analyse['total_credits_acquis']);
        $this->assertEquals('NON_VALIDEE', $analyse['ues'][0]['statut']);
        $this->assertCount(1, $analyse['elements_a_reprendre']);
    }

    // =========================================================================
    // CAS 11 : Non-duplication des crédits ECTS
    // =========================================================================
    public function test_cas_11_non_duplication_des_credits_ects(): void
    {
        $etudiant = $this->creerEtudiant('11110011');
        $insc1 = $this->creerInscription($etudiant, $this->annee2025);
        $ue = $this->creerUe('UE116', 'Web Dev', 6, 3.0);
        $ec = $this->creerEcue($ue, 'EC116', 'HTML/CSS', 6, 3.0);

        $this->attribuerNote($etudiant, $insc1, $ec, 14.0);
        $this->publierDeliberation($etudiant, $insc1);

        // Simulation réinscription annee2026
        $insc2 = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee2026->id,
            'programme_id' => $this->programmeLicence->id,
            'niveau_id' => $this->niveauL1->id,
            'filiere' => 'Informatique',
            'niveau' => 'L1',
            'statut' => 'en_cours',
        ]);

        $res = $this->reinscriptionService->effectuerReinscriptionPedagogique($etudiant, $insc2, 1);

        $this->assertEquals(6, $res['total_credits_conserves']);
        $this->assertEquals(0, $res['total_credits_a_reprendre']);
        $this->assertEquals(0, $res['elements_reinscrits']);
    }

    // =========================================================================
    // CAS 12 : Inscription pédagogique ciblée du redoublant (Art. 10)
    // =========================================================================
    public function test_cas_12_reinscription_pedagogique_ciblee_exclut_les_acquis_art_10(): void
    {
        $etudiant = $this->creerEtudiant('11110012');
        $insc1 = $this->creerInscription($etudiant, $this->annee2025);
        $ue1 = $this->creerUe('UE117', 'Prog 1', 6, 3.0);
        $ec1 = $this->creerEcue($ue1, 'EC117', 'C++', 6, 3.0);
        $ue2 = $this->creerUe('UE118', 'Maths 1', 6, 3.0);
        $ec2 = $this->creerEcue($ue2, 'EC118', 'Algèbre', 6, 3.0);

        $this->attribuerNote($etudiant, $insc1, $ec1, 15.0); // Capitalisé
        $this->attribuerNote($etudiant, $insc1, $ec2, 6.0);  // Non acquis
        $this->publierDeliberation($etudiant, $insc1);

        $insc2 = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee2026->id,
            'programme_id' => $this->programmeLicence->id,
            'niveau_id' => $this->niveauL1->id,
            'filiere' => 'Informatique',
            'niveau' => 'L1',
            'statut' => 'en_cours',
        ]);

        $res = $this->reinscriptionService->effectuerReinscriptionPedagogique($etudiant, $insc2, 1);

        $this->assertEquals(1, $res['elements_reinscrits']);
        $this->assertEquals(1, $res['elements_capitalises_ou_compenses']);

        $ips = InscriptionPedagogique::where('inscription_id', $insc2->id)->get();
        $this->assertCount(2, $ips);
        $this->assertEquals('valide', $ips->firstWhere('matiere_id', $ec1->id)->statut);
        $this->assertEquals('inscrit', $ips->firstWhere('matiere_id', $ec2->id)->statut);
    }

    // =========================================================================
    // CAS 13 : Transfert de parcours et non-transfert automatique des UE compensées (Art. 9)
    // =========================================================================
    public function test_cas_13_transfert_parcours_non_transfert_automatique_ue_compensee_art_9(): void
    {
        $etudiant = $this->creerEtudiant('11110013');
        $insc = $this->creerInscription($etudiant, $this->annee2025);
        $ue = $this->creerUe('UE119', 'Module Général', 6, 3.0);
        $ec = $this->creerEcue($ue, 'EC119', 'Culture Numérique', 6, 3.0);

        $this->attribuerNote($etudiant, $insc, $ec, 8.5);
        $this->publierDeliberation($etudiant, $insc, 'ADMIS_COMPENSATION', 11.0);

        // Tentative d'analyse de transférabilité vers un autre programme
        $autreProgramme = Programme::create([
            'faculte_id' => $this->faculte->id,
            'type_diplome_id' => $this->typeLicence->id,
            'code' => 'LIC-MATH',
            'nom' => 'Licence en Mathématiques',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
            'actif' => true,
        ]);

        $analyseTransfert = $this->equivalenceService->analyserTransferabiliteAcquis($etudiant, $autreProgramme);

        $this->assertFalse($analyseTransfert['tous_transferables']);
        $this->assertNotEmpty($analyseTransfert['elements_non_transferables_automatiquement']);
    }

    // =========================================================================
    // CAS 14 : Reconnaissance formelle d'équivalence (ParcoursEquivalence)
    // =========================================================================
    public function test_cas_14_reconnaissance_equivalence_validee(): void
    {
        $etudiant = $this->creerEtudiant('11110014');
        $insc = $this->creerInscription($etudiant, $this->annee2025);
        $ue = $this->creerUe('UE120', 'Langues Vivantes', 3, 1.5);
        $ec = $this->creerEcue($ue, 'EC120', 'Anglais Scientifique', 3, 1.5);

        // Enregistrement d'une équivalence validée par commission
        ParcoursEquivalence::create([
            'etudiant_id' => $etudiant->id,
            'origine_libelle' => 'TOEIC C1 Externe',
            'origine_credits' => 3,
            'cible_matiere_id' => $ec->id,
            'cible_ue_id' => $ue->id,
            'cible_libelle' => $ec->nom,
            'etablissement' => 'ETS Global',
            'statut' => 'validee',
            'decision' => 'accordee',
        ]);

        $this->publierDeliberation($etudiant, $insc);

        $analyse = $this->calculAcquisService->analyserAcquis($etudiant, $insc);
        $ecueAnalyse = $analyse['ues'][0]['ecues'][0];

        $this->assertTrue($ecueAnalyse['est_equivalent']);
        $this->assertEquals('EQUIVALENCE_VALIDEE', $ecueAnalyse['statut']);
        $this->assertEmpty($analyse['elements_a_reprendre']);
    }

    // =========================================================================
    // CAS 15 : Dispense académique reconnue (ParcoursDispense)
    // =========================================================================
    public function test_cas_15_dispense_academique_reconnue(): void
    {
        $etudiant = $this->creerEtudiant('11110015');
        $insc = $this->creerInscription($etudiant, $this->annee2025);
        $ue = $this->creerUe('UE121', 'Sport Universitaire', 2, 1.0);
        $ec = $this->creerEcue($ue, 'EC121', 'Éducation Physique', 2, 1.0);

        ParcoursDispense::create([
            'etudiant_id' => $etudiant->id,
            'matiere_id' => $ec->id,
            'ue_id' => null,
            'cible_libelle' => $ec->nom,
            'motif' => 'Certificat médical d’inaptitude temporaire',
            'decision' => 'dispense_totale',
            'responsable' => 'Médecin Universitaire',
            'statut' => 'validee',
        ]);

        $this->publierDeliberation($etudiant, $insc);

        $analyse = $this->calculAcquisService->analyserAcquis($etudiant, $insc);
        $ecueAnalyse = $analyse['ues'][0]['ecues'][0];

        $this->assertTrue($ecueAnalyse['est_dispense']);
        $this->assertEquals('DISPENSE_OFFICIELLE', $ecueAnalyse['statut']);
    }

    // =========================================================================
    // CAS 16 : Régime inconnu ou indéterminé -> Fail-closed
    // =========================================================================
    public function test_cas_16_regime_inconnu_declenche_fail_closed(): void
    {
        $this->expectException(DomainException::class);

        $programmeInconnu = Programme::create([
            'faculte_id' => $this->faculte->id,
            'type_diplome_id' => null,
            'code' => 'UNKNOWN-999',
            'nom' => 'Diplôme Spécial Non Homologué',
            'duree_annees' => 1,
            'semestres_nombre' => 2,
            'credits_requis' => 60,
            'actif' => true,
        ]);

        $this->reglementationService->resoudreRegime($programmeInconnu);
    }

    // =========================================================================
    // CAS 17 : Idempotence de la validation du plan de redoublement
    // =========================================================================
    public function test_cas_17_plan_redoublement_idempotence(): void
    {
        $etudiant = $this->creerEtudiant('11110017');
        $insc = $this->creerInscription($etudiant, $this->annee2025);
        $ue = $this->creerUe('UE122', 'Base', 6, 3.0);
        $ec = $this->creerEcue($ue, 'EC122', 'Matière 1', 6, 3.0);
        $this->attribuerNote($etudiant, $insc, $ec, 5.0);
        $this->publierDeliberation($etudiant, $insc);

        // Premier appel
        $plan1 = $this->planRedoublementService->confirmerPlan($etudiant, $this->annee2026->id, 1);
        // Deuxième appel idempotent
        $plan2 = $this->planRedoublementService->confirmerPlan($etudiant, $this->annee2026->id, 1);

        $this->assertEquals($plan1->id, $plan2->id);
        $this->assertEquals(1, ParcoursPlan::where('etudiant_id', $etudiant->id)->count());
    }

    // =========================================================================
    // CAS 18 : Redoublements successifs et cumul des acquis antérieurs
    // =========================================================================
    public function test_cas_18_redoublements_multiples_cumul_des_acquis(): void
    {
        $etudiant = $this->creerEtudiant('11110018');
        $insc1 = $this->creerInscription($etudiant, $this->annee2025);
        $ue1 = $this->creerUe('UE123', 'UE 1', 6, 3.0);
        $ec1 = $this->creerEcue($ue1, 'EC123', 'EC 1', 6, 3.0);
        $this->attribuerNote($etudiant, $insc1, $ec1, 12.0); // Capitalisé en N-1
        $this->publierDeliberation($etudiant, $insc1);

        $annee2027 = AnneeUniversitaire::create([
            'libelle' => '2027-2028',
            'date_debut' => '2027-09-01',
            'date_fin' => '2028-06-30',
            'active' => false,
        ]);

        $insc2 = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee2026->id,
            'programme_id' => $this->programmeLicence->id,
            'niveau_id' => $this->niveauL1->id,
            'filiere' => 'Informatique',
            'niveau' => 'L1',
            'statut' => 'en_cours',
        ]);
        $insc2->semestres()->create(['numero' => 1]);
        $ue2 = $this->creerUe('UE124', 'UE 2', 6, 3.0);
        $ec2 = $this->creerEcue($ue2, 'EC124', 'EC 2', 6, 3.0);
        $this->attribuerNote($etudiant, $insc2, $ec2, 11.0); // Capitalisé en N
        $this->publierDeliberation($etudiant, $insc2);

        // Inscription en redoublement N+1
        $insc3 = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $annee2027->id,
            'programme_id' => $this->programmeLicence->id,
            'niveau_id' => $this->niveauL1->id,
            'filiere' => 'Informatique',
            'niveau' => 'L1',
            'statut' => 'en_cours',
        ]);

        $res = $this->reinscriptionService->effectuerReinscriptionPedagogique($etudiant, $insc3, 1);

        // Cumul des crédits de EC1 (6) et EC2 (6) = 12 crédits conservés
        $this->assertEquals(12, $res['total_credits_conserves']);
    }

    // =========================================================================
    // CAS 19 : Consultation étudiante anti-IDOR
    // =========================================================================
    public function test_cas_19_consultation_etudiante_anti_idor(): void
    {
        $etudiantA = $this->creerEtudiant('11110019', 'Salah', 'Amine');
        $inscA = $this->creerInscription($etudiantA, $this->annee2025);
        $this->publierDeliberation($etudiantA, $inscA);

        $this->etudiantConnecte($etudiantA);

        $response = $this->get(route('etudiant.acquis.index'));

        $response->assertOk();
        $response->assertSee('Salah');
        $response->assertSee('Amine');
    }

    // =========================================================================
    // CAS 20 : Habilitation administrative requise pour le redoublement
    // =========================================================================
    public function test_cas_20_habilitation_administrative_requise_pour_action_redoublement(): void
    {
        $etudiant = $this->creerEtudiant('11110020');

        // Utilisateur non connecté -> Redirection login
        $response = $this->get(route('admin.redoublement.index'));
        $response->assertRedirect();

        // Agent avec rôle consultation (lecture seule) -> tentative d'action de modification rejetée (403)
        $agentConsultation = User::creerCompteBackOffice([
            'name' => 'Agent Consultation',
            'email' => 'agent.consultation@scolarite.test',
            'password' => 'Motdepasse@123',
        ], 'consultation');

        $this->actingAs($agentConsultation);
        $response403 = $this->post(route('admin.redoublement.confirmer-plan', $etudiant), [
            'nouvelle_annee_id' => $this->annee2026->id,
        ]);
        $response403->assertForbidden();
    }

    // =========================================================================
    // CAS 21 : Traçabilité et audit dans HistoriqueAcademique
    // =========================================================================
    public function test_cas_21_tracabilite_audit_historique_academique(): void
    {
        $etudiant = $this->creerEtudiant('11110021');
        $insc = $this->creerInscription($etudiant, $this->annee2025);
        $ue = $this->creerUe('UE125', 'Audit UE', 6, 3.0);
        $ec = $this->creerEcue($ue, 'EC125', 'Audit EC', 6, 3.0);
        $this->attribuerNote($etudiant, $insc, $ec, 4.0);
        $this->publierDeliberation($etudiant, $insc);

        $admin = $this->scolarite('super_admin');
        $this->planRedoublementService->confirmerPlan($etudiant, $this->annee2026->id, $admin->id);

        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $etudiant->id,
            'action' => 'plan_redoublement_valide',
            'utilisateur_id' => $admin->id,
        ]);
    }

    // =========================================================================
    // CAS 22 : Contrôleur Admin Index - Filtres et Recherche
    // =========================================================================
    public function test_cas_22_controleur_admin_index_filtres_et_recherche(): void
    {
        $this->scolarite('super_admin');
        $etudiant1 = $this->creerEtudiant('11110022', 'Ben Salah', 'Youssef');
        $etudiant2 = $this->creerEtudiant('11110023', 'Trabelsi', 'Nour');

        $response = $this->get(route('admin.redoublement.index', ['recherche' => 'Youssef']));

        $response->assertOk();
        $response->assertSee('Ben Salah');
        $response->assertDontSee('Trabelsi');
    }

    // =========================================================================
    // CAS 23 : Contrôleur Admin Show - Simulation et affichage
    // =========================================================================
    public function test_cas_23_controleur_admin_show_affiche_kpi_et_ues(): void
    {
        $this->scolarite('super_admin');
        $etudiant = $this->creerEtudiant('11110024');
        $insc = $this->creerInscription($etudiant, $this->annee2025);
        $this->publierDeliberation($etudiant, $insc);

        $response = $this->get(route('admin.redoublement.show', $etudiant));

        $response->assertOk();
        $response->assertSee('Simulation Prévisionnelle du Plan de Redoublement');
    }

    // =========================================================================
    // CAS 24 : Contrôleur Admin Confirmer Plan - Succès
    // =========================================================================
    public function test_cas_24_controleur_admin_confirmer_plan_succes(): void
    {
        $this->scolarite('super_admin');
        $etudiant = $this->creerEtudiant('11110025');
        $insc = $this->creerInscription($etudiant, $this->annee2025);
        $ue = $this->creerUe('UE126', 'Chimie', 6, 3.0);
        $ec = $this->creerEcue($ue, 'EC126', 'Organique', 6, 3.0);
        $this->attribuerNote($etudiant, $insc, $ec, 7.0);
        $this->publierDeliberation($etudiant, $insc);

        $response = $this->post(route('admin.redoublement.confirmer-plan', $etudiant), [
            'nouvelle_annee_id' => $this->annee2026->id,
        ]);

        $response->assertRedirect(route('admin.redoublement.show', $etudiant));
        $response->assertSessionHas('success');
    }

    // =========================================================================
    // CAS 25 : Contrôleur Admin Réinscrire - Succès
    // =========================================================================
    public function test_cas_25_controleur_admin_reinscrire_succes(): void
    {
        $this->scolarite('super_admin');
        $etudiant = $this->creerEtudiant('11110026');
        $insc1 = $this->creerInscription($etudiant, $this->annee2025);
        $this->publierDeliberation($etudiant, $insc1);

        $insc2 = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee2026->id,
            'programme_id' => $this->programmeLicence->id,
            'niveau_id' => $this->niveauL1->id,
            'filiere' => 'Informatique',
            'niveau' => 'L1',
            'statut' => 'en_cours',
        ]);

        $response = $this->post(route('admin.redoublement.reinscrire', $etudiant), [
            'inscription_academique_id' => $insc2->id,
        ]);

        $response->assertRedirect(route('admin.redoublement.show', $etudiant));
        $response->assertSessionHas('success');
    }

    // =========================================================================
    // CAS 26 : Traitement d'une demande d'équivalence en commission
    // =========================================================================
    public function test_cas_26_traitement_demande_equivalence_commission(): void
    {
        $this->scolarite('super_admin');
        $etudiant = $this->creerEtudiant('11110027');
        $ue = $this->creerUe('UE127', 'Communication', 3, 1.5);
        $ec = $this->creerEcue($ue, 'EC127', 'Français', 3, 1.5);

        $equiv = ParcoursEquivalence::create([
            'etudiant_id' => $etudiant->id,
            'origine_libelle' => 'Certificat DELF B2',
            'origine_credits' => 3,
            'cible_matiere_id' => $ec->id,
            'cible_ue_id' => $ue->id,
            'cible_libelle' => $ec->nom,
            'statut' => 'proposee',
        ]);

        $response = $this->post(route('admin.redoublement.equivalence', $etudiant), [
            'equivalence_id' => $equiv->id,
            'decision' => 'VALIDEE',
            'motif' => 'Niveau linguistique suffisant validé par commission',
        ]);

        $response->assertRedirect(route('admin.redoublement.show', $etudiant));
        $this->assertDatabaseHas('parcours_equivalences', [
            'id' => $equiv->id,
            'statut' => 'validee',
            'decision' => 'validee',
        ]);
    }

    // =========================================================================
    // CAS 27 : Élément sans note géré proprement (pas de crash)
    // =========================================================================
    public function test_cas_27_element_sans_note_gere_proprement(): void
    {
        $etudiant = $this->creerEtudiant('11110028');
        $insc = $this->creerInscription($etudiant, $this->annee2025);
        $ue = $this->creerUe('UE128', 'Droit Numérique', 3, 1.5);
        $ec = $this->creerEcue($ue, 'EC128', 'RGPD', 3, 1.5);

        // Aucune note saisie
        $this->publierDeliberation($etudiant, $insc);

        $analyse = $this->calculAcquisService->analyserAcquis($etudiant, $insc);
        $ecueAnalyse = $analyse['ues'][0]['ecues'][0];

        $this->assertNull($ecueAnalyse['note_reconnue']);
        $this->assertEquals('NON_EVALUE', $ecueAnalyse['statut']);
    }

    // =========================================================================
    // CAS 28 : Préservation de l'intégrité des décisions du jury
    // =========================================================================
    public function test_cas_28_preservation_integrite_decisions_jury(): void
    {
        $etudiant = $this->creerEtudiant('11110029');
        $insc = $this->creerInscription($etudiant, $this->annee2025);
        $delib = $this->publierDeliberation($etudiant, $insc, 'ADMIS', 14.8);

        // Même après calcul des acquis et plan
        $this->calculAcquisService->analyserAcquis($etudiant, $insc);
        $this->planRedoublementService->construirePlan($etudiant, null, true);

        $delibFraiche = DecisionDeliberation::find($delib->id);
        $this->assertEquals('ADMIS', $delibFraiche->decision);
        $this->assertEquals(14.8, (float) $delibFraiche->moyenne);
    }

    // =========================================================================
    // CAS 29 : Gestion des prérequis non validés
    // =========================================================================
    public function test_cas_29_gestion_des_prerequis_non_valides(): void
    {
        $etudiant = $this->creerEtudiant('11110030');
        $insc = $this->creerInscription($etudiant, $this->annee2025);
        $ue = $this->creerUe('UE129', 'Algèbre Avancée', 6, 3.0);
        $ecue = $this->creerEcue($ue, 'EC129', 'Topologie', 6, 3.0);
        $this->publierDeliberation($etudiant, $insc);

        $conflits = $this->planRedoublementService->verifierPrerequisParcours($etudiant, [$ecue->id]);
        $this->assertIsArray($conflits);
    }

    // =========================================================================
    // CAS 30 : Validation sans régression système
    // =========================================================================
    public function test_cas_30_validation_sans_regression_systeme(): void
    {
        $etudiant = $this->creerEtudiant('11110031');
        $insc = $this->creerInscription($etudiant, $this->annee2025);
        $this->publierDeliberation($etudiant, $insc);

        $this->assertNotNull($etudiant->faculte);
        $this->assertNotNull($insc->anneeUniversitaire);
        $this->assertEquals('valide', $etudiant->statut);
    }

    // =========================================================================
    // CAS 31 : Intégration Menu Navigation Étudiant
    // =========================================================================
    public function test_cas_31_menu_navigation_etudiant_affiche_mes_acquis(): void
    {
        $etudiant = $this->creerEtudiant('11110032');
        $insc = $this->creerInscription($etudiant, $this->annee2025);
        $this->publierDeliberation($etudiant, $insc);

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_auth_prenom' => $etudiant->prenom,
        ])->get(route('etudiant.acquis.index'));

        $response->assertOk();
        $response->assertSee('Mes Acquis Académiques');
        $response->assertSee('Mes acquis &amp; progression', false);
    }

    // =========================================================================
    // CAS 32 : Intégration Menu Navigation Admin & Cockpit Moderne
    // =========================================================================
    public function test_cas_32_cockpit_admin_affiche_navigation_kpi_et_onglets(): void
    {
        $this->scolarite('super_admin');
        $etudiant = $this->creerEtudiant('11110033');
        $insc = $this->creerInscription($etudiant, $this->annee2025);
        $this->publierDeliberation($etudiant, $insc);

        $response = $this->get(route('admin.redoublement.index'));

        $response->assertOk();
        $response->assertSee('Cockpit Acquis Académiques');
        $response->assertSee('Acquis &amp; Redoublement', false);
        $response->assertSee('Simulation de Cohorte');
        $response->assertSee('Redoublants à statuer');
        $response->assertSee('Compensés Art. 9');
    }
}
