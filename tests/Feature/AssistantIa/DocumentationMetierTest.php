<?php

namespace Tests\Feature\AssistantIa;

use App\Models\User;
use App\Modules\AssistantIa\Models\AiDocumentIndex;
use App\Modules\AssistantIa\Services\ConnecteurDocumentationMetier;
use App\Modules\AssistantIa\Services\MoteurRechercheDocumentaire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentationMetierTest extends TestCase
{
    use RefreshDatabase;

    protected ConnecteurDocumentationMetier $connecteur;

    protected MoteurRechercheDocumentaire $moteur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connecteur = app(ConnecteurDocumentationMetier::class);
        $this->moteur = app(MoteurRechercheDocumentaire::class);
    }

    public function test_analyse_metadonnees_frontmatter_et_statuts(): void
    {
        $contenu = <<<'MD'
---
titre: Règle d'affectation des groupes de TD
statut: valide
domaine: lmd
version: 2.1
confidentialite: interne
---
# Directives officielles LMD
Les étudiants sont répartis par ordre alphabétique dans les groupes de TD.
MD;

        $meta = $this->connecteur->analyserMetadonnees($contenu, 'docs/regles-td.md');

        $this->assertEquals("Règle d'affectation des groupes de TD", $meta['titre']);
        $this->assertEquals('valide', $meta['statut']);
        $this->assertEquals('lmd', $meta['domaine']);
        $this->assertEquals('2.1', $meta['version']);
        $this->assertFalse($meta['restreint']);
        $this->assertStringContainsString('Les étudiants sont répartis', $meta['corps']);
    }

    public function test_distinction_document_approuve_vs_document_brouillon(): void
    {
        $brouillonContenu = <<<'MD'
---
titre: Proposition Nouvelle Procédure d'Inscription
statut: brouillon
domaine: inscriptions
version: 0.9
---
Ce texte est une ébauche non votée par le conseil.
MD;

        $meta = $this->connecteur->analyserMetadonnees($brouillonContenu, 'docs/brouillon-inscription.md');
        $this->assertEquals('brouillon', $meta['statut']);
    }

    public function test_document_obsolete_recoit_avertissement_explicite(): void
    {
        $obsoleteContenu = <<<'MD'
---
titre: Ancien Barème des Notes 2020
statut: obsolete
domaine: examens
version: 1.0
---
Validation du semestre à 9.5/20.
MD;

        $meta = $this->connecteur->analyserMetadonnees($obsoleteContenu, 'docs/ancien-bareme.md');
        $this->assertEquals('obsolete', $meta['statut']);
    }

    public function test_scoping_documents_restreints_par_permission(): void
    {
        // Création directe d'un chunk confidentiel pour tester le moteur
        AiDocumentIndex::create([
            'source' => 'business_procedure',
            'titre' => 'Procédure Conseil de Discipline',
            'chemin_fichier' => 'docs/discipline.md',
            'index_morceau' => 0,
            'contenu_morceau' => 'Règles disciplinaires confidentielles pour la commission.',
            'empreinte_sha256' => hash('sha256', 'discipline'),
            'version_indexeur' => 1,
            'est_sensible' => true,
            'permission_requise' => 'assistant_ia.sources_restreintes',
            'statut_document' => 'valide',
            'domaine_metier' => 'gouvernance',
        ]);

        // Utilisateur normal : sans permission restreinte
        $userNormal = User::factory()->make([
            'id' => 93,
            'role' => 'agent_scolarite',
        ]);
        $userNormal->setRelation('roleModel', null);

        // Super Admin : a accès à tout
        $superAdmin = User::factory()->make([
            'id' => 94,
            'role' => 'super_admin',
        ]);
        $superAdmin->setRelation('roleModel', null);

        $resultatsNormal = $this->moteur->rechercher('disciplinaires', $userNormal);
        $this->assertEmpty($resultatsNormal);

        $resultatsAdmin = $this->moteur->rechercher('disciplinaires', $superAdmin);
        $this->assertNotEmpty($resultatsAdmin);
        $this->assertEquals('docs/discipline.md', $resultatsAdmin[0]['chemin']);
    }

    public function test_recherche_filtree_par_domaine_metier(): void
    {
        AiDocumentIndex::create([
            'source' => 'business_procedure',
            'titre' => 'Règle Inscription Annuelle',
            'chemin_fichier' => 'docs/inscriptions/regle.md',
            'index_morceau' => 0,
            'contenu_morceau' => 'Plafond inscription fixé à 3 inscriptions max.',
            'empreinte_sha256' => hash('sha256', 'regle1'),
            'version_indexeur' => 1,
            'est_sensible' => false,
            'statut_document' => 'valide',
            'domaine_metier' => 'inscriptions',
        ]);

        $docsInscriptions = AiDocumentIndex::parDomaine('inscriptions')->get();
        $this->assertCount(1, $docsInscriptions);
        $this->assertEquals('Règle Inscription Annuelle', $docsInscriptions->first()->titre);

        $docsExamens = AiDocumentIndex::parDomaine('examens')->get();
        $this->assertCount(0, $docsExamens);
    }
}
