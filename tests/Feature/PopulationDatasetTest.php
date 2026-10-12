<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Exports\ListeEtudiantsService;
use App\Services\Exports\PopulationDataset;
use App\Services\PopulationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Datasets population (§18-19, §31) : registre pur (aucune query),
 * whitelist par dataset, funnel unique vers la centrale.
 */
class PopulationDatasetTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalogue_complet_sans_logique(): void
    {
        foreach ([
            'students', 'students_by_group', 'students_by_program', 'students_by_level',
            'students_by_ue', 'students_by_ecue', 'students_by_teacher',
            'exam_candidates', 'attendance_list', 'grade_list',
        ] as $code) {
            $definition = PopulationDataset::definition($code);

            $this->assertNotEmpty($definition['label']);
            $this->assertNotEmpty($definition['filtres']);
            $this->assertNotEmpty($definition['colonnes']);
            $this->assertNotEmpty($definition['permission']);
        }

        $this->assertSame(PopulationDataset::codes(), array_keys(PopulationDataset::DATASETS));
    }

    public function test_filtres_whitelistes_par_dataset(): void
    {
        // matiere_id n'est pas un filtre du dataset groupe → ignoré.
        $this->assertSame(
            ['groupe_id' => 7],
            PopulationDataset::filtresPour('students_by_group', ['groupe_id' => 7, 'matiere_id' => 9])
        );

        // Bouche-trou nul/vide ignoré.
        $this->assertSame([], PopulationDataset::filtresPour('students', ['groupe_id' => null, 'search' => '']));

        $this->assertSame(
            ['matiere_id' => 9, 'tri' => 'nom'],
            PopulationDataset::filtresPour('students_by_ecue', ['matiere_id' => 9, 'tri' => 'nom'])
        );
    }

    public function test_regroupement_borne_au_dataset(): void
    {
        $this->assertSame(
            'groupe',
            PopulationDataset::regroupementPour('exam_candidates', ['regroupement' => 'groupe'])
        );
        // statut non autorisé pour exam_candidates → null.
        $this->assertNull(PopulationDataset::regroupementPour('exam_candidates', ['regroupement' => 'statut']));
        $this->assertNull(PopulationDataset::regroupementPour('students', []));
    }

    public function test_colonnes_defaut_ou_choix_catalogue(): void
    {
        $defaut = PopulationDataset::colonnesPour('attendance_list', []);
        $this->assertContains('numero', $defaut);
        $this->assertNotContains('email', $defaut);

        $choix = PopulationDataset::colonnesPour('students', ['colonnes' => ['matricule', 'nom']]);
        $this->assertSame(['matricule', 'nom'], $choix);
    }

    public function test_funnel_exige_permission_dataset(): void
    {
        // Compte sans rôle valide → aucune permission → 403 via Gate.
        $sansRole = User::query()->create([
            'name' => 'Sans rôle', 'email' => 'sansrole.dset@universite.test',
            'password' => 'Motdepasse@123',
        ]);
        $sansRole->forceFill(['role' => 'aucun'])->save();

        $this->expectException(AuthorizationException::class);
        app(PopulationService::class)->requete('grade_list', [], $sansRole);
    }

    public function test_funnel_meme_sql_que_centrale(): void
    {
        $admin = User::creerCompteBackOffice([
            'name' => 'S2', 'email' => 's2.dset@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $viaFunnel = app(PopulationService::class)->requete(
            'students_by_group', ['groupe_id' => 3], $admin
        )->toSql();
        $direct = app(ListeEtudiantsService::class)->requete(['groupe_id' => 3], $admin)->toSql();

        $this->assertSame($direct, $viaFunnel);
    }
}
