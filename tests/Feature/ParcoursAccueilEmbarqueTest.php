<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\MensReference;
use App\Notifications\CodeVerificationDossierExistant;
use App\Services\PeriodeInscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Parcours 100 % page d'accueil (`/`, modal sans navigation) : coquille à
 * états, branches existant (JSON) et nouvelle (wizard embarqué), mêmes
 * endpoints/validations/services que les parcours canoniques.
 */
class ParcoursAccueilEmbarqueTest extends TestCase
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
        ]);
    }

    public function test_coquille_modale_et_wizard_prefixe_sans_collision(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        // Déclencheur + machine à états, dialogue unique, repli sans JS.
        $this->assertStringContainsString('id="btn-commencer-accueil"', $html);
        $this->assertStringContainsString('titre-parcours-accueil', $html);
        $this->assertStringContainsString('x-data="parcoursAccueil()"', $html);
        $this->assertStringContainsString('<noscript>', $html);

        // Wizard embarqué : préfixé, mode fetch, sans doublon d'identifiants
        // avec les mini-formulaires de la page (cin/date_naissance uniques).
        $this->assertStringContainsString('id="emb-cin"', $html);
        $this->assertStringContainsString('id="emb-date_naissance"', $html);
        $this->assertSame(1, substr_count($html, 'id="cin"'));
        $this->assertSame(1, substr_count($html, 'id="date_naissance"'));

        // Page canonique inchangée : sans préfixe, mode classique initialisé
        // à `false` (la branche fetch n'existe que si `embarque` est vrai).
        $nouvelle = $this->get(route('inscription.create'))->assertOk()->getContent();
        $this->assertStringContainsString('id="cin"', $nouvelle);
        $this->assertStringNotContainsString('id="emb-cin"', $nouvelle);
        $this->assertStringContainsString(', false, \'\')"', $nouvelle);
    }

    public function test_branche_existant_json_neutre_puis_donnees(): void
    {
        $etudiant = $this->creerEtudiant();
        Notification::fake();

        // Sans jeton : 403 JSON, aucune donnée.
        $this->getJson(route('inscription.existant.donnees'))
            ->assertStatus(403)
            ->assertJson(['ok' => false]);

        // Recherche JSON neutre (dossier existant).
        $this->postJson(route('inscription.existant.recherche'), [
            'cin' => '12345678', 'date_naissance' => '2003-05-17',
        ])->assertOk()->assertJson(['ok' => true, 'etape' => 'code']);

        $code = Notification::sent($etudiant, CodeVerificationDossierExistant::class)->first()->code;

        // Code faux : même enveloppe, anciennes conservées.
        $this->postJson(route('inscription.existant.verification'), ['code' => '000000'])
            ->assertOk()
            ->assertJson(['ok' => false]);

        // Code valide : étape dossier, puis données réelles.
        $this->postJson(route('inscription.existant.verification'), ['code' => $code])
            ->assertOk()
            ->assertJson(['ok' => true, 'etape' => 'dossier']);

        $donnees = $this->getJson(route('inscription.existant.donnees'))
            ->assertOk()
            ->assertJson(['ok' => true, 'dossierModifiable' => true, 'emailModifiable' => true]);

        $this->assertSame('12***678', $donnees->json('dossier.cin_masquee'));
        $this->assertSame('moh******@example.com', $donnees->json('dossier.email_masque'));
        $this->assertStringNotContainsString('mohamed.benali@example.com', $donnees->getContent());
    }

    public function test_branche_existant_enregistrer_json_whitelist(): void
    {
        $etudiant = $this->creerEtudiant();
        Notification::fake();

        $this->postJson(route('inscription.existant.recherche'), [
            'cin' => '12345678', 'date_naissance' => '2003-05-17',
        ])->assertOk();
        $code = Notification::sent($etudiant, CodeVerificationDossierExistant::class)->first()->code;
        $this->postJson(route('inscription.existant.verification'), ['code' => $code])
            ->assertJson(['ok' => true]);

        // Champs parasites ignorés (whitelist serveur stricte).
        $this->postJson(route('inscription.existant-store'), [
            'telephone' => '+212611111111',
            'adresse' => '9 Rue Embarquee',
            'ville' => 'Rabat',
            'code_postal' => '10100',
            'confirmation' => '1',
            'email' => 'pirate@evil.example',
            'statut' => 'valide',
            'etudiant_id' => 9999,
        ])->assertOk()->assertJson(['ok' => true]);

        $etudiant->refresh();
        $this->assertSame('9 Rue Embarquee', $etudiant->adresse);
        $this->assertSame('mohamed.benali@example.com', $etudiant->email);
        $this->assertSame('en_attente', $etudiant->statut);

        // Rejeu : jeton consommé (usage unique).
        $this->postJson(route('inscription.existant-store'), [
            'adresse' => 'Autre', 'ville' => 'Rabat', 'code_postal' => '10100', 'confirmation' => '1',
        ])->assertStatus(403)->assertJson(['ok' => false]);
    }

    public function test_branche_nouvelle_store_json_idempotent(): void
    {
        MensReference::create([
            'cin_original' => '08123456', 'cin_normalise' => '08123456',
            'date_naissance' => '2004-05-12', 'nom' => 'Alami', 'prenom' => 'Youssef',
            'nom_ar' => 'x', 'prenom_ar' => 'y', 'sexe' => 'M', 'nationalite' => 'Marocaine',
            'annee' => '2025-2026', 'source_hash' => str_repeat('b', 64),
        ]);

        $charge = [
            'cin' => '08123456',
            'nom' => 'Alami',
            'prenom' => 'Youssef',
            'email' => 'youssef.alami@example.com',
            'date_naissance' => '2004-05-12',
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
            'idempotency_token' => 'jeton-embarque-1',
        ];

        $premiere = $this->postJson(route('inscription.store'), $charge)->assertOk();
        $premiere->assertJson(['ok' => true, 'deja_enregistre' => false]);
        $this->assertSame(1, Etudiant::count());

        // Rejeu idempotent : même référence, aucun doublon.
        $this->postJson(route('inscription.store'), $charge)
            ->assertOk()
            ->assertJson(['ok' => true, 'deja_enregistre' => true]);
        $this->assertSame(1, Etudiant::count());
    }
}
