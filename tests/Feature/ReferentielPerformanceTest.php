<?php

namespace Tests\Feature;

use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Referentiel\Models\Domaine;
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
 * Performance du référentiel (LOOP §21/§28) : listings bornés,
 * tableau de bord sans N+1 (withCount).
 */
class ReferentielPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.referentiel.active' => true]);

        $faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.perf@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        for ($p = 0; $p < 5; $p++) {
            $domaine = Domaine::create(['faculte_id' => $faculte->id, 'code' => "DP$p", 'nom' => 'D', 'actif' => true]);
            $mention = Mention::create(['domaine_id' => $domaine->id, 'code' => "MP$p", 'nom' => 'M', 'actif' => true]);
            $specialite = Specialite::create(['mention_id' => $mention->id, 'code' => "SP$p", 'nom' => 'S', 'actif' => true]);
            $programme = Programme::create([
                'faculte_id' => $faculte->id, 'specialite_id' => $specialite->id,
                'code' => "PP$p", 'nom' => 'Programme', 'statut' => 'actif', 'actif' => true,
            ]);
            for ($n = 0; $n < 2; $n++) {
                $niveau = Niveau::create(['programme_id' => $programme->id, 'code' => "L$n", 'nom' => 'N', 'ordre' => 1, 'actif' => true]);
                for ($s = 0; $s < 2; $s++) {
                    $semestre = SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => "S$s", 'nom' => 'S', 'numero' => 1, 'ordre' => 1, 'actif' => true]);
                    $ue = UniteEnseignement::create([
                        'semestre_id' => $semestre->id, 'programme_id' => $programme->id,
                        'code' => "UE$p$n$s", 'nom' => 'U', 'credits' => 30, 'actif' => true,
                    ]);
                    for ($e = 0; $e < 3; $e++) {
                        Matiere::create([
                            'code' => "M$p$n$s$e", 'nom' => 'M', 'ue_id' => $ue->id,
                            'niveau' => 'L1', 'semestre' => 1, 'coefficient' => 1, 'credits' => 2, 'volume_cm' => 10,
                        ]);
                    }
                }
            }
        }
    }

    public function test_listings_bornes(): void
    {
        foreach (['programmes', 'niveaux', 'semestres', 'ues'] as $entite) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $reponse = $this->actingAs($this->admin)->get(route('admin.referentiel.entites.index', $entite));
            $reponse->assertOk();
            $requetes = count(DB::getQueryLog());
            DB::disableQueryLog();

            $this->assertLessThan(15, $requetes, "N+1 sur l'index {$entite} ({$requetes} requêtes).");
        }
    }

    public function test_tableau_bord_sans_n_plus_un(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $reponse = $this->actingAs($this->admin)->get(route('admin.referentiel.tableau-bord'));
        $reponse->assertOk();
        $requetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(45, $requetes, "N+1 au tableau de bord ({$requetes} requêtes).");
    }
}
