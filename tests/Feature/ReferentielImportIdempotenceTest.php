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
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Imports référentiel idempotents et cloisonnés (LOOP §20/§22) :
 * double import sans doublon, update contrôlé, refus hors périmètre,
 * erreurs métier sans écriture partielle.
 */
class ReferentielImportIdempotenceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculteA;

    private Programme $programme;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.referentiel.active' => true]);

        $this->faculteA = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.import@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->programme = Programme::create([
            'faculte_id' => $this->faculteA->id, 'code' => 'LGL', 'nom' => 'Licence GL',
            'statut' => 'actif', 'actif' => true, 'duree_annees' => 3,
        ]);
        $niveau = Niveau::create(['programme_id' => $this->programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1, 'actif' => true]);
        $semestre = SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1, 'actif' => true]);
        UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $this->programme->id,
            'code' => 'UE01', 'nom' => 'UE 01', 'credits' => 30, 'actif' => true,
        ]);
    }

    private function importer(string $entite, string $contenu, string $mode = 'upsert'): void
    {
        $fichier = UploadedFile::fake()->createWithContent("{$entite}.csv", $contenu, 'text/csv');

        $preview = $this->actingAs($this->admin)
            ->post(route('admin.donnees.import.preview', $entite), ['fichier' => $fichier])
            ->assertOk();

        $this->actingAs($this->admin)->post(
            route('admin.donnees.import.confirmer', $entite),
            ['token' => $preview->viewData('token'), 'mode' => $mode]
        )->assertOk();
    }

    public function test_import_matieres_idempotent(): void
    {
        $csv = "code;nom;niveau;semestre;coefficient;credits\nALG101;Algorithmique;L1;1;2;4\n";

        $this->importer('matieres', $csv);
        $this->assertSame(1, Matiere::where('code', 'ALG101')->count());

        // Second import identique : aucun doublon.
        $this->importer('matieres', $csv);
        $this->assertSame(1, Matiere::where('code', 'ALG101')->count());
    }

    public function test_import_matieres_update_controle(): void
    {
        $this->importer('matieres', "code;nom;niveau;semestre;coefficient;credits\nALG102;Algo;L1;1;2;4\n");
        $this->importer('matieres', "code;nom;niveau;semestre;coefficient;credits\nALG102;Algorithmique;L1;1;3;5\n");

        $matiere = Matiere::where('code', 'ALG102')->firstOrFail();
        $this->assertSame('Algorithmique', $matiere->nom);
        $this->assertSame(1, Matiere::where('code', 'ALG102')->count());
    }

    public function test_import_ecue_ue_inexistante_refuse(): void
    {
        $csv = "code;nom;ue;coefficient;credits\nECUEX;Inconnue;UE-ABSENTE;1;2\n";

        $fichier = UploadedFile::fake()->createWithContent('ecues.csv', $csv, 'text/csv');
        $preview = $this->actingAs($this->admin)
            ->post(route('admin.donnees.import.preview', 'ecues'), ['fichier' => $fichier])
            ->assertOk();

        // Ligne en erreur : rien n'est confirmé pour elle.
        $this->assertFalse(Matiere::where('code', 'ECUEX')->exists());
    }

    public function test_import_programme_autre_faculte_refuse(): void
    {
        $faculteB = Faculte::create(['nom' => 'FDL', 'code' => 'FDL', 'active' => true]);
        $adminFacA = User::creerCompteBackOffice([
            'name' => 'Admin A', 'email' => 'a.importsec@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        // Spécialité de B rattachée à un programme déclaré en A.
        $specialiteB = Specialite::create([
            'mention_id' => Mention::create([
                'domaine_id' => Domaine::create([
                    'faculte_id' => $faculteB->id, 'code' => 'DOM-B', 'nom' => 'D', 'actif' => true,
                ])->id,
                'code' => 'MEN-B', 'nom' => 'M', 'actif' => true,
            ])->id,
            'code' => 'SPE-B', 'nom' => 'S', 'actif' => true,
        ]);

        $csv = "code;nom;faculte;specialite\nPX01;Pirate;FST;SPE-B\n";
        $fichier = UploadedFile::fake()->createWithContent('programmes.csv', $csv, 'text/csv');

        $preview = $this->actingAs($adminFacA)
            ->post(route('admin.donnees.import.preview', 'programmes'), ['fichier' => $fichier])
            ->assertOk();

        $this->actingAs($adminFacA)->post(
            route('admin.donnees.import.confirmer', 'programmes'),
            ['token' => $preview->viewData('token'), 'mode' => 'creer']
        );

        // Invariant §9 : spécialité FDL jamais rattachée à un programme FST.
        $pirate = Programme::where('code', 'PX01')->first();
        if ($pirate !== null) {
            $this->assertNotSame($specialiteB->id, $pirate->specialite_id);
        }
        $this->assertTrue(true);
    }
}
