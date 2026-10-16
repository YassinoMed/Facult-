<?php

namespace Tests\Feature;

use App\Models\Courriel;
use App\Models\JournalModele;
use App\Models\ModeleCourriel;
use App\Models\ThemeCourriel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP-MAIL-TEMPLATE-STUDIO-03 — cycle de vie, permissions, sécurité.
 * Transport `array` en test : aucun envoi réel.
 */
class StudioCourrielTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $utilisateur = User::creerCompteBackOffice([
            'name' => 'Super', 'email' => uniqid('st.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], 'super_admin');

        $this->actingAs($utilisateur);

        return $utilisateur;
    }

    private function agentLimite(): void
    {
        $utilisateur = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => uniqid('stagent.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->actingAs($utilisateur);
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(array $surcharge = []): array
    {
        return array_merge([
            'schema_version' => 1,
            'meta' => ['objet' => 'Sujet {{reference_dossier}}', 'preheader' => '', 'categorie' => 'confirmation'],
            'theme' => [
                'couleur_principale' => '#3730a3', 'couleur_accent' => '#4f46e5',
                'fond' => '#f1f5f9', 'fond_contenu' => '#ffffff', 'largeur' => 600,
                'logo_url' => null, 'nom_etablissement' => 'Univ', 'signature' => '', 'mentions' => '',
            ],
            'blocs' => [
                ['type' => 'texte', 'contenu' => 'Bonjour {{destinataire_nom}}', 'alignement' => 'gauche'],
            ],
        ], $surcharge);
    }

    private function creerBrouillon(): ModeleCourriel
    {
        $this->superAdmin();

        $reponse = $this->post(route('admin.studio.store'), [
            'code' => 'test-'.uniqid(),
            'nom' => 'Modèle test',
            'categorie' => 'confirmation',
            'schema' => $this->schema(),
        ]);
        $reponse->assertRedirect();

        return ModeleCourriel::latest('id')->firstOrFail();
    }

    public function test_bibliotheque_et_creation_brouillon(): void
    {
        $this->superAdmin();
        $this->get(route('admin.studio.index'))->assertOk()->assertSee('Studio e-mail');

        $modele = $this->creerBrouillon();
        $this->assertSame('brouillon', $modele->statut);
        $this->assertSame(1, $modele->versions()->count());
        $this->assertTrue(JournalModele::where('modele_id', $modele->id)->where('action', 'cree')->exists());
    }

    public function test_cycle_complet_publication_archivage_restauration(): void
    {
        $modele = $this->creerBrouillon();

        // Sauvegarde JSON : nouvelle version immuable.
        $schema = $this->schema();
        $schema['blocs'][] = ['type' => 'texte', 'contenu' => 'Second paragraphe.', 'alignement' => 'gauche'];
        $this->postJson(route('admin.studio.enregistrer', $modele), [
            'schema' => $schema, 'base_version' => 1,
        ])->assertOk()->assertJsonPath('numero', 2);

        // Conflit optimiste : base périmée refusée.
        $this->postJson(route('admin.studio.enregistrer', $modele), [
            'schema' => $schema, 'base_version' => 1,
        ])->assertStatus(409);

        // Schéma invalide refusé.
        $mauvais = $schema;
        $mauvais['blocs'][0]['contenu'] = '<script>alert(1)</script>';
        $this->postJson(route('admin.studio.enregistrer', $modele), [
            'schema' => $mauvais, 'base_version' => 2,
        ])->assertStatus(422);

        $this->post(route('admin.studio.soumettre', $modele))->assertRedirect();
        $this->assertSame('a_valider', $modele->fresh()->statut);

        $this->post(route('admin.studio.publier', $modele))->assertRedirect();
        $modele->refresh();
        $this->assertSame('publie', $modele->statut);
        $this->assertSame(2, $modele->version_courante);

        // Suppression d'un modèle publié : interdite (archivage exigé).
        $this->delete(route('admin.studio.supprimer', $modele))->assertRedirect();
        $this->assertNotNull(ModeleCourriel::find($modele->id));

        // Restauration de la v1 comme nouvelle version publiée.
        $this->post(route('admin.studio.restaurer', $modele), ['version' => 1])->assertRedirect();
        $modele->refresh();
        $this->assertSame(3, $modele->version_courante);
        $this->assertSame('publie', $modele->statut);

        $this->post(route('admin.studio.archiver', $modele))->assertRedirect();
        $this->assertSame('archive', $modele->fresh()->statut);

        // Édition d'un modèle archivé : refusée.
        $this->get(route('admin.studio.editer', $modele))->assertRedirect();
        $this->postJson(route('admin.studio.enregistrer', $modele), [
            'schema' => $schema, 'base_version' => 3,
        ])->assertStatus(409);

        // Comparatif de versions.
        $this->get(route('admin.studio.voir', $modele).'?v1=1&v2=3')
            ->assertOk()->assertSee('modifié', false);
    }

    public function test_duplication_et_suppression_brouillon_jamais_publie(): void
    {
        $modele = $this->creerBrouillon();

        $this->post(route('admin.studio.dupliquer', $modele))->assertRedirect();
        $copie = ModeleCourriel::where('code', 'like', $modele->code.'%copie%')->firstOrFail();
        $this->assertSame('brouillon', $copie->statut);

        $this->delete(route('admin.studio.supprimer', $copie))->assertRedirect();
        $this->assertNull(ModeleCourriel::find($copie->id));

        // Code dupliqué refusé à la création.
        $this->post(route('admin.studio.store'), [
            'code' => $modele->code, 'nom' => 'X', 'schema' => $this->schema(),
        ])->assertRedirect();
        $this->assertSame(1, ModeleCourriel::where('code', $modele->code)->count());
    }

    public function test_apercu_sans_effet_et_isole(): void
    {
        $modele = $this->creerBrouillon();

        $reponse = $this->postJson(route('admin.studio.apercu', $modele), ['schema' => $this->schema()]);
        $reponse->assertOk();
        $this->assertStringContainsString('<table', $reponse->json('html'));
        $this->assertStringNotContainsString('<script', $reponse->json('html'));
        $this->assertSame(1, $modele->versions()->count());

        $this->get(route('admin.studio.editer', $modele))
            ->assertOk()
            ->assertSee('sandbox=""', false)
            ->assertSee('editeurStudio', false);
    }

    public function test_permissions_distinctes_et_idor(): void
    {
        $modele = $this->creerBrouillon();

        $this->agentLimite();
        $this->get(route('admin.studio.index'))->assertForbidden();
        $this->get(route('admin.studio.voir', $modele))->assertForbidden();
        $this->get(route('admin.studio.editer', $modele))->assertForbidden();
        $this->postJson(route('admin.studio.enregistrer', $modele), [
            'schema' => $this->schema(), 'base_version' => 1,
        ])->assertForbidden();
        $this->post(route('admin.studio.publier', $modele))->assertForbidden();
        $this->post(route('admin.studio.envoi-test', $modele), [])->assertForbidden();

        // Invité : redirection vers la connexion scolarité.
        auth()->logout();
        $this->get(route('admin.studio.index'))->assertRedirect(route('admin.connexion.show'));
    }

    public function test_xss_stockee_neutralisee_au_rendu(): void
    {
        $modele = $this->creerBrouillon();

        // Tentative via l'API : rejetée à la validation (422), rien en base.
        $schema = $this->schema();
        $schema['blocs'][0]['contenu'] = '"><img src=x onerror=alert(1)>';
        $this->postJson(route('admin.studio.enregistrer', $modele), [
            'schema' => $schema, 'base_version' => 1,
        ])->assertStatus(422);
        $this->assertSame(1, $modele->versions()->count());

        // Variable sensible : rejetée.
        $schema['blocs'][0]['contenu'] = 'Votre code : {{code}}';
        $this->postJson(route('admin.studio.enregistrer', $modele), [
            'schema' => $schema, 'base_version' => 1,
        ])->assertStatus(422);
    }

    public function test_envoi_test_allowlist_confirmation_journal(): void
    {
        $modele = $this->creerBrouillon();

        // Allowlist vide par défaut : refusé.
        $this->post(route('admin.studio.envoi-test', $modele), [
            'version' => 1, 'destinataire' => 'test@example.com', 'confirmation' => '1',
        ])->assertRedirect();
        $this->assertSame(0, Courriel::where('categorie', 'test')->count());

        config(['courriels.adresses_test_autorisees' => ['test-autorise@example.com']]);

        // Sans confirmation : aperçu, aucun envoi.
        $this->post(route('admin.studio.envoi-test', $modele), [
            'version' => 1, 'destinataire' => 'test-autorise@example.com',
        ])->assertOk()->assertSee('Aperçu avant envoi réel', false);
        $this->assertSame(0, Courriel::where('categorie', 'test')->count());

        // Adresse hors allowlist : refusé même avec confirmation.
        $this->post(route('admin.studio.envoi-test', $modele), [
            'version' => 1, 'destinataire' => 'pirate@example.com', 'confirmation' => '1',
        ])->assertRedirect();
        $this->assertSame(0, Courriel::where('categorie', 'test')->count());

        // Envoi confirmé : transport `array`, journal masqué, version tracée.
        $this->post(route('admin.studio.envoi-test', $modele), [
            'version' => 1, 'destinataire' => 'test-autorise@example.com', 'confirmation' => '1',
        ])->assertRedirect();
        $ligne = Courriel::where('categorie', 'test')->sole();
        $this->assertSame('accepted_by_smtp', $ligne->statut);
        $this->assertSame('studio:'.$modele->code, $ligne->modele);
        $this->assertSame('1', $ligne->version_modele);
        $this->assertStringNotContainsString('test-autorise@example.com', (string) json_encode($ligne->toArray()));
        $this->assertTrue(
            JournalModele::where('modele_id', $modele->id)->where('action', 'envoi_test')->exists()
        );
    }

    public function test_themes_visibles_et_crees(): void
    {
        $this->superAdmin();
        $this->get(route('admin.studio.themes'))->assertOk();

        $this->post(route('admin.studio.themes.store'), [
            'nom' => 'Faculté',
            'config' => [
                'couleur_principale' => '#3730a3', 'couleur_accent' => '#4f46e5',
                'fond' => '#f1f5f9', 'fond_contenu' => '#ffffff', 'largeur' => 600,
                'logo_url' => 'https://img.test/logo.png', 'nom_etablissement' => 'Univ',
                'signature' => 'S', 'mentions' => 'M',
            ],
        ])->assertRedirect();
        $this->assertTrue(ThemeCourriel::where('nom', 'Faculté')->exists());

        // Couleur invalide refusée.
        $this->post(route('admin.studio.themes.store'), [
            'nom' => 'Mauvais',
            'config' => [
                'couleur_principale' => 'red', 'couleur_accent' => '#4f46e5',
                'fond' => '#f1f5f9', 'fond_contenu' => '#ffffff', 'largeur' => 600,
            ],
        ])->assertRedirect();
        $this->assertFalse(ThemeCourriel::where('nom', 'Mauvais')->exists());
    }

    public function test_non_regression_catalogue_intact(): void
    {
        $catalogue = config('courriels.modeles');
        $this->assertCount(7, $catalogue);
        foreach (['code_verification', 'code_changement_email', 'code_nouvelle_adresse', 'confirmation_mise_a_jour', 'alerte_changement_email', 'confirmation_changement_email', 'message_manuel'] as $identifiant) {
            $this->assertArrayHasKey($identifiant, $catalogue);
        }

        // Aucun modèle système dans les tables du studio.
        $this->assertSame(0, ModeleCourriel::where('verrou_systeme', true)->count());
    }
}
