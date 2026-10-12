<?php

namespace Tests\Feature;

use App\Contracts\EnregistrementPresenceInterface;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Attendance\Models\Presence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Module Attendance (§36) : démonstration que l'architecture permet
 * d'ajouter un module sans toucher au cœur.
 *
 * - désactivé (défaut) : routes = 404, liens absents des menus ;
 * - activé : CRUD scopé faculté + permissions + audit transverse.
 */
class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculte;

    protected User $admin;

    protected Etudiant $etudiant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences et Techniques',
            'code' => 'FST',
            'description' => 'Sciences et technologies',
            'active' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité Test',
            'email' => 'scolarite.test@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->etudiant = $this->creerEtudiant($this->faculte, 'BK930001');
    }

    private function creerEtudiant(Faculte $faculte, string $cin): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => 'Test',
            'prenom' => 'Etudiant',
            'email' => strtolower($cin).'@example.com',
            'telephone' => '+212600000000',
            'date_naissance' => '2004-03-15',
            'sexe' => 'M',
            'faculte_id' => $faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '12 Rue de la Liberté',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'mention_bac' => 'Bien',
            'password' => 'motdepasse1',
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-2026-'.$cin])->save();
        $etudiant->definirStatut('valide');

        return $etudiant->fresh();
    }

    private function activerModule(): void
    {
        config(['modules.attendance.active' => true]);
    }

    public function test_module_desactive_routes_inaccessibles(): void
    {
        config(['modules.attendance.active' => false]);

        $this->actingAs($this->admin)->get('/admin/presences')->assertNotFound();
        $this->actingAs($this->admin)->post('/admin/presences', [])->assertNotFound();

        session(['etudiant_auth_id' => $this->etudiant->id]);
        $this->get('/etudiant/absences')->assertNotFound();
    }

    public function test_module_desactive_liens_absents(): void
    {
        config(['modules.attendance.active' => false]);

        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertOk()->assertDontSee('Présences');

        session(['etudiant_auth_id' => $this->etudiant->id]);
        $this->get(route('etudiant.dashboard'))
            ->assertOk()->assertDontSee('Mes absences');
    }

    public function test_index_et_widgets_si_module_actif(): void
    {
        $this->activerModule();

        $this->actingAs($this->admin)->get(route('admin.presences.index'))
            ->assertOk()->assertSee('Relevés de présence');

        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertOk()->assertSee('Présences du jour');

        session(['etudiant_auth_id' => $this->etudiant->id]);
        $this->get(route('etudiant.dashboard'))
            ->assertOk()->assertSee('Assiduité');
    }

    public function test_store_cree_presence_et_audit(): void
    {
        $this->activerModule();

        $reponse = $this->actingAs($this->admin)->post(route('admin.presences.store'), [
            'etudiant_id' => $this->etudiant->id,
            'date' => '2026-09-20',
            'seance' => 'S1',
            'statut' => 'present',
        ]);

        $reponse->assertRedirect(route('admin.presences.index'));
        // whereDate : la colonne DATE est stockée avec heure sur SQLite,
        // sans heure sur MySQL — comparaison portable.
        $this->assertTrue(Presence::where('etudiant_id', $this->etudiant->id)
            ->whereDate('date', '2026-09-20')
            ->where('statut', 'present')
            ->exists());
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $this->etudiant->id,
            'action' => 'attendance.enregistree',
        ]);
    }

    public function test_store_idempotent_meme_seance(): void
    {
        $this->activerModule();

        $donnees = [
            'etudiant_id' => $this->etudiant->id,
            'date' => '2026-09-20',
            'seance' => 'S1',
            'statut' => 'present',
        ];

        $this->actingAs($this->admin)->post(route('admin.presences.store'), $donnees)->assertRedirect();
        $this->actingAs($this->admin)->post(route('admin.presences.store'), array_merge($donnees, ['statut' => 'retard']))->assertRedirect();

        // Unicité (étudiant, date, séance) : mise à jour, pas de doublon.
        $this->assertSame(1, Presence::where('etudiant_id', $this->etudiant->id)->count());
        $this->assertSame('retard', Presence::first()->statut);
    }

    public function test_store_refuse_date_future(): void
    {
        $this->activerModule();

        $this->actingAs($this->admin)->post(route('admin.presences.store'), [
            'etudiant_id' => $this->etudiant->id,
            'date' => now()->addDay()->format('Y-m-d'),
            'statut' => 'present',
        ])->assertSessionHasErrors('date');
    }

    public function test_isolation_faculte(): void
    {
        $this->activerModule();

        $autre = Faculte::create([
            'nom' => 'Faculté de Médecine', 'code' => 'FMP',
            'description' => 'Médecine', 'active' => true,
        ]);
        $etudiantB = $this->creerEtudiant($autre, 'BK930002');

        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Admin FST',
            'email' => 'admin.fst@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculte->id,
        ], 'admin_faculte');

        // Hors périmètre = 404, pas de fuite d'existence.
        $this->actingAs($adminFaculte)->post(route('admin.presences.store'), [
            'etudiant_id' => $etudiantB->id,
            'date' => '2026-09-20',
            'statut' => 'present',
        ])->assertNotFound();

        $this->assertDatabaseMissing('presences', ['etudiant_id' => $etudiantB->id]);
    }

    public function test_permission_requise(): void
    {
        $this->activerModule();

        $lecteur = User::creerCompteBackOffice([
            'name' => 'Consultation Test',
            'email' => 'consult.test@universite.test',
            'password' => 'Motdepasse@123',
        ], 'consultation');

        $this->actingAs($lecteur)->get(route('admin.presences.index'))->assertOk();
        $this->actingAs($lecteur)->post(route('admin.presences.store'), [
            'etudiant_id' => $this->etudiant->id,
            'date' => '2026-09-20',
            'statut' => 'present',
        ])->assertForbidden();
    }

    public function test_etudiant_voit_ses_absences_et_son_taux(): void
    {
        $this->activerModule();

        foreach (['present', 'present', 'absent', 'retard'] as $i => $statut) {
            Presence::create([
                'etudiant_id' => $this->etudiant->id,
                'date' => '2026-09-'.(10 + $i),
                'statut' => $statut,
            ]);
        }

        session(['etudiant_auth_id' => $this->etudiant->id]);

        $this->get(route('etudiant.absences.index'))
            ->assertOk()->assertSee('Mes absences')->assertSee('75');

        $service = $this->app->make(EnregistrementPresenceInterface::class);
        $this->assertSame(75.0, $service->tauxPresence($this->etudiant));
        $this->assertSame(4, $service->nombreSeances($this->etudiant));
    }

    public function test_destroy_scope_et_permission(): void
    {
        $this->activerModule();

        $presence = Presence::create([
            'etudiant_id' => $this->etudiant->id,
            'date' => '2026-09-20',
            'statut' => 'absent',
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.presences.destroy', $presence))
            ->assertRedirect(route('admin.presences.index'));

        $this->assertDatabaseMissing('presences', ['id' => $presence->id]);
    }
}
