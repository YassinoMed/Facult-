<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Thèmes graphiques du portail étudiant.
 *
 * La V2 est le rendu par défaut de toutes les pages étudiantes connectées
 * (hors écrans d'authentification plein écran). La V1 reste disponible via
 * `?theme=v1` et le sélecteur. Ces tests vérifient le rendu serveur de
 * secours, les liens partageables, les libellés accessibles et le fait que
 * le choix du thème ne contourne jamais l'authentification.
 */
class EtudiantThemesTest extends TestCase
{
    use RefreshDatabase;

    private Etudiant $etudiant;

    protected function setUp(): void
    {
        parent::setUp();

        $faculte = Faculte::create([
            'nom' => 'Faculté des Sciences et Techniques',
            'code' => 'FST',
            'description' => 'Sciences et technologies',
            'active' => true,
        ]);

        $this->etudiant = Etudiant::create([
            'cin' => 'BK910002',
            'nom' => 'Alami',
            'prenom' => 'Mehdi',
            'email' => 'themes.etudiant@example.com',
            'telephone' => '+212600000002',
            'date_naissance' => '2004-03-15',
            'sexe' => 'M',
            'faculte_id' => $faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => '12 Rue de la Liberté',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'mention_bac' => 'Très Bien',
            'password' => 'motdepasse1',
        ]);

        $this->etudiant->forceFill(['num_inscription' => 'INS-2026-THEME-01'])->save();
        $this->etudiant->definirStatut('valide');
    }

    private function connecter(): void
    {
        $this->post(route('etudiant.connexion.login'), [
            'identifiant' => 'themes.etudiant@example.com',
            'password' => 'motdepasse1',
        ])->assertRedirect(route('etudiant.profil.show'));
    }

    /**
     * Retourne les valeurs `aria-current` de toutes les options du thème.
     * Le portail peut afficher deux sélecteurs (barre latérale desktop et
     * tiroir mobile) : on vérifie donc toutes les occurrences.
     *
     * @return list<string>
     */
    private function etatsOptions(string $html, string $theme): array
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query(sprintf('//*[@data-etudiant-theme-option="%s"]', $theme));

        if ($nodes === false || $nodes->length === 0) {
            return [];
        }

        $etats = [];

        foreach ($nodes as $node) {
            $etats[] = $node->getAttribute('aria-current');
        }

        return $etats;
    }

    private function assertThemeActif(string $html, string $theme): void
    {
        $etats = $this->etatsOptions($html, $theme);

        $this->assertNotEmpty($etats, sprintf('Aucune option de thème %s trouvée.', $theme));
        $this->assertSame(['true'], array_values(array_unique($etats)));
    }

    private function assertThemeInactif(string $html, string $theme): void
    {
        $etats = $this->etatsOptions($html, $theme);

        $this->assertNotEmpty($etats, sprintf('Aucune option de thème %s trouvée.', $theme));
        $this->assertSame([''], array_values(array_unique($etats)));
    }

    public function test_le_profil_affiche_le_selecteur_et_la_v2_par_defaut(): void
    {
        $this->connecter();

        $response = $this->get(route('etudiant.profil.show'));

        $response->assertOk();
        $response->assertSee('Thème du portail étudiant');
        $response->assertSee('V1');
        $response->assertSee('Classique');
        $response->assertSee('V2');
        $response->assertSee('Beta');
        $response->assertSee('Aperçu V2 · Beta');

        $html = $response->getContent();

        $this->assertStringContainsString('data-etudiant-theme-switch', $html);
        $this->assertStringContainsString('data-etudiant-theme-option="v1"', $html);
        $this->assertStringContainsString('data-etudiant-theme-option="v2"', $html);
        $this->assertStringContainsString('data-etudiant-theme="v2"', $html);
        $this->assertStringContainsString('data-etudiant-page-theme="v2"', $html);
        $this->assertThemeActif($html, 'v2');
        $this->assertThemeInactif($html, 'v1');

        $response->assertSee(route('etudiant.profil.show', ['theme' => 'v1']), false);
        $response->assertSee(route('etudiant.profil.show', ['theme' => 'v2']), false);
    }

    public function test_le_profil_rend_la_v1_partageable_par_requete_sans_alterer_les_donnees(): void
    {
        $this->connecter();

        $response = $this->get(route('etudiant.profil.show', ['theme' => 'v1']));

        $response->assertOk();
        $response->assertSee('Mehdi Alami');
        $response->assertSee('INS-2026-THEME-01');
        $response->assertSee('Faculté des Sciences et Techniques');

        $html = $response->getContent();

        $this->assertStringContainsString('data-etudiant-theme="v1"', $html);
        $this->assertStringContainsString('data-etudiant-page-theme="v1"', $html);
        $this->assertThemeActif($html, 'v1');
        $this->assertThemeInactif($html, 'v2');
    }

    public function test_un_theme_inconnu_retombe_sur_la_v2(): void
    {
        $this->connecter();

        $response = $this->get(route('etudiant.profil.show', ['theme' => 'inconnu']));

        $response->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString('data-etudiant-theme="v2"', $html);
        $this->assertStringContainsString('data-etudiant-page-theme="v2"', $html);
        $this->assertThemeActif($html, 'v2');
        $this->assertThemeInactif($html, 'v1');
    }

    public function test_les_autres_pages_du_portail_affichent_la_v2_et_le_selecteur_lateral(): void
    {
        $this->connecter();

        $response = $this->get(route('etudiant.dashboard'));

        $response->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString('etudiant-shell', $html);
        $this->assertStringContainsString('data-etudiant-theme="v2"', $html);
        $this->assertStringContainsString('data-etudiant-page-theme="v2"', $html);
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'data-etudiant-theme-switch'));
        $this->assertThemeActif($html, 'v2');
        $this->assertThemeInactif($html, 'v1');
    }

    public function test_toutes_les_pages_accessibles_du_portail_exposent_la_v2(): void
    {
        $this->connecter();

        $routes = [
            'etudiant.dashboard',
            'etudiant.absences.index',
            'etudiant.appel.qr',
            'etudiant.corrections.index',
            'etudiant.demandes.index',
            'etudiant.demandes.create',
            'etudiant.documents.index',
            'etudiant.dossier.show',
            'etudiant.dossier.officiels.index',
            'etudiant.examens.index',
            'etudiant.historique.index',
            'etudiant.inscriptions.index',
            'etudiant.notes.index',
            'etudiant.notifications.index',
            'etudiant.parcours',
            'etudiant.profil.show',
            'etudiant.profil.edit',
            'etudiant.recherche',
            'etudiant.rendez-vous.nouveau',
            'etudiant.ressources.index',
        ];

        foreach ($routes as $route) {
            $response = $this->get(route($route));

            $response->assertOk();
            $html = $response->getContent();

            $this->assertStringContainsString('etudiant-shell', $html, $route);
            $this->assertStringContainsString('data-etudiant-theme="v2"', $html, $route);
            $this->assertStringContainsString('data-etudiant-page-theme="v2"', $html, $route);
            $this->assertStringContainsString('data-etudiant-theme-switch', $html, $route);
        }
    }

    public function test_la_page_de_suivi_du_dossier_expose_la_v2_sans_contourner_l_acces(): void
    {
        $this->connecter();

        $response = $this->get(route('etudiant.suivi'));

        $response->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString('data-etudiant-theme="v2"', $html);
        $this->assertStringContainsString('data-etudiant-page-theme="v2"', $html);
    }

    public function test_les_ecrans_d_authentification_restent_hors_du_theme_du_portail(): void
    {
        $response = $this->get(route('etudiant.connexion.show', ['theme' => 'v2']));

        $response->assertOk();
        $response->assertDontSee('data-etudiant-theme=', false);
        $response->assertDontSee('data-etudiant-theme-switch', false);
    }

    public function test_le_choix_du_theme_ne_contourne_pas_l_authentification(): void
    {
        foreach (['v1', 'v2', 'inconnu'] as $theme) {
            $this->get(route('etudiant.profil.show', ['theme' => $theme]))
                ->assertRedirect(route('etudiant.connexion.show'));
        }
    }
}
