<?php

namespace Tests\Feature;

use App\Models\AffectationGroupe;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\SituationEtudiant;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use App\Services\AffectationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DEV inventaire — changement de groupe, lots mixtes, MENS indisponible.
 * Verrouille les comportements existants avant tout comblement.
 */
class InscriptionAutomationTest extends TestCase
{
    use RefreshDatabase;

    private User $scolarite;

    private Faculte $faculte;

    private Faculte $autreFaculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->autreFaculte = Faculte::create(['nom' => 'FLSH', 'code' => 'FLSH', 'description' => 'T', 'active' => true]);
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.inv@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
    }

    private function creerGroupe(string $code, ?int $capacite = 30, ?Faculte $faculte = null): Group
    {
        return Group::create([
            'code' => $code, 'nom' => $code, 'faculte_id' => ($faculte ?? $this->faculte)->id,
            'niveau' => 'L1', 'capacite' => $capacite, 'actif' => true,
        ]);
    }

    private function creerEtudiant(string $cin): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara', 'email' => strtolower($cin).'@auto.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');
        $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
        ]);

        return $etudiant;
    }

    private function service(): AffectationService
    {
        return app(AffectationService::class);
    }

    public function test_transfert_cloture_ancienne_et_historise(): void
    {
        $groupeA = $this->creerGroupe('L1-A01');
        $groupeB = $this->creerGroupe('L1-B01');
        $etudiant = $this->creerEtudiant('TR000001');

        $ancienne = $this->service()->affecter($etudiant, $groupeA, $this->annee, $this->scolarite->id, 'initiale');
        $nouvelle = $this->service()->affecter($etudiant->fresh(), $groupeB, $this->annee, $this->scolarite->id, 'transfert');

        $this->assertSame('cloturee', $ancienne->fresh()->statut);
        $this->assertSame('active', $nouvelle->statut);
        $this->assertSame($groupeB->id, (int) $etudiant->fresh()->groupe_id);
        $this->assertSame(1, AffectationGroupe::where('etudiant_id', $etudiant->id)
            ->where('annee_universitaire_id', $this->annee->id)->where('statut', 'active')->count());
        $this->assertDatabaseHas('situations_etudiant', [
            'etudiant_id' => $etudiant->id, 'type' => 'groupe',
            'ancienne_valeur' => 'L1-A01', 'nouvelle_valeur' => 'L1-B01',
        ]);
    }

    public function test_rejeu_transfert_identique_idempotent(): void
    {
        $groupeA = $this->creerGroupe('L1-A02');
        $groupeB = $this->creerGroupe('L1-B02');
        $etudiant = $this->creerEtudiant('TR000002');

        $premiere = $this->service()->affecter($etudiant, $groupeA, $this->annee, $this->scolarite->id, 'initiale');
        $this->service()->affecter($etudiant->fresh(), $groupeB, $this->annee, $this->scolarite->id, 'transfert');
        $rejeu = $this->service()->affecter($etudiant->fresh(), $groupeB, $this->annee, $this->scolarite->id, 'transfert');

        $this->assertSame(2, AffectationGroupe::where('etudiant_id', $etudiant->id)
            ->where('annee_universitaire_id', $this->annee->id)->count());
        $this->assertNotSame($premiere->id, $rejeu->id);
    }

    public function test_transfert_vers_groupe_complet_refuse_ancienne_intacte(): void
    {
        $groupeA = $this->creerGroupe('L1-A03');
        $complet = $this->creerGroupe('L1-FULL', 1);
        $occupant = $this->creerEtudiant('TR000003');
        $this->service()->affecter($occupant, $complet, $this->annee, $this->scolarite->id, 'remplissage');

        $etudiant = $this->creerEtudiant('TR000004');
        $this->service()->affecter($etudiant, $groupeA, $this->annee, $this->scolarite->id, 'initiale');

        try {
            $this->service()->affecter($etudiant->fresh(), $complet, $this->annee, $this->scolarite->id, 'transfert');
            $this->fail('Transfert vers groupe complet accepté.');
        } catch (\DomainException) {
        }

        $this->assertSame($groupeA->id, (int) $etudiant->fresh()->groupe_id);
        $this->assertSame('active', AffectationGroupe::where('etudiant_id', $etudiant->id)
            ->where('groupe_id', $groupeA->id)->firstOrFail()->statut);
    }

    public function test_transfert_inter_facultes_refuse(): void
    {
        $groupeA = $this->creerGroupe('L1-A04');
        $groupeB = $this->creerGroupe('L1-X04', 30, $this->autreFaculte);
        $etudiant = $this->creerEtudiant('TR000005');
        $this->service()->affecter($etudiant, $groupeA, $this->annee, $this->scolarite->id, 'initiale');

        try {
            $this->service()->affecter($etudiant->fresh(), $groupeB, $this->annee, $this->scolarite->id, 'transfert');
            $this->fail('Transfert inter-facultés accepté.');
        } catch (\DomainException) {
        }

        $this->assertSame($groupeA->id, (int) $etudiant->fresh()->groupe_id);
    }

    public function test_lot_mixte_rapport_partiel_sans_exception(): void
    {
        $groupe = $this->creerGroupe('L1-LOT');
        $ok = $this->creerEtudiant('TR000006');
        $suspendu = $this->creerEtudiant('TR000007');
        SituationEtudiant::create([
            'etudiant_id' => $suspendu->id, 'type' => 'suspension',
            'date_effet' => now()->toDateString(), 'motif' => 'test',
        ]);

        $rapport = $this->service()->affecterLot(
            [$ok->id, $suspendu->id], $groupe, $this->annee, $this->scolarite->id, 'lot'
        );

        $this->assertSame($groupe->id, (int) $ok->fresh()->groupe_id);
        $this->assertNull($suspendu->fresh()->groupe_id);
        $this->assertSame(1, $rapport['appliquees']);
        $this->assertSame(1, $rapport['ignorees']);
        $this->assertSame([], $rapport['erreurs']);

        // Le motif du blocage est exposé par la simulation (pas d'exception globale).
        $simulation = $this->service()->simuler(
            [$ok->id, $suspendu->id], $groupe, $this->annee
        );
        $this->assertArrayHasKey($suspendu->id, $simulation['bloquees']);
    }

    public function test_mens_indisponible_store_refuse_rien_cree(): void
    {
        config(['inscriptions.mens_simuler_panne' => true]);

        $reponse = $this->post(route('inscription.store'), [
            'cin' => 'TR000008',
            'nom' => 'Test', 'prenom' => 'Mens', 'date_naissance' => '2004-01-01',
            'sexe' => 'M',
            'email' => 'mens.down@test.tn', 'telephone' => '+21600000000',
            'adresse' => 'X', 'ville' => 'Casa', 'code_postal' => '20000',
            'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
        ]);

        $reponse->assertSessionHasErrors();
        $this->assertDatabaseMissing('etudiants', ['cin' => 'TR000008']);
    }
}
