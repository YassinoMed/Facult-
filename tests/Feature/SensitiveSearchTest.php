<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\StudentArchive\Models\StudentSensitiveRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Recherche sensible (§20, §34) : AUCUNE indexation — un titre sensible
 * ne remonte ni en globale ni en instantanée.
 */
class SensitiveSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Etudiant $etudiant;

    protected function setUp(): void
    {
        parent::setUp();

        $faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.sse@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->etudiant = Etudiant::create([
            'cin' => 'SSE00001', 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'sse@sse.test', 'date_naissance' => '2004-01-01', 'sexe' => 'F',
            'faculte_id' => $faculte->id, 'niveau' => 'L1', 'annee_academique' => '2026-2027',
            'adresse' => 'X', 'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');
        $this->etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
        ]);

        StudentSensitiveRecord::create([
            'etudiant_id' => $this->etudiant->id, 'type' => 'medical',
            'titre' => 'RechercheIntrouvableABC999', 'statut' => 'ouvert',
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_globale_ignore_sensible(): void
    {
        // Zéro ligne de résultat : le message « Aucun résultat » prouve
        // qu'aucun dossier sensible n'est indexé (les occurrences restantes
        // sont le simple ré-affichage fonctionnel de la requête saisie).
        $this->actingAs($this->admin)->get(route('admin.recherche', [
            'q' => 'RechercheIntrouvableABC999',
        ]))->assertOk()->assertSee('Aucun résultat');
    }

    public function test_instantanee_ignore_sensible(): void
    {
        $json = $this->actingAs($this->admin)->get(route('admin.recherche.instantanee', [
            'q' => 'RechercheIntrouvableABC999',
        ]))->assertOk()->getContent();

        $this->assertStringNotContainsString('RechercheIntrouvableABC999', $json);
    }
}
