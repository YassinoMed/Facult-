<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\RegistrationPeriod;
use App\Notifications\AlerteChangementEmail;
use App\Notifications\CodeConfirmationChangementEmail;
use App\Notifications\CodeConfirmationNouvelleAdresse;
use App\Notifications\CodeVerificationDossierExistant;
use App\Notifications\ConfirmationChangementEmail;
use App\Notifications\ConfirmationMiseAJourDossierExistant;
use App\Services\DossierExistantService;
use App\Services\PeriodeInscriptionService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Popup « Modifier mon adresse email » du parcours public sans login
 * (dossier étudiant existant) — TRIPLE preuve.
 *
 * L'email servant d'identifiant de connexion à l'espace étudiant, la
 * nouvelle adresse (candidate) n'est JAMAIS appliquée avant sa propre
 * vérification :
 * 1. jeton temporaire de session (issu du parcours vérifié par OTP) ;
 * 2. code à usage unique envoyé à l'adresse DÉJÀ VÉRIFIÉE du dossier
 *    (l'adresse actuellement enregistrée — le titulaire prouve qu'il
 *    contrôle encore l'adresse en cours) ;
 * 3. code à usage unique envoyé à la NOUVELLE adresse candidate (prouve
 *    sa délivrabilité — une faute de frappe ne peut plus détourner ni
 *    perdre le compte).
 *
 * L'ancienne adresse reste en vigueur jusqu'à l'étape 3 ; la candidate
 * vit uniquement dans un état temporaire côté serveur (cache hashé +
 * lié, jamais en base). La soumission des coordonnées ignore toujours
 * l'email.
 */
class DossierExistantEmailTest extends TestCase
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

    /** Parcours jusqu'au formulaire (jeton temporaire en session). */
    private function acceder(Etudiant $etudiant): void
    {
        Notification::fake();

        $this->post(route('inscription.existant.recherche'), [
            'cin' => $etudiant->cin,
            'date_naissance' => $etudiant->date_naissance->toDateString(),
        ])->assertRedirect(route('inscription.existant.accueil'));

        $code = Notification::sent($etudiant, CodeVerificationDossierExistant::class)->first()->code;

        $this->post(route('inscription.existant.verification'), ['code' => $code])
            ->assertRedirect(route('inscription.existant.formulaire'));
    }

    private function demander(string $nouvel, ?string $confirmation = null)
    {
        return $this->post(route('inscription.existant.email'), [
            'nouvel_email' => $nouvel,
            'nouvel_email_confirmation' => $confirmation ?? $nouvel,
        ]);
    }

    private function confirmerAncienne(string $code)
    {
        return $this->post(route('inscription.existant.email.confirmer'), ['code' => $code]);
    }

    private function confirmerCandidate(string $code)
    {
        return $this->post(route('inscription.existant.email.confirmer-nouvelle'), ['code_nouvelle' => $code]);
    }

    /** Code de première preuve, envoyé à l'adresse DÉJÀ VÉRIFIÉE du dossier (null si aucun). */
    private function codeAncienneEnvoye(Etudiant $etudiant): ?CodeConfirmationChangementEmail
    {
        return Notification::sent($etudiant, CodeConfirmationChangementEmail::class)->first();
    }

    /** Code de seconde preuve, envoyé à la candidate (null si aucun). */
    private function codeCandidateEnvoye(string $nouvel): ?CodeConfirmationNouvelleAdresse
    {
        return Notification::sent(new AnonymousNotifiable, CodeConfirmationNouvelleAdresse::class,
            fn ($n, $c, $d) => $d->routeNotificationFor('mail') === $nouvel)->first();
    }

    private function service(): DossierExistantService
    {
        return app(DossierExistantService::class);
    }

    /* ---------------------------------------------------------------
     | PARCOURS NOMINAL — TRIPLE PREUVE
     * -------------------------------------------------------------- */

    public function test_parcours_nominal_triple_preuve_puis_poursuite_du_parcours(): void
    {
        $etudiant = $this->creerEtudiant();
        $this->acceder($etudiant);

        // Popup accessible après vérification (bouton + modale, titre exigé).
        $this->get(route('inscription.existant.formulaire'))
            ->assertOk()
            ->assertSee('Modifier mon adresse email')
            ->assertSee('titre-email', false)
            ->assertSee('moh******@example.com', false); // ancienne adresse masquée uniquement

        $nouvel = 'nouveau.adresse@example.com';
        $this->demander($nouvel)
            ->assertRedirect(route('inscription.existant.formulaire'))
            ->assertSessionHas('dossier_existant.email_etape', 'code');

        // Code 1 parti UNIQUEMENT vers l'adresse DÉJÀ VÉRIFIÉE du dossier
        // — la candidate ne reçoit RIEN à ce stade.
        $this->assertNotNull($this->codeAncienneEnvoye($etudiant));
        Notification::assertNotSentTo(new AnonymousNotifiable, CodeConfirmationChangementEmail::class);
        $this->assertNull($this->codeCandidateEnvoye($nouvel));
        Notification::assertNotSentTo($etudiant, AlerteChangementEmail::class);
        $this->assertSame('mohamed.benali@example.com', $etudiant->fresh()->email);

        // Première preuve : RIEN n'est persisté, un second code part vers
        // la candidate, l'ancienne adresse reste en vigueur.
        $code1 = $this->codeAncienneEnvoye($etudiant)->code;

        $this->confirmerAncienne($code1)
            ->assertRedirect(route('inscription.existant.formulaire'))
            ->assertSessionHas('dossier_existant.email_etape', 'code-nouvelle')
            ->assertSessionMissing('dossier_existant.email_succes');

        $this->assertSame('mohamed.benali@example.com', $etudiant->fresh()->email);
        $this->assertNotNull($this->codeCandidateEnvoye($nouvel));
        $this->assertDatabaseMissing('historique_academique', [
            'etudiant_id' => $etudiant->id,
            'action' => 'changement_email_public',
        ]);

        // Seconde preuve : le changement devient effectif ICI seulement.
        $code2 = $this->codeCandidateEnvoye($nouvel)->code;

        $this->confirmerCandidate($code2)
            ->assertRedirect(route('inscription.existant.formulaire'))
            ->assertSessionHas('dossier_existant.email_succes', 'nou******@example.com');

        // Un seul dossier, seul l'email a changé.
        $etudiant->refresh();
        $this->assertSame($nouvel, $etudiant->email);
        $this->assertSame(1, Etudiant::count());
        $this->assertSame('BEN ALI', $etudiant->nom);
        $this->assertSame('en_attente', $etudiant->statut);

        // Audit masqué : jamais les adresses complètes.
        $entree = HistoriqueAcademique::where('etudiant_id', $etudiant->id)
            ->where('action', 'changement_email_public')->firstOrFail();
        $this->assertSame('moh******@example.com', json_decode((string) $entree->ancienne_valeur, true)['email']);
        $this->assertSame('nou******@example.com', json_decode((string) $entree->nouvelle_valeur, true)['email']);
        $this->assertStringNotContainsString($nouvel, (string) $entree->nouvelle_valeur);
        $this->assertStringNotContainsString('mohamed.benali@example.com', (string) $entree->ancienne_valeur);

        // Alerte à l'ancienne adresse + confirmation à la nouvelle (post-commit).
        $alerte = Notification::sent(new AnonymousNotifiable, AlerteChangementEmail::class,
            fn ($n, $c, $d) => $d->routeNotificationFor('mail') === 'mohamed.benali@example.com')->first();
        $this->assertNotNull($alerte);
        $confirmation = Notification::sent(new AnonymousNotifiable, ConfirmationChangementEmail::class,
            fn ($n, $c, $d) => $d->routeNotificationFor('mail') === $nouvel)->first();
        $this->assertNotNull($confirmation);

        // Le jeton principal survit : la mise à jour des coordonnées se
        // poursuit (6 requêtes POST au total, seaux isolés : aucun 429) et
        // sa confirmation part vers la NOUVELLE adresse.
        $this->post(route('inscription.existant-store'), [
            'telephone' => '+212611111111',
            'adresse' => '2 Rue Continue',
            'ville' => 'Rabat',
            'code_postal' => '10100',
            'confirmation' => '1',
        ])->assertRedirect(route('inscription.existant.confirmation'));

        Notification::assertSentTo($etudiant->fresh(), ConfirmationMiseAJourDossierExistant::class, 1);
    }

    /* ---------------------------------------------------------------
     | ACCÈS REFUSÉ SANS PREUVE
     * -------------------------------------------------------------- */

    public function test_appel_direct_sans_autorisation_est_refuse(): void
    {
        $etudiant = $this->creerEtudiant();
        Notification::fake();

        $this->post(route('inscription.existant.email'), [
            'nouvel_email' => 'pirate@evil.example',
            'nouvel_email_confirmation' => 'pirate@evil.example',
        ])->assertRedirect(route('inscription.existant.accueil'))->assertSessionHas('statut');

        $this->post(route('inscription.existant.email.confirmer'), ['code' => '123456'])
            ->assertRedirect(route('inscription.existant.accueil'))->assertSessionHas('statut');

        $this->post(route('inscription.existant.email.confirmer-nouvelle'), ['code_nouvelle' => '123456'])
            ->assertRedirect(route('inscription.existant.accueil'))->assertSessionHas('statut');

        $this->assertSame('mohamed.benali@example.com', $etudiant->fresh()->email);
        Notification::assertNothingSent();
    }

    public function test_recherche_incorrecte_ne_donne_acces_a_rien(): void
    {
        $this->creerEtudiant();
        Notification::fake();

        $this->post(route('inscription.existant.recherche'), [
            'cin' => '12345678', 'date_naissance' => '1990-01-01',
        ])->assertRedirect(route('inscription.existant.accueil'));

        $this->post(route('inscription.existant.recherche'), [
            'cin' => '99999999', 'date_naissance' => '2003-05-17',
        ])->assertRedirect(route('inscription.existant.accueil'));

        Notification::assertNothingSent();

        $this->post(route('inscription.existant.email'), [
            'nouvel_email' => 'x@y.example', 'nouvel_email_confirmation' => 'x@y.example',
        ])->assertRedirect(route('inscription.existant.accueil'));
    }

    /* ---------------------------------------------------------------
     | VALIDATION DE LA DEMANDE
     * -------------------------------------------------------------- */

    public function test_confirmation_differente_et_format_invalide_sont_refuses(): void
    {
        $etudiant = $this->creerEtudiant();
        $this->acceder($etudiant);

        $this->demander('nouveau@example.com', 'autre@example.com')
            ->assertSessionHasErrors('nouvel_email');

        $this->post(route('inscription.existant.email'), [
            'nouvel_email' => 'pas-un-email',
            'nouvel_email_confirmation' => 'pas-un-email',
        ])->assertSessionHasErrors('nouvel_email');

        // Formulaire principal conservé (modale rouverte côté serveur).
        $this->get(route('inscription.existant.formulaire'))
            ->assertOk()
            ->assertSee('45 Avenue Hassan II');

        $this->assertTrue(Notification::sent(new AnonymousNotifiable, CodeConfirmationChangementEmail::class)->isEmpty());
        $this->assertTrue(Notification::sent(new AnonymousNotifiable, CodeConfirmationNouvelleAdresse::class)->isEmpty());
        $this->assertSame('mohamed.benali@example.com', $etudiant->fresh()->email);
    }

    public function test_email_deja_utilise_par_un_autre_dossier_est_refuse(): void
    {
        $etudiant = $this->creerEtudiant();
        $this->creerEtudiant([
            'cin' => '87654321', 'email' => 'Prise@Example.com',
            'date_naissance' => '2001-02-02', 'nom' => 'AUTRE', 'prenom' => 'Personne',
        ]);
        $this->acceder($etudiant);

        // Insensible à la casse : « prise@example.com » === « Prise@Example.com ».
        $this->demander('prise@example.com')
            ->assertRedirect(route('inscription.existant.formulaire'))
            ->assertSessionHasErrors('nouvel_email');

        $this->assertSame('mohamed.benali@example.com', $etudiant->fresh()->email);
        Notification::assertNotSentTo($etudiant, CodeConfirmationChangementEmail::class);
    }

    public function test_email_identique_a_l_actuel_est_refuse(): void
    {
        $etudiant = $this->creerEtudiant();
        $this->acceder($etudiant);

        $this->demander('Mohamed.Benali@Example.com')
            ->assertSessionHasErrors('nouvel_email');

        Notification::assertNotSentTo($etudiant, CodeConfirmationChangementEmail::class);
    }

    public function test_renvoi_de_code_respecte_le_delai_minimum(): void
    {
        $etudiant = $this->creerEtudiant();
        $this->acceder($etudiant);

        $this->demander('nouveau@example.com')->assertSessionMissing('errors');

        $this->demander('autre@example.com')->assertSessionHasErrors('nouvel_email');

        Notification::assertSentToTimes($etudiant, CodeConfirmationChangementEmail::class, 1);
    }

    /* ---------------------------------------------------------------
     | PREMIÈRE PREUVE : EXPIRATION, USAGE UNIQUE, ÉPUISEMENT
     * -------------------------------------------------------------- */

    public function test_code_invalide_expire_epuise_et_rejoue_sont_refuses(): void
    {
        $etudiant = $this->creerEtudiant();
        $this->acceder($etudiant);
        $nouvel = 'nouveau@example.com';
        $this->demander($nouvel);
        $bonCode = $this->codeAncienneEnvoye($etudiant)->code;

        // Invalide.
        $this->confirmerAncienne('000000')
            ->assertRedirect(route('inscription.existant.formulaire'))
            ->assertSessionHasErrors('code');
        $this->assertSame('mohamed.benali@example.com', $etudiant->fresh()->email);

        // Épuisement : 5 essais invalident définitivement le code.
        for ($i = 0; $i < DossierExistantService::MAX_ESSAIS_CODE_EMAIL - 1; $i++) {
            $this->confirmerAncienne('000000');
        }
        $this->confirmerAncienne($bonCode)
            ->assertSessionHasErrors('code');
        $this->assertSame('mohamed.benali@example.com', $etudiant->fresh()->email);

        // Aucun second code, aucune alerte ni confirmation envoyée.
        $this->assertNull($this->codeCandidateEnvoye($nouvel));
        $this->assertTrue(Notification::sent(new AnonymousNotifiable, AlerteChangementEmail::class)->isEmpty());
        $this->assertTrue(Notification::sent(new AnonymousNotifiable, ConfirmationChangementEmail::class)->isEmpty());
    }

    public function test_code_expire_est_refuse(): void
    {
        $etudiant = $this->creerEtudiant();
        Notification::fake();
        $nouvel = 'nouveau@example.com';
        $this->service()->demanderChangementEmail($etudiant, $nouvel);
        $code = $this->codeAncienneEnvoye($etudiant)->code;

        $this->travel(31)->minutes();

        $this->assertNull($this->service()->verifierCodeAncienneAdresse($etudiant, $code));
        $this->assertSame('mohamed.benali@example.com', $etudiant->fresh()->email);
    }

    public function test_expiration_du_jeton_principal_bloque_la_confirmation(): void
    {
        $etudiant = $this->creerEtudiant();
        $this->acceder($etudiant);
        $nouvel = 'nouveau@example.com';
        $this->demander($nouvel);
        $code = $this->codeAncienneEnvoye($etudiant)->code;

        // Le jeton principal (15 min) expire avant les codes email (30 min) :
        // chaque confirmation exige une nouvelle vérification (borne effective).
        $this->travel(16)->minutes();

        $this->confirmerAncienne($code)
            ->assertRedirect(route('inscription.existant.accueil'))
            ->assertSessionHas('statut');
        $this->confirmerCandidate($code)
            ->assertRedirect(route('inscription.existant.accueil'))
            ->assertSessionHas('statut');
        $this->assertSame('mohamed.benali@example.com', $etudiant->fresh()->email);
    }

    public function test_code_a_usage_unique_meme_en_cas_concurrent(): void
    {
        $etudiant = $this->creerEtudiant();
        $this->acceder($etudiant);
        $nouvel = 'nouveau@example.com';
        $this->demander($nouvel);
        $code = $this->codeAncienneEnvoye($etudiant)->code;

        // Premier flux consomme le code (usage unique atomique) : la
        // candidate est retournée, RIEN n'est persisté pour autant.
        $premier = $this->service()->verifierCodeAncienneAdresse($etudiant, $code);
        $this->assertSame($nouvel, $premier['nouvel_email']);
        $this->assertNotEmpty($premier['ancien_email_hash']);
        $this->assertSame('mohamed.benali@example.com', $etudiant->fresh()->email);

        // Second flux (requête rejouée) : code déjà consommé, aucun second
        // code émis.
        $this->confirmerAncienne($code)
            ->assertSessionHasErrors('code');
        $this->assertNull($this->codeCandidateEnvoye($nouvel));

        $this->assertSame('mohamed.benali@example.com', $etudiant->fresh()->email);
        Notification::assertSentOnDemandTimes(ConfirmationChangementEmail::class, 0);
    }

    public function test_deux_confirmations_concurrentes_un_seul_flux_obtient_le_code(): void
    {
        $etudiant = $this->creerEtudiant();
        $this->acceder($etudiant);
        $nouvel = 'nouveau@example.com';
        $this->demander($nouvel);
        $code = $this->codeAncienneEnvoye($etudiant)->code;

        // Simulation de deux flux concurrents lisant le même code : la
        // consumption atomique (pull) garantit qu'un seul obtient la
        // candidate — le second échoue sans écriture ni notification.
        $premier = $this->service()->verifierCodeAncienneAdresse($etudiant, $code);
        $second = $this->service()->verifierCodeAncienneAdresse($etudiant, $code);

        $this->assertSame($nouvel, $premier['nouvel_email']);
        $this->assertNull($second);
        $this->assertSame('mohamed.benali@example.com', $etudiant->fresh()->email);
        $this->assertSame(1, Etudiant::count());

        // Le parcours se poursuit normalement avec le jeton principal.
        $this->post(route('inscription.existant-store'), [
            'adresse' => '7 Avenue Concurrente',
            'ville' => 'Rabat',
            'code_postal' => '10300',
            'confirmation' => '1',
        ])->assertRedirect(route('inscription.existant.confirmation'));
    }

    /* ---------------------------------------------------------------
     | SECONDE PREUVE (CANDIDATE) : EXPIRATION, USAGE UNIQUE, ÉPUISEMENT
     * -------------------------------------------------------------- */

    /** Émet le second code via le parcours HTTP (première preuve validée). */
    private function emettreSecondCode(Etudiant $etudiant, string $nouvel): string
    {
        $this->demander($nouvel);
        $code1 = $this->codeAncienneEnvoye($etudiant)->code;
        $this->confirmerAncienne($code1)
            ->assertSessionHas('dossier_existant.email_etape', 'code-nouvelle');

        return $this->codeCandidateEnvoye($nouvel)->code;
    }

    public function test_code_candidate_invalide_epuise_et_rejoue_sont_refuses(): void
    {
        $etudiant = $this->creerEtudiant();
        $this->acceder($etudiant);
        $nouvel = 'nouveau@example.com';
        $code2 = $this->emettreSecondCode($etudiant, $nouvel);

        // Invalide : ancienne adresse conservée, candidate réutilisable.
        $this->confirmerCandidate('000000')
            ->assertRedirect(route('inscription.existant.formulaire'))
            ->assertSessionHasErrors('code_nouvelle');
        $this->assertSame('mohamed.benali@example.com', $etudiant->fresh()->email);

        // Épuisement : 5 essais invalident définitivement le code.
        for ($i = 0; $i < DossierExistantService::MAX_ESSAIS_CODE_EMAIL_NOUVELLE - 1; $i++) {
            $this->confirmerCandidate('000000');
        }
        $this->confirmerCandidate($code2)
            ->assertSessionHasErrors('code_nouvelle');
        $this->assertSame('mohamed.benali@example.com', $etudiant->fresh()->email);

        // Aucune alerte ni confirmation envoyée.
        $this->assertTrue(Notification::sent(new AnonymousNotifiable, AlerteChangementEmail::class)->isEmpty());
        $this->assertTrue(Notification::sent(new AnonymousNotifiable, ConfirmationChangementEmail::class)->isEmpty());
    }

    public function test_code_candidate_expire_est_refuse(): void
    {
        $etudiant = $this->creerEtudiant();
        Notification::fake();
        $nouvel = 'nouveau@example.com';
        $this->service()->demanderChangementEmail($etudiant, $nouvel);
        $code1 = $this->codeAncienneEnvoye($etudiant)->code;
        $candidate = $this->service()->verifierCodeAncienneAdresse($etudiant, $code1);
        $this->service()->emettreCodeNouvelleAdresse($etudiant, $candidate['nouvel_email'], $candidate['ancien_email_hash']);
        $code2 = $this->codeCandidateEnvoye($nouvel)->code;

        $this->travel(31)->minutes();

        $this->assertNull($this->service()->confirmerNouvelleAdresse($etudiant, $code2));
        $this->assertSame('mohamed.benali@example.com', $etudiant->fresh()->email);
    }

    public function test_code_candidate_a_usage_unique_deux_flux_un_seul_applique(): void
    {
        $etudiant = $this->creerEtudiant();
        $this->acceder($etudiant);
        $nouvel = 'nouveau@example.com';
        $code2 = $this->emettreSecondCode($etudiant, $nouvel);

        // Premier flux applique le changement (usage unique atomique).
        $premier = $this->service()->confirmerNouvelleAdresse($etudiant, $code2);
        $this->assertNotNull($premier);
        $this->assertSame($nouvel, $etudiant->fresh()->email);

        // Second flux (requête rejouée) : code déjà consommé — ni écriture
        // ni notification en double (l'entrée n'existe plus).
        $this->assertNull($this->service()->candidatureMasqueeEnAttente($etudiant));
        $this->confirmerCandidate($code2)
            ->assertSessionHasErrors('code_nouvelle');

        $this->assertSame($nouvel, $etudiant->fresh()->email);
        $this->assertSame(1, Etudiant::count());
    }

    public function test_tant_que_la_candidate_n_est_pas_confirmee_l_ancienne_reste_en_vigueur(): void
    {
        $etudiant = $this->creerEtudiant();
        $this->acceder($etudiant);
        $nouvel = 'nouveau@example.com';
        $code2 = $this->emettreSecondCode($etudiant, $nouvel);

        // L'ancienne adresse reste l'identifiant de connexion effectif ;
        // la candidate, non confirmée, ne permet PAS de se connecter.
        $this->post(route('etudiant.connexion.login'), [
            'identifiant' => 'mohamed.benali@example.com',
            'password' => 'MotdepasseSecret123',
        ])->assertRedirect(route('etudiant.profil.show'));

        $this->post(route('etudiant.connexion.login'), [
            'identifiant' => $nouvel,
            'password' => 'MotdepasseSecret123',
        ])->assertSessionHasErrors('identifiant');

        // Après confirmation, la bascule est réelle et définitive.
        $this->confirmerCandidate($code2)
            ->assertSessionHas('dossier_existant.email_succes');

        $this->assertSame($nouvel, $etudiant->fresh()->email);

        $this->post(route('etudiant.connexion.login'), [
            'identifiant' => $nouvel,
            'password' => 'MotdepasseSecret123',
        ])->assertRedirect(route('etudiant.profil.show'));
    }

    public function test_code_ancienne_adresse_refuse_pour_valider_la_nouvelle(): void
    {
        // Cross-canal : la VALEUR du code 1 (ancienne adresse) ne valide
        // JAMAIS la candidate (invariant 6).
        $etudiant = $this->creerEtudiant();
        $this->acceder($etudiant);
        $nouvel = 'nouveau@example.com';
        $this->demander($nouvel);
        $code1 = $this->codeAncienneEnvoye($etudiant)->code;
        $this->confirmerAncienne($code1)
            ->assertSessionHas('dossier_existant.email_etape', 'code-nouvelle');
        $code2 = $this->codeCandidateEnvoye($nouvel)->code;
        $this->assertNotSame($code1, $code2);

        // La valeur du code 1 soumise comme code 2 : refusée, sans consommer
        // le bon code (l'adresse reste inchangée).
        $this->post(route('inscription.existant.email.confirmer-nouvelle'), ['code_nouvelle' => $code1])
            ->assertSessionHasErrors('code_nouvelle');
        $this->assertSame('mohamed.benali@example.com', $etudiant->fresh()->email);

        // Le bon code 2 reste utilisable et mène au succès.
        $this->confirmerCandidate($code2)->assertSessionHas('dossier_existant.email_succes');
        $this->assertSame($nouvel, $etudiant->fresh()->email);
    }

    public function test_code_d_un_autre_dossier_est_refuse(): void
    {
        $cible = $this->creerEtudiant();
        $autre = $this->creerEtudiant([
            'cin' => '87654321', 'email' => 'autre@example.com',
            'date_naissance' => '2001-02-02', 'nom' => 'AUTRE', 'prenom' => 'Personne',
        ]);
        $this->acceder($cible);
        $nouvelCible = 'cible.nouveau@example.com';
        $this->demander($nouvelCible);
        $code1Cible = $this->codeAncienneEnvoye($cible)->code;

        // Session de l'AUTRE dossier : le code de la cible n'y vaut rien.
        // (Les travels lèvent les cooldowns de renvoi — horloge Carbon —
        // pour rejouer des parcours complets dans le même test.)
        $this->travel(61)->seconds();
        $this->acceder($autre);
        $this->post(route('inscription.existant.email.confirmer'), ['code' => $code1Cible])
            ->assertSessionHasErrors('code');

        // Retour côté cible : une nouvelle demande y émet un code valable.
        $this->travel(61)->seconds();
        $this->acceder($cible);
        $this->demander($nouvelCible)->assertSessionMissing('errors');
        $this->confirmerAncienne($this->codeAncienneEnvoye($cible)->code)
            ->assertSessionHas('dossier_existant.email_etape', 'code-nouvelle');
        $this->assertSame('mohamed.benali@example.com', $cible->fresh()->email);
        $this->assertSame('autre@example.com', $autre->fresh()->email);
    }

    public function test_seconde_demande_remplace_la_premiere_sans_ambiguite(): void
    {
        // Deux demandes actives : la seconde remplace la première (pas de
        // coexistence silencieuse — invariant 7) ; l'ancien code est mort.
        $etudiant = $this->creerEtudiant();
        $this->acceder($etudiant);
        $this->demander('premier@example.com');
        $code1a = $this->codeAncienneEnvoye($etudiant)->code;

        $this->travel(61)->seconds();

        $this->demander('second@example.com')
            ->assertRedirect(route('inscription.existant.formulaire'))
            ->assertSessionMissing('errors');
        // `sent()` accumule les deux envois : le second est le DERNIER.
        $code1b = Notification::sent($etudiant, CodeConfirmationChangementEmail::class)->last()->code;
        $this->assertNotSame($code1a, $code1b);

        // L'ancien code est définitivement invalide…
        $this->confirmerAncienne($code1a)->assertSessionHasErrors('code');
        $this->assertSame('mohamed.benali@example.com', $etudiant->fresh()->email);

        // …le nouveau mène au succès, vers la SECONDE adresse uniquement.
        $this->confirmerAncienne($code1b)
            ->assertSessionHas('dossier_existant.email_etape', 'code-nouvelle');
        $code2 = $this->codeCandidateEnvoye('second@example.com')->code;
        $this->confirmerCandidate($code2)->assertSessionHas('dossier_existant.email_succes');
        $this->assertSame('second@example.com', $etudiant->fresh()->email);
        $this->assertNull($this->codeCandidateEnvoye('premier@example.com'));
    }

    public function test_adresse_source_modifiee_entre_temps_refuse_partout(): void
    {
        // Invariant 8 : si l'adresse source change après la demande
        // (intervention concurrente), la preuve initiale est caduque.
        $etudiant = $this->creerEtudiant();
        $this->acceder($etudiant);
        $this->demander('nouveau@example.com');
        $code1 = $this->codeAncienneEnvoye($etudiant)->code;

        // Changement concurrent côté base (ex. scolarité).
        $etudiant->update(['email' => 'intervention.scolarite@example.com']);

        // L'émission du code 2 est refusée (retour saisie, erreur explicite).
        $this->confirmerAncienne($code1)
            ->assertRedirect(route('inscription.existant.formulaire'))
            ->assertSessionHas('dossier_existant.email_etape', 'saisie')
            ->assertSessionHasErrors('nouvel_email');
        $this->assertNull($this->codeCandidateEnvoye('nouveau@example.com'));
        $this->assertSame('intervention.scolarite@example.com', $etudiant->fresh()->email);

        // Et à l'application : même garde sous verrou (chemin direct,
        // cooldown levé par travel — horloge Carbon).
        $etudiant->update(['email' => 'mohamed.benali@example.com']);
        Notification::fake();
        $this->travel(61)->seconds();
        $this->service()->demanderChangementEmail($etudiant, 'nouveau@example.com');
        $code1b = $this->codeAncienneEnvoye($etudiant)->code;
        $preuve = $this->service()->verifierCodeAncienneAdresse($etudiant, $code1b);
        $this->service()->emettreCodeNouvelleAdresse($etudiant, $preuve['nouvel_email'], $preuve['ancien_email_hash']);
        $code2 = $this->codeCandidateEnvoye('nouveau@example.com')->code;

        $etudiant->update(['email' => 'intervention.scolarite@example.com']);

        try {
            $this->service()->confirmerNouvelleAdresse($etudiant, $code2);
            $this->fail('Le changement aurait dû être refusé (adresse source modifiée).');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('scolarité', $e->getMessage());
        }
        $this->assertSame('intervention.scolarite@example.com', $etudiant->fresh()->email);
        $this->assertDatabaseMissing('historique_academique', [
            'etudiant_id' => $etudiant->id, 'action' => 'changement_email_public',
        ]);
    }

    public function test_sans_adresse_deja_verifiee_le_changement_est_refuse_parcours_scolarite(): void
    {
        // Dossier sans adresse exploitable (saisie administrative incomplète)
        // : fail-closed — AUCUN contournement, orientation scolarité.
        // (Un tel dossier ne peut d'ailleurs pas passer la vérification
        // d'identité du parcours : le code d'entrée n'est pas envoyable.)
        $etudiant = $this->creerEtudiant();
        $etudiant->update(['email' => '']);

        Notification::fake();

        try {
            $this->service()->demanderChangementEmail($etudiant, 'nouveau@example.com');
            $this->fail('La demande aurait dû être refusée (fail-closed).');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('scolarité', $e->getMessage());
        }

        Notification::assertNothingSent();
        $this->assertSame('', $etudiant->fresh()->email);
        $this->assertNull($this->service()->nouvelEmailMasqueEnAttente($etudiant));
    }

    public function test_aide_perte_acces_visible_sans_oracle_ni_pii(): void
    {
        $this->creerEtudiant();

        // Visible SANS session ni parcours préalable (c'est le public qui
        // ne reçoit aucun code qui en a besoin).
        $accueil = $this->get(route('inscription.existant.accueil'))->getContent();
        $this->assertStringContainsString('Je n\'ai plus accès à mon ancienne adresse', $accueil);
        $this->assertStringContainsString('titre-aide-acces', $accueil);
        $this->assertStringContainsString('role="dialog"', $accueil);
        $this->assertStringContainsString('@keydown.escape.window', $accueil);
        $this->assertStringContainsString('Fermer', $accueil);

        // Procédure institutionnelle : connexion CIN, demande en ligne,
        // scolarité — et interdiction explicite du contournement.
        $this->assertStringContainsString(route('etudiant.connexion.show'), $accueil);
        $this->assertStringContainsString('aucune modification d\'adresse email n\'est effectuée automatiquement', $accueil);
        $this->assertStringContainsString(config('inscriptions.contact_scolarite.email'), $accueil);

        // Aucune donnée du dossier, aucun oracle : ni email, ni adresse,
        // ni nom, ni CIN en valeur (le placeholder statique n'est pas une donnée).
        // (La carte autonome contient ses propres champs `nouvel_email` :
        // c'est la popup post-OTP qui doit rester absente — voir test dédié.)
        $this->assertStringNotContainsString('mohamed.benali@example.com', $accueil);
        $this->assertStringNotContainsString('45 Avenue Hassan II', $accueil);
        $this->assertStringNotContainsString('BEN ALI', $accueil);
        $this->assertStringNotContainsString('value="12345678"', $accueil);
        $this->assertStringNotContainsString('titre-email', $accueil);

        // Identique après une recherche infructueuse : le bloc d'aide ne
        // varie pas selon l'existence d'un dossier (anti-énumération).
        $this->post(route('inscription.existant.recherche'), [
            'cin' => '99999999', 'date_naissance' => '2000-01-01',
        ])->assertRedirect(route('inscription.existant.accueil'));

        $apres = $this->get(route('inscription.existant.accueil'))->getContent();
        $this->assertStringContainsString('Je n\'ai plus accès à mon ancienne adresse', $apres);
        $this->assertStringContainsString('Récupérer l\'accès à votre dossier', $apres);
    }

    public function test_dialogue_confirmation_autonome_avant_envoi(): void
    {
        // LOOP 1 : confirmation pré-envoi avec les 3 choix, masquée par
        // défaut, sans oracle (aucune adresse enregistrée — ni en clair ni
        // masquée — avant preuve) et sans effet serveur à l'ouverture.
        $this->creerEtudiant();
        $html = $this->get(route('inscription.existant.accueil'))->getContent();

        $this->assertStringContainsString('titre-confirme-autonome', $html);
        $this->assertStringContainsString('Confirmer votre demande de changement d\'adresse e-mail', $html);
        $this->assertStringContainsString('Continuer sans l\'ancienne adresse', $html);
        $this->assertStringContainsString('Vérifier via mon ancienne adresse', $html);
        $this->assertStringContainsString('x-ref="autonomeForm"', $html);
        // Soumission sans navigation (fetch) : aucune soumission native
        // depuis le dialogue.
        $this->assertStringContainsString('@click="envoyerDemande()"', $html);
        $this->assertStringNotContainsString('requestSubmit()', $html);

        // Masqué par défaut (x-show + x-cloak sur le conteneur du dialogue).
        $this->assertMatchesRegularExpression(
            '/<div x-show="confirme" x-cloak[^>]*role="dialog"[^>]*aria-labelledby="titre-confirme-autonome"/',
            $html
        );

        // Retour focus au déclencheur à la fermeture (Échap et Annuler).
        $this->assertStringContainsString('document.getElementById(\'autonome_envoyer\')', $html);

        // Aucune donnée du dossier dans le dialogue (ni email masqué servi
        // côté serveur : l'étape est pré-preuve).
        $this->assertStringNotContainsString('moh******@example.com', $html);
        $this->assertStringNotContainsString('mohamed.benali@example.com', $html);

        // L'ouverture seule n'émet rien (aucun POST effectué ici).
        Notification::fake();
        $this->assertTrue(Notification::sent(new AnonymousNotifiable, CodeConfirmationNouvelleAdresse::class)->isEmpty());
    }

    public function test_la_popup_n_existe_que_sur_le_formulaire_post_verification(): void
    {
        $this->creerEtudiant();

        // LOOP-POPUP-HOME-ONLY-04 : l'accueil embarque les états vérifiés
        // (dossier + email triple preuve) SANS navigation, dans
        // `#etat-dossier-verifie` uniquement — mêmes endpoints/gardes que le
        // formulaire canonique, données chargées UNIQUEMENT après vérification
        // via `/inscription/existant/donnees` (aucune PII pré-chargée).
        $accueil = $this->get(route('inscription.existant.accueil'))->getContent();
        $this->assertStringNotContainsString('Modifier mon adresse email', $accueil);
        $this->assertStringNotContainsString('titre-email', $accueil);
        $this->assertStringNotContainsString('emailPopup', $accueil);

        // Le marqueur `code_nouvelle` (3e preuve, adresse candidate) vit
        // exclusivement dans l'état vérifié embarqué — jamais avant preuve.
        $posVerifie = strpos($accueil, 'id="etat-dossier-verifie"');
        $this->assertNotFalse($posVerifie);
        $this->assertStringNotContainsString('code_nouvelle', substr($accueil, 0, $posVerifie));
        $this->assertStringContainsString('code_nouvelle', substr($accueil, $posVerifie));
        // Aucune donnée du dossier pré-chargée dans le HTML initial.
        $this->assertStringNotContainsString('mohamed.benali@example.com', $accueil);
        $this->assertStringNotContainsString('BEN ALI', $accueil);

        // Mais il oriente vers elle (texte statique neutre, affiché que le
        // dossier existe ou non : aucun oracle d'existence) : sans cette
        // mention, un utilisateur qui ne reçoit pas le code ignore que la
        // mise à jour d'email se fait après la vérification (signalement
        // réel « la popup n'existe pas » §12.8).
        $this->assertStringContainsString('mettre à jour votre adresse email', $accueil);
    }

    /**
     * Non-régression markup : la modale doit être rendue COMPLÈTE —
     * directives Alpine (sans elles, la popup ne s'ouvre jamais en
     * navigateur), accessibilité et contrat §2 (2 champs par étape,
     * Enregistrer/Annuler, chargement + anti double-clic, étapes code et
     * code-nouvelle, renvoi, récupération scolarité).
     *
     * Note : les balises @vite ne sont PAS assertées — le TestCase du
     * projet sert volontairement des tags vides en test (`withoutVite`,
     * AC-00c) ; la présence du bundle est prouvée par le manifeste de
     * build (`public/build/manifest.json` → `app-*.js` incluant Alpine
     * + `Livewire.start()`), jamais par un rendu de test.
     */
    public function test_le_markup_de_la_popup_est_rendu_complet_et_accessible(): void
    {
        $etudiant = $this->creerEtudiant();
        $this->acceder($etudiant);

        $html = $this->get(route('inscription.existant.formulaire'))->getContent();

        // État Alpine partagé bouton ↔ modale (sans x-data, ouverture impossible).
        $this->assertStringContainsString('x-data="{ emailPopup', $html);

        // Modale masquée par défaut, fermable au clavier (Échap).
        $this->assertStringContainsString('x-show="emailPopup.ouvert"', $html);
        $this->assertStringContainsString('x-cloak', $html);
        $this->assertStringContainsString('@keydown.escape.window', $html);

        // Accessibilité : dialogue modale titrée.
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringContainsString('aria-labelledby="titre-email"', $html);

        // Contrat §2 : étapes saisie + code + code-nouvelle, actions POST
        // dédiées, boutons, état de chargement + prévention des doubles
        // clics.
        $this->assertStringContainsString('action="'.route('inscription.existant.email').'"', $html);
        $this->assertStringContainsString('action="'.route('inscription.existant.email.confirmer').'"', $html);
        $this->assertStringContainsString('action="'.route('inscription.existant.email.confirmer-nouvelle').'"', $html);
        $this->assertStringContainsString('name="nouvel_email"', $html);
        $this->assertStringContainsString('name="nouvel_email_confirmation"', $html);
        $this->assertStringContainsString('name="code"', $html);
        $this->assertStringContainsString('name="code_nouvelle"', $html);
        $this->assertStringContainsString('autocomplete="one-time-code"', $html);
        $this->assertStringContainsString('Enregistrer', $html);
        $this->assertStringContainsString('Vérifier ce code', $html);
        $this->assertStringContainsString('Confirmer le changement', $html);
        $this->assertStringContainsString('Annuler', $html);
        $this->assertStringContainsString(':disabled="emailPopup.chargement"', $html);
        $this->assertStringContainsString('Demander un nouveau code', $html);
        $this->assertStringContainsString("etape === 'code-nouvelle'", $html);

        // Accessibilité des erreurs : les messages @error (role="alert")
        // n'existent que si une erreur est en session — on en déclenche
        // une réelle (confirmation différente) puis on recharge SANS
        // toucher à la session entre-temps (le flash ne vit qu'une
        // requête, comme pour le navigateur qui suit la redirection) :
        // la modale réouverte à l'étape saisie affiche l'erreur annoncée.
        $this->demander('nouveau@example.com', 'different@example.com');

        $htmlErreur = $this->get(route('inscription.existant.formulaire'))->getContent();
        $this->assertStringContainsString('ouvert: true', $htmlErreur); // modale réouverte
        $this->assertStringContainsString('role="alert"', $htmlErreur);
        $this->assertStringContainsString('La confirmation ne correspond pas', $htmlErreur);

        // Erreur sur le second code : la modale rouvre la BONNE étape
        // (code-nouvelle), avec l'erreur annoncée.
        $etudiant2 = $this->creerEtudiant([
            'cin' => '87654321', 'email' => 'second@example.com',
            'date_naissance' => '2001-02-02',
        ]);
        $this->acceder($etudiant2);
        $this->emettreSecondCode($etudiant2, 'candidat@example.com');
        $this->confirmerCandidate('abc');

        $htmlErreur2 = $this->get(route('inscription.existant.formulaire'))->getContent();
        $this->assertStringContainsString("etape: 'code-nouvelle'", $htmlErreur2);
        $this->assertStringContainsString('role="alert"', $htmlErreur2);
    }

    /**
     * Preuve de RENDU (pas seulement de source) : après vérification OTP,
     * le HTML réellement servi contient le bouton, SANS attribut de
     * masquage, avec la classe primaire violette configurée et le câblage
     * d'ouverture de la popup. La matrice des statuts prouve que la
     * branche conditionnelle est atteinte (en_attente, valide) ou non
     * (rejete) — pas une branche morte.
     */
    public function test_bouton_rendu_visible_et_ouvrant_la_popup_apres_verification(): void
    {
        $etudiant = $this->creerEtudiant();
        $this->acceder($etudiant);

        $html = $this->get(route('inscription.existant.formulaire'))->getContent();

        // Le libellé exact est DANS le HTML rendu.
        $this->assertStringContainsString('✉️ Modifier mon adresse email', $html);

        // Extraire la balise bouton correspondante (ouverture non-greedy ET
        // sans traverser une balise fermante : les attributs Alpine
        // contiennent des `>` — `[^>]*` s'arrêterait trop tôt — et la page
        // contient d'autres <button> avant celui-ci — `(.*?)` irait trop
        // loin).
        $trouve = preg_match('/<button\b((?:(?!<\/button>).)*?)>\s*✉️ Modifier mon adresse email\s*<\/button>/us', $html, $correspondances);
        $this->assertSame(1, $trouve, 'Bouton introuvable dans le HTML rendu.');
        $attributs = $correspondances[1];

        // Aucun masquage : ni HTML, ni Alpine, ni CSS inline, ni utilitaire.
        $this->assertDoesNotMatchRegularExpression('/\shidden(\s|=|>|\/)/', $attributs);
        $this->assertStringNotContainsString('x-show', $attributs);
        $this->assertStringNotContainsString('x-cloak', $attributs);
        $this->assertStringNotContainsString('display:none', str_replace(' ', '', $attributs));
        $this->assertStringNotContainsString('visibility:hidden', str_replace(' ', '', $attributs));
        $this->assertStringNotContainsString('opacity-0', $attributs);
        $this->assertDoesNotMatchRegularExpression('/\binvisible(\s|")/', $attributs);

        // Classe primaire violette effectivement configurée (@theme +
        // bundle build contenant bg-primary-800) et contraste (texte blanc).
        $this->assertStringContainsString('bg-primary-800', $attributs);
        $this->assertStringContainsString('text-white', $attributs);

        // Câblage : le clic ouvre la popup à l'étape saisie.
        $this->assertStringContainsString('emailPopup.ouvert = true', $attributs);

        // Clavier : bouton natif focusable (aucun tabindex négatif).
        $this->assertStringNotContainsString('tabindex="-1"', $attributs);

        // Mobile : pleine largeur sous le breakpoint sm.
        $this->assertStringContainsString('w-full', $attributs);

        // Matrice des statuts : la branche @if ($emailModifiable) est
        // atteinte pour en_attente ET valide, jamais pour rejete.
        $valide = $this->creerEtudiant([
            'cin' => '11111112', 'email' => 'matrice.valide@example.com',
            'date_naissance' => '2001-03-03', 'statut' => 'valide',
        ]);
        $this->acceder($valide);
        $this->assertStringContainsString(
            '✉️ Modifier mon adresse email',
            $this->get(route('inscription.existant.formulaire'))->getContent()
        );

        $rejete = $this->creerEtudiant([
            'cin' => '22222223', 'email' => 'matrice.rejete@example.com',
            'date_naissance' => '2002-04-04', 'statut' => 'rejete',
        ]);
        $this->acceder($rejete);
        $this->assertStringNotContainsString(
            '✉️ Modifier mon adresse email',
            $this->get(route('inscription.existant.formulaire'))->getContent()
        );
    }

    public function test_bouton_corriger_adresse_revient_a_la_saisie_sans_effet_serveur(): void
    {
        $etudiant = $this->creerEtudiant();
        $this->acceder($etudiant);

        $html = $this->get(route('inscription.existant.formulaire'))->getContent();

        // Un bouton « Corriger » par étape code (2 occurrences), jamais
        // dans l'étape saisie (une seule paire Enregistrer/Annuler là-bas).
        $this->assertSame(2, substr_count($html, 'Corriger la nouvelle adresse'));

        // Navigation cliente vers la saisie + focus, sans soumission
        // (type="button") : aucun effet serveur, les codes émis restent
        // valables, la popup reste ouverte.
        $trouves = preg_match_all(
            '/<button\b((?:(?!<\/button>).)*?)>\s*Corriger la nouvelle adresse\s*<\/button>/us',
            $html, $correspondances
        );
        $this->assertSame(2, $trouves);
        foreach ($correspondances[1] as $attributs) {
            $this->assertStringContainsString('type="button"', $attributs);
            $this->assertStringContainsString("emailPopup.etape = 'saisie'", $attributs);
            $this->assertStringContainsString("getElementById('nouvel_email')", $attributs);
            $this->assertStringNotContainsString('emailPopup.ouvert = false', $attributs);
        }

        // Aucune requête serveur n'a été émise par ce bouton : aucun code,
        // aucune modification.
        $this->assertTrue(Notification::sent(new AnonymousNotifiable, CodeConfirmationChangementEmail::class)->isEmpty());
        $this->assertSame('mohamed.benali@example.com', $etudiant->fresh()->email);
    }

    /* ---------------------------------------------------------------
     | ANTI-IDOR ET WHITELIST
     * -------------------------------------------------------------- */

    public function test_identifiant_dossier_et_autres_champs_sont_ignores(): void
    {
        $cible = $this->creerEtudiant();
        $autre = $this->creerEtudiant([
            'cin' => '87654321', 'email' => 'autre@example.com',
            'date_naissance' => '2001-02-02', 'nom' => 'AUTRE', 'prenom' => 'Personne',
        ]);
        $this->acceder($cible);

        $nouvel = 'cible.nouveau@example.com';
        $this->post(route('inscription.existant.email'), [
            'etudiant_id' => $autre->id, // tentative IDOR
            'id' => $autre->id,
            'nom' => 'PIRATE',
            'cin' => '99999999',
            'statut' => 'valide',
            'nouvel_email' => $nouvel,
            'nouvel_email_confirmation' => $nouvel,
        ])->assertRedirect(route('inscription.existant.formulaire'));

        $this->confirmerAncienne($this->codeAncienneEnvoye($cible)->code)
            ->assertSessionHas('dossier_existant.email_etape', 'code-nouvelle');

        $this->post(route('inscription.existant.email.confirmer-nouvelle'), [
            'code_nouvelle' => $this->codeCandidateEnvoye($nouvel)->code,
            'etudiant_id' => $autre->id,
        ])->assertRedirect(route('inscription.existant.formulaire'));

        // Seul l'email de la cible change ; tout le reste est intact.
        $this->assertSame($nouvel, $cible->fresh()->email);
        $this->assertSame('autre@example.com', $autre->fresh()->email);
        $this->assertSame('BEN ALI', $cible->fresh()->nom);
        $this->assertSame('12345678', $cible->fresh()->cin);
        $this->assertSame('en_attente', $cible->fresh()->statut);
        $this->assertSame('AUTRE', $autre->fresh()->nom);
    }

    public function test_dossier_valide_peut_modifier_son_email_avec_triple_preuve(): void
    {
        // Mission §4 : la garde porte sur le statut courant, sans
        // interdire l'email d'un dossier déjà validé (coordonnée de
        // contact, pas une donnée académique). La triple preuve
        // (jeton + code vers l'adresse enregistrée + code vers la
        // candidate) reste obligatoire.
        $etudiant = $this->creerEtudiant([
            'cin' => '11111111',
            'email' => 'valide@example.com',
            'statut' => 'valide',
        ]);
        $this->acceder($etudiant);

        // Popup PROPOSÉE sur un dossier validé.
        $this->get(route('inscription.existant.formulaire'))
            ->assertOk()
            ->assertSee('Modifier mon adresse email')
            // Les coordonnées restent non modifiables (message dédié).
            ->assertSee('déjà été validé')
            ->assertDontSee('Soumettre ma mise à jour');

        $nouvel = 'valide.nouveau@example.com';
        $this->demander($nouvel)
            ->assertRedirect(route('inscription.existant.formulaire'))
            ->assertSessionMissing('errors');

        // Après la première preuve : RIEN n'est encore appliqué.
        $this->confirmerAncienne($this->codeAncienneEnvoye($etudiant)->code)
            ->assertSessionHas('dossier_existant.email_etape', 'code-nouvelle')
            ->assertSessionMissing('dossier_existant.email_succes');
        $this->assertSame('valide@example.com', $etudiant->fresh()->email);

        $this->confirmerCandidate($this->codeCandidateEnvoye($nouvel)->code)
            ->assertRedirect(route('inscription.existant.formulaire'))
            ->assertSessionHas('dossier_existant.email_succes', 'val******@example.com');

        $etudiant->refresh();
        $this->assertSame($nouvel, $etudiant->email);
        $this->assertSame('valide', $etudiant->statut); // statut jamais modifié
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $etudiant->id,
            'action' => 'changement_email_public',
        ]);
    }

    public function test_dossier_rejete_refuse_sans_reinitialisation(): void
    {
        $etudiant = $this->creerEtudiant([
            'cin' => '22222222',
            'email' => 'rejete@example.com',
            'statut' => 'rejete',
        ]);
        $this->acceder($etudiant);

        $this->get(route('inscription.existant.formulaire'))
            ->assertOk()
            ->assertDontSee('Modifier mon adresse email');

        $this->demander('nouveau@example.com')
            ->assertRedirect(route('inscription.existant.formulaire'))
            ->assertSessionHasErrors('nouvel_email');

        $this->assertSame('rejete', $etudiant->fresh()->statut);
        $this->assertSame('rejete@example.com', $etudiant->fresh()->email);
        Notification::assertNotSentTo($etudiant, CodeConfirmationChangementEmail::class);
    }

    public function test_periode_fermee_refuse_le_changement(): void
    {
        $etudiant = $this->creerEtudiant();
        $this->acceder($etudiant);

        RegistrationPeriod::query()->update(['closed_at' => now()->subDay()]);

        $this->demander('nouveau@example.com')
            ->assertRedirect(route('inscription.existant.formulaire'))
            ->assertSessionHasErrors('nouvel_email');

        $this->assertSame('mohamed.benali@example.com', $etudiant->fresh()->email);
        Notification::assertNotSentTo($etudiant, CodeConfirmationChangementEmail::class);
    }

    /* ---------------------------------------------------------------
     | FIABILITÉ DES NOTIFICATIONS
     * -------------------------------------------------------------- */

    public function test_echec_envoi_du_code_annule_la_demande_sans_cooldown_bloquant(): void
    {
        $etudiant = $this->creerEtudiant();
        $this->acceder($etudiant);

        // Transport en panne : chaque tentative d'envoi échoue.
        // `twice()` prouve qu'aucun cooldown n'est posé sur échec (deux
        // envois réellement tentés) et qu'aucun code orphelin ne subsiste.
        $this->mock(ChannelManager::class, function (Mockery\MockInterface $mock): void {
            $mock->shouldReceive('send')->twice()->andThrow(new RuntimeException('SMTP indisponible'));
        });

        $nouvel = 'nouveau@example.com';
        $this->demander($nouvel)
            ->assertRedirect(route('inscription.existant.formulaire'))
            ->assertSessionHasErrors('nouvel_email');
        $this->assertSame('mohamed.benali@example.com', $etudiant->fresh()->email);
        $this->assertNull($this->service()->nouvelEmailMasqueEnAttente($etudiant));

        // Nouvelle tentative immédiate possible (pas de cooldown sur échec).
        $this->demander($nouvel)->assertSessionHasErrors('nouvel_email');
        $this->assertNull($this->service()->nouvelEmailMasqueEnAttente($etudiant));
    }

    public function test_echec_envoi_du_second_code_aucun_orphelin_reprise_immediate(): void
    {
        $etudiant = $this->creerEtudiant();
        $this->acceder($etudiant);
        $nouvel = 'nouveau@example.com';
        $this->demander($nouvel);
        $code1 = $this->codeAncienneEnvoye($etudiant)->code;

        // Panne APRÈS la première preuve (émission vers la candidate).
        $this->mock(ChannelManager::class, function (Mockery\MockInterface $mock): void {
            $mock->shouldReceive('send')->twice()->andThrow(new RuntimeException('SMTP indisponible'));
        });

        // Pas de succès mensonger : retour à la saisie avec une erreur
        // explicite, ancienne adresse conservée, aucun code orphelin.
        $this->confirmerAncienne($code1)
            ->assertRedirect(route('inscription.existant.formulaire'))
            ->assertSessionHas('dossier_existant.email_etape', 'saisie')
            ->assertSessionHasErrors('nouvel_email')
            ->assertSessionMissing('dossier_existant.email_succes');

        $this->assertSame('mohamed.benali@example.com', $etudiant->fresh()->email);
        $this->assertNull($this->service()->candidatureMasqueeEnAttente($etudiant));

        // Reprise immédiate possible (délai de renvoi libéré malgré la
        // première preuve consommée) — le second envoi échoue à nouveau
        // (transport toujours en panne) sans poser de cooldown.
        $this->demander($nouvel)->assertSessionHasErrors('nouvel_email');
        $this->assertNull($this->service()->candidatureMasqueeEnAttente($etudiant));
        $this->assertSame('mohamed.benali@example.com', $etudiant->fresh()->email);
    }

    public function test_echec_des_notifications_apres_commit_dossier_conserve_et_signale(): void
    {
        $etudiant = $this->creerEtudiant();
        $this->acceder($etudiant);
        $nouvel = 'nouveau@example.com';
        $code2 = $this->emettreSecondCode($etudiant, $nouvel);

        // Panne APRÈS la confirmation finale (alerte + confirmation
        // post-commit). `twice()` : les deux notifications sont tentées.
        $this->mock(ChannelManager::class, function (Mockery\MockInterface $mock): void {
            $mock->shouldReceive('send')->twice()->andThrow(new RuntimeException('SMTP indisponible'));
        });

        $this->confirmerCandidate($code2)
            ->assertRedirect(route('inscription.existant.formulaire'))
            ->assertSessionHas('dossier_existant.email_succes', 'nou******@example.com')
            ->assertSessionHas('dossier_existant.email_notifications_en_attente', true);

        // Changement conservé + historisé malgré l'échec d'envoi.
        $this->assertSame($nouvel, $etudiant->fresh()->email);
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $etudiant->id,
            'action' => 'changement_email_public',
        ]);
    }

    public function test_echec_de_validation_n_envoie_rien_et_ne_consomne_pas_les_codes(): void
    {
        $etudiant = $this->creerEtudiant();
        $this->acceder($etudiant);
        $nouvel = 'nouveau@example.com';
        $this->demander($nouvel);
        $code1 = $this->codeAncienneEnvoye($etudiant)->code;

        // Code mal formé : rejeté par la validation, premier code conservé.
        $this->confirmerAncienne('abc')
            ->assertSessionHasErrors('code');

        // Le bon premier code reste utilisable.
        $this->confirmerAncienne($code1)
            ->assertSessionHas('dossier_existant.email_etape', 'code-nouvelle');

        $code2 = $this->codeCandidateEnvoye($nouvel)->code;

        // Code final mal formé : rejeté, second code conservé.
        $this->confirmerCandidate('abc')
            ->assertSessionHasErrors('code_nouvelle');

        $this->confirmerCandidate($code2)
            ->assertSessionHas('dossier_existant.email_succes');

        $this->assertSame($nouvel, $etudiant->fresh()->email);
    }

    /* ---------------------------------------------------------------
     | CONTENU DES NOTIFICATIONS ET CONTRAT
     * -------------------------------------------------------------- */

    public function test_contenu_des_notifications_sans_pii_avec_reference_et_scolarite(): void
    {
        $etudiant = $this->creerEtudiant();

        Notification::fake();
        $this->service()->demanderChangementEmail($etudiant, 'nouveau@example.com');

        $code1 = $this->codeAncienneEnvoye($etudiant);
        $message1 = $code1->toMail($etudiant);
        $this->assertSame('Votre code de confirmation — changement d\'adresse email', $message1->subject);
        $contenu1 = implode("\n", $message1->introLines);
        $this->assertStringContainsString($code1->code, $contenu1);
        $this->assertStringContainsString('30 minutes', $contenu1);
        $this->assertStringNotContainsString('12345678', $contenu1);
        $this->assertStringNotContainsString('2003-05-17', $contenu1);

        // La première preuve seule n'envoie NI confirmation NI alerte.
        $this->assertTrue(Notification::sent(new AnonymousNotifiable, ConfirmationChangementEmail::class)->isEmpty());
        $this->assertTrue(Notification::sent(new AnonymousNotifiable, AlerteChangementEmail::class)->isEmpty());

        // Seconde preuve vers la candidate : contenu vérifié, sans PII.
        $candidate = $this->service()->verifierCodeAncienneAdresse($etudiant, $code1->code);
        $this->service()->emettreCodeNouvelleAdresse($etudiant, $candidate['nouvel_email'], $candidate['ancien_email_hash']);

        $code2 = $this->codeCandidateEnvoye('nouveau@example.com');
        $this->assertNotNull($code2);
        $message2 = $code2->toMail($etudiant);
        $this->assertSame('Confirmez votre nouvelle adresse email — dossier étudiant', $message2->subject);
        $contenu2 = implode("\n", $message2->introLines);
        $this->assertStringContainsString($code2->code, $contenu2);
        $this->assertStringContainsString('30 minutes', $contenu2);
        $this->assertStringNotContainsString('12345678', $contenu2);
        $this->assertStringNotContainsString('nouveau@example.com', $contenu2);

        $resultat = $this->service()->confirmerNouvelleAdresse($etudiant, $code2->code);
        $this->assertNotNull($resultat);
        $notifications = $this->service()->notifierChangementEmail($etudiant->fresh(), 'mohamed.benali@example.com', 'nouveau@example.com');
        $this->assertTrue($notifications['ancienne_notifiee']);
        $this->assertTrue($notifications['nouvelle_notifiee']);

        $alerte = Notification::sent(new AnonymousNotifiable, AlerteChangementEmail::class,
            fn ($n, $c, $d) => $d->routeNotificationFor('mail') === 'mohamed.benali@example.com')->first();
        $mailAlerte = $alerte->toMail($etudiant->fresh());
        $corpsAlerte = implode("\n", array_merge($mailAlerte->introLines, $mailAlerte->outroLines ?? []));
        $this->assertSame('Sécurité : votre adresse email a été modifiée', $mailAlerte->subject);
        $this->assertStringContainsString('nou******@example.com', $corpsAlerte);
        $this->assertStringNotContainsString('nouveau@example.com', $corpsAlerte);
        $this->assertStringNotContainsString('mohamed.benali@example.com', $corpsAlerte);

        $confirmation = Notification::sent(new AnonymousNotifiable, ConfirmationChangementEmail::class,
            fn ($n, $c, $d) => $d->routeNotificationFor('mail') === 'nouveau@example.com')->first();
        $mailConfirmation = $confirmation->toMail($etudiant->fresh());
        $corpsConfirmation = implode("\n", array_merge($mailConfirmation->introLines, $mailConfirmation->outroLines ?? []));
        $this->assertSame('Confirmation de changement de votre adresse email', $mailConfirmation->subject);
        $this->assertStringContainsString($etudiant->fresh()->num_inscription, $corpsConfirmation);
        $this->assertStringContainsString('scolarité', $corpsConfirmation);
        $this->assertStringNotContainsString('12345678', $corpsConfirmation);
        $this->assertStringNotContainsString('nouveau@example.com', $corpsConfirmation);
    }

    public function test_routes_email_dans_le_groupe_web_avec_csrf_obligatoire(): void
    {
        foreach (['/inscription/existant/email', '/inscription/existant/email/confirmer', '/inscription/existant/email/confirmer-nouvelle'] as $uri) {
            $route = Route::getRoutes()->match(Request::create($uri, 'POST'));
            $this->assertContains('web', $route->gatherMiddleware());
        }

        $groupes = Route::getMiddlewareGroups();
        $this->assertContains(PreventRequestForgery::class, $groupes['web']);
    }

    public function test_throttle_isoles_par_fonction_le_parcours_nominal_n_est_jamais_bloque(): void
    {
        $etudiant = $this->creerEtudiant();
        $this->acceder($etudiant);

        // Compartiments par fonction (doctrine LOOP P) : 5 demandes
        // successives sur le même seau passent le middleware (chaque
        // demande après la première est refusée par le cooldown métier,
        // pas par HTTP 429), la 6e est rejetée en 429 — sans affecter les
        // autres étapes du parcours.
        for ($i = 1; $i <= 5; $i++) {
            $this->demander("nouveau{$i}@example.com")->assertRedirect(route('inscription.existant.formulaire'));
        }

        $this->demander('trop@example.com')->assertStatus(429);
        $this->assertSame('mohamed.benali@example.com', $etudiant->fresh()->email);
    }

    public function test_soumission_coordonnees_ignore_toujours_l_email(): void
    {
        $etudiant = $this->creerEtudiant();
        $code = $this->rechercherEtRecupererCode($etudiant);
        $this->post(route('inscription.existant.verification'), ['code' => $code])
            ->assertRedirect(route('inscription.existant.formulaire'));

        $this->post(route('inscription.existant-store'), [
            'email' => 'pirate@evil.example',
            'nouvel_email' => 'pirate@evil.example',
            'telephone' => '+212699999999',
            'adresse' => '12 Rue Nouvelle',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'confirmation' => '1',
        ])->assertRedirect(route('inscription.existant.confirmation'));

        $this->assertSame('mohamed.benali@example.com', $etudiant->fresh()->email);
    }

    private function rechercherEtRecupererCode(Etudiant $etudiant): string
    {
        Notification::fake();

        $this->post(route('inscription.existant.recherche'), [
            'cin' => $etudiant->cin,
            'date_naissance' => $etudiant->date_naissance->toDateString(),
        ])->assertRedirect(route('inscription.existant.accueil'));

        return Notification::sent($etudiant, CodeVerificationDossierExistant::class)->first()->code;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Cache::flush();
        parent::tearDown();
    }
}
