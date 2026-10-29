<?php

namespace Tests\Feature\Security;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\MensReference;
use App\Models\RegistrationPeriod;
use App\Notifications\CodeVerificationDossierExistant;
use App\Services\PeriodeInscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

/**
 * LOOP-05 — Inscription publique et reprise de dossier.
 *
 * Vérifie :
 * 1. Période d'inscription fermée (rejet propre côté serveur).
 * 2. Unicité stricte du CIN et de l'email (anti-doublons, anti-écrasement).
 * 3. Validation et fail-closed MENS (identification stricte).
 * 4. Idempotence de soumission (anti-replay, anti-double-clic).
 * 5. Reprise de dossier existant : recherche neutre anti-énumération.
 * 6. Reprise de dossier : vérification du code OTP (6 chiffres, 10 min) et rejet des codes erronés.
 * 7. Reprise de dossier : interdiction d'accès direct au formulaire sans autorisation vérifiée.
 * 8. Reprise de dossier : usage unique du jeton d'autorisation (consommé après enregistrement).
 * 9. Suivi public de dossier : double facteur obligatoire et absence de fuite PII.
 */
class Loop05InscriptionPubliqueRepriseDossierTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private RegistrationPeriod $periode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences de Tunis',
            'code' => 'FST',
            'description' => 'Campus El Manar',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026',
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30',
            'active' => true,
        ]);

        // Période ouverte par défaut
        $this->periode = app(PeriodeInscriptionService::class)->creerNormale([
            'annee_universitaire_id' => $this->annee->id,
            'opened_at' => now()->subDays(5)->format('Y-m-d H:i'),
            'closed_at' => now()->addDays(20)->format('Y-m-d H:i'),
        ], null);
    }

    private function donneesInscriptionValide(array $surcharges = []): array
    {
        return array_merge([
            'cin' => '08112233',
            'nom' => 'Ben Ammar',
            'prenom' => 'Sami',
            'email' => 'sami.benammar@etudiant.tn',
            'telephone' => '+216 71 123 456',
            'date_naissance' => '2004-05-14',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Avenue Habib Bourguiba, Tunis',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2023',
            'mention_bac' => 'Bien',
            'password' => 'MotDePasseFort@2026',
            'password_confirmation' => 'MotDePasseFort@2026',
            'confirmation' => '1',
        ], $surcharges);
    }

    private function creerReferenceMens(string $cin, string $dateNaissance, string $nom = 'Ben Ammar', string $prenom = 'Sami'): MensReference
    {
        return MensReference::create([
            'cin_normalise' => $cin,
            'cin_original' => $cin,
            'date_naissance' => $dateNaissance,
            'nom' => $nom,
            'prenom' => $prenom,
            'annee' => '2025-2026',
            'source_hash' => hash('sha256', $cin.$dateNaissance),
        ]);
    }

    // ── 1. Période d'inscription fermée ─────────────────────────────────────

    public function test_nouvelle_inscription_rejetee_quand_la_periode_est_fermee(): void
    {
        // On ferme la période
        $this->periode->update([
            'opened_at' => now()->subDays(30),
            'closed_at' => now()->subDay(),
        ]);

        $this->creerReferenceMens('08112233', '2004-05-14');

        $reponse = $this->post(route('inscription.store'), $this->donneesInscriptionValide());

        $reponse->assertSessionHasErrors(['periode']);
        $this->assertDatabaseMissing('etudiants', ['cin' => '08112233']);
    }

    public function test_nouvelle_inscription_rejetee_en_json_quand_periode_fermee(): void
    {
        $this->periode->update([
            'opened_at' => now()->subDays(30),
            'closed_at' => now()->subDay(),
        ]);

        $this->creerReferenceMens('08112233', '2004-05-14');

        $reponse = $this->postJson(route('inscription.store'), $this->donneesInscriptionValide());

        $reponse->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['periode']]);

        $this->assertDatabaseMissing('etudiants', ['cin' => '08112233']);
    }

    // ── 2. Unicité stricte du CIN et de l'email (anti-doublons) ──────────────

    public function test_tentative_inscription_avec_cin_deja_enregistre_est_rejetee_sans_ecrasement(): void
    {
        // Création d'un étudiant existant
        $existant = Etudiant::create([
            'cin' => '08112233',
            'num_inscription' => 'INS-2025-ORIGINAL',
            'nom' => 'Ben Ammar',
            'prenom' => 'Sami',
            'email' => 'sami.original@etudiant.tn',
            'date_naissance' => '2004-05-14',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Adresse originale',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2023',
            'password' => 'SecretOriginal123!',
        ]);

        $this->creerReferenceMens('08112233', '2004-05-14');

        $numOriginal = $existant->num_inscription;

        // Tentative de soumettre à nouveau ce CIN avec des données différentes
        $reponse = $this->post(route('inscription.store'), $this->donneesInscriptionValide([
            'email' => 'nouvel.email@etudiant.tn',
            'adresse' => 'Tentative écrasement adresse',
        ]));

        $reponse->assertSessionHasErrors(['cin']);

        // Vérification de non-écrasement
        $etudiantApres = $existant->fresh();
        $this->assertEquals('sami.original@etudiant.tn', $etudiantApres->email);
        $this->assertEquals('Adresse originale', $etudiantApres->adresse);
        $this->assertEquals($numOriginal, $etudiantApres->num_inscription);
    }

    public function test_tentative_inscription_avec_email_deja_utilise_est_rejetee(): void
    {
        Etudiant::create([
            'cin' => '09999999',
            'num_inscription' => 'INS-2025-AUTRE',
            'nom' => 'Autre',
            'prenom' => 'Etudiant',
            'email' => 'sami.benammar@etudiant.tn', // Même email
            'date_naissance' => '2004-01-01',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Adresse',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2023',
            'password' => 'SecretAutre123!',
        ]);

        $this->creerReferenceMens('08112233', '2004-05-14');

        $reponse = $this->post(route('inscription.store'), $this->donneesInscriptionValide([
            'cin' => '08112233',
        ]));

        $reponse->assertSessionHasErrors(['email']);
        $this->assertDatabaseMissing('etudiants', ['cin' => '08112233']);
    }

    // ── 3. Fail-closed MENS (identification préalable) ──────────────────────

    public function test_inscription_bloquee_si_cin_absent_du_referentiel_mens(): void
    {
        // Aucun enregistrement dans mens_references pour ce CIN
        $reponse = $this->post(route('inscription.store'), $this->donneesInscriptionValide([
            'cin' => '07777777',
            'date_naissance' => '2004-05-14',
        ]));

        $reponse->assertSessionHasErrors(['identification']);
        $this->assertDatabaseMissing('etudiants', ['cin' => '07777777']);
    }

    public function test_inscription_bloquee_si_date_naissance_discordante_avec_mens(): void
    {
        // La référence existe pour la date 2004-05-14
        $this->creerReferenceMens('08112233', '2004-05-14');

        // Mais le formulaire soumet une date différente (ex : 2004-05-15)
        $reponse = $this->post(route('inscription.store'), $this->donneesInscriptionValide([
            'cin' => '08112233',
            'date_naissance' => '2004-05-15',
        ]));

        $reponse->assertSessionHasErrors(['identification']);
        $this->assertDatabaseMissing('etudiants', ['cin' => '08112233']);
    }

    // ── 4. Idempotence et anti-rejeu ────────────────────────────────────────

    public function test_soumission_idempotente_rejouee_ne_cree_pas_de_doublon(): void
    {
        $this->creerReferenceMens('08112233', '2004-05-14');
        $idempotencyToken = 'IDEMP-TEST-TOKEN-123456789';

        $payload = $this->donneesInscriptionValide([
            'idempotency_token' => $idempotencyToken,
        ]);

        // Première soumission : succès
        $reponse1 = $this->post(route('inscription.store'), $payload);
        $reponse1->assertRedirect(route('inscription.success'));

        $this->assertDatabaseCount('etudiants', 1);
        $etudiant = Etudiant::where('cin', '08112233')->first();
        $this->assertNotNull($etudiant);
        $numInscription = $etudiant->num_inscription;

        // Seconde soumission immédiate avec le même jeton d'idempotence
        $reponse2 = $this->post(route('inscription.store'), $payload);
        $reponse2->assertRedirect(route('inscription.success'));

        // Toujours un seul étudiant en base, aucun doublon créé
        $this->assertDatabaseCount('etudiants', 1);
        $this->assertEquals($numInscription, $etudiant->fresh()->num_inscription);
    }

    // ── 5. Reprise de dossier existant : recherche neutre anti-énumération ────

    public function test_reprise_dossier_recherche_inexistante_reste_strictement_neutre(): void
    {
        $reponse = $this->post(route('inscription.existant.recherche'), [
            'cin' => '00000000',
            'date_naissance' => '2000-01-01',
        ]);

        $reponse->assertRedirect(route('inscription.existant.accueil'));
        $reponse->assertSessionHas('dossier_existant.code_envoye', true);
        $reponse->assertSessionHas('statut');
        // Le message d'information ne divulgue rien
        $this->assertStringContainsString('Si un dossier correspondant à ces informations existe', session('statut'));
    }

    public function test_reprise_dossier_recherche_valide_envoie_code_otp_par_email(): void
    {
        Notification::fake();

        $etudiant = Etudiant::create([
            'cin' => '08112233',
            'num_inscription' => 'INS-2026-REPRISE',
            'nom' => 'Ben Ammar',
            'prenom' => 'Sami',
            'email' => 'sami.benammar@etudiant.tn',
            'date_naissance' => '2004-05-14',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L2',
            'annee_academique' => '2025-2026',
            'adresse' => 'Tunis',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2023',
            'password' => 'Secret123!',
        ]);

        $reponse = $this->post(route('inscription.existant.recherche'), [
            'cin' => '08112233',
            'date_naissance' => '2004-05-14',
        ]);

        $reponse->assertRedirect(route('inscription.existant.accueil'));

        Notification::assertSentTo($etudiant, CodeVerificationDossierExistant::class, function ($notification) {
            return strlen($notification->code) === 6 && ctype_digit($notification->code);
        });
    }

    // ── 6. Reprise de dossier : vérification du code OTP ─────────────────────

    public function test_reprise_dossier_rejet_si_code_otp_invalide(): void
    {
        Notification::fake();

        $etudiant = Etudiant::create([
            'cin' => '08112233',
            'num_inscription' => 'INS-2026-REPRISE',
            'nom' => 'Ben Ammar',
            'prenom' => 'Sami',
            'email' => 'sami.benammar@etudiant.tn',
            'date_naissance' => '2004-05-14',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L2',
            'annee_academique' => '2025-2026',
            'adresse' => 'Tunis',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2023',
            'password' => 'Secret123!',
        ]);

        // Étape 1 : recherche
        $this->post(route('inscription.existant.recherche'), [
            'cin' => '08112233',
            'date_naissance' => '2004-05-14',
        ]);

        // Étape 2 : vérification avec un faux code
        $reponse = $this->post(route('inscription.existant.verification'), [
            'code' => '999999',
        ]);

        $reponse->assertRedirect(route('inscription.existant.accueil'));
        $reponse->assertSessionHasErrors(['code']);
        $this->assertNull(Session::get('dossier_existant.jeton'));
    }

    public function test_reprise_dossier_succes_code_otp_delivre_jeton_de_session(): void
    {
        $codeGenere = null;

        Notification::fake();

        $etudiant = Etudiant::create([
            'cin' => '08112233',
            'num_inscription' => 'INS-2026-REPRISE',
            'nom' => 'Ben Ammar',
            'prenom' => 'Sami',
            'email' => 'sami.benammar@etudiant.tn',
            'date_naissance' => '2004-05-14',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L2',
            'annee_academique' => '2025-2026',
            'adresse' => 'Tunis',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2023',
            'password' => 'Secret123!',
        ]);

        // Étape 1 : recherche
        $this->post(route('inscription.existant.recherche'), [
            'cin' => '08112233',
            'date_naissance' => '2004-05-14',
        ]);

        Notification::assertSentTo($etudiant, CodeVerificationDossierExistant::class, function ($notification) use (&$codeGenere) {
            $codeGenere = $notification->code;

            return true;
        });

        $this->assertNotNull($codeGenere);

        // Étape 2 : vérification avec le bon code
        $reponse = $this->post(route('inscription.existant.verification'), [
            'code' => $codeGenere,
        ]);

        $reponse->assertRedirect(route('inscription.existant.formulaire'));
        $this->assertNotNull(Session::get('dossier_existant.jeton'));
    }

    // ── 7. Reprise de dossier : accès direct au formulaire interdit ──────────

    public function test_acces_direct_au_formulaire_reprise_sans_jeton_est_bloque(): void
    {
        // Aucun jeton en session
        Session::forget('dossier_existant.jeton');

        $reponse = $this->get(route('inscription.existant.formulaire'));

        $reponse->assertRedirect(route('inscription.existant.accueil'));
        $reponse->assertSessionHas('statut');
    }

    public function test_acces_donnees_json_reprise_sans_jeton_est_bloque(): void
    {
        Session::forget('dossier_existant.jeton');

        $reponse = $this->getJson(route('inscription.existant.donnees'));

        $reponse->assertStatus(403);
    }

    // ── 8. Reprise de dossier : usage unique du jeton d'autorisation ─────────

    public function test_jeton_autorisation_reprise_est_consomme_apres_enregistrement(): void
    {
        $codeGenere = null;
        Notification::fake();

        $etudiant = Etudiant::create([
            'cin' => '08112233',
            'num_inscription' => 'INS-2026-REPRISE',
            'nom' => 'Ben Ammar',
            'prenom' => 'Sami',
            'email' => 'sami.benammar@etudiant.tn',
            'date_naissance' => '2004-05-14',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L2',
            'annee_academique' => '2025-2026',
            'adresse' => 'Ancienne Adresse',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2023',
            'password' => 'Secret123!',
        ]);

        // Recherche + code
        $this->post(route('inscription.existant.recherche'), [
            'cin' => '08112233',
            'date_naissance' => '2004-05-14',
        ]);

        Notification::assertSentTo($etudiant, CodeVerificationDossierExistant::class, function ($n) use (&$codeGenere) {
            $codeGenere = $n->code;

            return true;
        });

        // Validation code
        $this->post(route('inscription.existant.verification'), [
            'code' => $codeGenere,
        ]);

        $jeton = Session::get('dossier_existant.jeton');
        $this->assertNotNull($jeton);

        // Soumission des modifications
        $reponse = $this->post(route('inscription.existant-store'), [
            'adresse' => 'Nouvelle Adresse Validée 456',
            'ville' => 'Sousse',
            'code_postal' => '4000',
            'telephone' => '+216 73 999 888',
        ]);

        $reponse->assertRedirect(route('inscription.existant.confirmation'));

        // Vérification en base
        $this->assertEquals('Nouvelle Adresse Validée 456', $etudiant->fresh()->adresse);
        $this->assertEquals('Sousse', $etudiant->fresh()->ville);

        // Tentative de réutiliser le même jeton pour une autre modification : refusée
        Session::put('dossier_existant.jeton', $jeton);

        $reponseRejeu = $this->post(route('inscription.existant-store'), [
            'adresse' => 'Deuxième tentative avec jeton réutilisé',
            'ville' => 'Tunis',
            'code_postal' => '1000',
        ]);

        $reponseRejeu->assertRedirect(route('inscription.existant.accueil'));
        $reponseRejeu->assertSessionHas('statut');
        $this->assertEquals('Nouvelle Adresse Validée 456', $etudiant->fresh()->adresse);
    }

    // ── 9. Suivi public de dossier (double facteur et données minimales) ─────

    public function test_suivi_public_dossier_rejet_si_second_facteur_incorrect(): void
    {
        $etudiant = Etudiant::create([
            'cin' => '08112233',
            'nom' => 'Ben Ammar',
            'prenom' => 'Sami',
            'email' => 'sami.benammar@etudiant.tn',
            'date_naissance' => '2004-05-14',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Tunis',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2023',
            'password' => 'Secret123!',
        ]);

        // Numéro d'inscription correct mais date de naissance erronée
        $reponse = $this->post(route('inscription.suivi.rechercher'), [
            'reference' => $etudiant->num_inscription,
            'secondaire' => '2000-01-01',
        ]);

        $reponse->assertOk();
        $reponse->assertSee('Aucun dossier correspondant n’a été trouvé avec ces références.');
        $reponse->assertDontSee('sami.benammar@etudiant.tn');
        $reponse->assertDontSee('Ben Ammar');
    }

    public function test_suivi_public_dossier_succes_avec_second_facteur_valide_et_donnees_masquees(): void
    {
        $etudiant = Etudiant::create([
            'cin' => '08112233',
            'nom' => 'Ben Ammar',
            'prenom' => 'Sami',
            'email' => 'sami.benammar@etudiant.tn',
            'date_naissance' => '2004-05-14',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Tunis',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2023',
            'password' => 'Secret123!',
        ]);

        // Numéro d'inscription + date de naissance exacte (HTML)
        $reponse = $this->post(route('inscription.suivi.rechercher'), [
            'reference' => $etudiant->num_inscription,
            'secondaire' => '2004-05-14',
        ]);

        $reponse->assertOk();
        $reponse->assertSee($etudiant->num_inscription);
        $reponse->assertSee('Ben Ammar');
        $reponse->assertSee('Sami');
        // Jamais d'email complet ni de mot de passe dans le suivi
        $reponse->assertDontSee('sami.benammar@etudiant.tn');
        $reponse->assertDontSee('Secret123!');

        // Numéro d'inscription + date de naissance exacte (JSON pour popup embarqué)
        $reponseJson = $this->postJson(route('inscription.suivi.rechercher'), [
            'reference' => $etudiant->num_inscription,
            'secondaire' => '2004-05-14',
        ]);

        $reponseJson->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('suivi.reference', $etudiant->num_inscription)
            ->assertJsonPath('suivi.etudiant.email_masque', 'sam******@etudiant.tn');
    }
}
