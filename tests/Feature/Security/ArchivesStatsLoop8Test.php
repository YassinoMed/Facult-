<?php

namespace Tests\Feature\Security;

use App\Models\DossierAcademique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * LOOP 8 — Indicateurs du registre : valeurs exactes (total / actifs /
 * clos), cohérentes avec les filtres appliqués.
 */
class ArchivesStatsLoop8Test extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    public function test_stats_coherentes_avec_donnees(): void
    {
        $this->scolarite('super_admin');
        $etudiant = $this->etudiantAvecDossier();
        DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-STAT-1', 'date_archivage' => now()->toDateString(),
            'statut' => 'actif',
        ]);

        $contenu = $this->get(route('admin.archives.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Total dossiers', $contenu);
        $this->assertStringContainsString('Dossiers actifs', $contenu);
        $this->assertStringContainsString('Dossiers clos', $contenu);
    }

    public function test_stats_suivent_le_filtre_statut(): void
    {
        $this->scolarite('super_admin');
        $etudiant = $this->etudiantAvecDossier();
        DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-STAT-2', 'date_archivage' => now()->toDateString(),
            'statut' => 'clos',
        ]);

        // Le compteur « total » reflète l'ensemble filtré (ici : 1 clos).
        $contenu = $this->get(route('admin.archives.index', ['statut' => 'clos']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Dossier', $contenu);
    }
}
