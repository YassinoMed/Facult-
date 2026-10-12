<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\ImportReport;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\Semestre;
use App\Models\User;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\StudentArchive\Services\StudentArchiveService;
use App\Modules\StudentDossiers\Jobs\GenerateOfficialDocumentsJob;
use App\Modules\StudentDossiers\Models\GeneratedDocument;
use App\Modules\StudentDossiers\Services\Pdf\OfficialDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActAsApiClient;
use Tests\TestCase;

/**
 * Phase 7 — Documents officiels (§44-59, §64-66, §72-73) : catalogue,
 * versionnement + hash, révocation, vérification publique, IDOR,
 * génération massive. Moteur/registre existants, sans duplication.
 */
class DocumentsPhase7Test extends TestCase
{
    use ActAsApiClient;
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Matiere $ecue;

    private Etudiant $etudiant;

    private InscriptionAcademique $inscription;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.student-dossiers.active' => true]);
        Storage::fake('private');

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.ph7@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LMI', 'nom' => 'Licence MI',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niveau = Niveau::create(['programme_id' => $this->programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $semestre = SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $this->programme->id,
            'code' => 'UE01', 'nom' => 'Bases', 'credits' => 6, 'coefficient' => 2,
        ]);
        $this->ecue = Matiere::create([
            'code' => 'INF101', 'nom' => 'Info', 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 6, 'coefficient' => 2,
        ]);

        $this->etudiant = Etudiant::create([
            'cin' => 'BKPH7001', 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'bkph7001@ph7.test', 'date_naissance' => '2004-01-01',
            'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X',
            'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');

        $this->inscription = InscriptionAcademique::create([
            'etudiant_id' => $this->etudiant->id, 'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1', 'statut' => 'en_cours', 'type' => 'premiere', 'regime' => 'initiale',
            'moyenne_annuelle' => 12.5, 'credits_obtenus' => 30, 'credits_total' => 30,
        ]);
        $semestreNote = Semestre::create(['inscription_academique_id' => $this->inscription->id, 'numero' => 1, 'moyenne' => 12.5, 'credits_obtenus' => 30, 'credits_total' => 30]);
        Note::create([
            'etudiant_id' => $this->etudiant->id, 'inscription_academique_id' => $this->inscription->id,
            'semestre_id' => $semestreNote->id, 'matiere_id' => $this->ecue->id,
            'note' => 12.5, 'coefficient' => 2, 'credits' => 6, 'session' => 'normale',
        ]);
    }

    private function deliberer(string $decision): Deliberation
    {
        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $this->annee->id, 'programme_id' => $this->programme->id,
            'statut' => 'published', 'created_by' => $this->admin->id, 'published_at' => now(),
        ]);
        DecisionDeliberation::create([
            'deliberation_id' => $deliberation->id, 'etudiant_id' => $this->etudiant->id,
            'inscription_academique_id' => $this->inscription->id,
            'moyenne' => 12.5, 'credits_obtenus' => 30, 'credits_total' => 30,
            'decision' => $decision, 'publie' => true,
        ]);

        return $deliberation;
    }

    public function test_releve_versionne_hashaudite(): void
    {
        $service = app(OfficialDocumentService::class);

        $document = $service->generer($this->etudiant, 'releve_notes', $this->inscription->id, $this->admin->id);

        $this->assertStringStartsWith('REL-', $document->reference);
        $this->assertSame(1, $document->version);
        $this->assertSame('valide', $document->statut);
        $this->assertSame(64, strlen((string) $document->sha256));
        Storage::disk('private')->assertExists($document->storage_path);
        $this->assertTrue(HistoriqueAcademique::where('action', 'document.genere')
            ->where('entite_id', $document->id)->exists());

        // Contenu identique : anti-régénération (même ligne).
        $bis = $service->generer($this->etudiant, 'releve_notes', $this->inscription->id, $this->admin->id);
        $this->assertSame($document->id, $bis->id);

        // Donnée changée : v2 + v1 `remplace`, jamais supprimée.
        $this->inscription->update(['moyenne_annuelle' => 13.0]);
        $v2 = $service->generer($this->etudiant, 'releve_notes', $this->inscription->id, $this->admin->id);

        $this->assertSame(2, $v2->version);
        $this->assertSame('remplace', $document->fresh()->statut);
        $this->assertSame(2, GeneratedDocument::where('type', 'releve_notes')->count());
    }

    public function test_attestation_exige_decision_publiee(): void
    {
        $service = app(OfficialDocumentService::class);

        try {
            $service->generer($this->etudiant, 'attestation_reussite', $this->inscription->id, $this->admin->id);
            $this->fail('Sans décision, l’attestation aurait dû être refusée.');
        } catch (\DomainException) {
            $this->assertSame(0, GeneratedDocument::count());
        }

        $this->deliberer('ajourne');

        try {
            $service->generer($this->etudiant, 'attestation_reussite', $this->inscription->id, $this->admin->id);
            $this->fail('Décision ajourné : attestation refusée.');
        } catch (\DomainException) {
            $this->assertSame(0, GeneratedDocument::count());
        }
    }

    public function test_telechargement_revoque_et_idor(): void
    {
        $this->deliberer('admis');
        $document = app(OfficialDocumentService::class)
            ->generer($this->etudiant, 'attestation_reussite', $this->inscription->id, $this->admin->id);

        $this->assertStringStartsWith('ATT-', $document->reference);

        $this->actingAs($this->admin)
            ->get(route('admin.documents-officiels.download', $document))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        // Autre faculté : 403 (policy dossier + faculté).
        $autreFaculte = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'T', 'active' => true]);
        $adminAutre = User::creerCompteBackOffice([
            'name' => 'Fac B', 'email' => 'facb.ph7@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $autreFaculte->id,
        ], 'admin_faculte');

        $this->actingAs($adminAutre)
            ->get(route('admin.documents-officiels.download', $document))
            ->assertForbidden();

        // Révocation : statut + audit + 410 au téléchargement.
        $this->actingAs($this->admin)->post(route('admin.documents-officiels.revoquer', $document), [
            'motif' => 'Erreur de moyenne.',
        ])->assertRedirect();

        $this->assertSame('revoque', $document->fresh()->statut);
        $this->assertTrue(HistoriqueAcademique::where('action', 'document.revoque')
            ->where('entite_id', $document->id)->exists());
        $this->actingAs($this->admin)
            ->get(route('admin.documents-officiels.download', $document))
            ->assertStatus(410);
    }

    public function test_verification_publique_minimale(): void
    {
        $this->deliberer('admis');
        $document = app(OfficialDocumentService::class)
            ->generer($this->etudiant, 'releve_notes', $this->inscription->id, $this->admin->id);

        $reponse = $this->get(route('documents.verifier', $document->reference))->assertOk();

        $contenu = (string) $reponse->getContent();
        $this->assertStringContainsString($document->reference, $contenu);
        $this->assertStringNotContainsString('BKPH7001', $contenu);
        $this->assertStringNotContainsString('12.5', $contenu);

        $this->get(route('documents.verifier', 'REL-INCONNU-00'))->assertNotFound();
    }

    public function test_archive_expose_officiels(): void
    {
        $this->deliberer('admis');
        app(OfficialDocumentService::class)
            ->generer($this->etudiant, 'releve_notes', $this->inscription->id, $this->admin->id);

        $documents = app(StudentArchiveService::class)->documents($this->etudiant, [], $this->admin);

        $this->assertTrue($documents->contains(fn ($d) => ($d['source'] ?? null) === 'officiel'
            && ($d['reference'] ?? null) !== null));
    }

    public function test_api_results_dettes_et_decision(): void
    {
        $this->deliberer('admis');

        [, $token] = $this->clientApi(['grades:read']);

        $reponse = $this->getApi(route('api.v1.students.results', $this->etudiant), $token)->assertOk();

        $ligne = collect($reponse->json('data'))->firstWhere('inscription_id', $this->inscription->id);
        $this->assertNotNull($ligne);
        $this->assertSame('admis', $ligne['decision']);
        $this->assertSame([], $ligne['dettes']);
    }

    public function test_generation_masse_deliberation(): void
    {
        $deliberation = $this->deliberer('admis');

        $rapport = ImportReport::create([
            'user_id' => $this->admin->id, 'integration' => 'web', 'direction' => 'export',
            'type' => 'documents', 'format' => 'pdf', 'status' => 'pending',
        ]);

        GenerateOfficialDocumentsJob::dispatch($rapport->id, $deliberation->id, 'releve_notes', $this->admin->id);

        $this->assertSame('completed', $rapport->fresh()->status);
        $this->assertSame(1, $rapport->fresh()->valid_rows);
        $this->assertSame(1, GeneratedDocument::where('type', 'releve_notes')->count());
    }
}
