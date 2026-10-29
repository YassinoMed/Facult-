<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\ExamDecisionCapacitePermutationService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Services\ExamScheduleEngineService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * §7.3 / DM-05 — concurrence RÉELLE sur le même créneau de salle.
 *
 * Ce test ne s'exécute que si `EXAM_CONCURRENCY_DB_DRIVER` vaut
 * mysql|mariadb|pgsql. Il utilise une base DÉDIÉE
 * (`EXAM_CONCURRENCY_DB_DATABASE`, par défaut
 * `inscription_etudiants_concurrency_test`) : aucune donnée de
 * développement n'est lue ni écrite.
 *
 * Deux processus indépendants (pcntl_fork) placent simultanément deux
 * épreuves d'examens distincts dans la MÊME salle, sur le MÊME créneau.
 * Le verrouillage des ressources partagées (§7.3) doit garantir qu'au plus
 * une affectation incompatible est validée. Le test est répété pour
 * détecter une éventuelle fenêtre TOCTOU intermittente.
 */
class ExamensConcurrenceReelleTest extends TestCase
{
    private const ITERATIONS = 5;

    private string $driver;

    private string $repertoireSynchronisation;

    private AnneeUniversitaire $annee;

    private SessionExamen $session;

    private Programme $programme;

    private Niveau $niveau;

    private SemestreLmd $semestre;

    private Matiere $matiere;

    private Room $salle;

    private Group $groupeA;

    private Group $groupeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = (string) env('EXAM_CONCURRENCY_DB_DRIVER');

        if (! in_array($this->driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            $this->markTestSkipped(
                'Concurrence réelle NON EXÉCUTÉE : verrou transactionnel non démontrable sur SQLite. '
                .'Définir EXAM_CONCURRENCY_DB_DRIVER=mysql (ou pgsql) et une base dédiée '
                .'EXAM_CONCURRENCY_DB_DATABASE.'
            );
        }

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork indisponible : deux processus réels sont requis.');
        }

        $this->configurerBaseDediee();
        $this->creerReferentiel();

        $this->repertoireSynchronisation = sys_get_temp_dir().'/exam-conc-'.getmypid().'-'.bin2hex(random_bytes(4));
        if (! mkdir($this->repertoireSynchronisation, 0700, true) && ! is_dir($this->repertoireSynchronisation)) {
            $this->fail('Impossible de créer le répertoire de synchronisation inter-processus.');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->repertoireSynchronisation) && is_dir($this->repertoireSynchronisation)) {
            foreach (glob($this->repertoireSynchronisation.'/*') ?: [] as $fichier) {
                @unlink($fichier);
            }
            @rmdir($this->repertoireSynchronisation);
        }

        parent::tearDown();
    }

    /**
     * Bascule la connexion par défaut vers la base dédiée et garantit le
     * schéma. La base est créée si elle n'existe pas, puis migrée.
     */
    private function configurerBaseDediee(): void
    {
        $connexion = (string) ($this->driver === 'pgsql' ? 'pgsql' : 'mysql');
        $defaut = config("database.connections.{$connexion}");

        $hote = (string) env('EXAM_CONCURRENCY_DB_HOST', $defaut['host'] ?? '127.0.0.1');
        $port = (int) env('EXAM_CONCURRENCY_DB_PORT', $defaut['port'] ?? 3306);
        $base = (string) env('EXAM_CONCURRENCY_DB_DATABASE', 'inscription_etudiants_concurrency_test');
        $utilisateur = (string) env('EXAM_CONCURRENCY_DB_USERNAME', $defaut['username'] ?? 'root');
        $motDePasse = (string) env('EXAM_CONCURRENCY_DB_PASSWORD', $defaut['password'] ?? '');

        if ($connexion === 'mysql') {
            $pdo = new PDO(
                "mysql:host={$hote};port={$port}",
                $utilisateur,
                $motDePasse,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $pdo->exec(
                "CREATE DATABASE IF NOT EXISTS `{$base}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
            );
            $pdo = null;
        }

        config([
            'database.default' => $connexion,
            "database.connections.{$connexion}.host" => $hote,
            "database.connections.{$connexion}.port" => $port,
            "database.connections.{$connexion}.database" => $base,
            "database.connections.{$connexion}.username" => $utilisateur,
            "database.connections.{$connexion}.password" => $motDePasse,
        ]);

        DB::purge($connexion);
        DB::setDefaultConnection($connexion);

        Artisan::call('migrate', ['--database' => $connexion, '--force' => true]);
    }

    private function creerReferentiel(): void
    {
        // Base dédiée persistante : fixtures idempotentes par code.
        $faculte = Faculte::firstOrCreate(
            ['code' => 'FCONC'],
            ['nom' => 'Faculté Concurrence', 'active' => true]
        );

        $this->annee = AnneeUniversitaire::firstOrCreate(
            ['libelle' => '2026-2027'],
            ['date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true]
        );

        $this->programme = Programme::firstOrCreate(
            ['code' => 'P-CONC'],
            [
                'faculte_id' => $faculte->id, 'nom' => 'Programme Concurrence',
                'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
            ]
        );

        $this->niveau = Niveau::firstOrCreate(
            ['programme_id' => $this->programme->id, 'code' => 'L3'],
            ['nom' => 'Licence 3', 'ordre' => 3]
        );

        $this->semestre = SemestreLmd::firstOrCreate(
            ['niveau_id' => $this->niveau->id, 'code' => 'S5'],
            ['nom' => 'S5', 'numero' => 5, 'ordre' => 5]
        );

        $ue = UniteEnseignement::firstOrCreate(
            ['code' => 'UE-CONC'],
            [
                'semestre_id' => $this->semestre->id, 'programme_id' => $this->programme->id,
                'nom' => 'UE Concurrence', 'credits' => 6, 'coefficient' => 2,
            ]
        );

        $this->matiere = Matiere::firstOrCreate(
            ['code' => 'MAT-CONC'],
            [
                'nom' => 'Module Concurrence', 'ue_id' => $ue->id,
                'niveau' => 'L3', 'semestre' => 5, 'credits' => 4, 'actif' => true,
            ]
        );

        $this->session = SessionExamen::firstOrCreate(
            ['code' => 'S-CONC'],
            [
                'annee_universitaire_id' => $this->annee->id,
                'nom' => 'Session Concurrence', 'type' => 'principale',
                'date_debut' => '2027-06-01', 'date_fin' => '2027-06-30', 'statut' => 'open',
            ]
        );

        $this->salle = Room::firstOrCreate(
            ['code' => 'CONC-ROOM'],
            [
                'nom' => 'Salle Concurrence',
                'capacite' => 120, 'type' => 'amphi', 'actif' => true,
                'heure_ouverture' => '07:00:00', 'heure_fermeture' => '20:00:00',
                'faculte_id' => $faculte->id,
            ]
        );

        $this->groupeA = Group::firstOrCreate(
            ['code' => 'CONC-GA'],
            [
                'nom' => 'Groupe A', 'niveau' => 'L3',
                'faculte_id' => $faculte->id, 'capacite' => 40, 'actif' => true,
            ]
        );

        $this->groupeB = Group::firstOrCreate(
            ['code' => 'CONC-GB'],
            [
                'nom' => 'Groupe B', 'niveau' => 'L3',
                'faculte_id' => $faculte->id, 'capacite' => 40, 'actif' => true,
            ]
        );
    }

    /**
     * @return array{examen: Examen, epreuve: Epreuve}
     */
    private function creerExamen(string $suffixe, Group $groupe): array
    {
        $examen = Examen::create([
            'code' => 'CONC-EX-'.$suffixe, 'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id, 'niveau_id' => $this->niveau->id,
            'semestre_lmd_id' => $this->semestre->id,
            'libelle' => 'Examen concurrence '.$suffixe,
            'type' => 'examen', 'statut' => 'scheduled',
        ]);

        $epreuve = Epreuve::create([
            'code' => 'CONC-EPR-'.$suffixe, 'examen_id' => $examen->id,
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiere->id, 'groupe_id' => $groupe->id,
            'salle_id' => null,
            'date' => '2027-06-15', 'heure_debut' => '09:00:00', 'heure_fin' => '11:00:00',
            'duree_minutes' => 120, 'statut' => 'scheduled',
        ]);

        return ['examen' => $examen, 'epreuve' => $epreuve];
    }

    /**
     * @return array<string, mixed>
     */
    private function scenario(Epreuve $epreuve): array
    {
        return [
            'est_valide' => true,
            'nb_conflits_durs' => 0,
            'placements' => [[
                'epreuve_id' => $epreuve->id,
                'date' => '2027-06-15',
                'heure_debut' => '09:00',
                'heure_fin' => '11:00',
                'salle_id' => $this->salle->id,
                'surveillants' => [],
            ]],
        ];
    }

    public function test_serierisation_de_la_meme_salle_entre_deux_processus(): void
    {
        $service = app(ExamScheduleEngineService::class);

        for ($iteration = 1; $iteration <= self::ITERATIONS; $iteration++) {
            // Nettoyage des exécutions précédentes de CE test uniquement :
            // chaque itération doit repartir d'une salle réellement libre.
            Epreuve::where('code', 'like', 'CONC-EPR-%')->delete();
            Examen::where('code', 'like', 'CONC-EX-%')->delete();

            $suffixe = $iteration.'-'.bin2hex(random_bytes(3));
            $a = $this->creerExamen('A-'.$suffixe, $this->groupeA);
            $b = $this->creerExamen('B-'.$suffixe, $this->groupeB);

            // Sanity check : chaque scénario est valide considéré isolément.
            foreach ([$a, $b] as $contexte) {
                $controle = $service->verifierCreneauValide(
                    $contexte['epreuve'],
                    $this->salle,
                    '2027-06-15',
                    '09:00',
                    '11:00',
                    ExamScheduleEngineService::RATIO_CAPACITE_EXAMEN_DEFAUT,
                    []
                );
                $this->assertTrue($controle['valide'], 'Scénario isolé invalide : '.($controle['motif'] ?? '?'));
            }

            $resultats = $this->executerDeuxProcessus($iteration, [
                'A' => [$a['examen'], $a['epreuve']],
                'B' => [$b['examen'], $b['epreuve']],
            ]);

            $succes = array_filter($resultats, fn (string $r): bool => $r === 'OK');
            $echecs = array_filter($resultats, fn (string $r): bool => str_starts_with($r, 'ERR:'));

            $this->assertCount(
                1,
                $succes,
                "Itération {$iteration} : exactement un processus doit réussir. Résultats : ".json_encode($resultats, JSON_UNESCAPED_UNICODE)
            );
            $this->assertCount(
                1,
                $echecs,
                "Itération {$iteration} : exactement un processus doit échouer. Résultats : ".json_encode($resultats, JSON_UNESCAPED_UNICODE)
            );

            $messageEchec = (string) reset($echecs);
            $this->assertMatchesRegularExpression(
                '/Conflit en base|Collision salle/',
                $messageEchec,
                "Itération {$iteration} : l'échec doit être un refus de conflit explicite, pas une autre erreur. Reçu : {$messageEchec}"
            );

            // Aucune double occupation persistée sur la salle et le créneau.
            $occupantes = Epreuve::whereDate('date', '2027-06-15')
                ->where('salle_id', $this->salle->id)
                ->where('statut', '!=', 'cancelled')
                ->where('heure_debut', '<', '11:00:00')
                ->where('heure_fin', '>', '09:00:00')
                ->count();

            $this->assertSame(1, $occupantes, "Itération {$iteration} : la salle ne doit porter qu'une occupation.");

            // Aucune écriture partielle : l'épreuve perdante reste non
            // affectée (salle_id null) et aucun surveillant parasite.
            $perdante = str_contains($resultats['A'], 'ERR:') ? $a['epreuve']->fresh() : $b['epreuve']->fresh();
            $this->assertNull($perdante->salle_id, "Itération {$iteration} : écriture partielle détectée.");
            $this->assertSame(0, DB::table('evaluation_surveillants')->count());

            // L'examen perdant ne doit pas être passé à « scheduled » par
            // l'échec : seule la transaction gagnante a écrit.
            $examenPerdant = str_contains($resultats['A'], 'ERR:') ? $a['examen']->fresh() : $b['examen']->fresh();
            $this->assertSame('scheduled', $examenPerdant->statut);
        }
    }

    /**
     * Concurrence réelle MySQL sur le service D-04 (`ExamDecisionCapacitePermutationService::appliquer`) :
     * Deux processus parallèles (`pcntl_fork`) tentent d'affecter simultanément deux épreuves
     * à la même salle sur le même créneau horaire. Le verrouillage déterministe `lockForUpdate()`
     * garantit qu'exactement une transaction réussit et que l'autre est rejetée sans dépasser la jauge utile D-04.
     */
    public function test_concurrence_reelle_d04_appliquer_sur_meme_salle(): void
    {
        User::where('email', 'admin.conc.d04@univ.test')->delete();
        $admin = User::creerCompteBackOffice([
            'name' => 'Admin Concurrence D04',
            'email' => 'admin.conc.d04@univ.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        for ($iteration = 1; $iteration <= 3; $iteration++) {
            Epreuve::where('code', 'like', 'CONC-%')->delete();
            Examen::where('code', 'like', 'CONC-%')->delete();

            $suffixe = 'D04-'.$iteration.'-'.bin2hex(random_bytes(3));
            $a = $this->creerExamen('A-'.$suffixe, $this->groupeA);
            $b = $this->creerExamen('B-'.$suffixe, $this->groupeB);

            $a['epreuve']->update(['code' => 'CONC-D04-A-'.$iteration, 'capacite_necessaire' => 30]);
            $b['epreuve']->update(['code' => 'CONC-D04-B-'.$iteration, 'capacite_necessaire' => 30]);

            $resultats = $this->executerDeuxProcessusD04($iteration, [
                'A' => $a['epreuve'],
                'B' => $b['epreuve'],
            ], (int) $admin->id);

            $succes = array_filter($resultats, fn (string $r): bool => $r === 'OK');
            $echecs = array_filter($resultats, fn (string $r): bool => str_starts_with($r, 'ERR:'));

            $this->assertCount(
                1,
                $succes,
                "Itération D-04 {$iteration} : exactement un processus doit réussir. Résultats : ".json_encode($resultats, JSON_UNESCAPED_UNICODE)
            );
            $this->assertCount(
                1,
                $echecs,
                "Itération D-04 {$iteration} : exactement un processus doit échouer. Résultats : ".json_encode($resultats, JSON_UNESCAPED_UNICODE)
            );

            $messageEchec = (string) reset($echecs);
            $this->assertMatchesRegularExpression(
                '/déjà occupée|collision d\'occupation|n\'est plus disponible/i',
                $messageEchec,
                "Itération D-04 {$iteration} : l'échec doit être un refus explicite d'occupation D-04. Reçu : {$messageEchec}"
            );

            $occupantes = Epreuve::whereDate('date', '2027-06-15')
                ->where('salle_id', $this->salle->id)
                ->where('code', 'like', 'CONC-D04-%')
                ->where('statut', '!=', 'cancelled')
                ->count();

            $this->assertSame(1, $occupantes, "Itération D-04 {$iteration} : une seule épreuve doit occuper la salle.");
        }
    }

    /**
     * @param  array<string, Epreuve>  $epreuves
     * @return array<string, string>
     */
    private function executerDeuxProcessusD04(int $iteration, array $epreuves, int $adminId): array
    {
        $pids = [];
        $tag = 'd04-'.$iteration;

        foreach ($epreuves as $cle => $epreuve) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('pcntl_fork a échoué.');
            }

            if ($pid === 0) {
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }

                try {
                    DB::purge();
                    DB::reconnect();

                    $cheminPret = $this->repertoireSynchronisation."/pret-{$tag}-{$cle}";
                    file_put_contents($cheminPret, '1');

                    $limite = microtime(true) + 15.0;
                    while (! file_exists($this->repertoireSynchronisation."/go-{$tag}")) {
                        if (microtime(true) > $limite) {
                            throw new \RuntimeException('Barrière de synchronisation D-04 expirée.');
                        }
                        usleep(1000);
                    }

                    $epreuveFraiche = Epreuve::findOrFail($epreuve->id);
                    app(ExamDecisionCapacitePermutationService::class)->appliquer(
                        $epreuveFraiche,
                        [
                            'type' => 'reaffectation',
                            'salle_ids' => [$this->salle->id],
                            'motif' => "Test concurrence MySQL D-04 processus {$cle}",
                        ],
                        $adminId
                    );

                    file_put_contents($this->repertoireSynchronisation."/resultat-{$tag}-{$cle}", 'OK');
                } catch (\Throwable $e) {
                    file_put_contents(
                        $this->repertoireSynchronisation."/resultat-{$tag}-{$cle}",
                        'ERR:'.get_class($e).':'.$e->getMessage()
                    );
                }

                exit(0);
            }

            $pids[$cle] = $pid;
        }

        $limite = microtime(true) + 15.0;
        foreach (array_keys($epreuves) as $cle) {
            $chemin = $this->repertoireSynchronisation."/pret-{$tag}-{$cle}";
            while (! file_exists($chemin)) {
                if (microtime(true) > $limite) {
                    $this->fail("Le processus D-04 {$cle} n'a pas atteint la barrière.");
                }
                usleep(1000);
            }
        }

        touch($this->repertoireSynchronisation."/go-{$tag}");

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $statut);
        }

        $resultats = [];
        foreach (array_keys($epreuves) as $cle) {
            $chemin = $this->repertoireSynchronisation."/resultat-{$tag}-{$cle}";
            $resultats[$cle] = is_file($chemin)
                ? (string) file_get_contents($chemin)
                : 'ERR:RuntimeException:Aucun résultat produit par le processus.';
        }

        return $resultats;
    }

    /**
     * Lance deux enfants, les synchronise sur une barrière fichier, puis
     * collecte leurs résultats. Chaque enfant rétablit sa propre connexion
     * PDO : un descripteur hérité après fork ne doit jamais être partagé.
     *
     * @param  array<string, array{0: Examen, 1: Epreuve}>  $contextes
     * @return array<string, string>
     */
    private function executerDeuxProcessus(int $iteration, array $contextes): array
    {
        $pids = [];

        foreach ($contextes as $cle => [$examen, $epreuve]) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('pcntl_fork a échoué.');
            }

            if ($pid === 0) {
                // --- Processus enfant ---
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }

                try {
                    DB::purge();
                    DB::reconnect();

                    $cheminPret = $this->repertoireSynchronisation."/pret-{$iteration}-{$cle}";
                    file_put_contents($cheminPret, '1');

                    $limite = microtime(true) + 15.0;
                    while (! file_exists($this->repertoireSynchronisation."/go-{$iteration}")) {
                        if (microtime(true) > $limite) {
                            throw new \RuntimeException('Barrière de synchronisation expirée.');
                        }
                        usleep(1000);
                    }

                    app(ExamScheduleEngineService::class)->appliquerScenario(
                        $examen,
                        'scenario-conc-'.$iteration.'-'.$cle,
                        $this->scenario($epreuve),
                        null
                    );

                    file_put_contents($this->repertoireSynchronisation."/resultat-{$iteration}-{$cle}", 'OK');
                } catch (\Throwable $e) {
                    file_put_contents(
                        $this->repertoireSynchronisation."/resultat-{$iteration}-{$cle}",
                        'ERR:'.get_class($e).':'.$e->getMessage()
                    );
                }

                exit(0);
            }

            $pids[$cle] = $pid;
        }

        // Attendre que les deux enfants soient prêts avant de lever la
        // barrière : les deux lectures de conflit sont ainsi concurrentes.
        $limite = microtime(true) + 15.0;
        foreach (array_keys($contextes) as $cle) {
            $chemin = $this->repertoireSynchronisation."/pret-{$iteration}-{$cle}";
            while (! file_exists($chemin)) {
                if (microtime(true) > $limite) {
                    $this->fail("Le processus {$cle} n'a pas atteint la barrière.");
                }
                usleep(1000);
            }
        }

        touch($this->repertoireSynchronisation."/go-{$iteration}");

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $statut);
        }

        $resultats = [];
        foreach (array_keys($contextes) as $cle) {
            $chemin = $this->repertoireSynchronisation."/resultat-{$iteration}-{$cle}";
            $resultats[$cle] = is_file($chemin)
                ? (string) file_get_contents($chemin)
                : 'ERR:RuntimeException:Aucun résultat produit par le processus.';
        }

        return $resultats;
    }
}
