<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DossierAcademique;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * Tests fonctionnels du registre des dossiers académiques :
 * recherche, filtres, création (unicité), consultation, édition, suppression.
 */
class ArchiveTest extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    public function test_registre_inaccessible_aux_invités(): void
    {
        $this->get(route('admin.archives.index'))
            ->assertRedirect(route('admin.connexion.show'));
    }

    public function test_registre_liste_les_dossiers_avec_cin_masquee(): void
    {
        $this->scolarite('super_admin');
        $etudiant = $this->etudiantAvecDossier();
        DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-00042',
            'date_archivage' => now()->toDateString(),
        ]);

        $response = $this->get(route('admin.archives.index'));

        $response->assertOk()
            ->assertSee('MAT-00042')
            ->assertSee($etudiant->nom_complet)
            ->assertDontSee($etudiant->cin) // CIN complète jamais affichée en liste
            ->assertSee($etudiant->cin_masquee);
    }

    public function test_recherche_multicritere_matricule_nom_cin(): void
    {
        $this->scolarite('super_admin');
        $etudiant = $this->etudiantAvecDossier();
        DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-00077',
            'date_archivage' => now()->toDateString(),
        ]);

        $this->get(route('admin.archives.index', ['search' => 'MAT-00077']))->assertOk()->assertSee($etudiant->nom_complet);
        $this->get(route('admin.archives.index', ['search' => 'TestEtudiant']))->assertOk()->assertSee('MAT-00077');
        $this->get(route('admin.archives.index', ['search' => $etudiant->cin]))->assertOk()->assertSee('MAT-00077');
        $this->get(route('admin.archives.index', ['search' => 'INEXISTANT999']))->assertOk()->assertDontSee('MAT-00077');
    }

    public function test_filtre_par_statut(): void
    {
        $this->scolarite('super_admin');
        $etudiant = $this->etudiantAvecDossier();
        $dossier = DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-CLOS-1',
            'date_archivage' => now()->toDateString(),
            'statut' => 'clos',
        ]);

        $this->get(route('admin.archives.index', ['statut' => 'actif']))->assertOk()->assertDontSee('MAT-CLOS-1');
        $this->get(route('admin.archives.index', ['statut' => 'clos']))->assertOk()->assertSee('MAT-CLOS-1');
    }

    public function test_creation_de_dossier_genere_le_matricule_et_journalise(): void
    {
        $agent = $this->scolarite('admin_scolarite');
        $etudiant = $this->etudiantAvecDossier();

        $response = $this->post(route('admin.archives.store'), [
            'etudiant_id' => $etudiant->id,
        ]);

        $dossier = DossierAcademique::where('etudiant_id', $etudiant->id)->first();

        $response->assertRedirect(route('admin.archives.show', $dossier));
        $this->assertNotNull($dossier);
        $this->assertEquals(sprintf('MAT-%05d', $etudiant->id), $dossier->matricule);

        // Journal d'audit : création tracée avec l'acteur
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $etudiant->id,
            'utilisateur_id' => $agent->id,
            'action' => HistoriqueAcademique::ACTION_CREATION_DOSSIER,
        ]);
    }

    public function test_un_etudiant_ne_peut_avoir_deux_dossiers(): void
    {
        $this->scolarite('super_admin');
        $etudiant = $this->etudiantAvecDossier();
        DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-UNIQ-1',
            'date_archivage' => now()->toDateString(),
        ]);

        $this->post(route('admin.archives.store'), ['etudiant_id' => $etudiant->id])
            ->assertSessionHasErrors('etudiant_id');

        $this->assertDatabaseCount('archives_etudiants', 1);
    }

    public function test_consultation_du_dossier_affiche_la_synthèse_et_journalise(): void
    {
        $agent = $this->scolarite('consultation');
        $etudiant = $this->etudiantAvecDossier();
        $dossier = DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-VIEW-1',
            'date_archivage' => now()->toDateString(),
        ]);

        $response = $this->get(route('admin.archives.show', $dossier));

        $response->assertOk()
            ->assertSee('MAT-VIEW-1')
            ->assertSee($etudiant->nom_complet)
            ->assertSee('Dossier Académique');

        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $etudiant->id,
            'utilisateur_id' => $agent->id,
            'action' => HistoriqueAcademique::ACTION_CONSULTATION,
        ]);
    }

    public function test_modification_du_statut_et_journalisation(): void
    {
        $this->scolarite('admin_scolarite');
        $etudiant = $this->etudiantAvecDossier();
        $dossier = DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-UPD-1',
            'date_archivage' => now()->toDateString(),
        ]);

        $this->patch(route('admin.archives.update', $dossier), [
            'statut' => 'clos',
            'observation' => 'Parcours terminé, diplôme délivré.',
        ])->assertRedirect(route('admin.archives.show', $dossier));

        $this->assertDatabaseHas('archives_etudiants', [
            'id' => $dossier->id,
            'statut' => 'clos',
            'observation' => 'Parcours terminé, diplôme délivré.',
        ]);

        $this->assertDatabaseHas('historique_academique', [
            'action' => HistoriqueAcademique::ACTION_MODIFICATION_DOSSIER,
        ]);
    }

    public function test_suppression_du_dossier_cascade_et_journalise(): void
    {
        $this->scolarite('super_admin');
        $etudiant = $this->etudiantAvecDossier();
        $dossier = DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-DEL-1',
            'date_archivage' => now()->toDateString(),
        ]);

        // Une inscription rattachée : elle doit disparaître en cascade
        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => AnneeUniversitaire::create([
                'libelle' => '2024-2025', 'date_debut' => '2024-09-01', 'date_fin' => '2025-07-15',
            ])->id,
            'niveau' => 'L1',
        ]);

        $this->delete(route('admin.archives.destroy', $dossier), ['motif' => 'Doublon avéré avec un autre dossier.'])
            ->assertRedirect(route('admin.archives.index'));

        $this->assertDatabaseMissing('archives_etudiants', ['id' => $dossier->id]);
        $this->assertDatabaseMissing('inscriptions_academiques', ['id' => $inscription->id]);
        $entree = HistoriqueAcademique::where('action', HistoriqueAcademique::ACTION_SUPPRESSION_DOSSIER)->latest('id')->first();
        $this->assertNotNull($entree);
        $this->assertStringContainsString('Doublon avéré', (string) $entree->nouvelle_valeur);
    }

    public function test_export_csv_disponible_et_journalise(): void
    {
        $this->scolarite('admin_scolarite');
        $etudiant = $this->etudiantAvecDossier();
        $dossier = DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-EXP-1',
            'date_archivage' => now()->toDateString(),
        ]);

        $response = $this->get(route('admin.archives.export', $dossier));

        $response->assertOk();
        $this->assertDatabaseHas('historique_academique', [
            'action' => 'export_dossier',
            'entite' => 'dossier',
        ]);
    }

    /**
     * Audit §P3-22 : un `%` saisi dans la recherche est traité comme un
     * caractère littéral — il ne transforme pas la recherche en « tout voir ».
     */
    public function test_recherche_neutralise_le_joker_like_pourcent(): void
    {
        $this->scolarite('admin_scolarite');
        $this->etudiantAvecDossier();
        $this->etudiantAvecDossier();

        $this->get(route('admin.etudiants.index', ['search' => '%']))
            ->assertOk()
            ->assertDontSee('TestEtudiant', false);
    }

    /**
     * Audit §P3-18 : l'export CSV du dossier neutralise les formules
     * (préfixe apostrophe), comme tous les autres canaux (§62).
     */
    public function test_export_csv_neutralise_les_formules(): void
    {
        $this->scolarite('admin_scolarite');
        $etudiant = $this->etudiantAvecDossier();
        $dossier = DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-EXP-2',
            'date_archivage' => now()->toDateString(),
        ]);
        // Filière piégée : colonne exportée en clair dans le CSV.
        InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => AnneeUniversitaire::create([
                'libelle' => '2024-2025', 'date_debut' => '2024-09-01', 'date_fin' => '2025-07-15',
            ])->id,
            'niveau' => 'L1',
            'filiere' => '=SUM(A1:A9)',
        ]);

        $contenu = $this->get(route('admin.archives.export', $dossier))
            ->assertOk()
            ->streamedContent();

        // Formule neutralisée (apostrophe), jamais exécutable à l'ouverture.
        $this->assertStringContainsString("'=SUM(A1:A9)", $contenu);
    }
}
