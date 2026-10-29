<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\ConflitEpreuveService;
use App\Modules\Evaluation\Services\EpreuveService;
use App\Modules\Evaluation\Services\ExamRoomAssignmentService;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Invariants salles / capacités / horaires / candidats du module Examens (§7.2).
 *
 * Chaque test relie une décision métier (DM-01…DM-08) à une preuve exécutable :
 * - DM-01 : salle inactive ou capacité inconnue refusée partout ;
 * - DM-04 : horaires d'ouverture respectés ;
 * - DM-02 : statut initial « non pointé » ;
 * - DM-03 : conflits vérifiés globalement (aucun filtre faculté) ;
 * - DM-05/06/08 : revalidation complète, couverture intégrale, pas
 *   d'écriture partielle ni de doublon de place.
 */
class ExamensInvariantsSallesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Group $groupe;

    private Matiere $matiere;

    private SessionExamen $session;

    private Examen $examen;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.evaluation.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.inv@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->groupe = Group::create([
            'code' => 'INV1', 'nom' => 'INV1', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'capacite' => 50, 'actif' => true,
        ]);
        $this->matiere = Matiere::create([
            'code' => 'INV01', 'nom' => 'Invariant', 'niveau' => 'L1', 'semestre' => 1, 'credits' => 4,
        ]);
        $this->session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'S-INV', 'nom' => 'Session invariants', 'type' => 'principale',
            'date_debut' => '2026-06-01', 'date_fin' => '2026-06-30',
            'statut' => 'open', 'created_by' => $this->admin->id,
        ]);
        $this->examen = Examen::create([
            'session_id' => $this->session->id, 'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examen invariants', 'type' => 'examen', 'statut' => 'scheduled',
            'responsable_id' => $this->admin->id,
        ]);
    }

    public function test_salle_inactive_refusee_par_affecter_salle(): void
    {
        $salle = $this->creerSalle(['actif' => false]);
        $epreuve = $this->creerEpreuve();
        $this->creerCandidat($epreuve, $this->creerEtudiant('001'));

        try {
            app(ExamRoomAssignmentService::class)->affecterSalle($epreuve, (int) $salle->id, $this->admin->id);
            $this->fail('Salle inactive acceptée par affecterSalle.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('inactive : affectation interdite', $exception->getMessage());
        }

        // DM-01 : aucune écriture n'a eu lieu.
        $this->assertNull($epreuve->refresh()->salle_id);
    }

    public function test_salle_inactive_exclue_de_la_simulation_avec_conflit_explicite(): void
    {
        $service = app(ExamRoomAssignmentService::class);
        $inactive = $this->creerSalle(['actif' => false]);
        $valide = $this->creerSalle(['capacite' => 25]);
        $epreuve = $this->creerEpreuve();
        $this->creerCandidat($epreuve, $this->creerEtudiant('002'));

        $simulation = $service->simulerRepartition($epreuve, [(int) $inactive->id, (int) $valide->id]);

        $this->assertFalse($simulation['valide']);
        $this->assertContains('salle_inactive', array_column($simulation['conflits'], 'type'));
        // DM-01 : seule la capacité admissible compte.
        $this->assertSame(25, $simulation['capacite_totale']);
        $this->assertTrue(collect($simulation['repartition'])->every(fn (array $ligne) => (int) $ligne['salle_id'] === (int) $valide->id));
    }

    public function test_salle_capacite_inconnue_refusee(): void
    {
        $salle = $this->creerSalle(['capacite' => null]);
        $epreuve = $this->creerEpreuve();
        $this->creerCandidat($epreuve, $this->creerEtudiant('003'));

        try {
            app(ExamRoomAssignmentService::class)->affecterSalle($epreuve, (int) $salle->id, $this->admin->id);
            $this->fail('Salle à capacité inconnue acceptée.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('inconnue : affectation interdite', $exception->getMessage());
        }

        $this->assertNull($epreuve->refresh()->salle_id);
    }

    public function test_salle_fermee_sur_le_creneau_bloquante_et_ouverture_respectee_ok(): void
    {
        $conflits = app(ConflitEpreuveService::class)->detecter([
            'date' => '2026-06-10',
            'heure_debut' => '08:00',
            'heure_fin' => '10:00',
            'salle_id' => $this->creerSalle(['heure_ouverture' => '09:00', 'heure_fermeture' => '17:00'])->id,
        ]);

        $ouvertures = array_values(array_filter($conflits, fn (array $c) => $c['type'] === 'ouverture'));
        $this->assertCount(1, $ouvertures);
        $this->assertTrue($ouvertures[0]['critique']);

        // DM-04 : épreuve commençant exactement à l'heure d'ouverture.
        $dedans = app(ConflitEpreuveService::class)->detecter([
            'date' => '2026-06-10',
            'heure_debut' => '08:00',
            'heure_fin' => '10:00',
            'salle_id' => $this->creerSalle(['heure_ouverture' => '08:00', 'heure_fermeture' => '10:00'])->id,
        ]);

        $this->assertSame([], array_values(array_filter($dedans, fn (array $c) => $c['type'] === 'ouverture')));
    }

    public function test_bornes_adjacentes_ne_sont_pas_en_conflit(): void
    {
        $salle = $this->creerSalle();
        $premiere = $this->creerEpreuve(['date' => '2026-06-12', 'heure_debut' => '09:00', 'heure_fin' => '11:00']);
        $premiere->update(['salle_id' => $salle->id]);

        $suivante = $this->creerEpreuve(['date' => '2026-06-12', 'heure_debut' => '11:00', 'heure_fin' => '13:00']);

        $conflits = app(ConflitEpreuveService::class)->detecter([
            'date' => '2026-06-12',
            'heure_debut' => $suivante->heure_debut,
            'heure_fin' => $suivante->heure_fin,
            'salle_id' => $salle->id,
        ], (int) $suivante->id);

        $this->assertSame([], array_values(array_filter($conflits, fn (array $c) => $c['type'] === 'salle')));
    }

    public function test_depassement_capacite_bloque_simulation_et_application_directe(): void
    {
        $service = app(ExamRoomAssignmentService::class);
        $salle = $this->creerSalle(['capacite' => 1]);
        $epreuve = $this->creerEpreuve();
        $premier = $this->creerCandidat($epreuve, $this->creerEtudiant('004'));
        $second = $this->creerCandidat($epreuve, $this->creerEtudiant('005'));

        $simulation = $service->simulerRepartition($epreuve, [(int) $salle->id]);
        $this->assertFalse($simulation['valide']);
        $this->assertContains('capacite', array_column($simulation['conflits'], 'type'));

        $payload = [
            ['candidat_id' => (int) $premier->id, 'salle_id' => (int) $salle->id, 'place' => 'INV-S1-01'],
            ['candidat_id' => (int) $second->id, 'salle_id' => (int) $salle->id, 'place' => 'INV-S1-02'],
        ];

        try {
            $service->appliquerRepartition($epreuve, $payload, $this->admin->id);
            $this->fail('Dépassement de capacité accepté à l\'application.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Capacité dépassée', $exception->getMessage());
        }

        $this->assertNull($premier->refresh()->salle_id);
        $this->assertNull($second->refresh()->salle_id);
        $this->assertNull($premier->place);
        $this->assertNull($second->place);
    }

    public function test_capacite_reduite_apres_simulation_provoque_un_refus(): void
    {
        $service = app(ExamRoomAssignmentService::class);
        $salle = $this->creerSalle(['capacite' => 30]);
        $epreuve = $this->creerEpreuve();
        $premier = $this->creerCandidat($epreuve, $this->creerEtudiant('006'));
        $second = $this->creerCandidat($epreuve, $this->creerEtudiant('007'));

        $simulation = $service->simulerRepartition($epreuve, [(int) $salle->id]);
        $this->assertTrue($simulation['valide']);

        // SAN-04 : la salle évolue entre la simulation et l'application.
        $salle->update(['capacite' => 1]);

        try {
            $service->appliquerRepartition($epreuve, $simulation['repartition'], $this->admin->id);
            $this->fail('Répartition obsolète acceptée malgré la nouvelle capacité.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Capacité dépassée', $exception->getMessage());
        }

        $this->assertNull($premier->refresh()->salle_id);
        $this->assertNull($second->refresh()->salle_id);
    }

    public function test_salle_desactivee_apres_simulation_provoque_un_refus(): void
    {
        $service = app(ExamRoomAssignmentService::class);
        $salle = $this->creerSalle(['capacite' => 30]);
        $epreuve = $this->creerEpreuve();
        $candidat = $this->creerCandidat($epreuve, $this->creerEtudiant('013'));

        $simulation = $service->simulerRepartition($epreuve, [(int) $salle->id]);
        $this->assertTrue($simulation['valide']);

        // La salle est désactivée après la simulation : l'application
        // revalide l'admissibilité (DM-01/DM-08) et refuse.
        $salle->update(['actif' => false]);

        try {
            $service->appliquerRepartition($epreuve, $simulation['repartition'], $this->admin->id);
            $this->fail('Salle désactivée après simulation : répartition acceptée.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('inactive : répartition interdite', $exception->getMessage());
        }

        $this->assertNull($candidat->refresh()->salle_id);
    }

    public function test_payload_avec_candidat_hors_epreuve_provoque_rollback_complet(): void
    {
        $service = app(ExamRoomAssignmentService::class);
        $salle = $this->creerSalle(['capacite' => 30]);
        $epreuve = $this->creerEpreuve();
        $interne = $this->creerCandidat($epreuve, $this->creerEtudiant('008'));

        $etrangere = $this->creerEpreuve(['date' => '2026-06-10']);
        $horsEpreuve = $this->creerCandidat($etrangere, $this->creerEtudiant('009'));

        $payload = [
            ['candidat_id' => (int) $interne->id, 'salle_id' => (int) $salle->id, 'place' => 'INV-S1-01'],
            ['candidat_id' => (int) $horsEpreuve->id, 'salle_id' => (int) $salle->id, 'place' => 'INV-S1-02'],
        ];

        try {
            $service->appliquerRepartition($epreuve, $payload, $this->admin->id);
            $this->fail('Candidat hors épreuve accepté.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('candidat(s) de cette épreuve uniquement', $exception->getMessage());
        }

        $this->assertNull($interne->refresh()->salle_id);
        $this->assertNull($horsEpreuve->refresh()->salle_id);
    }

    public function test_doublon_de_place_refuse_sans_ecriture(): void
    {
        $service = app(ExamRoomAssignmentService::class);
        $salle = $this->creerSalle(['capacite' => 30]);
        $epreuve = $this->creerEpreuve();
        $premier = $this->creerCandidat($epreuve, $this->creerEtudiant('010'));
        $second = $this->creerCandidat($epreuve, $this->creerEtudiant('011'));

        $payload = [
            ['candidat_id' => (int) $premier->id, 'salle_id' => (int) $salle->id, 'place' => 'INV-S1-07'],
            ['candidat_id' => (int) $second->id, 'salle_id' => (int) $salle->id, 'place' => 'INV-S1-07'],
        ];

        try {
            $service->appliquerRepartition($epreuve, $payload, $this->admin->id);
            $this->fail('Place attribuée deux fois : accepté.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('attribuée deux fois', $exception->getMessage());
        }

        $this->assertNull($premier->refresh()->place);
        $this->assertNull($second->refresh()->place);
    }

    public function test_statut_initial_non_pointe_et_generation_idempotente(): void
    {
        $epreuve = $this->creerEpreuve();
        $this->creerEtudiant('102');
        $this->creerEtudiant('103');

        $service = app(EpreuveService::class);
        $this->assertSame(2, $service->genererCandidats($epreuve));

        $this->assertDatabaseHas('evaluation_candidats', [
            'epreuve_id' => $epreuve->id,
            'statut' => 'non_pointe',
        ]);

        // Idempotence : la régénération ne duplique ni ne re-pointe.
        $this->assertSame(0, $service->genererCandidats($epreuve));
        $this->assertSame(2, $epreuve->candidats()->where('statut', 'non_pointe')->count());
    }

    public function test_simulation_sans_ecriture_persistante(): void
    {
        $service = app(ExamRoomAssignmentService::class);
        $salle = $this->creerSalle();
        $epreuve = $this->creerEpreuve();
        $candidat = $this->creerCandidat($epreuve, $this->creerEtudiant('012'));

        $service->simulerRepartition($epreuve, [(int) $salle->id]);

        $candidat->refresh();
        $this->assertNull($candidat->salle_id);
        $this->assertNull($candidat->place);
        $this->assertNull($epreuve->refresh()->salle_id);
    }

    public function test_conflit_candidats_partages_detecte(): void
    {
        $salle = $this->creerSalle();
        $premiere = $this->creerEpreuve(['date' => '2026-06-14', 'heure_debut' => '08:00', 'heure_fin' => '10:00']);
        $seconde = $this->creerEpreuve(['date' => '2026-06-14', 'heure_debut' => '09:00', 'heure_fin' => '11:00']);

        $etudiant = $this->creerEtudiant('201');
        $this->creerCandidat($premiere, $etudiant);
        $this->creerCandidat($seconde, $etudiant);

        $conflits = app(ConflitEpreuveService::class)->detecter([
            'date' => '2026-06-14',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
            'salle_id' => $salle->id,
        ], (int) $seconde->id);

        $candidats = array_values(array_filter($conflits, fn (array $c) => $c['type'] === 'candidat'));
        $this->assertCount(1, $candidats);
        $this->assertTrue($candidats[0]['critique']);
    }

    public function test_conflit_salle_global_sans_isolation_par_faculte(): void
    {
        // DM-03 : deux examens distincts partagent une salle ; la réservation
        // d'un groupe A doit être visible du groupe B (aucun filtre faculté).
        $salle = $this->creerSalle();
        $premierExamen = Examen::create([
            'session_id' => $this->session->id, 'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examen B', 'type' => 'examen', 'statut' => 'scheduled',
            'responsable_id' => $this->admin->id,
        ]);

        $originale = $this->creerEpreuve(['date' => '2026-06-15']);
        $originale->update(['salle_id' => $salle->id, 'examen_id' => $premierExamen->id]);

        $croisee = $this->creerEpreuve(['date' => '2026-06-15', 'groupe_id' => null]);

        $conflits = app(ConflitEpreuveService::class)->detecter([
            'date' => '2026-06-15',
            'heure_debut' => '08:00',
            'heure_fin' => '10:00',
            'salle_id' => $salle->id,
        ], (int) $croisee->id);

        $salles = array_values(array_filter($conflits, fn (array $c) => $c['type'] === 'salle'));
        $this->assertCount(1, $salles);
        $this->assertTrue($salles[0]['critique']);
    }

    public function test_salle_capacite_negative_refusee(): void
    {
        // §3.2 item 2 : capacité négative = valeur invalide, jamais un
        // fallback artificiel (EX-01).
        $salle = $this->creerSalle(['capacite' => -7]);
        $epreuve = $this->creerEpreuve();
        $this->creerCandidat($epreuve, $this->creerEtudiant('014'));

        try {
            app(ExamRoomAssignmentService::class)->affecterSalle($epreuve, (int) $salle->id, $this->admin->id);
            $this->fail('Salle à capacité négative acceptée.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('inconnue : affectation interdite', $exception->getMessage());
        }

        $this->assertNull($epreuve->refresh()->salle_id);
    }

    public function test_place_hors_capacite_refusee_sans_ecriture(): void
    {
        // §3.2 item 7 (EX-04) : un numéro de place au-delà de la capacité
        // réelle de la salle est refusé au moment de l'application, sans
        // aucune écriture partielle.
        $service = app(ExamRoomAssignmentService::class);
        $salle = $this->creerSalle(['capacite' => 30]);
        $epreuve = $this->creerEpreuve();
        $candidat = $this->creerCandidat($epreuve, $this->creerEtudiant('015'));

        $payload = [
            ['candidat_id' => (int) $candidat->id, 'salle_id' => (int) $salle->id, 'place' => 'INV-P30-31'],
        ];

        try {
            $service->appliquerRepartition($epreuve, $payload, $this->admin->id);
            $this->fail('Place hors capacité acceptée.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('hors capacité', $exception->getMessage());
        }

        $this->assertNull($candidat->refresh()->salle_id);
        $this->assertNull($candidat->place);
        $this->assertNull($epreuve->refresh()->salle_id);

        // Une place valide dans la même salle passe toujours (01 ≤ 30).
        $payloadValide = [
            ['candidat_id' => (int) $candidat->id, 'salle_id' => (int) $salle->id, 'place' => 'INV-P30-01'],
        ];
        $this->assertSame(1, $service->appliquerRepartition($epreuve, $payloadValide, $this->admin->id));
        $this->assertSame('INV-P30-01', $candidat->fresh()->place);
    }

    // --------------------------------------------------------------- helpers

    private function creerSalle(array $attributs = []): Room
    {
        $this->seq++;

        return Room::create(array_merge([
            'code' => 'INV-S'.$this->seq,
            'nom' => 'Salle INV'.$this->seq,
            'capacite' => 30,
            'actif' => true,
        ], $attributs));
    }

    private function creerEpreuve(array $attributs = []): Epreuve
    {
        return Epreuve::create(array_merge([
            'examen_id' => $this->examen->id,
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiere->id,
            'groupe_id' => $this->groupe->id,
            'date' => '2026-06-10',
            'heure_debut' => '08:00',
            'heure_fin' => '10:00',
            'coefficient' => 1,
            'bareme' => 20,
            'note_max' => 20,
            'statut' => 'scheduled',
        ], $attributs));
    }

    private function creerEtudiant(string $suffixe): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => 'IN'.$suffixe, 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'in'.strtolower($suffixe).'@exam.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'groupe_id' => $this->groupe->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');
        $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
        ]);

        return $etudiant;
    }

    private function creerCandidat(Epreuve $epreuve, Etudiant $etudiant, array $attributs = []): Candidat
    {
        return Candidat::create(array_merge([
            'epreuve_id' => $epreuve->id,
            'etudiant_id' => $etudiant->id,
            'statut' => Candidat::STATUT_NON_POINTE,
        ], $attributs));
    }
}
