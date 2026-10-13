<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Parametre;
use App\Models\ParametreVersion;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Phase G — Audit : accès, secrets absents, historique indestructible.
 */
class AuditPhaseGTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $user = User::creerCompteBackOffice([
            'name' => 'Super', 'email' => uniqid('ag.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], 'super_admin');
        $this->actingAs($user);

        return $user;
    }

    public function test_journal_reserve_et_sans_mot_de_passe(): void
    {
        $this->superAdmin();
        $cible = User::creerCompteBackOffice([
            'name' => 'Cible', 'email' => uniqid('cb.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], 'consultation');

        $captures = [];
        Log::listen(function (MessageLogged $evenement) use (&$captures) {
            if (($evenement->message ?? '') === 'admin.password_reset') {
                $captures[] = $evenement->context;
            }
        });

        $this->post(route('admin.administrateurs.reinitialiser', $cible), [
            'password' => 'NouveauMdp123', 'password_confirmation' => 'NouveauMdp123',
        ])->assertRedirect();

        $this->assertCount(1, $captures);
        $contenu = json_encode($captures[0]);
        $this->assertStringNotContainsString('NouveauMdp123', $contenu);
        $this->assertArrayHasKey('acteur_id', $captures[0]);
        $this->assertArrayHasKey('adresse_ip', $captures[0]);

        // Invité : redirection connexion, jamais le journal.
        auth()->logout();
        $this->get(route('admin.audit.index'))->assertRedirect();
    }

    public function test_journal_isole_par_faculte(): void
    {
        $faculteA = Faculte::create(['nom' => 'FA', 'code' => 'FA'.uniqid(), 'description' => 'T', 'active' => true]);
        $faculteB = Faculte::create(['nom' => 'FB', 'code' => 'FB'.uniqid(), 'description' => 'T', 'active' => true]);
        $etudiantB = Etudiant::create([
            'cin' => 'BK'.random_int(100000, 999999), 'nom' => 'JournalB', 'prenom' => 'Test',
            'email' => uniqid('jb.', true).'@example.com', 'sexe' => 'M', 'faculte_id' => $faculteB->id,
            'niveau' => 'L1', 'annee_academique' => '2025-2026', 'adresse' => '1 rue',
            'ville' => 'Rabat', 'code_postal' => '10000', 'annee_bac' => '2024', 'password' => 'motdepasse1',
        ]);
        AuditService::class;
        app(AuditService::class)->tracer($etudiantB, 'consultation', 'dossier', 1);

        $adminA = User::creerCompteBackOffice([
            'name' => 'Admin A', 'email' => uniqid('aa.', true).'@scolarite.test',
            'password' => 'Motdepasse@123', 'faculte_id' => $faculteA->id,
        ], 'admin_faculte');
        $this->actingAs($adminA);

        $this->get(route('admin.audit.index'))->assertOk()->assertDontSee('JournalB');
    }

    public function test_restauration_nefface_jamais_lhistorique(): void
    {
        $this->superAdmin();
        $cible = Parametre::where('cle', 'etablissement.nom')->first();

        $this->patch(route('admin.parametres.update', $cible), [
            'valeur' => 'V2', 'attendu' => $cible->updated_at?->toIso8601String(),
        ])->assertRedirect();
        $this->patch(route('admin.parametres.update', $cible->fresh()), [
            'valeur' => 'V3', 'attendu' => $cible->fresh()->updated_at?->toIso8601String(),
        ])->assertRedirect();
        $avant = 2;
        $v1 = ParametreVersion::where('parametre_id', $cible->id)->orderBy('id')->first();
        $this->post(route('admin.parametres.restaurer', $cible->fresh()), ['version_id' => $v1->id])
            ->assertRedirect();

        $this->assertSame($avant + 1, ParametreVersion::where('parametre_id', $cible->id)->count());
        $this->assertNotNull(ParametreVersion::find($v1->id));
    }
}
