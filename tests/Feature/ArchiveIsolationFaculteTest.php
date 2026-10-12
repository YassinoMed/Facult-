<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * Isolation inter-facultés du module Archives (LOOP 1) — complément des
 * écritures couvertes par tests/Feature/Security/ArchivesIsolationLoop1Test.php
 * (convergence : aucune redondance entre les deux suites).
 *
 * Cette suite verrouille : la lecture filtrée du registre, la non-divulgation
 * des 403, la route toggleStatut, l'export du registre (nouvelles routes) et
 * l'impossibilité structurelle de suppression pour un périmètre restreint.
 */
class ArchiveIsolationFaculteTest extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    private User $adminA;

    private int $faculteBId;

    private int $dossierAId;

    private int $dossierBId;

    private \App\Models\Etudiant $etudiantA;

    protected function setUp(): void
    {
        parent::setUp();

        // Deux facultés distinctes, un dossier de chaque côté.
        $this->etudiantA = $this->etudiantAvecDossier();
        $etudiantB = $this->etudiantAvecDossier();
        $etudiantB->update(['nom' => 'AUTREFACINVISIBLE', 'prenom' => 'JamaisVue']);

        $this->faculteBId = (int) $etudiantB->faculte_id;

        $this->dossierAId = \App\Models\DossierAcademique::create([
            'etudiant_id' => $this->etudiantA->id, 'matricule' => 'MAT-FACA-1', 'date_archivage' => now()->toDateString(),
        ])->id;
        $this->dossierBId = \App\Models\DossierAcademique::create([
            'etudiant_id' => $etudiantB->id, 'matricule' => 'MAT-FACB-SECRET', 'date_archivage' => now()->toDateString(),
        ])->id;

        // Admin RESTREINT à la faculté A (archives.view/create/update —
        // JAMAIS archives.delete, réservé aux rôles globaux).
        $this->adminA = User::creerCompteBackOffice([
            'name' => 'Admin Faculte A',
            'email' => uniqid('admin.a.', true).'@scolarite.test',
            'password' => Hash::make('Motdepasse@123'),
            'faculte_id' => (int) $this->etudiantA->faculte_id,
            'actif' => true,
        ], 'admin_faculte');

        $this->actingAs($this->adminA);
    }

    public function test_index_ne_liste_que_sa_faculte_meme_avec_filtre_faculte_etrangere(): void
    {
        $this->get(route('admin.archives.index'))
            ->assertOk()
            ->assertSee('MAT-FACA-1')
            ->assertDontSee('MAT-FACB-SECRET');

        // Le paramètre faculte_id étranger est ignoré : le périmètre serveur
        // prime, jamais de fuite par filtre.
        $this->get(route('admin.archives.index', ['faculte_id' => $this->faculteBId]))
            ->assertOk()
            ->assertSee('MAT-FACA-1')
            ->assertDontSee('MAT-FACB-SECRET');
    }

    public function test_show_autre_faculte_403_sans_divulgation(): void
    {
        // Le refus ne doit rien révéler : ni nom, ni matricule, ni CIN.
        $this->get(route('admin.archives.show', $this->dossierBId))
            ->assertForbidden()
            ->assertDontSee('AUTREFACINVISIBLE')
            ->assertDontSee('MAT-FACB-SECRET');
    }

    public function test_statut_autre_faculte_refuse_et_inchange(): void
    {
        $this->patch(route('admin.archives.statut', $this->dossierBId), ['statut' => 'clos'])
            ->assertForbidden();

        $this->assertDatabaseHas('archives_etudiants', ['id' => $this->dossierBId, 'statut' => 'actif']);
    }

    public function test_destroy_interdit_meme_dans_sa_propre_faculte(): void
    {
        // archives.delete est réservé super_admin/admin_scolarite (globaux) :
        // un périmètre restreint ne supprime jamais, même chez lui.
        $this->delete(route('admin.archives.destroy', $this->dossierAId))->assertForbidden();

        $this->assertDatabaseHas('archives_etudiants', ['id' => $this->dossierAId]);
    }

    public function test_export_registre_isole_formule_et_cin_masque(): void
    {
        $csv = $this->get(route('admin.archives.export-registre'))->assertOk()->streamedContent();

        $this->assertStringContainsString('MAT-FACA-1', $csv);
        $this->assertStringNotContainsString('MAT-FACB-SECRET', $csv);
        // Le CIN est masqué dans l'export registre (jamais de CIN en clair).
        $this->assertStringNotContainsString($this->etudiantA->cin, $csv);

        // Le paramètre faculte_id étranger est ignoré : périmètre forcé.
        $csv2 = $this->get(route('admin.archives.export-registre', ['faculte_id' => $this->faculteBId]))
            ->assertOk()->streamedContent();
        $this->assertStringNotContainsString('MAT-FACB-SECRET', $csv2);
    }

    public function test_controle_positif_lecture_et_statut_propres_restent_possibles(): void
    {
        // Aucune garde ci-dessus ne doit être « trop large ».
        $this->get(route('admin.archives.show', $this->dossierAId))->assertOk();
        $this->patch(route('admin.archives.statut', $this->dossierAId), ['statut' => 'clos', 'motif' => 'Fin de parcours'])
            ->assertRedirect();

        $this->assertDatabaseHas('archives_etudiants', ['id' => $this->dossierAId, 'statut' => 'clos']);
    }
}
