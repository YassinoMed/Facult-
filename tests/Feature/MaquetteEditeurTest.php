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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Éditeur visuel de maquette (ENTERPRISE LOOP 03) : regroupement par
 * semestre, totaux serveur, ordre/volumes éditables (brouillon),
 * prérequis affichés, recherche d'affichage, verrouillage respecté.
 */
class MaquetteEditeurTest extends TestCase
{
    use RefreshDatabase;

    private User $scolarite;

    private Programme $programme;

    private Maquette $maquette;

    private UniteEnseignement $ue;

    private Matiere $ecue1;

    private Matiere $ecue2;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.referentiel.active' => true]);

        $faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.editeur@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->programme = Programme::create([
            'faculte_id' => $faculte->id, 'code' => 'EDT', 'nom' => 'Programme éditeur',
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
        $this->ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $this->programme->id,
            'code' => 'UE-EDT', 'nom' => 'UE éditeur', 'credits' => 6,
            'coefficient' => 1, 'type' => 'fondamentale', 'actif' => true,
        ]);
        $this->ecue1 = Matiere::create([
            'code' => 'EC-EDT-1', 'nom' => 'ECUE 1', 'credits' => 3, 'ue_id' => $this->ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'volume_cm' => 20, 'volume_td' => 10,
            'volume_tp' => 0, 'ordre' => 1, 'actif' => true,
        ]);
        $this->ecue2 = Matiere::create([
            'code' => 'EC-EDT-2', 'nom' => 'ECUE 2', 'credits' => 3, 'ue_id' => $this->ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'volume_cm' => 20, 'volume_td' => 0,
            'volume_tp' => 0, 'ordre' => 2, 'actif' => true,
        ]);
        $this->maquette = Maquette::create([
            'programme_id' => $this->programme->id,
            'annee_universitaire_id' => $annee->id,
            'version' => 1, 'statut' => 'draft',
        ]);
        $ligne = MaquetteUe::create([
            'maquette_id' => $this->maquette->id, 'ue_id' => $this->ue->id,
            'semestre_id' => $semestre->id,
        ]);
        foreach ([$this->ecue1, $this->ecue2] as $ordre => $ecue) {
            MaquetteUeEcue::create([
                'maquette_ue_id' => $ligne->id, 'matiere_id' => $ecue->id,
                'ordre' => $ordre, 'coefficient' => 1, 'credits' => 3,
                'volume_cm' => 20, 'volume_td' => 10, 'volume_tp' => 0,
                'regime' => 'obligatoire', 'obligatoire' => true,
            ]);
        }
    }

    public function test_fiche_affiche_resume_semestres_et_etats(): void
    {
        $reponse = $this->actingAs($this->scolarite)
            ->get(route('admin.referentiel.maquettes.show', $this->maquette));

        $reponse->assertOk();
        $reponse->assertSee('Résumé du plan');
        $reponse->assertSee('Semestre S1');
        $reponse->assertSee('Cohérente');
        $reponse->assertSee('Filtrer les UE affichées');
        $reponse->assertSee('Filtrer les ECUE affichées');
        $reponse->assertSee('Comparer');
    }

    public function test_ordre_volumes_et_prerequis_editables_brouillon(): void
    {
        $this->ecue2->prerequis()->syncWithoutDetaching([$this->ecue1->id]);

        $ligne = MaquetteUe::where('maquette_id', $this->maquette->id)->firstOrFail();

        $reponse = $this->actingAs($this->scolarite)->put(
            route('admin.referentiel.maquettes.ecues', $this->maquette),
            ['ecues' => [
                ['maquette_ue_id' => $ligne->id, 'matiere_id' => $this->ecue1->id,
                    'ordre' => 2, 'volume_cm' => 15, 'volume_td' => 5, 'volume_tp' => 4],
                ['maquette_ue_id' => $ligne->id, 'matiere_id' => $this->ecue2->id,
                    'ordre' => 1, 'volume_cm' => 20, 'volume_td' => 10, 'volume_tp' => 0],
            ]]
        );

        $reponse->assertRedirect();
        $this->assertDatabaseHas('maquette_ue_ecue', [
            'maquette_ue_id' => $ligne->id, 'matiere_id' => $this->ecue1->id,
            'ordre' => 2, 'volume_cm' => 15, 'volume_tp' => 4,
        ]);

        // Matière canonique jamais mutée par l'éditeur.
        $this->assertSame(20, (int) $this->ecue1->fresh()->volume_cm);

        // Prérequis affichés (lecture seule).
        $this->actingAs($this->scolarite)
            ->get(route('admin.referentiel.maquettes.show', $this->maquette))
            ->assertOk()->assertSee('EC-EDT-1');
    }

    public function test_maquette_verrouillee_figee(): void
    {
        $this->maquette->update(['statut' => 'approved']);

        $reponse = $this->actingAs($this->scolarite)
            ->get(route('admin.referentiel.maquettes.show', $this->maquette));

        $reponse->assertOk();
        $reponse->assertDontSee('name="ecues');
        $reponse->assertSee('Contextes figés');
    }

    public function test_fiche_sans_n_plus_un(): void
    {
        $requetes = 0;
        DB::listen(function () use (&$requetes) {
            $requetes++;
        });

        $this->actingAs($this->scolarite)
            ->get(route('admin.referentiel.maquettes.show', $this->maquette))
            ->assertOk();

        $this->assertLessThan(40, $requetes, "Fiche maquette : {$requetes} requêtes.");
    }

    public function test_lignage_versions_et_lien_comparer(): void
    {
        $this->actingAs($this->scolarite)
            ->get(route('admin.referentiel.maquettes.show', $this->maquette))
            ->assertOk()
            ->assertSee('Versions du programme')
            ->assertSee('version affichée');
    }
}
