<?php

namespace Tests\Feature;

use App\Models\AffectationGroupe;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Services\AffectationService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LOOP 4 (P1) — Concurrence MySQL dédiée : plusieurs workers simultanés
 * vers un groupe à capacité limitée ne doivent jamais dépasser la
 * capacité ni créer de double affectation active.
 *
 * Exécution EXCLUSIVE : LOOP4_MYSQL=1 + connexion mysql + base
 * `inscription_loop4_test` (dédiée). Toute autre base (dev, prod…)
 * = ÉCHEC immédiat, jamais d'exécution. Sans la variable : skip.
 */
class AffectationConcurrenceMysqlTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_DEDIEE = 'inscription_loop4_test';

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    protected function setUp(): void
    {
        // Pré-contrôles SANS façade (avant boot et avant toute migration) :
        // getenv() voit à la fois l'env réel et les <env> phpunit.
        if (getenv('LOOP4_MYSQL') !== '1') {
            parent::setUp();
            $this->markTestSkipped('Concurrence MySQL : LOOP4_MYSQL=1 requis.');
        }

        $connexion = getenv('DB_CONNECTION') ?: '';
        $base = getenv('DB_DATABASE') ?: '';

        if ($connexion !== 'mysql' || $base !== self::BASE_DEDIEE) {
            parent::setUp();
            $this->fail("REFUS : connexion « {$connexion} » / base « {$base} » — seule mysql/".self::BASE_DEDIEE.' est autorisée.');
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
        // (garde ci-dessus).
        $this->purgerBaseDediee();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté Concurrence', 'code' => 'FCC', 'description' => 'T', 'active' => true,
        ]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LIC-CC', 'nom' => 'Licence Concurrence',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
    }

    protected function tearDown(): void
    {
        // Purge UNIQUEMENT sur la base dédiée (les tests skippés sur
        // sqlite ne doivent rien toucher — sinon transaction laissée
        // ouverte et contamination des fichiers suivants).
        if (getenv('LOOP4_MYSQL') === '1'
            && DB::getDriverName() === 'mysql'
            && DB::getDatabaseName() === self::BASE_DEDIEE) {
            $this->purgerBaseDediee();
        }

        parent::tearDown();
    }

    /**
     * Remise à zéro COMMITÉE de la base dédiée (tables touchées par le
     * chemin `affecter()` + référentiels du test). TRUNCATE = DDL donc
     * commit implicite : les enfants forkés voient les données.
     */
    private function purgerBaseDediee(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        foreach ([
            'notifications',
            'situations_etudiant',
            'affectations_groupe',
            'inscriptions_academiques',
            'etudiants',
            'groups',
            'programmes',
            'facultes',
            'annees_universitaires',
        ] as $table) {
            DB::table($table)->truncate();
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function creerGroupe(string $code, ?int $capacite, string $niveau = 'L1'): Group
    {
        return Group::create([
            'code' => $code, 'nom' => 'Groupe '.$code,
            'faculte_id' => $this->faculte->id, 'programme_id' => $this->programme->id,
            'niveau' => $niveau, 'capacite' => $capacite, 'actif' => true,
        ]);
    }

    private function creerEtudiant(string $cin, string $niveau = 'L1'): Etudiant
    {
        static $compteur = 0;
        $compteur++;

        $etudiant = Etudiant::create([
            'cin' => $cin, 'num_inscription' => 'MAT-CC-'.$compteur,
            'nom' => 'Concurrent', 'prenom' => 'Etudiant'.$compteur,
            'email' => "cc{$compteur}@universite.test", 'date_naissance' => '2004-01-01',
            'sexe' => 'M', 'telephone' => '97000'.$compteur,
            'faculte_id' => $this->faculte->id, 'programme_id' => $this->programme->id,
            'niveau' => $niveau, 'annee_academique' => $this->annee->libelle,
            'adresse' => 'R', 'ville' => 'Tunis', 'code_postal' => '1000',
            'annee_bac' => '2023', 'password' => 'Secret@123',
        ]);
        $etudiant->definirStatut('valide');
        InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $this->annee->id,
            'niveau' => $niveau, 'statut' => 'valide',
        ]);

        return $etudiant;
    }

    /**
     * Forke un worker par paire [etudiant_id, groupe_id] : 0 = affecté,
     * 10 = refus métier attendu (concurrence), autre = anomalie.
     *
     * @param  array<int, array{0: int, 1: int}>  $paires
     * @return array<int, int> codes de sortie dans l'ordre des paires
     */
    private function executerEnParallele(array $paires): array
    {
        $enfants = [];

        foreach ($paires as $index => [$etudiantId, $groupeId]) {
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
                    app(AffectationService::class)->affecter(
                        Etudiant::findOrFail($etudiantId),
                        Group::findOrFail($groupeId),
                        AnneeUniversitaire::findOrFail($this->annee->id),
                        null,
                        'Test concurrence MySQL'
                    );
                    exit(0);
                } catch (DomainException) {
                    exit(10);
                } catch (\Throwable) {
                    exit(20);
                }
            }

            $enfants[$index] = $pid;
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

    private function activesPour(int $etudiantId): int
    {
        return AffectationGroupe::where('etudiant_id', $etudiantId)
            ->where('annee_universitaire_id', $this->annee->id)
            ->where('statut', 'active')
            ->count();
    }

    public function test_six_workers_capacite_deux_jamais_depassee(): void
    {
        $groupe = $this->creerGroupe('CC-G1', 2);

        $paires = [];
        for ($i = 1; $i <= 6; $i++) {
            $etudiant = $this->creerEtudiant('CC1000'.$i);
            $paires[] = [$etudiant->id, $groupe->id];
        }

        $codes = $this->executerEnParallele($paires);

        $this->assertCount(2, array_filter($codes, fn ($c) => $c === 0), 'Deux workers gagnent.');
        $this->assertCount(4, array_filter($codes, fn ($c) => $c === 10), 'Quatre refus métier.');
        $this->assertNotContains(20, $codes, 'Aucune erreur technique.');

        $this->assertSame(2, AffectationGroupe::where('groupe_id', $groupe->id)
            ->where('annee_universitaire_id', $this->annee->id)
            ->where('statut', 'active')->count(), 'Capacité 2 respectée.');

        foreach ($paires as [$etudiantId]) {
            $this->assertLessThanOrEqual(1, $this->activesPour($etudiantId), 'Jamais de double active.');
        }
    }

    public function test_meme_etudiant_deux_groupes_une_seule_active(): void
    {
        $groupeA = $this->creerGroupe('CC-GA', 5);
        $groupeB = $this->creerGroupe('CC-GB', 5);
        $etudiant = $this->creerEtudiant('CC20001');

        // Deux workers SIMULTANÉS pour le même étudiant vers deux groupes :
        // un seul gagne (transfert ou idempotence), jamais deux actives.
        $codes = $this->executerEnParallele([
            [$etudiant->id, $groupeA->id],
            [$etudiant->id, $groupeB->id],
        ]);

        $this->assertNotContains(20, $codes, 'Aucune erreur technique.');
        $this->assertSame(1, $this->activesPour($etudiant->id), 'Exactement une active.');
    }

    public function test_niveau_incompatible_refuse_meme_en_concurrence(): void
    {
        $groupeL2 = $this->creerGroupe('CC-GL2', 5, 'L2');
        $etudiant = $this->creerEtudiant('CC30001', 'L1');

        $codes = $this->executerEnParallele([[$etudiant->id, $groupeL2->id]]);

        $this->assertSame([10], array_values($codes), 'Refus LEVEL_MISMATCH.');
        $this->assertSame(0, $this->activesPour($etudiant->id), 'Rien écrit (rollback).');
    }
}
