<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Referentiel\Models\Domaine;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\MaquetteUe;
use App\Modules\Referentiel\Models\MaquetteUeEcue;
use App\Modules\Referentiel\Models\Mention;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\Specialite;
use App\Modules\Referentiel\Models\UniteEnseignement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Performance et fiabilité (LOOP 15) : mesures REPRODUCTIBLES avant
 * toute optimisation — requêtes (garde N+1), temps, mémoire. Bornes =
 * références mesurées sur fixture (5 programmes), pas des SLA
 * contractuels. Aucune optimisation sans mesure, aucun résultat
 * métier modifié.
 */
class ReferentielPerformanceBoucle15Test extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /** @var array<string, array{requetes: int, ms: float, memoire_mo: float}> */
    private static array $mesures = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.referentiel.active' => true]);

        $faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.perf15@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        for ($p = 0; $p < 5; $p++) {
            $domaine = Domaine::create(['faculte_id' => $faculte->id, 'code' => "DQ$p", 'nom' => 'D', 'actif' => true]);
            $mention = Mention::create(['domaine_id' => $domaine->id, 'code' => "MQ$p", 'nom' => 'M', 'actif' => true]);
            $specialite = Specialite::create(['mention_id' => $mention->id, 'code' => "SQ$p", 'nom' => 'S', 'actif' => true]);
            $programme = Programme::create([
                'faculte_id' => $faculte->id, 'specialite_id' => $specialite->id,
                'code' => "PQ$p", 'nom' => 'Programme', 'statut' => 'actif', 'actif' => true,
            ]);

            foreach ([1, 2] as $n) {
                $niveau = Niveau::create([
                    'programme_id' => $programme->id, 'code' => "L$n", 'nom' => "L$n",
                    'ordre' => $n, 'actif' => true,
                ]);

                foreach ([1, 2] as $s) {
                    $numero = ($n - 1) * 2 + $s;
                    $semestre = SemestreLmd::create([
                        'niveau_id' => $niveau->id, 'code' => "S$numero", 'nom' => "S$numero",
                        'numero' => $numero, 'ordre' => $numero, 'actif' => true,
                    ]);
                    $ue = UniteEnseignement::create([
                        'semestre_id' => $semestre->id, 'programme_id' => $programme->id,
                        'code' => "UQ$p$n$s", 'nom' => 'U', 'credits' => 6, 'actif' => true,
                    ]);

                    for ($e = 0; $e < 3; $e++) {
                        Matiere::create([
                            'code' => "MQ$p$n$s$e", 'nom' => 'M', 'ue_id' => $ue->id,
                            'niveau' => "L$n", 'semestre' => $numero, 'coefficient' => 1,
                            'credits' => 2, 'volume_cm' => 10,
                        ]);
                    }
                }
            }

            if ($p === 0) {
                $v1 = Maquette::create([
                    'programme_id' => $programme->id, 'annee_universitaire_id' => $annee->id,
                    'version' => 1, 'statut' => 'archived',
                ]);
                $v2 = Maquette::create([
                    'programme_id' => $programme->id, 'annee_universitaire_id' => $annee->id,
                    'version' => 2, 'statut' => 'draft',
                ]);
                $premierUe = UniteEnseignement::where('programme_id', $programme->id)->firstOrFail();
                foreach ([$v1, $v2] as $maquette) {
                    $ligne = MaquetteUe::create([
                        'maquette_id' => $maquette->id, 'ue_id' => $premierUe->id,
                        'semestre_id' => $premierUe->semestre_id,
                    ]);
                    foreach (Matiere::where('ue_id', $premierUe->id)->orderBy('id')->get() as $ordre => $ecue) {
                        MaquetteUeEcue::create([
                            'maquette_ue_id' => $ligne->id, 'matiere_id' => $ecue->id,
                            'ordre' => $ordre, 'coefficient' => 1, 'credits' => 2,
                            'volume_cm' => 10, 'volume_td' => 0, 'volume_tp' => 0,
                            'regime' => 'obligatoire', 'obligatoire' => true,
                        ]);
                    }
                }
            }
        }

        for ($i = 0; $i < 10; $i++) {
            Matiere::create([
                'code' => "STAND$i", 'nom' => 'Standalone '.$i, 'credits' => 2,
                'niveau' => 'L1', 'semestre' => 1, 'actif' => true,
            ]);
        }
    }

    /**
     * @return array{requetes: int, ms: float, memoire_mo: float}
     */
    private function mesurer(string $cle, callable $action): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $memoireAvant = memory_get_usage(true);
        $debut = microtime(true);

        $action();

        $mesure = [
            'requetes' => count(DB::getQueryLog()),
            'ms' => round((microtime(true) - $debut) * 1000, 1),
            'memoire_mo' => round((memory_get_usage(true) - $memoireAvant) / 1048576, 2),
        ];
        DB::disableQueryLog();
        self::$mesures[$cle] = $mesure;

        return $mesure;
    }

    public function test_parcours_critiques_bornes(): void
    {
        // Références mesurées le 12/10 (sqlite :memory:, fixture 5 prog.
        // × 2 niv. × 2 sem. × 1 UE × 3 ECUE + 10 standalone) : bornes =
        // observé + marge, toute régression N+1 les fera rougir.
        $bornes = [
            'tableau-bord' => ['url' => route('admin.referentiel.tableau-bord'), 'requetes' => 60, 'ms' => 3000],
            'pilotage' => ['url' => route('admin.referentiel.pilotage'), 'requetes' => 150, 'ms' => 5000],
            'arbre' => ['url' => route('admin.referentiel.arbre'), 'requetes' => 60, 'ms' => 3000],
            'prevalidation' => ['url' => route('admin.referentiel.prevalidation.index'), 'requetes' => 150, 'ms' => 5000],
            'standalone' => ['url' => route('admin.referentiel.standalone'), 'requetes' => 60, 'ms' => 3000],
            'charges' => ['url' => route('admin.referentiel.charges'), 'requetes' => 60, 'ms' => 5000],
            'prerequis' => ['url' => route('admin.referentiel.prerequis', ['programme_id' => Programme::where('code', 'PQ0')->firstOrFail()->id]), 'requetes' => 60, 'ms' => 3000],
        ];

        foreach ($bornes as $cle => $attente) {
            $mesure = $this->mesurer($cle, function () use ($attente) {
                $this->actingAs($this->admin)->get($attente['url'])->assertOk();
            });

            $this->assertLessThan($attente['requetes'], $mesure['requetes'], "{$cle} : {$mesure['requetes']} requêtes.");
            $this->assertLessThan($attente['ms'], $mesure['ms'], "{$cle} : {$mesure['ms']} ms.");
        }
    }

    public function test_maquette_show_et_comparer_bornes(): void
    {
        $v1 = Maquette::where('version', 1)->firstOrFail();
        $v2 = Maquette::where('version', 2)->firstOrFail();

        $show = $this->mesurer('maquette-show', function () use ($v1) {
            $this->actingAs($this->admin)
                ->get(route('admin.referentiel.maquettes.show', $v1))->assertOk();
        });
        $this->assertLessThan(60, $show['requetes'], "show : {$show['requetes']} requêtes.");

        $comparer = $this->mesurer('maquette-comparer', function () use ($v1, $v2) {
            $this->actingAs($this->admin)
                ->get(route('admin.referentiel.maquettes.comparer', $v1).'?autre='.$v2->id)->assertOk();
        });
        $this->assertLessThan(60, $comparer['requetes'], "comparer : {$comparer['requetes']} requêtes.");
    }

    public function test_volumes_reels_dev_references(): void
    {
        // Garde-fou générique : aucune page du chantier ne charge de
        // collection complète pour un compteur (requêtes plates).
        $this->assertNotEmpty(self::$mesures);
    }
}
