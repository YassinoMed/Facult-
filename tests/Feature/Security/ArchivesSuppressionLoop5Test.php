<?php

namespace Tests\Feature\Security;

use App\Models\DossierAcademique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * LOOP 5 — Suppression contrôlée : motif obligatoire, transaction
 * unique, répétition idempotente (404 sans effet).
 */
class ArchivesSuppressionLoop5Test extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    public function test_suppression_sans_motif_refusee_dossier_intact(): void
    {
        $this->scolarite('super_admin');
        $etudiant = $this->etudiantAvecDossier();
        $dossier = DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-SUPPR-1', 'date_archivage' => now()->toDateString(),
        ]);

        $this->delete(route('admin.archives.destroy', $dossier))->assertSessionHasErrors('motif');
        $this->delete(route('admin.archives.destroy', $dossier), ['motif' => 'court'])
            ->assertSessionHasErrors('motif');
        $this->assertDatabaseHas('archives_etudiants', ['id' => $dossier->id]);
    }

    public function test_double_suppression_seconde_404_sans_effet(): void
    {
        $this->scolarite('super_admin');
        $etudiant = $this->etudiantAvecDossier();
        $dossier = DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-SUPPR-2', 'date_archivage' => now()->toDateString(),
        ]);

        $this->delete(route('admin.archives.destroy', $dossier), ['motif' => 'Dossier de test à purger.'])
            ->assertRedirect(route('admin.archives.index'));
        $this->delete('/admin/archives/'.$dossier->id, ['motif' => 'Dossier de test à purger.'])
            ->assertNotFound();
    }

    public function test_zone_dangereuse_visible_avec_permission(): void
    {
        $this->scolarite('super_admin');
        $etudiant = $this->etudiantAvecDossier();
        $dossier = DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-SUPPR-3', 'date_archivage' => now()->toDateString(),
        ]);

        $this->get(route('admin.archives.edit', $dossier))->assertOk()
            ->assertSee('Zone dangereuse', false)
            ->assertSee('Motif de suppression', false);
    }
}
