<?php

namespace Tests\Feature\AssistantIa;

use App\Models\User;
use App\Modules\AssistantIa\Models\AiDocumentIndex;
use App\Modules\AssistantIa\Services\ConnecteurCodeUniRegistre;
use App\Modules\AssistantIa\Services\MoteurRechercheDocumentaire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConnecteurCodeTest extends TestCase
{
    use RefreshDatabase;

    protected ConnecteurCodeUniRegistre $connecteur;

    protected MoteurRechercheDocumentaire $moteur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connecteur = app(ConnecteurCodeUniRegistre::class);
        $this->moteur = app(MoteurRechercheDocumentaire::class);
    }

    public function test_fichiers_autorises_et_rejet_strict_des_fichiers_sensibles(): void
    {
        // Fichiers code autorisés
        $this->assertTrue($this->connecteur->estFichierCodeAutorise('app/Http/Controllers/InscriptionController.php'));
        $this->assertTrue($this->connecteur->estFichierCodeAutorise('routes/web.php'));
        $this->assertTrue($this->connecteur->estFichierCodeAutorise('database/migrations/2026_10_23_100000_creer_tables_assistant_ia.php'));

        // Fichiers strictement interdits
        $this->assertFalse($this->connecteur->estFichierCodeAutorise('.env'));
        $this->assertFalse($this->connecteur->estFichierCodeAutorise('.env.production'));
        $this->assertFalse($this->connecteur->estFichierCodeAutorise('vendor/laravel/framework/src/Illuminate/Foundation/Application.php'));
        $this->assertFalse($this->connecteur->estFichierCodeAutorise('node_modules/vue/package.json'));
        $this->assertFalse($this->connecteur->estFichierCodeAutorise('storage/logs/laravel.log'));
        $this->assertFalse($this->connecteur->estFichierCodeAutorise('storage/app/documents/cin_etudiant.pdf'));
        $this->assertFalse($this->connecteur->estFichierCodeAutorise('app/../../.env'));
    }

    public function test_detection_et_rejet_immediat_des_fichiers_contenant_des_secrets(): void
    {
        $codeAvecClePrivee = "<?php\n\$cle = '-----BEGIN RSA PRIVATE KEY-----\nMIIEowIBAAKCAQEA...';";
        $this->assertTrue($this->connecteur->contientSecrets($codeAvecClePrivee));

        $codeAvecAppKey = 'APP_KEY=base64:9L7Z6a4dF3y1k8J8vX2w==';
        $this->assertTrue($this->connecteur->contientSecrets($codeAvecAppKey));

        $codeSain = "<?php\nnamespace App\Services;\nclass InscriptionService { public function inscrire() {} }";
        $this->assertFalse($this->connecteur->contientSecrets($codeSain));
    }

    public function test_extraction_des_symboles_classes_methodes_et_routes(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Http\Controllers;

use Illuminate\Support\Facades\Route;

class TestCodeController extends Controller
{
    public function index()
    {
        return response()->json([]);
    }

    protected function calculerMoyenneSemestre()
    {
        return 12.5;
    }
}

Route::get('/api/test-route', [TestCodeController::class, 'index']);
Route::post('/api/test-action', [TestCodeController::class, 'store']);
PHP;

        $symboles = $this->connecteur->extraireSymboles($code);

        $this->assertContains('TestCodeController', $symboles['classes']);
        $this->assertContains('index', $symboles['methodes']);
        $this->assertContains('calculerMoyenneSemestre', $symboles['methodes']);
        $this->assertContains('GET /api/test-route', $symboles['routes']);
        $this->assertContains('POST /api/test-action', $symboles['routes']);
    }

    public function test_indexation_code_associe_source_et_permission_mode_developpeur(): void
    {
        // Indexation d'un fichier réel du projet
        $chemin = 'app/Modules/AssistantIa/DTO/ReponseIa.php';
        $nbFragments = $this->connecteur->indexerFichierCode($chemin);

        $this->assertGreaterThan(0, $nbFragments);

        $fragment = AiDocumentIndex::where('chemin_fichier', $chemin)->first();
        $this->assertNotNull($fragment);
        $this->assertEquals('project_code', $fragment->source);
        $this->assertEquals('assistant_ia.mode_developpeur', $fragment->permission_requise);
        $this->assertTrue($fragment->est_sensible);
        $this->assertStringContainsString('ReponseIa', $fragment->titre);
    }

    public function test_recherche_code_respecte_strictement_rbac_developpeur(): void
    {
        $chemin = 'app/Modules/AssistantIa/DTO/ReponseIa.php';
        $this->connecteur->indexerFichierCode($chemin);

        // Utilisateur 1 : Développeur avec rôle super_admin
        $devUser = User::factory()->make([
            'id' => 91,
            'role' => 'super_admin',
        ]);
        $devUser->setRelation('roleModel', null);

        // Utilisateur 2 : Utilisateur scolarité sans permission développeur
        $scolariteUser = User::factory()->make([
            'id' => 92,
            'role' => 'agent_scolarite',
        ]);
        $scolariteUser->setRelation('roleModel', null);

        // Recherche par le dev
        $resultatsDev = $this->moteur->rechercher('ReponseIa', $devUser);
        $this->assertNotEmpty($resultatsDev);
        $this->assertEquals($chemin, $resultatsDev[0]['chemin']);

        // Recherche par l'agent sans droit dev : ne doit voir AUCUN fragment de code
        $resultatsScolarite = $this->moteur->rechercher('ReponseIa', $scolariteUser);
        $this->assertEmpty($resultatsScolarite);
    }

    public function test_indexation_incrémentale_et_anti_doublon(): void
    {
        $chemin = 'app/Modules/AssistantIa/DTO/ReponseIa.php';
        $nb1 = $this->connecteur->indexerFichierCode($chemin);
        $count1 = AiDocumentIndex::where('chemin_fichier', $chemin)->count();

        // Ré-exécution sans modification : doit être idempotent
        $nb2 = $this->connecteur->indexerFichierCode($chemin);
        $count2 = AiDocumentIndex::where('chemin_fichier', $chemin)->count();

        $this->assertEquals($nb1, $nb2);
        $this->assertEquals($count1, $count2);
    }
}
