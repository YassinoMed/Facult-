<?php

namespace Tests\Feature;

use App\Models\ImportReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit §P3-19 : la prévisualisation d'un rapport d'import est réservée
 * à SON propriétaire (comme `erreurs()`) — 404 silencieux sinon.
 */
class IntegrationPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function agent(): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Agent Import',
            'email' => uniqid('agent-import-', true).'@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    private function rapportProprietaire(User $proprietaire): ImportReport
    {
        return ImportReport::create([
            'user_id' => $proprietaire->id,
            'integration' => 'web',
            'direction' => 'import',
            'type' => 'students',
            'format' => 'csv',
            'filename' => 'import.csv',
            'status' => 'pending',
        ]);
    }

    public function test_le_proprietaire_previsualise_son_rapport(): void
    {
        $agent = $this->agent();
        $rapport = $this->rapportProprietaire($agent);

        $this->actingAs($agent)
            ->get(route('admin.integrations.preview', $rapport))
            ->assertOk();
    }

    public function test_un_autre_agent_ne_previsualise_pas_le_rapport_d_autrui(): void
    {
        $proprietaire = $this->agent();
        $rapport = $this->rapportProprietaire($proprietaire);

        $this->actingAs($this->agent())
            ->get(route('admin.integrations.preview', $rapport))
            ->assertNotFound();
    }

    public function test_un_autre_agent_ne_confirme_pas_le_rapport_d_autrui(): void
    {
        $proprietaire = $this->agent();
        $rapport = $this->rapportProprietaire($proprietaire);

        $this->actingAs($this->agent())
            ->post(route('admin.integrations.confirm', $rapport), ['mode' => 'atomique'])
            ->assertNotFound();

        $this->assertSame('pending', $rapport->fresh()->status);
    }
}
