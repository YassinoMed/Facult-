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
use Tests\TestCase;

/**
 * Hiérarchie LMD (LOOP référentiel) : cardinalités 1-N vérifiées,
 * périmètres faculté par niveau, garde-fous de suppression.
 */
class ReferentielHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculteA;

    private Faculte $faculteB;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.referentiel.active' => true]);

        $this->faculteA = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'FDL', 'code' => 'FDL', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.hierarchie@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    private function creerProgramme(Faculte $faculte, string $code): Programme
    {
        $domaine = Domaine::create(['faculte_id' => $faculte->id, 'code' => 'DOM-'.$code, 'nom' => 'D', 'actif' => true]);
        $mention = Mention::create(['domaine_id' => $domaine->id, 'code' => 'MEN-'.$code, 'nom' => 'M', 'actif' => true]);
        $specialite = Specialite::create(['mention_id' => $mention->id, 'code' => 'SPE-'.$code, 'nom' => 'S', 'actif' => true]);

        return Programme::create([
            'faculte_id' => $faculte->id, 'specialite_id' => $specialite->id,
            'code' => $code, 'nom' => 'Programme '.$code, 'statut' => 'actif', 'actif' => true,
        ]);
    }

    public function test_chaine_complete_cardinalites(): void
    {
        $programme = $this->creerProgramme($this->faculteA, 'LGL');
        $niveau = Niveau::create(['programme_id' => $programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1, 'actif' => true]);
        $semestre = SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1, 'actif' => true]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $programme->id,
            'code' => 'UE01', 'nom' => 'UE', 'credits' => 30, 'actif' => true,
        ]);
        $matiere = Matiere::create([
            'code' => 'ALGO1', 'nom' => 'Algo', 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'coefficient' => 2, 'credits' => 4, 'volume_cm' => 20,
        ]);

        // Remontée complète de la chaîne.
        $this->assertSame($ue->id, $matiere->ue->id);
        $this->assertSame($semestre->id, $ue->semestre->id);
        $this->assertSame($niveau->id, $ue->semestre->niveau->id);
        $this->assertSame($programme->id, $ue->programme->id);
        $this->assertSame($this->faculteA->id, $matiere->faculteId());

        // Descente : 1 programme → N niveaux → N semestres → N UE → N ECUE.
        $this->assertTrue($programme->niveaux()->exists());
        $this->assertTrue($niveau->semestres()->exists());
        $this->assertTrue($semestre->unitesEnseignement()->exists());
        $this->assertTrue($ue->ecues()->exists());
    }

    public function test_suppression_bloquee_si_enfants(): void
    {
        $programme = $this->creerProgramme($this->faculteA, 'LBLQ');
        Niveau::create(['programme_id' => $programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1, 'actif' => true]);

        $this->actingAs($this->admin)
            ->delete(route('admin.referentiel.entites.destroy', ['programmes', $programme->id]))
            ->assertRedirect();

        $this->assertDatabaseHas('programmes', ['id' => $programme->id]);
    }

    public function test_matiere_sans_ue_transversale(): void
    {
        $matiere = Matiere::create([
            'code' => 'TRANS01', 'nom' => 'Transversale',
            'niveau' => 'L1', 'semestre' => 1, 'coefficient' => 1, 'credits' => 2, 'volume_cm' => 10,
        ]);

        $this->assertNull($matiere->faculteId());
    }

    public function test_contexte_maquette_ue_ecue(): void
    {
        $programme = $this->creerProgramme($this->faculteA, 'LCTX');
        $niveau = Niveau::create(['programme_id' => $programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1, 'actif' => true]);
        $semestre = SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1, 'actif' => true]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $programme->id,
            'code' => 'UE01', 'nom' => 'UE', 'credits' => 30, 'actif' => true,
        ]);
        $matiere = Matiere::create([
            'code' => 'CTX01', 'nom' => 'Contexte', 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'coefficient' => 2, 'credits' => 4, 'volume_cm' => 20,
        ]);

        $maquetteUe = MaquetteUe::create([
            'maquette_id' => Maquette::create([
                'programme_id' => $programme->id,
                'annee_universitaire_id' => AnneeUniversitaire::create([
                    'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
                ])->id,
                'version' => 1, 'statut' => 'draft',
            ])->id,
            'ue_id' => $ue->id,
            'semestre_id' => $semestre->id,
        ]);

        $contexte = MaquetteUeEcue::create([
            'maquette_ue_id' => $maquetteUe->id,
            'matiere_id' => $matiere->id,
            'coefficient' => 3,
            'credits' => 6,
            'obligatoire' => false,
            'groupe_choix' => 'OPT-L1',
            'conditions' => ['parcours' => 'SI'],
        ]);

        $this->assertSame($maquetteUe->id, $contexte->maquetteUe->id);
        $this->assertSame($matiere->id, $contexte->matiere->id);
        $this->assertFalse($contexte->fresh()->obligatoire);
        $this->assertSame('OPT-L1', $contexte->fresh()->groupe_choix);
        // La canonique n'est pas mutée par le contexte annuel.
        $this->assertSame(2.0, (float) $matiere->fresh()->coefficient);
    }
}
