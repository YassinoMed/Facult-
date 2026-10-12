<?php

namespace Tests\Feature\Security;

use App\Models\AnneeUniversitaire;
use App\Models\DossierAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * LOOP 11 — Durcissement : matrice interfacultaire (statut, validation,
 * pédagogique), silence d'audit sur refus, budget requêtes du registre.
 */
class ArchivesDurcissementLoop11Test extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteA = Faculte::create(['nom' => 'FA', 'code' => 'FA', 'description' => 'T', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'FB', 'code' => 'FB', 'description' => 'T', 'active' => true]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30', 'active' => true,
        ]);
    }

    private function adminFaculteA(): void
    {
        $user = User::creerCompteBackOffice([
            'name' => 'Admin A', 'email' => uniqid('aa11.', true).'@scolarite.test',
            'password' => 'Motdepasse@123', 'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');
        $this->actingAs($user);
    }

    private function etudiantDe(Faculte $faculte): Etudiant
    {
        return Etudiant::create([
            'cin' => 'BK'.random_int(100000, 999999),
            'nom' => 'Durc', 'prenom' => 'Test',
            'email' => uniqid('d11.', true).'@example.com',
            'sexe' => 'M', 'faculte_id' => $faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2025-2026',
            'adresse' => '1 rue', 'ville' => 'Rabat',
            'code_postal' => '10000', 'annee_bac' => '2024',
            'password' => 'motdepasse1',
        ]);
    }

    public function test_statut_valider_rejeter_pedagogique_autre_faculte_refuses(): void
    {
        $etudiantB = $this->etudiantDe($this->faculteB);
        $dossierB = DossierAcademique::create([
            'etudiant_id' => $etudiantB->id,
            'matricule' => 'MAT-DURC-1', 'date_archivage' => now()->toDateString(),
        ]);
        $inscriptionB = InscriptionAcademique::create([
            'etudiant_id' => $etudiantB->id, 'niveau' => 'L1',
            'annee_universitaire_id' => $this->annee->id, 'statut' => 'en_cours',
        ]);
        $this->adminFaculteA();

        $this->patch(route('admin.archives.statut', $dossierB), ['statut' => 'clos'])
            ->assertForbidden();
        $this->post(route('admin.inscriptions.valider', $inscriptionB))->assertForbidden();
        $this->post(route('admin.inscriptions.rejeter', $inscriptionB), ['motif' => 'Test de refus.'])
            ->assertForbidden();
        $this->get(route('admin.inscriptions.pedagogique', $inscriptionB))->assertForbidden();

        $this->assertSame('actif', $dossierB->fresh()->statut);
        $this->assertSame('en_cours', $inscriptionB->fresh()->statut);
    }

    public function test_refus_sans_ecriture_ni_divulgation(): void
    {
        $etudiantB = $this->etudiantDe($this->faculteB);
        $dossierB = DossierAcademique::create([
            'etudiant_id' => $etudiantB->id,
            'matricule' => 'MAT-DURC-2', 'date_archivage' => now()->toDateString(),
        ]);
        $this->adminFaculteA();

        $reponse = $this->get(route('admin.archives.show', $dossierB))->assertForbidden();
        $this->assertStringNotContainsString($etudiantB->cin, $reponse->getContent());
        $this->assertSame(0, HistoriqueAcademique::where('etudiant_id', $etudiantB->id)->count());
    }

    public function test_registre_borne_en_requetes(): void
    {
        $this->scolarite('super_admin');
        $etudiant = $this->etudiantAvecDossier();
        DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-DURC-3', 'date_archivage' => now()->toDateString(),
        ]);

        $compteur = 0;
        DB::listen(function () use (&$compteur): void {
            $compteur++;
        });

        $this->get(route('admin.archives.index'))->assertOk();
        // Mesuré : 11 requêtes (stats + page + eager + listes) ; borne
        // de garde avec marge pour évolutions mineures.
        $this->assertLessThan(20, $compteur, "Registre : {$compteur} requêtes.");
    }

    public function test_suppression_concurrente_seconde_requete_404(): void
    {
        $this->scolarite('super_admin');
        $etudiant = $this->etudiantAvecDossier();
        $dossier = DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-DURC-4', 'date_archivage' => now()->toDateString(),
        ]);

        $this->delete(route('admin.archives.destroy', $dossier), ['motif' => 'Suppression de test.'])
            ->assertRedirect(route('admin.archives.index'));
        // Rejeu séquentiel : la garde transactionnelle et le binding
        // convergent vers 404, sans seconde écriture d'audit.
        $this->delete('/admin/archives/'.$dossier->id, ['motif' => 'Suppression de test.'])
            ->assertNotFound();
        $this->assertSame(1, HistoriqueAcademique::where(
            'action', HistoriqueAcademique::ACTION_SUPPRESSION_DOSSIER
        )->where('entite_id', $dossier->id)->count());
    }
}
