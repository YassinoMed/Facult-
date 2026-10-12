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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contextes annuels maquette_ue_ecue (LOOP référentiel §6B) : la matière
 * canonique n'est jamais mutée, les valeurs annuelles vivent dans le
 * contexte ; backfill déterministe et idempotent.
 */
class MatiereContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_contexte_porte_valeurs_annuelles_sans_muter_canonique(): void
    {
        $faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $programme = Programme::create([
            'faculte_id' => $faculte->id, 'code' => 'LGL', 'nom' => 'Licence GL',
            'statut' => 'actif', 'actif' => true,
        ]);
        $niveau = Niveau::create(['programme_id' => $programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1, 'actif' => true]);
        $semestre = SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1, 'actif' => true]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $programme->id,
            'code' => 'UE01', 'nom' => 'UE', 'credits' => 30, 'actif' => true,
        ]);
        $matiere = Matiere::create([
            'code' => 'BDD1', 'nom' => 'Bases de données', 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'coefficient' => 2, 'credits' => 4, 'volume_cm' => 20,
        ]);
        $maquette = Maquette::create([
            'programme_id' => $programme->id,
            'annee_universitaire_id' => AnneeUniversitaire::create([
                'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
            ])->id,
            'version' => 1, 'statut' => 'draft',
        ]);
        $ligne = MaquetteUe::create(['maquette_id' => $maquette->id, 'ue_id' => $ue->id, 'semestre_id' => $semestre->id]);

        $contexte = MaquetteUeEcue::create([
            'maquette_ue_id' => $ligne->id,
            'matiere_id' => $matiere->id,
            'coefficient' => 3,
            'credits' => 6,
            'obligatoire' => false,
            'groupe_choix' => 'OPT-S1',
            'conditions' => ['niveau' => 'L1'],
        ]);

        // Le contexte porte ses valeurs propres…
        $this->assertSame(3.0, (float) $contexte->fresh()->coefficient);
        $this->assertSame('OPT-S1', $contexte->fresh()->groupe_choix);
        // …sans muter la canonique.
        $this->assertSame(2.0, (float) $matiere->fresh()->coefficient);
        $this->assertSame(4, (int) $matiere->fresh()->credits);

        // Unicité du couple (ligne, matière).
        try {
            MaquetteUeEcue::create(['maquette_ue_id' => $ligne->id, 'matiere_id' => $matiere->id]);
            $this->fail('Doublon contexte accepté.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_backfill_contextes_idempotent(): void
    {
        $faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $programme = Programme::create([
            'faculte_id' => $faculte->id, 'code' => 'LGL', 'nom' => 'Licence GL',
            'statut' => 'actif', 'actif' => true,
        ]);
        $niveau = Niveau::create(['programme_id' => $programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1, 'actif' => true]);
        $semestre = SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1, 'actif' => true]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $programme->id,
            'code' => 'UE01', 'nom' => 'UE', 'credits' => 30, 'actif' => true,
        ]);
        Matiere::create([
            'code' => 'CTX-A', 'nom' => 'A', 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'coefficient' => 2, 'credits' => 4, 'volume_cm' => 20,
        ]);
        Matiere::create([
            'code' => 'CTX-B', 'nom' => 'B', 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'coefficient' => 1, 'credits' => 2, 'volume_cm' => 10,
        ]);
        $maquette = Maquette::create([
            'programme_id' => $programme->id,
            'annee_universitaire_id' => AnneeUniversitaire::create([
                'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
            ])->id,
            'version' => 1, 'statut' => 'draft',
        ]);
        MaquetteUe::create(['maquette_id' => $maquette->id, 'ue_id' => $ue->id, 'semestre_id' => $semestre->id]);

        $this->artisan('referentiel:backfill', ['--force' => true])->assertSuccessful();
        $this->assertSame(2, MaquetteUeEcue::count());

        // Idempotence : second passage sans doublon.
        $this->artisan('referentiel:backfill', ['--force' => true])->assertSuccessful();
        $this->assertSame(2, MaquetteUeEcue::count());

        // Dry-run par défaut : aucune écriture.
        MaquetteUeEcue::query()->delete();
        $this->artisan('referentiel:backfill')->assertSuccessful();
        $this->assertSame(0, MaquetteUeEcue::count());
    }
}
