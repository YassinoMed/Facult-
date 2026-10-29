<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\RegistrationPeriod;
use App\Services\PeriodeInscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Point d'entrée accueil (`/#inscription`) vers le parcours canonique
 * `/inscription/existant` : l'accueil propose le choix AVANT toute saisie,
 * la détection (modale) et le backend restent l'unique implémentation
 * canonique — aucun formulaire ni endpoint dupliqué.
 */
class InscriptionAccueilParcoursTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculte;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences',
            'code' => 'FS',
            'description' => 'Test',
            'active' => true,
        ]);

        $annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-06-30', 'active' => true,
        ]);
        app(PeriodeInscriptionService::class)->creerNormale([
            'annee_universitaire_id' => $annee->id,
            'opened_at' => '2020-01-01 00:00', 'closed_at' => '2030-12-31 23:59',
        ], null);
    }

    private function creerEtudiant(): Etudiant
    {
        return Etudiant::create([
            'cin' => '12345678',
            'nom' => 'BEN ALI',
            'prenom' => 'Mohamed',
            'email' => 'mohamed.benali@example.com',
            'telephone' => '+212612345678',
            'date_naissance' => '2003-05-17',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L2',
            'annee_academique' => '2025-2026',
            'adresse' => '45 Avenue Hassan II',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2022',
            'mention_bac' => 'Bien',
            'password' => 'MotdepasseSecret123',
            'password_confirmation' => 'MotdepasseSecret123',
        ]);
    }

    public function test_accueil_plateforme_propose_les_deux_parcours_sans_dupliquer_le_backend(): void
    {
        // La page d'accueil de la plateforme EST le parcours existant.
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('Retrouver votre dossier', $html);
        $this->assertStringContainsString('action="'.route('inscription.existant.recherche').'"', $html);
        $this->assertStringContainsString('Changer mon adresse e-mail sans passer par la scolarité', $html);

        // LOOP-POPUP-HOME-ONLY-04 : la page d'accueil embarque le formulaire
        // officiel de nouvelle inscription (même partial `inscription._wizard`,
        // même endpoint `inscription.store`, même backend — aucune seconde
        // implémentation). Le wizard vit dans `#panneau-nouvelle`, affiché
        // sans navigation via l'état `nouvelle` de la popup d'accueil.
        $this->assertStringContainsString('id="panneau-nouvelle"', $html);
        $this->assertStringContainsString('action="'.route('inscription.store').'"', $html);
        $this->assertSame(1, substr_count($html, 'action="'.route('inscription.store').'"'));

        // La page nouvelle inscription porte le choix + le formulaire unique.
        $nouvelle = $this->get(route('inscription.create'))->assertOk()->getContent();

        // A. Reprise : BOUTON ouvrant la popup de choix (plus de navigation
        // immédiate), dont le choix A mène au parcours canonique.
        $this->assertStringContainsString('id="btn-choix-inscription"', $nouvelle);
        $this->assertStringContainsString('titre-modal-choix', $nouvelle);
        $this->assertMatchesRegularExpression('/Comment souhaitez-vous\s+vous\s+inscrire\s+\?/', $nouvelle);
        $this->assertStringContainsString('J\'ai déjà un dossier', $nouvelle);
        $this->assertStringContainsString('Je suis un nouvel étudiant', $nouvelle);
        $this->assertStringContainsString('href="'.route('inscription.existant.accueil').'"', $nouvelle);
        $this->assertStringContainsString('href="#inscription"', $nouvelle);

        // Popup masquée par défaut, jamais ouverte au chargement.
        $this->assertMatchesRegularExpression(
            '/<div x-show="modal" x-cloak[^>]*role="dialog"[^>]*aria-labelledby="\'titre-modal-\' \+ modal"/',
            $nouvelle
        );
        $this->assertStringContainsString('modal: null', $nouvelle);

        // Aucun formulaire ni écriture liés à la popup : entre le titre du
        // dialogue et son bouton Fermer, que des liens et des boutons.
        $debut = strpos($nouvelle, 'titre-modal-choix');
        $fin = strpos($nouvelle, 'Fermer', $debut);
        $this->assertNotFalse($debut);
        $this->assertNotFalse($fin);
        $segment = substr($nouvelle, $debut, $fin - $debut);
        $this->assertStringNotContainsString('<form', $segment);
        $this->assertStringContainsString('/inscription/existant', $segment);

        // B. Nouvel étudiant : ancre vers le formulaire de la page.
        $this->assertStringContainsString('Commencer mon inscription', $nouvelle);
        $this->assertStringContainsString('href="#inscription"', $nouvelle);

        // Un SEUL formulaire de création (aucun backend dupliqué) : une
        // seule action vers le store officiel.
        $this->assertSame(1, substr_count($nouvelle, 'action="'.route('inscription.store').'"'));

        // La modale de détection existe (filet post-identification) et pointe
        // elle aussi vers la route canonique (avec repli).
        $this->assertStringContainsString('titre-modal-existe', $nouvelle);
        $this->assertStringContainsString('Ouvrir le parcours de mise à jour', $nouvelle);
        $this->assertStringContainsString(route('inscription.existant.accueil'), $nouvelle);
    }

    public function test_accueil_sans_sidebar_avec_bandeau_periode(): void
    {
        // Page d'accueil publique : chrome allégé (ni sidebar desktop, ni
        // drawer mobile, ni bouton hamburger), en-tête et pied conservés.
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('Navigation principale', $html);
        $this->assertStringNotContainsString('Ouvrir le menu', $html);
        $this->assertStringNotContainsString('Menu de navigation', $html);
        $this->assertStringContainsString('Connexion', $html);
        $this->assertStringContainsString('Tous droits réservés', $html);

        // Bandeau de période (même source que le portail de création).
        $this->assertStringContainsString('État des inscriptions', $html);
        $this->assertStringContainsString('Inscriptions ouvertes', $html);
        $this->assertStringContainsString('Période :', $html);
    }

    public function test_bandeau_periode_fermee_et_sidebar_ailleurs(): void
    {
        RegistrationPeriod::query()->update(['closed_at' => now()->subDay()]);

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('Inscriptions fermées', $html);
        $this->assertStringNotContainsString('Navigation principale', $html);

        // Ciblage strict : les autres pages gardent leur sidebar.
        $nouvelle = $this->get(route('inscription.create'))->assertOk()->getContent();
        $this->assertStringContainsString('Navigation principale', $nouvelle);
    }

    public function test_declencheur_ouvre_popup_sans_navigation_directe(): void
    {
        // Régression LOOP-POPUP-FIX-02 : le déclencheur DOIT être un bouton
        // (pas un lien), et AUCUN lien direct vers le parcours existant ne
        // doit exister hors modales sur la page nouvelle inscription.
        $html = $this->get(route('inscription.create'))->assertOk()->getContent();

        // 1. Le déclencheur est un <button type="button"> câblé à l'état.
        $this->assertSame(1, preg_match(
            '/<button\b[^>]*type="button"[^>]*id="btn-choix-inscription"[^>]*@click="ouvrirModal\(\'choix\'\)"/',
            $html
        ));

        // 2. Avant la première modale (choix), aucun href direct.
        $posModale = strpos($html, 'titre-modal-choix');
        $this->assertNotFalse($posModale);
        $this->assertStringNotContainsString(
            route('inscription.existant.accueil', [], false),
            substr($html, 0, $posModale)
        );

        // 3. Exactement 3 références, toutes dans des contextes non
        // navigants : choix A de la popup, lien + repli JS de la modale
        // post-identification (serveur vérifié). Aucune navigation directe.
        $occurrences = substr_count($html, route('inscription.existant.accueil', [], false));
        $this->assertSame(3, $occurrences);
    }

    public function test_identifier_redirige_dossier_existant_sans_fuite(): void
    {
        $this->creerEtudiant();

        $reponse = $this->postJson(route('inscription.identifier'), [
            'cin' => '12345678',
            'date_naissance' => '2003-05-17',
        ]);

        $reponse->assertOk()->assertJson([
            'status' => 'EXISTING_STUDENT',
            'redirection' => route('inscription.existant.accueil'),
        ]);

        $corps = $reponse->getContent();
        // Adresse masquée uniquement : jamais l'adresse complète, ni le
        // nom, ni la date de naissance dans la réponse.
        $this->assertStringNotContainsString('mohamed.benali@example.com', $corps);
        $this->assertStringNotContainsString('BEN ALI', $corps);
        $this->assertStringNotContainsString('2003-05-17', $corps);
        $reponse->assertJsonPath('masked_email', 'moh******@example.com');
    }

    public function test_identifier_inconnu_sans_redirection(): void
    {
        $reponse = $this->postJson(route('inscription.identifier'), [
            'cin' => '00000000',
            'date_naissance' => '2000-01-01',
        ]);

        $reponse->assertOk()->assertJson(['status' => 'UNKNOWN']);
        $this->assertStringNotContainsString('redirection', $reponse->getContent());
    }

    public function test_store_refuse_doublon_sans_creer(): void
    {
        $this->creerEtudiant();

        $charge = [
            'cin' => '12345678',
            'nom' => 'Duplicata',
            'prenom' => 'Test',
            'email' => 'duplicata@example.com',
            'date_naissance' => '2003-05-17',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Rue Test',
            'ville' => 'Fes',
            'code_postal' => '30000',
            'annee_bac' => '2024',
            'password' => 'motdepasse1',
            'password_confirmation' => 'motdepasse1',
        ];

        // Répétition incluse : aucun doublon, dossier d'origine intact.
        $this->post(route('inscription.store'), $charge)->assertSessionHasErrors(['cin']);
        $this->post(route('inscription.store'), $charge)->assertSessionHasErrors(['cin']);

        $this->assertSame(1, Etudiant::count());
        $this->assertSame('BEN ALI', Etudiant::where('cin', '12345678')->sole()->nom);
    }

    public function test_bouton_email_periode_fermee_expose_un_aria_disabled_valide(): void
    {
        // Régression LOOP-13 : l'attribut était produit par une expression
        // Blade `{{ }}` → guillemets échappés (`aria-disabled="&quot;true&quot;"`),
        // valeur ARIA invalide (axe-core : aria-valid-attr-value, critical).
        RegistrationPeriod::query()->update(['closed_at' => now()->subDay()]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('&quot;true&quot;', $html);
        $this->assertStringContainsString(
            'aria-disabled="true" title="Inscriptions fermées."',
            $html,
            'Le bouton « Modifier mon adresse » doit porter un aria-disabled valide.'
        );
    }

    public function test_accueil_public_ne_monte_pas_le_popup_ia(): void
    {
        // Le popup résout un acteur connecté et répond 401 aux anonymes :
        // le monter sur une page publique ne produisait qu'une requête
        // inutile et une erreur console à chaque visite.
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('x-data="uniregistreAiPopup()"', $html);
        $this->assertStringNotContainsString('/assistant-ia/popup/initialiser', $html);
    }
}
