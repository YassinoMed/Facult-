<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\User;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\NoteEpreuve;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\CalculEvaluationService;
use App\Modules\Evaluation\Services\ConflitEpreuveService;
use App\Modules\Evaluation\Services\DeliberationService;
use App\Modules\Evaluation\Services\EpreuveService;
use App\Modules\Evaluation\Services\SaisieNoteService;
use App\Modules\Evaluation\Services\SurveillanceService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Teacher;
use App\Services\ArchiveService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4 — Évaluation académique (§26-35) : module désactivé = 404,
 * sessions, conflits bloquants, saisie/validation/verrouillage des
 * notes, calcul UE, délibération avec anomalies bloquantes.
 */
class EvaluationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Matiere $ecue;

    private UniteEnseignement $ue;

    private Group $groupe;

    private Room $salle;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculte = Faculte::create([
            'nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true,
        ]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.eval@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LGL', 'nom' => 'Licence GL',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niveau = Niveau::create(['programme_id' => $programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $semestre = SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1]);
        $this->ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $programme->id,
            'code' => 'UE01', 'nom' => 'Algo', 'credits' => 6, 'coefficient' => 2,
        ]);
        $this->ecue = Matiere::create([
            'code' => 'A101', 'nom' => 'Algorithmique', 'ue_id' => $this->ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 6, 'coefficient' => 2,
        ]);
        $this->groupe = Group::create([
            'code' => 'G1', 'nom' => 'Groupe 1', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'actif' => true,
        ]);
        $this->salle = Room::create(['code' => 'B12', 'nom' => 'B12', 'capacite' => 40, 'actif' => true]);
    }

    public function test_module_desactive_repond_404(): void
    {
        config(['modules.evaluation.active' => false]);

        $this->actingAs($this->admin)
            ->get(route('admin.evaluations.index'))
            ->assertNotFound();
    }

    public function test_session_refuse_chevauchement_incompatible(): void
    {
        $this->actingAs($this->admin)->post(route('admin.evaluations.sessions.store'), [
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'S-PRINC',
            'nom' => 'Principale',
            'type' => 'principale',
            'date_debut' => '2027-01-05',
            'date_fin' => '2027-01-20',
        ])->assertSessionHasNoErrors();

        $principale = SessionExamen::where('code', 'S-PRINC')->firstOrFail();

        $this->actingAs($this->admin)->post(route('admin.evaluations.sessions.store'), [
            'annee_universitaire_id' => $this->annee->id,
            'session_origine_id' => $principale->id,
            'code' => 'S-CHEV',
            'nom' => 'Chevauchement',
            'type' => 'rattrapage',
            'date_debut' => '2027-01-10',
            'date_fin' => '2027-01-25',
        ])->assertSessionHasErrors('date_debut');
    }

    public function test_epreuve_hors_periode_et_conflit_salle_bloquants(): void
    {
        $session = $this->creerSession();
        $examen = $this->creerExamen($session);

        $service = app(EpreuveService::class);

        $this->expectException(DomainException::class);
        $service->creer($examen, [
            'session_id' => $session->id,
            'matiere_id' => $this->ecue->id,
            'salle_id' => $this->salle->id,
            'date' => '2027-03-01',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
        ]);
    }

    public function test_detecteur_conflit_salle_groupe_horaire(): void
    {
        $session = $this->creerSession();
        $examen = $this->creerExamen($session);
        $service = app(EpreuveService::class);

        $service->creer($examen, [
            'session_id' => $session->id,
            'matiere_id' => $this->ecue->id,
            'salle_id' => $this->salle->id,
            'groupe_id' => $this->groupe->id,
            'date' => '2027-01-06',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
        ]);

        $conflits = app(ConflitEpreuveService::class)->detecter([
            'date' => '2027-01-06',
            'heure_debut' => '10:00',
            'heure_fin' => '12:00',
            'salle_id' => $this->salle->id,
            'groupe_id' => $this->groupe->id,
        ]);

        $types = array_column($conflits, 'type');

        $this->assertContains('salle', $types);
        $this->assertContains('groupe', $types);

        $this->assertNotEmpty(app(ConflitEpreuveService::class)->bloquants($conflits));

        $horaires = app(ConflitEpreuveService::class)->detecter([
            'date' => '2027-01-06',
            'heure_debut' => '11:00',
            'heure_fin' => '09:00',
        ]);
        $this->assertSame('horaire', $horaires[0]['type']);
    }

    public function test_saisie_validee_synchronise_note_officielle_et_verrouille(): void
    {
        $session = $this->creerSession();
        $examen = $this->creerExamen($session);
        $epreuve = app(EpreuveService::class)->creer($examen, [
            'session_id' => $session->id,
            'matiere_id' => $this->ecue->id,
            'salle_id' => $this->salle->id,
            'date' => '2027-01-06',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
        ]);

        $etudiant = $this->creerEtudiantAvecInscription();

        Candidat::create(['epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'present']);

        $service = app(SaisieNoteService::class);

        $resultat = $service->saisirLot($epreuve, [
            ['etudiant_id' => $etudiant->id, 'valeur' => 14.5, 'motif' => 'Saisie'],
        ], $this->admin->id);

        $this->assertSame(1, $resultat['saisies']);
        $this->assertSame([], $resultat['erreurs']);

        $note = NoteEpreuve::where('epreuve_id', $epreuve->id)->firstOrFail();

        // Hors bornes refusé.
        $resultat = $service->saisirLot($epreuve, [
            ['etudiant_id' => $etudiant->id, 'valeur' => 99],
        ], $this->admin->id);
        $this->assertNotEmpty($resultat['erreurs']);

        $service->valider($note->fresh(), $this->admin->id, 'Contrôle');

        $this->assertSame('validated', $note->fresh()->statut);
        $this->assertNotNull($note->fresh()->note_id);
        $this->assertDatabaseHas('notes', [
            'etudiant_id' => $etudiant->id,
            'matiere_id' => $this->ecue->id,
            'note' => 14.50,
        ]);

        $service->verrouiller($note->fresh(), $this->admin->id, 'Jury');

        $this->assertTrue($note->fresh()->estVerrouillee());

        // Note verrouillée : saisie ignorée, pas d'écrasement silencieux.
        $resultat = $service->saisirLot($epreuve, [
            ['etudiant_id' => $etudiant->id, 'valeur' => 10],
        ], $this->admin->id);
        $this->assertSame(1, $resultat['ignorees']);
    }

    /**
     * Verrou officiel indépendant : une note officielle verrouillée via
     * les archives ne doit plus être réécrite par la validation d'épreuve
     * (fail-secure, invariant NotePolicy : verrouillée = rôles supérieurs).
     */
    public function test_validation_refusee_si_note_officielle_verrouillee(): void
    {
        $session = $this->creerSession();
        $examen = $this->creerExamen($session);
        $epreuve = app(EpreuveService::class)->creer($examen, [
            'session_id' => $session->id,
            'matiere_id' => $this->ecue->id,
            'date' => '2027-01-06',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
        ]);

        $etudiant = $this->creerEtudiantAvecInscription();
        Candidat::create(['epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'present']);

        $service = app(SaisieNoteService::class);
        $service->saisirLot($epreuve, [
            ['etudiant_id' => $etudiant->id, 'valeur' => 14.5],
        ], $this->admin->id);

        $note = NoteEpreuve::where('epreuve_id', $epreuve->id)->firstOrFail();
        $service->valider($note->fresh(), $this->admin->id, 'Contrôle');

        $officielle = Note::where('etudiant_id', $etudiant->id)
            ->where('matiere_id', $this->ecue->id)
            ->firstOrFail();

        // Verrou indépendant côté archives, SANS verrouiller la note
        // d'épreuve (le contournement passe par la voie épreuve).
        app(ArchiveService::class)->basculerVerrou($officielle, true);

        try {
            $service->valider($note->fresh(), $this->admin->id);
            $this->fail('La validation aurait dû refuser la note officielle verrouillée.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('verrouillée', $e->getMessage());
        }

        $this->assertTrue($officielle->fresh()->estVerrouillee());
        $this->assertEquals(14.50, (float) $officielle->fresh()->note);
    }

    public function test_candidat_absent_sans_note(): void
    {
        $session = $this->creerSession();
        $examen = $this->creerExamen($session);
        $epreuve = app(EpreuveService::class)->creer($examen, [
            'session_id' => $session->id,
            'matiere_id' => $this->ecue->id,
            'date' => '2027-01-06',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
        ]);

        $etudiant = $this->creerEtudiantAvecInscription();
        Candidat::create(['epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'absent_justifie']);

        $resultat = app(SaisieNoteService::class)->saisirLot($epreuve, [
            ['etudiant_id' => $etudiant->id, 'valeur' => 12],
        ], $this->admin->id);

        $this->assertNotEmpty($resultat['erreurs']);
        $this->assertSame(0, $resultat['saisies']);
    }

    public function test_calcul_ue_et_deliberation_bloquee_puis_validee(): void
    {
        $calculs = app(CalculEvaluationService::class);

        $resultat = $calculs->validerUe([
            ['note' => 14.0, 'coefficient' => 2.0, 'credits' => 3, 'matiere_id' => 1],
            ['note' => 11.0, 'coefficient' => 1.0, 'credits' => 3, 'matiere_id' => 2],
        ], 6, $this->annee->id);

        $this->assertTrue($resultat['validee']);
        $this->assertSame(6, $resultat['credits_obtenus']);
        $this->assertEquals(13.0, $resultat['moyenne']);

        $etudiant = $this->creerEtudiantAvecInscription();

        $session = $this->creerSession();
        $examen = $this->creerExamen($session);
        $epreuve = app(EpreuveService::class)->creer($examen, [
            'session_id' => $session->id,
            'matiere_id' => $this->ecue->id,
            'date' => '2027-01-06',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
        ]);
        Candidat::create(['epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'present']);

        $saisie = app(SaisieNoteService::class);
        $saisie->saisirLot($epreuve, [['etudiant_id' => $etudiant->id, 'valeur' => 9.0]], $this->admin->id);
        // Note saisie mais non validée → anomalie bloquante attendue.

        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $this->annee->id,
            'session_id' => $session->id,
            'statut' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        $service = app(DeliberationService::class);
        $service->preparer($deliberation);

        $this->assertNotEmpty($deliberation->fresh()->anomaliesBloquantes());

        $this->expectException(DomainException::class);
        $service->valider($deliberation->fresh());
    }

    public function test_surveillant_double_affectation_refusee(): void
    {
        $session = $this->creerSession();
        $examen = $this->creerExamen($session);
        $service = app(EpreuveService::class);

        $epreuveA = $service->creer($examen, [
            'session_id' => $session->id,
            'matiere_id' => $this->ecue->id,
            'salle_id' => $this->salle->id,
            'date' => '2027-01-06',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
        ]);

        $salleB = Room::create(['code' => 'B14', 'capacite' => 40, 'actif' => true]);
        $epreuveB = $service->creer($examen, [
            'session_id' => $session->id,
            'matiere_id' => $this->ecue->id,
            'salle_id' => $salleB->id,
            'date' => '2027-01-06',
            'heure_debut' => '09:30',
            'heure_fin' => '11:30',
        ]);

        // secondes épreuves : groupes différents pour ne tester que le surveillant.
        $epreuveB->update(['groupe_id' => null]);
        $epreuveA->update(['groupe_id' => null]);

        $enseignant = Teacher::create(['nom' => 'Haddad', 'prenom' => 'Karim', 'actif' => true, 'statut' => 'actif']);

        app(SurveillanceService::class)
            ->assigner($epreuveA, ['teacher_id' => $enseignant->id]);

        $this->expectException(DomainException::class);
        app(SurveillanceService::class)
            ->assigner($epreuveB, ['teacher_id' => $enseignant->id]);
    }

    private function creerSession(): SessionExamen
    {
        return SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'S-PRINC-'.random_int(1000, 9999),
            'nom' => 'Session principale',
            'type' => 'principale',
            'date_debut' => '2027-01-05',
            'date_fin' => '2027-01-20',
            'statut' => 'open',
            'created_by' => $this->admin->id,
        ]);
    }

    private function creerExamen(SessionExamen $session): Examen
    {
        return Examen::create([
            'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examen S1',
            'type' => 'examen',
            'statut' => 'scheduled',
            'responsable_id' => $this->admin->id,
        ]);
    }

    private function creerEtudiantAvecInscription(): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => 'EE'.random_int(100000, 999999),
            'nom' => 'Benali',
            'prenom' => 'Sara',
            'email' => 'sara.'.random_int(1000, 9999).'@test.ma',
            'date_naissance' => '2004-01-01',
            'sexe' => 'F',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '12 rue des Tests',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-2026-'.random_int(10000, 99999)])->save();
        $etudiant->definirStatut('valide');

        $inscription = $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1',
            'statut' => 'en_cours',
        ]);
        $inscription->semestres()->create(['numero' => 1]);
        $inscription->semestres()->create(['numero' => 2]);

        return $etudiant;
    }
}
