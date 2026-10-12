<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Services\PeriodeInscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP 17 — Accessibilité, mobile et connexion instable.
 *
 * Vérifie :
 * - Formulaire utilisable sans souris : labels for/id, aria-describedby, focus visible.
 * - Responsive mobile : viewport configuré, grilles 1-col mobile, cibles tactiles min 44px.
 * - Erreurs compréhensibles et associées aux champs avec aria-invalid.
 * - Réseau lent/instable géré sans fausse confirmation (anti-double envoi, garde hors-ligne).
 * - Pas de cache public de données sensibles (SW app-shell strict, brouillon sans mot de passe).
 */
class InscriptionAccessibiliteMobileTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences',
            'code' => 'FS',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        app(PeriodeInscriptionService::class)->creerNormale([
            'annee_universitaire_id' => $this->annee->id,
            'opened_at' => '2020-01-01 00:00',
            'closed_at' => '2030-12-31 23:59',
        ], null);
    }

    /**
     * Critère 1 : Navigation au clavier et accessibilité sémantique (labels, ARIA, rôles).
     */
    public function test_formulaire_accessible_au_clavier_avec_labels_et_aria(): void
    {
        $response = $this->get(route('inscription.create'));
        $response->assertOk();

        $html = $response->getContent();

        // 1. Présence des labels for associés aux inputs id obligatoires
        $champs = ['cin', 'nom', 'prenom', 'email', 'telephone', 'ville', 'code_postal', 'adresse', 'faculte_id', 'annee_academique', 'password', 'confirmation'];
        foreach ($champs as $champ) {
            $this->assertStringContainsString("id=\"{$champ}\"", $html, "Le champ '{$champ}' doit avoir un attribut id.");
            $this->assertStringContainsString("for=\"{$champ}\"", $html, "Le champ '{$champ}' doit avoir un label for correspondant.");
        }

        // 2. Présence des attributs ARIA structurants
        $this->assertStringContainsString('role="progressbar"', $html);
        $this->assertStringContainsString('role="radiogroup"', $html);
        $this->assertStringContainsString('aria-describedby', $html);
        $this->assertStringContainsString('role="status"', $html);

        // 3. Boutons d'étapes actionnables au clavier avec attribut step
        $this->assertStringContainsString(':aria-current="etape === 1 ? \'step\' : null"', $html);
    }

    /**
     * Critère 2 : Affichage adapté sur mobile et petit écran.
     */
    public function test_affichage_adapte_sur_mobile_et_petit_ecran(): void
    {
        $response = $this->get(route('inscription.create'));
        $response->assertOk();

        $html = $response->getContent();

        // 1. Meta viewport configuré pour mobile
        $this->assertStringContainsString('<meta name="viewport"', $html);
        $this->assertStringContainsString('width=device-width', $html);

        // 2. Classes responsive Tailwind (1 col sur mobile, 2 col sur sm)
        $this->assertStringContainsString('grid-cols-1 sm:grid-cols-2', $html);
        $this->assertStringContainsString('max-w-5xl', $html);

        // 3. Cibles tactiles confortables conformes aux recommandations tactiles (>= 44px / 48px)
        $this->assertStringContainsString('min-h-[48px]', $html);
        $this->assertStringContainsString('min-h-[52px]', $html);
    }

    /**
     * Critère 3 : Erreurs compréhensibles et associées aux champs avec aria-invalid.
     */
    public function test_erreurs_associées_aux_champs_avec_aria_invalid(): void
    {
        // Soumission avec erreurs de validation (sans mot de passe, sans confirmation, format email invalide)
        $response = $this->from(route('inscription.create'))
            ->post(route('inscription.store'), [
                'cin' => '12345678',
                'nom' => 'Test',
                'prenom' => 'Etudiant',
                'date_naissance' => '2004-01-01',
                'sexe' => 'M',
                'email' => 'format-invalide-sans-arobase',
                'telephone' => '12345678',
                'adresse' => 'Rue de test',
                'ville' => 'Tunis',
                'code_postal' => '1000',
                'faculte_id' => $this->faculte->id,
                'niveau' => 'L1',
                'annee_bac' => '2023',
                'mention_bac' => 'bien',
                'annee_academique' => '2026-2027',
                // password manquant, confirmation manquante
            ]);

        $vueErreurs = $this->followRedirects($response);
        $vueErreurs->assertOk();

        $htmlErreurs = $vueErreurs->getContent();

        // aria-invalid="true" présent sur les champs erronés
        $this->assertStringContainsString('aria-invalid="true"', $htmlErreurs);

        // ID d'erreur associé dans aria-describedby
        $this->assertStringContainsString('erreur-email', $htmlErreurs);
        $this->assertStringContainsString('erreur-password', $htmlErreurs);

        // Message lisible avec icône d'avertissement
        $this->assertStringContainsString('erreur-champ', $htmlErreurs);
    }

    /**
     * Critère 4 : Réseau instable, protection anti-double envoi et garde hors ligne.
     */
    public function test_reseau_instable_anti_double_envoi_et_garde_hors_ligne(): void
    {
        $response = $this->get(route('inscription.create'));
        $response->assertOk();

        $html = $response->getContent();

        // 1. Jeton d'idempotence contre la soumission multiple
        $this->assertStringContainsString('name="idempotency_token"', $html);

        // 2. Bouton désactivé pendant la soumission (anti-double clic)
        $this->assertStringContainsString(':disabled="submitting', $html);
        $this->assertStringContainsString('Enregistrement sécurisé en cours…', $html);

        // 3. Détection hors ligne et bannière explicite
        $this->assertStringContainsString('x-show="horsLigne"', $html);
        $this->assertStringContainsString('Mode hors-ligne actif', $html);
        $this->assertStringContainsString('⚡ Connexion réseau requise', $html);

        // 4. Aucun succès fantôme si soumission invalide
        $soumissionInvalide = $this->post(route('inscription.store'), []);
        $soumissionInvalide->assertSessionHasErrors();
        $this->assertNull(session('success'));
        $this->assertNull(session('num_inscription'));
    }

    /**
     * Critère 5 : Pas de cache public de données sensibles.
     */
    public function test_pas_de_cache_public_de_donnees_sensibles(): void
    {
        // 1. Service Worker exclut toute requête d'inscription et de données sensibles
        $sw = file_get_contents(public_path('sw.js'));
        $this->assertStringContainsString("if (request.method !== 'GET') return false;", $sw);
        $this->assertStringContainsString("if (url.pathname.startsWith('/api/')) return false;", $sw);
        $this->assertStringContainsString('build', $sw);

        // 2. Page de repli /hors-ligne publique ne contient aucune donnée sensible
        $horsLigne = $this->get(route('hors-ligne'));
        $horsLigne->assertOk();
        $contenuHorsLigne = $horsLigne->getContent();
        $this->assertStringContainsString('hors ligne', strtolower($contenuHorsLigne));
        $this->assertStringContainsString('connexion perdue', strtolower($contenuHorsLigne));
        // Aucune donnée d'étudiant, aucun formulaire avec secrets
        $this->assertStringNotContainsString('password', $contenuHorsLigne);
        $this->assertStringNotContainsString('num_inscription', $contenuHorsLigne);

        // 3. Le script de brouillon local exclut les mots de passe et documents
        $appJs = file_get_contents(resource_path('js/app.js'));
        preg_match('/champsBrouillon:\s*\[(.*?)\]/s', $appJs, $matches);
        $this->assertNotEmpty($matches);
        $this->assertStringNotContainsString('password', $matches[1]);
        $this->assertStringNotContainsString('piece', $matches[1]);
    }
}
