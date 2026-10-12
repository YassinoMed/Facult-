<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase D — UX : anti-double-submit, modale rouverte sur erreur avec
 * saisie conservée, compteur de filtres actifs, seuils expliqués.
 */
class FileTraitementUxTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private User $scolarite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.ux@test.tn', 'password' => 'Secret123!',
        ], 'admin_scolarite');
    }

    private function dossier(): Etudiant
    {
        static $n = 1;

        return Etudiant::create([
            'nom' => 'NomU'.$n, 'prenom' => 'P', 'email' => "u{$n}@test.tn",
            'cin' => sprintf('740000%02d', $n++), 'sexe' => 'M', 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'adr', 'ville' => 'Tunis',
            'code_postal' => '1000', 'annee_bac' => '2025', 'faculte_id' => $this->faculte->id,
        ]);
    }

    public function test_erreur_validation_conserve_saisie_et_rouvre_la_modale(): void
    {
        $etudiant = $this->dossier();

        $reponse = $this->actingAs($this->scolarite)
            ->post(route('admin.inscriptions.file-traitement.commenter', $etudiant), [
                'commentaire' => 'ab',
            ]);

        $reponse->assertSessionHasErrors('commentaire');
        $reponse->assertSessionHas('note_etudiant_id', $etudiant->id);

        // Retour page : la modale ciblée est marquée à rouvrir, saisie conservée.
        $page = $this->actingAs($this->scolarite)->get(route('admin.inscriptions.file-traitement'));
        $page->assertOk();
        $page->assertSee('data-reouverture="'.$etudiant->id.'"', false);
        $page->assertSee('>ab</textarea>', false);
    }

    public function test_bouton_note_protege_contre_double_soumission(): void
    {
        $page = $this->actingAs($this->scolarite)->get(route('admin.inscriptions.file-traitement'));
        $page->assertOk();

        $page->assertSee('envoiNote', false);
        $page->assertSee(':disabled="envoiNote"', false);
    }

    public function test_compteur_filtres_actifs(): void
    {
        $sansFiltre = $this->actingAs($this->scolarite)->get(route('admin.inscriptions.file-traitement'));
        $this->assertSame(0, $sansFiltre->viewData('filtresActifs'));

        $avecFiltres = $this->actingAs($this->scolarite)->get(route('admin.inscriptions.file-traitement', [
            'statut' => 'en_attente', 'depose_depuis' => 2,
        ]));
        $this->assertSame(2, $avecFiltres->viewData('filtresActifs'));
        $avecFiltres->assertSee('2 filtres actifs', false);
    }

    public function test_seuils_expliques_en_infobulle(): void
    {
        $page = $this->actingAs($this->scolarite)->get(route('admin.inscriptions.file-traitement'));
        $page->assertOk();

        $page->assertSee('plus de 48 heures', false);
        $page->assertSee('plus de 7 jours', false);
    }
}
