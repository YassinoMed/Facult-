<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\Semestre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * Catalogue des matières : gestion en configuration, unicité du code,
 * validation stricte, protection contre la suppression si des notes
 * sont rattachées.
 */
class SubjectTest extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    public function test_page_configuration_accessible_aux_roles_autorises(): void
    {
        $this->scolarite('admin_scolarite');
        $this->get(route('admin.archives.configuration.index'))->assertOk()->assertSee('Configuration Académique');

        $this->scolarite('consultation');
        $this->get(route('admin.archives.configuration.index'))->assertOk();
    }

    public function test_ajout_d_une_matiere_au_catalogue(): void
    {
        $this->scolarite('admin_scolarite');

        $this->post(route('admin.archives.matieres.store'), [
            'code' => 'PHYS401', 'nom' => 'Physique Quantique', 'coefficient' => 3, 'credits' => 5,
            'niveau' => 'M1', 'semestre' => 1,
        ])->assertRedirect(route('admin.archives.configuration.index'));

        $this->assertDatabaseHas('matieres', [
            'code' => 'PHYS401', 'nom' => 'Physique Quantique', 'coefficient' => 3, 'credits' => 5, 'niveau' => 'M1',
        ]);
    }

    public function test_code_matiere_unique(): void
    {
        $this->scolarite('super_admin');
        Matiere::create([
            'code' => 'INFO101', 'nom' => 'Algorithmique I', 'coefficient' => 2, 'credits' => 4, 'niveau' => 'L1', 'semestre' => 1,
        ]);

        $this->post(route('admin.archives.matieres.store'), [
            'code' => 'INFO101', 'nom' => 'Doublon', 'coefficient' => 1, 'credits' => 2, 'niveau' => 'L1', 'semestre' => 1,
        ])->assertSessionHasErrors('code');

        $this->assertDatabaseCount('matieres', 1);
    }

    public function test_validation_stricte_coefficient_et_semestre(): void
    {
        $this->scolarite('super_admin');

        $this->post(route('admin.archives.matieres.store'), [
            'code' => 'BAD001', 'nom' => 'Coef nul', 'coefficient' => 0, 'credits' => 3, 'niveau' => 'L1', 'semestre' => 1,
        ])->assertSessionHasErrors('coefficient');

        $this->post(route('admin.archives.matieres.store'), [
            'code' => 'BAD002', 'nom' => 'Semestre invalide', 'coefficient' => 1, 'credits' => 3, 'niveau' => 'L1', 'semestre' => 3,
        ])->assertSessionHasErrors('semestre');

        $this->assertDatabaseCount('matieres', 0);
    }

    public function test_agent_ne_peut_pas_modifier_le_catalogue(): void
    {
        $this->scolarite('agent_scolarite');

        $this->post(route('admin.archives.matieres.store'), [
            'code' => 'AGENT01', 'nom' => 'Interdit', 'coefficient' => 1, 'credits' => 2, 'niveau' => 'L1', 'semestre' => 1,
        ])->assertForbidden();

        $this->assertDatabaseMissing('matieres', ['code' => 'AGENT01']);
    }

    public function test_suppression_refusee_si_des_notes_sont_rattachees(): void
    {
        $this->scolarite('super_admin');
        $etudiant = $this->etudiantAvecDossier();
        $matiere = Matiere::create([
            'code' => 'LOCK01', 'nom' => 'Matière notée', 'coefficient' => 2, 'credits' => 3, 'niveau' => 'L1', 'semestre' => 1,
        ]);

        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => AnneeUniversitaire::create([
                'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-07-15',
            ])->id,
            'niveau' => 'L1',
        ]);
        $semestre = Semestre::create(['inscription_academique_id' => $inscription->id, 'numero' => 1]);
        Note::create([
            'etudiant_id' => $etudiant->id, 'inscription_academique_id' => $inscription->id,
            'semestre_id' => $semestre->id, 'matiere_id' => $matiere->id,
            'note' => 12, 'coefficient' => $matiere->coefficient, 'credits' => $matiere->credits, 'session' => 'normale',
        ]);

        $this->delete(route('admin.archives.matieres.destroy', $matiere))
            ->assertRedirect(route('admin.archives.configuration.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('matieres', ['id' => $matiere->id]);
    }

    public function test_suppression_autorisee_sans_notes(): void
    {
        $this->scolarite('super_admin');
        $matiere = Matiere::create([
            'code' => 'FREE01', 'nom' => 'Sans note', 'coefficient' => 1, 'credits' => 2, 'niveau' => 'L2', 'semestre' => 1,
        ]);

        $this->delete(route('admin.archives.matieres.destroy', $matiere))
            ->assertRedirect(route('admin.archives.configuration.index'));

        $this->assertDatabaseMissing('matieres', ['id' => $matiere->id]);
    }
}
