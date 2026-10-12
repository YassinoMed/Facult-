<?php

namespace Tests\Feature\Security;

use App\Models\DossierAcademique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * LOOP 4 — Complétude documentaire : checklist affichée, informative
 * uniquement (aucune décision modifiée), sans PII.
 */
class ArchivesCompletudeLoop4Test extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    public function test_checklist_affichee_dossier_incomplet(): void
    {
        $this->scolarite('super_admin');
        $etudiant = $this->etudiantAvecDossier();
        $dossier = DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-COMPLET-1', 'date_archivage' => now()->toDateString(),
        ]);

        $reponse = $this->get(route('admin.archives.show', $dossier))->assertOk();
        $contenu = $reponse->getContent();

        $reponse->assertSee('Pièces obligatoires');
        $reponse->assertSee('Dossier incomplet');
        $reponse->assertSee('Non fourni');
        $reponse->assertSee('sans effet automatique', false);
    }

    public function test_checklist_sans_effet_sur_decisions(): void
    {
        $this->scolarite('super_admin');
        $etudiant = $this->etudiantAvecDossier();
        $dossier = DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-COMPLET-2', 'date_archivage' => now()->toDateString(),
        ]);

        $this->get(route('admin.archives.show', $dossier))->assertOk();

        // La simple consultation n'a modifié ni le dossier ni l'étudiant
        // (le helper initialise l'étudiant à « valide » — inchangé ici).
        $this->assertSame('actif', $dossier->fresh()->statut);
        $this->assertSame('valide', $etudiant->fresh()->statut);
    }

    public function test_checklist_sans_cin_complete(): void
    {
        $this->scolarite('super_admin');
        $etudiant = $this->etudiantAvecDossier();
        $dossier = DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-COMPLET-3', 'date_archivage' => now()->toDateString(),
        ]);

        $reponse = $this->get(route('admin.archives.show', $dossier))->assertOk();

        // La checklist n'affiche que libellés + statuts (pas de nom de fichier).
        $this->assertStringNotContainsString('original_filename', $reponse->getContent());
    }
}
