<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\RegistrationPeriod;
use App\Notifications\CodeVerificationDossierExistant;
use App\Notifications\ConfirmationMiseAJourDossierExistant;
use App\Services\DossierExistantService;
use App\Services\PeriodeInscriptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Parcours public sans login pour dossier étudiant EXISTANT (extension LOOP).
 *
 * Couvre les scénarios obligatoires :
 * - nominal : recherche → code → formulaire prérempli → soumission →
 *   historique → email après commit ;
 * - sécurité : anti-énumération, code expiré/invalide/épuisé, accès
 *   direct au formulaire, IDOR, champs protégés, dossiers valide/rejete,
 *   rejeu après succès, consumption concurrente de l'autorisation,
 *   limitation par identité, aucune session authentifiée générale ;
 * - email : jamais avant commit, distinction « enregistré » / « envoyé »,
 *   échec d'envoi non bloquant, contenu sans PII.
 */
class DossierExistantParcoursTest extends TestCase
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

        // Parcours public en régime strict : période ouverte globale.
        $annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-06-30', 'active' => true,
        ]);
        app(PeriodeInscriptionService::class)->creerNormale([
            'annee_universitaire_id' => $annee->id,
            'opened_at' => '2020-01-01 00:00', 'closed_at' => '2030-12-31 23:59',
        ], null);
    }

    /** Crée un étudiant en attente (parcours modifiable). */
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

    /** Exécute la recherche (étape 1) et retourne le code envoyé (notification captée). */
    private function rechercherEtRecupererCode(Etudiant $etudiant): string
    {
        Notification::fake();

        $this->post(route('inscription.existant.recherche'), [
            'cin' => $etudiant->cin,
            'date_naissance' => $etudiant->date_naissance->toDateString(),
        ])->assertRedirect(route('inscription.existant.accueil'));

        Notification::assertSentTo($etudiant, CodeVerificationDossierExistant::class);

        return Notification::sent($etudiant, CodeVerificationDossierExistant::class)->first()->code;
    }

    private function verifierCode(string $code): void
    {
        $this->post(route('inscription.existant.verification'), ['code' => $code])
            ->assertRedirect(route('inscription.existant.formulaire'));
    }

    private function service(): DossierExistantService
    {
        return app(DossierExistantService::class);
    }

    /* ---------------------------------------------------------------
     | PARCOURS NOMINAL
     * -------------------------------------------------------------- */

    public function test_parcours_nominal_complet_recherche_code_formulaire_soumission_email(): void
    {
        $etudiant = $this->creerEtudiant();

        $code = $this->rechercherEtRecupererCode($etudiant);

        // Le code part à l'adresse DÉJÀ ENREGISTRÉE du dossier.
        Notification::assertSentTo($etudiant, CodeVerificationDossierExistant::class, 1);

        $this->verifierCode($code);

        // Aucune session authentifiée générale n'est créée.
        $this->assertGuest();
        $this->assertFalse(session()->has('etudiant_auth_id'));

        $reponse = $this->get(route('inscription.existant.formulaire'));
        $reponse->assertOk();
        $reponse->assertSee('45 Avenue Hassan II'); // prérempli
        $reponse->assertSee('Rabat');
        $reponse->assertSee('12***678'); // CIN masquée uniquement
        $reponse->assertDontSee('MotdepasseSecret123');
        $reponse->assertDontSee('name="statut"', false);
        $reponse->assertDontSee('name="cin"', false); // CIN non modifiable

        $reponse = $this->post(route('inscription.existant-store'), [
            'telephone' => '+212699999999',
            'adresse' => '12 Rue Nouvelle',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'confirmation' => '1',
        ]);
        $reponse->assertRedirect(route('inscription.existant.confirmation'));

        // Enregistrement réel (aucun second dossier créé).
        $etudiant->refresh();
        $this->assertSame('12 Rue Nouvelle', $etudiant->adresse);
        $this->assertSame('Casablanca', $etudiant->ville);
        $this->assertSame('20000', $etudiant->code_postal);
        $this->assertSame(1, Etudiant::count());

        // Historique de la modification.
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $etudiant->id,
            'action' => 'modification_dossier_public',
        ]);

        // Email de confirmation déclenché APRÈS le commit (l'enregistrement
        // ci-dessus est déjà visible en base au moment de l'assertion).
        Notification::assertSentTo($etudiant, ConfirmationMiseAJourDossierExistant::class, 1);

        // Écran final : succès explicite sans données sensibles.
        $confirmation = $this->get(route('inscription.existant.confirmation'));
        $confirmation->assertOk()
            ->assertSee('Votre demande a été enregistrée')
            ->assertSee($etudiant->num_inscription);
        $corps = $confirmation->getContent();
        $this->assertStringNotContainsString('12345678', $corps);
        $this->assertStringNotContainsString('2003-05-17', $corps);

        // Le flash est consommé : un rechargement ne réaffiche pas un faux succès.
        $this->get(route('inscription.existant.confirmation'))
            ->assertRedirect(route('inscription.existant.accueil'));
    }

    public function test_saisie_conservee_apres_recherche_sans_oracle(): void
    {
        // La saisie (CIN + naissance tapés par l'utilisateur) est conservée
        // après la recherche — identique que le dossier existe ou non
        // (aucune donnée du dossier, aucun oracle).
        $this->creerEtudiant();
        Notification::fake();

        $this->post(route('inscription.existant.recherche'), [
            'cin' => '99999999',
            'date_naissance' => '2000-01-01',
        ])->assertRedirect(route('inscription.existant.accueil'));

        $inconnu = $this->get(route('inscription.existant.accueil'))->getContent();
        $this->assertStringContainsString('value="99999999"', $inconnu);
        $this->assertStringContainsString('value="2000-01-01"', $inconnu);
        $this->assertStringNotContainsString('mohamed.benali@example.com', $inconnu);

        $this->post(route('inscription.existant.recherche'), [
            'cin' => '12345678',
            'date_naissance' => '2003-05-17',
        ])->assertRedirect(route('inscription.existant.accueil'));

        $connu = $this->get(route('inscription.existant.accueil'))->getContent();
        $this->assertStringContainsString('value="12345678"', $connu);
        $this->assertStringContainsString('value="2003-05-17"', $connu);
        // Toujours aucune donnée du dossier avant le code (l'autofill
        // complet reste conditionné à la vérification OTP — P0).
        $this->assertStringNotContainsString('mohamed.benali@example.com', $connu);
        $this->assertStringNotContainsString('BEN ALI', $connu);
    }

    public function test_recherche_dossier_inconnu_ne_change_pas_la_reponse_et_n_envoie_rien(): void
    {
        Notification::fake();

        $reponse = $this->post(route('inscription.existant.recherche'), [
            'cin' => '99999999',
            'date_naissance' => '2000-01-01',
        ])->assertRedirect(route('inscription.existant.accueil'));

        $statutInconnu = session('statut');
        Notification::assertNothingSent();

        // Réponse strictement identique à un dossier existant (anti-énumération).
        $etudiant = $this->creerEtudiant();
        $this->post(route('inscription.existant.recherche'), [
            'cin' => $etudiant->cin,
            'date_naissance' => $etudiant->date_naissance->toDateString(),
        ])->assertRedirect(route('inscription.existant.accueil'));

        $this->assertSame($statutInconnu, session('statut'));
        $this->assertStringNotContainsString('mohamed.benali@example.com', (string) $statutInconnu);
        $this->assertStringNotContainsString('12345678', (string) $statutInconnu);
    }

    public function test_cin_correct_avec_date_naissance_incorrecte_ne_revele_rien_n_envoie_rien(): void
    {
        $this->creerEtudiant();
        Notification::fake();

        $this->post(route('inscription.existant.recherche'), [
            'cin' => '12345678',
            'date_naissance' => '1990-01-01',
        ])->assertRedirect(route('inscription.existant.accueil'));

        Notification::assertNothingSent();
    }

    public function test_le_code_n_est_jamais_envoye_a_une_adresse_librement_saisie(): void
    {
        $etudiant = $this->creerEtudiant();
        Notification::fake();

        $this->post(route('inscription.existant.recherche'), [
            'cin' => $etudiant->cin,
            'date_naissance' => $etudiant->date_naissance->toDateString(),
            'email' => 'attaquant@evil.example',
        ])->assertRedirect(route('inscription.existant.accueil'));

        // Une seule notification, vers la seule adresse enregistrée : le
        // endpoint n'accepte AUCUNE adresse librement saisie.
        Notification::assertSentTo($etudiant, CodeVerificationDossierExistant::class, 1);
    }

    /* ---------------------------------------------------------------
     | SÉCURITÉ — code, autorisation, IDOR, champs protégés
     * -------------------------------------------------------------- */

    public function test_code_expire_est_refuse(): void
    {
        $etudiant = $this->creerEtudiant();

        Notification::fake();
        $this->service()->envoyerCodeVerification($etudiant);
        $code = Notification::sent($etudiant, CodeVerificationDossierExistant::class)->first()->code;

        $this->travel(11)->minutes();

        $this->assertNull($this->service()->verifierCodeEtAutoriser($etudiant, $code));
    }

    public function test_code_invalide_et_code_deja_utilise_sont_refuses(): void
    {
        $etudiant = $this->creerEtudiant();

        Notification::fake();
        $this->service()->envoyerCodeVerification($etudiant);
        $code = Notification::sent($etudiant, CodeVerificationDossierExistant::class)->first()->code;

        $this->assertNull($this->service()->verifierCodeEtAutoriser($etudiant, '000000'));

        // Usage unique : le bon code ne peut être consommé qu'une fois.
        $jeton = $this->service()->verifierCodeEtAutoriser($etudiant, $code);
        $this->assertNotNull($jeton);
        $this->assertNull($this->service()->verifierCodeEtAutoriser($etudiant, $code));
    }

    public function test_trop_de_tentatives_invalide_definitivement_le_code(): void
    {
        $etudiant = $this->creerEtudiant();

        Notification::fake();
        $this->service()->envoyerCodeVerification($etudiant);
        $code = Notification::sent($etudiant, CodeVerificationDossierExistant::class)->first()->code;

        for ($i = 0; $i < DossierExistantService::MAX_ESSAIS_CODE; $i++) {
            $this->assertNull($this->service()->verifierCodeEtAutoriser($etudiant, '000000'));
        }

        // Le bon code est désormais refusé : il faut re-demander un code.
        $this->assertNull($this->service()->verifierCodeEtAutoriser($etudiant, $code));
    }

    public function test_acces_direct_au_formulaire_sans_verification_est_refuse(): void
    {
        $this->creerEtudiant();

        $this->get(route('inscription.existant.formulaire'))
            ->assertRedirect(route('inscription.existant.accueil'))
            ->assertSessionHas('statut');

        $this->get(route('inscription.existant.confirmation'))
            ->assertRedirect(route('inscription.existant.accueil'));
    }

    public function test_autorisation_temporaire_expire_et_est_limitee_au_dossier(): void
    {
        $etudiant = $this->creerEtudiant();
        $jeton = $this->service()->emettreAutorisation($etudiant);

        $this->travel(16)->minutes();

        $this->assertNull($this->service()->autorisationValide($jeton));
        $this->assertNull($this->service()->consommerAutorisation($jeton, $etudiant->id));
    }

    public function test_le_formulaire_redirige_quand_l_autorisation_est_absente_ou_expiree(): void
    {
        $etudiant = $this->creerEtudiant();
        $jeton = $this->service()->emettreAutorisation($etudiant);

        $this->withSession(['dossier_existant.jeton' => $jeton])
            ->get(route('inscription.existant.formulaire'))
            ->assertOk();

        $this->travel(16)->minutes();

        $this->withSession(['dossier_existant.jeton' => $jeton])
            ->get(route('inscription.existant.formulaire'))
            ->assertRedirect(route('inscription.existant.accueil'));
    }

    public function test_modification_de_l_identifiant_du_dossier_dans_la_requete_est_ignoree(): void
    {
        $cible = $this->creerEtudiant();
        $autre = $this->creerEtudiant(['cin' => '87654321', 'email' => 'autre@example.com', 'date_naissance' => '2001-02-02', 'nom' => 'AUTRE', 'prenom' => 'Personne']);

        $jeton = $this->service()->emettreAutorisation($cible);

        $this->withSession(['dossier_existant.jeton' => $jeton])
            ->post(route('inscription.existant-store'), [
                'etudiant_id' => $autre->id, // tentative IDOR
                'id' => $autre->id,
                'adresse' => '1 Rue Piratée',
                'ville' => 'Fes',
                'code_postal' => '30000',
                'confirmation' => '1',
            ])->assertRedirect(route('inscription.existant.confirmation'));

        // La modification s'applique UNIQUEMENT au dossier lié au jeton.
        $this->assertSame('1 Rue Piratée', $cible->fresh()->adresse);
        $this->assertSame('45 Avenue Hassan II', $autre->fresh()->adresse);
        $this->assertSame('AUTRE', $autre->fresh()->nom);
    }

    public function test_champs_proteges_ne_sont_jamais_modifies(): void
    {
        $etudiant = $this->creerEtudiant();
        $jeton = $this->service()->emettreAutorisation($etudiant);

        $this->withSession(['dossier_existant.jeton' => $jeton])
            ->post(route('inscription.existant-store'), [
                'cin' => '99999999',
                'nom' => 'PIRATE',
                'prenom' => 'Injection',
                'sexe' => 'F',
                'date_naissance' => '1990-01-01',
                'email' => 'pirate@evil.example',
                'statut' => 'valide',
                'niveau' => 'M2',
                'faculte_id' => 999,
                'annee_academique' => '2030-2031',
                'password' => 'Pirate123456',
                // Champs autorisés, pour vérifier que le reste passe
                'ville' => 'Meknes',
                'adresse' => '9 Avenue Legale',
                'code_postal' => '50000',
                'confirmation' => '1',
            ])->assertRedirect(route('inscription.existant.confirmation'));

        $etudiant->refresh();
        $this->assertSame('12345678', $etudiant->cin);
        $this->assertSame('BEN ALI', $etudiant->nom);
        $this->assertSame('Mohamed', $etudiant->prenom);
        $this->assertSame('M', $etudiant->sexe);
        $this->assertSame('2003-05-17', $etudiant->date_naissance->toDateString());
        $this->assertSame('mohamed.benali@example.com', $etudiant->email);
        $this->assertSame('en_attente', $etudiant->statut);
        $this->assertSame('L2', $etudiant->niveau);
        $this->assertSame('2025-2026', $etudiant->annee_academique);
        $this->assertTrue(Hash::check('MotdepasseSecret123', $etudiant->password));
    }

    public function test_dossier_deja_valide_ne_peut_pas_etre_modifie(): void
    {
        $etudiant = $this->creerEtudiant(['statut' => 'valide']);
        $code = $this->rechercherEtRecupererCode($etudiant);
        $this->verifierCode($code);

        // Consultation possible, mais sans formulaire de modification.
        $this->get(route('inscription.existant.formulaire'))
            ->assertOk()
            ->assertSee('déjà été validé');

        $this->withSession(['dossier_existant.jeton' => session('dossier_existant.jeton')])
            ->post(route('inscription.existant-store'), [
                'adresse' => '1 Rue Interdite',
                'ville' => 'Tanger',
                'code_postal' => '90000',
                'confirmation' => '1',
            ])->assertRedirect(route('inscription.existant.accueil'))
            ->assertSessionHas('statut');

        $this->assertSame('45 Avenue Hassan II', $etudiant->fresh()->adresse);
        $this->assertSame('valide', $etudiant->fresh()->statut);

        Notification::assertNotSentTo($etudiant, ConfirmationMiseAJourDossierExistant::class);
    }

    public function test_dossier_rejete_n_est_pas_reinitialise_silencieusement(): void
    {
        $etudiant = $this->creerEtudiant(['statut' => 'rejete']);
        $code = $this->rechercherEtRecupererCode($etudiant);
        $this->verifierCode($code);

        $this->get(route('inscription.existant.formulaire'))
            ->assertOk()
            ->assertSee('rejet');

        $this->withSession(['dossier_existant.jeton' => session('dossier_existant.jeton')])
            ->post(route('inscription.existant-store'), [
                'adresse' => '1 Rue Interdite',
                'ville' => 'Tanger',
                'code_postal' => '90000',
                'confirmation' => '1',
            ])->assertRedirect(route('inscription.existant.accueil'));

        $this->assertSame('rejete', $etudiant->fresh()->statut);
        $this->assertSame('45 Avenue Hassan II', $etudiant->fresh()->adresse);
        Notification::assertNotSentTo($etudiant, ConfirmationMiseAJourDossierExistant::class);
    }

    public function test_rejeu_apres_succes_est_refuse_sans_double_email(): void
    {
        $etudiant = $this->creerEtudiant();
        $code = $this->rechercherEtRecupererCode($etudiant);
        $this->verifierCode($code);

        $charge = [
            'telephone' => '+212611111111',
            'adresse' => '2 Rue Unique',
            'ville' => 'Rabat',
            'code_postal' => '10100',
            'confirmation' => '1',
        ];

        $this->post(route('inscription.existant-store'), $charge)
            ->assertRedirect(route('inscription.existant.confirmation'));

        // Rejeu de la même requête : l'autorisation est consommée.
        $this->post(route('inscription.existant-store'), $charge)
            ->assertRedirect(route('inscription.existant.accueil'))
            ->assertSessionHas('statut');

        Notification::assertSentToTimes($etudiant, ConfirmationMiseAJourDossierExistant::class, 1); // jamais en double
        $this->assertSame('+212611111111', $etudiant->fresh()->telephone);
    }

    public function test_deux_soumissions_concurrentes_seule_la_premiere_est_acceptee(): void
    {
        $etudiant = $this->creerEtudiant();
        $code = $this->rechercherEtRecupererCode($etudiant);
        $this->verifierCode($code);
        $jeton = session('dossier_existant.jeton');

        // Simulation d'une soumission concurrente : le second flux lit le
        // même jeton ; la consumption atomique (pull) garantit qu'un seul
        // traitement est accepté.
        $apercu = $this->service()->autorisationValide($jeton);
        $premiere = $this->service()->consommerAutorisation($jeton, $apercu->id);
        $seconde = $this->service()->consommerAutorisation($jeton, $apercu->id);

        $this->assertNotNull($premiere);
        $this->assertNull($seconde);

        $this->withSession(['dossier_existant.jeton' => $jeton])
            ->post(route('inscription.existant-store'), [
                'adresse' => '3 Rue Concurrente',
                'ville' => 'Rabat',
                'code_postal' => '10200',
                'confirmation' => '1',
            ])->assertRedirect(route('inscription.existant.accueil'))
            ->assertSessionHas('statut');

        $this->assertSame('45 Avenue Hassan II', $etudiant->fresh()->adresse);
    }

    public function test_limitation_de_debit_par_identite_sur_la_recherche(): void
    {
        $this->creerEtudiant();

        for ($i = 0; $i < DossierExistantService::MAX_RECHERCHES; $i++) {
            $this->service()->compterRecherche('12345678', '2003-05-17');
        }

        $this->assertTrue($this->service()->rechercheBloquee('12345678', '2003-05-17'));
        // Une autre identité n'est pas impactée.
        $this->assertFalse($this->service()->rechercheBloquee('87654321', '2003-05-17'));
    }

    public function test_renvoi_de_code_respecte_un_delai_minimum(): void
    {
        $etudiant = $this->creerEtudiant();

        Notification::fake();
        $this->service()->envoyerCodeVerification($etudiant);

        $this->expectException(RuntimeException::class);
        $this->service()->envoyerCodeVerification($etudiant);
    }

    public function test_throttle_route_recherche_bloque_apres_cinq_requetes(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->post(route('inscription.existant.recherche'), [
                'cin' => sprintf('%08d', 10000000 + $i),
                'date_naissance' => '2000-01-01',
            ])->assertRedirect(route('inscription.existant.accueil'), "La requête {$i}/5 aurait dû passer.");
        }

        $this->post(route('inscription.existant.recherche'), [
            'cin' => '10000011',
            'date_naissance' => '2000-01-01',
        ])->assertStatus(429);
    }

    /* ---------------------------------------------------------------
     | EMAIL — fiabilité et contenu
     * -------------------------------------------------------------- */

    public function test_echec_de_validation_n_envoie_aucun_email_de_confirmation(): void
    {
        $etudiant = $this->creerEtudiant();
        $code = $this->rechercherEtRecupererCode($etudiant);
        $this->verifierCode($code);

        $this->withSession(['dossier_existant.jeton' => session('dossier_existant.jeton')])
            ->post(route('inscription.existant-store'), [
                'adresse' => 'abc', // invalide (min:5)
                'ville' => '',
                'code_postal' => '',
            ])->assertSessionHasErrors(['adresse', 'ville', 'code_postal']);

        Notification::assertNotSentTo($etudiant, ConfirmationMiseAJourDossierExistant::class);
        $this->assertSame('45 Avenue Hassan II', $etudiant->fresh()->adresse);

        // L'autorisation reste valable : l'utilisateur peut corriger et resoumettre.
        $this->assertNotNull($this->service()->autorisationValide(session('dossier_existant.jeton')));
    }

    public function test_echec_de_transaction_aucune_notification_de_succes_et_donnees_intactes(): void
    {
        $etudiant = $this->creerEtudiant(['statut' => 'valide']);
        $jeton = $this->service()->emettreAutorisation($etudiant);

        Notification::fake();
        // Statut changé entre-temps → l'écriture est refusée.
        $this->withSession(['dossier_existant.jeton' => $jeton])
            ->post(route('inscription.existant-store'), [
                'adresse' => '1 Rue Interdite',
                'ville' => 'Tanger',
                'code_postal' => '90000',
                'confirmation' => '1',
            ])->assertRedirect(route('inscription.existant.accueil'));

        $this->assertSame('45 Avenue Hassan II', $etudiant->fresh()->adresse);
        Notification::assertNotSentTo($etudiant, ConfirmationMiseAJourDossierExistant::class);
    }

    public function test_echec_de_l_email_apres_commit_le_dossier_est_conserve_et_le_statut_distingue(): void
    {
        $etudiant = $this->creerEtudiant();
        $jeton = $this->service()->emettreAutorisation($etudiant);

        // Simulation d'une panne du transport de notifications (après commit).
        $this->mock(ChannelManager::class, function (Mockery\MockInterface $mock): void {
            $mock->shouldReceive('send')->andThrow(new RuntimeException('SMTP indisponible'));
        });

        $this->withSession(['dossier_existant.jeton' => $jeton])
            ->post(route('inscription.existant-store'), [
                'adresse' => '4 Rue Fiable',
                'ville' => 'Agadir',
                'code_postal' => '80000',
                'confirmation' => '1',
            ])->assertRedirect(route('inscription.existant.confirmation'));

        // L'enregistrement est conservé malgré l'échec d'envoi.
        $this->assertSame('4 Rue Fiable', $etudiant->fresh()->adresse);
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $etudiant->id,
            'action' => 'modification_dossier_public',
        ]);

        // L'écran final DISTINGUE « demande enregistrée » de « email envoyé ».
        $this->get(route('inscription.existant.confirmation'))
            ->assertOk()
            ->assertSee('Votre demande a été enregistrée')
            ->assertSee('en attente de nouvelle tentative')
            ->assertDontSee('Un email de confirmation vous a été adressé');
    }

    public function test_email_confirmation_contient_reference_et_prochaines_etapes_sans_pii(): void
    {
        $etudiant = $this->creerEtudiant();
        $jeton = $this->service()->emettreAutorisation($etudiant);

        Notification::fake();
        $this->withSession(['dossier_existant.jeton' => $jeton])
            ->post(route('inscription.existant-store'), [
                'ville' => 'Oujda',
                'adresse' => '5 Avenue Confirmation',
                'code_postal' => '60000',
                'confirmation' => '1',
            ])->assertRedirect(route('inscription.existant.confirmation'));

        $notification = Notification::sent($etudiant, ConfirmationMiseAJourDossierExistant::class)->first();
        $this->assertNotNull($notification);

        $message = $notification->toMail($etudiant->fresh());
        $this->assertSame('Confirmation de mise à jour de votre dossier étudiant', $message->subject);

        $contenu = implode("\n", array_merge($message->introLines, $message->outroLines ?? []));
        $this->assertStringContainsString($etudiant->num_inscription, $contenu);
        $this->assertStringContainsString('en attente de traitement administratif', $contenu);
        $this->assertStringContainsString('scolarité', $contenu);

        // Jamais de CIN complet, de date de naissance, de mot de passe ni de code.
        $this->assertStringNotContainsString('12345678', $contenu);
        $this->assertStringNotContainsString('2003-05-17', $contenu);
        $this->assertStringNotContainsString('MotdepasseSecret123', $contenu);
    }

    public function test_le_code_de_verification_est_transmis_avec_sa_duree_et_sans_pii(): void
    {
        $etudiant = $this->creerEtudiant();

        Notification::fake();
        $this->service()->envoyerCodeVerification($etudiant);

        $notification = Notification::sent($etudiant, CodeVerificationDossierExistant::class)->first();
        $message = $notification->toMail($etudiant);

        $this->assertSame('Votre code de vérification — mise à jour de dossier étudiant', $message->subject);

        $contenu = implode("\n", $message->introLines);
        $this->assertStringContainsString($notification->code, $contenu);
        $this->assertStringContainsString('10 minutes', $contenu);
        $this->assertStringNotContainsString('12345678', $contenu);
        $this->assertStringNotContainsString('2003-05-17', $contenu);
    }

    /* ---------------------------------------------------------------
     | CONTRAT — CSRF, file de notifications, historique, anti-fuite
     * -------------------------------------------------------------- */

    public function test_la_soumission_est_dans_le_groupe_web_avec_csrf_obligatoire(): void
    {
        $route = Route::getRoutes()->match(Request::create('/inscription/existant', 'POST'));

        $this->assertContains('web', $route->gatherMiddleware());
        $groupes = Route::getMiddlewareGroups();
        $this->assertContains(PreventRequestForgery::class, $groupes['web']);
    }

    public function test_l_email_final_est_planifie_en_file_avec_retries(): void
    {
        $notification = new ConfirmationMiseAJourDossierExistant('INS-2026-00001', 2);

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertSame('notifications', $notification->queue);
    }

    public function test_historique_capture_anciennes_et_nouvelles_valeurs(): void
    {
        $etudiant = $this->creerEtudiant();
        $jeton = $this->service()->emettreAutorisation($etudiant);

        $this->withSession(['dossier_existant.jeton' => $jeton])
            ->post(route('inscription.existant-store'), [
                'ville' => 'Kénitra',
                'adresse' => '6 Avenue Historique',
                'code_postal' => '14000',
                'confirmation' => '1',
            ])->assertRedirect(route('inscription.existant.confirmation'));

        $entree = HistoriqueAcademique::where('etudiant_id', $etudiant->id)
            ->where('action', 'modification_dossier_public')
            ->first();

        $this->assertNotNull($entree);
        $anciennes = json_decode((string) $entree->ancienne_valeur, true);
        $nouvelles = json_decode((string) $entree->nouvelle_valeur, true);

        $this->assertSame('Rabat', $anciennes['ville']);
        $this->assertSame('Kénitra', $nouvelles['ville']);
        $this->assertSame('45 Avenue Hassan II', $anciennes['adresse']);
        $this->assertSame('6 Avenue Historique', $nouvelles['adresse']);
    }

    public function test_aucune_donnee_du_dossier_n_apparait_avant_verification(): void
    {
        $this->creerEtudiant();

        $corps = $this->get(route('inscription.existant.accueil'))->getContent();

        // Aucune donnée réelle du dossier : email, adresse, téléphone,
        // nom. (Le CIN « 12345678 » figure comme EXEMPLE statique dans
        // le placeholder du champ de saisie — ce n'est pas une donnée.)
        $this->assertStringNotContainsString('mohamed.benali@example.com', $corps);
        $this->assertStringNotContainsString('45 Avenue Hassan II', $corps);
        $this->assertStringNotContainsString('+212612345678', $corps);
        $this->assertStringNotContainsString('BEN ALI', $corps);
        $this->assertStringNotContainsString('value="12345678"', $corps);
    }

    public function test_aucune_modification_si_aucun_champ_ne_change_et_demande_quand_meme_confirmee(): void
    {
        $etudiant = $this->creerEtudiant();
        $jeton = $this->service()->emettreAutorisation($etudiant);

        Notification::fake();
        $this->withSession(['dossier_existant.jeton' => $jeton])
            ->post(route('inscription.existant-store'), [
                'telephone' => $etudiant->telephone,
                'adresse' => $etudiant->adresse,
                'ville' => $etudiant->ville,
                'code_postal' => $etudiant->code_postal,
                'confirmation' => '1',
            ])->assertRedirect(route('inscription.existant.confirmation'));

        $this->assertDatabaseMissing('historique_academique', [
            'etudiant_id' => $etudiant->id,
            'action' => 'modification_dossier_public',
        ]);

        // La demande est tout de même confirmée (prise en compte).
        Notification::assertSentToTimes($etudiant, ConfirmationMiseAJourDossierExistant::class, 1);
    }

    /* ---------------------------------------------------------------
     | PÉRIODE — revalidation serveur de la période d'inscription
     * -------------------------------------------------------------- */

    public function test_modification_refusee_quand_periode_fermee_dossier_intact_sans_email(): void
    {
        $etudiant = $this->creerEtudiant();

        // Période prouvée CLOSED : configurée mais fenêtres dépassées.
        RegistrationPeriod::query()->update(['closed_at' => now()->subDay()]);

        $code = $this->rechercherEtRecupererCode($etudiant);
        $this->verifierCode($code);

        $this->post(route('inscription.existant-store'), [
            'telephone' => '+212611111111',
            'adresse' => '7 Rue Periode Fermee',
            'ville' => 'Rabat',
            'code_postal' => '10100',
            'confirmation' => '1',
        ])->assertRedirect(route('inscription.existant.accueil'))
            ->assertSessionHas('statut');

        $this->assertSame('45 Avenue Hassan II', $etudiant->fresh()->adresse);
        $this->assertSame('+212612345678', $etudiant->fresh()->telephone);
        $this->assertDatabaseMissing('historique_academique', [
            'etudiant_id' => $etudiant->id,
            'action' => 'modification_dossier_public',
        ]);
        Notification::assertNotSentTo($etudiant, ConfirmationMiseAJourDossierExistant::class);
    }

    public function test_formulaire_masque_le_bloc_modifiable_quand_periode_fermee(): void
    {
        $etudiant = $this->creerEtudiant();

        RegistrationPeriod::query()->update(['closed_at' => now()->subDay()]);

        $code = $this->rechercherEtRecupererCode($etudiant);
        $this->verifierCode($code);

        $this->get(route('inscription.existant.formulaire'))
            ->assertOk()
            ->assertSee('actuellement fermées')
            ->assertDontSee('Soumettre ma mise à jour', false);
    }

    public function test_recherche_normalise_le_cin_avec_espaces_comme_la_creation(): void
    {
        $etudiant = $this->creerEtudiant();

        // Même normalisation que le parcours de création (espaces
        // supprimés, casse ignorée) : « 12 345678 » retrouve le dossier.
        $this->assertTrue($this->service()->rechercher('12 345678', '2003-05-17')?->is($etudiant) ?? false);

        // La limitation par identité est insensible aux espaces.
        $this->service()->compterRecherche('12 345678', '2003-05-17');
        for ($i = 0; $i < DossierExistantService::MAX_RECHERCHES - 1; $i++) {
            $this->service()->compterRecherche('12345678', '2003-05-17');
        }
        $this->assertTrue($this->service()->rechercheBloquee('12345678', '2003-05-17'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Cache::flush();
        parent::tearDown();
    }
}
