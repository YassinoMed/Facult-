<?php

namespace Tests\Feature\AssistantIa;

use App\Models\User;
use App\Modules\AssistantIa\Models\AiDocumentIndex;
use App\Modules\AssistantIa\Services\MoteurRechercheDocumentaire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MoteurRechercheHybrideTest extends TestCase
{
    use RefreshDatabase;

    protected MoteurRechercheDocumentaire $moteur;

    protected User $userAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->moteur = app(MoteurRechercheDocumentaire::class);

        $this->userAdmin = User::factory()->make([
            'id' => 99,
            'role' => 'super_admin',
        ]);
        $this->userAdmin->setRelation('roleModel', null);
    }

    public function test_recherche_priorise_le_symbole_exact_par_rapport_a_une_mention_marginale(): void
    {
        // Document A : mentionne simplement le mot en passant
        AiDocumentIndex::create([
            'source' => 'business_procedure',
            'titre' => 'Guide Général des Etudes',
            'chemin_fichier' => 'docs/general.md',
            'index_morceau' => 0,
            'contenu_morceau' => 'Pour les questions d inscription, voir le contrôleur InscriptionController.',
            'empreinte_sha256' => hash('sha256', 'a'),
            'version_indexeur' => 1,
            'statut_document' => 'valide',
        ]);

        // Document B : définition exacte de la classe InscriptionController
        AiDocumentIndex::create([
            'source' => 'project_code',
            'titre' => 'InscriptionController (app/Http/Controllers/InscriptionController.php)',
            'chemin_fichier' => 'app/Http/Controllers/InscriptionController.php',
            'index_morceau' => 0,
            'contenu_morceau' => 'class InscriptionController extends Controller { public function store() {} }',
            'empreinte_sha256' => hash('sha256', 'b'),
            'version_indexeur' => 1,
            'statut_document' => 'valide',
        ]);

        $resultats = $this->moteur->rechercher('InscriptionController', $this->userAdmin, 5);

        $this->assertNotEmpty($resultats);
        // Le document B (symbole exact dans titre et chemin) doit arriver en première position
        $this->assertEquals('app/Http/Controllers/InscriptionController.php', $resultats[0]['chemin']);
        $this->assertGreaterThan($resultats[1]['score'], $resultats[0]['score']);
    }

    public function test_exclusion_automatique_des_documents_obsoletes_sauf_demande_expresse(): void
    {
        // Règle courante valide
        AiDocumentIndex::create([
            'source' => 'business_procedure',
            'titre' => 'Barème de Validation 2026',
            'chemin_fichier' => 'docs/bareme-actuel.md',
            'index_morceau' => 0,
            'contenu_morceau' => 'Moyenne requise pour valider le semestre : 10.0 sur 20.',
            'empreinte_sha256' => hash('sha256', 'c1'),
            'version_indexeur' => 1,
            'statut_document' => 'valide',
            'domaine_metier' => 'examens',
        ]);

        // Règle obsolète archivée
        AiDocumentIndex::create([
            'source' => 'business_procedure',
            'titre' => 'Ancien Barème 2018',
            'chemin_fichier' => 'docs/ancien-bareme-2018.md',
            'index_morceau' => 0,
            'contenu_morceau' => 'Moyenne requise pour valider le semestre : 9.5 sur 20.',
            'empreinte_sha256' => hash('sha256', 'c2'),
            'version_indexeur' => 1,
            'statut_document' => 'obsolete',
            'domaine_metier' => 'examens',
        ]);

        // Recherche standard : le document obsolète ne doit pas apparaître
        $resultatsSansObsoletes = $this->moteur->rechercher('Moyenne requise pour valider', $this->userAdmin);
        $this->assertCount(1, $resultatsSansObsoletes);
        $this->assertEquals('docs/bareme-actuel.md', $resultatsSansObsoletes[0]['chemin']);

        // Recherche avec demande explicite des archives obsolètes
        $resultatsAvecObsoletes = $this->moteur->rechercher('Moyenne requise pour valider', $this->userAdmin, 5, ['inclure_obsoletes' => true]);
        $this->assertCount(2, $resultatsAvecObsoletes);
    }

    public function test_filtrage_par_source_et_domaine(): void
    {
        AiDocumentIndex::create([
            'source' => 'project_code',
            'titre' => 'ReferentielService',
            'chemin_fichier' => 'app/Services/ReferentielService.php',
            'index_morceau' => 0,
            'contenu_morceau' => 'Gestion des unités d enseignement et crédits ECTS.',
            'empreinte_sha256' => hash('sha256', 'd1'),
            'version_indexeur' => 1,
            'domaine_metier' => 'lmd',
        ]);

        AiDocumentIndex::create([
            'source' => 'business_procedure',
            'titre' => 'Procédure ECTS LMD',
            'chemin_fichier' => 'docs/lmd-ects.md',
            'index_morceau' => 0,
            'contenu_morceau' => 'Attribution des crédits ECTS par commission.',
            'empreinte_sha256' => hash('sha256', 'd2'),
            'version_indexeur' => 1,
            'domaine_metier' => 'lmd',
        ]);

        // Filtrer par source project_code uniquement
        $resCode = $this->moteur->rechercher('crédits ECTS', $this->userAdmin, 5, ['source' => 'project_code']);
        $this->assertCount(1, $resCode);
        $this->assertEquals('project_code', $resCode[0]['source']);

        // Filtrer par source business_procedure uniquement
        $resDoc = $this->moteur->rechercher('crédits ECTS', $this->userAdmin, 5, ['source' => 'business_procedure']);
        $this->assertCount(1, $resDoc);
        $this->assertEquals('business_procedure', $resDoc[0]['source']);
    }

    public function test_structure_et_exactitude_des_citations(): void
    {
        AiDocumentIndex::create([
            'source' => 'loop_report',
            'titre' => 'Rapport LOOP Notes Evaluations',
            'chemin_fichier' => 'docs/engineering/LOOP-NOTES.md',
            'index_morceau' => 0,
            'contenu_morceau' => 'Le calcul de délibération applique le seuil de compensation annuelle.',
            'empreinte_sha256' => hash('sha256', 'e'),
            'version_indexeur' => 1,
            'statut_document' => 'valide',
            'version_document' => '1.4',
            'domaine_metier' => 'examens',
        ]);

        $res = $this->moteur->rechercher('compensation annuelle', $this->userAdmin);

        $this->assertNotEmpty($res);
        $citation = $res[0];

        $this->assertArrayHasKey('source', $citation);
        $this->assertArrayHasKey('titre', $citation);
        $this->assertArrayHasKey('chemin', $citation);
        $this->assertArrayHasKey('statut', $citation);
        $this->assertArrayHasKey('version', $citation);
        $this->assertArrayHasKey('extrait', $citation);
        $this->assertArrayHasKey('score', $citation);
        $this->assertArrayHasKey('date_indexation', $citation);

        $this->assertEquals('1.4', $citation['version']);
        $this->assertEquals('valide', $citation['statut']);
        $this->assertEquals('docs/engineering/LOOP-NOTES.md', $citation['chemin']);
    }

    public function test_performance_et_latence_de_recherche(): void
    {
        // Créer 20 fragments synthétiques
        for ($i = 1; $i <= 20; $i++) {
            AiDocumentIndex::create([
                'source' => 'business_procedure',
                'titre' => "Document {$i}",
                'chemin_fichier' => "docs/doc_{$i}.md",
                'index_morceau' => 0,
                'contenu_morceau' => "Texte de référence portant sur l'algorithme numéro {$i} avec mots clés académiques.",
                'empreinte_sha256' => hash('sha256', "p_{$i}"),
                'version_indexeur' => 1,
                'statut_document' => 'valide',
            ]);
        }

        $debut = hrtime(true);
        $resultats = $this->moteur->rechercher('algorithme académique', $this->userAdmin);
        $fin = hrtime(true);
        $dureeMs = ($fin - $debut) / 1e6;

        $this->assertNotEmpty($resultats);
        // Latence locale doit être ultra-rapide (< 100 ms)
        $this->assertLessThan(100.0, $dureeMs);
    }
}
