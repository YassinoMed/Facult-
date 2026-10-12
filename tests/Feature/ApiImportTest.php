<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DossierAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Semestre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActAsApiClient;
use Tests\TestCase;

/**
 * API v1 — Imports (§37-40, §50, §60-62) : workflow deux phases,
 * idempotence, anti-XXE, formats malformés, extension dangereuse.
 */
class ApiImportTest extends TestCase
{
    use ActAsApiClient, RefreshDatabase;

    private const CSV_ETUDIANTS = "cin;nom;prenom;email;sexe;faculte;niveau;annee_academique;adresse;ville;code_postal;annee_bac\n"
        ."IMP0001;Ba;Awa;awa.imp@example.com;F;FST;L1;2025-2026;Rue 1;Dakar;10000;2024\n"
        ."IMP0002;Diop;Moussa;moussa.imp@example.com;M;FST;L1;2025-2026;Rue 2;Thies;20000;2024\n"
        ."BADLINE;X;;email-invalide;;INCONNUE;L9;;;;;\n";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Faculte::create(['nom' => 'Faculté Sciences', 'code' => 'FST', 'description' => 'T', 'active' => true]);
    }

    private function fichierCsv(string $contenu): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('etudiants.csv', $contenu);
    }

    public function test_import_deux_phases_la_previsualisation_n_ecrit_rien(): void
    {
        [, $token] = $this->clientApi(['imports:create', 'students:write']);

        $reponse = $this->post('/api/v1/imports', [
            'type' => 'students',
            'file' => $this->fichierCsv(self::CSV_ETUDIANTS),
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(202);

        // 2 valides, 1 ligne en erreur — et RIEN en base
        $this->assertSame(2, $reponse->json('report.valides'));
        $this->assertSame(1, $reponse->json('report.erreurs'));
        $this->assertSame(3, $reponse->json('report.total'));
        $this->assertSame(0, Etudiant::where('cin', 'like', 'IMP%')->count());

        // Rapport pending créé + journal d'intégration tracé
        $this->assertDatabaseHas('import_reports', ['status' => 'pending', 'type' => 'students']);
        $this->assertDatabaseHas('integration_logs', ['integration' => 'api', 'operation' => 'import.preview.students']);
    }

    public function test_confirmation_importe_les_lignes_valides_seulement(): void
    {
        [, $token] = $this->clientApi(['imports:create', 'students:write']);

        $preview = $this->post('/api/v1/imports', [
            'type' => 'students', 'file' => $this->fichierCsv(self::CSV_ETUDIANTS),
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(202);

        $rapportId = $preview->json('report.id');

        $final = $this->post("/api/v1/imports/{$rapportId}/confirm", ['mode' => 'partiel'],
            ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        // Mode partiel : 2 importées, 1 ligne ignorée et rapportée
        $this->assertSame(2, $final->json('report.valides'));
        $this->assertSame(2, Etudiant::where('cin', 'like', 'IMP%')->count());
        $this->assertSame(1, $final->json('report.erreurs'));

        $this->assertDatabaseHas('import_reports', ['id' => $rapportId, 'status' => 'completed']);
        $this->assertDatabaseHas('integration_logs', ['operation' => 'import.confirm.students']);
    }

    public function test_idempotence_reimporter_le_meme_fichier_ne_cree_rien(): void
    {
        [, $token] = $this->clientApi(['imports:create', 'students:write']);

        $premier = $this->post('/api/v1/imports', ['type' => 'students', 'file' => $this->fichierCsv(self::CSV_ETUDIANTS)],
            ['Authorization' => 'Bearer '.$token])->assertStatus(202);
        $this->post("/api/v1/imports/{$premier->json('report.id')}/confirm", ['mode' => 'partiel'],
            ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $this->assertSame(2, Etudiant::count());

        // Ré-envoi du même fichier : les 2 lignes deviennent des doublons
        $second = $this->post('/api/v1/imports', ['type' => 'students', 'file' => $this->fichierCsv(self::CSV_ETUDIANTS)],
            ['Authorization' => 'Bearer '.$token])->assertStatus(202);

        $this->assertSame(0, $second->json('report.valides'));
        $this->assertSame(2, $second->json('report.doublons'));

        // Rapport annulé automatiquement : rien à importer
        $this->assertSame('cancelled', $second->json('report.status'));
        $this->assertSame(2, Etudiant::count()); // toujours 2 — jamais de doublon créé
    }

    public function test_rapport_d_un_autre_client_est_inaccessible(): void
    {
        [, $tokenA] = $this->clientApi(['imports:create', 'students:write']);
        [, $tokenB] = $this->clientApi(['imports:create', 'students:write']);

        $preview = $this->post('/api/v1/imports', ['type' => 'students', 'file' => $this->fichierCsv(self::CSV_ETUDIANTS)],
            ['Authorization' => 'Bearer '.$tokenA])->assertStatus(202);

        $rapportId = $preview->json('report.id');

        // Le client B ne peut ni consulter ni confirmer le rapport de A (anti-IDOR)
        $this->resetAuthGuards(); // requête fraîche : le client B s'authentifie avec SON token
        $this->get("/api/v1/imports/{$rapportId}", ['Authorization' => 'Bearer '.$tokenB])->assertStatus(404);
        $this->post("/api/v1/imports/{$rapportId}/confirm", ['mode' => 'partiel'],
            ['Authorization' => 'Bearer '.$tokenB])->assertStatus(404);
    }

    public function test_xml_avec_attaque_xxe_refuse(): void
    {
        [, $token] = $this->clientApi(['imports:create', 'students:write']);

        $payload = <<<'XML'
            <?xml version="1.0"?>
            <!DOCTYPE etudiants [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>
            <etudiants>
                <etudiant><cin>&xxe;</cin></etudiant>
            </etudiants>
            XML;

        $this->post('/api/v1/imports', [
            'type' => 'students',
            'file' => UploadedFile::fake()->createWithContent('evil.xml', $payload),
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(422);

        $this->assertDatabaseCount('import_reports', 0);
    }

    public function test_json_malforme_refuse(): void
    {
        [, $token] = $this->clientApi(['imports:create', 'students:write']);

        $this->post('/api/v1/imports', [
            'type' => 'students',
            'file' => UploadedFile::fake()->createWithContent('donnees.json', '{"etudiants": [INVALID'),
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(422);
    }

    public function test_extension_dangereuse_refusee(): void
    {
        [, $token] = $this->clientApi(['imports:create', 'students:write']);

        $this->post('/api/v1/imports', [
            'type' => 'students',
            'file' => UploadedFile::fake()->create('payload.php', 10, 'text/x-php'),
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(422);
    }

    public function test_import_xml_valide_fonctionne(): void
    {
        [, $token] = $this->clientApi(['imports:create', 'students:write']);

        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <etudiants>
                <etudiant>
                    <cin>XML0001</cin><nom>Sarr</nom><prenom>Ali</prenom>
                    <email>ali.sarr@example.com</email><sexe>M</sexe><faculte>FST</faculte>
                    <niveau>L1</niveau><annee_academique>2025-2026</annee_academique>
                    <adresse>Rue</adresse><ville>Dakar</ville><code_postal>10000</code_postal><annee_bac>2024</annee_bac>
                </etudiant>
            </etudiants>
            XML;

        $preview = $this->post('/api/v1/imports', [
            'type' => 'students',
            'file' => UploadedFile::fake()->createWithContent('etudiants.xml', $xml),
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(202);

        $this->assertSame(1, $preview->json('report.valides'));

        $this->post('/api/v1/imports/'.$preview->json('report.id').'/confirm', ['mode' => 'atomique'],
            ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $this->assertDatabaseHas('etudiants', ['cin' => 'XML0001', 'nom' => 'Sarr']);
    }

    public function test_mode_inconnu_refuse(): void
    {
        [, $token] = $this->clientApi(['imports:create', 'students:write']);

        $preview = $this->post('/api/v1/imports', ['type' => 'students', 'file' => $this->fichierCsv(self::CSV_ETUDIANTS)],
            ['Authorization' => 'Bearer '.$token])->assertStatus(202);

        $this->post('/api/v1/imports/'.$preview->json('report.id').'/confirm', ['mode' => 'nimporte'],
            ['Authorization' => 'Bearer '.$token])->assertStatus(422);
    }

    public function test_import_de_notes_recalcule_les_moyennes(): void
    {
        // Préparation : un étudiant avec dossier + inscription L1 + semestres + matière
        $this->seedScenarioNote();

        [, $token] = $this->clientApi(['imports:create', 'grades:write']);

        $csv = "cin;matiere;annee;niveau;semestre;note;session\n"
            ."NOTEAPI01;NOTE101;2025-2026;L1;1;14.5;normale\n";

        $preview = $this->post('/api/v1/imports', [
            'type' => 'grades', 'file' => $this->fichierCsv($csv),
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(202);

        $this->assertSame(1, $preview->json('report.valides'));

        $this->post('/api/v1/imports/'.$preview->json('report.id').'/confirm', ['mode' => 'atomique'],
            ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $inscription = InscriptionAcademique::first();
        $this->assertEquals(14.5, (float) $inscription->fresh()->moyenne_annuelle);
        $this->assertSame(5, $inscription->fresh()->credits_obtenus);
    }

    private function seedScenarioNote(): void
    {
        $faculte = Faculte::create(['nom' => 'F3', 'code' => 'F3', 'description' => 'T', 'active' => true]);
        $etudiant = Etudiant::create(['cin' => 'NOTEAPI01',  'nom' => 'Note', 'prenom' => 'Api',
            'email' => 'note.api@example.com', 'sexe' => 'M', 'faculte_id' => $faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2025-2026',
            'adresse' => 'R', 'ville' => 'R', 'code_postal' => '1', 'annee_bac' => '2024', ]);
        $etudiant->forceFill(['num_inscription' => 'INS-NOTE-1'])->save();

        DossierAcademique::create([
            'etudiant_id' => $etudiant->id, 'matricule' => 'MAT-NOTE-1', 'date_archivage' => now()->toDateString(),
        ]);

        $annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-07-15',
        ]);

        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $annee->id, 'niveau' => 'L1',
        ]);
        Semestre::create(['inscription_academique_id' => $inscription->id, 'numero' => 1]);
        Matiere::create([
            'code' => 'NOTE101', 'nom' => 'Matière API', 'coefficient' => 2, 'credits' => 5, 'niveau' => 'L1', 'semestre' => 1,
        ]);
    }
}
