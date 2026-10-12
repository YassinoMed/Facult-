<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Design System (§35) : chaque composant rend un markup accessible
 * et cohérent — garde-fou contre les régressions visuelles.
 */
class DesignSystemTest extends TestCase
{
    public function test_page_header_fil_ariane_et_titre(): void
    {
        $html = Blade::render(
            '<x-page-header titre="Emplois du temps" description="Sous-titre." :fil="[\'Accueil\' => \'/\', \'Planning\' => \'#\']" />'
        );

        $this->assertStringContainsString('Emplois du temps</h1>', $html);
        $this->assertStringContainsString('aria-label="Fil d\'Ariane"', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
    }

    public function test_alert_roles_et_icones(): void
    {
        $erreur = Blade::render('<x-alert type="error" titre="Échec">Détail.</x-alert>');
        $this->assertStringContainsString('role="alert"', $erreur);
        $this->assertStringContainsString('✕', $erreur);
        $this->assertStringContainsString('<strong>Échec</strong>', $erreur);

        $succes = Blade::render('<x-alert type="success">OK.</x-alert>');
        $this->assertStringContainsString('role="status"', $succes);
        $this->assertStringContainsString('✓', $succes);
    }

    public function test_btn_variantes_et_hauteur_tactile(): void
    {
        $html = Blade::render('<x-btn variant="danger" href="/x">Supprimer</x-btn>');

        $this->assertStringContainsString('<a href="/x"', $html);
        $this->assertStringContainsString('bg-rose-700', $html);
        $this->assertStringContainsString('min-h-[44px]', $html);

        $bouton = Blade::render('<x-btn type="submit">Envoyer</x-btn>');
        $this->assertStringContainsString('<button type="submit"', $bouton);
    }

    public function test_modal_accessible(): void
    {
        $html = Blade::render(
            '<div x-data="{ ouvert: false }"><x-modal titre="Confirmer" nom="ouvert"><p>Texte.</p></x-modal></div>'
        );

        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringContainsString('aria-label="Fermer"', $html);
        $this->assertStringContainsString('x-show="ouvert"', $html);
    }

    public function test_stepper_etats_textuels(): void
    {
        $html = Blade::render(
            '<x-stepper :etapes="[[\'Fichier\', true], [\'Validation\', false]]" :courant="2" />'
        );

        $this->assertStringContainsString('aria-label="Progression"', $html);
        $this->assertStringContainsString('(terminée)', $html);
        $this->assertStringContainsString('(étape en cours)', $html);
    }

    public function test_status_badge_pastille_et_libelle(): void
    {
        $html = Blade::render('<x-status-badge statut="in_review" />');

        $this->assertStringContainsString('En cours', $html);
        $this->assertStringContainsString('bg-amber-500', $html);
    }

    public function test_empty_state_et_skeleton(): void
    {
        $vide = Blade::render('<x-empty-state message="Rien ici." />');
        $this->assertStringContainsString('role="status"', $vide);
        $this->assertStringContainsString('Rien ici.', $vide);

        $skeleton = Blade::render('<x-skeleton :lignes="2" />');
        $this->assertStringContainsString('ds-skeleton', $skeleton);
        $this->assertStringContainsString('Chargement en cours', $skeleton);
    }

    public function test_card_titre(): void
    {
        $html = Blade::render('<x-card titre="Ma carte">Contenu.</x-card>');

        $this->assertStringContainsString('<h2', $html);
        $this->assertStringContainsString('Ma carte', $html);
        $this->assertStringContainsString('Contenu.', $html);
    }

    public function test_input_label_et_erreur(): void
    {
        $html = Blade::render('<x-input id="email" name="email" label="Email universitaire" required />');

        $this->assertStringContainsString('<label for="email"', $html);
        $this->assertStringContainsString('aria-invalid="false"', $html);

        $avecErreur = Blade::render('<x-input id="email" name="email" label="Email" erreur="Adresse invalide." />');
        $this->assertStringContainsString('role="alert"', $avecErreur);
        $this->assertStringContainsString('Adresse invalide.', $avecErreur);
        $this->assertStringContainsString('aria-describedby="email-erreur"', $avecErreur);
    }

    public function test_select_options(): void
    {
        $html = Blade::render('<x-select id="niveau" name="niveau" label="Niveau" vide="— Choisir —" :options="[\'L1\' => \'Licence 1\']" />');

        $this->assertStringContainsString('<option value="">— Choisir —</option>', $html);
        $this->assertStringContainsString('value="L1"', $html);
        $this->assertStringContainsString('Licence 1</option>', $html);

        $avecValeur = Blade::render('<x-select id="niveau" name="niveau" label="Niveau" valeur="L1" :options="[\'L1\' => \'Licence 1\']" />');
        $this->assertStringContainsString('selected', $avecValeur);
    }

    public function test_textarea(): void
    {
        $html = Blade::render('<x-textarea id="motif" name="motif" label="Motif">Bonjour</x-textarea>');

        $this->assertStringContainsString('<textarea', $html);
        $this->assertStringContainsString('Bonjour</textarea>', $html);
    }
}
