<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Batiment;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\InscriptionPedagogique;
use App\Models\IntegrationLog;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\GroupeVirtuelExamen;
use App\Modules\Evaluation\Models\GroupeVirtuelMembre;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\ConsultationExportGroupeVirtuelService;
use App\Modules\Evaluation\Services\ExamDecisionCapacitePermutationService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Suite de tests Enterprise LOOP — Capacité de salle non confirmée (D-04).
 *
 * Vérifie :
 * 1. Détection et blocage officiel lors d'une capacité inconnue ou insuffisante
 * 2. Propositions de salles compatibles en lecture seule stricte
 * 3. Motifs d'exclusion explicites (capacité, conflits, indisponibilités, BOLA)
 * 4. Distinction entre données insuffisantes et absence de candidates
 * 5. Intégrité lecture seule : 0 mutation en base sur le planning
 * 6. Respect strict de l'isolation facultaire BOLA et permissions
 * 7. Maintien du blocage de l'export officiel
 * 8. Détection d'obsolescence et traçabilité sans PII
 */
class ExamCapaciteSalleNonConfirmeeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $adminFaculteB;

    private User $lecteurSansDroit;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private Programme $programmeA;

    private Matiere $matiereA;

    private Group $groupeA;

    private SessionExamen $session;

    private Examen $examen;

    private Epreuve $epreuve;

    private Room $salleInconnue;

    private Room $salleValide50;

    private Room $salleValide100;

    private Room $salleTropPetite;

    private Room $salleInactive;

    private Room $salleFaculteB;

    private GroupeVirtuelExamen $groupeVirtuel;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculteA = Faculte::create(['nom' => 'Sciences et Ingénierie', 'code' => 'FSI', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'Faculté de Droit', 'code' => 'DROIT', 'active' => true]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Responsable Scolarité',
            'email' => 'resp.scol.'.uniqid().'@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->adminFaculteB = User::creerCompteBackOffice([
            'name' => 'Admin Droit',
            'email' => 'admin.droit.'.uniqid().'@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');

        $this->lecteurSansDroit = User::factory()->create([
            'email' => 'sans.droit.'.uniqid().'@universite.test',
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programmeA = Programme::create([
            'faculte_id' => $this->faculteA->id,
            'code' => 'INFO-L',
            'nom' => 'Licence Informatique',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);

        $niveau = Niveau::create(['programme_id' => $this->programmeA->id, 'code' => 'L3', 'nom' => 'L3', 'ordre' => 3]);
        $semestre = SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => 'S5', 'nom' => 'S5', 'numero' => 5, 'ordre' => 5]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id,
            'programme_id' => $this->programmeA->id,
            'code' => 'UE-INFO-301',
            'nom' => 'Informatique Fondamentale',
            'credits' => 12,
            'coefficient' => 4,
        ]);

        $this->matiereA = Matiere::create([
            'code' => 'INF301',
            'nom' => 'Algorithmique Avancée',
            'ue_id' => $ue->id,
            'niveau' => 'L3',
            'semestre' => 5,
            'credits' => 6,
            'coefficient' => 2,
            'heures_cm' => 24,
            'heures_td' => 24,
        ]);

        $this->groupeA = Group::create([
            'code' => 'L3-INFO-G1',
            'nom' => 'Groupe L3 Info 1',
            'programme_id' => $this->programmeA->id,
            'faculte_id' => $this->faculteA->id,
            'niveau' => 'L3',
            'niveau_id' => $niveau->id,
            'annee_universitaire_id' => $this->annee->id,
            'capacite' => 40,
            'actif' => true,
        ]);

        $batiment = Batiment::create(['faculte_id' => $this->faculteA->id, 'nom' => 'Bâtiment Sciences', 'code' => 'BAT-S']);

        // Salle avec capacité inconnue (NULL)
        $this->salleInconnue = Room::create([
            'code' => 'SALLE-INC',
            'nom' => 'Salle Sans Capacité',
            'faculte_id' => $this->faculteA->id,
            'batiment_id' => $batiment->id,
            'capacite' => null,
            'actif' => true,
            'type_salle' => 'td',
            'heure_ouverture' => '08:00',
            'heure_fermeture' => '19:00',
        ]);

        // Salle valide 50 places nominales (25 utiles en D-04)
        $this->salleValide50 = Room::create([
            'code' => 'SALLE-V50',
            'nom' => 'Salle TD 50',
            'faculte_id' => $this->faculteA->id,
            'batiment_id' => $batiment->id,
            'capacite' => 50,
            'actif' => true,
            'type_salle' => 'td',
            'heure_ouverture' => '08:00',
            'heure_fermeture' => '19:00',
        ]);

        // Salle valide 100 places nominales (50 utiles en D-04)
        $this->salleValide100 = Room::create([
            'code' => 'AMPHI-100',
            'nom' => 'Amphithéâtre 100',
            'faculte_id' => $this->faculteA->id,
            'batiment_id' => $batiment->id,
            'capacite' => 100,
            'actif' => true,
            'type_salle' => 'amphitheatre',
            'heure_ouverture' => '08:00',
            'heure_fermeture' => '19:00',
        ]);

        // Salle trop petite (10 nominales => 5 utiles)
        $this->salleTropPetite = Room::create([
            'code' => 'SALLE-P10',
            'nom' => 'Petite Salle 10',
            'faculte_id' => $this->faculteA->id,
            'batiment_id' => $batiment->id,
            'capacite' => 10,
            'actif' => true,
            'type_salle' => 'tp',
            'heure_ouverture' => '08:00',
            'heure_fermeture' => '19:00',
        ]);

        // Salle inactive
        $this->salleInactive = Room::create([
            'code' => 'SALLE-INACT',
            'nom' => 'Salle Fermée',
            'faculte_id' => $this->faculteA->id,
            'batiment_id' => $batiment->id,
            'capacite' => 80,
            'actif' => false,
            'type_salle' => 'td',
        ]);

        // Salle faculté B (isolation BOLA)
        $this->salleFaculteB = Room::create([
            'code' => 'SALLE-DROIT-60',
            'nom' => 'Salle Droit 60',
            'faculte_id' => $this->faculteB->id,
            'capacite' => 60,
            'actif' => true,
            'type_salle' => 'td',
        ]);

        // Session et examen
        $this->session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-2026-N',
            'nom' => 'Session Normale 2026-2027',
            'type' => 'principale',
            'date_debut' => '2026-12-01',
            'date_fin' => '2026-12-20',
            'statut' => 'draft',
        ]);

        $this->examen = Examen::create([
            'annee_universitaire_id' => $this->annee->id,
            'session_id' => $this->session->id,
            'programme_id' => $this->programmeA->id,
            'niveau_id' => $niveau->id,
            'code' => 'EXAM-INF301',
            'libelle' => 'Examen Algorithmique',
            'statut' => 'draft',
        ]);

        // Épreuve avec salle inconnue
        $this->epreuve = Epreuve::create([
            'annee_universitaire_id' => $this->annee->id,
            'examen_id' => $this->examen->id,
            'session_id' => $this->session->id,
            'matiere_id' => $this->matiereA->id,
            'groupe_id' => $this->groupeA->id,
            'salle_id' => $this->salleInconnue->id,
            'code' => 'EPR-INF301-01',
            'titre' => 'Épreuve Écrite Algorithmique',
            'date' => '2026-12-05',
            'heure_debut' => '09:00:00',
            'heure_fin' => '11:00:00',
            'duree_minutes' => 120,
            'statut' => 'draft',
            'capacite_necessaire' => 20,
        ]);

        // Créer 20 étudiants éligibles
        $etudiants = [];
        for ($i = 1; $i <= 20; $i++) {
            $e = Etudiant::create([
                'cin' => 'CIN-'.$i,
                'num_inscription' => 'INS-'.$i,
                'nom' => 'Etudiant'.$i,
                'prenom' => 'Prenom'.$i,
                'email' => 'etudiant'.$i.'@universite.test',
                'date_naissance' => '2004-05-15',
                'sexe' => 'M',
                'groupe_id' => $this->groupeA->id,
                'faculte_id' => $this->faculteA->id,
                'niveau' => 'L3',
                'annee_academique' => '2026-2027',
                'adresse' => 'Campus Universitaire',
                'ville' => 'Tunis',
                'code_postal' => '1002',
                'annee_bac' => 2024,
                'password' => 'Etudiant@123',
            ]);
            $e->definirStatut('valide');

            $inscAcad = InscriptionAcademique::create([
                'etudiant_id' => $e->id,
                'annee_universitaire_id' => $this->annee->id,
                'programme_id' => $this->programmeA->id,
                'niveau' => 'L3',
                'statut' => 'valide',
            ]);

            InscriptionPedagogique::create([
                'inscription_id' => $inscAcad->id,
                'semestre_id' => $semestre->id,
                'ue_id' => $ue->id,
                'matiere_id' => $this->matiereA->id,
                'statut' => 'inscrit',
            ]);

            Candidat::create([
                'epreuve_id' => $this->epreuve->id,
                'etudiant_id' => $e->id,
                'salle_id' => $this->salleInconnue->id,
                'place' => 'Place '.$i,
                'statut' => 'registered',
            ]);

            $etudiants[] = $e;
        }

        // Groupe virtuel associé
        $this->groupeVirtuel = GroupeVirtuelExamen::create([
            'uuid' => (string) Str::uuid(),
            'cle' => 'GV-TEST-001',
            'libelle' => 'GV Algorithmique L3',
            'epreuve_id' => $this->epreuve->id,
            'examen_id' => $this->examen->id,
            'matiere_id' => $this->matiereA->id,
            'programme_id' => $this->programmeA->id,
            'niveau_id' => $niveau->id,
            'sources' => [$this->groupeA->id],
            'statut' => GroupeVirtuelExamen::STATUT_PROPOSE,
            'created_by' => $this->admin->id,
        ]);

        foreach ($etudiants as $e) {
            GroupeVirtuelMembre::create([
                'groupe_virtuel_id' => $this->groupeVirtuel->id,
                'etudiant_id' => $e->id,
                'groupe_source_id' => $this->groupeA->id,
                'decision' => GroupeVirtuelMembre::DECISION_ELIGIBLE,
                'regle_appliquee' => 'inscription_pedagogique_validee',
            ]);
        }
    }

    /** 1. Capacité inconnue ou nulle bloque l'export et déclenche l'anomalie bloquante. */
    public function test_capacite_inconnue_ou_nulle_bloque_export_officiel_et_declenche_anomalie_bloquante(): void
    {
        $service = app(ConsultationExportGroupeVirtuelService::class);
        $fiche = $service->construireFicheDetaillee($this->groupeVirtuel, $this->admin);

        // L'export officiel DOIT être bloqué
        $this->assertFalse($fiche['export_officiel_autorise']);

        // Une alerte bloquante explicite doit être présente
        $bloquants = $fiche['bloquants'];
        $this->assertNotEmpty($bloquants);
        $this->assertTrue(collect($bloquants)->contains(fn ($b) => str_contains($b, 'Capacité de salle non confirmée (D-04)')));

        // Tentative d'export via HTTP : doit être bloquée avec 422
        $this->actingAs($this->admin)
            ->get(route('admin.evaluation.examens.groupes-virtuels.groupe.imprimer', [
                'groupeVirtuel' => $this->groupeVirtuel->id,
                'document' => 'fiche',
            ]))
            ->assertStatus(422)
            ->assertSee('Capacité de salle non confirmée');
    }

    /** 2. Capacité insuffisante bloque l'export officiel. */
    public function test_capacite_insuffisante_bloque_export_officiel(): void
    {
        $this->epreuve->update(['salle_id' => $this->salleTropPetite->id]);

        $service = app(ConsultationExportGroupeVirtuelService::class);
        $fiche = $service->construireFicheDetaillee($this->groupeVirtuel, $this->admin);

        $this->assertFalse($fiche['export_officiel_autorise']);
        $this->assertTrue(collect($fiche['bloquants'])->contains(fn ($b) => str_contains($b, 'Capacité de salle insuffisante')));
    }

    /** 3. Diagnostic opposable détecte la capacité non confirmée et propose les salles candidates. */
    public function test_diagnostic_lecture_seule_detecte_capacite_non_confirmee_et_propose_salles_compatibles(): void
    {
        $decisionService = app(ExamDecisionCapacitePermutationService::class);
        $diagnostic = $decisionService->diagnostiquerCapaciteSalle($this->epreuve, $this->admin);

        // Statut salle actuelle
        $this->assertSame('non_confirmee', $diagnostic['salle_actuelle']['statut_capacite']);
        $this->assertTrue($diagnostic['salle_actuelle']['est_non_confirmee']);
        $this->assertSame(20, $diagnostic['effectif_requis']);

        // Salles candidates : SALLE-V50 (25 utiles >= 20) et AMPHI-100 (50 utiles >= 20)
        $this->assertSame('candidates_trouvees', $diagnostic['diagnostic_global']);
        $this->assertGreaterThanOrEqual(2, $diagnostic['nb_candidates']);

        $codesCandidates = array_column($diagnostic['candidates_compatibles'], 'code');
        $this->assertContains('SALLE-V50', $codesCandidates);
        $this->assertContains('AMPHI-100', $codesCandidates);

        // Tri déterministe par surplus croissant (V50 a 5 places de surplus, AMPHI-100 en a 30)
        $this->assertSame('SALLE-V50', $diagnostic['candidates_compatibles'][0]['code']);
        $this->assertSame(5, $diagnostic['candidates_compatibles'][0]['surplus_places']);

        // Export non autorisé et mode lecture seule
        $this->assertFalse($diagnostic['export_officiel_autorise']);
        $this->assertTrue($diagnostic['action_lecture_seule']);
        $this->assertNotEmpty($diagnostic['empreinte']);
    }

    /** 4. Les salles inadmissibles sont exclues avec motifs explicites. */
    public function test_salles_inadmissibles_sont_exclues_avec_motifs_explicites(): void
    {
        $decisionService = app(ExamDecisionCapacitePermutationService::class);
        $diagnostic = $decisionService->diagnostiquerCapaciteSalle($this->epreuve, $this->admin);

        $sallesExclues = collect($diagnostic['salles_exclues'])->keyBy('code');

        // Salle inactive
        $this->assertTrue($sallesExclues->has('SALLE-INACT'));
        $this->assertContains('salle_inactive', $sallesExclues['SALLE-INACT']['types_exclusion']);

        // Salle trop petite
        $this->assertTrue($sallesExclues->has('SALLE-P10'));
        $this->assertContains('capacite_insuffisante', $sallesExclues['SALLE-P10']['types_exclusion']);

        // Salle actuelle
        $this->assertTrue($sallesExclues->has('SALLE-INC'));
        $this->assertContains('salle_actuelle', $sallesExclues['SALLE-INC']['types_exclusion']);
    }

    /** 5. Conflits d'horaires et indisponibilités excluent rigoureusement la candidate. */
    public function test_conflit_creneau_exclut_salle_candidate(): void
    {
        // Créer une épreuve concurrente sur SALLE-V50 au même créneau
        Epreuve::create([
            'annee_universitaire_id' => $this->annee->id,
            'examen_id' => $this->examen->id,
            'session_id' => $this->session->id,
            'matiere_id' => $this->matiereA->id,
            'groupe_id' => $this->groupeA->id,
            'salle_id' => $this->salleValide50->id,
            'code' => 'EPR-CONCURRENTE',
            'titre' => 'Épreuve concurrente',
            'date' => '2026-12-05',
            'heure_debut' => '09:00:00',
            'heure_fin' => '11:00:00',
            'statut' => 'draft',
        ]);

        $decisionService = app(ExamDecisionCapacitePermutationService::class);
        $diagnostic = $decisionService->diagnostiquerCapaciteSalle($this->epreuve, $this->admin);

        $codesCandidates = array_column($diagnostic['candidates_compatibles'], 'code');
        $this->assertNotContains('SALLE-V50', $codesCandidates);

        $sallesExclues = collect($diagnostic['salles_exclues'])->keyBy('code');
        $this->assertTrue($sallesExclues->has('SALLE-V50'));
        $this->assertContains('collision_salle', $sallesExclues['SALLE-V50']['types_exclusion']);
    }

    /** 6. Distinction nette entre données insuffisantes et absence de candidates. */
    public function test_distinction_entre_donnees_insuffisantes_et_absence_de_candidates(): void
    {
        $decisionService = app(ExamDecisionCapacitePermutationService::class);

        // A. Données insuffisantes (effectif nul ou non déterminé)
        $matiereVide = Matiere::create([
            'code' => 'MAT-VIDE',
            'nom' => 'Matière Sans Inscrits',
            'ue_id' => $this->matiereA->ue_id,
            'niveau' => 'L3',
            'semestre' => 5,
            'credits' => 3,
            'coefficient' => 1,
        ]);

        $epreuveSansHoraire = Epreuve::create([
            'annee_universitaire_id' => $this->annee->id,
            'examen_id' => $this->examen->id,
            'session_id' => $this->session->id,
            'matiere_id' => $matiereVide->id,
            'code' => 'EPR-SANS-EFFECTIF',
            'date' => '2026-12-05',
            'heure_debut' => '09:00:00',
            'heure_fin' => '11:00:00',
            'statut' => 'draft',
            'capacite_necessaire' => 0,
        ]);

        $diagInsuffisant = $decisionService->diagnostiquerCapaciteSalle($epreuveSansHoraire, $this->admin);
        $this->assertSame('donnees_insuffisantes', $diagInsuffisant['diagnostic_global']);
        $this->assertEmpty($diagInsuffisant['candidates_compatibles']);

        // B. Absence de candidates (horaire valide mais besoin de 1000 places qu'aucune salle ne peut fournir)
        $epreuveGrosEffectif = Epreuve::create([
            'annee_universitaire_id' => $this->annee->id,
            'examen_id' => $this->examen->id,
            'session_id' => $this->session->id,
            'matiere_id' => $matiereVide->id,
            'code' => 'EPR-MEGA',
            'date' => '2026-12-05',
            'heure_debut' => '14:00:00',
            'heure_fin' => '16:00:00',
            'statut' => 'draft',
            'capacite_necessaire' => 1000,
        ]);

        $diagAucune = $decisionService->diagnostiquerCapaciteSalle($epreuveGrosEffectif, $this->admin);
        $this->assertSame('aucune_candidate', $diagAucune['diagnostic_global']);
        $this->assertEmpty($diagAucune['candidates_compatibles']);
        $this->assertNotEmpty($diagAucune['salles_exclues']);
    }

    /** 7. Intégrité stricte lecture seule : aucune mutation sur le planning ou les épreuves. */
    public function test_integrite_strictement_lecture_seule_aucune_mutation_de_planning(): void
    {
        $epreuveAvant = $this->epreuve->fresh()->toArray();
        $sallesAvant = Room::all()->toArray();
        $candidatsAvant = Candidat::where('epreuve_id', $this->epreuve->id)->get()->toArray();

        // Appel direct au service
        $service = app(ExamDecisionCapacitePermutationService::class);
        $service->diagnostiquerCapaciteSalle($this->epreuve, $this->admin);

        // Appel via contrôleur HTTP
        $response = $this->actingAs($this->admin)
            ->getJson(route('admin.evaluation.examens.groupes.capacite.diagnostic-salle', [
                'epreuve_id' => $this->epreuve->id,
            ]));
        $response->assertOk();

        // Vérification de non-mutation absolue
        $this->assertSame($epreuveAvant, $this->epreuve->fresh()->toArray());
        $this->assertSame($sallesAvant, Room::all()->toArray());
        $this->assertSame($candidatsAvant, Candidat::where('epreuve_id', $this->epreuve->id)->get()->toArray());
    }

    /** 8. Contrôle d'accès et isolation multi-facultés (BOLA). */
    public function test_controle_acces_et_isolation_facultaire_bola(): void
    {
        // 1. Accès refusé sans permission
        $this->actingAs($this->lecteurSansDroit)
            ->getJson(route('admin.evaluation.examens.groupes.capacite.diagnostic-salle', [
                'epreuve_id' => $this->epreuve->id,
            ]))
            ->assertForbidden();

        // 2. Accès refusé cross-faculté (BOLA)
        $this->actingAs($this->adminFaculteB)
            ->getJson(route('admin.evaluation.examens.groupes.capacite.diagnostic-salle', [
                'epreuve_id' => $this->epreuve->id,
            ]))
            ->assertForbidden();

        // 3. Aucune fuite de salle de Faculté B dans les propositions pour Faculté A
        $decisionService = app(ExamDecisionCapacitePermutationService::class);
        $diagnostic = $decisionService->diagnostiquerCapaciteSalle($this->epreuve, $this->admin);

        $codesCompatibles = array_column($diagnostic['candidates_compatibles'], 'code');
        $this->assertNotContains('SALLE-DROIT-60', $codesCompatibles);

        $sallesExclues = collect($diagnostic['salles_exclues'])->keyBy('code');
        if ($sallesExclues->has('SALLE-DROIT-60')) {
            $this->assertContains('salle_hors_faculte', $sallesExclues['SALLE-DROIT-60']['types_exclusion']);
        }
    }

    /** 9. Détection de l'obsolescence après modification des données de référence. */
    public function test_detection_obsolescence_diagnostic_quand_donnees_changent(): void
    {
        $service = app(ExamDecisionCapacitePermutationService::class);
        $initial = $service->diagnostiquerCapaciteSalle($this->epreuve, $this->admin);
        $empreinteInitiale = $initial['empreinte'];

        // Tant que rien ne bouge, pas obsolète
        $this->assertFalse($service->estDiagnosticObsolete($empreinteInitiale, $this->epreuve));

        // Modification de l'horaire de l'épreuve
        $this->epreuve->update(['heure_debut' => '14:00:00', 'heure_fin' => '16:00:00']);

        // Doit être détecté obsolète
        $this->assertTrue($service->estDiagnosticObsolete($empreinteInitiale, $this->epreuve->fresh()));

        // Via API HTTP avec paramètre empreinte
        $this->actingAs($this->admin)
            ->getJson(route('admin.evaluation.examens.groupes.capacite.diagnostic-salle', [
                'epreuve_id' => $this->epreuve->id,
                'empreinte' => $empreinteInitiale,
            ]))
            ->assertOk()
            ->assertJsonPath('diagnostic.est_obsolete', true);
    }

    /** 10. Traçabilité d'audit sans PII lors de la consultation. */
    public function test_journalisation_audit_sans_pii(): void
    {
        $service = app(ExamDecisionCapacitePermutationService::class);
        $service->diagnostiquerCapaciteSalle($this->epreuve, $this->admin);

        $log = IntegrationLog::query()
            ->where('operation', 'exam.room.diagnostic_consulted')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('EPR-'.$this->epreuve->id, $log->external_reference);
        $this->assertNotNull($log->payload_hash);
    }
}
