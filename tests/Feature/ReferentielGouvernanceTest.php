<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\Programme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Gouvernance des maquettes (LOOP 12) : refus tracés sans PII,
 * transitions répétées impossibles, approbation réservée
 * (`maquettes.approve`), workflow complet prouvé sur fixture —
 * jamais sur données réelles.
 */
class ReferentielGouvernanceTest extends TestCase
{
    use RefreshDatabase;

    private User $scolarite;

    private User $adminFaculte;

    private Maquette $maquette;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.referentiel.active' => true]);

        $faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.gouv@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->adminFaculte = User::creerCompteBackOffice([
            'name' => 'Admin Fac', 'email' => 'fac.gouv@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $faculte->id,
        ], 'admin_faculte');

        $programme = Programme::create([
            'faculte_id' => $faculte->id, 'code' => 'GOUV', 'nom' => 'Programme gouvernance',
            'statut' => 'actif', 'actif' => true,
        ]);
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->maquette = Maquette::create([
            'programme_id' => $programme->id, 'annee_universitaire_id' => $annee->id,
            'version' => 1, 'statut' => 'draft',
        ]);
    }

    public function test_refus_transition_trace_sans_pii(): void
    {
        $captures = [];
        Log::listen(function ($evenement) use (&$captures) {
            $captures[] = [
                'niveau' => $evenement->level,
                'message' => $evenement->message,
                'contexte' => $evenement->context,
            ];
        });

        $this->actingAs($this->scolarite)->patch(
            route('admin.referentiel.maquettes.transition', $this->maquette), ['statut' => 'active']
        )->assertRedirect()->assertSessionHasErrors('statut');

        $refus = array_values(array_filter(
            $captures, fn ($c) => ($c['message'] ?? '') === 'referentiel.maquette.transition_refusee'
        ));

        $this->assertCount(1, $refus);
        $this->assertSame($this->maquette->id, $refus[0]['contexte']['maquette_id']);
        $this->assertSame('active', $refus[0]['contexte']['cible']);
        $this->assertSame('draft', $this->maquette->fresh()->statut);

        // Aucune PII dans le contexte (ids techniques uniquement).
        foreach ($refus[0]['contexte'] as $valeur) {
            $this->assertFalse(
                str_contains((string) $valeur, '@') || str_contains((string) $valeur, 'GOUV'),
                'PII dans le journal de refus.'
            );
        }
    }

    public function test_transition_repetee_impossible(): void
    {
        $route = route('admin.referentiel.maquettes.transition', $this->maquette);

        $this->actingAs($this->scolarite)->patch($route, ['statut' => 'review'])->assertRedirect();
        $this->assertSame('review', $this->maquette->fresh()->statut);

        // Répétition vers le même état : transition absente de TRANSITIONS.
        $this->actingAs($this->scolarite)->patch($route, ['statut' => 'review'])
            ->assertRedirect()->assertSessionHasErrors('statut');
        $this->assertSame('review', $this->maquette->fresh()->statut);
    }

    public function test_approbation_reservee_approve(): void
    {
        $this->maquette->update(['statut' => 'review']);
        $route = route('admin.referentiel.maquettes.transition', $this->maquette);

        // admin_faculte : referentiel.view seul → 403, état intact.
        $this->actingAs($this->adminFaculte)->patch($route, ['statut' => 'approved'])
            ->assertForbidden();
        $this->assertSame('review', $this->maquette->fresh()->statut);

        $this->actingAs($this->adminFaculte)->patch($route, ['statut' => 'active'])
            ->assertForbidden();
    }

    public function test_workflow_complet_autorise(): void
    {
        $route = route('admin.referentiel.maquettes.transition', $this->maquette);

        foreach (['review', 'approved', 'active'] as $cible) {
            $this->actingAs($this->scolarite)->patch($route, ['statut' => $cible])->assertRedirect();
            $this->assertSame($cible, $this->maquette->fresh()->statut);
        }

        // Terminal : archived sans retour.
        $this->actingAs($this->scolarite)->patch($route, ['statut' => 'archived'])->assertRedirect();
        $this->actingAs($this->scolarite)->patch($route, ['statut' => 'draft'])
            ->assertRedirect()->assertSessionHasErrors('statut');
    }
}
