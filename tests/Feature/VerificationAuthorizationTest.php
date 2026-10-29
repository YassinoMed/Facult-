<?php

namespace Tests\Feature;

use App\Models\DossierVerification;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * LOOP centre de travail — les routes de vérification sont des écritures :
 * `consultation` (archives.view seule) ne doit jamais pouvoir lancer un
 * contrôle unitaire ou en masse (D-05 / M-08 = A).
 */
class VerificationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private User $lecteur;

    private User $scolarite;

    private Etudiant $etudiant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'FST', 'code' => 'FST', 'active' => true,
        ]);

        $this->lecteur = User::creerCompteBackOffice([
            'name' => 'Lecteur', 'email' => 'lecteur.verif@test.tn', 'password' => 'Secret123!',
        ], 'consultation');

        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.verif@test.tn', 'password' => 'Secret123!',
        ], 'admin_scolarite');

        $this->etudiant = Etudiant::create([
            'nom' => 'Verif', 'prenom' => 'Test', 'email' => 'verif@test.tn',
            'cin' => '74000001', 'sexe' => 'M', 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'adr', 'ville' => 'Tunis',
            'code_postal' => '1000', 'annee_bac' => '2025', 'faculte_id' => $this->faculte->id,
        ]);
    }

    public function test_routes_verification_exigent_une_permission_ecriture(): void
    {
        $this->assertContains(
            'can:archives.update',
            Route::getRoutes()->getByName('admin.verification.verifier')->gatherMiddleware()
        );
        $this->assertContains(
            'can:archives.update',
            Route::getRoutes()->getByName('admin.verification.bulk')->gatherMiddleware()
        );
    }

    public function test_consultation_ne_peut_pas_lancer_une_verification_unitaire(): void
    {
        $this->actingAs($this->lecteur)
            ->post(route('admin.verification.verifier', $this->etudiant))
            ->assertForbidden();

        $this->assertSame(0, DossierVerification::where('etudiant_id', $this->etudiant->id)->count());
    }

    public function test_consultation_ne_peut_pas_lancer_une_verification_en_masse(): void
    {
        $this->actingAs($this->lecteur)
            ->post(route('admin.verification.bulk'), [
                'etudiant_ids' => [$this->etudiant->id],
            ])
            ->assertForbidden();

        $this->assertSame(0, DossierVerification::where('etudiant_id', $this->etudiant->id)->count());
    }

    public function test_scolarite_autorisee_peut_verifier(): void
    {
        $this->actingAs($this->scolarite)
            ->post(route('admin.verification.verifier', $this->etudiant))
            ->assertRedirect();

        $this->assertSame(1, DossierVerification::where('etudiant_id', $this->etudiant->id)->count());
    }
}
