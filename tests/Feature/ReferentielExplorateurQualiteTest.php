<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Referentiel\Models\Domaine;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\Mention;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\Specialite;
use App\Modules\Referentiel\Models\UniteEnseignement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explorateur LMD (LOOP 05) + centre qualité (LOOP 06) : détails
 * hiérarchiques sans requête supplémentaire, badges de publication,
 * catégories/impacts de présentation (moteur canonique intact).
 */
class ReferentielExplorateurQualiteTest extends TestCase
{
    use RefreshDatabase;

    private User $scolarite;

    private User $adminFacA;

    private Faculte $faculteA;

    private Faculte $faculteB;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.referentiel.active' => true]);

        $this->faculteA = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'FDL', 'code' => 'FDL', 'active' => true]);
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.explo@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->adminFacA = User::creerCompteBackOffice([
            'name' => 'Admin A', 'email' => 'a.explo@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $this->creerChaine($this->faculteA, 'EXP-A');
        $this->creerChaine($this->faculteB, 'EXP-B');
    }

    private function creerChaine(Faculte $faculte, string $code): Programme
    {
        $domaine = Domaine::create(['faculte_id' => $faculte->id, 'code' => 'D-'.$code, 'nom' => 'Domaine '.$code, 'actif' => true]);
        $mention = Mention::create(['domaine_id' => $domaine->id, 'code' => 'M-'.$code, 'nom' => 'Mention '.$code, 'actif' => true]);
        $specialite = Specialite::create(['mention_id' => $mention->id, 'code' => 'S-'.$code, 'nom' => 'Spécialité '.$code, 'actif' => true]);
        $programme = Programme::create([
            'faculte_id' => $faculte->id, 'specialite_id' => $specialite->id,
            'code' => $code, 'nom' => 'Programme '.$code, 'statut' => 'actif', 'actif' => true,
        ]);
        $niveau = Niveau::create([
            'programme_id' => $programme->id, 'code' => 'L1', 'nom' => 'L1',
            'ordre' => 1, 'actif' => true,
        ]);
        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1',
            'numero' => 1, 'ordre' => 1, 'actif' => true,
        ]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $programme->id,
            'code' => 'UE-'.$code, 'nom' => 'UE '.$code, 'credits' => 6, 'actif' => true,
        ]);
        Matiere::create([
            'code' => 'EC-'.$code, 'nom' => 'ECUE '.$code, 'credits' => 6, 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'volume_cm' => 20, 'actif' => true,
        ]);

        return $programme;
    }

    public function test_arbre_details_badges_et_chemin(): void
    {
        $reponse = $this->actingAs($this->scolarite)->get(route('admin.referentiel.arbre'));

        $reponse->assertOk();
        $reponse->assertSee('UE-EXP-A');
        $reponse->assertSee('Sans maquette active');
        $reponse->assertSee('Prévalidation →');
        $reponse->assertSee('Chemin :');
        $reponse->assertSee('D-EXP-A');
    }

    public function test_arbre_badge_maquette_active(): void
    {
        $programme = Programme::where('code', 'EXP-A')->firstOrFail();
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        Maquette::create([
            'programme_id' => $programme->id, 'annee_universitaire_id' => $annee->id,
            'version' => 1, 'statut' => 'active',
        ]);

        $this->actingAs($this->scolarite)->get(route('admin.referentiel.arbre'))
            ->assertOk()->assertSee('Maquette active v1');
    }

    public function test_arbre_scope_et_filtre(): void
    {
        $reponse = $this->actingAs($this->adminFacA)->get(route('admin.referentiel.arbre'));
        $reponse->assertOk();
        $reponse->assertSee('EXP-A');
        $reponse->assertDontSee('EXP-B');

        $this->actingAs($this->scolarite)->get(route('admin.referentiel.arbre', ['q' => 'EXP-A']))
            ->assertOk()->assertSee('EXP-A');
    }

    public function test_qualite_categories_impacts_et_filtre(): void
    {
        $reponse = $this->actingAs($this->scolarite)->get(route('admin.referentiel.qualite'));

        $reponse->assertOk();
        $reponse->assertSee('Catégorie');
        $reponse->assertSee('Impact');
        $reponse->assertSee('Maquettes');
        $reponse->assertSee('prévalidation');

        // Filtre erreur : aucune erreur en fixture → état vide, pas de ligne.
        $filtre = $this->actingAs($this->scolarite)
            ->get(route('admin.referentiel.qualite', ['gravite' => 'erreur']));
        $filtre->assertOk();
        $filtre->assertSee('Aucune anomalie détectée');
        $filtre->assertDontSee('EXP-A');
    }

    public function test_qualite_scope_facute(): void
    {
        $reponse = $this->actingAs($this->adminFacA)->get(route('admin.referentiel.qualite'));

        $reponse->assertOk();
        $reponse->assertSee('EXP-A');
        $reponse->assertDontSee('EXP-B');
    }
}
