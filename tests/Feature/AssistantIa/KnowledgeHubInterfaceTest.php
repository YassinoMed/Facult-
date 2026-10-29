<?php

namespace Tests\Feature\AssistantIa;

use App\Models\User;
use App\Modules\AssistantIa\Models\AiDocumentIndex;
use App\Modules\AssistantIa\Models\AiSourceConnaissance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeHubInterfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_utilisateur_non_authentifie_ne_peut_pas_acceder_aux_sources(): void
    {
        $response = $this->get(route('admin.assistant-ia.sources.index'));
        $response->assertRedirect('/admin/connexion');
    }

    public function test_utilisateur_sans_permission_admin_est_interdit_dacces_aux_sources(): void
    {
        $user = User::factory()->create();
        $user->definirRole('consultation'); // Possède assistant_ia.view mais pas assistant_ia.admin

        $response = $this->actingAs($user)->get(route('admin.assistant-ia.sources.index'));
        $response->assertForbidden();
    }

    public function test_administrateur_peut_consulter_le_tableau_de_bord_des_sources(): void
    {
        $admin = User::factory()->create();
        $admin->role = 'super_admin';
        $admin->save();

        AiSourceConnaissance::create([
            'nom' => 'Code App UniRegistre',
            'type' => 'project_code',
            'origine' => 'app/',
            'statut' => 'actif',
            'politique_actualisation' => 'manuelle',
        ]);

        AiDocumentIndex::create([
            'source' => 'app/Services/Inscription.php',
            'chemin_fichier' => 'app/Services/Inscription.php',
            'titre' => 'Service Inscription',
            'index_morceau' => 0,
            'contenu_morceau' => 'Contenu test',
            'empreinte_sha256' => hash('sha256', 'Contenu test'),
            'statut_document' => 'valide',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.assistant-ia.sources.index'));

        $response->assertOk();
        $response->assertViewIs('modules.assistant-ia.sources');
        $response->assertSee('Knowledge Hub UniRegistre');
        $response->assertSee('Code App UniRegistre');
        $response->assertSee('Administration des Sources');
    }

    public function test_administrateur_peut_basculer_le_statut_dune_source(): void
    {
        $admin = User::factory()->create();
        $admin->role = 'super_admin';
        $admin->save();

        $source = AiSourceConnaissance::create([
            'nom' => 'Doc Interne',
            'type' => 'internal_document',
            'origine' => 'docs/',
            'statut' => 'actif',
            'politique_actualisation' => 'manuelle',
        ]);

        $response = $this->actingAs($admin)->post(route('admin.assistant-ia.sources.basculer', $source->id));
        $response->assertRedirect();

        $source->refresh();
        $this->assertEquals('suspendu', $source->statut);

        // Réactivation
        $response2 = $this->actingAs($admin)->post(route('admin.assistant-ia.sources.basculer', $source->id));
        $response2->assertRedirect();

        $source->refresh();
        $this->assertEquals('actif', $source->statut);
    }

    public function test_administrateur_peut_declencher_une_synchronisation(): void
    {
        $admin = User::factory()->create();
        $admin->role = 'super_admin';
        $admin->save();

        $source = AiSourceConnaissance::create([
            'nom' => 'Routes Assistant IA',
            'type' => 'project_code',
            'origine' => 'app/Modules/AssistantIa/Routes',
            'statut' => 'actif',
            'politique_actualisation' => 'manuelle',
        ]);

        $response = $this->actingAs($admin)->post(route('admin.assistant-ia.sources.synchroniser', $source->id));
        $response->assertRedirect();

        $source->refresh();
        $this->assertNotNull($source->derniere_synchronisation_a);
    }
}
