<?php

namespace Tests\Feature\Livewire\Admin;

use App\Livewire\Admin\Campagnes\AssistantCompositionCampagne;
use App\Livewire\Admin\Campagnes\GestionGroupesDestinataires;
use App\Livewire\Admin\Campagnes\SuiviCampagneCourriel;
use App\Livewire\Admin\Campagnes\TableauDeBordCampagnes;
use App\Models\CampagneCourriel;
use App\Models\CampagneCourrielDestinataire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\GroupeDestinataires;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CampagnesLivewireTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['code' => 'FS', 'nom' => 'Faculté des Sciences', 'actif' => true]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Test',
            'email' => 'admin.test@univ.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->actingAs($this->admin);
    }

    private function creerEtudiant(array $attributs = []): Etudiant
    {
        static $compteur = 3000;
        $compteur++;

        return Etudiant::create(array_merge([
            'cin' => str_pad((string) $compteur, 8, '0', STR_PAD_LEFT),
            'nom' => 'Nom'.$compteur,
            'prenom' => 'Prenom'.$compteur,
            'email' => "etudiant{$compteur}@univ.test",
            'sexe' => 'M',
            'date_naissance' => '2002-01-01',
            'telephone' => '20000000',
            'statut' => 'valide',
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'faculte_id' => $this->faculte->id,
            'adresse' => '1 rue Test',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2022',
        ], $attributs));
    }

    public function test_tableau_de_bord_rendu_et_actions(): void
    {
        $campagne = CampagneCourriel::create([
            'titre' => 'Campagne Brouillon',
            'sujet' => 'Objet Test',
            'type_ciblage' => CampagneCourriel::CIBLAGE_INDIVIDUEL,
            'statut' => CampagneCourriel::STATUT_BROUILLON,
            'cle_idempotence' => CampagneCourriel::genererCleIdempotence(),
            'auteur_id' => $this->admin->id,
        ]);

        Livewire::test(TableauDeBordCampagnes::class)
            ->assertOk()
            ->assertSee('Campagne Brouillon')
            ->assertSee('Objet Test')
            ->call('supprimerBrouillon', $campagne->id)
            ->assertSet('messageSucces', 'Le brouillon a été supprimé.');

        $this->assertDatabaseMissing('campagnes_courriel', ['id' => $campagne->id]);
    }

    public function test_assistant_composition_flux_etapes(): void
    {
        $this->creerEtudiant(['faculte_id' => $this->faculte->id, 'niveau' => 'L1']);

        Livewire::test(AssistantCompositionCampagne::class)
            ->assertOk()
            ->assertSet('etape', 1)
            ->set('typeCiblage', CampagneCourriel::CIBLAGE_DYNAMIQUE_ETUDIANTS)
            ->call('changerEtape', 2)
            ->assertSet('etape', 2)
            ->set('filtreFaculteId', $this->faculte->id)
            ->set('filtreNiveau', 'L1')
            ->call('changerEtape', 3)
            ->assertSet('etape', 3)
            ->set('titre', 'Campagne de test Livewire')
            ->set('sujet', 'Objet de test Livewire')
            ->set('contenuTexte', 'Ceci est le corps du message test.')
            ->call('changerEtape', 4)
            ->assertSet('etape', 4)
            ->set('confirmationExplicite', true)
            ->call('changerEtape', 5)
            ->assertSet('etape', 5);
    }

    public function test_suivi_campagne_affichage_et_filtrage(): void
    {
        $campagne = CampagneCourriel::create([
            'titre' => 'Campagne pour suivi',
            'sujet' => 'Objet',
            'type_ciblage' => CampagneCourriel::CIBLAGE_LISTE_FIXE,
            'statut' => CampagneCourriel::STATUT_TERMINEE,
            'cle_idempotence' => CampagneCourriel::genererCleIdempotence(),
            'total_destinataires' => 1,
            'total_envoyes' => 1,
            'auteur_id' => $this->admin->id,
        ]);

        $dest = $campagne->destinataires()->create([
            'type_destinataire' => CampagneCourrielDestinataire::TYPE_ETUDIANT,
            'nom_complet' => 'Destinataire Test',
            'email' => 'dest@univ.test',
            'email_hash' => CampagneCourrielDestinataire::hacherEmail('dest@univ.test'),
            'statut' => CampagneCourrielDestinataire::STATUT_ENVOYE,
            'envoye_a' => now(),
        ]);

        Livewire::test(SuiviCampagneCourriel::class, ['campagne' => $campagne])
            ->assertOk()
            ->assertSee('Campagne pour suivi')
            ->assertSee('Destinataire Test')
            ->assertSee($dest->emailMasque());
    }

    public function test_gestion_groupes_creation_duplication_archivage(): void
    {
        Livewire::test(GestionGroupesDestinataires::class)
            ->assertOk()
            ->call('ouvrirCreation')
            ->assertSet('modalOuverte', true)
            ->set('nom', 'Nouveau Groupe Sciences')
            ->set('type', GroupeDestinataires::TYPE_DYNAMIQUE_ETUDIANTS)
            ->set('filtreFaculteId', $this->faculte->id)
            ->call('sauvegarder')
            ->assertSet('modalOuverte', false);

        $this->assertDatabaseHas('groupes_destinataires', [
            'nom' => 'Nouveau Groupe Sciences',
            'est_archive' => false,
        ]);

        $groupe = GroupeDestinataires::where('nom', 'Nouveau Groupe Sciences')->firstOrFail();

        Livewire::test(GestionGroupesDestinataires::class)
            ->call('dupliquerGroupe', $groupe->id);

        $this->assertDatabaseHas('groupes_destinataires', [
            'nom' => 'Nouveau Groupe Sciences (Copie)',
        ]);

        Livewire::test(GestionGroupesDestinataires::class)
            ->call('archiverGroupe', $groupe->id);

        $this->assertTrue($groupe->fresh()->est_archive);
    }
}
