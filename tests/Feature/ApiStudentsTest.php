<?php

namespace Tests\Feature;

use App\Models\DocumentAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsApiClient;
use Tests\TestCase;

/**
 * API v1 — étudiants (§44-45, §58-59) : resources contrôlées (aucune
 * exposition de mot de passe ou de champs internes), filtres en liste
 * blanche, pagination, 404 propres.
 */
class ApiStudentsTest extends TestCase
{
    use ActAsApiClient, RefreshDatabase;

    private Etudiant $etudiant;

    protected function setUp(): void
    {
        parent::setUp();

        $faculte = Faculte::create(['nom' => 'Faculté des Sciences', 'code' => 'FST', 'description' => 'Test', 'active' => true]);
        $faculteBis = Faculte::create(['nom' => 'Faculté de Droit', 'code' => 'FDEG', 'description' => 'Test', 'active' => true]);

        $this->etudiant = Etudiant::create(['cin' => 'APISTU001',
            'nom' => 'Ndiaye', 'prenom' => 'Awa', 'email' => 'awa.ndiaye@example.com',
            'telephone' => '+2210000000', 'sexe' => 'F', 'faculte_id' => $faculte->id,
            'niveau' => 'L2', 'annee_academique' => '2025-2026',
            'adresse' => 'Rue X', 'ville' => 'Dakar', 'code_postal' => '10000', 'annee_bac' => '2024', ]);
        $this->etudiant->forceFill(['num_inscription' => 'INS-API-00001'])->save();

        $etudiantCree = Etudiant::create(['cin' => 'APISTU002',
            'nom' => 'Fall', 'prenom' => 'Moussa', 'email' => 'moussa.fall@example.com',
            'sexe' => 'M', 'faculte_id' => $faculteBis->id,
            'niveau' => 'L1', 'annee_academique' => '2025-2026',
            'adresse' => 'Rue Y', 'ville' => 'Thies', 'code_postal' => '20000', 'annee_bac' => '2025', ]);
        $etudiantCree->forceFill(['num_inscription' => 'INS-API-00002'])->save();
    }

    public function test_liste_renvoie_une_resource_controlee(): void
    {
        [, $token] = $this->clientApi(['students:read']);

        $reponse = $this->getApi('/api/v1/students', $token)->assertOk();

        // Champs autorisés — JAMAIS password, hash ni champs internes.
        // CIN complète = scope archives:read uniquement (§15) : ce token
        // students:read seul reçoit cin_masquee, jamais cin en clair.
        $etudiantJson = $reponse->json('data.0');
        $this->assertArrayHasKey('cin_masquee', $etudiantJson);
        $this->assertArrayNotHasKey('cin', $etudiantJson);
        $this->assertArrayHasKey('faculte', $etudiantJson);
        $this->assertArrayNotHasKey('password', $etudiantJson);

        // Aucun secret dans le corps complet de la réponse
        $this->assertStringNotContainsStringIgnoringCase('password', (string) json_encode($reponse->json('data')));
    }

    public function test_filtres_en_liste_blanche(): void
    {
        [, $token] = $this->clientApi(['students:read']);

        // Filtre autorisé : faculty
        $reponse = $this->getApi('/api/v1/students?faculty=FST', $token)->assertOk();
        $this->assertSame('Ndiaye', $reponse->json('data.0.nom'));
        $this->assertCount(1, $reponse->json('data'));

        // Filtre autorisé : status
        $this->getApi('/api/v1/students?status=valide', $token)->assertOk();

        // Paramètre arbitraire (sort=password) : ignoré, jamais une colonne SQL (§59)
        $this->getApi('/api/v1/students?sort=password', $token)->assertOk();
    }

    public function test_detail_etudiant_existe_et_404_si_inconnu(): void
    {
        [, $token] = $this->clientApi(['students:read']);

        $this->getApi('/api/v1/students/'.$this->etudiant->id, $token)
            ->assertOk()
            ->assertJsonPath('data.cin_masquee', 'AP***001')
            ->assertJsonMissingPath('data.cin');

        // ID inexistant : 404 propre, jamais 500
        $this->getApi('/api/v1/students/999999', $token)->assertStatus(404);
    }

    public function test_cin_complete_reservee_au_scope_archives_read(): void
    {
        [, $token] = $this->clientApi(['students:read', 'archives:read']);

        $this->getApi('/api/v1/students/'.$this->etudiant->id, $token)
            ->assertOk()
            ->assertJsonPath('data.cin', 'APISTU001');
    }

    public function test_documents_ne_revelent_jamais_le_chemin_interne(): void
    {
        [, $token] = $this->clientApi(['documents:read']);

        DocumentAcademique::create([
            'etudiant_id' => $this->etudiant->id,
            'type_document' => 'attestation',
            'nom_original' => 'attestation.pdf',
            'chemin' => 'archives/etudiant_1/courant/documents/interne-uuid.pdf',
            'mime_type' => 'application/pdf', 'taille' => 1024,
            'hash_fichier' => str_repeat('a', 64),
        ]);

        $reponse = $this->getApi('/api/v1/students/'.$this->etudiant->id.'/documents', $token)->assertOk();

        $corps = (string) json_encode($reponse->json());
        $this->assertStringNotContainsString('chemin', $corps); // champ masqué
        $this->assertStringNotContainsString('interne-uuid', $corps);
        $this->assertStringContainsString('attestation.pdf', $corps);
    }

    public function test_ids_invalides_rejetes_par_pattern_numerique(): void
    {
        [, $token] = $this->clientApi(['students:read']);

        // {student} contraint par whereNumber : injection textuelle → 404
        $this->getApi('/api/v1/students/abc', $token)->assertStatus(404);
        $this->getApi('/api/v1/students/1;DROP%20TABLE', $token)->assertStatus(404);
    }
}
