<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\Semestre;
use App\Models\User;
use App\Modules\Evaluation\Models\CalculationRun;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\NoteEpreuve;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\DeliberationService;
use App\Modules\Evaluation\Services\GradeCheckSuite;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Services\ArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsApiClient;
use Tests\TestCase;

/**
 * Phase 14 — Notes et délibérations (§14.4-14.7) : contrôles,
 * traçabilité des calculs, invalidation, PV, visibilité publiée.
 * Moteurs existants, sans duplication.
 */
class DeliberationPhase14Test extends TestCase
{
    use ActAsApiClient;
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Niveau $niveau;

    private Matiere $ecue1;

    private Matiere $ecue2;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.ph14@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LMI', 'nom' => 'Licence MI',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niveau = Niveau::create(['programme_id' => $this->programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $this->niveau = $niveau;
        $semestre = SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1]);
        $ue1 = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $this->programme->id,
            'code' => 'UE01', 'nom' => 'Bases', 'credits' => 6, 'coefficient' => 2,
        ]);
        $ue2 = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $this->programme->id,
            'code' => 'UE02', 'nom' => 'Outils', 'credits' => 6, 'coefficient' => 2,
        ]);
        $this->ecue1 = Matiere::create([
            'code' => 'INF101', 'nom' => 'Info', 'ue_id' => $ue1->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 6, 'coefficient' => 2,
        ]);
        $this->ecue2 = Matiere::create([
            'code' => 'INF102', 'nom' => 'Algo', 'ue_id' => $ue2->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 6, 'coefficient' => 2,
        ]);
    }

    private function creerCas(float $note1, float $note2, ?string $cin = null): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin ?? 'BKPH14'.random_int(100, 999), 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'ph14-'.uniqid().'@test.test', 'date_naissance' => '2004-01-01',
            'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X',
            'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');

        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id, 'niveau_id' => $this->niveau->id,
            'niveau' => 'L1', 'statut' => 'en_cours', 'type' => 'premiere', 'regime' => 'initiale',
        ]);
        $semestre = Semestre::create(['inscription_academique_id' => $inscription->id, 'numero' => 1]);

        Note::create([
            'etudiant_id' => $etudiant->id, 'inscription_academique_id' => $inscription->id,
            'semestre_id' => $semestre->id, 'matiere_id' => $this->ecue1->id,
            'note' => $note1, 'coefficient' => 2, 'credits' => 6, 'session' => 'normale',
        ]);
        Note::create([
            'etudiant_id' => $etudiant->id, 'inscription_academique_id' => $inscription->id,
            'semestre_id' => $semestre->id, 'matiere_id' => $this->ecue2->id,
            'note' => $note2, 'coefficient' => 2, 'credits' => 6, 'session' => 'normale',
        ]);

        return $etudiant->refresh();
    }

    private function creerDeliberation(): Deliberation
    {
        return Deliberation::create([
            'annee_universitaire_id' => $this->annee->id, 'programme_id' => $this->programme->id,
            'statut' => 'draft', 'created_by' => $this->admin->id,
        ]);
    }

    private function quorum(Deliberation $deliberation): void
    {
        $deliberation->membres()->create(['user_id' => $this->admin->id, 'fonction' => 'president', 'present' => true]);
        $deliberation->membres()->create(['fonction' => 'membre', 'present' => true]);
        $deliberation->membres()->create(['fonction' => 'membre', 'present' => true]);
    }

    public function test_controles_notes_informatifs(): void
    {
        $this->creerCas(14.0, 12.0);

        $deliberation = $this->creerDeliberation();
        $anomalies = app(GradeCheckSuite::class)->controler($deliberation);

        // Cas sain : aucune anomalie.
        $this->assertSame([], $anomalies);

        // Note hors bornes + coefficient nul : signalés, non bloquants.
        // Donnée historiquement invalide (pré-CHECK D-004) : PRAGMA
        // test-only, SQLite uniquement.
        $note = Note::firstOrFail();
        if (\DB::getDriverName() === 'sqlite') {
            \DB::statement('PRAGMA ignore_check_constraints=ON');
        }
        $note->forceFill(['note' => 25, 'coefficient' => 0])->save();
        if (\DB::getDriverName() === 'sqlite') {
            \DB::statement('PRAGMA ignore_check_constraints=OFF');
        }

        $anomalies = app(GradeCheckSuite::class)->controler($deliberation);
        $types = array_column($anomalies, 'type');

        $this->assertContains('note_hors_bornes', $types);
        $this->assertContains('coefficient_nul', $types);
        $this->assertTrue(collect($anomalies)->every(fn ($a) => $a['bloquant'] === false));
    }

    public function test_candidat_present_sans_note_signale(): void
    {
        $etudiant = $this->creerCas(14.0, 12.0);

        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id, 'code' => 'S1', 'nom' => 'Principale',
            'type' => 'principale', 'date_debut' => '2027-01-05', 'date_fin' => '2027-01-20',
            'statut' => 'open', 'created_by' => $this->admin->id,
        ]);
        $examen = Examen::create([
            'session_id' => $session->id, 'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examen', 'type' => 'examen', 'statut' => 'scheduled',
        ]);
        $groupe = Group::create([
            'code' => 'G1', 'nom' => 'Groupe 1', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'actif' => true,
        ]);
        $epreuve = Epreuve::create([
            'examen_id' => $examen->id, 'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id, 'matiere_id' => $this->ecue1->id,
            'groupe_id' => $groupe->id, 'date' => '2027-01-06',
            'heure_debut' => '08:00', 'heure_fin' => '10:00', 'statut' => 'scheduled',
        ]);
        Candidat::create(['epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'present']);

        $types = array_column(
            app(GradeCheckSuite::class)->controler($this->creerDeliberation()), 'type'
        );

        $this->assertContains('candidat_sans_note', $types);

        $candidat = Candidat::where('epreuve_id', $epreuve->id)->where('etudiant_id', $etudiant->id)->firstOrFail();

        NoteEpreuve::create([
            'epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id,
            'candidat_id' => $candidat->id,
            'valeur' => 12, 'statut' => 'validated',
        ]);

        $types = array_column(
            app(GradeCheckSuite::class)->controler($this->creerDeliberation()), 'type'
        );

        $this->assertNotContains('candidat_sans_note', $types);
    }

    public function test_preparer_fusionne_controles_et_trace_run(): void
    {
        $this->creerCas(14.0, 12.0);

        $deliberation = $this->creerDeliberation();
        app(DeliberationService::class)->preparer($deliberation);

        // Run officiel enregistré : règles + hash + résumé.
        $run = CalculationRun::where('deliberation_id', $deliberation->id)
            ->where('mode', 'officiel')->firstOrFail();
        $this->assertSame(64, strlen($run->hash_entrees));
        $this->assertSame(1, $run->resume['etudiants']);
        $this->assertArrayHasKey('version', $run->regles);

        // Simulation : run tracé, rien d'officiel écrit.
        $avant = $deliberation->decisions()->count();
        app(DeliberationService::class)->simuler($deliberation->fresh());
        $this->assertSame($avant, $deliberation->decisions()->count());
        $this->assertSame(1, CalculationRun::where('deliberation_id', $deliberation->id)
            ->where('mode', 'simulation')->count());
    }

    public function test_correction_note_invalide_puis_repreparer(): void
    {
        $etudiant = $this->creerCas(14.0, 12.0);
        $service = app(DeliberationService::class);

        $deliberation = $this->creerDeliberation();
        $service->preparer($deliberation);
        $this->quorum($deliberation);

        // Correction de note post-préparation → invalidation.
        $note = Note::firstOrFail();
        app(ArchiveService::class)->modifierNote($note, ['note' => 8.0, 'raison' => 'Erreur de report']);

        $this->assertNotNull($deliberation->fresh()->resultats_invalides_le);

        // Validation refusée tant que non re-préparée.
        try {
            $service->valider($deliberation->fresh());
            $this->fail('Validation sur résultats invalidés aurait dû être refusée.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('relancez la préparation', $e->getMessage());
        }

        // Re-préparation : invalidation levée, validation possible.
        $service->preparer($deliberation->fresh());
        $this->assertNull($deliberation->fresh()->resultats_invalides_le);

        $service->valider($deliberation->fresh());
        $this->assertSame('validated', $deliberation->fresh()->statut);

        // Verrouillée : correction ultérieure ne touche plus (garde service).
        $this->assertTrue($deliberation->fresh()->estVerrouillee());
    }

    public function test_cas_limite_signale_jury(): void
    {
        // Moyenne 10.0 pile au seuil : cas limite informatif.
        $this->creerCas(10.0, 10.0);

        $deliberation = $this->creerDeliberation();
        app(DeliberationService::class)->preparer($deliberation);

        $types = array_column($deliberation->fresh()->anomalies ?? [], 'type');

        $this->assertContains('cas_limite', $types);
        // Informatif : la validation reste possible (quorum requis).
        $this->quorum($deliberation);
        app(DeliberationService::class)->valider($deliberation->fresh());
        $this->assertSame('validated', $deliberation->fresh()->statut);
    }

    public function test_pv_pdf_et_garde_statut(): void
    {
        $this->creerCas(14.0, 12.0);

        $deliberation = $this->creerDeliberation();

        // Brouillon : PV refusé (422, jamais de PDF partiel).
        $this->actingAs($this->admin)
            ->get(route('admin.evaluations.deliberations.pv', $deliberation))
            ->assertStatus(422);

        app(DeliberationService::class)->preparer($deliberation);
        $this->quorum($deliberation);
        app(DeliberationService::class)->valider($deliberation->fresh());

        $this->actingAs($this->admin)
            ->get(route('admin.evaluations.deliberations.pv', $deliberation))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_portail_masque_non_publie(): void
    {
        $etudiant = $this->creerCas(14.0, 12.0);

        session(['etudiant_auth_id' => $etudiant->id]);

        // Sans décision publiée : page sans notes.
        $vide = $this->get(route('etudiant.notes.index'))->assertOk();
        $vide->assertDontSee('INF101', false);

        // Décision publiée : notes visibles.
        $deliberation = $this->creerDeliberation();
        app(DeliberationService::class)->preparer($deliberation);
        $this->quorum($deliberation);
        app(DeliberationService::class)->valider($deliberation->fresh());
        app(DeliberationService::class)->publier($deliberation->fresh());

        $plein = $this->get(route('etudiant.notes.index'))->assertOk();
        $plein->assertSee('INF101', false);
    }

    public function test_api_results_nuls_sans_publication(): void
    {
        $etudiant = $this->creerCas(14.0, 12.0);

        [, $token] = $this->clientApi(['grades:read']);

        // Sans publication : enveloppe stable, valeurs nulles.
        $reponse = $this->getApi(route('api.v1.students.results', $etudiant), $token)->assertOk();
        $ligne = $reponse->json('data.0');
        $this->assertNull($ligne['moyenne']);
        $this->assertNull($ligne['decision_proposee']);
        $this->assertNull($ligne['decision']);
    }
}
