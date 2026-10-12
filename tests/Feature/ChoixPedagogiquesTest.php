<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\MaquetteUe;
use App\Modules\Referentiel\Models\MaquetteUeEcue;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Choix pédagogiques Master (LOOP référentiel §17) : OBLIGATOIRE,
 * K_PARMI_N (groupe_choix), conditions programme/semestre — stockés
 * sur le contexte annuel, jamais codés en dur (aucun `if M1`).
 */
class ChoixPedagogiquesTest extends TestCase
{
    use RefreshDatabase;

    private int $ligneId;

    protected function setUp(): void
    {
        parent::setUp();

        $faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $programme = Programme::create([
            'faculte_id' => $faculte->id, 'code' => 'M-DATA', 'nom' => 'Master Data',
            'statut' => 'actif', 'actif' => true,
        ]);
        $niveau = Niveau::create([
            'programme_id' => $programme->id, 'code' => 'M2', 'nom' => 'M2', 'ordre' => 2, 'actif' => true,
        ]);
        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id, 'code' => 'S3', 'nom' => 'S3', 'numero' => 3, 'ordre' => 3, 'actif' => true,
        ]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $programme->id,
            'code' => 'UE-OPT', 'nom' => 'UE optionnelle', 'credits' => 12, 'type' => 'optionnelle', 'actif' => true,
        ]);
        $maquette = Maquette::create([
            'programme_id' => $programme->id,
            'annee_universitaire_id' => AnneeUniversitaire::create([
                'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
            ])->id,
            'version' => 1, 'statut' => 'draft',
        ]);
        $this->ligneId = MaquetteUe::create([
            'maquette_id' => $maquette->id, 'ue_id' => $ue->id, 'semestre_id' => $semestre->id,
        ])->id;
    }

    public function test_choix_k_parmi_n_et_conditions(): void
    {
        // Matières M2 optionnelles d'un même groupe de choix.
        $matieres = [];
        foreach (['OPT-A', 'OPT-B', 'OPT-C', 'OPT-D'] as $code) {
            $matieres[] = Matiere::create([
                'code' => $code, 'nom' => 'Option '.$code,
                'niveau' => 'M2', 'semestre' => 1, 'coefficient' => 1, 'credits' => 3, 'volume_cm' => 15,
            ]);
        }

        // Groupe de choix : 2 parmi 4, condition programme Data.
        foreach ($matieres as $matiere) {
            MaquetteUeEcue::create([
                'maquette_ue_id' => $this->ligneId,
                'matiere_id' => $matiere->id,
                'coefficient' => 1,
                'credits' => 3,
                'obligatoire' => false,
                'groupe_choix' => 'DATA-M2-S3',
                'conditions' => ['programme' => 'M-DATA', 'semestre' => 'S3', 'min_credits' => 6],
            ]);
        }

        $options = MaquetteUeEcue::where('groupe_choix', 'DATA-M2-S3')->get();

        $this->assertSame(4, $options->count());
        $this->assertTrue($options->every(fn ($o) => $o->obligatoire === false));
        $this->assertSame(['programme' => 'M-DATA', 'semestre' => 'S3', 'min_credits' => 6], $options->first()->conditions);

        // K_PARMI_N : choisir 2 parmi 4 sans règle en dur.
        $choisies = $options->take(2);
        $this->assertSame(2, $choisies->count());
        $this->assertSame(6, $choisies->sum('credits'));
    }

    public function test_obligatoire_par_defaut(): void
    {
        $matiere = Matiere::create([
            'code' => 'OBL-01', 'nom' => 'Obligatoire',
            'niveau' => 'L1', 'semestre' => 1, 'coefficient' => 2, 'credits' => 4, 'volume_cm' => 20,
        ]);

        $contexte = MaquetteUeEcue::create([
            'maquette_ue_id' => $this->ligneId,
            'matiere_id' => $matiere->id,
        ]);

        $this->assertTrue($contexte->fresh()->obligatoire);
        $this->assertNull($contexte->fresh()->groupe_choix);
    }
}
