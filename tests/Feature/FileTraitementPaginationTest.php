<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Services\PreferencesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B — Pagination configurable de la file (préférence lignes_par_page).
 */
class FileTraitementPaginationTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private User $scolarite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.pag@test.tn', 'password' => 'Secret123!',
        ], 'admin_scolarite');
    }

    private function dossier(string $nom): Etudiant
    {
        static $n = 1;

        return Etudiant::create([
            'nom' => $nom, 'prenom' => 'P', 'email' => "p{$n}@test.tn",
            'cin' => sprintf('720000%02d', $n++), 'sexe' => 'M', 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'adr', 'ville' => 'Tunis',
            'code_postal' => '1000', 'annee_bac' => '2025', 'faculte_id' => $this->faculte->id,
        ]);
    }

    public function test_pagination_suit_preference_valide(): void
    {
        app(PreferencesService::class)->ecrire((int) $this->scolarite->id, ['lignes_par_page' => 5]);

        for ($i = 1; $i <= 7; $i++) {
            $this->dossier('DossierP'.$i);
        }

        $reponse = $this->actingAs($this->scolarite)->get(route('admin.inscriptions.file-traitement'));
        $reponse->assertOk();

        $page = $reponse->viewData('etudiants');
        $this->assertSame(5, $page->perPage());
        $this->assertSame(7, $page->total());
        $this->assertCount(5, $page->items());
    }

    public function test_pagination_defaut_sans_preference(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->dossier('DossierD'.$i);
        }

        $page = $this->actingAs($this->scolarite)
            ->get(route('admin.inscriptions.file-traitement'))
            ->viewData('etudiants');

        $this->assertSame(12, $page->perPage());
    }

    public function test_preference_invalide_rejetee_au_clamp(): void
    {
        // Valeurs hors bornes : le service borne à [5,100], jamais d'exception ni de page absurde.
        app(PreferencesService::class)->ecrire((int) $this->scolarite->id, ['lignes_par_page' => 5000]);
        $this->dossier('DossierX');

        $page = $this->actingAs($this->scolarite)
            ->get(route('admin.inscriptions.file-traitement'))
            ->viewData('etudiants');

        $this->assertSame(100, $page->perPage());
    }

    public function test_filtres_conserves_entre_pages_et_parcours_sans_perte(): void
    {
        app(PreferencesService::class)->ecrire((int) $this->scolarite->id, ['lignes_par_page' => 5]);

        for ($i = 1; $i <= 8; $i++) {
            $this->dossier('DossierF'.$i);
        }

        $params = ['statut' => 'en_attente', 'sens' => 'asc'];
        $page1 = $this->actingAs($this->scolarite)
            ->get(route('admin.inscriptions.file-traitement', $params))
            ->viewData('etudiants');
        $page2 = $this->actingAs($this->scolarite)
            ->get(route('admin.inscriptions.file-traitement', $params + ['page' => 2]))
            ->viewData('etudiants');

        // Pas de doublon ni perte sur jeu stable : 5 + 3 = 8.
        $ids1 = array_map(fn ($e) => $e->id, $page1->items());
        $ids2 = array_map(fn ($e) => $e->id, $page2->items());
        $this->assertCount(5, $ids1);
        $this->assertCount(3, $ids2);
        $this->assertSame([], array_intersect($ids1, $ids2));

        // Liens de pagination conservent les filtres.
        $this->assertStringContainsString('statut=en_attente', $page1->nextPageUrl());
    }
}
