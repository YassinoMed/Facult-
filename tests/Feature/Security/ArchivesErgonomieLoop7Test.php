<?php

namespace Tests\Feature\Security;

use App\Models\DossierAcademique;
use App\Models\Etudiant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * LOOP 7 — Ergonomie et accessibilité du registre : libellés des
 * champs, région de recherche, compteur aria-live, indicateurs.
 */
class ArchivesErgonomieLoop7Test extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    public function test_champs_accessibles_et_compteur_live(): void
    {
        $this->scolarite('super_admin');

        $contenu = $this->get(route('admin.archives.index'))->assertOk()->getContent();

        $this->assertStringContainsString('role="search"', $contenu);
        foreach (['recherche-registre', 'filtre-faculte', 'filtre-annee', 'filtre-niveau', 'filtre-statut', 'filtre-programme'] as $identifiant) {
            $this->assertStringContainsString('for="'.$identifiant.'"', $contenu);
            $this->assertStringContainsString('id="'.$identifiant.'"', $contenu);
        }
        $this->assertStringContainsString('aria-live="polite"', $contenu);
    }

    public function test_indicateurs_et_vues_mobiles(): void
    {
        $this->scolarite('super_admin');
        $this->etudiantAvecDossier();
        DossierAcademique::create([
            'etudiant_id' => Etudiant::latest('id')->first()->id,
            'matricule' => 'MAT-ERGO-1', 'date_archivage' => now()->toDateString(),
        ]);

        $contenu = $this->get(route('admin.archives.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Total dossiers', $contenu);
        $this->assertStringContainsString('Dossiers actifs', $contenu);
        $this->assertStringContainsString('Dossiers clos', $contenu);
        $this->assertStringContainsString('Réinitialiser', $contenu);
        $this->assertStringContainsString('lg:hidden', $contenu);
    }
}
