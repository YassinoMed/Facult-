<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\EvaluationCompostage;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\NoteEpreuve;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\CompostageService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Tests du numéro de compostage et de la saisie anonyme des notes (§1-§24).
 */
class EvaluationCompostageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $enseignant;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private Epreuve $epreuve1;

    private Epreuve $epreuve2;

    private Etudiant $etudiant1;

    private Etudiant $etudiant2;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté des Sciences',
            'code' => 'FST',
            'description' => 'Sciences',
            'active' => true,
        ]);

        $this->faculteB = Faculte::create([
            'nom' => 'Faculté de Médecine',
            'code' => 'FMP',
            'description' => 'Médecine',
            'active' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Directeur Examens',
            'email' => 'admin.examens@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->enseignant = User::creerCompteBackOffice([
            'name' => 'Agent Saisie',
            'email' => 'agent.saisie@universite.test',
            'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $prog = Programme::create([
            'faculte_id' => $this->faculteA->id,
            'code' => 'LGL',
            'nom' => 'Licence Génie Logiciel',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);

        $niv = Niveau::create(['programme_id' => $prog->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $sem = SemestreLmd::create(['niveau_id' => $niv->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1]);

        $ue = UniteEnseignement::create([
            'semestre_id' => $sem->id,
            'programme_id' => $prog->id,
            'code' => 'UE-INF1',
            'nom' => 'Fondements Informatique',
            'credits' => 6,
            'coefficient' => 2,
        ]);

        $mat1 = Matiere::create([
            'code' => 'INFO-101',
            'nom' => 'Algorithmique',
            'ue_id' => $ue->id,
            'niveau' => 'L1',
            'semestre' => 1,
            'credits' => 3,
            'coefficient' => 1.5,
        ]);

        $mat2 = Matiere::create([
            'code' => 'INFO-102',
            'nom' => 'Architecture',
            'ue_id' => $ue->id,
            'niveau' => 'L1',
            'semestre' => 1,
            'credits' => 3,
            'coefficient' => 1.5,
        ]);

        $groupe = Group::create([
            'code' => 'GL-G1',
            'nom' => 'Groupe 1',
            'faculte_id' => $this->faculteA->id,
            'niveau' => 'L1',
            'actif' => true,
        ]);

        $salle = Room::create(['code' => 'AMPHI-A', 'nom' => 'Amphi A', 'capacite' => 100, 'actif' => true]);

        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-2027-P1',
            'nom' => 'Session Principale S1',
            'type' => 'principale',
            'date_debut' => '2027-01-10',
            'date_fin' => '2027-01-25',
            'statut' => 'open',
        ]);

        $examen = Examen::create([
            'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id,
            'semestre_lmd_id' => $sem->id,
            'programme_id' => $prog->id,
            'niveau_id' => $niv->id,
            'groupe_id' => $groupe->id,
            'libelle' => 'Examens S1',
            'statut' => 'scheduled',
        ]);

        $this->epreuve1 = Epreuve::create([
            'examen_id' => $examen->id,
            'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $mat1->id,
            'groupe_id' => $groupe->id,
            'salle_id' => $salle->id,
            'date' => '2027-01-12',
            'heure_debut' => '08:30:00',
            'heure_fin' => '10:30:00',
            'coefficient' => 1.5,
            'bareme' => 20,
            'note_max' => 20,
            'statut' => 'scheduled',
        ]);

        $this->epreuve2 = Epreuve::create([
            'examen_id' => $examen->id,
            'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $mat2->id,
            'groupe_id' => $groupe->id,
            'salle_id' => $salle->id,
            'date' => '2027-01-14',
            'heure_debut' => '08:30:00',
            'heure_fin' => '10:30:00',
            'coefficient' => 1.5,
            'bareme' => 20,
            'note_max' => 20,
            'statut' => 'scheduled',
        ]);

        $this->etudiant1 = Etudiant::create([
            'nom' => 'Ben Ali',
            'prenom' => 'Kais',
            'cin' => '08765432',
            'email' => 'kais.benali@etudiant.test',
            'telephone' => '98123456',
            'date_naissance' => '2004-05-10',
            'sexe' => 'M',
            'faculte_id' => $this->faculteA->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '12 rue',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2024',
            'password' => 'Etudiant@123',
            'statut' => 'valide',
            'num_inscription' => 'ETU-2026-0001',
        ]);

        $this->etudiant2 = Etudiant::create([
            'nom' => 'Trabelsi',
            'prenom' => 'Sarra',
            'cin' => '12345678',
            'email' => 'sarra.trabelsi@etudiant.test',
            'telephone' => '98654321',
            'date_naissance' => '2004-09-15',
            'sexe' => 'F',
            'faculte_id' => $this->faculteA->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '15 rue',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2024',
            'password' => 'Etudiant@123',
            'statut' => 'valide',
            'num_inscription' => 'ETU-2026-0002',
        ]);

        // Inscription des candidats aux épreuves
        Candidat::create(['epreuve_id' => $this->epreuve1->id, 'etudiant_id' => $this->etudiant1->id, 'statut' => 'present']);
        Candidat::create(['epreuve_id' => $this->epreuve1->id, 'etudiant_id' => $this->etudiant2->id, 'statut' => 'present']);
        Candidat::create(['epreuve_id' => $this->epreuve2->id, 'etudiant_id' => $this->etudiant1->id, 'statut' => 'present']);
    }

    /**
     * §5 : Format CMP-XXXXXX, non prédictible, et distinct d'une épreuve à l'autre pour un même étudiant (§3).
     */
    public function test_generation_numero_format_et_portee(): void
    {
        $service = app(CompostageService::class);

        $comp1 = $service->getOrCreate($this->epreuve1, $this->etudiant1);
        $comp2 = $service->getOrCreate($this->epreuve2, $this->etudiant1);

        $this->assertMatchesRegularExpression('/^CMP-\d{6}$/', $comp1->numero);
        $this->assertMatchesRegularExpression('/^CMP-\d{6}$/', $comp2->numero);

        // Portée contextuelle : numéros différents pour deux épreuves distinctes du même étudiant
        $this->assertNotEquals($comp1->numero, $comp2->numero);
        $this->assertEquals($this->epreuve1->id, $comp1->epreuve_id);
        $this->assertEquals($this->epreuve2->id, $comp2->epreuve_id);
        $this->assertEquals($this->etudiant1->id, $comp1->etudiant_id);
    }

    /**
     * §4 & §20 : Unicité stricte par (epreuve_id, etudiant_id) et (epreuve_id, numero).
     */
    public function test_unicite_et_idempotence_compostage(): void
    {
        $service = app(CompostageService::class);

        $compA = $service->getOrCreate($this->epreuve1, $this->etudiant1);
        $compB = $service->getOrCreate($this->epreuve1, $this->etudiant1);

        // Même instance/ID retourné sans doublon
        $this->assertEquals($compA->id, $compB->id);
        $this->assertEquals($compA->numero, $compB->numero);
        $this->assertEquals(1, EvaluationCompostage::where('epreuve_id', $this->epreuve1->id)->where('etudiant_id', $this->etudiant1->id)->count());
    }

    /**
     * §9 & §11 : Saisie anonyme via numéro de compostage (aucune exposition de student_id).
     */
    public function test_saisie_note_via_compostage_anonyme(): void
    {
        $service = app(CompostageService::class);
        $comp1 = $service->getOrCreate($this->epreuve1, $this->etudiant1);
        $comp2 = $service->getOrCreate($this->epreuve1, $this->etudiant2);

        $response = $this->actingAs($this->enseignant)->post(route('admin.evaluations.notes.store-anonyme', $this->epreuve1), [
            'lignes' => [
                ['numero' => $comp1->numero, 'valeur' => 14.50],
                ['numero' => $comp2->numero, 'valeur' => 11.00],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('succes');

        // Vérification de la note en base pour les étudiants réels
        $note1 = NoteEpreuve::where('epreuve_id', $this->epreuve1->id)->where('etudiant_id', $this->etudiant1->id)->firstOrFail();
        $this->assertEquals(14.50, (float) $note1->valeur);
        $this->assertEquals('entered', $note1->statut);

        $note2 = NoteEpreuve::where('epreuve_id', $this->epreuve1->id)->where('etudiant_id', $this->etudiant2->id)->firstOrFail();
        $this->assertEquals(11.00, (float) $note2->valeur);
    }

    /**
     * §10 : Contrôle strict des bornes 0 <= note <= note_max.
     */
    public function test_validation_bornes_notes_anonymes(): void
    {
        $service = app(CompostageService::class);
        $comp = $service->getOrCreate($this->epreuve1, $this->etudiant1);

        // Note négative rejetée
        $this->actingAs($this->enseignant)->post(route('admin.evaluations.notes.store-anonyme', $this->epreuve1), [
            'lignes' => [['numero' => $comp->numero, 'valeur' => -1.5]],
        ])->assertSessionHasErrors();

        // Note supérieure à note_max (20) rejetée
        $this->actingAs($this->enseignant)->post(route('admin.evaluations.notes.store-anonyme', $this->epreuve1), [
            'lignes' => [['numero' => $comp->numero, 'valeur' => 20.5]],
        ])->assertSessionHasErrors();

        // Note 0 acceptée
        $this->actingAs($this->enseignant)->post(route('admin.evaluations.notes.store-anonyme', $this->epreuve1), [
            'lignes' => [['numero' => $comp->numero, 'valeur' => 0.0]],
        ])->assertSessionHasNoErrors();

        // Note 20 acceptée
        $this->actingAs($this->enseignant)->post(route('admin.evaluations.notes.store-anonyme', $this->epreuve1), [
            'lignes' => [['numero' => $comp->numero, 'valeur' => 20.0]],
        ])->assertSessionHasNoErrors();
    }

    /**
     * §6 & §23 : L'écran de saisie ne divulgue AUCUNE PII (nom, prénom, CIN, email, téléphone, etudiant_id).
     */
    public function test_anonymisation_ecran_saisie_ne_divulgue_aucune_pii(): void
    {
        $service = app(CompostageService::class);
        $comp1 = $service->getOrCreate($this->epreuve1, $this->etudiant1);

        $response = $this->actingAs($this->enseignant)->get(route('admin.evaluations.notes.saisie', $this->epreuve1));
        $response->assertOk();

        $contenu = $response->getContent();

        // Le numéro de compostage est bien présent
        $this->assertStringContainsString($comp1->numero, $contenu);

        // Aucune donnée d'identification personnelle de l'étudiant
        $this->assertStringNotContainsString('Ben Ali', $contenu);
        $this->assertStringNotContainsString('Kais', $contenu);
        $this->assertStringNotContainsString('08765432', $contenu);
        $this->assertStringNotContainsString('kais.benali@etudiant.test', $contenu);
        $this->assertStringNotContainsString('98123456', $contenu);

        // Aucun champ caché etudiant_id
        $this->assertStringNotContainsString('name="lignes[0][etudiant_id]"', $contenu);
        $this->assertStringContainsString('name="lignes[0][numero]"', $contenu);
    }

    /**
     * §13 : L'enseignant sans permission ne peut PAS accéder à la levée d'anonymat (403).
     */
    public function test_enseignant_sans_permission_ne_peut_pas_resoudre_compostage(): void
    {
        $this->actingAs($this->enseignant)
            ->get(route('admin.evaluations.epreuves.correspondance', $this->epreuve1))
            ->assertForbidden();
    }

    /**
     * §13 & §18 : L'administrateur autorisé peut consulter la correspondance et cela est audité.
     */
    public function test_admin_autorise_consulte_correspondance_et_est_audite(): void
    {
        $service = app(CompostageService::class);
        $comp1 = $service->getOrCreate($this->epreuve1, $this->etudiant1);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.evaluations.epreuves.correspondance', $this->epreuve1));

        $response->assertOk();
        $response->assertSee($comp1->numero);
        $response->assertSee('Ben Ali');
        $response->assertSee('Kais');

        // Audit de la résolution d'identité
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $this->etudiant1->id,
            'action' => 'compostage.resolved',
            'entite_id' => $this->epreuve1->id,
        ]);
    }

    /**
     * §19 : Sécurité IDOR/BOLA : Rejet si utilisation d'un compostage d'une autre épreuve.
     */
    public function test_idor_rejet_compostage_d_une_autre_epreuve(): void
    {
        $service = app(CompostageService::class);
        // Compostage généré pour l'épreuve 2
        $compEpreuve2 = $service->getOrCreate($this->epreuve2, $this->etudiant1);

        // Tentative de soumission du compostage de l'épreuve 2 sur l'épreuve 1
        $response = $this->actingAs($this->enseignant)->post(route('admin.evaluations.notes.store-anonyme', $this->epreuve1), [
            'lignes' => [
                ['numero' => $compEpreuve2->numero, 'valeur' => 15.0],
            ],
        ]);

        $response->assertSessionHasErrors();

        // Aucune note n'a été insérée pour l'épreuve 1
        $this->assertDatabaseMissing('evaluation_notes', [
            'epreuve_id' => $this->epreuve1->id,
            'valeur' => 15.0,
        ]);
    }

    /**
     * §19 : Isolation faculté (BOLA) : accès hors faculté retourne 404.
     */
    public function test_faculty_scoping_404_hors_faculte(): void
    {
        $adminFacB = User::creerCompteBackOffice([
            'name' => 'Admin Médecine',
            'email' => 'admin.med@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');

        // Épreuve de la faculté A consultée par admin restreint faculté B
        $this->actingAs($adminFacB)
            ->get(route('admin.evaluations.notes.saisie', $this->epreuve1))
            ->assertNotFound();
    }

    /**
     * §14 : Verrouillage : génération interdite une fois l'épreuve verrouillée.
     */
    public function test_interdiction_generation_si_epreuve_verrouillee(): void
    {
        $this->epreuve1->update(['statut' => 'locked']);

        $service = app(CompostageService::class);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Épreuve verrouillée');

        $service->getOrCreate($this->epreuve1, $this->etudiant1);
    }

    /**
     * §17 : Export CSV anonyme sans PII.
     */
    public function test_export_csv_anonyme_sans_pii(): void
    {
        $service = app(CompostageService::class);
        $comp = $service->getOrCreate($this->epreuve1, $this->etudiant1);

        $response = $this->actingAs($this->enseignant)
            ->get(route('admin.evaluations.notes.export-anonyme', $this->epreuve1));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));

        $contenu = $response->streamedContent();
        $this->assertStringContainsString('compostage,note,statut', $contenu);
        $this->assertStringContainsString($comp->numero, $contenu);
        $this->assertStringNotContainsString('Ben Ali', $contenu);
        $this->assertStringNotContainsString('08765432', $contenu);
    }

    /**
     * §16 : Import CSV anonyme valide met à jour les notes.
     */
    public function test_import_csv_anonyme_valide(): void
    {
        $service = app(CompostageService::class);
        $comp1 = $service->getOrCreate($this->epreuve1, $this->etudiant1);
        $comp2 = $service->getOrCreate($this->epreuve1, $this->etudiant2);

        $csvContenu = "compostage,note\n{$comp1->numero},16.5\n{$comp2->numero},13.0\n";
        $fichier = UploadedFile::fake()->createWithContent('notes.csv', $csvContenu);

        $response = $this->actingAs($this->admin)->post(route('admin.evaluations.notes.import-anonyme', $this->epreuve1), [
            'fichier' => $fichier,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('succes');

        $this->assertDatabaseHas('evaluation_notes', [
            'epreuve_id' => $this->epreuve1->id,
            'etudiant_id' => $this->etudiant1->id,
            'valeur' => 16.50,
        ]);

        $this->assertDatabaseHas('evaluation_notes', [
            'epreuve_id' => $this->epreuve1->id,
            'etudiant_id' => $this->etudiant2->id,
            'valeur' => 13.00,
        ]);
    }

    /**
     * §16 : Rejet absolu si le CSV d'import anonyme contient student_id ou etudiant_id.
     */
    public function test_import_csv_anonyme_rejette_student_id(): void
    {
        $csvContenu = "student_id,compostage,note\n1,CMP-123456,15.0\n";
        $fichier = UploadedFile::fake()->createWithContent('notes_avec_student_id.csv', $csvContenu);

        $response = $this->actingAs($this->admin)->post(route('admin.evaluations.notes.import-anonyme', $this->epreuve1), [
            'fichier' => $fichier,
        ]);

        $response->assertSessionHasErrors('fichier');
    }
}
