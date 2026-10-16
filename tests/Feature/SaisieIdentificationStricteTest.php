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
 * Contrôle strict des saisies d'identification publique (CIN + date de
 * naissance) à 4 niveaux : HTML (pattern/maxlength/required), JS/Alpine
 * (filtre CIN, auto-format JJ/MM/AAAA, pré-contrôles regex), validation
 * Laravel (VerifierCinRequest / DossierExistantAutonomeRequest) et règle
 * métier (SaisieIdentification : date impossible JAMAIS roulée
 * silencieusement, année ≥ 1900, date future refusée).
 *
 * La saisie publique JJ/MM/AAAA est NORMALISÉE vers Y-m-d (format interne
 * des services — MENS, whereDate) : les parcours existants restent
 * inchangés, et le format legacy Y-m-d demeure accepté (non-régression).
 */
class SaisieIdentificationStricteTest extends TestCase
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

    /* ---------------------------------------------------------------
     | FORMAT PUBLIC JJ/MM/AAAA — accepté et normalisé en Y-m-d
     * -------------------------------------------------------------- */

    public function test_recherche_existant_accepte_la_date_jj_mm_aaaa_et_normalise(): void
    {
        $etudiant = $this->creerEtudiant();
        Notification::fake();

        $this->post(route('inscription.existant.recherche'), [
            'cin' => '12345678',
            'date_naissance' => '17/05/2003',
        ])->assertRedirect(route('inscription.existant.accueil'));

        // Le code part bien : la date JJ/MM/AAAA a retrouvé le dossier.
        Notification::assertSentTo($etudiant, CodeVerificationDossierExistant::class);

        // L'identité en session est NORMALISÉE en Y-m-d (format interne) :
        // l'étape de vérification suivante re-résout le dossier sans
        // connaître le format de saisie.
        $identite = session('dossier_existant.recherche');
        $this->assertSame('2003-05-17', $identite['date_naissance']);
    }

    public function test_format_legacy_y_m_d_reste_accepte(): void
    {
        $etudiant = $this->creerEtudiant();
        Notification::fake();

        $this->post(route('inscription.existant.recherche'), [
            'cin' => '12345678',
            'date_naissance' => '2003-05-17',
        ])->assertRedirect(route('inscription.existant.accueil'));

        Notification::assertSentTo($etudiant, CodeVerificationDossierExistant::class);
        $this->assertSame('2003-05-17', session('dossier_existant.recherche')['date_naissance']);
    }

    public function test_identifier_du_wizard_accepte_jj_mm_aaaa(): void
    {
        $this->creerEtudiant();

        $this->postJson(route('inscription.identifier'), [
            'cin' => '12345678',
            'date_naissance' => '17/05/2003',
        ])->assertOk()->assertJson([
            'status' => 'EXISTING_STUDENT',
        ])->assertJsonPath('masked_email', 'moh******@example.com');
    }

    public function test_autonome_accepte_la_date_jj_mm_aaaa(): void
    {
        $this->post(route('inscription.existant.autonome'), [
            'cin' => '99999999', // CIN bien formé, dossier inexistant : réponse neutre
            'date_naissance' => '17/05/2003',
            'ancien_email' => 'ancien@example.com',
            'password' => 'MotDePasse123',
            'nouvel_email' => 'nouvel@example.com',
            'nouvel_email_confirmation' => 'nouvel@example.com',
        ])->assertSessionHasNoErrors('date_naissance'); // la date a franchi la validation
    }

    /* ---------------------------------------------------------------
     | RÈGLE MÉTIER — dates impossibles, futures, trop anciennes
     * -------------------------------------------------------------- */

    public function test_date_impossible_est_rejetee_sans_roulement_silencieux(): void
    {
        $this->creerEtudiant();
        Notification::fake();

        // 31/02/2020 passe le pattern HTML (jour 31 autorisé par la regex)
        // mais n'existe pas : rejet serveur, aucun roulement vers le 02/03.
        $this->post(route('inscription.existant.recherche'), [
            'cin' => '12345678',
            'date_naissance' => '31/02/2020',
        ])->assertSessionHasErrors([
            'date_naissance' => 'Date de naissance invalide ou impossible. Format obligatoire : JJ/MM/AAAA (ex : 15/03/2004).',
        ]);

        Notification::assertNothingSent(); // aucun code, aucune révélation
    }

    public function test_date_future_est_rejetee(): void
    {
        Notification::fake();

        $this->post(route('inscription.existant.recherche'), [
            'cin' => '12345678',
            'date_naissance' => now()->addDay()->format('d/m/Y'),
        ])->assertSessionHasErrors([
            'date_naissance' => 'La date de naissance doit être antérieure à aujourd\'hui.',
        ]);

        Notification::assertNothingSent();
    }

    public function test_annee_anterieure_a_1900_est_rejetee(): void
    {
        Notification::fake();

        $this->post(route('inscription.existant.recherche'), [
            'cin' => '12345678',
            'date_naissance' => '01/01/1899',
        ])->assertSessionHasErrors([
            'date_naissance' => 'La date de naissance doit être postérieure au 01/01/1900.',
        ]);

        Notification::assertNothingSent();
    }

    public function test_date_mal_formatee_est_rejetee_avec_message_jj_mm_aaaa(): void
    {
        Notification::fake();

        $this->post(route('inscription.existant.recherche'), [
            'cin' => '12345678',
            'date_naissance' => '17-05-2003', // tirets au lieu des slashes
        ])->assertSessionHasErrors(['date_naissance']);

        Notification::assertNothingSent();
    }

    /* ---------------------------------------------------------------
     | CIN — exactement 8 chiffres, lettres refusées
     * -------------------------------------------------------------- */

    public function test_cin_avec_lettres_est_rejete(): void
    {
        Notification::fake();

        $this->post(route('inscription.existant.recherche'), [
            'cin' => '12A45678',
            'date_naissance' => '17/05/2003',
        ])->assertSessionHasErrors([
            'cin' => 'Le numéro de CIN doit être composé d\'exactement 8 chiffres (chiffres 0 à 9 uniquement, sans lettre, espace ou tiret).',
        ]);

        Notification::assertNothingSent();
    }

    public function test_cin_trop_court_ou_trop_long_est_rejete(): void
    {
        Notification::fake();

        $this->post(route('inscription.existant.recherche'), [
            'cin' => '1234567',
            'date_naissance' => '17/05/2003',
        ])->assertSessionHasErrors(['cin']);

        $this->post(route('inscription.existant.recherche'), [
            'cin' => '123456789',
            'date_naissance' => '17/05/2003',
        ])->assertSessionHasErrors(['cin']);

        Notification::assertNothingSent();
    }

    /* ---------------------------------------------------------------
     | MARKUP — les 4 niveaux sont présents dans les vues servies
     * -------------------------------------------------------------- */

    public function test_le_formulaire_existant_embarque_le_controle_strict(): void
    {
        $html = $this->get(route('inscription.existant.accueil'))->assertOk()->getContent();

        // Niveau 1 (HTML) : pattern + longueur + format affiché.
        $this->assertStringContainsString('pattern="[0-9]{8}"', $html);
        $this->assertStringContainsString('maxlength="8"', $html);
        $this->assertStringContainsString('minlength="8"', $html);
        $this->assertStringContainsString('inputmode="numeric"', $html);
        $this->assertStringContainsString('placeholder="JJ/MM/AAAA"', $html);
        $this->assertStringContainsString('maxlength="10"', $html);

        // Niveau 2 (JS) : filtre CIN + auto-formatage date.
        $this->assertStringContainsString('this.value.replace(/[^0-9]/g', $html);
        $this->assertStringContainsString('formaterDateNaissance(this)', $html);
        $this->assertStringContainsString('function formaterDateNaissance', $html);

        // Aides explicites.
        $this->assertStringContainsString('Le CIN doit contenir exactement 8 chiffres.', $html);
        $this->assertStringContainsString('Format obligatoire : JJ/MM/AAAA', $html);

        // Les DEUX formulaires de la page (recherche + autonome).
        $this->assertSame(2, substr_count($html, 'formaterDateNaissance(this)'));
        $this->assertSame(2, substr_count($html, 'pattern="[0-9]{8}"'));
    }

    public function test_le_wizard_embarque_le_controle_strict_et_les_pre_controles(): void
    {
        $html = $this->get(route('inscription.create'))->assertOk()->getContent();

        // Champs d'identification (étape 1) : HTML strict + auto-format.
        $this->assertStringContainsString('id="ident-cin"', $html);
        $this->assertStringContainsString('id="ident-naissance"', $html);
        $this->assertStringContainsString('formaterDateNaissance(this)', $html);
        $this->assertStringContainsString('placeholder="JJ/MM/AAAA"', $html);

        // Pré-contrôles Alpine (niveau 2) : aucune requête serveur pour
        // une saisie non conforme, messages explicites.
        $this->assertStringContainsString('!/^[0-9]{8}$/.test(this.identification.cin)', $html);
        $this->assertStringContainsString('Le CIN doit contenir exactement 8 chiffres numériques.', $html);
        $this->assertStringContainsString('La date de naissance doit être au format JJ/MM/AAAA', $html);
    }
}
