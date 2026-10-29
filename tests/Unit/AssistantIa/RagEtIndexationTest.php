<?php

namespace Tests\Unit\AssistantIa;

use App\Models\User;
use App\Modules\AssistantIa\Models\AiDocumentIndex;
use App\Modules\AssistantIa\Services\IndexeurDocumentaire;
use App\Modules\AssistantIa\Services\MoteurRechercheDocumentaire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RagEtIndexationTest extends TestCase
{
    use RefreshDatabase;

    public function test_regles_d_exclusion_strictes_des_fichiers(): void
    {
        $indexeur = new IndexeurDocumentaire;

        // Autorisés
        $this->assertTrue($indexeur->estFichierAutorise('docs/engineering/LOOP-UNIREGISTRE.md'));
        $this->assertTrue($indexeur->estFichierAutorise('docs/architecture/lmd.txt'));

        // Exclus impérativement
        $this->assertFalse($indexeur->estFichierAutorise('.env'));
        $this->assertFalse($indexeur->estFichierAutorise('.env.production'));
        $this->assertFalse($indexeur->estFichierAutorise('vendor/laravel/framework/composer.json'));
        $this->assertFalse($indexeur->estFichierAutorise('node_modules/vue/package.json'));
        $this->assertFalse($indexeur->estFichierAutorise('storage/app/private/etudiant_cin.pdf'));
        $this->assertFalse($indexeur->estFichierAutorise('certificats/server.key'));
        $this->assertFalse($indexeur->estFichierAutorise('app/Http/Controllers/SecretController.php')); // hors docs
    }

    public function test_decoupage_texte_en_morceaux_coherents(): void
    {
        $indexeur = new IndexeurDocumentaire;

        $texteCourt = 'Ceci est un document très court.';
        $morceauxCourt = $indexeur->decouperTexte($texteCourt, 100);
        $this->assertCount(1, $morceauxCourt);

        $texteLong = str_repeat("Article premier. L'inscription universitaire s'effectue en début d'année académique.\n", 30);
        $morceauxLong = $indexeur->decouperTexte($texteLong, 300, 50);
        $this->assertGreaterThan(1, count($morceauxLong));
    }

    public function test_indexation_calcule_sha256_et_evite_reindexation_inutile(): void
    {
        $indexeur = new IndexeurDocumentaire;

        $contenu = "Règlement LMD UniRegistre : Un semestre est validé dès l'obtention de 30 crédits ECTS.";
        $chemin = 'docs/regles-metier/lmd_credits.md';

        // Première indexation
        $nbMorceaux1 = $indexeur->indexerContenu($chemin, 'Crédits LMD', $contenu, 'regles');
        $this->assertSame(1, $nbMorceaux1);
        $this->assertDatabaseHas('ai_documents_index', ['chemin_fichier' => $chemin]);

        // Deuxième indexation identique -> Détection par empreinte sha256 (aucun doublon)
        $nbMorceaux2 = $indexeur->indexerContenu($chemin, 'Crédits LMD', $contenu, 'regles');
        $this->assertSame(1, $nbMorceaux2);
        $this->assertSame(1, AiDocumentIndex::where('chemin_fichier', $chemin)->count());
    }

    public function test_recherche_documentaire_classe_par_pertinence_et_fournit_citations_verifiables(): void
    {
        $indexeur = new IndexeurDocumentaire;
        $moteur = new MoteurRechercheDocumentaire;

        $indexeur->indexerContenu(
            'docs/regles-metier/inscriptions.md',
            'Procédure d\'inscription',
            'Le paiement des frais de scolarité est requis pour finaliser l\'inscription administrative.',
            'regles'
        );

        $indexeur->indexerContenu(
            'docs/regles-metier/examens.md',
            'Session d\'examens',
            'Les délibérations se tiennent après la seconde session de rattrapage.',
            'regles'
        );

        $resultats = $moteur->rechercher('paiement des frais de scolarité');

        $this->assertNotEmpty($resultats);
        $premier = $resultats[0];
        $this->assertSame('Procédure d\'inscription', $premier['titre']);
        $this->assertSame('docs/regles-metier/inscriptions.md', $premier['chemin']);
        $this->assertStringContainsString('frais de scolarité', $premier['extrait']);
        $this->assertGreaterThan(0, $premier['score']);
    }

    public function test_isolation_des_documents_sensibles_selon_les_permissions(): void
    {
        $indexeur = new IndexeurDocumentaire;
        $moteur = new MoteurRechercheDocumentaire;

        // Document public/métier standard
        $indexeur->indexerContenu(
            'docs/regles-metier/calendrier.md',
            'Calendrier universitaire',
            'La rentrée académique a lieu le premier lundi d\'octobre.',
            'public',
            false
        );

        // Document restreint
        $indexeur->indexerContenu(
            'docs/engineering/rapport_audit_interne.md',
            'Rapport confidentiel',
            'Audit des vulnérabilités de l\'infrastructure serveur.',
            'interne',
            true, // sensible
            'assistant_ia.sources_restreintes'
        );

        // Utilisateur scolarité standard (sans permission sources_restreintes)
        $agent = User::factory()->create();
        $agent->definirRole('agent_scolarite');

        $resultatsAgent = $moteur->rechercher('infrastructure serveur vulnérabilités', $agent);
        $this->assertEmpty($resultatsAgent, 'Un agent sans permission ne doit JAMAIS voir de document restreint');

        // Super administrateur
        $superAdmin = User::factory()->create();
        $superAdmin->definirRole('super_admin');

        $resultatsAdmin = $moteur->rechercher('infrastructure serveur vulnérabilités', $superAdmin);
        $this->assertNotEmpty($resultatsAdmin);
        $this->assertSame('Rapport confidentiel', $resultatsAdmin[0]['titre']);
    }
}
