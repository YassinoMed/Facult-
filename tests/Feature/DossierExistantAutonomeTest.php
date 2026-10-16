<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\RegistrationPeriod;
use App\Notifications\AlerteChangementEmail;
use App\Notifications\CodeConfirmationNouvelleAdresse;
use App\Notifications\ConfirmationChangementEmail;
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
 * Parcours AUTONOME de changement d'adresse email (sans l'ancienne adresse,
 * sans scolarité) : CIN + naissance + ANCIENNE adresse + MOT DE PASSE en une
 * seule soumission à réponse neutre, PUIS OTP vers la NOUVELLE adresse.
 *
 * La preuve supplémentaire exigée (la boucle interdit CIN + ancienne adresse
 * + OTP comme preuve suffisante) est le MOT DE PASSE du compte, vérifié en
 * bcrypt — mécanisme fiable déjà disponible, aucune question faible inventée.
 * L'autorisation temporaire est liée au dossier exact et à la SEULE opération
 * `email_change` (usage unique). L'ancienne adresse reste active jusqu'à la
 * confirmation finale.
 */
class DossierExistantAutonomeTest extends TestCase
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

    private function demander(array $charge = [])
    {
        return $this->post(route('inscription.existant.autonome'), array_merge([
            'cin' => '12345678',
            'date_naissance' => '2003-05-17',
            'ancien_email' => 'mohamed.benali@example.com',
            'password' => 'MotdepasseSecret123',
            'nouvel_email' => 'nouveau.autonome@example.com',
            'nouvel_email_confirmation' => 'nouveau.autonome@example.com',
        ], $charge));
    }

    private function confirmer(string $code)
    {
        return $this->post(route('inscription.existant.autonome.confirmer'), ['code_autonome' => $code]);
    }

    /** OTP émis vers la candidate (null si aucun). */
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
     | PARCOURS NOMINAL
     * -------------------------------------------------------------- */

    public function test_parcours_nominal_autonome_persiste_en_base(): void
    {
        $etudiant = $this->creerEtudiant();
        Notification::fake();
        $nouvel = 'nouveau.autonome@example.com';

        // Carte visible sans session sur l'accueil (entrée du parcours).
        $this->get(route('inscription.existant.accueil'))
            ->assertOk()
            ->assertSee('Changer mon adresse e-mail sans passer par la scolarité')
            ->assertSee('action="'.route('inscription.existant.autonome').'"', false)
            ->assertSee('name="password"', false);

        // Le mot de passe n'est JAMAIS réaffiché (pas de old()).
        $this->assertStringNotContainsString(
            'value="MotdepasseSecret123"',
            $this->get(route('inscription.existant.accueil'))->getContent()
        );

        $this->demander()
            ->assertRedirect(route('inscription.existant.accueil'))
            ->assertSessionHas('dossier_existant.autonome_etape', 'code')
            ->assertSessionMissing('errors');

        // OTP parti UNIQUEMENT vers la candidate ; ancienne active.
        $this->assertNotNull($this->codeCandidateEnvoye($nouvel));
        $this->assertTrue(Notification::sent(new AnonymousNotifiable, AlerteChangementEmail::class)->isEmpty());
        $this->assertTrue(Notification::sent(new AnonymousNotifiable, ConfirmationChangementEmail::class)->isEmpty());
        $this->assertSame('mohamed.benali@example.com', $etudiant->fresh()->email);

        // L'étape OTP s'affiche en FENÊTRE POPUP (même message neutre que
        // les échecs), avec bouton de réouverture.
        $etape = $this->get(route('inscription.existant.accueil'))->getContent();
        $this->assertStringContainsString('titre-code-autonome', $etape);
        $this->assertStringContainsString('aria-modal="true"', $etape);
        $this->assertStringContainsString('Saisir le code reçu', $etape);
        $this->assertStringContainsString('name="code_autonome"', $etape);

        $this->confirmer($this->codeCandidateEnvoye($nouvel)->code)
            ->assertRedirect(route('inscription.existant.accueil'))
            ->assertSessionHas('dossier_existant.autonome_succes', 'nou******@example.com');

        // Persistance réelle : un seul dossier, seul l'email a changé.
        $etudiant->refresh();
        $this->assertSame($nouvel, $etudiant->email);
        $this->assertSame(1, Etudiant::count());
        $this->assertSame('BEN ALI', $etudiant->nom);
        $this->assertSame('en_attente', $etudiant->statut);

        // Audit masqué sous l'action dédiée.
        $entree = HistoriqueAcademique::where('etudiant_id', $etudiant->id)
            ->where('action', 'changement_email_autonome')->firstOrFail();
        $this->assertSame('moh******@example.com', json_decode((string) $entree->ancienne_valeur, true)['email']);
        $this->assertStringNotContainsString('mohamed.benali@example.com', (string) $entree->ancienne_valeur);

        // Alerte ancienne + confirmation nouvelle (post-commit uniquement).
        $this->assertNotNull(Notification::sent(new AnonymousNotifiable, AlerteChangementEmail::class,
            fn ($n, $c, $d) => $d->routeNotificationFor('mail') === 'mohamed.benali@example.com')->first());
        $this->assertNotNull(Notification::sent(new AnonymousNotifiable, ConfirmationChangementEmail::class,
            fn ($n, $c, $d) => $d->routeNotificationFor('mail') === $nouvel)->first());

        // Bascule de connexion effective (ancienne KO, nouvelle OK).
        $this->post(route('etudiant.connexion.login'), [
            'identifiant' => 'mohamed.benali@example.com', 'password' => 'MotdepasseSecret123',
        ])->assertSessionHasErrors('identifiant');
        $this->post(route('etudiant.connexion.login'), [
            'identifiant' => $nouvel, 'password' => 'MotdepasseSecret123',
        ])->assertRedirect(route('etudiant.profil.show'));
    }

    /* ---------------------------------------------------------------
     | NEUTRALITÉ ANTI-ÉNUMÉRATION
     * -------------------------------------------------------------- */

    public function test_etape_code_masquee_par_defaut_sans_demande(): void
    {
        // La popup de validation est toujours rendue mais masquée par
        // défaut (x-show + x-cloak, état initial false) : aucun code en
        // attente côté serveur, rien n'est exposé.
        $this->creerEtudiant();

        $html = $this->get(route('inscription.existant.accueil'))->getContent();
        $this->assertStringContainsString('titre-code-autonome', $html);
        $this->assertStringContainsString('x-show="codeOuvert"', $html);
        $this->assertStringContainsString('codeOuvert: false', $html);
        $this->assertStringNotContainsString('Saisir le code reçu', $html);
        // Aucune valeur pré-remplie dans le champ code.
        $this->assertDoesNotMatchRegularExpression('/name="code_autonome"\s+value="[^"]/', $html);
    }

    public function test_reponse_strictement_neutre_quel_que_soit_l_echec(): void
    {
        $this->creerEtudiant();
        Notification::fake();

        $statutValide = $this->demander()->assertSessionHas('dossier_existant.autonome_etape', 'code');
        $messageValide = session('statut');

        // Reset du fake : les échecs suivants ne doivent RIEN envoyer.
        Notification::fake();

        // CIN inconnu, naissance incorrecte, ancienne adresse incorrecte,
        // mot de passe incorrect : réponse IDENTIQUE, rien n'est envoyé.
        foreach ([
            ['cin' => '99999999'],
            ['date_naissance' => '1990-01-01'],
            ['ancien_email' => 'inconnu@evil.example'],
            ['password' => 'MauvaisMotDePasse1'],
        ] as $charge) {
            $this->demander($charge)
                ->assertRedirect(route('inscription.existant.accueil'))
                ->assertSessionHas('dossier_existant.autonome_etape', 'code')
                ->assertSessionMissing('errors');
            $this->assertSame($messageValide, session('statut'));
        }

        Notification::assertNothingSent();
    }

    public function test_sans_mot_de_passe_le_parcours_est_ferme_neutre(): void
    {
        // Dossier sans mot de passe défini (fail-closed) : réponse neutre,
        // aucun code, orientation scolarité via l'aide pré-OTP.
        $etudiant = $this->creerEtudiant();
        $etudiant->update(['password' => null]);

        Notification::fake();

        $this->demander()
            ->assertRedirect(route('inscription.existant.accueil'))
            ->assertSessionHas('dossier_existant.autonome_etape', 'code');

        Notification::assertNothingSent();
        $this->assertSame('mohamed.benali@example.com', $etudiant->fresh()->email);
    }

    public function test_api_json_neutre_identique_valide_ou_non(): void
    {
        $this->creerEtudiant();
        Notification::fake();

        $charge = [
            'cin' => '12345678', 'date_naissance' => '2003-05-17',
            'ancien_email' => 'mohamed.benali@example.com', 'password' => 'MotdepasseSecret123',
            'nouvel_email' => 'json@example.com', 'nouvel_email_confirmation' => 'json@example.com',
        ];

        $valide = $this->postJson(route('inscription.existant.autonome'), $charge)
            ->assertOk()
            ->assertJson(['ok' => true, 'etape' => 'code'])
            ->getContent();

        // Mot de passe faux : corps STRICTEMENT identique (aucun oracle),
        // mais rien n'est envoyé.
        Notification::fake();
        $invalide = $this->postJson(route('inscription.existant.autonome'), array_merge($charge, [
            'password' => 'FauxMotDePasse9',
        ]))->assertOk()->getContent();

        $this->assertSame($valide, $invalide);
        Notification::assertNothingSent();
    }

    public function test_api_json_validation_422_sans_secret(): void
    {
        $this->creerEtudiant();
        Notification::fake();

        $reponse = $this->postJson(route('inscription.existant.autonome'), [
            'cin' => '12345678', 'date_naissance' => '2003-05-17',
            'ancien_email' => 'mohamed.benali@example.com', 'password' => 'MotdepasseSecret123',
            'nouvel_email' => 'pas-un-email', 'nouvel_email_confirmation' => 'pas-un-email',
        ])->assertStatus(422);

        $corps = $reponse->getContent();
        $this->assertStringContainsString('nouvel_email', $corps);
        // Le secret ne fuit ni dans les erreurs, ni dans l'_old_input.
        $this->assertStringNotContainsString('MotdepasseSecret123', $corps);
        Notification::assertNothingSent();
    }

    public function test_api_json_confirmation_succes_et_echec(): void
    {
        $etudiant = $this->creerEtudiant();
        Notification::fake();
        $nouvel = 'json.confirm@example.com';

        $this->postJson(route('inscription.existant.autonome'), [
            'cin' => '12345678', 'date_naissance' => '2003-05-17',
            'ancien_email' => 'mohamed.benali@example.com', 'password' => 'MotdepasseSecret123',
            'nouvel_email' => $nouvel, 'nouvel_email_confirmation' => $nouvel,
        ])->assertOk()->assertJson(['ok' => true]);

        $code = $this->codeCandidateEnvoye($nouvel)->code;

        $this->postJson(route('inscription.existant.autonome.confirmer'), ['code_autonome' => '000000'])
            ->assertOk()
            ->assertJson(['ok' => false, 'etape' => 'code']);
        $this->assertSame('mohamed.benali@example.com', $etudiant->fresh()->email);

        $this->postJson(route('inscription.existant.autonome.confirmer'), ['code_autonome' => $code])
            ->assertOk()
            ->assertJson(['ok' => true]);
        $this->assertSame($nouvel, $etudiant->fresh()->email);
    }

    public function test_mot_de_passe_jamais_rembourse_en_session(): void
    {
        $this->creerEtudiant();
        Notification::fake();

        // Échec de validation (format) avec mot de passe fourni : le flash
        // `_old_input` ne doit JAMAIS contenir le secret.
        $this->demander(['nouvel_email' => 'pas-un-email', 'nouvel_email_confirmation' => 'pas-un-email'])
            ->assertSessionHasErrors('nouvel_email');

        $ancien = session()->get('_old_input', []);
        $this->assertIsArray($ancien);
        $this->assertArrayNotHasKey('password', $ancien);
    }

    /* ---------------------------------------------------------------
     | VALIDATION DE LA DEMANDE (neutre, rien d'envoyé)
     * -------------------------------------------------------------- */

    public function test_demandes_refusees_neutres_sans_envoi(): void
    {
        $etudiant = $this->creerEtudiant();
        $this->creerEtudiant([
            'cin' => '87654321', 'email' => 'prise@example.com',
            'date_naissance' => '2001-02-02', 'nom' => 'AUTRE', 'prenom' => 'Personne',
        ]);
        Notification::fake();

        // Confirmation différente → erreur de FORMAT (seule entorse à la
        // neutralité : ne révèle rien sur l'existence).
        $this->demander(['nouvel_email_confirmation' => 'autre@example.com'])
            ->assertSessionHasErrors('nouvel_email');

        // Unicité / identité / période / statut : réponse neutre.
        $this->demander(['nouvel_email' => 'PRISE@example.com', 'nouvel_email_confirmation' => 'PRISE@example.com'])
            ->assertSessionMissing('errors');
        $this->demander(['nouvel_email' => 'Mohamed.Benali@Example.com', 'nouvel_email_confirmation' => 'Mohamed.Benali@Example.com'])
            ->assertSessionMissing('errors');

        Notification::assertNothingSent();
        $this->assertSame('mohamed.benali@example.com', $etudiant->fresh()->email);
    }

    public function test_statut_et_periode_refusent_neutre(): void
    {
        $rejete = $this->creerEtudiant([
            'cin' => '22222222', 'email' => 'rejete@example.com',
            'date_naissance' => '2002-02-02', 'statut' => 'rejete',
        ]);
        Notification::fake();

        $this->demander([
            'cin' => '22222222', 'date_naissance' => '2002-02-02',
            'ancien_email' => 'rejete@example.com',
        ])->assertSessionMissing('errors');

        RegistrationPeriod::query()->update(['closed_at' => now()->subDay()]);

        $this->demander()->assertSessionMissing('errors');

        Notification::assertNothingSent();
        $this->assertSame('rejete@example.com', $rejete->fresh()->email);
    }

    /* ---------------------------------------------------------------
     | CODE : EXPIRATION, USAGE UNIQUE, ÉPUISEMENT, AUTORISATION
     * -------------------------------------------------------------- */

    public function test_code_invalide_epuise_et_rejoue_sont_refuses(): void
    {
        $etudiant = $this->creerEtudiant();
        Notification::fake();
        $nouvel = 'nouveau.autonome@example.com';
        $this->demander();
        $bonCode = $this->codeCandidateEnvoye($nouvel)->code;

        $this->confirmer('000000')
            ->assertRedirect(route('inscription.existant.accueil'))
            ->assertSessionHasErrors('code_autonome');
        $this->assertSame('mohamed.benali@example.com', $etudiant->fresh()->email);

        for ($i = 0; $i < DossierExistantService::MAX_ESSAIS_CODE_AUTONOME - 1; $i++) {
            $this->confirmer('000000');
        }
        $this->confirmer($bonCode)->assertSessionHasErrors('code_autonome');
        $this->assertSame('mohamed.benali@example.com', $etudiant->fresh()->email);

        $this->assertTrue(Notification::sent(new AnonymousNotifiable, AlerteChangementEmail::class)->isEmpty());
        $this->assertTrue(Notification::sent(new AnonymousNotifiable, ConfirmationChangementEmail::class)->isEmpty());
    }

    public function test_code_expire_et_autorisation_expiree_sont_refuses(): void
    {
        $etudiant = $this->creerEtudiant();
        Notification::fake();
        $jeton = $this->service()->demanderChangementAutonome(
            '12345678', '2003-05-17', 'mohamed.benali@example.com', 'MotdepasseSecret123', 'nouveau.autonome@example.com'
        );
        $this->assertNotNull($jeton);
        $code = $this->codeCandidateEnvoye('nouveau.autonome@example.com')->code;

        $this->travel(31)->minutes();

        $this->assertNull($this->service()->confirmerChangementAutonome($jeton, $code));
        $this->assertSame('mohamed.benali@example.com', $etudiant->fresh()->email);
    }

    public function test_adresse_source_modifiee_entre_temps_refuse_neutre(): void
    {
        // Invariant 8 (parcours autonome) : intervention concurrente sur
        // l'adresse source → preuve caduque, refus neutre, ancienne
        // (nouvelle en vigueur) conservée, aucun audit.
        $etudiant = $this->creerEtudiant();
        Notification::fake();
        $nouvel = 'nouveau.autonome@example.com';
        $jeton = $this->service()->demanderChangementAutonome(
            '12345678', '2003-05-17', 'mohamed.benali@example.com', 'MotdepasseSecret123', $nouvel
        );
        $this->assertNotNull($jeton);
        $code = $this->codeCandidateEnvoye($nouvel)->code;

        $etudiant->update(['email' => 'intervention.scolarite@example.com']);

        try {
            $this->service()->confirmerChangementAutonome($jeton, $code);
            $this->fail('Le changement aurait dû être refusé (adresse source modifiée).');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('scolarité', $e->getMessage());
        }
        $this->assertSame('intervention.scolarite@example.com', $etudiant->fresh()->email);
        $this->assertDatabaseMissing('historique_academique', [
            'etudiant_id' => $etudiant->id, 'action' => 'changement_email_autonome',
        ]);
    }

    public function test_meme_candidate_via_plusieurs_dossiers_limitee(): void
    {
        // Anti-mailbomb par destinataire : une même candidate ne peut pas
        // être arrosée via des CIN multiples (cooldown 60 s partagé).
        $cible = $this->creerEtudiant();
        $autre = $this->creerEtudiant([
            'cin' => '87654321', 'email' => 'autre@example.com',
            'date_naissance' => '2001-02-02', 'nom' => 'AUTRE', 'prenom' => 'Personne',
        ]);
        Notification::fake();
        $nouvel = 'arrosage@example.com';

        $jetonA = $this->service()->demanderChangementAutonome(
            '12345678', '2003-05-17', 'mohamed.benali@example.com', 'MotdepasseSecret123', $nouvel
        );
        $this->assertNotNull($jetonA);

        // Second dossier, même candidate : refus silencieux (neutre), aucun
        // second envoi vers cette adresse.
        $jetonB = $this->service()->demanderChangementAutonome(
            '87654321', '2001-02-02', 'autre@example.com', 'MotdepasseSecret123', $nouvel
        );
        $this->assertNull($jetonB);

        $envois = Notification::sent(new AnonymousNotifiable, CodeConfirmationNouvelleAdresse::class,
            fn ($n, $c, $d) => $d->routeNotificationFor('mail') === $nouvel);
        $this->assertCount(1, $envois);
        $this->assertSame('mohamed.benali@example.com', $cible->fresh()->email);
        $this->assertSame('autre@example.com', $autre->fresh()->email);
    }

    public function test_sans_parcours_prealable_la_confirmation_est_refusee(): void
    {
        $etudiant = $this->creerEtudiant();
        Notification::fake();

        // Appel direct, sans jeton en session.
        $this->confirmer('123456')
            ->assertRedirect(route('inscription.existant.accueil'))
            ->assertSessionHasErrors('code_autonome');

        $this->assertSame('mohamed.benali@example.com', $etudiant->fresh()->email);
        Notification::assertNothingSent();
    }

    public function test_jeton_autre_operation_et_concurrence(): void
    {
        $etudiant = $this->creerEtudiant();
        Notification::fake();
        $nouvel = 'nouveau.autonome@example.com';
        $this->demander();
        $code = $this->codeCandidateEnvoye($nouvel)->code;

        // Jeton lié à une autre opération : refusé.
        Cache::put(
            'dossier_existant:autonome:'.hash('sha256', 'faux-jeton'),
            ['operation' => 'autre_operation', 'etudiant_id' => $etudiant->id,
                'nouvel_email' => $nouvel, 'code_hash' => hash('sha256', $code),
                'tentatives' => 0, 'expire_a' => now()->addMinutes(30)->getTimestamp()],
            1800
        );
        $this->assertNull($this->service()->confirmerChangementAutonome('faux-jeton', $code));

        // Concurrence : un seul flux applique (usage unique atomique).
        // (La session stocke la clé à points en tableau imbriqué : lecture
        // par notation à points, symétrique au `put` du contrôleur.)
        $jeton = session('dossier_existant.jeton_autonome');
        $this->assertNotNull($jeton);

        $premier = $this->service()->confirmerChangementAutonome($jeton, $code);
        $this->assertNotNull($premier);
        $this->assertNull($this->service()->confirmerChangementAutonome($jeton, $code));

        $this->assertSame($nouvel, $etudiant->fresh()->email);
        $this->assertSame(1, Etudiant::count());

        // Rejeu HTTP après consommation : refusé ; le service seul ne
        // notifie jamais (les notifications partent du contrôleur,
        // après commit — ici le chemin est direct service).
        $this->confirmer($code)->assertSessionHasErrors('code_autonome');
        Notification::assertSentOnDemandTimes(ConfirmationChangementEmail::class, 0);
    }

    /* ---------------------------------------------------------------
     | ANTI-IDOR, WHITELIST, CONFLIT TARDIF, FIABILITÉ
     * -------------------------------------------------------------- */

    public function test_identifiant_et_autres_champs_sont_ignores(): void
    {
        $cible = $this->creerEtudiant();
        $autre = $this->creerEtudiant([
            'cin' => '87654321', 'email' => 'autre@example.com',
            'date_naissance' => '2001-02-02', 'nom' => 'AUTRE', 'prenom' => 'Personne',
        ]);
        Notification::fake();
        $nouvel = 'cible.autonome@example.com';

        $this->post(route('inscription.existant.autonome'), [
            'etudiant_id' => $autre->id, // tentative IDOR
            'id' => $autre->id,
            'nom' => 'PIRATE',
            'statut' => 'valide',
            'cin' => '12345678',
            'date_naissance' => '2003-05-17',
            'ancien_email' => 'mohamed.benali@example.com',
            'password' => 'MotdepasseSecret123',
            'nouvel_email' => $nouvel,
            'nouvel_email_confirmation' => $nouvel,
        ])->assertRedirect(route('inscription.existant.accueil'));

        $this->post(route('inscription.existant.autonome.confirmer'), [
            'code_autonome' => $this->codeCandidateEnvoye($nouvel)->code,
            'etudiant_id' => $autre->id,
        ])->assertRedirect(route('inscription.existant.accueil'));

        $this->assertSame($nouvel, $cible->fresh()->email);
        $this->assertSame('autre@example.com', $autre->fresh()->email);
        $this->assertSame('BEN ALI', $cible->fresh()->nom);
        $this->assertSame('en_attente', $cible->fresh()->statut);
    }

    public function test_conflit_unicite_tardif_conserve_l_ancienne_adresse(): void
    {
        $etudiant = $this->creerEtudiant();
        Notification::fake();
        $nouvel = 'conflit@example.com';
        $this->demander(['nouvel_email' => $nouvel, 'nouvel_email_confirmation' => $nouvel]);
        $code = $this->codeCandidateEnvoye($nouvel)->code;

        // Un autre dossier prend l'adresse entre la demande et la confirmation.
        $this->creerEtudiant([
            'cin' => '87654321', 'email' => $nouvel,
            'date_naissance' => '2001-02-02', 'nom' => 'AUTRE', 'prenom' => 'Personne',
        ]);

        $this->confirmer($code)
            ->assertRedirect(route('inscription.existant.accueil'))
            ->assertSessionMissing('dossier_existant.autonome_succes');

        $this->assertSame('mohamed.benali@example.com', $etudiant->fresh()->email);
        $this->assertDatabaseMissing('historique_academique', [
            'etudiant_id' => $etudiant->id, 'action' => 'changement_email_autonome',
        ]);
    }

    public function test_echec_envoi_otp_conserve_tout_et_permet_reessai(): void
    {
        $etudiant = $this->creerEtudiant();
        Notification::fake();

        $this->mock(ChannelManager::class, function (Mockery\MockInterface $mock): void {
            $mock->shouldReceive('send')->twice()->andThrow(new RuntimeException('SMTP indisponible'));
        });

        // Réponse neutre identique, ancienne conservée, réessai immédiat.
        $this->demander()->assertSessionHas('dossier_existant.autonome_etape', 'code');
        $this->demander()->assertSessionHas('dossier_existant.autonome_etape', 'code');
        $this->assertSame('mohamed.benali@example.com', $etudiant->fresh()->email);
    }

    public function test_echec_notifications_apres_commit_conserve_et_signale(): void
    {
        $etudiant = $this->creerEtudiant();
        Notification::fake();
        $nouvel = 'nouveau.autonome@example.com';
        $this->demander();
        $code = $this->codeCandidateEnvoye($nouvel)->code;

        $this->mock(ChannelManager::class, function (Mockery\MockInterface $mock): void {
            $mock->shouldReceive('send')->twice()->andThrow(new RuntimeException('SMTP indisponible'));
        });

        $this->confirmer($code)
            ->assertRedirect(route('inscription.existant.accueil'))
            ->assertSessionHas('dossier_existant.autonome_succes', 'nou******@example.com')
            ->assertSessionHas('dossier_existant.email_notifications_en_attente', true);

        $this->assertSame($nouvel, $etudiant->fresh()->email);
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $etudiant->id, 'action' => 'changement_email_autonome',
        ]);
    }

    public function test_limitation_par_identite_et_routes_protegees(): void
    {
        $this->creerEtudiant();

        for ($i = 0; $i < DossierExistantService::MAX_DEMANDES_AUTONOME; $i++) {
            $this->service()->compterDemandeAutonome('12345678', '2003-05-17');
        }
        $this->assertTrue($this->service()->demandeAutonomeBloquee('12345678', '2003-05-17'));
        $this->assertFalse($this->service()->demandeAutonomeBloquee('87654321', '2003-05-17'));

        foreach (['/inscription/existant/autonome', '/inscription/existant/autonome/confirmer'] as $uri) {
            $route = Route::getRoutes()->match(Request::create($uri, 'POST'));
            $this->assertContains('web', $route->gatherMiddleware());
        }
        $this->assertContains(PreventRequestForgery::class, Route::getMiddlewareGroups()['web']);
    }

    public function test_soumission_coordonnees_existante_toujours_independante(): void
    {
        // Le parcours popup historique ignore toujours l'email via le store.
        $etudiant = $this->creerEtudiant();
        Notification::fake();

        $this->post(route('inscription.existant.autonome'), [
            'cin' => '12345678', 'date_naissance' => '2003-05-17',
            'ancien_email' => 'mohamed.benali@example.com', 'password' => 'MotdepasseSecret123',
            'email' => 'pirate@evil.example',
            'nouvel_email' => 'nouveau.autonome@example.com',
            'nouvel_email_confirmation' => 'nouveau.autonome@example.com',
        ])->assertRedirect(route('inscription.existant.accueil'));

        // `email` parasite ignoré : seule la candidate liée au code compte.
        $this->confirmer($this->codeCandidateEnvoye('nouveau.autonome@example.com')->code)
            ->assertSessionHas('dossier_existant.autonome_succes');

        $this->assertSame('nouveau.autonome@example.com', $etudiant->fresh()->email);
        $this->assertSame(1, Etudiant::count());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Cache::flush();
        parent::tearDown();
    }
}
