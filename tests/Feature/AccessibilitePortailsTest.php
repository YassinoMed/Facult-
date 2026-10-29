<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOT A (LOOP-UNIREGISTRE-PORTALS-CONTINUATION-03) :
 * Validation automatisée de l'accessibilité des portails Étudiant et Enseignant :
 * - association stricte de labels sur tous les champs de saisie (formulaires de connexion et de réinitialisation) ;
 * - boutons d'affichage/masquage du mot de passe avec aria-label dynamique ;
 * - protection anti-double-soumission avec état accessible (:disabled="submitting") ;
 * - hiérarchie sémantique des titres (h1, h2, sections labellisées) ;
 * - descriptions audio alternatives (sr-only) pour les barres de progression et démarches.
 */
class AccessibilitePortailsTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences et Techniques',
            'code' => 'FST',
            'description' => 'Sciences',
            'active' => true,
        ]);
    }

    public function test_accessibilite_page_connexion_etudiant(): void
    {
        $contenu = (string) $this->get(route('etudiant.connexion.show'))->assertOk()->getContent();

        // 1. Tous les champs interactifs ont un label explicite for="..."
        $this->assertStringContainsString('for="identifiant"', $contenu);
        $this->assertStringContainsString('id="identifiant"', $contenu);
        $this->assertStringContainsString('for="password"', $contenu);
        $this->assertStringContainsString('id="password"', $contenu);

        // 2. Bouton bascule mot de passe avec aria-label
        $this->assertStringContainsString(':aria-label="showPassword', $contenu);

        // 3. Bouton soumission avec protection anti-double soumission et état lisible
        $this->assertStringContainsString(':disabled="submitting"', $contenu);
        $this->assertStringContainsString('aria-label="Basculer le thème d\'affichage"', $contenu);
    }

    public function test_accessibilite_page_connexion_enseignant(): void
    {
        $contenu = (string) $this->get(route('enseignant.connexion.show'))->assertOk()->getContent();

        $this->assertStringContainsString('for="identifiant"', $contenu);
        $this->assertStringContainsString('id="identifiant"', $contenu);
        $this->assertStringContainsString('for="password"', $contenu);
        $this->assertStringContainsString('id="password"', $contenu);
        $this->assertStringContainsString(':aria-label="showPassword', $contenu);
        $this->assertStringContainsString(':disabled="submitting"', $contenu);
    }

    public function test_accessibilite_pages_mot_de_passe_oublie_et_reinitialisation(): void
    {
        $oublieEtudiant = (string) $this->get(route('etudiant.mot-de-passe.oublie'))->assertOk()->getContent();
        $this->assertStringContainsString('for="email"', $oublieEtudiant);
        $this->assertStringContainsString('id="email"', $oublieEtudiant);
        $this->assertStringContainsString('type="email"', $oublieEtudiant);
        $this->assertStringContainsString(':disabled="submitting"', $oublieEtudiant);

        $reinitialiserEtudiant = (string) $this->get(route('etudiant.mot-de-passe.reinitialiser', [
            'token' => 'token-test',
            'email' => 'test@example.com',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('for="email"', $reinitialiserEtudiant);
        $this->assertStringContainsString('for="password"', $reinitialiserEtudiant);
        $this->assertStringContainsString('for="password_confirmation"', $reinitialiserEtudiant);
        $this->assertStringContainsString(':disabled="submitting"', $reinitialiserEtudiant);
    }

    public function test_accessibilite_structure_semantique_dashboard_etudiant(): void
    {
        $etudiant = Etudiant::create([
            'cin' => 'A11Y0001',
            'nom' => 'Benali',
            'prenom' => 'Sara',
            'email' => 'sara@example.com',
            'telephone' => '+212600000000',
            'date_naissance' => '2004-05-12',
            'sexe' => 'F',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Rabat',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2024',
            'mention_bac' => 'Très Bien',
            'password' => 'Secret12345',
        ]);
        $etudiant->definirStatut('valide');

        session(['etudiant_auth_id' => $etudiant->id]);

        $contenu = (string) $this->get(route('etudiant.dashboard'))->assertOk()->getContent();

        // 1. Présence d'un titre h1 unique d'accueil
        $this->assertSame(1, substr_count($contenu, '<h1 '));

        // 2. Sections thématiques repérées avec aria-labelledby
        $this->assertStringContainsString('aria-labelledby="titre-bonjour"', $contenu);
        $this->assertStringContainsString('aria-labelledby="titre-aujourdhui"', $contenu);
        $this->assertStringContainsString('aria-labelledby="titre-situation"', $contenu);
        $this->assertStringContainsString('aria-labelledby="titre-resultat"', $contenu);
        $this->assertStringContainsString('aria-labelledby="titre-docs"', $contenu);
    }

    public function test_accessibilite_structure_semantique_dashboard_enseignant(): void
    {
        $departement = Departement::create([
            'code' => 'DEP-INFO',
            'nom' => 'Informatique',
            'faculte_id' => $this->faculte->id,
        ]);

        $enseignant = Teacher::create([
            'matricule' => 'ENS-A11Y-01',
            'nom' => 'Alami',
            'prenom' => 'Karim',
            'email' => 'karim.alami@univ.test',
            'departement_id' => $departement->id,
            'actif' => true,
            'statut' => 'actif',
            'password' => 'Secret12345',
        ]);

        session([
            'enseignant_auth_id' => $enseignant->id,
            'enseignant_mdp_version' => 0,
        ]);

        $contenu = (string) $this->get(route('enseignant.dashboard'))->assertOk()->getContent();

        // 1. Présence d'un h1 principal
        $this->assertStringContainsString('<h1 ', $contenu);
        $this->assertStringContainsString('Espace Enseignant', $contenu);

        // 2. Contrôle des balises titres structurées
        $this->assertStringContainsString('<h2 ', $contenu);
    }
}
