<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\MaquetteUe;
use App\Modules\Referentiel\Models\MaquetteUeEcue;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\TypeDiplome;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Referentiel\Services\MaquetteActivationSimulationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Simulation d'activation des maquettes (MASTER LOOP §1.3) : prévalidation
 * LECTURE SEULE — aucune activation, aucune migration legacy. Chaque cas
 * prouve : validations, blocages codés, recommandation et pureté
 * (zéro écriture en base).
 */
class ReferentielActivationSimulationTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.referentiel.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        TypeDiplome::create(['code' => 'LIC', 'nom' => 'Licence', 'cycle' => 'licence', 'duree_annees' => 3, 'actif' => true]);
    }

    private function creerProgramme(string $code = 'LGL'): Programme
    {
        return Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => $code, 'nom' => 'Licence '.$code,
            'statut' => 'actif', 'actif' => true, 'duree_annees' => 3,
            'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
    }

    /**
     * Chaîne complète : 3 niveaux, 6 semestres, 1 UE de 6 crédits par
     * semestre, 2 ECUE de 3 crédits avec volumes par UE.
     *
     * @return array{programme: Programme, ues: Collection, ecues: Collection}
     */
    private function creerChaineComplete(Programme $programme): array
    {
        $ues = collect();
        $ecues = collect();
        $numero = 0;

        foreach (['L1' => 1, 'L2' => 2, 'L3' => 3] as $codeNiveau => $ordre) {
            $niveau = Niveau::create([
                'programme_id' => $programme->id, 'code' => $codeNiveau, 'nom' => $codeNiveau,
                'ordre' => $ordre, 'actif' => true,
            ]);

            for ($i = 1; $i <= 2; $i++) {
                $numero++;
                $semestre = SemestreLmd::create([
                    'niveau_id' => $niveau->id, 'code' => 'S'.$numero, 'nom' => 'Semestre '.$numero,
                    'numero' => $numero, 'ordre' => $numero, 'actif' => true,
                ]);
                $ue = UniteEnseignement::create([
                    'semestre_id' => $semestre->id, 'programme_id' => $programme->id,
                    'code' => 'UE-'.$codeNiveau.'-'.$i, 'nom' => 'UE '.$codeNiveau.' '.$i,
                    'credits' => 6, 'actif' => true,
                ]);
                $ues->push($ue);

                foreach ([1, 2] as $k) {
                    $ecues->push(Matiere::create([
                        'code' => 'EC-'.$ue->code.'-'.$k, 'nom' => 'ECUE '.$ue->code.' '.$k,
                        'credits' => 3, 'ue_id' => $ue->id,
                        'niveau' => $codeNiveau, 'semestre' => $numero,
                        'volume_cm' => 20, 'volume_td' => 10, 'volume_tp' => 0,
                        'actif' => true,
                    ]));
                }
            }
        }

        return ['programme' => $programme, 'ues' => $ues, 'ecues' => $ecues];
    }

    private function creerMaquette(Programme $programme, string $statut, int $version = 1): Maquette
    {
        return Maquette::create([
            'programme_id' => $programme->id,
            'annee_universitaire_id' => $this->annee->id,
            'version' => $version,
            'statut' => $statut,
        ]);
    }

    private function remplirPlan(Maquette $maquette, iterable $ues): void
    {
        foreach ($ues as $ue) {
            $ligne = MaquetteUe::create([
                'maquette_id' => $maquette->id, 'ue_id' => $ue->id, 'semestre_id' => $ue->semestre_id,
            ]);

            foreach (Matiere::where('ue_id', $ue->id)->orderBy('id')->get() as $ordre => $matiere) {
                MaquetteUeEcue::create([
                    'maquette_ue_id' => $ligne->id, 'matiere_id' => $matiere->id,
                    'ordre' => $ordre, 'coefficient' => 1, 'credits' => 3,
                    'volume_cm' => 20, 'volume_td' => 10, 'volume_tp' => 0,
                    'regime' => 'obligatoire', 'obligatoire' => true,
                ]);
            }
        }
    }

    private function service(): MaquetteActivationSimulationService
    {
        return app(MaquetteActivationSimulationService::class);
    }

    public function test_simulation_necrit_rien_en_base(): void
    {
        $programme = $this->creerProgramme();
        $this->creerChaineComplete($programme);
        $this->creerMaquette($programme, 'draft');

        $avant = [
            'programmes' => Programme::count(),
            'maquettes' => Maquette::count(),
            'ues' => UniteEnseignement::count(),
            'matieres' => Matiere::count(),
            'lignes' => MaquetteUe::count(),
            'occurrences' => MaquetteUeEcue::count(),
            'inscriptions' => InscriptionAcademique::count(),
        ];

        $rapport = $this->service()->simuler();

        foreach ($avant as $table => $nombre) {
            $this->assertSame($nombre, match ($table) {
                'programmes' => Programme::count(),
                'maquettes' => Maquette::count(),
                'ues' => UniteEnseignement::count(),
                'matieres' => Matiere::count(),
                'lignes' => MaquetteUe::count(),
                'occurrences' => MaquetteUeEcue::count(),
                'inscriptions' => InscriptionAcademique::count(),
            }, "Écriture détectée sur {$table}.");
        }

        $this->assertArrayHasKey('programmes', $rapport);
        $this->assertArrayHasKey('resume', $rapport);
    }

    public function test_programme_sans_maquette_bloque_avec_code(): void
    {
        $programme = $this->creerProgramme();
        $this->creerChaineComplete($programme);

        $resultat = $this->service()->simulerProgramme($programme);

        $this->assertSame('ATTENTE', $resultat['recommandation']);
        $codes = array_column($resultat['blocages'], 'code');
        $this->assertContains('MAQUETTE_ABSENTE', $codes);
        $this->assertNotEmpty($resultat['corrections']);
        // Chaîne complète : pas d'autre blocage structurel.
        $this->assertNotContains('SEMESTRES_NON_COUVERTS', $codes);
        $this->assertNotContains('CREDITS_INCOHERENTS', $codes);
    }

    public function test_maquette_brouillon_incomplete_reste_en_attente(): void
    {
        $programme = $this->creerProgramme();
        $niveau = Niveau::create([
            'programme_id' => $programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1, 'actif' => true,
        ]);
        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1, 'actif' => true,
        ]);
        // S2 existant mais sans UE : prouve SEMESTRES_NON_COUVERTS.
        SemestreLmd::create([
            'niveau_id' => $niveau->id, 'code' => 'S2', 'nom' => 'S2', 'numero' => 2, 'ordre' => 2, 'actif' => true,
        ]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $programme->id,
            'code' => 'UE01', 'nom' => 'UE 01', 'credits' => 6, 'actif' => true,
        ]);
        foreach ([1, 2] as $k) {
            Matiere::create([
                'code' => 'EC0'.$k, 'nom' => 'ECUE '.$k, 'credits' => 3, 'ue_id' => $ue->id,
                'niveau' => 'L1', 'semestre' => 1,
                'volume_cm' => 20, 'volume_td' => 0, 'volume_tp' => 0, 'actif' => true,
            ]);
        }
        $this->creerMaquette($programme, 'draft');

        $resultat = $this->service()->simulerProgramme($programme);

        $this->assertSame('ATTENTE', $resultat['recommandation']);
        $codes = array_column($resultat['blocages'], 'code');
        $this->assertContains('MAQUETTE_NON_APPROUVEE', $codes);
        $this->assertContains('NIVEAUX_INCOMPLETS', $codes);
        $this->assertContains('SEMESTRES_MANQUANTS', $codes);
        $this->assertContains('SEMESTRES_NON_COUVERTS', $codes);
    }

    public function test_maquette_approuvee_complete_est_activable(): void
    {
        $programme = $this->creerProgramme();
        $chaine = $this->creerChaineComplete($programme);
        $maquette = $this->creerMaquette($programme, 'approved');
        $this->remplirPlan($maquette, $chaine['ues']);

        $resultat = $this->service()->simulerProgramme($programme);

        $this->assertSame('ACTIVABLE', $resultat['recommandation']);
        $this->assertSame([], $resultat['blocages']);
        $this->assertNotEmpty($resultat['validations']);
    }

    public function test_maquette_active_complete_est_deja_active(): void
    {
        $programme = $this->creerProgramme();
        $chaine = $this->creerChaineComplete($programme);
        $maquette = $this->creerMaquette($programme, 'active');
        $this->remplirPlan($maquette, $chaine['ues']);

        $resultat = $this->service()->simulerProgramme($programme);

        $this->assertSame('ACTIVE_DEJA', $resultat['recommandation']);
        $this->assertSame([], $resultat['blocages']);
    }

    public function test_credits_incoherents_bloquent(): void
    {
        $programme = $this->creerProgramme();
        $chaine = $this->creerChaineComplete($programme);
        $chaine['ues']->first()->update(['credits' => 30]);

        $resultat = $this->service()->simulerProgramme($programme);

        $this->assertSame('ATTENTE', $resultat['recommandation']);
        $this->assertContains('CREDITS_INCOHERENTS', array_column($resultat['blocages'], 'code'));
    }

    public function test_cycle_prerequis_bloque(): void
    {
        $programme = $this->creerProgramme();
        $chaine = $this->creerChaineComplete($programme);
        [$a, $b] = [$chaine['ecues'][0], $chaine['ecues'][1]];

        // Cycle A ↔ B inséré en direct (le service refuse ce cycle en écriture).
        DB::table('ecue_prerequis')->insert([
            ['matiere_id' => $a->id, 'prerequis_id' => $b->id],
            ['matiere_id' => $b->id, 'prerequis_id' => $a->id],
        ]);

        $resultat = $this->service()->simulerProgramme($programme);

        $this->assertSame('ATTENTE', $resultat['recommandation']);
        $this->assertContains('PREREQUIS_CYCLE', array_column($resultat['blocages'], 'code'));
    }

    public function test_commande_simulation_sans_ecriture(): void
    {
        $programme = $this->creerProgramme('SIMU');
        $this->creerChaineComplete($programme);

        $avant = ['maquettes' => Maquette::count(), 'ues' => UniteEnseignement::count()];

        $this->artisan('referentiel:simuler-activation', ['--programme' => 'SIMU'])
            ->assertSuccessful();

        $this->assertSame($avant['maquettes'], Maquette::count());
        $this->assertSame($avant['ues'], UniteEnseignement::count());
    }

    public function test_commande_json_structuree(): void
    {
        $this->creerProgramme('JSON');

        $this->artisan('referentiel:simuler-activation', ['--programme' => 'JSON', '--json' => true])
            ->assertSuccessful();
    }
}
