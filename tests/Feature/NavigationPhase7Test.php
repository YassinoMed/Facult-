<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\FavoriUtilisateur;
use App\Models\User;
use App\Models\VueSauvegardee;
use App\Modules\Schedule\Models\Group;
use App\Services\Interface\RecentsService;
use App\Services\PreferencesService;
use App\Support\Actions\QuickActionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 7 — UX et navigation intelligente : saut direct, parité
 * instantanée, actions rapides contextuelles, favoris, préférences,
 * vues par défaut. Registre de recherche partagé, sans duplication.
 */
class NavigationPhase7Test extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteA = Faculte::create(['nom' => 'Faculté des Sciences', 'code' => 'FST', 'description' => 'S', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'Faculté de Médecine', 'code' => 'FMP', 'description' => 'M', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.nav7@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    private function creerEtudiant(string $cin, int $faculteId): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => strtolower($cin).'@nav7.test', 'date_naissance' => '2004-01-01',
            'sexe' => 'F', 'faculte_id' => $faculteId, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');

        return $etudiant->refresh();
    }

    public function test_saut_direct_cin_et_perimetre(): void
    {
        $etudiant = $this->creerEtudiant('BKNAV001', $this->faculteA->id);

        // CIN exacte → 302 vers le dossier (pas de liste), terme conservé (?q=).
        $this->actingAs($this->admin)->get(route('admin.recherche', ['q' => 'BKNAV001']))
            ->assertRedirect(route('admin.etudiants.show', $etudiant).'?q=BKNAV001');

        // Hors périmètre : aucune redirection (pas de fuite d'existence).
        $adminB = User::creerCompteBackOffice([
            'name' => 'Fac B', 'email' => 'facb.nav7@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');

        $this->actingAs($adminB)->get(route('admin.recherche', ['q' => 'BKNAV001']))
            ->assertOk()->assertDontSee('Benali');
    }

    public function test_instantanee_paritaire_et_gatee(): void
    {
        $this->creerEtudiant('BKNAV002', $this->faculteA->id);
        Group::create([
            'code' => 'NAV-G1', 'nom' => 'Nav Groupe', 'faculte_id' => $this->faculteA->id,
            'niveau' => 'L1', 'actif' => true,
        ]);

        $reponse = $this->actingAs($this->admin)
            ->getJson(route('admin.recherche.instantanee', ['q' => 'NAV']))
            ->assertOk();

        $sections = collect($reponse->json())->pluck('section')->unique()->all();
        $this->assertContains('Étudiants', $sections);
        $this->assertContains('Groupes', $sections);

        // Utilisateurs : super-admin uniquement (jamais exposé sinon).
        $consult = User::creerCompteBackOffice([
            'name' => 'Consult', 'email' => 'consult.nav7@universite.test', 'password' => 'Motdepasse@123',
        ], 'consultation');
        $super = User::creerCompteBackOffice([
            'name' => 'Super', 'email' => 'super.nav7@universite.test', 'password' => 'Motdepasse@123',
        ], 'super_admin');

        $sectionsSuper = collect($this->actingAs($super)
            ->getJson(route('admin.recherche.instantanee', ['q' => 'Super']))
            ->assertOk()->json())->pluck('section')->unique()->all();
        $this->assertContains('Utilisateurs', $sectionsSuper);

        $sectionsConsult = collect($this->actingAs($consult)
            ->getJson(route('admin.recherche.instantanee', ['q' => 'Super']))
            ->assertOk()->json())->pluck('section')->unique()->all();
        $this->assertNotContains('Utilisateurs', $sectionsConsult);
    }

    public function test_actions_rapides_contextuelles(): void
    {
        $etudiant = $this->creerEtudiant('BKNAV003', $this->faculteA->id);

        // Registre : contexte inconnu → vide ; route manquante → masquée.
        $registre = app(QuickActionRegistry::class);
        $this->assertSame([], $registre->pour('inexistant', $etudiant, $this->admin));

        $registre->enregistrer([
            'key' => 'test.future', 'label' => 'Future', 'contextes' => ['etudiant.show'],
            'visible' => fn () => true, 'url' => fn () => '',
        ]);
        $cles = array_column($registre->pour('etudiant.show', $etudiant, $this->admin), 'key');
        $this->assertNotContains('test.future', $cles);
        $this->assertContains('etudiant.archive', $cles);
        $this->assertContains('etudiant.export', $cles);

        // Rendu dossier : le menu apparaît pour un rôle autorisé.
        $this->actingAs($this->admin)->get(route('admin.etudiants.show', $etudiant))
            ->assertOk()->assertSee('Actions rapides', false);
    }

    public function test_favoris_personnels_et_contexte(): void
    {
        $etudiant = $this->creerEtudiant('BKNAV004', $this->faculteA->id);

        $this->actingAs($this->admin)->post(route('admin.favoris.basculer'), [
            'type' => 'etudiant', 'reference' => (string) $etudiant->id,
            'titre' => 'Sara Benali', 'lien' => '/admin/etudiants/'.$etudiant->id,
        ])->assertRedirect();
        $this->assertSame(1, FavoriUtilisateur::where('user_id', $this->admin->id)->count());

        // Re-clic : retrait idempotent.
        $this->actingAs($this->admin)->post(route('admin.favoris.basculer'), [
            'type' => 'etudiant', 'reference' => (string) $etudiant->id,
            'titre' => 'Sara Benali', 'lien' => '/admin/etudiants/'.$etudiant->id,
        ])->assertRedirect();
        $this->assertSame(0, FavoriUtilisateur::where('user_id', $this->admin->id)->count());

        // Suppression du favori d'autrui : 404.
        $autre = User::creerCompteBackOffice([
            'name' => 'Autre', 'email' => 'autre.nav7@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $favori = FavoriUtilisateur::create([
            'user_id' => $autre->id, 'type' => 'etudiant', 'reference' => '1',
            'titre' => 'X', 'lien' => '/admin/etudiants/1',
        ]);
        $this->actingAs($this->admin)->delete(route('admin.favoris.supprimer', $favori))->assertNotFound();

        // Contexte palette : favoris + récents personnels uniquement.
        app(RecentsService::class)
            ->enregistrer($this->admin->id, 'etudiant', (string) $etudiant->id, 'Sara', '/x');
        $contexte = $this->actingAs($this->admin)->getJson(route('admin.recherche.contexte'))->assertOk()->json();
        $this->assertCount(1, $contexte['recents']);

        $contexteAutre = $this->actingAs($autre)->getJson(route('admin.recherche.contexte'))->assertOk()->json();
        $this->assertCount(0, $contexteAutre['recents']);
        $this->assertCount(1, $contexteAutre['favoris']);
    }

    public function test_preferences_lignes_et_vue_defaut(): void
    {
        foreach (range(1, 7) as $i) {
            $this->creerEtudiant('BKNAV1'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), $this->faculteA->id);
        }

        $this->actingAs($this->admin)->patch(route('admin.preferences.update'), [
            'densite' => 'compact', 'lignes_par_page' => 5,
        ])->assertRedirect();
        $this->assertSame(5, app(PreferencesService::class)->lire($this->admin->id)['lignes_par_page']);

        $reponse = $this->actingAs($this->admin)->get(route('admin.etudiants.index'))->assertOk();
        $this->assertSame(5, $reponse->viewData('etudiants')->perPage());

        // Vue par défaut appliquée au chargement sans filtres.
        VueSauvegardee::create([
            'user_id' => $this->admin->id, 'tableau' => 'etudiants', 'nom' => 'Rejetés',
            'filtres' => ['statut' => 'rejete'], 'par_defaut' => true,
        ]);

        $reponse2 = $this->actingAs($this->admin)->get(route('admin.etudiants.index'))->assertOk();
        $this->assertSame(0, $reponse2->viewData('etudiants')->total());
    }

    public function test_recherche_bornee_en_temps(): void
    {
        $this->creerEtudiant('BKNAV005', $this->faculteA->id);

        $debut = microtime(true);
        $this->actingAs($this->admin)->get(route('admin.recherche', ['q' => 'BKNAV005']))->assertRedirect();
        $duree = microtime(true) - $debut;

        $this->assertLessThan(5, $duree);
    }

    public function test_nav_affiche_documents_academiques(): void
    {
        $reponse = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();

        $reponse->assertSee('Documents académiques', false);
        $reponse->assertSee(route('admin.documents-center.index'), false);
    }

    public function test_nav_documents_academiques_actif_sur_centre(): void
    {
        $reponse = $this->actingAs($this->admin)->get(route('admin.documents-center.index'))->assertOk();

        $reponse->assertSee('Documents académiques', false);
        $reponse->assertSee('aria-current="page"', false);
    }
}
