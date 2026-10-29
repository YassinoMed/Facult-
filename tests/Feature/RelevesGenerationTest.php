<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\User;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Evaluation\Services\DeliberationService;
use App\Modules\StudentDossiers\Jobs\GenerateOfficialDocumentsJob;
use App\Modules\StudentDossiers\Models\GeneratedDocument;
use App\Modules\StudentDossiers\Services\Pdf\OfficialDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * LOOP 4 — relevés : semestriel/annuel/cursus, code de vérification,
 * auto-génération à la publication, masse, portail étudiant.
 */
class RelevesGenerationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Etudiant $etudiant;

    private InscriptionAcademique $inscription;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        config(['modules.evaluation.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.rel@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->etudiant = Etudiant::create([
            'cin' => 'REL00001', 'nom' => 'Benali', 'prenom' => 'Sara', 'email' => 'rel@univ.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');
        $this->inscription = $this->etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1', 'statut' => 'valide',
        ]);
        $s1 = $this->inscription->semestres()->create(['numero' => 1]);
        $s2 = $this->inscription->semestres()->create(['numero' => 2]);

        $m1 = Matiere::create(['code' => 'REL1', 'nom' => 'M1', 'niveau' => 'L1', 'semestre' => 1]);
        $m2 = Matiere::create(['code' => 'REL2', 'nom' => 'M2', 'niveau' => 'L1', 'semestre' => 2]);

        foreach ([
            [$s1, $m1, 14.0], [$s2, $m2, 12.0],
        ] as [$semestre, $matiere, $note]) {
            Note::create([
                'etudiant_id' => $this->etudiant->id,
                'inscription_academique_id' => $this->inscription->id,
                'semestre_id' => $semestre->id, 'matiere_id' => $matiere->id,
                'note' => $note, 'coefficient' => 1, 'credits' => 4, 'session' => 'normale',
            ]);
        }
    }

    public function test_releve_semestriel_filtre_un_semestre(): void
    {
        $document = app(OfficialDocumentService::class)->generer(
            $this->etudiant, 'releve_semestriel', $this->inscription->id, $this->admin->id, 1
        );

        $this->assertCount(1, $document->snapshot_donnees['semestres'] ?? $this->snapshotSemestres($document));
        $this->assertSame(1, (int) $this->snapshotSemestres($document)[0]['numero']);
        $this->assertNotNull($document->code_verification);
    }

    public function test_releve_semestriel_exige_semestre(): void
    {
        $this->expectException(\DomainException::class);

        app(OfficialDocumentService::class)->generer(
            $this->etudiant, 'releve_semestriel', $this->inscription->id, $this->admin->id
        );
    }

    public function test_releve_cursus_agrege_deux_annees(): void
    {
        $annee2 = AnneeUniversitaire::create([
            'libelle' => '2027-2028', 'date_debut' => '2027-09-01', 'date_fin' => '2028-06-30', 'active' => false,
        ]);
        $inscription2 = $this->etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $annee2->id, 'niveau' => 'L2', 'statut' => 'valide',
        ]);
        $inscription2->semestres()->create(['numero' => 1]);

        $document = app(OfficialDocumentService::class)->generer(
            $this->etudiant, 'releve_cursus', $this->inscription->id, $this->admin->id
        );

        $this->assertCount(2, $document->snapshot_donnees['annees']);
    }

    public function test_publication_declenche_job_sauf_config_off(): void
    {
        Queue::fake();

        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $this->annee->id, 'statut' => 'validated', 'created_by' => $this->admin->id,
        ]);

        app(DeliberationService::class)->publier($deliberation);

        Queue::assertPushed(GenerateOfficialDocumentsJob::class);

        config()->set('documents-officiels.generation_auto_publication', false);
        Queue::fake();

        $deliberation2 = Deliberation::create([
            'annee_universitaire_id' => $this->annee->id, 'statut' => 'validated', 'created_by' => $this->admin->id,
        ]);
        app(DeliberationService::class)->publier($deliberation2);

        Queue::assertNotPushed(GenerateOfficialDocumentsJob::class);
    }

    public function test_masse_depuis_deliberation_publiee(): void
    {
        Queue::fake();

        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $this->annee->id, 'statut' => 'published', 'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.evaluations.deliberations.releves', $deliberation))
            ->assertRedirect();

        Queue::assertPushed(GenerateOfficialDocumentsJob::class);
        $this->assertDatabaseHas('import_reports', ['type' => 'data.releves']);
    }

    public function test_portail_liste_telecharge_et_idor(): void
    {
        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $this->annee->id,
            'statut' => 'published',
            'published_at' => now(),
            'created_by' => $this->admin->id,
        ]);
        DecisionDeliberation::create([
            'deliberation_id' => $deliberation->id,
            'etudiant_id' => $this->etudiant->id,
            'inscription_academique_id' => $this->inscription->id,
            'moyenne' => 13.0,
            'decision' => 'admis',
            'publie' => true,
        ]);

        $document = app(OfficialDocumentService::class)->generer(
            $this->etudiant, 'releve_notes', $this->inscription->id, $this->admin->id
        );

        session(['etudiant_auth_id' => $this->etudiant->id]);

        $this->get(route('etudiant.dossier.officiels.index'))->assertOk()
            ->assertSee((string) $document->reference);
        $this->get(route('etudiant.dossier.officiels.download', $document))->assertOk();

        $autre = Etudiant::create([
            'cin' => 'REL00002', 'nom' => 'X', 'prenom' => 'Y', 'email' => 'rel2@univ.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'M', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $inscriptionAutre = $autre->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1', 'statut' => 'valide',
        ]);
        $documentAutre = app(OfficialDocumentService::class)->generer(
            $autre, 'releve_notes', $inscriptionAutre->id, $this->admin->id
        );

        // Toujours connecté comme premier étudiant : 404, aucune fuite.
        $this->get(route('etudiant.dossier.officiels.download', $documentAutre))->assertNotFound();
    }

    /** @return array<int, array<string, mixed>> */
    private function snapshotSemestres(GeneratedDocument $document): array
    {
        // Snapshot brut (PdfDocumentService) ou snapshot versionné (Centre).
        return $document->snapshot_donnees['semestres'] ?? [];
    }
}
