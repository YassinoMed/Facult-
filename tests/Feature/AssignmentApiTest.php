<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\AssignmentSimulation;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsApiClient;
use Tests\TestCase;

/**
 * API v1 affectations (§26) : scopes, périmètre, BOLA, tranches.
 */
class AssignmentApiTest extends TestCase
{
    use ActAsApiClient, RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Group $groupe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->groupe = Group::create([
            'code' => 'GA', 'nom' => 'GA', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'capacite' => 30, 'actif' => true,
        ]);
    }

    private function etudiant(string $cin): Etudiant
    {
        static $n = 0;
        $n++;

        $e = Etudiant::create([
            'cin' => $cin, 'nom' => 'N'.$n, 'prenom' => 'P'.$n, 'email' => strtolower($cin).'@api.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X',
            'ville' => 'T', 'code_postal' => '1', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $e->definirStatut('valide');
        InscriptionAcademique::create([
            'etudiant_id' => $e->id, 'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1', 'statut' => 'en_cours', 'type' => 'premiere', 'regime' => 'initiale',
        ]);

        return $e;
    }

    public function test_simulate_lecture_confirmation(): void
    {
        [, $token] = $this->clientApi(['assignments:read', 'assignments:write']);
        $e = $this->etudiant('APIX0001');

        $simulation = $this->postApi('/api/v1/assignments/simulate', [
            'annee_id' => $this->annee->id,
            'groupe_ids' => [$this->groupe->id],
            'etudiant_ids' => [$e->id],
            'seed' => 'API-SEED',
        ], $token)->assertCreated()->json('data');

        $this->assertSame('API-SEED', $simulation['seed']);
        $this->assertSame(1, $simulation['affectes']);

        $this->getApi("/api/v1/assignments/simulations/{$simulation['id']}", $token)->assertOk();

        $detail = $this->getApi(
            "/api/v1/assignments/simulations/{$simulation['id']}/students/{$e->id}", $token
        )->assertOk()->json('data');

        $this->assertSame($this->groupe->id, $detail['groupe_propose_id']);
        $this->assertNotEmpty($detail['candidats']);

        $confirmation = $this->postApi(
            "/api/v1/assignments/simulations/{$simulation['id']}/groups/{$this->groupe->id}/confirm",
            ['tranche' => 200], $token
        )->assertOk()->json('data');

        $this->assertTrue($confirmation['terminee']);
        $this->assertSame('confirmee', AssignmentSimulation::find($simulation['id'])->statut);
    }

    public function test_scope_insuffisant_refuse(): void
    {
        [, $token] = $this->clientApi(['assignments:read']);

        $this->postApi('/api/v1/assignments/simulate', [
            'annee_id' => $this->annee->id, 'groupe_ids' => [$this->groupe->id], 'etudiant_ids' => [1],
        ], $token)->assertForbidden();
    }

    public function test_bola_faculte_etrangere_404(): void
    {
        [, $token] = $this->clientApi(['assignments:read', 'assignments:write']);

        $autre = Faculte::create(['nom' => 'FLSH', 'code' => 'FLSH', 'description' => 'T', 'active' => true]);
        $groupeB = Group::create([
            'code' => 'GB', 'nom' => 'GB', 'faculte_id' => $autre->id,
            'niveau' => 'L1', 'capacite' => 30, 'actif' => true,
        ]);

        // Token lié à un utilisateur restreint fac A (via client global ?
        // non : on simule le périmètre avec un token d'admin_faculte).
        $adminFac = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.api@u.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculte->id,
        ], 'admin_faculte');
        $jeton = $adminFac->createToken('t', ['assignments:read', 'assignments:write'])->plainTextToken;

        // Simulation hors périmètre : 404, pas d'oracle.
        $this->getApi('/api/v1/assignments/simulations/999999', $jeton)->assertNotFound();

        // Groupe hors périmètre exclu de la simulation (422, rien d'écrit).
        $e = $this->etudiant('APIX0002');
        $this->postApi('/api/v1/assignments/simulate', [
            'annee_id' => $this->annee->id, 'groupe_ids' => [$groupeB->id], 'etudiant_ids' => [$e->id],
        ], $jeton)->assertNotFound();
        $this->assertSame(0, AssignmentSimulation::count());
    }
}
