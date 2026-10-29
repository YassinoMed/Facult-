<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Services\ArchiveService;
use App\Services\InscriptionService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LOOP-INSCRIPTION-UNIQUE-ANNUELLE-01 — concurrence MySQL dédiée :
 * plusieurs workers SIMULTANÉS tentent la PREMIÈRE inscription du même
 * étudiant pour la même année universitaire. La contrainte UNIQUE
 * `uniq_inscription_etudiant_annee` arbitre : exactement un gagne, les
 * autres reçoivent l'erreur métier unique (DomainException) — jamais un
 * second enregistrement, jamais d'erreur technique.
 *
 * Exécution EXCLUSIVE : `CONCURRENCE_MYSQL=1` + connexion mysql + base
 * `inscription_uniq_test` (dédiée). Toute autre base (dev, prod…) =
 * ÉCHEC immédiat, jamais d'exécution. Sans la variable : skip.
 *
 * Exemple :
 * CONCURRENCE_MYSQL=1 DB_CONNECTION=mysql DB_DATABASE=inscription_uniq_test \
 *   DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root DB_PASSWORD=root \
 *   php vendor/bin/phpunit --filter=UniciteInscriptionConcurrenceMysqlTest
 */
class UniciteInscriptionConcurrenceMysqlTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_DEDIEE = 'inscription_uniq_test';

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        // Pré-contrôles SANS façade (avant boot et avant toute migration) :
        // getenv() voit à la fois l'env réel et les <env> de phpunit.xml.
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

        // Revérification via l'application démarrée (ceinture + bretelles).
        if (DB::getDriverName() !== 'mysql' || DB::getDatabaseName() !== self::BASE_DEDIEE) {
            $this->fail('REFUS : la connexion effective ne pointe pas vers la base dédiée.');
        }

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl indisponible.');
        }

        // Les workers forkés ont chacun leur connexion : ils ne voient que
        // le COMMITÉ. Or RefreshDatabase isole chaque test dans une
        // transaction non commitée → on nettoie par TRUNCATE (DDL = commit
        // implicite MySQL), rejouable en tearDown. Base dédiée uniquement
        // (gardes ci-dessus).
        $this->purgerBaseDediee();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté Unicité', 'code' => 'FUN', 'description' => 'T', 'active' => true,
        ]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        // Purge UNIQUEMENT sur la base dédiée (les tests skippés sur
        // sqlite ne doivent rien toucher — sinon transaction laissée
        // ouverte et contamination des fichiers suivants).
        if (getenv('CONCURRENCE_MYSQL') === '1'
            && DB::getDriverName() === 'mysql'
            && DB::getDatabaseName() === self::BASE_DEDIEE) {
            $this->purgerBaseDediee();
        }

        parent::tearDown();
    }

    /**
     * Remise à zéro COMMITÉE de la base dédiée (tables touchées par les
     * chemins d'inscription du test). TRUNCATE = DDL donc commit implicite :
     * les enfants forkés voient les fixtures créées ensuite.
     */
    private function purgerBaseDediee(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        foreach ([
            'historique_academique',
            'semestres',
            'inscriptions_academiques',
            'etudiants',
            'facultes',
            'annees_universitaires',
        ] as $table) {
            DB::table($table)->truncate();
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function creerEtudiant(string $cin): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'num_inscription' => 'MAT-UNIQ-'.$cin,
            'nom' => 'Concurrent', 'prenom' => 'Unicite',
            'email' => strtolower($cin).'@universite.test', 'date_naissance' => '2004-01-01',
            'sexe' => 'M', 'telephone' => '97000001',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => $this->annee->libelle,
            'adresse' => 'Rue du Test', 'ville' => 'Rabat', 'code_postal' => '1000',
            'annee_bac' => '2023', 'password' => 'Secret@123',
        ]);
        $etudiant->definirStatut('valide');

        return $etudiant;
    }

    /**
     * Forke un worker par tentative : 0 = inscription créée, 10 = refus
     * métier unique (attendu), 11 = DomainException d'une autre règle
     * (anormal ici), 20 = erreur technique (anormal). L'index du worker
     * est transmis à la tentative (ordonnancement déterministe).
     *
     * @return array<int, int> codes de sortie dans l'ordre des workers
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
                // Enfant : connexion fraîche (jamais le PDO hérité du parent).
                DB::purge('mysql');
                DB::reconnect('mysql');
                DB::disableQueryLog();
                usleep(random_int(0, 50000));

                try {
                    $tentative($i);
                    exit(0);
                } catch (DomainException $e) {
                    exit($e->getMessage() === InscriptionService::MESSAGE_INSCRIPTION_ANNUELLE_EXISTANTE ? 10 : 11);
                } catch (\Throwable) {
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

        // Les enfants ont fermé (QUIT) le socket MySQL hérité au moment du
        // fork : la connexion du parent est tombée (« gone away ») et doit
        // être rétablie avant toute lecture d'assertion.
        DB::purge('mysql');
        DB::reconnect('mysql');

        return $codes;
    }

    // ── Scénarios ───────────────────────────────────────────────────

    public function test_six_workers_meme_etudiant_meme_annee_une_seule_inscription(): void
    {
        $etudiant = $this->creerEtudiant('UNIQ1001');
        $anneeId = $this->annee->id;
        $service = app(InscriptionService::class);

        $codes = $this->executerEnParallele(6, function () use ($service, $etudiant, $anneeId) {
            $service->creerInscriptionAnnuelle((int) $etudiant->id, (int) $anneeId, ['niveau' => 'L1']);
        });

        $this->assertCount(1, array_filter($codes, fn ($c) => $c === 0), 'Un seul worker gagne.');
        $this->assertCount(5, array_filter($codes, fn ($c) => $c === 10), 'Cinq refus métier uniques.');
        $this->assertNotContains(11, $codes, 'Aucune autre règle métier déclenchée.');
        $this->assertNotContains(20, $codes, 'Aucune erreur technique.');

        $this->assertSame(1, InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->where('annee_universitaire_id', $anneeId)->count(), 'Une seule inscription en base.');
    }

    public function test_quatre_workers_chemin_admin_complet_une_seule_inscription(): void
    {
        $etudiant = $this->creerEtudiant('UNIQ2001');
        $anneeId = $this->annee->id;
        $archives = app(ArchiveService::class);

        // Chemin réel du formulaire admin : ArchiveService (périodes +
        // inscription + semestres + audit), en concurrence sur la même paire.
        $codes = $this->executerEnParallele(4, function () use ($archives, $etudiant, $anneeId) {
            $archives->creerInscription(
                Etudiant::findOrFail($etudiant->id),
                ['annee_universitaire_id' => $anneeId, 'niveau' => 'L1'],
            );
        });

        $this->assertCount(1, array_filter($codes, fn ($c) => $c === 0), 'Un seul worker gagne.');
        $this->assertCount(3, array_filter($codes, fn ($c) => $c === 10), 'Trois refus métier uniques.');
        $this->assertNotContains(20, $codes, 'Aucune erreur technique.');

        $inscription = InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->where('annee_universitaire_id', $anneeId)->get();

        $this->assertCount(1, $inscription, 'Une seule inscription en base.');
        $this->assertSame(2, DB::table('semestres')->where('inscription_academique_id', $inscription->first()->id)->count());
    }

    public function test_etudiants_differents_meme_annee_concurrents_tous_autorises(): void
    {
        $etudiants = [$this->creerEtudiant('UNIQ3001'), $this->creerEtudiant('UNIQ3002')];
        $anneeId = $this->annee->id;
        $service = app(InscriptionService::class);

        // Un worker par étudiant : la règle est bien PAR PAIRE — aucune
        // sur-interdiction pour les autres étudiants de la même année.
        $codes = $this->executerEnParallele(2, function (int $i) use ($service, $etudiants, $anneeId) {
            $service->creerInscriptionAnnuelle((int) $etudiants[$i]->id, (int) $anneeId, ['niveau' => 'L1']);
        });

        $this->assertSame([0, 0], array_values($codes), 'Les deux étudiants sont inscrits.');
        $this->assertSame(2, InscriptionAcademique::where('annee_universitaire_id', $anneeId)->count());
    }
}
