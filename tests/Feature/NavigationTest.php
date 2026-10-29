<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Schedule\Models\Teacher;
use App\Support\NavigationDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Navigation globale et palette de commandes (§LOT 1 à §LOT 5) :
 * - Navigation du logo selon le rôle effectif et le contexte
 * - Palette de commandes x-palette filtrée (admin, enseignant, étudiant)
 * - Accessibilité WAI-ARIA (combobox, listbox, options, live region)
 * - Ergonomie mobile et cibles tactiles
 */
class NavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function creerFaculte(): Faculte
    {
        return Faculte::create([
            'nom' => 'Faculté des Sciences', 'code' => 'FST',
            'description' => 'Sciences', 'active' => true,
        ]);
    }

    protected function creerCompte(string $email, string $role): User
    {
        return User::creerCompteBackOffice([
            'name' => $role, 'email' => $email, 'password' => 'Motdepasse@123',
        ], $role);
    }

    protected function creerEnseignant(Faculte $faculte, array $surcharge = []): Teacher
    {
        $departement = Departement::create([
            'code' => 'DEP-'.uniqid(), 'nom' => 'Département Test', 'faculte_id' => $faculte->id,
        ]);

        return Teacher::create(array_merge([
            'nom' => 'Alami', 'prenom' => 'Karim',
            'email' => 'k.alami.'.uniqid().'@univ.test',
            'matricule' => 'ENS-'.uniqid(),
            'departement_id' => $departement->id,
            'actif' => true, 'statut' => 'actif',
            'password' => 'Motdepasse@123',
        ], $surcharge));
    }

    protected function creerEtudiant(Faculte $faculte, array $surcharge = []): Etudiant
    {
        $cin = 'BK'.rand(100000, 999999);
        $etudiant = Etudiant::create(array_merge([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sami',
            'email' => strtolower($cin).'@example.com', 'sexe' => 'M',
            'faculte_id' => $faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'Avenue Test',
            'ville' => 'Rabat', 'code_postal' => '10000', 'annee_bac' => '2024',
            'password' => 'motdepasse1',
        ], $surcharge));
        $etudiant->forceFill(['num_inscription' => 'INS-2026-'.$cin])->save();
        $etudiant->definirStatut('valide');

        return $etudiant->fresh();
    }

    public function test_invite_sidebar_portail(): void
    {
        $this->get(route('inscription.create'))
            ->assertOk()
            ->assertSee('Portail')
            ->assertSee('Inscription')
            ->assertSee('Espace scolarité')
            ->assertSee('aria-label="Navigation principale"', false)
            ->assertSee('sidebar-collapsed', false)
            ->assertSee('aria-expanded', false)
            ->assertSee('localStorage.getItem', false)
            ->assertSee('Choisir le thème', false)
            ->assertDontSee('Registre des étudiants');
    }

    public function test_etudiant_sidebar_espace_et_dashboard_vivant(): void
    {
        $faculte = $this->creerFaculte();
        $etudiant = $this->creerEtudiant($faculte, [
            'cin' => 'BK960001', 'nom' => 'Test', 'prenom' => 'Nadia',
            'email' => 'bk960001@example.com',
        ]);

        session(['etudiant_auth_id' => $etudiant->id]);

        $this->get(route('etudiant.dashboard'))
            ->assertOk()
            ->assertSee('Bonjour Nadia')
            ->assertSee("Aujourd'hui", false)
            ->assertSee('Dernier résultat')
            ->assertSee('Documents')
            ->assertSee('Mon espace')
            ->assertSee('Mes notes')
            ->assertDontSee('Registre des étudiants')
            ->assertDontSee('Espace scolarité');
    }

    public function test_admin_sidebar_pilotage_et_actions_rapides(): void
    {
        $this->creerFaculte();
        $admin = $this->creerCompte('sco@universite.test', 'admin_scolarite');

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Pilotage')
            ->assertSee('Scolarité')
            ->assertSee('Registre des étudiants')
            ->assertSee('Actions rapides')
            ->assertSee('Activité récente')
            ->assertDontSee('Mon espace');
    }

    public function test_admin_faculte_sans_section_super_admin(): void
    {
        $faculte = $this->creerFaculte();
        $admin = User::creerCompteBackOffice([
            'name' => 'Admin F', 'email' => 'admin.f@universite.test',
            'password' => 'Motdepasse@123', 'faculte_id' => $faculte->id,
        ], 'admin_faculte');

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Ma Faculté')
            ->assertDontSee('Administrateurs');
    }

    // ── LOT 1 : Destinations du logo selon le rôle et les permissions ──

    public function test_destination_logo_visiteur(): void
    {
        $reponse = $this->get(route('inscription.create'));
        $reponse->assertOk();
        $reponse->assertSee('<a href="'.route('inscription.create').'" class="flex min-w-0 flex-1 items-center gap-3">', false);
        $reponse->assertSee('<a href="'.route('inscription.create').'" class="lg:hidden flex items-center gap-2">', false);
        $this->assertSame(route('inscription.create'), NavigationDestination::resoudre());
    }

    public function test_destination_logo_etudiant(): void
    {
        $faculte = $this->creerFaculte();
        $etudiant = $this->creerEtudiant($faculte);

        $reponse = $this->withSession(['etudiant_auth_id' => $etudiant->id])->get(route('etudiant.dashboard'));
        $reponse->assertOk();
        $reponse->assertSee('<a href="'.route('etudiant.dashboard').'" class="flex min-w-0 flex-1 items-center gap-3">', false);
        $reponse->assertSee('<a href="'.route('etudiant.dashboard').'" class="lg:hidden flex items-center gap-2">', false);
    }

    public function test_destination_logo_enseignant(): void
    {
        $faculte = $this->creerFaculte();
        $enseignant = $this->creerEnseignant($faculte);

        $reponse = $this->withSession(['enseignant_auth_id' => $enseignant->id])->get(route('enseignant.dashboard'));
        $reponse->assertOk();
        $reponse->assertSee('<a href="'.route('enseignant.dashboard').'" class="flex min-w-0 flex-1 items-center gap-3">', false);
        $reponse->assertSee('<a href="'.route('enseignant.dashboard').'" class="lg:hidden flex items-center gap-2">', false);
    }

    public function test_destination_logo_personnel_administratif(): void
    {
        $this->creerFaculte();
        $agent = $this->creerCompte('agent.nav@universite.test', 'agent_scolarite');

        $reponse = $this->actingAs($agent)->get(route('admin.dashboard'));
        $reponse->assertOk();
        $reponse->assertSee('<a href="'.route('admin.dashboard').'" class="flex min-w-0 flex-1 items-center gap-3">', false);
        $reponse->assertSee('<a href="'.route('admin.dashboard').'" class="lg:hidden flex items-center gap-2">', false);
    }

    public function test_destination_logo_administrateur_et_super_admin(): void
    {
        $faculte = $this->creerFaculte();
        $superAdmin = $this->creerCompte('super.nav@universite.test', 'super_admin');
        $adminSco = $this->creerCompte('admin.sco.nav@universite.test', 'admin_scolarite');
        $adminFac = User::creerCompteBackOffice([
            'name' => 'Admin Fac Nav', 'email' => 'admin.fac.nav@universite.test',
            'password' => 'Motdepasse@123', 'faculte_id' => $faculte->id,
        ], 'admin_faculte');

        foreach ([$superAdmin, $adminSco, $adminFac] as $compte) {
            $reponse = $this->actingAs($compte)->get(route('admin.dashboard'));
            $reponse->assertOk();
            $reponse->assertSee('<a href="'.route('admin.dashboard').'" class="flex min-w-0 flex-1 items-center gap-3">', false);
        }
    }

    public function test_destination_logo_multi_roles(): void
    {
        $faculte = $this->creerFaculte();
        $enseignant = $this->creerEnseignant($faculte);
        $admin = $this->creerCompte('admin.multi@universite.test', 'admin_scolarite');

        // Dans l'espace enseignant, le logo dirige vers le tableau de bord enseignant
        $respEnseignant = $this->actingAs($admin)
            ->withSession(['enseignant_auth_id' => $enseignant->id])
            ->get(route('enseignant.dashboard'));
        $respEnseignant->assertOk();
        $respEnseignant->assertSee('<a href="'.route('enseignant.dashboard').'" class="flex min-w-0 flex-1 items-center gap-3">', false);

        // Dans l'espace admin, le logo dirige vers le tableau de bord admin
        $respAdmin = $this->actingAs($admin)
            ->withSession(['enseignant_auth_id' => $enseignant->id])
            ->get(route('admin.dashboard'));
        $respAdmin->assertOk();
        $respAdmin->assertSee('<a href="'.route('admin.dashboard').'" class="flex min-w-0 flex-1 items-center gap-3">', false);
    }

    public function test_destination_logo_utilisateur_sans_tableau_de_bord_dedie(): void
    {
        $userInactif = $this->creerCompte('inactif.nav@universite.test', 'super_admin');
        $userInactif->forceFill(['actif' => false])->save();

        // Un compte inactif ne doit pas être dirigé vers admin.dashboard (éviterait 403)
        $this->assertFalse(NavigationDestination::estBackOfficeAutorise($userInactif));

        $userSansRole = User::factory()->make(['role' => null, 'actif' => true]);
        $this->assertFalse(NavigationDestination::estBackOfficeAutorise($userSansRole));

        $adminFacSansFaculte = User::factory()->make(['role' => 'admin_faculte', 'faculte_id' => null, 'actif' => true]);
        $this->assertFalse(NavigationDestination::estBackOfficeAutorise($adminFacSansFaculte));
    }

    // ── LOT 2 : Palette de commandes pour enseignants et isolation ──

    public function test_palette_enseignant_actions_dediees_et_isolation(): void
    {
        $faculte = $this->creerFaculte();
        $enseignant = $this->creerEnseignant($faculte);

        $reponse = $this->withSession(['enseignant_auth_id' => $enseignant->id])
            ->get(route('enseignant.dashboard'));

        $reponse->assertOk()
            ->assertSee('paletteCommandes', false)
            ->assertSee('Espace enseignant', false)
            ->assertSee('Mon emploi du temps', false)
            ->assertSee('Mes documents', false)
            ->assertSee('Mes demandes', false)
            ->assertSee('Mes réclamations', false)
            ->assertSee('Mon profil', false)
            ->assertDontSee('Importer des données')
            ->assertDontSee('Registre des étudiants');
    }

    public function test_palette_non_rendue_visiteur(): void
    {
        $reponse = $this->get(route('inscription.create'));
        $reponse->assertOk()
            ->assertDontSee('paletteCommandes', false)
            ->assertDontSee('id="palette-recherche"', false);
    }

    // ── LOT 3 : Accessibilité WAI-ARIA ──

    public function test_palette_accessibilite_wai_aria(): void
    {
        $this->creerFaculte();
        $admin = $this->creerCompte('aria.admin@universite.test', 'admin_scolarite');

        $reponse = $this->actingAs($admin)->get(route('admin.dashboard'));
        $reponse->assertOk();

        // Combobox input
        $reponse->assertSee('role="combobox"', false);
        $reponse->assertSee('aria-autocomplete="list"', false);
        $reponse->assertSee(':aria-expanded="ouverte.toString()"', false);
        $reponse->assertSee('aria-controls="palette-liste"', false);
        $reponse->assertSee('aria-haspopup="listbox"', false);
        $reponse->assertSee(':aria-activedescendant=', false);

        // Listbox and options
        $reponse->assertSee('id="palette-liste"', false);
        $reponse->assertSee('role="listbox"', false);
        $reponse->assertSee('role="option"', false);
        $reponse->assertSee(':aria-selected=', false);
        $reponse->assertSee(':id="\'palette-opt-\' + index"', false);

        // Live region
        $reponse->assertSee('aria-live="polite"', false);
        $reponse->assertSee('aria-atomic="true"', false);
        $reponse->assertSee('x-text="messageStatut"', false);
    }

    // ── LOT 4 : Recherche Mobile et ergonomie tactile ──

    public function test_palette_ergonomie_mobile(): void
    {
        $this->creerFaculte();
        $admin = $this->creerCompte('mob.admin@universite.test', 'admin_scolarite');

        $reponse = $this->actingAs($admin)->get(route('admin.dashboard'));
        $reponse->assertOk();

        // Cibles tactiles >= 44px
        $reponse->assertSee('min-h-[44px]', false);

        // Bouton tactile de fermeture dédié au mobile
        $reponse->assertSee('aria-label="Fermer la palette"', false);

        // Défilement fluide sans overflow
        $reponse->assertSee('overscroll-contain', false);
        $reponse->assertSee('max-h-[55vh]', false);

        // Réduction du mouvement
        $reponse->assertSee('motion-reduce:transition-none', false);
    }
}
