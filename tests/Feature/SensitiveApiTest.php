<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\StudentArchive\Models\StudentSensitiveRecord;
use App\Support\ApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsApiClient;
use Tests\TestCase;

/**
 * API sensible (§32, §34) : RIEN par défaut — ni endpoints dédiés ni
 * scopes médicaux, métadonnées documents limitées au cœur.
 */
class SensitiveApiTest extends TestCase
{
    use ActAsApiClient;
    use RefreshDatabase;

    private Etudiant $etudiant;

    protected function setUp(): void
    {
        parent::setUp();

        $faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.sapi@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->etudiant = Etudiant::create([
            'cin' => 'SAPI00001', 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'sapi@sapi.test', 'date_naissance' => '2004-01-01', 'sexe' => 'F',
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
            'titre' => 'ApiSecretGHI789', 'statut' => 'ouvert',
            'created_by' => $admin->id,
        ]);
    }

    public function test_documents_api_sans_sensible(): void
    {
        [, $token] = $this->clientApi(['documents:read']);

        $reponse = $this->getApi('/api/v1/students/'.$this->etudiant->id.'/documents', $token);

        $reponse->assertOk();
        $this->assertStringNotContainsString('ApiSecretGHI789', $reponse->getContent());
    }

    public function test_scopes_medicaux_inexistants(): void
    {
        $this->assertArrayNotHasKey('medical:read', ApiScopes::SCOPES);
        $this->assertArrayNotHasKey('sensitive:read', ApiScopes::SCOPES);
        $this->assertArrayNotHasKey('disciplinary:read', ApiScopes::SCOPES);
    }

    public function test_archive_api_absente(): void
    {
        [, $token] = $this->clientApi(['archives:read']);

        $this->getApi('/api/v1/students/'.$this->etudiant->id.'/archive', $token)
            ->assertNotFound();
    }
}
