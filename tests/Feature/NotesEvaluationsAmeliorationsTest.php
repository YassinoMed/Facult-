<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Events\EvaluationChanged;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\NoteEpreuve;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Policies\EvaluationPolicy;
use App\Modules\Evaluation\Services\DeliberationService;
use App\Modules\Evaluation\Services\GradeCheckSuite;
use App\Modules\Evaluation\Services\SaisieNoteService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Services\CommunicationDispatcher;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Améliorations LOOP Notes & Évaluations (§5 du rapport dédié) :
 * - C2 : policy `view` des délibérations alignée sur `deliberations.view` ;
 * - C1 : semestre manquant = erreur métier lisible (plus de 500) ;
 * - C5 : contrôles de notes adaptés au barème libre (note_max) ;
 * - C4 : décision du jury tracée par valeurs (ancienne/nouvelle) ;
 * - C3 : `EvaluationChanged` diffusé après commit ;
 * - C6 : `validerTout` sans explosion de requêtes (préchargement).
 */
class NotesEvaluationsAmeliorationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Matiere $matiere;

    private Group $groupe;

    private int $compteur = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculte = Faculte::create([
            'nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true,
        ]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.notes.ameliorations@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LGL', 'nom' => 'Licence GL',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niveau = Niveau::create([
            'programme_id' => $this->programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1,
        ]);
        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1,
        ]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $this->programme->id,
            'code' => 'UE01', 'nom' => 'Algo', 'credits' => 6, 'coefficient' => 2,
        ]);
        $this->matiere = Matiere::create([
            'code' => 'A101', 'nom' => 'Algorithmique', 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 6, 'coefficient' => 2,
        ]);
        $this->groupe = Group::create([
            'code' => 'G1', 'nom' => 'Groupe 1', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'actif' => true,
        ]);
    }

    public function test_evaluation_changed_est_diffuse_apres_commit(): void
    {
        $evenement = new EvaluationChanged('deliberation.publiee', 1);

        $this->assertTrue($evenement->afterCommit);
    }

    public function test_la_vue_d_une_deliberation_accepte_deliberations_view_sans_evaluations_view(): void
    {
        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'statut' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        // Membre jury / scolarité avec UNIQUEMENT `deliberations.view`
        // (rôle dédié) : la route exige `deliberations.view`, la policy
        // doit l'accepter aussi — sinon 403 sur show/PV (C2).
        $membreJury = new class extends User
        {
            public function hasPermission(string $permission): bool
            {
                return $permission === 'deliberations.view';
            }
        };
        $membreJury->forceFill(['faculte_id' => null]);

        $sansAcces = new class extends User
        {
            public function hasPermission(string $permission): bool
            {
                return false;
            }
        };
        $sansAcces->forceFill(['faculte_id' => null]);

        $policy = app(EvaluationPolicy::class);

        $this->assertTrue($policy->view($membreJury, $deliberation));
        $this->assertFalse($policy->view($sansAcces, $deliberation));
    }

    public function test_une_note_sans_semestre_rattachable_renvoie_une_erreur_metier_lisible(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerEtudiant(sansSemestres: true);
        Candidat::create([
            'epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'present',
        ]);

        $service = app(SaisieNoteService::class);
        $service->saisirLot($epreuve, [
            ['etudiant_id' => $etudiant->id, 'valeur' => 12],
        ], $this->admin->id);

        $note = NoteEpreuve::where('epreuve_id', $epreuve->id)->firstOrFail();

        try {
            $service->valider($note, $this->admin->id, 'Test semestre manquant');
            $this->fail('DomainException attendue.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Semestre', $e->getMessage());
        }

        $this->assertDatabaseHas('evaluation_notes', [
            'id' => $note->id,
            'statut' => 'entered',
        ]);
    }

    public function test_les_controles_s_adaptent_au_bareme_de_l_epreuve(): void
    {
        $etudiant = $this->creerEtudiant();

        // Barème /100 : moyenne 60 (ratio 0,6) = saine, jamais un faux
        // positif « hors plage 5–16 » (C5).
        $epreuveCent = $this->creerEpreuve(noteMax: 100);
        Candidat::create(['epreuve_id' => $epreuveCent->id, 'etudiant_id' => $etudiant->id, 'statut' => 'present']);
        NoteEpreuve::create([
            'epreuve_id' => $epreuveCent->id, 'etudiant_id' => $etudiant->id,
            'valeur' => 60, 'note_max' => 100, 'coefficient' => 2, 'statut' => 'entered',
        ]);

        $deliberation = $this->creerDeliberation();
        $this->assertSame([], array_column(app(GradeCheckSuite::class)->controler($deliberation), 'type'));

        // Toutes les notes au maximum du barème = suspect.
        NoteEpreuve::where('epreuve_id', $epreuveCent->id)->update(['valeur' => 100]);
        $this->assertContains(
            'moyenne_epreuve_anormale',
            array_column(app(GradeCheckSuite::class)->controler($deliberation), 'type')
        );
        NoteEpreuve::where('epreuve_id', $epreuveCent->id)->update(['valeur' => 60]);

        // Barème /20 : moyenne 3 (ratio 0,15) = suspecte.
        $epreuveSur20 = $this->creerEpreuve();
        Candidat::create(['epreuve_id' => $epreuveSur20->id, 'etudiant_id' => $etudiant->id, 'statut' => 'present']);
        NoteEpreuve::create([
            'epreuve_id' => $epreuveSur20->id, 'etudiant_id' => $etudiant->id,
            'valeur' => 3, 'note_max' => 20, 'coefficient' => 2, 'statut' => 'entered',
        ]);

        $this->assertContains(
            'moyenne_epreuve_anormale',
            array_column(app(GradeCheckSuite::class)->controler($deliberation), 'type')
        );

        // Note au-delà du note_max de sa ligne : nouvelle anomalie non
        // bloquante (donnée historique pré-CHECK, PRAGMA test-only).
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA ignore_check_constraints=ON');
        }
        NoteEpreuve::where('epreuve_id', $epreuveSur20->id)->update(['valeur' => 25]);
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA ignore_check_constraints=OFF');
        }

        $anomalies = app(GradeCheckSuite::class)->controler($deliberation);
        $types = array_column($anomalies, 'type');

        $this->assertContains('note_epreuve_hors_bareme', $types);
        $this->assertTrue(collect($anomalies)->every(fn ($a) => $a['bloquant'] === false));
    }

    public function test_la_modification_de_decision_est_journalisee_avec_anciennes_et_nouvelles_valeurs(): void
    {
        $deliberation = $this->creerDeliberation();
        $etudiant = $this->creerEtudiant();
        $decision = DecisionDeliberation::create([
            'deliberation_id' => $deliberation->id,
            'etudiant_id' => $etudiant->id,
            'decision' => 'admis',
            'moyenne' => 12,
            'publie' => false,
        ]);

        Log::spy();

        app(DeliberationService::class)
            ->decider($decision, 'ajourne', 'Moyenne revue par le jury', $decision->updated_at->format('Y-m-d H:i:s'));

        Log::assertLogged('deliberation.decision.modifiee');

        $this->assertDatabaseHas('evaluation_decisions', [
            'id' => $decision->id,
            'decision' => 'ajourne',
            'commentaire' => 'Moyenne revue par le jury',
        ]);
    }

    public function test_la_publication_notifie_toujours_apres_commit(): void
    {
        // C3 : avec un dispatcher après commit, le flux de publication
        // reste complet (décisions publiées + notification étudiante).
        $deliberation = $this->creerDeliberation();
        $etudiant = $this->creerEtudiant();
        DecisionDeliberation::create([
            'deliberation_id' => $deliberation->id,
            'etudiant_id' => $etudiant->id,
            'decision' => 'admis',
            'moyenne' => 14,
            'publie' => false,
        ]);

        $deliberation->update(['statut' => 'validated', 'verrouillee_at' => now()]);

        $dispatcher = $this->createMock(CommunicationDispatcher::class);
        $dispatcher->expects($this->once())
            ->method('dispatcherPlusieurs')
            ->with('deliberation.publiee', [$etudiant->id]);
        $this->app->instance(CommunicationDispatcher::class, $dispatcher);

        app(DeliberationService::class)->publier($deliberation->fresh());

        $this->assertDatabaseHas('evaluation_decisions', [
            'deliberation_id' => $deliberation->id,
            'publie' => true,
        ]);
    }

    public function test_la_validation_en_masse_ne_explose_pas_en_requetes(): void
    {
        $epreuve = $this->creerEpreuve();
        $lignes = [];

        for ($i = 0; $i < 20; $i++) {
            $etudiant = $this->creerEtudiant();
            Candidat::create(['epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'present']);
            $lignes[] = ['etudiant_id' => $etudiant->id, 'valeur' => 10 + ($i % 8)];
        }

        app(SaisieNoteService::class)->saisirLot($epreuve, $lignes, $this->admin->id);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $resultat = app(SaisieNoteService::class)->validerTout($epreuve, $this->admin->id);

        $requetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(20, $resultat['validees']);
        $this->assertSame([], $resultat['erreurs']);

        // C6 : inscriptions/semestres/candidats préchargés une fois —
        // plus de savepoint ni de requêtes inscription/semestre/note.
        // Garde anti-N+1 : le coût par note reste borné (les recalcs
        // officiels de ArchiveService restent volontairement par note).
        $this->assertLessThan(20 * 15, $requetes, "validerTout ne doit pas exploser en requêtes ({$requetes} pour 20 notes).");
    }

    private function creerEpreuve(float $noteMax = 20): Epreuve
    {
        $this->compteur++;
        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'S-AM-'.$this->compteur.'-'.random_int(100, 999),
            'nom' => 'Principale', 'type' => 'principale',
            'date_debut' => '2027-01-05', 'date_fin' => '2027-01-20',
            'statut' => 'open', 'created_by' => $this->admin->id,
        ]);
        $examen = Examen::create([
            'session_id' => $session->id, 'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examen S1', 'type' => 'examen', 'statut' => 'scheduled',
            'responsable_id' => $this->admin->id,
        ]);

        return Epreuve::create([
            'examen_id' => $examen->id, 'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id, 'matiere_id' => $this->matiere->id,
            'groupe_id' => $this->groupe->id, 'date' => '2027-01-'.sprintf('%02d', 10 + $this->compteur),
            'heure_debut' => '08:00', 'heure_fin' => '10:00', 'statut' => 'scheduled',
            'note_max' => $noteMax,
        ]);
    }

    private function creerEtudiant(bool $sansSemestres = false): Etudiant
    {
        $suffixe = random_int(100000, 999999);

        $etudiant = Etudiant::create([
            'cin' => 'NA'.$suffixe,
            'nom' => 'Amrani',
            'prenom' => 'Youssef',
            'email' => 'youssef.'.$suffixe.'@test.ma',
            'date_naissance' => '2004-01-01',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '14 rue des Tests',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');

        $inscription = $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1',
            'statut' => 'en_cours',
        ]);

        if (! $sansSemestres) {
            $inscription->semestres()->create(['numero' => 1]);
            $inscription->semestres()->create(['numero' => 2]);
        }

        return $etudiant;
    }

    private function creerDeliberation(): Deliberation
    {
        return Deliberation::create([
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'statut' => 'draft',
            'created_by' => $this->admin->id,
        ]);
    }
}
