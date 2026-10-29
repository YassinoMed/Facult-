<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\EpreuveService;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Examens via population centrale (§9-12, §31) : génération candidats,
 * émargement PDF unifié, liens CSV — workflow examen inchangé.
 */
class ExamCandidatePopulationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Group $groupe;

    private Matiere $matiere;

    private Epreuve $epreuve;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.evaluation.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.exam@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->groupe = Group::create([
            'code' => 'EX1', 'nom' => 'EX1', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'capacite' => 30, 'actif' => true,
        ]);
        $this->matiere = Matiere::create([
            'code' => 'EXM01', 'nom' => 'Examen', 'niveau' => 'L1', 'semestre' => 1, 'credits' => 4,
        ]);

        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'S-PRINC-EX', 'nom' => 'Session principale', 'type' => 'principale',
            'date_debut' => '2026-06-01', 'date_fin' => '2026-06-30',
            'statut' => 'open', 'created_by' => $this->admin->id,
        ]);
        $examen = Examen::create([
            'session_id' => $session->id, 'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examen S1', 'type' => 'examen', 'statut' => 'scheduled',
            'responsable_id' => $this->admin->id,
        ]);
        $salle = Room::create(['code' => 'A101', 'nom' => 'A101', 'capacite' => 30, 'actif' => true]);
        $this->epreuve = Epreuve::create([
            'examen_id' => $examen->id, 'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiere->id, 'groupe_id' => $this->groupe->id,
            'salle_id' => $salle->id, 'date' => '2026-06-10',
            'heure_debut' => '08:00', 'heure_fin' => '10:00',
            'coefficient' => 1, 'bareme' => 20, 'note_max' => 20, 'statut' => 'scheduled',
        ]);
    }

    public function test_generer_candidats_via_population(): void
    {
        $etudiant = $this->creerEtudiant('EXA00001');
        $this->actingAs($this->admin);

        $crees = app(EpreuveService::class)->genererCandidats($this->epreuve);

        $this->assertSame(1, $crees);
        $this->assertDatabaseHas('evaluation_candidats', [
            'epreuve_id' => $this->epreuve->id, 'etudiant_id' => $etudiant->id,
        ]);

        // Idempotence conservée.
        $this->assertSame(0, app(EpreuveService::class)->genererCandidats($this->epreuve));
    }

    public function test_emargement_pdf_layout_partage(): void
    {
        $this->creerEtudiant('EXA00002');
        app(EpreuveService::class)->genererCandidats($this->epreuve);

        $this->actingAs($this->admin)->get(route('admin.evaluations.epreuves.convocation', $this->epreuve))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->assertDatabaseHas('integration_logs', [
            'integration' => 'export', 'operation' => 'export.emargement', 'status' => 'completed',
        ]);
    }

    public function test_page_epreuve_propose_exports(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.evaluations.epreuves.show', $this->epreuve))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Émargement PDF', $html);
        $this->assertStringContainsString('Candidats CSV', $html);
    }

    public function test_candidats_csv_masque_sans_data_export_et_route_directe_refusee(): void
    {
        $consultation = User::creerCompteBackOffice([
            'name' => 'Consultation', 'email' => 'cons.exam@universite.test', 'password' => 'Motdepasse@123',
        ], 'consultation');

        $url = route('admin.listes.exporter', array_filter([
            'format' => 'csv', 'groupe_id' => $this->epreuve->groupe_id,
            'matiere_id' => $this->epreuve->groupe_id ? null : $this->epreuve->matiere_id,
            'annee_universitaire_id' => $this->epreuve->session?->annee_universitaire_id,
        ]));

        // Page visible (epreuves.view) mais lien d'export masqué (data.export).
        $this->actingAs($consultation)
            ->get(route('admin.evaluations.epreuves.show', $this->epreuve))
            ->assertOk()
            ->assertSee('Émargement PDF')
            ->assertDontSee('Candidats CSV')
            ->assertDontSee($url, false);

        // Le refus serveur reste effectif en accès direct.
        $this->actingAs($consultation)->get($url)->assertForbidden();

        // L'utilisateur autorisé conserve le lien.
        $this->actingAs($this->admin)
            ->get(route('admin.evaluations.epreuves.show', $this->epreuve))
            ->assertOk()
            ->assertSee('Candidats CSV');
    }

    public function test_candidats_csv_meme_population(): void
    {
        $this->creerEtudiant('EXA00003');

        $csv = $this->actingAs($this->admin)->get(route('admin.listes.exporter', [
            'format' => 'csv', 'groupe_id' => $this->groupe->id,
            'annee_universitaire_id' => $this->annee->id,
        ]))->assertOk()->streamedContent();

        $this->assertStringContainsString('EXA00003', $csv);
    }

    private function creerEtudiant(string $cin): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => strtolower($cin).'@exam.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'groupe_id' => $this->groupe->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');
        $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
        ]);

        return $etudiant;
    }
}
