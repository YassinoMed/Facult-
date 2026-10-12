<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionPedagogique;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Announcements\Models\Campagne;
use App\Modules\Announcements\Services\CampagneService;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\NoteEpreuve;
use App\Modules\Evaluation\Models\NoteEpreuveHistorique;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\DeliberationService;
use App\Modules\Evaluation\Services\SaisieNoteService;
use App\Modules\Evaluation\Services\SessionExamenService;
use App\Modules\Evaluation\Services\SessionPreparationService;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Teacher;
use App\Services\DataExchange\Definitions\NoteImportDefinition;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phases 13-14-15 — Examens intelligents, notes/délibérations,
 * communication : préparation session, simulation, correction,
 * jury, campagnes, planification.
 */
class ExamensCommunicationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.evaluation.active' => true,
            'modules.announcements.active' => true,
            'modules.schedule.active' => true,
        ]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.ec@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
    }

    public function test_session_ouverture_bloquee_sans_preparation(): void
    {
        $session = $this->creerSession();

        $readiness = app(SessionExamenService::class)->readiness($session);
        $this->assertNotEmpty($readiness['bloquants']);

        $this->expectException(\DomainException::class);
        app(SessionExamenService::class)->transiter($session, 'open');
    }

    public function test_generation_epreuves_idempotente_depuis_maquette(): void
    {
        [$programme] = $this->creerMaquette();
        $session = $this->creerSession();

        $service = app(SessionPreparationService::class);
        $premier = $service->genererEpreuves($session, $programme->id, 'L1', '2027-01-06');
        $second = $service->genererEpreuves($session, $programme->id, 'L1', '2027-01-06');

        // 2 ECUE × 2 groupes = 4 épreuves, seconde passe sans doublon.
        $this->assertSame(4, $premier['creees']);
        $this->assertSame(0, $second['creees']);
        $this->assertSame(4, Epreuve::where('session_id', $session->id)->count());
    }

    public function test_salles_suggerees_capacite_et_conflits(): void
    {
        [$programme] = $this->creerMaquette();
        $session = $this->creerSession();

        app(SessionPreparationService::class)->genererEpreuves($session, $programme->id, 'L1', '2027-01-06');
        $epreuve = Epreuve::where('session_id', $session->id)->firstOrFail();

        // 60 candidats : seule la grande salle convient.
        Room::create(['code' => 'P1', 'capacite' => 30, 'actif' => true]);
        Room::create(['code' => 'G1', 'capacite' => 200, 'actif' => true]);
        $epreuve->update(['capacite_necessaire' => 60]);

        $suggestions = app(SessionPreparationService::class)->suggererSalles($epreuve);

        $this->assertSame(['G1'], array_column($suggestions, 'code'));
    }

    public function test_surveillants_auto_equite_et_conflits(): void
    {
        [$programme] = $this->creerMaquette();
        $session = $this->creerSession();

        app(SessionPreparationService::class)->genererEpreuves($session, $programme->id, 'L1', '2027-01-06');
        $epreuves = Epreuve::where('session_id', $session->id)->orderBy('id')->get();
        $this->assertGreaterThanOrEqual(2, $epreuves->count());

        foreach (['A', 'B', 'C'] as $nom) {
            Teacher::create(['nom' => $nom, 'prenom' => 'Ens', 'actif' => true, 'statut' => 'actif']);
        }

        $service = app(SessionPreparationService::class);
        $service->affecterSurveillantsAuto($epreuves[0], 2);
        // Même jour/même heure (autre groupe) : A et B en conflit, C affecté.
        $resultat = $service->affecterSurveillantsAuto($epreuves[1], 2);

        $this->assertSame(1, $resultat['assignes']);
        $this->assertSame(2, $resultat['ignores']);
        $noms = $epreuves[1]->surveillants()->with('enseignant')->get()
            ->map(fn ($s) => $s->enseignant->nom)->all();
        $this->assertSame(['C'], $noms);

        // Jour suivant : tous disponibles, les moins chargés d'abord.
        $resultat = $service->affecterSurveillantsAuto($epreuves[2], 2);
        $this->assertSame(2, $resultat['assignes']);
    }

    public function test_convocation_pdf_telechargeable(): void
    {
        [$programme] = $this->creerMaquette();
        $session = $this->creerSession();

        app(SessionPreparationService::class)->genererEpreuves($session, $programme->id, 'L1', '2027-01-06');
        $epreuve = Epreuve::where('session_id', $session->id)->firstOrFail();

        $this->actingAs($this->admin)
            ->get(route('admin.evaluations.epreuves.convocation', $epreuve))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_simulation_necrit_rien(): void
    {
        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $this->annee->id, 'statut' => 'draft', 'created_by' => $this->admin->id,
        ]);

        $simulation = app(DeliberationService::class)->simuler($deliberation);

        $this->assertArrayHasKey('decisions', $simulation);
        $this->assertSame('draft', $deliberation->fresh()->statut);
        $this->assertSame(0, DecisionDeliberation::where('deliberation_id', $deliberation->id)->count());
    }

    public function test_correction_verrouillee_workflow_complet(): void
    {
        [$programme] = $this->creerMaquette();
        $session = $this->creerSession();

        app(SessionPreparationService::class)->genererEpreuves($session, $programme->id, 'L1', '2027-01-06');
        $epreuve = Epreuve::where('session_id', $session->id)->firstOrFail();

        $etudiant = $this->creerEtudiant($epreuve);
        Candidat::create([
            'epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'present',
        ]);

        $service = app(SaisieNoteService::class);
        $service->saisirLot($epreuve, [['etudiant_id' => $etudiant->id, 'valeur' => 10]], $this->admin->id);
        $note = NoteEpreuve::where('epreuve_id', $epreuve->id)->firstOrFail();
        $service->valider($note->fresh(), $this->admin->id);
        $service->verrouiller($note->fresh(), $this->admin->id, 'Jury');

        // Saisie directe refusée sur verrouillée.
        $resultat = $service->saisirLot($epreuve, [['etudiant_id' => $etudiant->id, 'valeur' => 15]], $this->admin->id);
        $this->assertSame(1, $resultat['ignorees']);

        // Demande + autorisation + re-saisie + re-validation.
        $service->demanderCorrection($note->fresh(), $this->admin->id, 'Erreur de report');
        $this->assertSame('locked', $note->fresh()->statut);

        $service->autoriserCorrection($note->fresh(), $this->admin->id, 'Vérifié avec la copie');
        $this->assertSame('correction_demandee', $note->fresh()->statut);

        $service->saisirLot($epreuve, [['etudiant_id' => $etudiant->id, 'valeur' => 15, 'motif' => 'Correction autorisée']], $this->admin->id);
        $service->valider($note->fresh(), $this->admin->id);

        $this->assertEquals(15, (float) $note->fresh()->valeur);
        $this->assertTrue(
            NoteEpreuveHistorique::where('evaluation_note_id', $note->id)->where('motif', 'like', '%Correction autorisée%')->exists()
        );
    }

    public function test_quorum_jury_requis_a_la_validation(): void
    {
        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $this->annee->id, 'statut' => 'prepared', 'created_by' => $this->admin->id,
        ]);

        try {
            app(DeliberationService::class)->valider($deliberation);
            $this->fail('Sans jury, la validation aurait dû échouer.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Quorum', $e->getMessage());
        }

        $deliberation->membres()->create(['user_id' => $this->admin->id, 'fonction' => 'president', 'present' => true]);
        $deliberation->membres()->create(['fonction' => 'membre', 'present' => true]);
        $deliberation->membres()->create(['fonction' => 'membre', 'present' => true]);

        // Plus de blocage quorum : reste le blocage décisions à examiner.
        try {
            app(DeliberationService::class)->valider($deliberation->fresh());
        } catch (\DomainException $e) {
            $this->assertStringNotContainsString('Quorum', $e->getMessage());
        }
    }

    public function test_import_notes_dataexchange(): void
    {
        [$programme] = $this->creerMaquette();
        $etudiant = $this->creerEtudiant(null, 'EE140001');

        $definition = app(NoteImportDefinition::class);

        $this->assertSame('notes', $definition->entite());

        $matiere = Matiere::orderBy('id')->firstOrFail();

        $message = $definition->persister([
            'cin' => 'EE140001',
            'code_matiere' => $matiere->code,
            'annee' => '2026-2027',
            'semestre' => $matiere->semestre,
            'note' => 14,
            'session' => 'normale',
        ], 'upsert', $this->admin->id);

        $this->assertStringContainsString('créée', $message);
        $this->assertDatabaseHas('notes', ['etudiant_id' => $etudiant->id, 'matiere_id' => $matiere->id]);

        // Idempotence en mode creer.
        $message = $definition->persister([
            'cin' => 'EE140001',
            'code_matiere' => $matiere->code,
            'annee' => '2026-2027',
            'semestre' => $matiere->semestre,
            'note' => 14,
            'session' => 'normale',
        ], 'creer', $this->admin->id);

        $this->assertStringContainsString('doublon', $message);
    }

    public function test_campagne_envoi_idempotent_et_stats(): void
    {
        $this->creerEtudiant(null, 'EE150001');
        $this->creerEtudiant(null, 'EE150002');

        $service = app(CampagneService::class);
        $campagne = Campagne::create([
            'titre' => 'Rentrée', 'message' => 'Bienvenue', 'audience' => 'etudiants',
            'faculte_id' => $this->faculte->id, 'statut' => 'brouillon',
            'cle_unique' => $service->nouvelleCle(), 'created_by' => $this->admin->id,
        ]);

        $this->assertSame(2, $service->estimer($campagne));
        $service->envoyer($campagne);

        $this->assertSame(2, $campagne->fresh()->envoyes);

        // Relance : statut envoyé → refus (pas de doublon).
        $this->expectException(\DomainException::class);
        $service->envoyer($campagne->fresh());
    }

    public function test_priorite_notification_persistee(): void
    {
        $notification = app(NotificationService::class)->notifierUser(
            $this->admin, 'test.prio', 'Titre', 'Message', null, [], 2
        );

        $this->assertSame(2, $notification->fresh()->priorite);
    }

    public function test_centre_notifications_filtres(): void
    {
        app(NotificationService::class)->notifierUser($this->admin, 'info.basse', 'Basse', 'x', null, [], 0);
        app(NotificationService::class)->notifierUser($this->admin, 'info.haute', 'Haute', 'x', null, [], 3);

        $reponse = $this->actingAs($this->admin)->get(route('admin.notifications.index', ['filtre' => 'importantes']));
        $reponse->assertOk();
        $titres = $reponse->viewData('notifications')->pluck('titre')->all();
        $this->assertContains('Haute', $titres);
        $this->assertNotContains('Basse', $titres);
    }

    private function creerSession(): SessionExamen
    {
        return SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id, 'code' => 'S-'.random_int(1000, 9999),
            'nom' => 'Session', 'type' => 'principale',
            'date_debut' => '2027-01-05', 'date_fin' => '2027-01-30',
            'statut' => 'draft', 'created_by' => $this->admin->id,
        ]);
    }

    /** @return array{0: Programme} */
    private function creerMaquette(): array
    {
        $programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LP'.random_int(100, 999), 'nom' => 'Licence',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niveau = Niveau::create([
            'programme_id' => $programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1,
        ]);
        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1,
        ]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $programme->id,
            'code' => 'UE'.random_int(100, 999), 'nom' => 'UE', 'credits' => 12, 'coefficient' => 2,
        ]);
        $maquette = Maquette::create([
            'programme_id' => $programme->id, 'annee_universitaire_id' => $this->annee->id,
            'version' => 1, 'statut' => 'active',
        ]);
        $maquette->unitesEnseignement()->attach($ue->id, ['semestre_id' => $semestre->id]);

        foreach (['E1', 'E2'] as $code) {
            Matiere::create([
                'code' => $code.random_int(100, 999), 'nom' => 'ECUE '.$code, 'ue_id' => $ue->id,
                'niveau' => 'L1', 'semestre' => 1, 'credits' => 6, 'coefficient' => 1, 'actif' => true,
            ]);
        }

        foreach (['GA', 'GB'] as $code) {
            Group::create([
                'code' => $code.random_int(100, 999), 'nom' => 'Groupe', 'faculte_id' => $this->faculte->id,
                'programme_id' => $programme->id, 'niveau' => 'L1', 'actif' => true,
            ]);
        }

        return [$programme];
    }

    private function creerEtudiant(?Epreuve $epreuve = null, string $cin = 'EE140002'): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin === 'EE140002' ? 'EE'.random_int(100000, 999999) : $cin,
            'nom' => 'Benali', 'prenom' => 'Sara', 'email' => 'sara.'.random_int(1000, 9999).'@ec.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');

        $inscription = $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
        ]);
        $inscription->semestres()->create(['numero' => 1]);
        $inscription->semestres()->create(['numero' => 2]);

        if ($epreuve !== null) {
            InscriptionPedagogique::create([
                'inscription_id' => $inscription->id,
                'semestre_id' => $epreuve->matiere->ue->semestre_id,
                'ue_id' => $epreuve->matiere->ue_id,
                'matiere_id' => $epreuve->matiere_id,
                'statut' => 'inscrit',
            ]);
        }

        return $etudiant;
    }
}
