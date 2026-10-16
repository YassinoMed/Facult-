<?php

namespace Tests\Feature\Livewire\Admin\Courriels;

use App\Livewire\Admin\Courriels\DesignerCourriel;
use App\Models\ModeleCourriel;
use App\Models\User;
use App\Services\StudioCourrielService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * LOOP-MAIL-LIVEWIRE-DESIGNER-04 — designer une page : cycle complet,
 * canvas sans navigation, sécurité serveur souveraine, 409, permissions.
 * Transport `array` en test : aucun envoi réel.
 */
class DesignerLivewireTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $utilisateur = User::creerCompteBackOffice([
            'name' => 'Super', 'email' => uniqid('dw.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], 'super_admin');

        $this->actingAs($utilisateur);

        return $utilisateur;
    }

    private function agentLimite(): void
    {
        $this->actingAs(User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => uniqid('dwagent.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite'));
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(array $blocs = []): array
    {
        return [
            'schema_version' => 1,
            'meta' => ['objet' => 'Sujet', 'preheader' => '', 'categorie' => 'confirmation'],
            'theme' => [
                'couleur_principale' => '#3730a3', 'couleur_accent' => '#4f46e5',
                'fond' => '#f1f5f9', 'fond_contenu' => '#ffffff', 'largeur' => 600,
                'police' => 'arial', 'style_bouton' => 'arrondi',
                'logo_url' => null, 'nom_etablissement' => '',
                'signature' => '', 'mentions' => '',
            ],
            'blocs' => $blocs,
        ];
    }

    /** 1 page : route protégée + composant monté. */
    public function test_page_unique_protegee_et_montee(): void
    {
        $this->get(route('admin.studio.designer'))->assertRedirect(route('admin.connexion.show'));

        $this->agentLimite();
        $this->get(route('admin.studio.designer'))->assertForbidden();

        $this->superAdmin();
        $this->get(route('admin.studio.designer'))->assertOk();

        Livewire::test(DesignerCourriel::class)
            ->assertSee('Nouveau modèle')
            ->assertSee('Créer le brouillon');
    }

    /** Création → édition sur la MÊME page (aucune navigation). */
    public function test_creation_puis_edition_meme_page(): void
    {
        $this->superAdmin();

        $composant = Livewire::test(DesignerCourriel::class)
            ->set('code', 'dw-'.uniqid())
            ->set('nom', 'Designer')
            ->set('categorie', 'confirmation')
            ->call('appliquerSquelette', 0)
            ->assertSee('designer', false);

        $composant->call('creerBrouillon')
            ->assertSet('messageOk', true)
            ->assertSet('baseVersion', 1)
            ->assertSet('sale', false);

        $modele = ModeleCourriel::where('nom', 'Designer')->sole();
        $this->assertSame('brouillon', $modele->statut);
        $this->assertSame(1, $modele->versions()->count());

        // Le composant édité est le même : pas de route dédiée par section.
        $composant->assertSet('modeleId', $modele->id)
            ->assertSee('Canevas', false);
    }

    /** Blocs, réorganisation, duplication, suppression : tout via canvas. */
    public function test_canvas_blocs_reorganisation_et_verrou(): void
    {
        $this->superAdmin();
        $service = app(StudioCourrielService::class);
        $modele = $service->creer('dw-canvas-'.uniqid(), 'Canvas', 'confirmation', $this->schema([
            ['type' => 'texte', 'contenu' => 'Un', 'alignement' => 'gauche', 'id' => null, 'verrouille' => false],
            ['type' => 'texte', 'contenu' => 'Deux', 'alignement' => 'gauche', 'id' => null, 'verrouille' => false],
            ['type' => 'texte', 'contenu' => 'Trois', 'alignement' => 'gauche', 'id' => null, 'verrouille' => false],
        ]), 1);

        $composant = Livewire::test(DesignerCourriel::class, ['modeleId' => $modele->id])
            ->assertSet('baseVersion', 1)
            ->assertSee('Trois', false);

        // Sélection + déplacement : 3 → position 0.
        $composant->call('selectionner', 2)
            ->call('monterBloc', 2)
            ->call('monterBloc', 1)
            ->call('monterBloc', 0);

        $this->assertSame(
            ['Trois', 'Un', 'Deux'],
            array_column($composant->instance()->schema['blocs'], 'contenu')
        );

        // Duplication + suppression.
        $composant->call('dupliquerBloc', 0);
        $this->assertCount(4, $composant->instance()->schema['blocs']);
        $composant->call('supprimerBloc', 1);
        $this->assertCount(3, $composant->instance()->schema['blocs']);

        // Dépôt (drag & drop) : 0 → 2.
        $composant->call('deposerBloc', 0, 2);
        $this->assertSame(
            ['Un', 'Deux', 'Trois'],
            array_column($composant->instance()->schema['blocs'], 'contenu')
        );

        // Bloc verrouillé : toute altération refusée.
        $composant->call('ajouterBloc', [
            'type' => 'texte', 'contenu' => 'Fige.', 'alignement' => 'gauche', 'id' => 'fixe', 'verrouille' => true,
        ]);
        $composant->call('supprimerBloc', 3);
        $this->assertCount(4, $composant->instance()->schema['blocs']);
        $composant->call('monterBloc', 3);
        $this->assertSame('Fige.', $composant->instance()->schema['blocs'][3]['contenu']);
    }

    /** Undo / redo. */
    public function test_annuler_retablir(): void
    {
        $this->superAdmin();
        $service = app(StudioCourrielService::class);
        $modele = $service->creer('dw-undo-'.uniqid(), 'Undo', 'confirmation', $this->schema([
            ['type' => 'texte', 'contenu' => 'Un', 'alignement' => 'gauche', 'id' => null, 'verrouille' => false],
        ]), 1);

        $composant = Livewire::test(DesignerCourriel::class, ['modeleId' => $modele->id]);
        $composant->call('ajouterBloc', [
            'type' => 'titre', 'texte' => 'Titre', 'niveau' => 'h2', 'alignement' => 'gauche', 'id' => null, 'verrouille' => false,
        ]);
        $this->assertCount(2, $composant->instance()->schema['blocs']);

        $composant->call('annuler');
        $this->assertCount(1, $composant->instance()->schema['blocs']);

        $composant->call('retablir');
        $this->assertCount(2, $composant->instance()->schema['blocs']);
    }

    /** Sauvegarde, rechargement, 409 (modifications locales conservées). */
    public function test_sauvegarde_rechargement_et_conflit_409(): void
    {
        $this->superAdmin();
        $service = app(StudioCourrielService::class);
        $modele = $service->creer('dw-save-'.uniqid(), 'Save', 'confirmation', $this->schema([
            ['type' => 'texte', 'contenu' => 'V1.', 'alignement' => 'gauche', 'id' => null, 'verrouille' => false],
        ]), 1);

        $composant = Livewire::test(DesignerCourriel::class, ['modeleId' => $modele->id]);
        $composant->set('schema.meta.objet', 'Objet v2')
            ->call('sauvegarder')
            ->assertSet('messageOk', true)
            ->assertSet('baseVersion', 2)
            ->assertSet('sale', false);
        $this->assertSame(2, $modele->versions()->count());

        // Conflit : base périmée → refus, modifications locales conservées.
        $composant->set('schema.blocs.0.contenu', 'Local non perdu.')
            ->set('baseVersion', 1)
            ->call('sauvegarder')
            ->assertSet('messageOk', false)
            ->assertSee('plus récente', false);
        $this->assertSame('Local non perdu.', $composant->instance()->schema['blocs'][0]['contenu']);
        $this->assertSame(2, $modele->fresh()->versions()->count());

        // Rechargement explicite : reprise depuis la version serveur.
        $composant->call('chargerModele', $modele->id)
            ->assertSet('baseVersion', 2);
        $this->assertSame('V1.', $composant->instance()->schema['blocs'][0]['contenu']);
    }

    /** Aperçu : rendu SERVEUR, données fictives, sandbox. */
    public function test_apercu_rendu_serveur_fictif_sandbox(): void
    {
        $this->superAdmin();
        $service = app(StudioCourrielService::class);
        $modele = $service->creer('dw-apercu-'.uniqid(), 'Aperçu', 'confirmation', $this->schema([
            ['type' => 'texte', 'contenu' => 'Bonjour {{destinataire_nom}}', 'alignement' => 'gauche', 'id' => null, 'verrouille' => false],
        ]), 1);

        $reponse = $this->get(route('admin.studio.designer'));
        $reponse->assertOk();
        $html = $reponse->getContent();
        // Aucun dossier réel dans la page (pas de données d'étudiant).
        $this->assertStringNotContainsString('mohamed.benali@example.com', $html);

        $composant = Livewire::test(DesignerCourriel::class, ['modeleId' => $modele->id])
            ->call('rafraichirApercu')
            ->assertSet('erreurApercu', '');
        $this->assertStringContainsString('Bonjour', $composant->instance()->apercuHtml);
        $this->assertStringContainsString('Étudiant Exemple', $composant->instance()->apercuHtml);

        // Éditeur rendu : iframe sandbox présente (aucun script modèle exécutable).
        Livewire::test(DesignerCourriel::class, ['modeleId' => $modele->id])
            ->assertSee('sandbox=""', false);
    }

    /** Sécurité : schéma invalide rejeté à la sauvegarde (serveur souverain). */
    public function test_securite_xss_variable_inconnue_rejetees(): void
    {
        $this->superAdmin();
        $service = app(StudioCourrielService::class);
        $modele = $service->creer('dw-sec-'.uniqid(), 'Sécurité', 'confirmation', $this->schema([
            ['type' => 'texte', 'contenu' => 'Sain.', 'alignement' => 'gauche', 'id' => null, 'verrouille' => false],
        ]), 1);

        $composant = Livewire::test(DesignerCourriel::class, ['modeleId' => $modele->id]);

        // XSS dans un bloc : refus serveur, message, rien persisté.
        $composant->set('schema.blocs.0.contenu', '<script>alert(1)</script>')
            ->call('sauvegarder')
            ->assertSet('messageOk', false);
        $this->assertSame(1, $modele->fresh()->versions()->count());

        // Variable inconnue : refus serveur.
        $composant->set('schema.blocs.0.contenu', 'Code : {{code}}')
            ->call('sauvegarder')
            ->assertSet('messageOk', false);
        $this->assertSame(1, $modele->fresh()->versions()->count());

        // Sain : accepté.
        $composant->set('schema.blocs.0.contenu', 'Bonjour {{destinataire_nom}}.')
            ->call('sauvegarder')
            ->assertSet('messageOk', true);
        $this->assertSame(2, $modele->fresh()->versions()->count());
    }

    /** Cycle complet sur la page : publier, bloqué par la qualité, archivé non éditable. */
    public function test_cycle_publication_qualite_et_archivage(): void
    {
        $this->superAdmin();
        $service = app(StudioCourrielService::class);
        $modele = $service->creer('dw-cycle-'.uniqid(), 'Cycle', 'confirmation', $this->schema([
            ['type' => 'texte', 'contenu' => 'Contenu.', 'alignement' => 'gauche', 'id' => null, 'verrouille' => false],
        ]), 1);

        $composant = Livewire::test(DesignerCourriel::class, ['modeleId' => $modele->id])
            ->call('soumettre');
        $this->assertSame('a_valider', $modele->fresh()->statut);

        $composant->call('publier');
        $this->assertSame('publie', $modele->fresh()->statut);

        // Reprise d'un modèle publié : chargement refusé côté studio? Non : éditable
        // (statut publie reste modifiable), on vérifie le chargement ok.
        $composant->call('chargerModele', $modele->id)->assertSet('messageOk', true);

        // Archivage via le cockpit : le designer refuse ensuite (estModifiable() = false).
        $service->archiver($modele, 1);
        $composant->call('chargerModele', $modele->id)
            ->assertSet('messageOk', false);
        $this->assertStringContainsString('verrouillé', $composant->instance()->message);
    }

    /** Non-régression : catalogue système intact, OTP impossibles au designer. */
    public function test_non_regression_catalogue_otp_impossibles(): void
    {
        $this->superAdmin();

        // Aucun modèle système dans les tables du studio.
        $this->assertSame(0, ModeleCourriel::where('verrou_systeme', true)->count());
        $this->assertCount(7, config('courriels.modeles'));

        // Tentative de créer un modèle reprenant un identifiant système :
        // il reste un modèle personnalisé (le catalogue n'est pas mutable).
        Livewire::test(DesignerCourriel::class)
            ->set('code', 'code_verification')
            ->set('nom', 'Piraterie')
            ->set('categorie', 'securite')
            ->set('schema', $this->schema([
                ['type' => 'texte', 'contenu' => 'Votre code : {{code}}', 'alignement' => 'gauche', 'id' => null, 'verrouille' => false],
            ]))
            ->call('creerBrouillon')
            ->assertSet('messageOk', false);

        $this->assertSame(0, ModeleCourriel::count());
        // Le vrai OTP reste envoyé uniquement par son service (intact).
        $this->assertArrayHasKey('reemissible', config('courriels.modeles.code_verification'));
    }
}
