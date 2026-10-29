<?php

namespace Tests\Feature;

use App\Modules\Evaluation\Services\ExamChargeService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * §7.3 / §2.7 / §2.15 — preuves de CHARGE des examens.
 *
 * Ce test ne s'exécute que sur une base MySQL DÉDIÉE :
 * `EXAM_LOAD_DB_DRIVER=mysql` + `EXAM_LOAD_DB_DATABASE` (nom contenant
 * « _test », par défaut `inscription_etudiants_load_test`). Sans cette
 * configuration, il est ignoré : aucun résultat n'est inventé, et aucune
 * base de développement n'est touchée.
 *
 * Il prouve trois choses :
 *  1. le circuit complet (réconciliation, quatre yeux, gouvernance)
 *     s'exécute sans erreur sous charge ;
 *  2. le rapport expose p50/p95/p99, erreurs et mémoire exploitables ;
 *  3. la commande Artisan renvoie un code de retour cohérent avec le
 *     verdict, et refuse une base non dédiée.
 */
#[Group('charge')]
class ExamensChargeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array((string) env('EXAM_LOAD_DB_DRIVER', ''), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped(
                'Charge NON EXÉCUTÉE : définir EXAM_LOAD_DB_DRIVER=mysql (ou mariadb) et une base dédiée '
                .'EXAM_LOAD_DB_DATABASE dont le nom contient « _test ».'
            );
        }
    }

    public function test_charge_complete_respecte_les_seuils_et_expose_des_percentiles(): void
    {
        /** @var ExamChargeService $service */
        $service = app(ExamChargeService::class);

        $rapport = $service->executer(3, 8);

        $this->assertTrue($rapport['respecte'], 'Charge non conforme : '.implode(' | ', $rapport['raisons']));
        $this->assertSame(0, $rapport['erreurs'], 'Aucune opération ne doit échouer sous charge.');
        $this->assertGreaterThan(0, $rapport['executions']);
        $this->assertLessThanOrEqual((float) env('EXAM_LOAD_P95_MS', 1500), $rapport['p95_ms']);
        $this->assertLessThanOrEqual((float) env('EXAM_LOAD_MAX_MEMOIRE_MB', 512), $rapport['memoire_pic_mb']);
        $this->assertLessThanOrEqual((float) env('EXAM_LOAD_MAX_ERREURS_PCT', 0.0), $rapport['taux_erreur_pct']);

        $operations = array_column($rapport['operations'], 'operation');
        foreach ([
            'reconciliation.tri_sources',
            'reconciliation.persister_ecarts',
            'reconciliation.instruire',
            'reconciliation.valider',
            'gouvernance.soumettre',
            'gouvernance.certifier',
            'gouvernance.approuver_1',
            'gouvernance.approuver_2',
            'gouvernance.publier',
            'reconciliation.liste',
        ] as $operation) {
            $this->assertContains($operation, $operations, "Opération non mesurée : {$operation}");
        }

        foreach ($rapport['operations'] as $operation) {
            $this->assertSame(0, $operation['erreurs'], 'Erreur sur '.$operation['operation']);
            $this->assertGreaterThanOrEqual($operation['p99_ms'], $operation['max_ms']);
            $this->assertGreaterThanOrEqual($operation['p50_ms'], $operation['p95_ms']);
        }

        // La base dédiée est bien celle qui a été migrée, jamais celle de dev.
        $this->assertStringContainsString('_test', (string) $rapport['infrastructure']['database']);

        $baseDev = (string) env('DB_DATABASE', '');
        if ($baseDev !== '') {
            $this->assertNotSame($baseDev, (string) $rapport['infrastructure']['database']);
        }

        // §2.7 — la matrice de rôles doit être STOCKABLE : sans cet ENUM,
        // MySQL tronque silencieusement une attribution dédiée (erreur 1265).
        $colonne = DB::selectOne(
            'select COLUMN_TYPE as type from information_schema.COLUMNS
             where TABLE_SCHEMA = ? and TABLE_NAME = ? and COLUMN_NAME = ?',
            [DB::getDatabaseName(), 'users', 'role']
        );

        $this->assertNotNull($colonne);
        foreach (['certificateur_qualite', 'direction_etudes', 'autorite_publication'] as $role) {
            $this->assertStringContainsString($role, (string) $colonne->type);
        }

        // Le JSON doit rester sérialisable (sortie machine de la commande).
        $json = json_encode($rapport, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($json);
        $this->assertIsArray(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_commande_refuse_une_base_non_dediee(): void
    {
        // 1. Base dédiée : sortie machine exploitable et verdict conforme.
        $dediee = $this->lancerCommande([
            'EXAM_LOAD_DB_DRIVER' => 'mysql',
            'EXAM_LOAD_DB_DATABASE' => (string) env('EXAM_LOAD_DB_DATABASE', 'inscription_etudiants_load_test'),
        ], ['--iterations=1', '--candidats=2', '--json']);

        $this->assertSame(0, $dediee->getExitCode(), $dediee->getErrorOutput());

        $rapport = $this->jsonDepuisSortie($dediee->getOutput().$dediee->getErrorOutput());
        $this->assertTrue($rapport['respecte']);
        $this->assertStringContainsString('_test', (string) $rapport['infrastructure']['database']);

        // 2. Garde anti-destruction : un sous-processus qui désigne la base de
        // DÉVELOPPEMENT doit échouer AVANT toute migration.
        $baseDev = (string) config('database.connections.mysql.database');

        $refusee = $this->lancerCommande([
            'EXAM_LOAD_DB_DRIVER' => 'mysql',
            'EXAM_LOAD_DB_DATABASE' => $baseDev,
        ], ['--iterations=1', '--candidats=2']);

        $this->assertNotSame(0, $refusee->getExitCode(), 'La base de développement doit être refusée.');
        $this->assertStringContainsString(
            'Base de charge refusée',
            $refusee->getOutput().$refusee->getErrorOutput()
        );
    }

    /**
     * @param  array<string, string>  $env
     * @param  array<int, string>  $arguments
     */
    private function lancerCommande(array $env, array $arguments): Process
    {
        $processus = new Process(
            array_merge([PHP_BINARY, 'artisan', 'examens:charge'], $arguments),
            base_path(),
            $env
        );
        $processus->setTimeout(600);
        $processus->run();

        return $processus;
    }

    /**
     * La commande écrit la sortie de `migrate:fresh` avant son JSON :
     * on ne décode qu'à partir du premier objet.
     *
     * @return array<string, mixed>
     */
    private function jsonDepuisSortie(string $sortie): array
    {
        $debut = strpos($sortie, '{');
        $this->assertNotFalse($debut, 'Aucun JSON dans la sortie de la commande.');

        return json_decode(substr($sortie, (int) $debut), true, 512, JSON_THROW_ON_ERROR);
    }
}
