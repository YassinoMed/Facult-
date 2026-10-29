<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Modules\ParcoursAcademique\Models\ParcoursSimulation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADVANCED-01 LOT B — simulations self-service du portail étudiant.
 *
 * Exigences prouvées :
 * - isolation stricte (session uniquement, jamais d'identifiant client) ;
 * - persistance append-only (`auteur_id` null) SANS aucune écriture
 *   officielle (notes, inscriptions, délibérations inchangées) ;
 * - état d'indisponibilité EXPLICITE sans inscription académique ;
 * - module désactivé = 404, invité = redirection connexion.
 */
class MaSimulationParcoursTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculte;

    protected AnneeUniversitaire $annee;

    protected Etudiant $etudiantA;

    protected Etudiant $etudiantB;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.parcours-academique.active' => true]);

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences', 'code' => 'FST',
            'description' => 'Sciences', 'active' => true,
        ]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $this->etudiantA = $this->creerEtudiant('BK980001');
        $this->etudiantB = $this->creerEtudiant('BK980002');

        // Seul A possède une inscription académique : B servira au cas
        // d'indisponibilité explicite.
        InscriptionAcademique::create([
            'etudiant_id' => $this->etudiantA->id,
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1', 'statut' => 'en_cours',
            'type' => 'premiere', 'regime' => 'initiale',
        ]);
    }

    public function test_invite_est_redirige_vers_connexion(): void
    {
        $this->get(route('etudiant.parcours.simulations.index'))
            ->assertRedirect(route('etudiant.connexion.show'));
    }

    public function test_module_desactive_repond_404(): void
    {
        config(['modules.parcours-academique.active' => false]);
        $this->session(['etudiant_auth_id' => $this->etudiantA->id]);

        $this->get(route('etudiant.parcours.simulations.index'))->assertNotFound();
    }

    public function test_store_persiste_sans_aucune_ecriture_officielle(): void
    {
        $this->session(['etudiant_auth_id' => $this->etudiantA->id]);
        $notesAvant = DB::table('notes')->count();
        $inscriptionAvant = InscriptionAcademique::where('etudiant_id', $this->etudiantA->id)->first()->toArray();

        $reponse = $this->post(route('etudiant.parcours.simulations.store'), [
            'scenario' => 'passage',
            'hypotheses' => ['note' => '12.5', 'semestre' => '1'],
        ]);

        $reponse->assertRedirect();

        $simulation = ParcoursSimulation::where('etudiant_id', $this->etudiantA->id)->first();
        $this->assertNotNull($simulation, 'La simulation doit être persistée (append-only).');
        $this->assertNull($simulation->auteur_id, 'Self-service : auteur_id doit rester null.');
        $this->assertSame('passage', $simulation->scenario);
        $this->assertSame(12.5, (float) $simulation->hypotheses['note']);
        $this->assertArrayHasKey('avertissement', $simulation->resultat);
        $this->assertStringContainsString('sans effet officiel', $simulation->resultat['avertissement']);

        // Zéro écriture officielle : notes et inscription inchangées.
        $this->assertSame($notesAvant, DB::table('notes')->count());
        $this->assertSame(
            $inscriptionAvant,
            InscriptionAcademique::where('etudiant_id', $this->etudiantA->id)->first()->toArray(),
        );
        $this->assertSame(0, DB::table('evaluation_decisions')->count());
    }

    public function test_index_ne_montre_que_mes_simulations(): void
    {
        ParcoursSimulation::create([
            'etudiant_id' => $this->etudiantB->id,
            'scenario' => 'redoublement',
            'hypotheses' => [],
            'resultat' => ['resultat_final' => 'blocage_d_un_autre_etudiant'],
        ]);

        $this->session(['etudiant_auth_id' => $this->etudiantA->id]);

        $this->get(route('etudiant.parcours.simulations.index'))
            ->assertOk()
            ->assertDontSee('blocage_d_un_autre_etudiant');
    }

    public function test_show_d_un_autre_etudiant_est_404(): void
    {
        $simulationB = ParcoursSimulation::create([
            'etudiant_id' => $this->etudiantB->id,
            'scenario' => 'passage',
            'hypotheses' => [],
            'resultat' => ['resultat_final' => 'ok'],
        ]);

        $this->session(['etudiant_auth_id' => $this->etudiantA->id]);

        $this->get(route('etudiant.parcours.simulations.show', $simulationB))->assertNotFound();
    }

    public function test_sans_inscription_affiche_indisponibilite_explicite(): void
    {
        $this->session(['etudiant_auth_id' => $this->etudiantB->id]);

        // Le formulaire affiche l'état d'indisponibilité (pas de calcul substitué).
        $this->get(route('etudiant.parcours.simulations.create'))
            ->assertOk()
            ->assertSee('indisponible');

        // La soumission refuse sans persistre et redirige avec un message explicite.
        $this->from(route('etudiant.parcours.simulations.create'))
            ->post(route('etudiant.parcours.simulations.store'), ['scenario' => 'passage'])
            ->assertRedirect(route('etudiant.parcours.simulations.create'));

        $this->assertSame(0, ParcoursSimulation::where('etudiant_id', $this->etudiantB->id)->count());
    }

    public function test_scenario_invalide_est_rejete(): void
    {
        $this->session(['etudiant_auth_id' => $this->etudiantA->id]);

        $this->from(route('etudiant.parcours.simulations.create'))
            ->post(route('etudiant.parcours.simulations.store'), ['scenario' => 'diplome_automatique'])
            ->assertRedirect(route('etudiant.parcours.simulations.create'))
            ->assertSessionHasErrors('scenario');

        $this->assertSame(0, ParcoursSimulation::count());
    }

    public function test_note_hypothese_hors_borne_est_rejetee(): void
    {
        $this->session(['etudiant_auth_id' => $this->etudiantA->id]);

        $this->from(route('etudiant.parcours.simulations.create'))
            ->post(route('etudiant.parcours.simulations.store'), [
                'scenario' => 'compensation',
                'hypotheses' => ['note' => '25'],
            ])
            ->assertRedirect(route('etudiant.parcours.simulations.create'))
            ->assertSessionHasErrors('hypotheses.note');

        $this->assertSame(0, ParcoursSimulation::count());
    }

    public function test_etudiant_b_ne_peut_pas_simuler_pour_a(): void
    {
        $this->session(['etudiant_auth_id' => $this->etudiantB->id]);

        // Même en injectant un etudiant_id client, il est ignoré :
        // aucune route self-service ne l'accepte.
        $this->from(route('etudiant.parcours.simulations.create'))
            ->post(route('etudiant.parcours.simulations.store'), [
                'scenario' => 'passage',
                'etudiant_id' => $this->etudiantA->id,
            ])
            ->assertRedirect(route('etudiant.parcours.simulations.create'));

        $this->assertSame(0, ParcoursSimulation::where('etudiant_id', $this->etudiantA->id)->count());
    }

    private function creerEtudiant(string $cin): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => 'Simulation',
            'prenom' => 'Test',
            'email' => strtolower($cin).'@example.com',
            'telephone' => '+212600000000',
            'date_naissance' => '2004-03-15',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '12 Rue de la Liberté',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'mention_bac' => 'Bien',
            'password' => 'motdepasse1',
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-2026-'.$cin])->save();
        $etudiant->definirStatut('valide');

        return $etudiant->fresh();
    }
}
