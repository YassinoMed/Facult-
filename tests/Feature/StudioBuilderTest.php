<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ControleQualiteCourriel;
use App\Services\MoteurRenduCourriel;
use App\Services\StudioCourrielService;
use App\Services\ValidateurSchemaCourriel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * LOOP-MAIL-TEMPLATE-STUDIO-04 — builder visuel : nouveaux blocs,
 * verrouillage, qualité, flag progressif, compatibilité v1.
 */
class StudioBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): void
    {
        $this->actingAs(User::creerCompteBackOffice([
            'name' => 'Super', 'email' => uniqid('sb.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], 'super_admin'));
    }

    /**
     * @return array<string, mixed>
     */
    private function theme(array $surcharge = []): array
    {
        return array_merge([
            'couleur_principale' => '#3730a3', 'couleur_accent' => '#4f46e5',
            'fond' => '#f1f5f9', 'fond_contenu' => '#ffffff', 'largeur' => 600,
            'logo_url' => null, 'nom_etablissement' => 'Univ',
            'signature' => 'Scolarité', 'mentions' => '',
        ], $surcharge);
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(array $blocs, array $theme = []): array
    {
        return [
            'schema_version' => 1,
            'meta' => ['objet' => 'Sujet', 'preheader' => '', 'categorie' => 'confirmation'],
            'theme' => $this->theme($theme),
            'blocs' => $blocs,
        ];
    }

    private function validateur(): ValidateurSchemaCourriel
    {
        return app(ValidateurSchemaCourriel::class);
    }

    public function test_nouveaux_blocs_valides_et_rendus(): void
    {
        $normalise = $this->validateur()->valider($this->schema([
            ['type' => 'colonnes', 'colonnes' => [
                [['type' => 'titre', 'texte' => 'Gauche', 'niveau' => 'h3', 'alignement' => 'gauche']],
                [['type' => 'texte', 'contenu' => 'Droite {{reference_dossier}}', 'alignement' => 'gauche']],
            ], 'alignement' => 'gauche'],
            ['type' => 'carte', 'titre' => 'Info', 'texte' => 'Détail.', 'couleur_bordure' => null, 'alignement' => 'gauche'],
            ['type' => 'tableau', 'entetes' => ['A', 'B'], 'lignes' => [['1', '2'], ['3', '4']], 'alignement' => 'gauche'],
            ['type' => 'signature', 'texte' => '', 'alignement' => 'gauche'],
        ]));

        $this->assertCount(4, $normalise['blocs']);
        $this->assertSame('arial', $normalise['theme']['police']);
        $this->assertSame('arrondi', $normalise['theme']['style_bouton']);

        $rendu = app(MoteurRenduCourriel::class)->rendre($normalise, ['reference_dossier' => 'INS-9']);
        $this->assertStringContainsString('width="50%"', $rendu['html']);
        $this->assertStringContainsString('border-left:4px solid', $rendu['html']);
        $this->assertStringContainsString('<th', $rendu['html']);
        $this->assertStringContainsString('Scolarité', $rendu['html']);
        $this->assertStringContainsString('INS-9', $rendu['texte']);
        $this->assertStringContainsString('1 | 2', $rendu['texte']);
        $this->assertStringNotContainsString('<script', $rendu['html']);
    }

    public function test_nouveaux_blocs_rejettent_leurs_invalides(): void
    {
        $cas = [
            'colonnes-3' => [['type' => 'colonnes', 'colonnes' => [[], [], []], 'alignement' => 'gauche']],
            'colonnes-imbriquee' => [['type' => 'colonnes', 'colonnes' => [[['type' => 'carte', 'titre' => 'X', 'texte' => 'Y', 'couleur_bordure' => null, 'alignement' => 'gauche']], []], 'alignement' => 'gauche']],
            'colonnes-verrou' => [['type' => 'colonnes', 'colonnes' => [[['type' => 'texte', 'contenu' => 'X', 'alignement' => 'gauche', 'verrouille' => true]], []], 'alignement' => 'gauche']],
            'tableau-rectangle' => [['type' => 'tableau', 'entetes' => ['A', 'B'], 'lignes' => [['1']], 'alignement' => 'gauche']],
            'tableau-cellule-xss' => [['type' => 'tableau', 'entetes' => ['A'], 'lignes' => [['<img src=x onerror=y>']], 'alignement' => 'gauche']],
            'carte-couleur' => [['type' => 'carte', 'titre' => 'T', 'texte' => 'X', 'couleur_bordure' => 'red', 'alignement' => 'gauche']],
            'id-duplique' => [
                ['type' => 'texte', 'contenu' => 'A', 'alignement' => 'gauche', 'id' => 'x1'],
                ['type' => 'texte', 'contenu' => 'B', 'alignement' => 'gauche', 'id' => 'x1'],
            ],
            'id-invalide' => [['type' => 'texte', 'contenu' => 'A', 'alignement' => 'gauche', 'id' => 'A B']],
        ];

        foreach ($cas as $nom => $blocs) {
            try {
                $this->validateur()->valider($this->schema($blocs));
                $this->fail('Bloc accepté : '.$nom);
            } catch (InvalidArgumentException $e) {
                $this->assertNotEmpty($e->getMessage(), $nom);
            }
        }
    }

    public function test_bouton_url_variable_acceptee_puis_omise_si_absente(): void
    {
        $normalise = $this->validateur()->valider($this->schema([
            ['type' => 'bouton', 'libelle' => 'Voir', 'url' => '{{url_action}}', 'alignement' => 'centre'],
        ]));

        $moteur = app(MoteurRenduCourriel::class);
        $avec = $moteur->rendre($normalise, ['url_action' => 'https://exemple.univ.test/d']);
        $this->assertStringContainsString('https://exemple.univ.test/d', $avec['html']);

        $sans = $moteur->rendre($normalise, []);
        $this->assertStringNotContainsString('<a href', $sans['html']);
        $this->assertStringContainsString('Voir', $sans['texte']);
    }

    public function test_verrou_bloc_exige_identique(): void
    {
        $this->superAdmin();
        $service = app(StudioCourrielService::class);

        $schema = $this->schema([
            ['type' => 'texte', 'contenu' => 'Fixe.', 'alignement' => 'gauche', 'id' => 'intro', 'verrouille' => true],
            ['type' => 'texte', 'contenu' => 'Libre.', 'alignement' => 'gauche'],
        ]);
        $modele = $service->creer('verrou-'.uniqid(), 'Verrou', 'confirmation', $schema, 1);

        // Modification du bloc verrouillé : 422.
        $modifie = $schema;
        $modifie['blocs'][0]['contenu'] = 'Altéré.';
        $this->postJson(route('admin.studio.enregistrer', $modele), [
            'schema' => $modifie, 'base_version' => 1,
        ])->assertStatus(422);

        // Suppression du bloc verrouillé : 422.
        $tronque = $schema;
        array_shift($tronque['blocs']);
        $this->postJson(route('admin.studio.enregistrer', $modele), [
            'schema' => $tronque, 'base_version' => 1,
        ])->assertStatus(422);

        // Bloc libre modifié, verrou intact : OK (v2).
        $libre = $schema;
        $libre['blocs'][1]['contenu'] = 'Libre v2.';
        $this->postJson(route('admin.studio.enregistrer', $modele), [
            'schema' => $libre, 'base_version' => 1,
        ])->assertOk()->assertJsonPath('numero', 2);
    }

    public function test_qualite_bloquants_et_avertissements(): void
    {
        $qualite = app(ControleQualiteCourriel::class);

        $mauvais = $this->validateur()->valider($this->schema(
            [['type' => 'image', 'url' => 'https://img.test/x.png', 'texte_alt' => 'x', 'largeur' => 600, 'alignement' => 'gauche']],
            ['couleur_principale' => '#ffffff', 'couleur_accent' => '#ffffff']
        ));
        // fond_contenu #ffffff + titres blancs => contraste critique.
        $resultat = $qualite->analyser($mauvais);
        $codes = array_column($resultat['erreurs'], 'code');
        $this->assertContains('E_CONTRASTE_CRITIQUE', $codes);
        $this->assertContains('A_PREHEADER_VIDE', array_column($resultat['avertissements'], 'code'));

        $colonnes = $this->validateur()->valider($this->schema(
            [['type' => 'colonnes', 'colonnes' => [[], []], 'alignement' => 'gauche']]
        ));
        $resultat2 = $qualite->analyser($colonnes);
        $this->assertSame([], $resultat2['erreurs']);
        $this->assertContains('A_COLONNES_OUTLOOK', array_column($resultat2['avertissements'], 'code'));

        // Publication refusée sur bloquant.
        $this->superAdmin();
        $service = app(StudioCourrielService::class);
        $modele = $service->creer('qual-'.uniqid(), 'Qualité', 'confirmation', $this->schema(
            [['type' => 'image', 'url' => 'https://img.test/x.png', 'texte_alt' => 'x', 'largeur' => 600, 'alignement' => 'gauche']],
            ['couleur_principale' => '#ffffff', 'couleur_accent' => '#ffffff']
        ), 1);
        // Image 600 = largeur max : pas de débordement ; contraste critique bloque.
        $this->post(route('admin.studio.publier', $modele))->assertRedirect();
        $this->assertSame('brouillon', $modele->fresh()->statut);
    }

    public function test_image_debordement_bloque_publication(): void
    {
        $this->superAdmin();
        $service = app(StudioCourrielService::class);
        $modele = $service->creer('deb-'.uniqid(), 'Débordement', 'confirmation', $this->schema(
            [['type' => 'image', 'url' => 'https://img.test/x.png', 'texte_alt' => 'x', 'largeur' => 600, 'alignement' => 'gauche']],
            ['largeur' => 480]
        ), 1);
        $this->post(route('admin.studio.publier', $modele))->assertRedirect();
        $this->assertSame('brouillon', $modele->fresh()->statut);
    }

    public function test_flag_progressif_v1_par_defaut_v2_sur_demande(): void
    {
        $this->superAdmin();
        $modele = app(StudioCourrielService::class)->creer(
            'flag-'.uniqid(), 'Flag', 'confirmation', $this->schema([
                ['type' => 'texte', 'contenu' => 'Bonjour.', 'alignement' => 'gauche'],
            ]), 1
        );

        config(['courriels.editeur_v2' => false]);
        $this->get(route('admin.studio.editer', $modele))
            ->assertOk()->assertSee('editeurStudio(', false)->assertDontSee('editeurStudioV2', false);

        config(['courriels.editeur_v2' => true]);
        $this->get(route('admin.studio.editer', $modele))
            ->assertOk()->assertSee('editeurStudioV2', false)
            ->assertSee('sandbox=""', false)->assertSee('Bureau', false);
    }

    public function test_squelettes_valides_et_sans_secret(): void
    {
        foreach (StudioCourrielService::squelettes() as $nom => $squelette) {
            $normalise = $this->validateur()->valider($squelette);
            $rendu = app(MoteurRenduCourriel::class)->rendre($normalise, app(MoteurRenduCourriel::class)->contexteFictif());
            $this->assertStringNotContainsString('{{', $rendu['html'], $nom);
            $this->assertNotEmpty($rendu['sujet'], $nom);
        }
    }

    public function test_compatibilite_schemas_v1_rendu_inchange(): void
    {
        // Schéma v1 historique (sans id/verrouille/police/style_bouton) : défauts appliqués.
        $ancien = [
            'schema_version' => 1,
            'meta' => ['objet' => 'Sujet', 'preheader' => '', 'categorie' => 'confirmation'],
            'theme' => [
                'couleur_principale' => '#3730a3', 'couleur_accent' => '#4f46e5',
                'fond' => '#f1f5f9', 'fond_contenu' => '#ffffff', 'largeur' => 600,
                'logo_url' => null, 'nom_etablissement' => 'U', 'signature' => '', 'mentions' => '',
            ],
            'blocs' => [
                ['type' => 'titre', 'texte' => 'T', 'niveau' => 'h2', 'alignement' => 'gauche'],
                ['type' => 'texte', 'contenu' => 'C', 'alignement' => 'gauche'],
                ['type' => 'bouton', 'libelle' => 'V', 'url' => 'https://exemple.univ.test/', 'alignement' => 'centre'],
            ],
        ];
        $normalise = $this->validateur()->valider($ancien);
        $this->assertSame('arial', $normalise['theme']['police']);
        $this->assertSame('arrondi', $normalise['theme']['style_bouton']);
        $this->assertNull($normalise['blocs'][0]['id']);
        $this->assertFalse($normalise['blocs'][0]['verrouille']);

        // Revalidation du normalisé : stable (round-trip).
        $bis = $this->validateur()->valider($normalise);
        $this->assertSame($normalise, $bis);

        $rendu = app(MoteurRenduCourriel::class)->rendre($normalise, []);
        $this->assertStringContainsString('border-radius:8px', $rendu['html']);
        $this->assertStringContainsString('Arial,Helvetica,sans-serif', $rendu['html']);
    }

    public function test_theme_police_et_bouton_appliques(): void
    {
        $normalise = $this->validateur()->valider($this->schema(
            [['type' => 'bouton', 'libelle' => 'Go', 'url' => 'https://exemple.univ.test/', 'alignement' => 'centre']],
            ['police' => 'georgia', 'style_bouton' => 'angle']
        ));
        $rendu = app(MoteurRenduCourriel::class)->rendre($normalise, []);
        $this->assertStringContainsString('Georgia', $rendu['html']);
        $this->assertStringContainsString('border-radius:2px', $rendu['html']);
    }
}
