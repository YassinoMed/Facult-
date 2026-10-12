<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DossierAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\User;
use App\Services\Io\XlsxSerializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Import notes multi-format : association stricte (CIN → matricule →
 * num_inscription → CIN+naissance, jamais de nom seul), matière par
 * code + contexte, verrous, scope, doublons, idempotence, concurrence.
 */
class NotesImportAssociationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Etudiant $etudiant;

    private Matiere $matiere;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.notes@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->etudiant = Etudiant::create([
            'cin' => '12000001', 'nom' => 'Benali', 'prenom' => 'Sara', 'email' => 'nt1@notes.test',
            'date_naissance' => '2004-03-15', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        DossierAcademique::create([
            'etudiant_id' => $this->etudiant->id, 'matricule' => 'MAT-2026-001',
            'date_archivage' => now()->toDateString(), 'statut' => 'actif',
        ]);
        $inscription = $this->etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
        ]);
        $inscription->semestres()->create(['numero' => 1]);
        $inscription->semestres()->create(['numero' => 2]);
        $this->matiere = Matiere::create([
            'code' => 'INFO101', 'nom' => 'Algo', 'niveau' => 'L1', 'semestre' => 1,
        ]);
    }

    public function test_import_csv_valide_par_cin(): void
    {
        // Entrée menu : lien direct vers l'import des notes (permission data.import).
        $menu = (string) $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('Importer les notes', $menu);
        $this->assertStringContainsString('/admin/donnees/notes/import', $menu);

        $token = $this->preview("cin;code_matiere;annee;semestre;note;session\n12000001;INFO101;2026-2027;1;14.5;normale\n");

        $this->actingAs($this->admin)
            ->post('/admin/donnees/notes/import/confirmer', ['token' => $token, 'mode' => 'creer'])
            ->assertOk();

        $this->assertDatabaseHas('notes', [
            'etudiant_id' => $this->etudiant->id, 'matiere_id' => $this->matiere->id, 'note' => 14.50,
        ]);
    }

    public function test_resolution_matricule_num_inscription_et_alias(): void
    {
        // Alias d'en-tête + matricule.
        $token = $this->preview("N°CIN;matiere;annee_universitaire;semestre;note\n12000001;INFO101;2026-2027;1;12\n", 'text/csv');
        $this->confirmer($token);

        // num_inscription comme matricule.
        $token = $this->preview("Code étudiant;code_matiere;annee;semestre;note;session\n{$this->etudiant->num_inscription};INFO101;2026-2027;1;13;rattrapage\n");
        $this->confirmer($token, 'upsert');

        $this->assertSame(2, Note::where('etudiant_id', $this->etudiant->id)->count());
    }

    public function test_formats_json_xml_xlsx(): void
    {
        $lignes = [['cin' => '12000001', 'code_matiere' => 'INFO101', 'annee' => '2026-2027', 'semestre' => 1, 'note' => 15]];

        $json = UploadedFile::fake()->createWithContent('n.json', json_encode($lignes), 'application/json');
        $this->confirmer($this->previewFichier($json));
        $this->assertSame(1, Note::count());

        Note::query()->delete();

        $xml = UploadedFile::fake()->createWithContent(
            'n.xml', '<?xml version="1.0"?><rows><row><cin>12000001</cin><code_matiere>INFO101</code_matiere><annee>2026-2027</annee><semestre>1</semestre><note>15</note></row></rows>', 'application/xml'
        );
        $this->confirmer($this->previewFichier($xml));
        $this->assertSame(1, Note::count());

        Note::query()->delete();

        $octets = app(XlsxSerializer::class)->serialize(
            ['cin', 'code_matiere', 'annee', 'semestre', 'note'],
            [['cin' => '12000001', 'code_matiere' => 'INFO101', 'annee' => '2026-2027', 'semestre' => 1, 'note' => 15]]
        );
        $xlsx = UploadedFile::fake()->createWithContent(
            'n.xlsx', $octets, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
        $this->confirmer($this->previewFichier($xlsx));
        $this->assertSame(1, Note::count());
    }

    public function test_etudiant_introuvable_matiere_incoherente(): void
    {
        // CIN inconnu → code précis.
        $reponse = $this->actingAs($this->admin)
            ->post('/admin/donnees/notes/import/preview', ['fichier' => $this->csv("cin;code_matiere;annee;semestre;note\n99999998;INFO101;2026-2027;1;10\n")])
            ->assertOk();
        $this->assertStringContainsString('ETUDIANT_INTROUVABLE', (string) $reponse->getContent());

        // Matière d'un autre niveau → NIVEAU_INCOHERENT.
        Matiere::create(['code' => 'M2X', 'nom' => 'X', 'niveau' => 'M1', 'semestre' => 1]);
        $reponse = $this->actingAs($this->admin)
            ->post('/admin/donnees/notes/import/preview', ['fichier' => $this->csv("cin;code_matiere;annee;semestre;note\n12000001;M2X;2026-2027;1;10\n")])
            ->assertOk();
        $this->assertStringContainsString('NIVEAU_INCOHERENT', (string) $reponse->getContent());
    }

    public function test_doublon_meme_etudiant_deux_identifiants(): void
    {
        $reponse = $this->actingAs($this->admin)
            ->post('/admin/donnees/notes/import/preview', ['fichier' => $this->csv(
                "cin;matricule;code_matiere;annee;semestre;note\n12000001;;INFO101;2026-2027;1;12\n;MAT-2026-001;INFO101;2026-2027;1;13\n"
            )])
            ->assertOk();

        // Doublon métier signalé (même étudiant via CIN puis matricule).
        $this->assertStringContainsString('DUPLICATE_FILE', (string) $reponse->getContent());

        // Succès partiel : la ligne saine est importée, la doublonne écartée.
        $token = $reponse->viewData('token');
        $this->confirmer($token);
        $this->assertSame(1, Note::count());
    }

    public function test_note_verrouillee_refusee(): void
    {
        $token = $this->preview("cin;code_matiere;annee;semestre;note\n12000001;INFO101;2026-2027;1;12\n");
        $this->confirmer($token);

        Note::firstOrFail()->update(['locked_at' => now()]);

        $token = $this->preview("cin;code_matiere;annee;semestre;note\n12000001;INFO101;2026-2027;1;15\n");

        // Relecture preview : verrou signalé avant toute écriture.
        $this->actingAs($this->admin)
            ->post('/admin/donnees/notes/import/confirmer', ['token' => $token, 'mode' => 'upsert'])
            ->assertRedirect();
        $this->assertSame(12.0, (float) Note::firstOrFail()->note);
    }

    public function test_scope_faculte_bloque(): void
    {
        $autre = Faculte::create(['nom' => 'FLSH', 'code' => 'FLSH', 'description' => 'T', 'active' => true]);
        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.notes@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $autre->id,
        ], 'admin_faculte');

        $reponse = $this->actingAs($adminFaculte)
            ->post('/admin/donnees/notes/import/preview', ['fichier' => $this->csv("cin;code_matiere;annee;semestre;note\n12000001;INFO101;2026-2027;1;12\n")])
            ->assertOk();
        $this->assertStringContainsString('HORS_PERIMETRE', (string) $reponse->getContent());
    }

    public function test_idempotence_fichier_et_double_confirmation(): void
    {
        $token = $this->preview("cin;code_matiere;annee;semestre;note\n12000001;INFO101;2026-2027;1;12\n");
        $this->confirmer($token);

        // Même fichier : avertissement IMPORT_ALREADY_PROCESSED.
        $reponse = $this->actingAs($this->admin)
            ->post('/admin/donnees/notes/import/preview', ['fichier' => $this->csv("cin;code_matiere;annee;semestre;note\n12000001;INFO101;2026-2027;1;12\n")])
            ->assertOk();
        $this->assertStringContainsString('IMPORT_ALREADY_PROCESSED', (string) $reponse->getContent());

        // Double confirmation du même token : expiré.
        $this->actingAs($this->admin)
            ->post('/admin/donnees/notes/import/confirmer', ['token' => $token, 'mode' => 'creer'])
            ->assertRedirect();
        $this->assertSame(1, Note::count());
    }

    public function test_donnees_demo_idempotentes_et_csv_demo_importable(): void
    {
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\NotesDemoSeeder']);
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\NotesDemoSeeder']);

        $this->assertSame(12, Etudiant::where('cin', 'like', '120001%')->count());
        $this->assertSame(3, Matiere::where('code', 'like', 'DEMO%')->count());

        $contenu = file_get_contents(base_path('docs/notes/demo-notes.csv'));
        $this->assertNotFalse($contenu);

        $token = $this->previewFichier(UploadedFile::fake()->createWithContent('demo.csv', $contenu, 'text/csv'));
        $this->confirmer($token);
        $this->assertSame(8, Note::count());
    }

    private function csv(string $contenu, string $mime = 'text/csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('notes.csv', $contenu, $mime);
    }

    private function preview(string $contenu, string $mime = 'text/csv'): string
    {
        return $this->previewFichier($this->csv($contenu, $mime));
    }

    private function previewFichier(UploadedFile $fichier): string
    {
        $reponse = $this->actingAs($this->admin)
            ->post('/admin/donnees/notes/import/preview', ['fichier' => $fichier])
            ->assertOk();

        $token = $reponse->viewData('token');
        $this->assertNotEmpty($token);

        return $token;
    }

    private function confirmer(string $token, string $mode = 'creer'): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/donnees/notes/import/confirmer', ['token' => $token, 'mode' => $mode])
            ->assertOk();
    }
}
