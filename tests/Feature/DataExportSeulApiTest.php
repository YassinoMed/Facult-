<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Modules\Attendance\Models\Presence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsApiClient;
use Tests\TestCase;

/**
 * LOOP 3 (P1) — Export API des entités export-seules : `presences`,
 * `notes_epreuves`, `anomalies_qualite`, `factures`, `demandes` sont
 * exportables sur le web mais retournaient 404 via
 * `/api/v1/data/{entite}/export/{format}` (validation branchée par
 * erreur sur le registre d'import).
 */
class DataExportSeulApiTest extends TestCase
{
    use ActAsApiClient;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $faculte = Faculte::create([
            'nom' => 'Faculté des Sciences de Tunis',
            'code' => 'FST',
            'description' => 'Sciences',
            'active' => true,
        ]);

        $etudiant = Etudiant::create([
            'cin' => 'EXP40401',
            'num_inscription' => 'MAT-40401',
            'nom' => 'Ben Ammar',
            'prenom' => 'Sana',
            'email' => 'sana.export@universite.test',
            'date_naissance' => '2003-05-20',
            'sexe' => 'F',
            'telephone' => '97000401',
            'faculte_id' => $faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Rue Léon',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2022',
            'password' => 'Secret@123',
        ]);

        Presence::create([
            'etudiant_id' => $etudiant->id,
            'date' => '2026-10-01',
            'seance' => '08:00-10:00',
            'statut' => 'present',
        ]);
    }

    public function test_export_api_entite_export_seule_presences(): void
    {
        [, $token] = $this->clientApi(['data:exchange']);

        $reponse = $this->getApi('/api/v1/data/presences/export/csv', $token);

        $reponse->assertOk();
        $contenu = (string) $reponse->streamedContent();
        $this->assertStringContainsString('Ben Ammar', $contenu);
        $this->assertStringContainsString('2026-10-01', $contenu);
    }

    public function test_export_api_entite_importable_temoin(): void
    {
        [, $token] = $this->clientApi(['data:exchange']);

        $this->getApi('/api/v1/data/etudiants/export/csv', $token)->assertOk();
    }

    public function test_export_api_entite_inconnue_reste_404(): void
    {
        [, $token] = $this->clientApi(['data:exchange']);

        $this->getApi('/api/v1/data/entite-inexistante/export/csv', $token)->assertNotFound();
    }

    public function test_export_api_sans_scope_interdit(): void
    {
        [, $token] = $this->clientApi(['exports:create']);

        $this->getApi('/api/v1/data/presences/export/csv', $token)->assertForbidden();
    }

    public function test_export_api_invite_non_authentifie(): void
    {
        $this->get('/api/v1/data/presences/export/csv')->assertUnauthorized();
    }

    public function test_export_api_factures_sans_finances_view_refuse(): void
    {
        [$client, $token] = $this->clientApi(['data:exchange']);
        $client->definirRole('agent_scolarite');

        $this->assertFalse($client->hasPermission('finances.view'));

        $this->getApi('/api/v1/data/factures/export/csv', $token)->assertForbidden();
    }

    public function test_export_api_factures_avec_finances_view_autorise(): void
    {
        [$client, $token] = $this->clientApi(['data:exchange']);

        $this->assertTrue($client->hasPermission('finances.view'));

        $this->getApi('/api/v1/data/factures/export/csv', $token)->assertOk();
    }

    public function test_export_api_format_non_pris_en_charge_reste_404(): void
    {
        [, $token] = $this->clientApi(['data:exchange']);

        $this->getApi('/api/v1/data/presences/export/ods', $token)->assertNotFound();
    }
}
