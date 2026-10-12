<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\MensReference;
use App\Services\PeriodeInscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lot correctif critique — identification fail-closed : un couple
 * CIN + date de naissance absent de `mens_references` (INCONNU) ne
 * doit JAMAIS atteindre la création, par aucun chemin serveur
 * (wizard, appel direct, valeurs modifiées, session réutilisée).
 */
class IdentificationFailClosedTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences et Techniques', 'code' => 'FST',
            'description' => 'T', 'active' => true,
        ]);

        $annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-06-30', 'active' => true,
        ]);
        app(PeriodeInscriptionService::class)->creerNormale([
            'annee_universitaire_id' => $annee->id,
            'opened_at' => '2020-01-01 00:00', 'closed_at' => '2030-12-31 23:59',
        ], null);
    }

    private function seedMens(string $cin, string $naissance, array $extra = []): MensReference
    {
        return MensReference::create(array_merge([
            'cin_normalise' => mb_strtoupper(trim($cin)),
            'cin_original' => trim($cin),
            'date_naissance' => $naissance,
            'nom' => 'Alami',
            'prenom' => 'Mehdi',
            'annee' => '2025-2026',
            'source_hash' => hash('sha256', $cin.$naissance),
        ], $extra));
    }

    private function payload(string $cin, string $email, string $naissance = '2004-03-15', array $extra = []): array
    {
        return array_merge([
            'cin' => $cin, 'nom' => 'Alami', 'prenom' => 'Mehdi', 'email' => $email,
            'telephone' => '+212612345678', 'date_naissance' => $naissance, 'sexe' => 'M',
            'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'annee_academique' => '2025-2026',
            'adresse' => '12 Rue de la Liberté', 'ville' => 'Casablanca', 'code_postal' => '20000',
            'annee_bac' => '2024', 'password' => 'Etudiant@123', 'password_confirmation' => 'Etudiant@123',
        ], $extra);
    }

    // ── Cas 1 : CIN + DOB inexistants → INCONNU, rien créé ─────────

    public function test_couple_inexistant_bloque_sans_rien_creer(): void
    {
        $this->post(route('inscription.store'), $this->payload('70000001', 'inconnu.test@example.com', '1999-12-31'))
            ->assertRedirect()
            ->assertSessionHasErrors('identification');

        $this->assertDatabaseMissing('etudiants', ['cin' => '70000001']);
        $this->assertSame(0, Etudiant::count());
    }

    // ── Cas 2 : CIN inexistant ─────────────────────────────────────

    public function test_cin_inexistant_bloque(): void
    {
        $this->seedMens('99999999', '2004-03-15');

        $this->post(route('inscription.store'), $this->payload('70000002', 'xx.test@example.com', '2004-03-15'))
            ->assertRedirect()
            ->assertSessionHasErrors('identification');

        $this->assertDatabaseMissing('etudiants', ['cin' => '70000002']);
    }

    // ── Cas 3 : CIN existant MENS + DOB incorrecte ─────────────────

    public function test_dob_incorrecte_bloquee(): void
    {
        $this->seedMens('11111111', '2004-03-15');

        $this->post(route('inscription.store'), $this->payload('11111111', 'dob.test@example.com', '1990-01-01'))
            ->assertRedirect()
            ->assertSessionHasErrors('identification');

        $this->assertDatabaseMissing('etudiants', ['cin' => '11111111']);
    }

    // ── Cas 4 : CIN + DOB valides → création fonctionnelle ─────────

    public function test_couple_valide_cree_inscription(): void
    {
        $this->seedMens('22222222', '2004-03-15');

        $this->post(route('inscription.store'), $this->payload('22222222', 'valide.test@example.com'))
            ->assertRedirect(route('inscription.success'));

        $this->assertDatabaseHas('etudiants', ['cin' => '22222222']);
    }

    // ── Cas 5 : CIN modifié après identification → invalidé ─────────

    public function test_cin_modifie_apres_identification_invalide(): void
    {
        $this->seedMens('33333333', '2004-03-15');

        // Identification réussie (MENS_FOUND)…
        $this->postJson(route('inscription.identifier'), [
            'cin' => '33333333', 'date_naissance' => '2004-03-15',
        ])->assertOk()->assertJsonPath('status', 'MENS_FOUND');

        // …puis soumission avec un CIN différent : refusée.
        $this->post(route('inscription.store'), $this->payload('33333334', 'modifie.test@example.com'))
            ->assertRedirect()
            ->assertSessionHasErrors('identification');

        $this->assertDatabaseMissing('etudiants', ['cin' => '33333334']);
    }

    // ── Cas 6 : DOB modifiée → invalidée ───────────────────────────

    public function test_dob_modifiee_apres_identification_invalide(): void
    {
        $this->seedMens('44444444', '2004-03-15');

        $this->postJson(route('inscription.identifier'), [
            'cin' => '44444444', 'date_naissance' => '2004-03-15',
        ])->assertOk()->assertJsonPath('status', 'MENS_FOUND');

        $this->post(route('inscription.store'), $this->payload('44444444', 'dobmod.test@example.com', '1990-01-01'))
            ->assertRedirect()
            ->assertSessionHasErrors('identification');

        $this->assertDatabaseMissing('etudiants', ['cin' => '44444444']);
    }

    // ── Cas 7 : appel direct confirmation sans identification ──────

    public function test_confirmation_directe_sans_identification_refusee(): void
    {
        // Aucun appel à /inscription/identifier au préalable.
        $this->post(route('inscription.store'), $this->payload('55555555', 'direct.test@example.com'))
            ->assertRedirect()
            ->assertSessionHasErrors('identification');

        $this->assertSame(0, Etudiant::count());
    }

    // ── Cas 8 : ancienne session réutilisée avec autre couple ──────

    public function test_ancienne_identification_inutilisable_pour_autre_couple(): void
    {
        $this->seedMens('66666666', '2004-03-15');

        $this->postJson(route('inscription.identifier'), [
            'cin' => '66666666', 'date_naissance' => '2004-03-15',
        ])->assertOk()->assertJsonPath('status', 'MENS_FOUND');

        // L'ancienne identification ne vaut pas pour un autre couple.
        $this->post(route('inscription.store'), $this->payload('77777777', 'autre.test@example.com'))
            ->assertRedirect()
            ->assertSessionHasErrors('identification');

        $this->assertDatabaseMissing('etudiants', ['cin' => '77777777']);
    }

    // ── Cas 9 : double soumission → une seule inscription ──────────

    public function test_double_soumission_unique_inscription(): void
    {
        $this->seedMens('88888888', '2004-03-15');

        $payload = $this->payload('88888888', 'double.test@example.com');

        $this->post(route('inscription.store'), $payload)->assertRedirect(route('inscription.success'));
        $this->post(route('inscription.store'), $payload)->assertSessionHasErrors(['cin']);

        $this->assertSame(1, Etudiant::where('cin', '88888888')->count());
    }

    // ── MENS_ET_LOCAL : dossier existant → refus `cin`, jamais
    // d'écrasement ni de doublon ──────────────────────────────────

    public function test_dossier_existant_mens_local_refuse_sans_ecraser(): void
    {
        $this->seedMens('99990001', '2001-02-02', ['nom' => 'MENSNOM', 'prenom' => 'MENSPRENOM']);
        Etudiant::create([
            'cin' => '99990001', 'nom' => 'Local', 'prenom' => 'Nomic', 'email' => 'local.test@example.com',
            'date_naissance' => '2001-02-02', 'sexe' => 'M', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2025-2026', 'adresse' => 'X',
            'ville' => 'V', 'code_postal' => '1', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);

        $this->post(route('inscription.store'), $this->payload('99990001', 'autre.test@example.com', '2001-02-02'))
            ->assertRedirect()
            ->assertSessionHasErrors('cin');

        // Données locales intactes (jamais écrasées par MENS).
        $this->assertSame(1, Etudiant::where('cin', '99990001')->count());
        $this->assertSame('Local', Etudiant::where('cin', '99990001')->sole()->nom);
    }

    // ── DOB absente → identification impossible ────────────────────

    public function test_naissance_absente_bloquee(): void
    {
        $payload = $this->payload('70000010', 'sansdob.test@example.com');
        unset($payload['date_naissance']);

        $this->post(route('inscription.store'), $payload)
            ->assertRedirect()
            ->assertSessionHasErrors('identification');

        $this->assertDatabaseMissing('etudiants', ['cin' => '70000010']);
    }
}
