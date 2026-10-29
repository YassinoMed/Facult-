<?php

namespace Tests\Feature\Security;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\PortalPasswordResetToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * LOOP-07 — Profil et sécurité du compte étudiant.
 *
 * Vérifie :
 * 1. Consultation du profil : affichage conforme des données personnelles sans fuite de secrets (hash, remember_token).
 * 2. Protection contre les accès non authentifiés (redirection vers connexion par EnsureStudent).
 * 3. Page de modification des coordonnées : affichage des valeurs actuelles, email en lecture seule.
 * 4. Modification des coordonnées : mise à jour réussie des champs autorisés (téléphone, adresse, ville, code postal).
 * 5. Validation stricte des coordonnées (formats, longueurs min/max, regex téléphone, code postal obligatoire).
 * 6. Protection anti-détournement d'identifiant : interdiction de changer l'adresse email en direct depuis le profil.
 * 7. Unicité de l'adresse email : rejet de toute tentative d'usurper l'email d'un autre étudiant.
 * 8. Protection contre le mass-assignment et anti-tampering : immutabilité absolue des champs verrouillés
 *    (cin, num_inscription, statut, faculte_id, niveau, date_naissance, password, mot_de_passe_version).
 * 9. Changement de mot de passe sécurisé : vérification du mot de passe actuel par bcrypt, confirmation et politique de complexité.
 * 10. Rejet du changement de mot de passe si mot de passe actuel erroné, confirmation discordante ou nouveau mot de passe identique.
 * 11. Rejet du changement si complexité insuffisante (< 10 caractères, absence de chiffres/lettres).
 * 12. Révocation des sessions concurrentes : incrémentation atomique de mot_de_passe_version invalidant immédiatement les autres sessions.
 * 13. Neutralisation des jetons de réinitialisation de mot de passe actifs dès changement du mot de passe.
 * 14. Déconnexion explicite sécurisée : invalidation de session, régénération CSRF et protection contre la réutilisation de session.
 * 15. Cloisonnement strict (anti-IDOR) : isolation absolue entre profils étudiants par session serveur.
 * 16. Protection contre les attaques en force brute via limitation de débit (throttling sur routes profil et mot de passe).
 */
class Loop07ProfilSecuriteCompteTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026',
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30',
            'active' => true,
        ]);

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences de Rabat',
            'code' => 'FSR',
            'active' => true,
        ]);
    }

    private function creerEtudiant(array $surcharge = []): Etudiant
    {
        $etudiant = Etudiant::create(array_merge([
            'cin' => 'AB123456',
            'nom' => 'Alami',
            'prenom' => 'Sara',
            'email' => 'sara.alami@example.com',
            'telephone' => '+212612345678',
            'date_naissance' => '2003-05-17',
            'sexe' => 'F',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => '15 Avenue Mohammed V',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2021',
            'mention_bac' => 'Bien',
            'password' => 'ActuelPass123!',
        ], $surcharge));

        if (empty($etudiant->num_inscription)) {
            $etudiant->forceFill(['num_inscription' => 'INS-2026-00001'])->save();
        }

        return $etudiant->fresh();
    }

    public function test_consultation_profil_refuse_utilisateur_non_authentifie(): void
    {
        $this->get(route('etudiant.profil.show'))
            ->assertRedirect(route('etudiant.connexion.show'))
            ->assertSessionHas('error');

        $this->get(route('etudiant.profil.edit'))
            ->assertRedirect(route('etudiant.connexion.show'))
            ->assertSessionHas('error');
    }

    public function test_consultation_profil_affiche_donnees_legitimes_sans_fuite_de_secrets(): void
    {
        $etudiant = $this->creerEtudiant();

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->get(route('etudiant.profil.show'));

        $response->assertOk();

        // Données d'identité légitimes affichées
        $response->assertSee('Sara');
        $response->assertSee('Alami');
        $response->assertSee($etudiant->cin);
        $response->assertSee($etudiant->num_inscription);
        $response->assertSee('Faculté des Sciences de Rabat');
        $response->assertSee('sara.alami@example.com');
        $response->assertSee('15 Avenue Mohammed V');
        $response->assertSee('Rabat');
        $response->assertSee('10000');

        // Aucun secret (hash de mot de passe, token interne, sel bcrypt) ne doit fuiter dans le DOM
        $html = $response->getContent();
        $this->assertFalse(str_contains($html, $etudiant->password), 'Le hash du mot de passe ne doit jamais apparaître dans le code HTML.');
        $this->assertFalse(str_contains($html, 'ActuelPass123!'), 'Le mot de passe en clair ne doit jamais apparaître dans le code HTML.');
        $this->assertFalse(str_contains($html, '$2y$'), 'Aucune empreinte bcrypt ne doit figurer dans le rendu HTML.');
    }

    public function test_page_modification_coordonnees_affiche_donnees_actuelles_et_champs_verrouilles(): void
    {
        $etudiant = $this->creerEtudiant();

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->get(route('etudiant.profil.edit'));

        $response->assertOk();
        $response->assertSee('Modifier mes coordonnées');
        $response->assertSee($etudiant->num_inscription);
        $response->assertSee('value="sara.alami@example.com"', false);
        $response->assertSee('value="+212612345678"', false);
        $response->assertSee('15 Avenue Mohammed V');
        $response->assertSee('value="Rabat"', false);
        $response->assertSee('value="10000"', false);
        $response->assertSee('readonly', false);
    }

    public function test_mise_a_jour_coordonnees_autorisees_reussit_avec_message_flash(): void
    {
        $etudiant = $this->creerEtudiant();

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->patch(route('etudiant.profil.update'), [
            'email' => $etudiant->email,
            'telephone' => '+212699887766',
            'adresse' => '42 Boulevard d\'Anfa, Apt 5',
            'ville' => 'Casablanca',
            'code_postal' => '20050',
        ]);

        $response->assertRedirect(route('etudiant.profil.show'));
        $response->assertSessionHas('success', 'Vos coordonnées ont été mises à jour avec succès.');

        $etudiant->refresh();
        $this->assertSame('+212699887766', $etudiant->telephone);
        $this->assertSame('42 Boulevard d\'Anfa, Apt 5', $etudiant->adresse);
        $this->assertSame('Casablanca', $etudiant->ville);
        $this->assertSame('20050', $etudiant->code_postal);
    }

    public function test_mise_a_jour_coordonnees_valide_strictement_formats_et_longueurs(): void
    {
        $etudiant = $this->creerEtudiant();

        // 1. Adresse trop courte (< 5 caractères)
        $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->patch(route('etudiant.profil.update'), [
            'email' => $etudiant->email,
            'telephone' => '+212612345678',
            'adresse' => 'Rue',
            'ville' => 'Rabat',
            'code_postal' => '10000',
        ])->assertSessionHasErrors(['adresse']);

        // 2. Ville trop courte (< 2 caractères)
        $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->patch(route('etudiant.profil.update'), [
            'email' => $etudiant->email,
            'telephone' => '+212612345678',
            'adresse' => '15 Avenue Mohammed V',
            'ville' => 'R',
            'code_postal' => '10000',
        ])->assertSessionHasErrors(['ville']);

        // 3. Code postal manquant
        $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->patch(route('etudiant.profil.update'), [
            'email' => $etudiant->email,
            'telephone' => '+212612345678',
            'adresse' => '15 Avenue Mohammed V',
            'ville' => 'Rabat',
            'code_postal' => '',
        ])->assertSessionHasErrors(['code_postal']);

        // 4. Numéro de téléphone invalide (lettres ou trop court)
        $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->patch(route('etudiant.profil.update'), [
            'email' => $etudiant->email,
            'telephone' => 'telephone-invalide',
            'adresse' => '15 Avenue Mohammed V',
            'ville' => 'Rabat',
            'code_postal' => '10000',
        ])->assertSessionHasErrors(['telephone']);

        // Vérifier que la base est restée inchangée
        $etudiant->refresh();
        $this->assertSame('15 Avenue Mohammed V', $etudiant->adresse);
        $this->assertSame('Rabat', $etudiant->ville);
    }

    public function test_tentative_changement_email_en_direct_est_bloquee_avec_explication_parcours_verifie(): void
    {
        $etudiant = $this->creerEtudiant();

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->patch(route('etudiant.profil.update'), [
            'email' => 'nouveau.pirate@example.com',
            'telephone' => '+212612345678',
            'adresse' => '15 Avenue Mohammed V',
            'ville' => 'Rabat',
            'code_postal' => '10000',
        ]);

        $response->assertSessionHasErrors(['email']);

        // L'email en base doit rester rigoureusement identique
        $this->assertSame('sara.alami@example.com', $etudiant->fresh()->email);
    }

    public function test_tentative_changement_email_vers_email_existant_autre_etudiant_est_bloquee(): void
    {
        $etudiant1 = $this->creerEtudiant(['email' => 'sara.alami@example.com', 'cin' => 'AB111111']);
        $this->creerEtudiant(['email' => 'autre.etudiant@example.com', 'cin' => 'CD222222']);

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant1->id,
            'etudiant_mdp_version' => (int) $etudiant1->mot_de_passe_version,
        ])->patch(route('etudiant.profil.update'), [
            'email' => 'autre.etudiant@example.com',
            'telephone' => '+212612345678',
            'adresse' => '15 Avenue Mohammed V',
            'ville' => 'Rabat',
            'code_postal' => '10000',
        ]);

        $response->assertSessionHasErrors(['email']);
        $this->assertSame('sara.alami@example.com', $etudiant1->fresh()->email);
    }

    public function test_champs_verrouilles_et_identite_strictement_immuables_contre_mass_assignment(): void
    {
        $etudiant = $this->creerEtudiant([
            'statut' => 'en_attente',
            'cin' => 'AB123456',
            'niveau' => 'L1',
            'date_naissance' => '2003-05-17',
        ]);

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->patch(route('etudiant.profil.update'), [
            'email' => $etudiant->email,
            'telephone' => '+212612345678',
            'adresse' => '42 Boulevard d\'Anfa',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            // Tentative d'injection malveillante de champs protégés :
            'cin' => 'PIRATE99',
            'num_inscription' => 'INS-FAKE-99999',
            'statut' => 'valide',
            'faculte_id' => 999,
            'niveau' => 'Doctorat',
            'date_naissance' => '1980-01-01',
            'annee_academique' => '2030-2031',
            'password' => 'InjectedPass123!',
            'mot_de_passe_version' => 999,
        ]);

        $response->assertRedirect(route('etudiant.profil.show'));

        $etudiant->refresh();
        $this->assertSame('42 Boulevard d\'Anfa', $etudiant->adresse);
        $this->assertSame('Casablanca', $etudiant->ville);
        // Les champs protégés doivent impérativement conserver leur valeur initiale
        $this->assertSame('AB123456', $etudiant->cin);
        $this->assertSame('INS-2026-00001', $etudiant->num_inscription);
        $this->assertSame('en_attente', $etudiant->statut);
        $this->assertSame($this->faculte->id, $etudiant->faculte_id);
        $this->assertSame('L1', $etudiant->niveau);
        $this->assertSame('2003-05-17', $etudiant->date_naissance->toDateString());
        $this->assertSame(0, (int) $etudiant->mot_de_passe_version);
        $this->assertTrue(Hash::check('ActuelPass123!', $etudiant->password));
    }

    public function test_changement_mot_de_passe_reussit_avec_mot_de_passe_actuel_valide_et_confirmation(): void
    {
        $etudiant = $this->creerEtudiant();

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->patch(route('etudiant.profil.password'), [
            'current_password' => 'ActuelPass123!',
            'password' => 'NouveauPass456!',
            'password_confirmation' => 'NouveauPass456!',
        ]);

        $response->assertRedirect(route('etudiant.profil.show'));
        $response->assertSessionHas('success', 'Votre mot de passe a été modifié avec succès.');

        $etudiant->refresh();
        $this->assertTrue(Hash::check('NouveauPass456!', $etudiant->password));
        $this->assertFalse(Hash::check('ActuelPass123!', $etudiant->password));
    }

    public function test_changement_mot_de_passe_echoue_si_mot_de_passe_actuel_incorrect(): void
    {
        $etudiant = $this->creerEtudiant();

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->patch(route('etudiant.profil.password'), [
            'current_password' => 'FauxPassInvalide123!',
            'password' => 'NouveauPass456!',
            'password_confirmation' => 'NouveauPass456!',
        ]);

        $response->assertSessionHasErrors(['current_password']);

        $etudiant->refresh();
        $this->assertTrue(Hash::check('ActuelPass123!', $etudiant->password));
        $this->assertFalse(Hash::check('NouveauPass456!', $etudiant->password));
    }

    public function test_changement_mot_de_passe_echoue_si_confirmation_differente(): void
    {
        $etudiant = $this->creerEtudiant();

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->patch(route('etudiant.profil.password'), [
            'current_password' => 'ActuelPass123!',
            'password' => 'NouveauPass456!',
            'password_confirmation' => 'DiscordantPass789!',
        ]);

        $response->assertSessionHasErrors(['password']);
        $this->assertTrue(Hash::check('ActuelPass123!', $etudiant->fresh()->password));
    }

    public function test_changement_mot_de_passe_echoue_si_nouveau_mot_de_passe_identique_a_actuel(): void
    {
        $etudiant = $this->creerEtudiant();

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->patch(route('etudiant.profil.password'), [
            'current_password' => 'ActuelPass123!',
            'password' => 'ActuelPass123!',
            'password_confirmation' => 'ActuelPass123!',
        ]);

        $response->assertSessionHasErrors(['password']);
    }

    public function test_changement_mot_de_passe_echoue_si_complexite_insuffisante(): void
    {
        $etudiant = $this->creerEtudiant();

        // 1. Trop court (< 10 caractères)
        $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->patch(route('etudiant.profil.password'), [
            'current_password' => 'ActuelPass123!',
            'password' => 'Court1!',
            'password_confirmation' => 'Court1!',
        ])->assertSessionHasErrors(['password']);

        // 2. Sans chiffres
        $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->patch(route('etudiant.profil.password'), [
            'current_password' => 'ActuelPass123!',
            'password' => 'SeulementDesLettres!',
            'password_confirmation' => 'SeulementDesLettres!',
        ])->assertSessionHasErrors(['password']);
    }

    public function test_changement_mot_de_passe_incremente_mot_de_passe_version_et_invalide_sessions_concurrentes(): void
    {
        $etudiant = $this->creerEtudiant();
        $this->assertSame(0, (int) $etudiant->mot_de_passe_version);

        // Session A effectue le changement de mot de passe
        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => 0,
        ])->patch(route('etudiant.profil.password'), [
            'current_password' => 'ActuelPass123!',
            'password' => 'NouveauPass456!',
            'password_confirmation' => 'NouveauPass456!',
        ]);

        $response->assertRedirect(route('etudiant.profil.show'));

        $etudiant->refresh();
        // La version en base a été incrémentée à 1
        $this->assertSame(1, (int) $etudiant->mot_de_passe_version);

        // Une session concurrente B (ayant l'ancienne version 0 dans son cookie de session)
        // tente d'accéder au profil étudiant : elle doit être immédiatement invalidée par EnsureStudent
        $responseConcurrent = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => 0, // ancienne version
        ])->get(route('etudiant.profil.show'));

        $responseConcurrent->assertRedirect(route('etudiant.connexion.show'));
        $responseConcurrent->assertSessionHas('error');

        // La session active légitime avec la nouvelle version 1 accède sans encombre
        $responseValide = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => 1,
        ])->get(route('etudiant.profil.show'));

        $responseValide->assertOk();
    }

    public function test_changement_mot_de_passe_neutralise_jetons_de_reinitialisation_actifs(): void
    {
        $etudiant = $this->creerEtudiant();

        // Création d'un jeton actif de réinitialisation de mot de passe
        $token = PortalPasswordResetToken::create([
            'guard' => 'etudiant',
            'email' => $etudiant->email,
            'token_hash' => hash('sha256', 'jeton-secret-en-cours'),
            'expires_at' => now()->addMinutes(60),
            'used_at' => null,
        ]);

        $this->assertNull($token->used_at);

        // L'étudiant met à jour son mot de passe
        $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->patch(route('etudiant.profil.password'), [
            'current_password' => 'ActuelPass123!',
            'password' => 'NouveauPass456!',
            'password_confirmation' => 'NouveauPass456!',
        ])->assertRedirect(route('etudiant.profil.show'));

        // Le jeton actif doit avoir été neutralisé (used_at renseigné)
        $token->refresh();
        $this->assertNotNull($token->used_at, 'Le jeton de réinitialisation actif doit être invalidé après rotation du mot de passe.');
    }

    public function test_deconnexion_explicite_invalide_session_et_protege_contre_reutilisation(): void
    {
        $etudiant = $this->creerEtudiant();

        // L'étudiant est connecté et envoie une requête de déconnexion POST
        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->post(route('etudiant.connexion.logout'));

        $response->assertRedirect(route('etudiant.connexion.show'));
        $response->assertSessionHas('success');

        // Une requête suivante sans session réauthentifiée ne peut pas accéder au profil
        $this->get(route('etudiant.profil.show'))
            ->assertRedirect(route('etudiant.connexion.show'));
    }

    public function test_cloisonnement_strict_anti_idor_aucun_etudiant_ne_peut_acceder_ou_modifier_un_autre(): void
    {
        $etudiantA = $this->creerEtudiant([
            'cin' => 'AA111111',
            'email' => 'etudiant.a@example.com',
            'nom' => 'Alami',
            'prenom' => 'Ahmed',
            'adresse' => 'Adresse Initiale A',
            'ville' => 'Rabat',
        ]);

        $etudiantB = $this->creerEtudiant([
            'cin' => 'BB222222',
            'email' => 'etudiant.b@example.com',
            'nom' => 'Benjelloun',
            'prenom' => 'Bouchra',
            'adresse' => 'Adresse Intouchable B',
            'ville' => 'Casablanca',
        ]);

        // Étudiant A est connecté et tente d'injecter l'identifiant de B dans le payload
        $this->withSession([
            'etudiant_auth_id' => $etudiantA->id,
            'etudiant_mdp_version' => (int) $etudiantA->mot_de_passe_version,
        ])->patch(route('etudiant.profil.update'), [
            'id' => $etudiantB->id,
            'etudiant_id' => $etudiantB->id,
            'cin' => $etudiantB->cin,
            'email' => $etudiantA->email,
            'telephone' => '+212611111111',
            'adresse' => 'Nouvelle Adresse de A',
            'ville' => 'Tanger',
            'code_postal' => '90000',
        ])->assertRedirect(route('etudiant.profil.show'));

        // Vérification : A est mis à jour, mais B est STRICTEMENT intact
        $etudiantA->refresh();
        $this->assertSame('Nouvelle Adresse de A', $etudiantA->adresse);
        $this->assertSame('Tanger', $etudiantA->ville);

        $etudiantB->refresh();
        $this->assertSame('Adresse Intouchable B', $etudiantB->adresse);
        $this->assertSame('Casablanca', $etudiantB->ville);
        $this->assertSame('BB222222', $etudiantB->cin);

        // La consultation du profil de A n'expose aucune donnée de B
        $responseA = $this->withSession([
            'etudiant_auth_id' => $etudiantA->id,
            'etudiant_mdp_version' => (int) $etudiantA->mot_de_passe_version,
        ])->get(route('etudiant.profil.show'));

        $responseA->assertOk();
        $responseA->assertSee('Ahmed');
        $responseA->assertSee('Alami');
        $responseA->assertDontSee('Benjelloun');
        $responseA->assertDontSee('Bouchra');
        $responseA->assertDontSee('BB222222');
    }

    public function test_routes_profil_et_mot_de_passe_possedent_throttling_de_securite(): void
    {
        $routeUpdate = Route::getRoutes()->getByName('etudiant.profil.update');
        $this->assertNotNull($routeUpdate);
        $this->assertContains('throttle:10,1', $routeUpdate->middleware(), 'La mise à jour de profil doit être protégée par un throttle.');

        $routePassword = Route::getRoutes()->getByName('etudiant.profil.password');
        $this->assertNotNull($routePassword);
        $this->assertContains('throttle:5,1', $routePassword->middleware(), 'Le changement de mot de passe doit être protégé par un throttle strict.');
    }
}
