<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\MaquetteUe;
use App\Modules\Referentiel\Models\MaquetteUeEcue;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Referentiel\Services\MaquetteComparaisonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Comparaison de versions (ENTERPRISE LOOP 04) : ajouts, retraits,
 * modifications champ par champ, écarts de totaux — lecture seule,
 * même programme exigé, périmètre vérifié.
 */
class MaquetteComparaisonTest extends TestCase
{
    use RefreshDatabase;

    private User $scolarite;

    private User $adminFacB;

    private Programme $programme;

    private Maquette $v1;

    private Maquette $v2;

    private Matiere $ecueGardee;

    private Matiere $ecueRetiree;

    private Matiere $ecueAjoutee;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.referentiel.active' => true]);

        $faculteA = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $faculteB = Faculte::create(['nom' => 'FDL', 'code' => 'FDL', 'active' => true]);
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.comp@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->adminFacB = User::creerCompteBackOffice([
            'name' => 'Admin B', 'email' => 'b.comp@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $faculteB->id,
        ], 'admin_faculte');

        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->programme = Programme::create([
            'faculte_id' => $faculteA->id, 'code' => 'CMP', 'nom' => 'Programme comparaison',
            'statut' => 'actif', 'actif' => true,
        ]);
        $niveau = Niveau::create([
            'programme_id' => $this->programme->id, 'code' => 'L1', 'nom' => 'L1',
            'ordre' => 1, 'actif' => true,
        ]);
        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1',
            'numero' => 1, 'ordre' => 1, 'actif' => true,
        ]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $this->programme->id,
            'code' => 'UE-CMP', 'nom' => 'UE comparaison', 'credits' => 9, 'actif' => true,
        ]);
        $fabriquer = fn (string $code, int $credits) => Matiere::create([
            'code' => $code, 'nom' => 'ECUE '.$code, 'credits' => $credits, 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'volume_cm' => 20, 'actif' => true,
        ]);
        $this->ecueGardee = $fabriquer('EC-GARD', 3);
        $this->ecueRetiree = $fabriquer('EC-RET', 3);
        $this->ecueAjoutee = $fabriquer('EC-AJ', 4);

        $this->v1 = Maquette::create([
            'programme_id' => $this->programme->id, 'annee_universitaire_id' => $annee->id,
            'version' => 1, 'statut' => 'archived',
        ]);
        $this->v2 = Maquette::create([
            'programme_id' => $this->programme->id, 'annee_universitaire_id' => $annee->id,
            'version' => 2, 'statut' => 'draft',
        ]);

        $ligne1 = MaquetteUe::create([
            'maquette_id' => $this->v1->id, 'ue_id' => $ue->id, 'semestre_id' => $semestre->id,
        ]);
        $ligne2 = MaquetteUe::create([
            'maquette_id' => $this->v2->id, 'ue_id' => $ue->id, 'semestre_id' => $semestre->id,
        ]);

        foreach ([$this->ecueGardee, $this->ecueRetiree] as $ecue) {
            MaquetteUeEcue::create([
                'maquette_ue_id' => $ligne1->id, 'matiere_id' => $ecue->id,
                'ordre' => 0, 'coefficient' => 1, 'credits' => 3,
                'volume_cm' => 20, 'volume_td' => 0, 'volume_tp' => 0,
                'regime' => 'obligatoire', 'obligatoire' => true,
            ]);
        }
        // v2 : gardée avec crédits modifiés + ajoutée, retirée absente.
        MaquetteUeEcue::create([
            'maquette_ue_id' => $ligne2->id, 'matiere_id' => $this->ecueGardee->id,
            'ordre' => 0, 'coefficient' => 2, 'credits' => 5,
            'volume_cm' => 20, 'volume_td' => 0, 'volume_tp' => 0,
            'regime' => 'optionnelle', 'obligatoire' => false,
        ]);
        MaquetteUeEcue::create([
            'maquette_ue_id' => $ligne2->id, 'matiere_id' => $this->ecueAjoutee->id,
            'ordre' => 1, 'coefficient' => 1, 'credits' => 4,
            'volume_cm' => 20, 'volume_td' => 0, 'volume_tp' => 0,
            'regime' => 'obligatoire', 'obligatoire' => true,
        ]);
    }

    public function test_comparaison_detecte_ajouts_retraits_modifs(): void
    {
        $diff = app(MaquetteComparaisonService::class)->comparer($this->v1, $this->v2);

        $this->assertFalse($diff['identiques']);
        $this->assertSame(['EC-AJ'], array_column($diff['ecue_ajoutees'], 'code'));
        $this->assertSame(['EC-RET'], array_column($diff['ecue_retirees'], 'code'));
        $this->assertCount(1, $diff['ecue_modifiees']);
        $this->assertSame('EC-GARD', $diff['ecue_modifiees'][0]['matiere']);

        $champs = array_column($diff['ecue_modifiees'][0]['changements'], 'champ');
        $this->assertContains('credits', $champs);
        $this->assertContains('coefficient', $champs);
        $this->assertContains('regime', $champs);

        // Totaux : v1 = 3+3, v2 = 5+4 → écart +3.
        $this->assertSame(6, $diff['totaux']['avant']['credits']);
        $this->assertSame(9, $diff['totaux']['apres']['credits']);
        $this->assertSame(3, $diff['totaux']['ecarts']['credits']);
    }

    public function test_meme_version_identique(): void
    {
        $diff = app(MaquetteComparaisonService::class)->comparer($this->v1, $this->v1);

        $this->assertTrue($diff['identiques']);
    }

    public function test_programmes_differents_non_comparables(): void
    {
        $autreProgramme = Programme::create([
            'faculte_id' => $this->programme->faculte_id, 'code' => 'AUT', 'nom' => 'Autre programme',
            'statut' => 'actif', 'actif' => true,
        ]);
        $intruse = Maquette::create([
            'programme_id' => $autreProgramme->id,
            'annee_universitaire_id' => $this->v1->annee_universitaire_id,
            'version' => 1, 'statut' => 'draft',
        ]);

        try {
            app(MaquetteComparaisonService::class)->comparer($this->v1, $intruse);
            $this->fail('Comparaison inter-programmes acceptée.');
        } catch (\DomainException) {
            $this->assertTrue(true);
        }

        $this->actingAs($this->scolarite)
            ->get(route('admin.referentiel.maquettes.comparer', $this->v1).'?autre='.$intruse->id)
            ->assertRedirect();
    }

    public function test_vue_comparaison_et_filtre(): void
    {
        $url = route('admin.referentiel.maquettes.comparer', $this->v1).'?autre='.$this->v2->id;

        $reponse = $this->actingAs($this->scolarite)->get($url);
        $reponse->assertOk();
        $reponse->assertSee('EC-AJ');
        $reponse->assertSee('EC-RET');
        $reponse->assertSee('EC-GARD');
        $reponse->assertSee('Écart crédits');

        // Filtre ajouts : la section Modifications est masquée.
        $this->actingAs($this->scolarite)->get($url.'&filtre=ajouts')
            ->assertOk()->assertSee('EC-AJ')->assertDontSee('aria-label="Modifications"');
    }

    public function test_comparaison_idor(): void
    {
        $this->actingAs($this->adminFacB)
            ->get(route('admin.referentiel.maquettes.comparer', $this->v1).'?autre='.$this->v2->id)
            ->assertForbidden();
    }

    public function test_comparaison_necrit_rien(): void
    {
        $avant = [Maquette::count(), MaquetteUe::count(), MaquetteUeEcue::count()];

        app(MaquetteComparaisonService::class)->comparer($this->v1, $this->v2);
        $this->actingAs($this->scolarite)
            ->get(route('admin.referentiel.maquettes.comparer', $this->v1).'?autre='.$this->v2->id)
            ->assertOk();

        $this->assertSame($avant, [Maquette::count(), MaquetteUe::count(), MaquetteUeEcue::count()]);
    }
}
