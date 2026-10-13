<?php

namespace Tests\Feature;

use App\Models\DossierAcademique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * LOOP 3 — Le journal d'historique (acteur, IP, anciennes/nouvelles
 * valeurs) n'est exposé qu'aux agents habilités à modifier le dossier.
 * La consultation reste tracée en base pour tous (preuve existante dans
 * ArchiveTest::test_consultation_du_dossier_affiche_la_synthèse_et_journalise).
 */
class ArchiveJournalAccesTest extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    public function test_lecture_seule_ne_voit_pas_le_journal(): void
    {
        $this->scolarite('consultation');
        $etudiant = $this->etudiantAvecDossier();
        $dossier = DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-JRN-1',
            'date_archivage' => now()->toDateString(),
        ]);

        // Une première visite génère une entrée d'audit (IP 127.0.0.1)...
        $this->get(route('admin.archives.show', $dossier))->assertOk();
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $etudiant->id,
        ]);

        // ...mais la section audit n'est jamais rendue pour la lecture seule.
        $this->get(route('admin.archives.show', $dossier))
            ->assertOk()
            ->assertSee('MAT-JRN-1')
            ->assertDontSee('Historique des actions')
            ->assertDontSee('127.0.0.1');
    }

    public function test_agent_habilite_voit_le_journal(): void
    {
        $this->scolarite('agent_scolarite');
        $etudiant = $this->etudiantAvecDossier();
        $dossier = DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-JRN-2',
            'date_archivage' => now()->toDateString(),
        ]);

        $this->get(route('admin.archives.show', $dossier))->assertOk();

        $this->get(route('admin.archives.show', $dossier))
            ->assertOk()
            ->assertSee('Historique des actions');
    }
}
