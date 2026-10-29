<?php

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Referentiel\Models\Programme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * LOOP-14 — vérification indépendante du simulateur de scénarios.
 *
 * Deux contrôles négatifs qui ne figuraient pas dans la suite du lot :
 * 1. un admin_faculte ne peut pas imposer une autre faculté via les
 *    sous-tableaux `scenario_a` / `scenario_b` (contournement du
 *    résolveur de périmètre) ;
 * 2. le compteur `hors_chaine_programme` reste dans le périmètre
 *    facultaire demandé (aucun agrégat inter-facultés).
 */
class Loop14SimulateurScenariosVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private Programme $programmeA;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('modules.simulateur-scenarios.active', true);

        $this->faculteA = Faculte::create([
            'nom' => 'Sciences LOOP14', 'code' => 'F14A', 'description' => 'Test', 'active' => true,
        ]);
        $this->faculteB = Faculte::create([
            'nom' => 'Droit LOOP14', 'code' => 'F14B', 'description' => 'Test', 'active' => true,
        ]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->programmeA = Programme::create([
            'faculte_id' => $this->faculteA->id, 'code' => 'LIC14A', 'nom' => 'Licence LOOP14 A',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
    }

    private function creerInscription(Faculte $faculte, ?Programme $programme, string $suffixe, ?AnneeUniversitaire $annee = null): InscriptionAcademique
    {
        $etudiant = Etudiant::create([
            'cin' => 'CIN14'.uniqid(),
            'nom' => 'Nom'.$suffixe, 'prenom' => 'Prenom'.$suffixe,
            'email' => 'loop14-'.$suffixe.'-'.uniqid().'@example.test', 'sexe' => 'M',
            'faculte_id' => $faculte->id, 'niveau' => 'L1', 'annee_academique' => '2026-2027',
            'adresse' => 'Rue test', 'ville' => 'Dakar', 'code_postal' => '10000', 'annee_bac' => '2020',
        ]);

        return InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => ($annee ?? $this->annee)->id,
            'programme_id' => $programme?->id,
            'niveau' => 'L1',
            'statut' => 'valide',
        ]);
    }

    private function adminFaculte(Faculte $faculte): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Admin '.$faculte->code,
            'email' => 'loop14-admin-'.uniqid().'@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $faculte->id,
        ], 'admin_faculte');
    }

    public function test_un_admin_faculte_ne_peut_pas_forcer_une_autre_faculte_dans_les_sous_scenarios(): void
    {
        $this->creerInscription($this->faculteB, null, 'B1');
        $this->creerInscription($this->faculteB, null, 'B2');
        $this->creerInscription($this->faculteB, null, 'B3');

        $reponse = $this->actingAs($this->adminFaculte($this->faculteA))
            ->postJson(route('admin.simulateur-scenarios.comparer'), [
                'scenario_a' => ['faculte_id' => $this->faculteB->id, 'delta_effectifs' => 0.10],
                'scenario_b' => ['faculte_id' => $this->faculteB->id, 'delta_effectifs' => 0.20],
            ]);

        $reponse->assertOk();
        $reponse->assertJsonPath('perimetre.faculte_id', $this->faculteA->id);
        $reponse->assertJsonPath('scenarios.a.perimetre.faculte_id', $this->faculteA->id);
        $reponse->assertJsonPath('scenarios.b.perimetre.faculte_id', $this->faculteA->id);
        $reponse->assertJsonPath('scenarios.a.axes.effectifs.base', 0);
        $reponse->assertJsonPath('scenarios.b.axes.effectifs.base', 0);
    }

    public function test_le_compteur_hors_chaine_programme_reste_dans_le_perimetre_facultaire(): void
    {
        $this->creerInscription($this->faculteA, $this->programmeA, 'A1');
        $this->creerInscription($this->faculteA, null, 'A2');
        $this->creerInscription($this->faculteB, null, 'B1');

        $reponse = $this->actingAs($this->adminFaculte($this->faculteA))
            ->postJson(route('admin.simulateur-scenarios.executer'), [
                'delta_effectifs' => 0.0,
            ]);

        $reponse->assertOk();
        $reponse->assertJsonPath('perimetre.faculte_id', $this->faculteA->id);
        $reponse->assertJsonPath('axes.effectifs.base', 1);
        $reponse->assertJsonPath('axes.effectifs.hors_chaine_programme', 1);
    }

    /**
     * Troisième contrôle négatif : `faculte_id` envoyé explicitement à null
     * ne doit PAS lever le verrou facultaire. Le résolveur serveur retombe
     * sur la faculté de l'admin_faculte ; le DTO ne doit donc jamais voir
     * la clé null (sinon `isset()` la traite comme absente = transversal).
     */
    public function test_un_faculte_id_null_explicite_ne_doit_pas_ouvrir_le_perimetre_transversal(): void
    {
        $this->creerInscription($this->faculteA, $this->programmeA, 'A1');
        $this->creerInscription($this->faculteB, null, 'B1');
        $this->creerInscription($this->faculteB, null, 'B2');

        $reponse = $this->actingAs($this->adminFaculte($this->faculteA))
            ->postJson(route('admin.simulateur-scenarios.executer'), [
                'faculte_id' => null,
                'delta_effectifs' => 0.0,
            ]);

        $reponse->assertOk();
        $reponse->assertJsonPath('perimetre.faculte_id', $this->faculteA->id);
        $reponse->assertJsonPath('axes.effectifs.base', 1);
    }

    public function test_le_filtre_annee_est_applique_et_ne_fuit_pas_hors_faculte(): void
    {
        $programmeB = Programme::create([
            'faculte_id' => $this->faculteB->id, 'code' => 'LIC14B', 'nom' => 'Licence LOOP14 B',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);

        $anneePrecedente = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-06-30', 'active' => false,
        ]);

        $this->creerInscription($this->faculteA, $this->programmeA, 'A-2026', $this->annee);
        $this->creerInscription($this->faculteA, $this->programmeA, 'A-2025', $anneePrecedente);
        $this->creerInscription($this->faculteB, $programmeB, 'B-2026', $this->annee);

        $admin = $this->adminFaculte($this->faculteA);

        $sansFiltre = $this->actingAs($admin)
            ->postJson(route('admin.simulateur-scenarios.executer'), ['delta_effectifs' => 0.0]);
        $sansFiltre->assertOk();
        $sansFiltre->assertJsonPath('axes.effectifs.base', 2);

        $surAnneePrecedente = $this->actingAs($admin)
            ->postJson(route('admin.simulateur-scenarios.executer'), [
                'annee_id' => $anneePrecedente->id,
                'delta_effectifs' => 0.0,
            ]);
        $surAnneePrecedente->assertOk();
        $surAnneePrecedente->assertJsonPath('perimetre.faculte_id', $this->faculteA->id);
        $surAnneePrecedente->assertJsonPath('perimetre.annee_id', $anneePrecedente->id);
        $surAnneePrecedente->assertJsonPath('axes.effectifs.base', 1);

        // L'année ne doit jamais ouvrir le périmètre facultaire : la
        // faculté B n'apparaît dans aucun agrégat.
        $surAnneeCourante = $this->actingAs($admin)
            ->postJson(route('admin.simulateur-scenarios.executer'), [
                'annee_id' => $this->annee->id,
                'delta_effectifs' => 0.0,
            ]);
        $surAnneeCourante->assertOk();
        $surAnneeCourante->assertJsonPath('axes.effectifs.base', 1);
    }

    public function test_le_plafond_de_volume_est_explicite_sans_tronquer_les_agregats(): void
    {
        $this->creerInscription($this->faculteA, $this->programmeA, 'A1');

        $reponse = $this->actingAs($this->adminFaculte($this->faculteA))
            ->postJson(route('admin.simulateur-scenarios.executer'), ['delta_effectifs' => 0.0]);

        $reponse->assertOk();
        $reponse->assertJsonPath('axes.effectifs.plafond_lignes', 5000);
        $reponse->assertJsonPath('axes.effectifs.plafond_atteint', false);
        $reponse->assertJsonPath('axes.capacite.plafond_atteint', false);
        $reponse->assertJsonPath('axes.charges.plafond_atteint', false);
        $reponse->assertJsonPath('axes.programmes.plafond_atteint', false);

        // Le compte reste exact : aucune troncature silencieuse à 5 000.
        $reponse->assertJsonPath('axes.effectifs.base', 1);
    }

    public function test_l_ecran_du_simulateur_est_accessible_et_calcule_un_scenario(): void
    {
        $this->creerInscription($this->faculteA, $this->programmeA, 'A1');

        $admin = $this->adminFaculte($this->faculteA);

        $this->actingAs($admin)
            ->get(route('admin.simulateur-scenarios.index'))
            ->assertOk()
            ->assertSee('Simulateur de scénarios')
            ->assertSee('Périmètre verrouillé sur votre faculté.');

        $this->actingAs($admin)
            ->get(route('admin.simulateur-scenarios.index', [
                'executer' => 1,
                'delta_effectifs' => 0.10,
            ]))
            ->assertOk()
            ->assertSee('Effectifs (base)')
            ->assertSee('Effectifs — base');
    }
}
