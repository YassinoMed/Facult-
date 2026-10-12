<?php

namespace Tests\Feature;

use App\Jobs\ExecuterAffectationMasseLot;
use App\Models\AffectationGroupe;
use App\Models\AffectationMasseLot;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Services\Affectations\MassAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP P2 — lots d'affectation de masse par tranches : exécution,
 * progression, reprise idempotente, annulation, seuil sync/async,
 * périmètre faculté, auteur révoqué. Le Job orchestre, le service
 * existant décide (aucune règle métier dans le Job).
 */
class AffectationMasseLotTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Group $groupe1;

    private Group $groupe2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'lots@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LIC-INFO', 'nom' => 'Licence Info',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $this->groupe1 = Group::create([
            'code' => 'LOT-G1', 'nom' => 'G1', 'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id, 'niveau' => 'L1', 'capacite' => 30, 'actif' => true,
        ]);
        $this->groupe2 = Group::create([
            'code' => 'LOT-G2', 'nom' => 'G2', 'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id, 'niveau' => 'L1', 'capacite' => 30, 'actif' => true,
        ]);
    }

    private function creerEtudiant(string $cin): Etudiant
    {
        static $compteur = 300;
        $compteur++;

        $etudiant = Etudiant::create([
            'cin' => $cin, 'num_inscription' => 'INS-LOT'.$compteur,
            'nom' => 'Lot', 'prenom' => 'Test'.$compteur, 'email' => "lot{$compteur}@universite.test",
            'date_naissance' => '2004-01-15', 'sexe' => 'M', 'telephone' => '98000111',
            'faculte_id' => $this->faculte->id, 'programme_id' => $this->programme->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027',
            'adresse' => 'Rue X', 'ville' => 'Tunis', 'code_postal' => '1000',
            'annee_bac' => '2023', 'password' => 'Secret@123',
        ]);
        $etudiant->definirStatut('valide');
        $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1', 'statut' => 'valide',
        ]);

        return $etudiant;
    }

    /**
     * @param  array<int>  $ids
     */
    private function creerLot(array $ids, ?int $auteurId = null): AffectationMasseLot
    {
        return app(MassAssignmentService::class)->creerLot(
            array_map(fn (int $id) => ['student_id' => $id, 'groupe_id' => $this->groupe1->id], $ids),
            $this->annee->id,
            $auteurId ?? $this->admin->id,
            'Lot de test'
        );
    }

    public function test_lot_execute_tranches_et_termine_avec_compteurs(): void
    {
        $ids = [$this->creerEtudiant('LOT00001')->id, $this->creerEtudiant('LOT00002')->id];
        $lot = $this->creerLot($ids);

        $this->assertSame('en_attente', $lot->statut);

        app()->call([new ExecuterAffectationMasseLot($lot->id), 'handle']);

        $lot->refresh();
        $this->assertSame('termine', $lot->statut);
        $this->assertSame(2, $lot->traites);
        $this->assertSame(2, $lot->affectes);
        $this->assertSame(2, AffectationGroupe::where('annee_universitaire_id', $this->annee->id)->count());
        $this->assertSame(100.0, $lot->pourcentage());
    }

    public function test_reprise_ne_duplique_aucune_affectation(): void
    {
        $ids = [$this->creerEtudiant('LOT00011')->id];
        $lot = $this->creerLot($ids);
        $job = new ExecuterAffectationMasseLot($lot->id);

        $job = new ExecuterAffectationMasseLot($lot->id);
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);

        $this->assertSame(1, AffectationGroupe::where('annee_universitaire_id', $this->annee->id)->count());
        $this->assertSame('termine', $lot->fresh()->statut);
    }

    public function test_lot_annule_n_execute_rien(): void
    {
        $ids = [$this->creerEtudiant('LOT00021')->id];
        $lot = $this->creerLot($ids);
        $lot->update(['statut' => 'annule']);

        app()->call([new ExecuterAffectationMasseLot($lot->id), 'handle']);

        $this->assertSame(0, AffectationGroupe::count());
        $this->assertSame('annule', $lot->fresh()->statut);
    }

    public function test_auteur_introuvable_lot_echoue_proprement(): void
    {
        $ids = [$this->creerEtudiant('LOT00031')->id];
        $lot = $this->creerLot($ids);
        $this->admin->delete();

        app()->call([new ExecuterAffectationMasseLot($lot->id), 'handle']);

        $this->assertSame('echoue', $lot->fresh()->statut);
        $this->assertNotNull($lot->fresh()->message);
        $this->assertSame(0, AffectationGroupe::count());
    }

    public function test_controleur_bascule_sync_ou_lot_selon_seuil(): void
    {
        config(['affectations.lots.seuil_sync' => 5]);
        $etudiant = $this->creerEtudiant('LOT00041');

        // Sous le seuil : exécution synchrone immédiate.
        $this->actingAs($this->admin)->post(route('admin.affectations.masse.confirmer'), [
            'annee_universitaire_id' => $this->annee->id,
            'motif' => 'Petit lot',
            'confirmer' => true,
            'selections' => [['student_id' => $etudiant->id, 'groupe_id' => $this->groupe1->id]],
        ])->assertRedirect(route('admin.affectations.masse'));
        $this->assertSame(0, AffectationMasseLot::count());

        // Au-delà du seuil : lot + file (sync en tests = exécuté inline).
        $etudiant2 = $this->creerEtudiant('LOT00042');
        $selections = [
            ['student_id' => $etudiant->id, 'groupe_id' => $this->groupe1->id],
            ['student_id' => $etudiant2->id, 'groupe_id' => $this->groupe2->id],
        ];
        config(['affectations.lots.seuil_sync' => 1]);

        $reponse = $this->actingAs($this->admin)->post(route('admin.affectations.masse.confirmer'), [
            'annee_universitaire_id' => $this->annee->id,
            'motif' => 'Gros lot',
            'confirmer' => true,
            'selections' => $selections,
        ]);

        $lot = AffectationMasseLot::firstOrFail();
        $reponse->assertRedirect(route('admin.affectations.masse.lots.show', $lot));
        $this->assertSame('termine', $lot->fresh()->statut);
    }

    public function test_lot_ignore_hors_perimetre_faculte(): void
    {
        $autreFaculte = Faculte::create(['nom' => 'FB', 'code' => 'FB', 'description' => 'T', 'active' => true]);
        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.lots@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculte->id,
        ], 'admin_faculte');
        $horsPerimetre = $this->creerEtudiant('LOT00051');
        $horsPerimetre->update(['faculte_id' => $autreFaculte->id]);

        $lot = $this->creerLot([$horsPerimetre->id], $adminFaculte->id);
        app()->call([new ExecuterAffectationMasseLot($lot->id), 'handle']);

        $this->assertSame(0, AffectationGroupe::count());
        $this->assertSame('termine', $lot->fresh()->statut);
        $this->assertSame(1, $lot->fresh()->ignores);
    }

    public function test_statut_lot_exige_authentification(): void
    {
        $lot = $this->creerLot([$this->creerEtudiant('LOT00061')->id]);

        $this->getJson(route('admin.affectations.masse.lots.show', $lot))->assertUnauthorized();
    }

    public function test_annuler_lot_arrete_la_progression(): void
    {
        $lot = $this->creerLot([
            $this->creerEtudiant('LOT00071')->id, $this->creerEtudiant('LOT00072')->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.affectations.masse.lots.show', $lot))
            ->assertOk();

        $this->actingAs($this->admin)
            ->post(route('admin.affectations.masse.lots.annuler', $lot))
            ->assertRedirect(route('admin.affectations.masse.lots.show', $lot));

        $this->assertSame('annule', $lot->fresh()->statut);

        app()->call([new ExecuterAffectationMasseLot($lot->id), 'handle']);
        $this->assertSame(0, AffectationGroupe::count());
    }

    public function test_annuler_lot_refuse_tiers_sans_droit(): void
    {
        $lot = $this->creerLot([$this->creerEtudiant('LOT00081')->id]);
        $consultation = User::creerCompteBackOffice([
            'name' => 'Consult', 'email' => 'consult.lots@universite.test', 'password' => 'Motdepasse@123',
        ], 'consultation');

        $this->actingAs($consultation)
            ->post(route('admin.affectations.masse.lots.annuler', $lot))
            ->assertForbidden();
        $this->assertSame('en_attente', $lot->fresh()->statut);
    }
}
