<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DossierCorrection;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskEvenement;
use App\Modules\Tasks\Services\TaskService;
use App\Services\InscriptionService;
use App\Services\Verification\CorrectionService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LOOP-UNIREGISTRE-CENTRE-TRAVAIL-FINAL-GATES-AUTO-PASS-03 (LOT 1)
 *
 * Validation de concurrence multi-processus RÉELLE sur moteur MySQL InnoDB isolé.
 * Utilise pcntl_fork() avec des connexions indépendantes dédiées.
 *
 * Base dédiée obligatoire : `inscription_uniq_test` (sécurisé par TestCase).
 * Activation explicite : `CONCURRENCE_MYSQL=1`.
 */
class CentreTravailConcurrenceMysqlTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_DEDIEE = 'inscription_uniq_test';

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private User $agent1;

    private User $agent2;

    protected function setUp(): void
    {
        if (getenv('CONCURRENCE_MYSQL') !== '1') {
            parent::setUp();
            $this->markTestSkipped('Concurrence MySQL dédiée désactivée (CONCURRENCE_MYSQL=1 requis).');
        }

        if ((getenv('DB_CONNECTION') ?: '') !== 'mysql' || (getenv('DB_DATABASE') ?: '') !== self::BASE_DEDIEE) {
            parent::setUp();
            $this->fail(
                'REFUS : exécution concurrence MySQL exige DB_CONNECTION=mysql et '
                .'DB_DATABASE='.self::BASE_DEDIEE.' (base dédiée uniquement).'
            );
        }

        parent::setUp();

        if (DB::getDriverName() !== 'mysql' || DB::getDatabaseName() !== self::BASE_DEDIEE) {
            $this->fail('REFUS : la connexion effective ne pointe pas vers la base dédiée.');
        }

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Extension pcntl indisponible.');
        }

        config(['modules.tasks.active' => true]);

        $this->purgerBaseDediee();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté Concurrence',
            'code' => 'FCC',
            'description' => 'Test MySQL',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->agent1 = User::create([
            'name' => 'Agent Concurrence 1',
            'email' => 'agent1.conc@uniregistre.tn',
            'password' => bcrypt('Secret@123'),
            'role' => 'admin_scolarite',
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->agent2 = User::create([
            'name' => 'Agent Concurrence 2',
            'email' => 'agent2.conc@uniregistre.tn',
            'password' => bcrypt('Secret@123'),
            'role' => 'admin_scolarite',
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);
    }

    protected function tearDown(): void
    {
        if (getenv('CONCURRENCE_MYSQL') === '1'
            && DB::getDriverName() === 'mysql'
            && DB::getDatabaseName() === self::BASE_DEDIEE) {
            $this->purgerBaseDediee();
        }

        parent::tearDown();
    }

    private function purgerBaseDediee(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        foreach ([
            'task_evenements',
            'tasks',
            'dossier_corrections',
            'dossier_verifications',
            'historique_academique',
            'situation_etudiants',
            'inscriptions_academiques',
            'etudiants',
            'users',
            'facultes',
            'annees_universitaires',
        ] as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                DB::table($table)->truncate();
            }
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function creerEtudiant(string $cin): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'num_inscription' => 'MAT-CONC-'.$cin,
            'nom' => 'Test',
            'prenom' => 'Concurrence',
            'email' => strtolower($cin).'@universite.test',
            'date_naissance' => '2004-01-01',
            'sexe' => 'M',
            'telephone' => '98000001',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => $this->annee->libelle,
            'adresse' => 'Rue Test',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2023',
            'password' => bcrypt('Secret@123'),
        ]);
        $etudiant->definirStatut('valide');

        return $etudiant;
    }

    private function creerInscription(Etudiant $etudiant, string $statut = 'en_cours'): InscriptionAcademique
    {
        return InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'filiere' => 'Informatique',
            'niveau' => 'L1',
            'statut' => $statut,
        ]);
    }

    /**
     * @return array<int, int> Codes de sortie des processus forked
     */
    private function executerEnParallele(int $nbWorkers, callable $tentative): array
    {
        $enfants = [];

        for ($i = 0; $i < $nbWorkers; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('pcntl_fork impossible.');
            }

            if ($pid === 0) {
                DB::purge('mysql');
                DB::reconnect('mysql');
                DB::disableQueryLog();
                usleep(random_int(1000, 20000));

                try {
                    $tentative($i);
                    exit(0);
                } catch (DomainException $e) {
                    exit(10);
                } catch (\Throwable $e) {
                    exit(20);
                }
            }

            $enfants[$i] = $pid;
        }

        $codes = [];
        foreach ($enfants as $index => $pid) {
            pcntl_waitpid($pid, $statut);
            $codes[$index] = pcntl_wexitstatus($statut);
        }

        DB::purge('mysql');
        DB::reconnect('mysql');

        return $codes;
    }

    // ── 1. Deux validations simultanées du même dossier ───────────────────
    public function test_mysql_deux_validations_simultanees_du_meme_dossier(): void
    {
        $etudiant = $this->creerEtudiant('11223344');
        $inscription = $this->creerInscription($etudiant, 'en_cours');
        $service = app(InscriptionService::class);
        $agentId = (int) $this->agent1->id;

        $codes = $this->executerEnParallele(2, function () use ($service, $inscription, $agentId) {
            $service->transiter($inscription, 'valide', $agentId, 'Validation concurrente');
        });

        $succes = array_filter($codes, fn ($c) => $c === 0);
        $refus = array_filter($codes, fn ($c) => $c === 10);

        $this->assertCount(1, $succes, 'Exactement un processus valide.');
        $this->assertCount(1, $refus, 'Le second processus reçoit DomainException.');
        $this->assertNotContains(20, $codes, 'Aucune erreur technique.');
        $this->assertEquals('valide', $inscription->fresh()->statut);
    }

    // ── 2. Validation contre rejet simultanés ────────────────────────────
    public function test_mysql_validation_contre_rejet_simultanes(): void
    {
        $etudiant = $this->creerEtudiant('22334455');
        $inscription = $this->creerInscription($etudiant, 'en_cours');
        $service = app(InscriptionService::class);
        $agentId = (int) $this->agent1->id;

        $codes = $this->executerEnParallele(2, function ($index) use ($service, $inscription, $agentId) {
            if ($index === 0) {
                $service->transiter($inscription, 'valide', $agentId, 'Validation');
            } else {
                $service->transiter($inscription, 'ajourne', $agentId, 'Ajournement');
            }
        });

        $succes = array_filter($codes, fn ($c) => $c === 0);
        $refus = array_filter($codes, fn ($c) => $c === 10);

        $this->assertCount(1, $succes, 'Exactement une des deux décisions gagne.');
        $this->assertCount(1, $refus, 'L’autre décision concurrente est rejetée.');
        $this->assertNotContains(20, $codes, 'Aucun crash.');
        $this->assertContains($inscription->fresh()->statut, ['valide', 'ajourne']);
    }

    // ── 3. Deux décisions concurrentes à quatre yeux ─────────────────────
    public function test_mysql_deux_decisions_concurrentes_a_quatre_yeux(): void
    {
        $etudiant = $this->creerEtudiant('33445566');
        $inscription = $this->creerInscription($etudiant, 'en_cours');

        $correction = DossierCorrection::create([
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'champ' => 'prenom',
            'ancienne_valeur' => 'Old',
            'nouvelle_valeur' => 'New',
            'motif' => 'Correction concurrente',
            'statut' => 'proposee',
            'propose_par' => $this->agent1->id,
        ]);

        $service = app(CorrectionService::class);
        $decideurId = (int) $this->agent2->id;

        $codes = $this->executerEnParallele(2, function () use ($service, $correction, $decideurId) {
            $service->approuver($correction, $decideurId, 'Approbation concurrente');
        });

        $this->assertCount(1, array_filter($codes, fn ($c) => $c === 0), 'Une seule approbation réussit.');
        $this->assertCount(1, array_filter($codes, fn ($c) => $c === 10), 'La seconde approbation lève DomainException.');
        $this->assertNotContains(20, $codes);
        $this->assertEquals('approuvee', $correction->fresh()->statut);
    }

    // ── 4. Deux créations de tâches pour une même inscription ────────────
    public function test_mysql_deux_creations_de_taches_pour_une_meme_inscription(): void
    {
        $etudiant = $this->creerEtudiant('44556677');
        $inscription = $this->creerInscription($etudiant, 'en_cours');
        $service = app(TaskService::class);
        $cle = "inscription:{$inscription->id}";

        $codes = $this->executerEnParallele(2, function () use ($service, $cle, $inscription, $etudiant) {
            $service->synchroniser($cle, [
                'titre' => "Contrôle dossier {$etudiant->nom}",
                'source' => 'inscription',
                'priorite' => 'normal',
                'faculte_id' => $etudiant->faculte_id,
                'data' => ['inscription_id' => $inscription->id],
            ]);
        });

        $this->assertNotContains(20, $codes, 'Aucune collision non gérée.');
        $this->assertSame(1, Task::where('cle_unique', $cle)->count(), 'Exactement une tâche créée sous concurrence MySQL.');
    }

    // ── 5. Deux escalades SLA simultanées ────────────────────────────────
    public function test_mysql_deux_escalades_sla_simultanees(): void
    {
        $tache = Task::create([
            'source' => 'inscription',
            'cle_unique' => 'tache:mysql:sla',
            'titre' => 'Tâche SLA MySQL',
            'statut' => 'pending',
            'priorite' => 'normal',
            'faculte_id' => $this->faculte->id,
            'echeance_a' => now()->subDay(),
        ]);

        $service = app(TaskService::class);
        $faculteId = (int) $this->faculte->id;

        $codes = $this->executerEnParallele(2, function () use ($service, $faculteId) {
            $service->escaladerTachesEnSouffrance($faculteId);
        });

        $this->assertNotContains(20, $codes);
        $this->assertEquals('critical', $tache->fresh()->priorite);

        // L'audit atomique ne doit avoir qu'un seul enregistrement
        $logs = TaskEvenement::where('task_id', $tache->id)
            ->where('action', 'escalade_sla')
            ->count();
        $this->assertSame(1, $logs, 'Exactement un événement d’escalade SLA tracé.');
    }

    // ── 6. Deux réconciliations simultanées ──────────────────────────────
    public function test_mysql_deux_reconciliations_simultanees(): void
    {
        $etudiant = $this->creerEtudiant('55667788');
        $inscription = $this->creerInscription($etudiant, 'valide');

        $tache = Task::create([
            'source' => 'inscription',
            'cle_unique' => "inscription:{$inscription->id}",
            'titre' => 'Tâche à réconcilier',
            'statut' => 'pending',
            'faculte_id' => $this->faculte->id,
            'data' => ['inscription_id' => $inscription->id],
        ]);

        $service = app(TaskService::class);
        $faculteId = (int) $this->faculte->id;

        $codes = $this->executerEnParallele(2, function () use ($service, $faculteId) {
            $service->reconcilierTachesOrphelines($faculteId);
        });

        $this->assertNotContains(20, $codes);
        $this->assertEquals('completed', $tache->fresh()->statut);

        $logs = TaskEvenement::where('task_id', $tache->id)
            ->where('action', 'reconciliation_validee')
            ->count();
        $this->assertSame(1, $logs, 'Exactement un événement de réconciliation tracé.');
    }

    // ── 7. Action groupée contre mutation individuelle ───────────────────
    public function test_mysql_action_groupee_contre_mutation_individuelle(): void
    {
        $tache = Task::create([
            'source' => 'inscription',
            'cle_unique' => 'tache:mysql:groupe_indiv',
            'titre' => 'Tâche Bulk vs Indiv',
            'statut' => 'pending',
            'priorite' => 'normal',
            'faculte_id' => $this->faculte->id,
        ]);

        $service = app(TaskService::class);
        $agentId = (int) $this->agent1->id;

        $codes = $this->executerEnParallele(2, function ($index) use ($service, $tache, $agentId) {
            if ($index === 0) {
                // Action groupée : démarrer
                $service->actionGroupee([$tache->id], 'demarrer', $agentId);
            } else {
                // Action individuelle : démarrer
                $service->transiter($tache, 'in_progress', $agentId, 'Transition individuelle');
            }
        });

        $this->assertNotContains(20, $codes);
        $this->assertEquals('in_progress', $tache->fresh()->statut);
    }
}
