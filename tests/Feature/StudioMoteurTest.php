<?php

namespace Tests\Feature;

use App\Services\MoteurRenduCourriel;
use App\Services\ValidateurSchemaCourriel;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * LOOP-MAIL-TEMPLATE-STUDIO-03 — validateur de schéma et moteur de rendu :
 * rejet XSS/HTML/URL/variables, interpolation échappée, HTML + texte.
 */
class StudioMoteurTest extends TestCase
{
    private function schemaValide(array $surcharge = []): array
    {
        return array_merge([
            'schema_version' => 1,
            'meta' => ['objet' => 'Sujet {{reference_dossier}}', 'preheader' => 'Aperçu', 'categorie' => 'confirmation'],
            'theme' => [
                'couleur_principale' => '#3730a3', 'couleur_accent' => '#4f46e5',
                'fond' => '#f1f5f9', 'fond_contenu' => '#ffffff', 'largeur' => 600,
                'logo_url' => null, 'nom_etablissement' => 'Univ Test',
                'signature' => 'Scolarité', 'mentions' => 'Message automatique.',
            ],
            'blocs' => [
                ['type' => 'titre', 'texte' => 'Bonjour {{destinataire_nom}}', 'niveau' => 'h2', 'alignement' => 'gauche'],
                ['type' => 'texte', 'contenu' => "Paragraphe un.\n\nParagraphe deux.", 'alignement' => 'gauche'],
                ['type' => 'bouton', 'libelle' => 'Voir', 'url' => 'https://exemple.univ.test/dossier', 'alignement' => 'centre'],
            ],
        ], $surcharge);
    }

    private function validateur(): ValidateurSchemaCourriel
    {
        return app(ValidateurSchemaCourriel::class);
    }

    public function test_schema_valide_normalise(): void
    {
        $normalise = $this->validateur()->valider($this->schemaValide());
        $this->assertSame(1, $normalise['schema_version']);
        $this->assertCount(3, $normalise['blocs']);
        $this->assertSame(600, $normalise['theme']['largeur']);
    }

    public function test_rejette_version_blocs_vides_et_trop_nombreux(): void
    {
        foreach ([
            ['schema_version' => 2],
            ['blocs' => []],
            ['blocs' => array_fill(0, 31, ['type' => 'espaceur', 'hauteur' => 8, 'alignement' => 'gauche'])],
        ] as $cas) {
            try {
                $this->validateur()->valider(array_merge($this->schemaValide(), $cas));
                $this->fail('Schéma accepté : '.json_encode(array_keys($cas)));
            } catch (InvalidArgumentException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function test_rejette_xss_html_et_contenu_actif(): void
    {
        $charges = [
            '<script>alert(1)</script>',
            '<b>gras</b>',
            '<a href="x" onclick="y">clic</a>',
            'voir javascript:alert(1)',
            '<iframe src="x"></iframe>',
            '<form action="x"></form>',
        ];

        foreach ($charges as $charge) {
            try {
                $schema = $this->schemaValide();
                $schema['blocs'][1]['contenu'] = $charge;
                $this->validateur()->valider($schema);
                $this->fail('Charge acceptée : '.$charge);
            } catch (InvalidArgumentException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function test_rejette_variable_inconnue_et_malformee(): void
    {
        foreach (['{{code}}', '{{token}}', '{{ mot_de_passe }}', '{{inconnue}}', '{{nom}'] as $charge) {
            try {
                $schema = $this->schemaValide();
                $schema['blocs'][1]['contenu'] = 'Bonjour '.$charge;
                $this->validateur()->valider($schema);
                $this->fail('Variable acceptée : '.$charge);
            } catch (InvalidArgumentException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function test_rejette_url_interdites_et_images(): void
    {
        foreach (['http://clair.test/x', 'javascript:alert(1)', 'data:text/html;base64,xxx', 'ftp://f.test/x', ''] as $url) {
            try {
                $schema = $this->schemaValide();
                $schema['blocs'][2]['url'] = $url;
                $this->validateur()->valider($schema);
                $this->fail('URL acceptée : '.$url);
            } catch (InvalidArgumentException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }

        $schema = $this->schemaValide();
        $schema['blocs'][] = ['type' => 'image', 'url' => 'https://img.test/x.exe', 'texte_alt' => 'x', 'largeur' => 200, 'alignement' => 'gauche'];
        try {
            $this->validateur()->valider($schema);
            $this->fail('Image .exe acceptée.');
        } catch (InvalidArgumentException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $schema['blocs'][3]['url'] = 'https://img.test/x.png';
        $normalise = $this->validateur()->valider($schema);
        $this->assertSame('https://img.test/x.png', $normalise['blocs'][3]['url']);
    }

    public function test_rejette_couleurs_dimensions_invalides(): void
    {
        $schema = $this->schemaValide();
        $schema['theme']['couleur_principale'] = 'red';
        try {
            $this->validateur()->valider($schema);
            $this->fail('Couleur nommée acceptée.');
        } catch (InvalidArgumentException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $schema = $this->schemaValide();
        $schema['theme']['largeur'] = 1200;
        try {
            $this->validateur()->valider($schema);
            $this->fail('Largeur hors bornes acceptée.');
        } catch (InvalidArgumentException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    public function test_rendu_html_texte_interpole_et_echappe(): void
    {
        $moteur = app(MoteurRenduCourriel::class);
        $rendu = $moteur->rendre(
            $this->validateur()->valider($this->schemaValide()),
            ['destinataire_nom' => '<b>Amina</b>', 'reference_dossier' => 'INS-1']
        );

        $this->assertSame('Sujet INS-1', $rendu['sujet']);
        $this->assertStringContainsString('&lt;b&gt;Amina&lt;/b&gt;', $rendu['html']);
        $this->assertStringNotContainsString('<b>Amina</b>', $rendu['html']);
        $this->assertStringContainsString('<table', $rendu['html']);
        $this->assertStringContainsString('https://exemple.univ.test/dossier', $rendu['html']);
        $this->assertStringNotContainsString('<script', $rendu['html']);
        $this->assertStringContainsString('INS-1', $rendu['texte']);
        $this->assertStringContainsString('Paragraphe un.', $rendu['texte']);

        // Variable absente = chaîne vide, jamais d'erreur ni de fuite.
        $renduVide = $moteur->rendre($this->validateur()->valider($this->schemaValide()), []);
        $this->assertStringNotContainsString('{{', $renduVide['html']);
        $this->assertStringNotContainsString('{{', $renduVide['texte']);
    }

    public function test_contexte_fictif_sans_dossier_reel(): void
    {
        $contexte = app(MoteurRenduCourriel::class)->contexteFictif();
        $this->assertArrayHasKey('etablissement_nom', $contexte);
        $this->assertArrayNotHasKey('code', $contexte);
        $this->assertArrayNotHasKey('token', $contexte);
    }
}
