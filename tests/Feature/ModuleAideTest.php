<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * LOOP-AIDE-KB-UTILISATEUR-01 — module Aide (centre d'aide utilisateur) :
 * rendu public de la KB « docs/utilisateur/ », lecture seule.
 *
 * Aucune base de données, aucun mock : le contenu réel du dépôt est rendu
 * (markdown → HTML via league/commonmark déjà vendu avec laravel/framework).
 * Couverture : accès public, liste des 6 angles, rendu réel d'une page,
 * libellés vérifiés, liste blanche de slugs (404 hors liste), contrainte de
 * segment (404 hors format), désactivation module → 404, HTML sans script brut.
 */
class ModuleAideTest extends TestCase
{
    public function test_index_public_sans_authentification_liste_les_six_angles(): void
    {
        $reponse = $this->get('/aide');

        $reponse->assertOk();
        // Texte Blade littéral (pas {{ }}) : apostrophes non échappées.
        $reponse->assertSee("Bienvenue — entrez par l'angle qui correspond à votre situation", false);
        $reponse->assertSeeInOrder([
            'Par rôle : qui êtes-vous, que pouvez-vous faire ?',
            'Guides pas-à-pas (par tâche)',
            'À la page : que puis-je faire sur l\'écran ?',
            'FAQ (questions fréquentes)',
            'Dépannage : symptôme, cause, quoi faire',
            'Vocabulaire (langage utilisateur)',
        ]);
    }

    public function test_page_rend_le_contenu_reel_de_la_kb_depannage(): void
    {
        $reponse = $this->get('/aide/depannage');

        $reponse->assertOk();
        $reponse->assertSee('REGISTRATION_PERIOD_CLOSED');
        $reponse->assertSee('Note verrouillée');
    }

    public function test_page_guides_affiche_les_actions_admin_verifiees(): void
    {
        $reponse = $this->get('/aide/guides-pas-a-pas');

        $reponse->assertOk();
        // Markdown rendu par CommonMark : apostrophes brutes (pas d'échappement HTML).
        $reponse->assertSee("Relancer l'analyse", false);
        $reponse->assertSee('Prendre en charge');
        $reponse->assertSee('Correction assistée');
    }

    public function test_page_vocabulaire_affiche_les_libelles_verifies(): void
    {
        $reponse = $this->get('/aide/vocabulaire');

        $reponse->assertOk();
        $reponse->assertSee('Correction assistée');
        $reponse->assertSee('motif obligatoire');
    }

    public function test_slug_inconnu_repond_404(): void
    {
        $this->get('/aide/inconnu-absent')->assertNotFound();
    }

    public function test_slug_reserve_repond_404_hors_liste_blanche(): void
    {
        $this->get('/aide/index')->assertNotFound();
    }

    public function test_slug_hors_contrainte_de_segment_repond_404(): void
    {
        // Majuscule + point : la contrainte `where slug` refuse le segment.
        $this->get('/aide/FICHIER.md')->assertNotFound();

        // Tentative de traversée (décodée « ../équivalent ») : refusée.
        $this->get('/aide/..%2Fequivoque')->assertNotFound();
    }

    public function test_module_desactive_repond_404(): void
    {
        config(['modules.aide.active' => false]);

        $this->get('/aide')->assertNotFound();
        $this->get('/aide/faq')->assertNotFound();
    }

    public function test_rendu_html_sans_script_ni_html_brut(): void
    {
        $reponse = $this->get('/aide/faq');

        $reponse->assertOk();
        $this->assertStringNotContainsString('<script', (string) $reponse->getContent());
        $this->assertStringNotContainsString('<iframe', (string) $reponse->getContent());
        $this->assertStringNotContainsString('<img onerror', (string) $reponse->getContent());
    }
}
