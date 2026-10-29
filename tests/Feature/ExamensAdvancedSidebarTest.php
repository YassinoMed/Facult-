<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\User;
use App\Modules\Evaluation\Models\SessionExamen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExamensAdvancedSidebarTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Test',
            'email' => 'admin.test'.uniqid().'@universite.test',
            'password' => 'Motdepasse@123',
            'actif' => true,
        ], 'super_admin');
    }

    public function test_les_12_menus_examens_sont_accessibles_pour_un_administrateur(): void
    {
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        SessionExamen::create([
            'annee_universitaire_id' => $annee->id,
            'code' => 'SESS-TEST-01',
            'nom' => 'Session Principale 2026',
            'type' => 'principale',
            'date_debut' => now(),
            'date_fin' => now()->addDays(14),
            'statut' => 'open',
        ]);

        $routes = [
            'admin.evaluation.examens.tableau-de-bord',
            'admin.evaluation.examens.sessions',
            'admin.evaluation.examens.configurations',
            'admin.evaluation.examens.eligibilite',
            'admin.evaluation.examens.groupes',
            'admin.evaluation.examens.salles',
            'admin.evaluation.examens.planification',
            'admin.evaluation.examens.surveillance',
            'admin.evaluation.examens.simulation',
            'admin.evaluation.examens.publication',
            'admin.evaluation.examens.convocations',
            'admin.evaluation.examens.historique',
        ];

        foreach ($routes as $nomRoute) {
            $reponse = $this->actingAs($this->admin)->get(route($nomRoute));
            $reponse->assertOk();
            $reponse->assertSee('Examens Advanced — 12 Menus fonctionnels');
        }
    }

    public function test_le_sidebar_affiche_la_section_examens_avec_les_12_liens(): void
    {
        $reponse = $this->actingAs($this->admin)->get(route('admin.evaluation.examens.tableau-de-bord'));
        $reponse->assertOk();

        $reponse->assertSee('aria-controls="nav-examens-advanced"', false);
        $reponse->assertSee('Tableau de bord');
        $reponse->assertSee('Sessions d’examen');
        $reponse->assertSee('Configurations & Modules');
        $reponse->assertSee('Éligibilité candidats');
        $reponse->assertSee('Groupes d’examen');
        $reponse->assertSee('Salles & Capacités');
        $reponse->assertSee('Planification auto');
        $reponse->assertSee('Surveillance');
        $reponse->assertSee('Simulation & Qualité');
        $reponse->assertSee('Validation & Publication');
        $reponse->assertSee('Convocations');
        $reponse->assertSee('Historique & Audit');
    }

    public function test_le_bandeau_des_menus_reste_un_point_de_repere_accessible(): void
    {
        $reponse = $this->actingAs($this->admin)->get(route('admin.evaluation.examens.tableau-de-bord'));
        $reponse->assertOk();

        // Navigation transversale : repère ARIA nommé, onglet courant exposé,
        // et accroche de mouvement utilisée pour recadrer l'onglet actif.
        $reponse->assertSee('aria-label="Menus des examens avancés"', false);
        $reponse->assertSee('data-motion-tabs', false);
        $reponse->assertSee('aria-current="page"', false);
        $reponse->assertSee('data-motion-count', false);

        // L'en-tête situe le menu courant (famille + rang) sans transformer
        // l'index en parcours d'étapes ; les quatre familles sont séparées.
        $html = $reponse->getContent();
        $reponse->assertSee('Vue d’ensemble', false);
        $reponse->assertSee('sur 12', false);
        $this->assertSame(3, substr_count($html, 'examens-tabs-separateur'));
    }

    public function test_acces_refuse_pour_un_utilisateur_non_authentifie(): void
    {
        $reponse = $this->get(route('admin.evaluation.examens.tableau-de-bord'));
        $reponse->assertRedirect(route('admin.connexion.show'));
    }
}
