<?php

namespace Tests\Concerns;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Iam\HabilitationIam;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\SimulationTemporelle\Models\ScenarioTemporel;
use App\Support\Iam\MoteurAutorisation;
use Illuminate\Support\Facades\Config;

/**
 * ST-14 / LOT B — contexte synthétique partagé par SIM-14, SIM-15, SIM-16
 * et SIM-19.
 *
 * Périmètre : faculté A, L1=10, L2=20, L3=30 (total 60), année 2026-2027.
 * Les tests calculent leurs résultats attendus À LA MAIN ; ce trait ne
 * fournit que le décor et les helpers d'exécution.
 */
trait PrepareSimulationTemporelleParNiveau
{
    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private User $admin;

    private User $adminB;

    private User $sansPermission;

    private Niveau $niveauL1;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('modules.simulation-temporelle.active', true);

        $this->faculteA = Faculte::create(['nom' => 'Sciences', 'code' => 'FST', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'Droit', 'code' => 'FDEG', 'active' => true]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'LOT-B', 'email' => 'lotb-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->adminB = User::creerCompteBackOffice([
            'name' => 'LOT-B-B', 'email' => 'lotbb-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');
        $this->sansPermission = User::creerCompteBackOffice([
            'name' => 'LOT-B-N', 'email' => 'lotbn-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->habiliter($this->admin);
        $this->habiliter($this->adminB);

        $programme = Programme::create([
            'faculte_id' => $this->faculteA->id, 'code' => 'PFSTB', 'nom' => 'Prog',
            'statut' => 'actif', 'actif' => true, 'duree_annees' => 3,
        ]);
        foreach (['L1' => 1, 'L2' => 2, 'L3' => 3] as $code => $ordre) {
            $niveau = Niveau::create([
                'programme_id' => $programme->id, 'code' => $code, 'nom' => $code,
                'ordre' => $ordre, 'actif' => true,
            ]);
            if ($code === 'L1') {
                $this->niveauL1 = $niveau;
            }
            for ($i = 0; $i < 10 * $ordre; $i++) {
                $etudiant = Etudiant::create([
                    'cin' => 'CIN-'.uniqid(), 'nom' => 'N'.uniqid(), 'prenom' => 'P',
                    'email' => 'e-'.uniqid().'@u.test', 'sexe' => 'M',
                    'faculte_id' => $this->faculteA->id, 'niveau' => $code, 'annee_academique' => '2026-2027',
                    'adresse' => 'Rue', 'ville' => 'Dakar', 'code_postal' => '10000', 'annee_bac' => '2024',
                ]);
                InscriptionAcademique::create([
                    'etudiant_id' => $etudiant->id,
                    'programme_id' => $programme->id,
                    'niveau' => $code,
                    'niveau_id' => $niveau->id,
                    'annee_universitaire_id' => $this->annee->id,
                    'statut' => 'valide',
                ]);
            }
        }
    }

    private function habiliter(User $user): void
    {
        foreach ([
            'simulation.view', 'simulation.gerer', 'simulation.executer',
            'simulation.export', 'simulation.explication',
            'simulation.gouvernance.proposer', 'simulation.gouvernance.decider',
        ] as $code) {
            HabilitationIam::create([
                'user_id' => $user->id,
                'permission_code' => $code,
                'statut' => HabilitationIam::STATUT_ACTIVE,
                'debut_le' => now(),
            ]);
        }
        MoteurAutorisation::invalider(null);
    }

    /**
     * Socle commun des critères LOT B : jeu global COMPLET et saisi, sans
     * aucune valeur inventée. Les surcharges par niveau sont ajoutées par
     * chaque scénario.
     *
     * @param  array<string, mixed>  $surcharges
     * @return array<string, mixed>
     */
    protected function criteresBase(array $surcharges = []): array
    {
        return array_merge([
            'delta_admissions' => 0.0,
            'bornes' => ['min' => 0.0, 'max' => 0.0],
            'horizon_annees' => 2,
            'taux' => ['passage' => 0.6, 'redoublement' => 0.2, 'abandon' => 0.2],
        ], $surcharges);
    }

    /** @param array<string, mixed> $criteres */
    protected function creerScenario(array $criteres, string $suffixe = 'B'): ScenarioTemporel
    {
        return ScenarioTemporel::create([
            'code' => 'SIM'.$suffixe.'-'.uniqid(),
            'libelle' => 'Scénario LOT B '.$suffixe,
            'domaine' => 'effectifs_cohortes',
            'faculte_id' => $this->faculteA->id,
            'criteres' => $criteres,
            'contraintes' => [],
            'objectifs' => [],
            'version' => 1,
            'cree_par' => $this->admin->id,
        ]);
    }

    /** @return array<string, mixed> */
    protected function executer(ScenarioTemporel $scenario, string $graine): array
    {
        $reponse = $this->actingAs($this->admin)->postJson(
            route('admin.simulation-temporelle.executer', ['id' => $scenario->id]),
            [
                'annee_universitaire_id' => $this->annee->id,
                'graine' => $graine,
                'nb_variantes' => 1,
            ],
        );
        $reponse->assertOk();

        return $reponse->json('execution');
    }

    /** @param array<string, mixed> $criteres @return array<string, mixed> */
    protected function donneesDefinition(array $criteres): array
    {
        return [
            'code' => 'LOTB-DEF-'.uniqid(),
            'libelle' => 'Scénario LOT B (définition)',
            'faculte_id' => $this->faculteA->id,
            'criteres' => $criteres,
            'contraintes' => [],
            'objectifs' => [['type' => 'stabilite', 'poids' => 1]],
        ];
    }

    /**
     * Paramètres catalogue LOT B : socle + surcharges.
     *
     * @param  array<string, mixed>  $surcharges
     * @return array<string, mixed>
     */
    protected function parametresCatalogue(array $surcharges = []): array
    {
        return $this->criteresBase($surcharges);
    }

    protected function assertRefuseDefinition(callable $action, string $messagePart): void
    {
        try {
            $action();
        } catch (\DomainException $e) {
            $this->assertStringContainsString($messagePart, $e->getMessage());

            return;
        }

        $this->fail('Une DomainException était attendue.');
    }

    protected function assertRefuseCatalogue(callable $action, string $messagePart): void
    {
        try {
            $action();
        } catch (\DomainException $e) {
            $this->assertStringContainsString($messagePart, $e->getMessage());

            return;
        }

        $this->fail('Une DomainException catalogue était attendue.');
    }
}
