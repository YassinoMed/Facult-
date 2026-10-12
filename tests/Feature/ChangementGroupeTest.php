<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\AppNotification;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\OnlineRequests\Models\RequestType;
use App\Modules\OnlineRequests\Services\OnlineRequestService;
use App\Modules\Schedule\Models\Group;
use App\Services\AffectationService;
use App\Services\ChangementGroupeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP B — Changement de groupe : workflow distinct réutilisant les demandes
 * en ligne (référence, transitions verrouillées, audit, SLA) et le moteur
 * d'affectation (transfert historisé, verrous, idempotence).
 */
class ChangementGroupeTest extends TestCase
{
    use RefreshDatabase;

    private User $scolarite;

    private Faculte $faculte;

    private Faculte $autreFaculte;

    private AnneeUniversitaire $annee;

    private RequestType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->autreFaculte = Faculte::create(['nom' => 'FLSH', 'code' => 'FLSH', 'description' => 'T', 'active' => true]);
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.chg@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->type = RequestType::create([
            'code' => 'changement_groupe', 'name' => 'Changement de groupe', 'active' => true,
            'form_schema' => [
                ['name' => 'groupe_cible_id', 'type' => 'text', 'required' => true, 'label' => 'Groupe cible'],
                ['name' => 'motif', 'type' => 'text', 'required' => false, 'label' => 'Motif'],
            ],
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
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara', 'email' => strtolower($cin).'@chg.test',
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

    private function affecterInitial(Etudiant $etudiant, Group $groupe): void
    {
        app(AffectationService::class)->affecter($etudiant, $groupe, $this->annee, $this->scolarite->id, 'initiale');
    }

    private function service(): ChangementGroupeService
    {
        return app(ChangementGroupeService::class);
    }

    public function test_demande_cree_reference_soumise(): void
    {
        $groupeA = $this->creerGroupe('L1-A10');
        $groupeB = $this->creerGroupe('L1-B10');
        $etudiant = $this->creerEtudiant('CHG00001');
        $this->affecterInitial($etudiant, $groupeA);

        $demande = $this->service()->demander($etudiant, $groupeB, $this->annee, 'Rapprochement domicile');

        $this->assertNotEmpty($demande->reference);
        $this->assertSame('submitted', $demande->status);
        $this->assertSame($groupeB->id, (int) $demande->form_data['groupe_cible_id']);
        // L'affectation d'origine est intacte tant que ce n'est pas appliqué.
        $this->assertSame($groupeA->id, (int) $etudiant->fresh()->groupe_id);
    }

    public function test_demande_identique_idempotente(): void
    {
        $groupeA = $this->creerGroupe('L1-A11');
        $groupeB = $this->creerGroupe('L1-B11');
        $etudiant = $this->creerEtudiant('CHG00002');
        $this->affecterInitial($etudiant, $groupeA);

        $premiere = $this->service()->demander($etudiant, $groupeB, $this->annee);
        $seconde = $this->service()->demander($etudiant, $groupeB, $this->annee);

        $this->assertSame($premiere->id, $seconde->id);
        $this->assertSame(1, OnlineRequest::where('etudiant_id', $etudiant->id)
            ->where('request_type_id', $this->type->id)->count());
    }

    public function test_nouvelle_demande_apres_cloture_autorisee(): void
    {
        $groupeA = $this->creerGroupe('L1-A12');
        $groupeB = $this->creerGroupe('L1-B12');
        $etudiant = $this->creerEtudiant('CHG00003');
        $this->affecterInitial($etudiant, $groupeA);

        $ancienne = $this->service()->demander($etudiant, $groupeB, $this->annee);
        $serviceDemandes = app(OnlineRequestService::class);
        $serviceDemandes->recevoir($ancienne->fresh());
        $serviceDemandes->examiner($ancienne->fresh());
        $serviceDemandes->rejeter($ancienne->fresh(), 'Test');

        $nouvelle = $this->service()->demander($etudiant, $groupeB, $this->annee);

        $this->assertNotSame($ancienne->id, $nouvelle->id);
    }

    public function test_simulation_compatible_et_incompatible(): void
    {
        $groupeA = $this->creerGroupe('L1-A13');
        $groupeB = $this->creerGroupe('L1-B13');
        $complet = $this->creerGroupe('L1-F13', 1);
        $etudiant = $this->creerEtudiant('CHG00004');
        $this->affecterInitial($etudiant, $groupeA);
        $occupant = $this->creerEtudiant('CHG00005');
        $this->affecterInitial($occupant, $complet);

        $demandeOk = $this->service()->demander($etudiant, $groupeB, $this->annee);
        $simulationOk = $this->service()->simulerDemande($demandeOk);
        $this->assertTrue($simulationOk['compatible']);
        $this->assertSame([], $simulationOk['bloquants']);

        $demandeKo = $this->service()->demander($etudiant, $complet, $this->annee);
        $simulationKo = $this->service()->simulerDemande($demandeKo);
        $this->assertFalse($simulationKo['compatible']);
        $this->assertNotEmpty($simulationKo['bloquants']);
        // Simulation pure : rien n'a bougé.
        $this->assertSame($groupeA->id, (int) $etudiant->fresh()->groupe_id);
    }

    public function test_approbation_applique_transfert_historise(): void
    {
        $groupeA = $this->creerGroupe('L1-A14');
        $groupeB = $this->creerGroupe('L1-B14');
        $etudiant = $this->creerEtudiant('CHG00006');
        $this->affecterInitial($etudiant, $groupeA);

        $demande = $this->service()->demander($etudiant, $groupeB, $this->annee);
        $this->instruire($demande);
        $this->service()->approuver($demande, $this->scolarite->id);
        $avant = AppNotification::where('etudiant_id', $etudiant->id)
            ->where('type', 'affectation.groupe')->count();
        $this->service()->appliquer($demande->fresh(), $this->scolarite->id);

        $this->assertSame('completed', $demande->fresh()->status);
        $this->assertSame($groupeB->id, (int) $etudiant->fresh()->groupe_id);
        $this->assertDatabaseHas('situations_etudiant', [
            'etudiant_id' => $etudiant->id, 'type' => 'groupe',
            'ancienne_valeur' => 'L1-A14', 'nouvelle_valeur' => 'L1-B14',
        ]);
        // Notification étudiante unique pour le transfert (pas de double envoi).
        $this->assertSame($avant + 1, AppNotification::where('etudiant_id', $etudiant->id)
            ->where('type', 'affectation.groupe')->count());
    }

    public function test_application_refusee_si_cible_devenue_incompatible(): void
    {
        $groupeA = $this->creerGroupe('L1-A15');
        $groupeB = $this->creerGroupe('L1-B15', 1);
        $etudiant = $this->creerEtudiant('CHG00007');
        $this->affecterInitial($etudiant, $groupeA);

        $demande = $this->service()->demander($etudiant, $groupeB, $this->annee);
        $this->instruire($demande);
        $this->service()->approuver($demande, $this->scolarite->id);

        // Course : la cible se remplit avant l'application.
        $concurrent = $this->creerEtudiant('CHG00008');
        app(AffectationService::class)->affecter($concurrent, $groupeB, $this->annee, $this->scolarite->id, 'course');

        try {
            $this->service()->appliquer($demande->fresh(), $this->scolarite->id);
            $this->fail('Application sur cible saturée acceptée.');
        } catch (\DomainException) {
        }

        // Demande rejouable (approved conservé), origine intacte.
        $this->assertSame('approved', $demande->fresh()->status);
        $this->assertSame($groupeA->id, (int) $etudiant->fresh()->groupe_id);
    }

    public function test_appliquer_exige_statut_approved(): void
    {
        $groupeA = $this->creerGroupe('L1-A16');
        $groupeB = $this->creerGroupe('L1-B16');
        $etudiant = $this->creerEtudiant('CHG00009');
        $this->affecterInitial($etudiant, $groupeA);

        $demande = $this->service()->demander($etudiant, $groupeB, $this->annee);

        try {
            $this->service()->appliquer($demande, $this->scolarite->id);
            $this->fail('Application sans approbation acceptée.');
        } catch (\DomainException) {
        }
        $this->assertSame($groupeA->id, (int) $etudiant->fresh()->groupe_id);
    }

    public function test_sans_origine_simulation_impossible_affectation_directe_requise(): void
    {
        // Sans affectation active, il n'y a rien à « changer » : la voie
        // demande est refusée en simulation (l'affectation initiale passe
        // par le moteur direct, pas par ce workflow).
        $groupeB = $this->creerGroupe('L1-B17');
        $etudiant = $this->creerEtudiant('CHG00010');

        $demande = $this->service()->demander($etudiant, $groupeB, $this->annee);
        $simulation = $this->service()->simulerDemande($demande);

        $this->assertFalse($simulation['compatible']);
        $this->assertNotEmpty($simulation['bloquants']);
    }

    public function test_cible_inter_facultes_refusee(): void
    {
        $groupeA = $this->creerGroupe('L1-A18');
        $groupeB = $this->creerGroupe('L1-X18', 30, $this->autreFaculte);
        $etudiant = $this->creerEtudiant('CHG00011');
        $this->affecterInitial($etudiant, $groupeA);

        $demande = $this->service()->demander($etudiant, $groupeB, $this->annee);
        $simulation = $this->service()->simulerDemande($demande);

        $this->assertFalse($simulation['compatible']);
    }

    public function test_annulation_demande_bloque_application(): void
    {
        $groupeA = $this->creerGroupe('L1-A19');
        $groupeB = $this->creerGroupe('L1-B19');
        $etudiant = $this->creerEtudiant('CHG00012');
        $this->affecterInitial($etudiant, $groupeA);

        $demande = $this->service()->demander($etudiant, $groupeB, $this->annee);
        app(OnlineRequestService::class)->annuler($demande);

        try {
            $this->service()->appliquer($demande->fresh(), $this->scolarite->id);
            $this->fail('Application après annulation acceptée.');
        } catch (\DomainException) {
        }
        $this->assertSame($groupeA->id, (int) $etudiant->fresh()->groupe_id);
    }

    public function test_perimetre_restreint_refuse_demande_et_application(): void
    {
        $etudiantB = $this->creerEtudiant('CHG00013');
        $etudiantB->update(['faculte_id' => $this->autreFaculte->id]);
        $groupeB = $this->creerGroupe('L1-B20', 30, $this->autreFaculte);
        $restreinte = $this->faculte->id;

        try {
            $this->service()->demander($etudiantB, $groupeB, $this->annee, null, $restreinte);
            $this->fail('Demande hors périmètre acceptée.');
        } catch (\DomainException) {
        }
        $this->assertSame(0, OnlineRequest::where('etudiant_id', $etudiantB->id)->count());
    }

    private function instruire(OnlineRequest $demande): void
    {
        $service = app(OnlineRequestService::class);
        $service->recevoir($demande);
        $service->examiner($demande->fresh());
    }
}
