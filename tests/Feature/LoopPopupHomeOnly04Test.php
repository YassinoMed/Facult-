<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Notifications\CodeVerificationDossierExistant;
use App\Services\PeriodeInscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * LOOP-POPUP-HOME-ONLY-04 — Parcours d'inscription 100 % sur la page d'accueil.
 *
 * Point de départ/fin : `/`. Aucun clic du parcours (popup + vues intégrées)
 * ne doit provoquer de navigation complète vers `/inscription/nouvelle`,
 * `/inscription/existant` ou une autre URL applicative. Les contrôles serveur
 * (identité, code à usage unique, CSRF, throttling, autorisation) restent
 * obligatoires : la popup ne fait que changer d'état d'interface et appelle
 * les endpoints canoniques en JSON (mêmes contrôleurs/services/validations).
 */
class LoopPopupHomeOnly04Test extends TestCase
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

    private function creerEtudiant(array $attributs = []): Etudiant
    {
        $statut = $attributs['statut'] ?? 'en_attente';
        unset($attributs['statut']);

        $etudiant = Etudiant::create(array_merge([
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
        ], $attributs));
        $etudiant->definirStatut($statut);

        return $etudiant;
    }

    private function htmlAccueil(): string
    {
        return $this->get('/')->assertOk()->getContent();
    }

    /** 1. Le déclencheur depuis `/` ouvre la popup (bouton, pas un lien). */
    public function test_declencheur_ouvre_popup_depuis_accueil(): void
    {
        $html = $this->htmlAccueil();

        $this->assertSame(1, preg_match(
            '/<button\b[^>]*type="button"[^>]*id="btn-commencer-accueil"[^>]*@click="ouvrir\(\)"/',
            $html
        ));
        $this->assertStringContainsString('id="popup-parcours-accueil"', $html);
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringContainsString('aria-labelledby="titre-parcours-accueil"', $html);
        // Popup masquée par défaut, jamais ouverte au chargement.
        $this->assertMatchesRegularExpression(
            '/<div x-show="ouvert" x-cloak[^>]*id="popup-parcours-accueil"/',
            $html
        );
        $this->assertStringContainsString('x-data="parcoursAccueil()"', $html);
        $this->assertStringContainsString('x-show="ouvert"', $html);
    }

    /** 1bis. Titre accessible exact exigé par la LOOP. */
    public function test_popup_titre_exact_et_choix(): void
    {
        $html = $this->htmlAccueil();

        $this->assertStringContainsString('Comment souhaitez-vous vous inscrire ?', $html);
        $this->assertStringContainsString('Déjà inscrit', $html);
        $this->assertStringContainsString('Nouvel étudiant', $html);
        $this->assertStringContainsString('id="btn-choix-deja-inscrit"', $html);
        $this->assertStringContainsString('id="btn-choix-nouvel-etudiant"', $html);
        $this->assertStringContainsString('id="etat-choix-parcours"', $html);
    }

    /** 2. Aucune navigation complète depuis le parcours (que des états + fetch). */
    public function test_parcours_sans_navigation_complete(): void
    {
        $html = $this->htmlAccueil();

        $debut = strpos($html, 'id="popup-parcours-accueil"');
        $this->assertNotFalse($debut);
        // Fin du dialogue : le panneau nouvelle inscription (dernier état).
        $fin = strpos($html, 'id="panneau-nouvelle"');
        $this->assertNotFalse($fin);
        $dialogue = substr($html, $debut, ($fin - $debut) + 5000);

        // Aucun lien applicatif de navigation dans les états du parcours :
        // les changements d'état passent par `aller()` / `precedant()`.
        $this->assertStringNotContainsString('<a href="'.route('inscription.create').'"', $dialogue);
        $this->assertStringNotContainsString('<a href="'.route('inscription.existant.accueil').'"', $dialogue);
        $this->assertStringNotContainsString('href="/inscription/nouvelle"', $dialogue);
        $this->assertStringNotContainsString('href="/inscription/existant"', $dialogue);

        // En revanche les formulaires embarqués ciblent les endpoints
        // canoniques (POST + fetch JSON, `@submit.prevent`, pas de navigation).
        foreach ([
            route('inscription.existant.recherche'),
            route('inscription.existant.verification'),
            route('inscription.identifier'),
        ] as $endpoint) {
            $this->assertStringContainsString('action="'.$endpoint.'"', $dialogue);
        }
        $this->assertStringContainsString('@submit.prevent="rechercherDossier($el)"', $dialogue);
        $this->assertStringContainsString('@submit.prevent="verifierCode($el)"', $dialogue);
        $this->assertStringContainsString('@submit.prevent="identifierNouveau($el)"', $dialogue);
        $this->assertStringContainsString('@submit.prevent="enregistrerCoords($el)"', $dialogue);

        // Aucune navigation JS dans le composant d'accueil.
        $js = (string) file_get_contents(base_path('resources/js/app.js'));
        $segment = substr($js, (int) strpos($js, "Alpine.data('parcoursAccueil'"));
        $this->assertStringNotContainsString('window.location', $segment);
        $this->assertStringNotContainsString('location.href', $segment);
    }

    /** 3. Choix « Déjà inscrit » : formulaire dossier existant dans la même page. */
    public function test_choix_deja_inscrit_affiche_formulaire_meme_page(): void
    {
        $html = $this->htmlAccueil();

        $this->assertStringContainsString('id="etat-dossier-existant"', $html);
        $this->assertStringContainsString('id="m-ex-cin"', $html);
        $this->assertStringContainsString('id="m-ex-dob"', $html);
        $this->assertStringContainsString('name="cin"', $html);
        $this->assertStringContainsString('name="date_naissance"', $html);
        $this->assertStringContainsString('id="etat-verification-code"', $html);
        $this->assertStringContainsString('id="m-ex-code"', $html);
        $this->assertStringContainsString('id="etat-dossier-verifie"', $html);
        $this->assertStringContainsString('id="titre-dossier"', $html);
    }

    /** 4. Choix « Nouvel étudiant » : formulaire officiel embarqué, même backend. */
    public function test_choix_nouvel_etudiant_affiche_wizard_officiel_meme_page(): void
    {
        $html = $this->htmlAccueil();

        $this->assertStringContainsString('id="etat-nouvelle-inscription"', $html);
        $this->assertStringContainsString('id="m-id-cin"', $html);
        $this->assertStringContainsString('id="panneau-nouvelle"', $html);
        // Même partial, même endpoint, aucune seconde implémentation.
        $this->assertStringContainsString("@include('inscription._wizard'", $this->sourceAccueil());
        $this->assertStringContainsString('action="'.route('inscription.store').'"', $html);
        $this->assertSame(1, substr_count($html, 'action="'.route('inscription.store').'"'));
        $this->assertStringContainsString('name="idempotency_token"', $html);
    }

    /** 4bis. Panneau de la popup hors Lenis : scroll natif + page mère gelée. */
    public function test_popup_gele_le_scroll_de_la_page_et_conserve_son_panneau_defilant(): void
    {
        $html = $this->htmlAccueil();

        // Le panneau reste le conteneur défilant, exclu du moteur inertiel,
        // et la molette ne se propage pas à la page en fin de panneau.
        $this->assertMatchesRegularExpression('/data-panneau-defilant[^>]*data-lenis-prevent/', $html);
        $this->assertMatchesRegularExpression('/max-h-\[92vh\][^"]*overscroll-contain/', $html);

        // Le composant suspend/reprend le socle cinématique à l'ouverture/fermeture.
        $js = (string) file_get_contents(base_path('resources/js/app.js'));
        $segment = substr($js, (int) strpos($js, "Alpine.data('parcoursAccueil'"));
        $this->assertStringContainsString("new CustomEvent('mouvement:suspendre')", $segment);
        $this->assertStringContainsString("new CustomEvent('mouvement:reprendre')", $segment);

        // Lenis écoute ces événements et s'arrête/reprend réellement.
        $motion = (string) file_get_contents(base_path('resources/js/motion-cinematic.js'));
        $this->assertStringContainsString("addEventListener('mouvement:suspendre'", $motion);
        $this->assertStringContainsString("addEventListener('mouvement:reprendre'", $motion);
        $this->assertStringContainsString('lenis?.stop()', $motion);
        $this->assertStringContainsString('lenis?.start()', $motion);
    }

    private function sourceAccueil(): string
    {
        return (string) file_get_contents(base_path('resources/views/inscription/existant/accueil.blade.php'));
    }

    /** 5. Vérification du code sans navigation (enveloppe JSON canonique). */
    public function test_verification_code_sans_navigation(): void
    {
        Notification::fake();
        $etudiant = $this->creerEtudiant();

        $recherche = $this->postJson(route('inscription.existant.recherche'), [
            'cin' => '12345678',
            'date_naissance' => '2003-05-17',
        ])->assertOk();

        $recherche->assertJsonPath('ok', true);
        $recherche->assertJsonPath('etape', 'code');

        $code = Notification::sent($etudiant, CodeVerificationDossierExistant::class)->first()->code;

        // Code erroné : pas de navigation, pas de dossier, message neutre.
        $this->postJson(route('inscription.existant.verification'), ['code' => '000000'])
            ->assertOk()
            ->assertJsonPath('ok', false);

        // Accès dossier toujours refusé avant vérification réussie.
        $this->getJson(route('inscription.existant.donnees'))->assertForbidden();

        // Code valide : étape dossier, toujours en JSON (aucune redirection).
        $this->postJson(route('inscription.existant.verification'), ['code' => $code])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->getJson(route('inscription.existant.donnees'))
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    /** 6. Erreurs de validation affichées dans la page (zones dédiées). */
    public function test_erreurs_validation_affichees_dans_page(): void
    {
        $html = $this->htmlAccueil();

        $this->assertStringContainsString('x-text="message"', $html);
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('erreurs.code', $html);
        $this->assertStringContainsString('emailErreurs', $html);
        $this->assertStringContainsString('identErreurs', $html);
        $this->assertStringContainsString('embarque-erreurs', $html);
    }

    /** 7. Dossier inaccessible avant vérification (GET + POST). */
    public function test_dossier_inaccessible_avant_verification(): void
    {
        $this->creerEtudiant();

        $this->get(route('inscription.existant.formulaire'))
            ->assertRedirect(route('inscription.existant.accueil'));

        $this->getJson(route('inscription.existant.donnees'))
            ->assertForbidden()
            ->assertJsonPath('ok', false);

        $this->postJson(route('inscription.existant-store'), [
            'telephone' => '+212600000000',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'adresse' => 'Rue test',
            'confirmation' => '1',
        ])->assertForbidden();
    }

    /** 8. Protections conservées : email masqué, CSRF + throttle. */
    public function test_protections_conservees_email_masque_et_throttle(): void
    {
        Notification::fake();
        $this->creerEtudiant();

        $existant = $this->postJson(route('inscription.existant.recherche'), [
            'cin' => '12345678',
            'date_naissance' => '2003-05-17',
        ])->assertOk()->getContent();

        // Contrôle bloquant demandé : un couple CIN + date qui ne
        // correspond à aucun dossier reste sur l'identification (422) et
        // ne reçoit aucun code. L'email MASQUÉ n'est renvoyé que si le
        // dossier existe : oracle d'existence partiel assumé pour le popup
        // d'accueil ; l'adresse complète et le nom restent absents.
        $absent = $this->postJson(route('inscription.existant.recherche'), [
            'cin' => '87654321',
            'date_naissance' => '2000-01-01',
        ])->assertStatus(422)->getContent();

        $this->assertJson($existant);
        $this->assertJson($absent);
        $this->assertSame('code', json_decode($existant, true)['etape'] ?? null);
        $this->assertTrue(json_decode($existant, true)['identite_correspond'] ?? false);
        $this->assertSame('identification', json_decode($absent, true)['etape'] ?? null);
        $this->assertFalse(json_decode($absent, true)['identite_correspond'] ?? true);
        $this->assertSame(
            'moh******@example.com',
            json_decode($existant, true)['email_masque'] ?? null
        );
        $this->assertArrayNotHasKey('email_masque', json_decode($absent, true));
        $this->assertArrayHasKey('identite', json_decode($absent, true)['errors'] ?? []);
        $this->assertStringNotContainsString('mohamed.benali@example.com', $existant.$absent);
        $this->assertStringNotContainsString('BEN ALI', $existant.$absent);

        // L'étape « code » du popup affiche l'adresse d'envoi masquée.
        $vue = $this->sourceAccueil();
        $this->assertStringContainsString('x-show="emailDestinataire"', $vue);
        $this->assertStringContainsString('x-text="emailDestinataire"', $vue);
        $this->assertStringContainsString('Code envoyé à', $vue);
        $this->assertStringContainsString(
            "this.emailDestinataire = corps.email_masque || '';",
            (string) file_get_contents(base_path('resources/js/app.js'))
        );

        // CSRF conservé sur les mutations web (jeton exigé hors tests).
        $this->assertStringContainsString('X-CSRF-TOKEN', (string) file_get_contents(base_path('resources/js/app.js')));
        $this->assertStringContainsString('@csrf', $this->sourceAccueil());
    }

    /** 8 bis. Dossier sans email : aucun masque trompeur, aucun code. */
    public function test_dossier_sans_email_ne_revele_pas_de_masque(): void
    {
        Notification::fake();
        $this->creerEtudiant(['email' => '']);

        $reponse = $this->postJson(route('inscription.existant.recherche'), [
            'cin' => '12345678',
            'date_naissance' => '2003-05-17',
        ])->assertOk();

        $this->assertNull($reponse->json('email_masque'));
        Notification::assertNothingSent();
    }

    /** 9. Doubles clics / doubles soumissions neutralisés. */
    public function test_doubles_soumissions_neutralisees(): void
    {
        $html = $this->htmlAccueil();
        $js = (string) file_get_contents(base_path('resources/js/app.js'));

        // Garde client : boutons désactivés pendant l'envoi, drapeau unique.
        $this->assertStringContainsString(':disabled="envoi"', $html);
        $this->assertStringContainsString('if (this.submitting) return;', $js);
        $this->assertStringContainsString('this.envoi = true', $js);

        // Garde serveur dossier existant : autorisation à usage unique.
        Notification::fake();
        $etudiant = $this->creerEtudiant();

        $this->post(route('inscription.existant.recherche'), [
            'cin' => '12345678',
            'date_naissance' => '2003-05-17',
        ])->assertRedirect();

        $code = Notification::sent($etudiant, CodeVerificationDossierExistant::class)->first()->code;
        $this->post(route('inscription.existant.verification'), ['code' => $code])->assertRedirect();

        $payload = [
            'telephone' => '+212600000000',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'adresse' => 'Rue test',
            'confirmation' => '1',
        ];
        $this->post(route('inscription.existant-store'), $payload)->assertRedirect(route('inscription.existant.confirmation'));

        // Rejeu de la même requête : autorisation déjà consommée → refus.
        $this->post(route('inscription.existant-store'), $payload)
            ->assertRedirect(route('inscription.existant.accueil'));
    }

    /** 10. Boutons retour et fermeture (états, focus, scroll). */
    public function test_retour_et_fermeture(): void
    {
        $html = $this->htmlAccueil();
        $js = (string) file_get_contents(base_path('resources/js/app.js'));

        $this->assertStringContainsString('@click="precedant()"', $html);
        $this->assertStringContainsString('@click="fermer()"', $html);
        $this->assertStringContainsString('@keydown.escape.window="fermer()"', $html);
        $this->assertStringContainsString('id="btn-fermer-parcours-accueil"', $html);
        $this->assertStringContainsString("document.body.style.overflow = 'hidden'", $js);
        $this->assertStringContainsString("document.body.style.overflow = ''", $js);
        $this->assertStringContainsString('btn-commencer-accueil', $js);
        $this->assertStringContainsString('precedant()', $js);
    }

    /** 11. Clavier, mobile, accessibilité. */
    public function test_accessibilite_clavier_mobile(): void
    {
        $html = $this->htmlAccueil();

        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringContainsString('tabindex="-1"', $html);
        $this->assertStringContainsString('@keydown.tab', $html);
        $this->assertStringContainsString('min-h-[44px]', $html);
        $this->assertStringContainsString('min-h-[48px]', $html);
        $this->assertStringContainsString('overflow-y-auto', $html);
        $this->assertStringContainsString('max-w-3xl', $html);
        $this->assertStringContainsString('name="viewport"', $html);
    }

    /** 12. Accès directs aux anciennes routes toujours fonctionnels. */
    public function test_acces_directs_anciennes_routes(): void
    {
        $this->get(route('inscription.create'))->assertOk();
        $accueil = $this->get(route('inscription.existant.accueil'))->assertOk()->getContent();
        $racine = $this->get('/')->assertOk()->getContent();

        // Même gabarit (jeton d'idempotence unique par rendu : on neutralise
        // les valeurs volatiles avant comparaison structurelle).
        $normaliser = static fn (string $h): string => preg_replace(
            ['/name="idempotency_token" value="[^"]+"/', '/name="_token" value="[^"]+"/'],
            ['name="idempotency_token" value="TOKEN"', 'name="_token" value="TOKEN"'],
            $h
        );
        $this->assertSame($normaliser($racine), $normaliser($accueil));
    }

    /** 13. Aucune redirection applicative involontaire sur les endpoints JSON. */
    public function test_aucune_redirection_sur_endpoints_json(): void
    {
        $this->creerEtudiant();

        // wantsJson → JSON, jamais de 302 vers une autre page.
        $this->postJson(route('inscription.existant.recherche'), [
            'cin' => '12345678',
            'date_naissance' => '2003-05-17',
        ])->assertOk();

        $this->postJson(route('inscription.identifier'), [
            'cin' => '12345678',
            'date_naissance' => '2003-05-17',
        ])->assertOk()->assertJsonPath('status', 'EXISTING_STUDENT');

        $this->getJson(route('inscription.periode-etat'))->assertOk();
    }

    /** 14. Récupération email inaccessible toujours disponible sans oracle. */
    public function test_recuperation_email_sans_oracle(): void
    {
        $html = $this->htmlAccueil();

        $this->assertStringContainsString("Je n'ai plus accès à mon ancienne adresse", $html);
        $this->assertStringContainsString('Récupérer l\'accès à votre dossier', $html);

        // Parcours autonome : réponse strictement neutre dans tous les cas.
        $neutreExistant = $this->postJson(route('inscription.existant.autonome'), [
            'cin' => '12345678',
            'date_naissance' => '2003-05-17',
            'ancien_email' => 'mohamed.benali@example.com',
            'password' => 'MotdepasseSecret123',
            'nouvel_email' => 'nouveau@example.com',
            'nouvel_email_confirmation' => 'nouveau@example.com',
        ])->assertOk()->assertJsonPath('ok', true)->getContent();

        $neutreAbsent = $this->postJson(route('inscription.existant.autonome'), [
            'cin' => '00000000',
            'date_naissance' => '2000-01-01',
            'ancien_email' => 'inconnu@example.com',
            'password' => 'MotdepasseSecret123',
            'nouvel_email' => 'nouveau@example.com',
            'nouvel_email_confirmation' => 'nouveau@example.com',
        ])->assertOk()->assertJsonPath('ok', true)->getContent();

        $this->assertSame(
            json_decode($neutreExistant, true)['etape'] ?? null,
            json_decode($neutreAbsent, true)['etape'] ?? null
        );
    }
}
