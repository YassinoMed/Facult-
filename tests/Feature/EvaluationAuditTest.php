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
use App\Modules\Evaluation\Models\NoteEpreuve;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\EvaluationAuditService;
use App\Modules\Evaluation\Services\SaisieNoteService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EvaluationAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Matiere $matiere;

    private Group $groupe;

    private Room $salle;

    private SessionExamen $session;

    private Epreuve $epreuve;

    private int $compteur = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences Test Audit',
            'code' => 'FST-AUDIT',
            'description' => 'Test Audit',
            'active' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Audit',
            'email' => 'admin.audit@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programme = Programme::create([
            'nom' => 'Licence Informatique Audit',
            'code' => 'L-INFO-AUDIT',
            'cycle' => 'licence',
            'faculte_id' => $this->faculte->id,
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
            'actif' => true,
        ]);

        $niveau = Niveau::create([
            'code' => 'L1-AUDIT',
            'nom' => 'Licence 1 Audit',
            'libelle' => 'Licence 1 Audit',
            'programme_id' => $this->programme->id,
            'ordre' => 1,
            'actif' => true,
        ]);

        $semestre = SemestreLmd::create([
            'code' => 'S1-AUDIT',
            'nom' => 'Semestre 1 Audit',
            'libelle' => 'Semestre 1 Audit',
            'numero' => 1,
            'niveau_id' => $niveau->id,
            'actif' => true,
        ]);

        $ue = UniteEnseignement::create([
            'code' => 'UE-INFO-AUDIT',
            'nom' => 'UE Informatique Audit',
            'programme_id' => $this->programme->id,
            'semestre_id' => $semestre->id,
            'credits' => 6,
            'coefficient' => 2,
            'type' => 'fondamentale',
            'actif' => true,
        ]);

        $this->matiere = Matiere::create([
            'code' => 'ALGO-AUDIT',
            'nom' => 'Algorithmique Audit',
            'ue_id' => $ue->id,
            'niveau' => 'L1',
            'semestre' => 1,
            'credits' => 3,
            'coefficient' => 1.5,
            'heures_cm' => 20,
            'heures_td' => 20,
            'heures_tp' => 10,
            'active' => true,
        ]);

        $this->groupe = Group::create([
            'nom' => 'Groupe Audit G1',
            'code' => 'GRP-AUDIT-G1',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'actif' => true,
        ]);

        $this->salle = Room::create([
            'code' => 'AMPHI-AUDIT',
            'nom' => 'Amphi Audit',
            'capacity' => 100,
            'exam_capacity' => 50,
            'active' => true,
        ]);

        $this->session = SessionExamen::create([
            'code' => 'SESS-AUDIT-01',
            'nom' => 'Session Principale Audit 2026',
            'libelle' => 'Session Principale Audit 2026',
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculte->id,
            'type' => 'normale',
            'statut' => 'open',
            'date_debut' => '2026-11-01',
            'date_fin' => '2026-11-30',
            'created_by' => $this->admin->id,
        ]);

        $examen = Examen::create([
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'libelle' => 'Examen Algorithmique Audit',
            'type' => 'examen_final',
            'statut' => 'scheduled',
            'titre' => 'Examen Algorithmique Audit',
        ]);

        $this->epreuve = Epreuve::create([
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'examen_id' => $examen->id,
            'matiere_id' => $this->matiere->id,
            'groupe_id' => $this->groupe->id,
            'salle_id' => $this->salle->id,
            'date' => '2026-11-10',
            'heure_debut' => '09:00:00',
            'heure_fin' => '11:00:00',
            'note_max' => 20.00,
            'coefficient' => 1.5,
            'type_evaluation' => 'ecrit',
            'statut' => 'draft',
        ]);
    }

    private function creerEtudiant(): Etudiant
    {
        static $compteurEtudiant = 600000;
        $suffixe = ++$compteurEtudiant;

        $etudiant = Etudiant::create([
            'cin' => 'NW'.$suffixe,
            'nom' => 'Mansouri',
            'prenom' => 'Karim',
            'email' => 'karim.'.$suffixe.'@test.ma',
            'date_naissance' => '2004-01-01',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'groupe_id' => $this->groupe->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '12 rue des Tests',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-2026-'.$suffixe])->save();
        $etudiant->definirStatut('valide');

        // LOT 05 : inscription S1 requise par la synchronisation officielle
        // de la validation (SaisieNoteService::validerNoteVerrouillee →
        // ArchiveService via inscriptionPourEpreuve + semestrePourMatiere).
        $inscription = $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1',
            'statut' => 'en_cours',
        ]);
        $inscription->semestres()->create(['numero' => 1]);

        return $etudiant;
    }

    private function saisirNote(Epreuve $epreuve, Candidat $candidat, float $valeur, ?string $motif = null): NoteEpreuve
    {
        /** @var SaisieNoteService $service */
        $service = app(SaisieNoteService::class);
        $service->saisirLot($epreuve, [[
            'etudiant_id' => $candidat->etudiant_id,
            'candidat_id' => $candidat->id,
            'valeur' => $valeur,
            'motif' => $motif ?? 'Saisie test',
        ]], (int) $this->admin->id);

        return NoteEpreuve::where('epreuve_id', $epreuve->id)
            ->where('etudiant_id', $candidat->etudiant_id)
            ->firstOrFail();
    }

    public function test_journal_global_enregistre_et_recupere_modifications_de_notes(): void
    {
        $etudiant = $this->creerEtudiant();
        $candidat = Candidat::create(['epreuve_id' => $this->epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'inscrit']);

        // 1. Première saisie : 11.00
        $this->saisirNote($this->epreuve, $candidat, 11.00, 'Première saisie enseignant');

        // 2. Modification : 14.50
        $this->saisirNote($this->epreuve, $candidat, 14.50, 'Correction après vérification barème');

        /** @var EvaluationAuditService $auditService */
        $auditService = app(EvaluationAuditService::class);
        $journal = $auditService->journalGlobal(['epreuve_id' => $this->epreuve->id], $this->admin);

        $this->assertGreaterThanOrEqual(1, $journal->total());

        $derniereRevision = $journal->first();
        $this->assertEquals(11.00, (float) $derniereRevision->ancienne_valeur);
        $this->assertEquals(14.50, (float) $derniereRevision->nouvelle_valeur);
        $this->assertStringContainsString('Correction', $derniereRevision->motif);
        $this->assertEquals($this->admin->id, $derniereRevision->auteur_id);
    }

    public function test_comparaison_differentielle_deux_versions(): void
    {
        $etudiant = $this->creerEtudiant();
        $candidat = Candidat::create(['epreuve_id' => $this->epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'inscrit']);

        $this->saisirNote($this->epreuve, $candidat, 10.00, 'Initiale');
        $this->saisirNote($this->epreuve, $candidat, 13.50, 'Seconde');
        $note = $this->saisirNote($this->epreuve, $candidat, 16.00, 'Troisieme');

        /** @var EvaluationAuditService $auditService */
        $auditService = app(EvaluationAuditService::class);
        $donnees = $auditService->historiqueNote($note, $this->admin);

        $this->assertSame(3, $donnees['total_modifications']);
        $revisions = $note->historique()->orderBy('id')->get();
        $this->assertCount(3, $revisions);

        $diff = $auditService->comparerVersions($note, $revisions[1]->id, $revisions[2]->id, $this->admin);

        $this->assertEquals(13.50, $diff['version_a']['valeur']);
        $this->assertEquals(16.00, $diff['version_b']['valeur']);
        $this->assertEquals(2.50, $diff['ecart_valeur']); // 16.00 - 13.50
    }

    public function test_valide_at_est_horodate_a_la_validation(): void
    {
        $etudiant = $this->creerEtudiant();
        $candidat = Candidat::create(['epreuve_id' => $this->epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'inscrit']);

        $note = $this->saisirNote($this->epreuve, $candidat, 12.00, 'Saisie initiale');
        $this->assertNull($note->fresh()->valide_at);

        $validee = app(SaisieNoteService::class)->valider($note->fresh(), $this->admin->id, 'Contrôle');

        $this->assertNotNull($validee->valide_at);
    }

    public function test_modification_apres_validation_sans_motif_cle_est_signalee_par_horodatage(): void
    {
        $etudiant = $this->creerEtudiant();
        $candidat = Candidat::create(['epreuve_id' => $this->epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'inscrit']);

        try {
            $this->travelTo(Carbon::parse('2026-11-11 10:00:00'));
            $note = $this->saisirNote($this->epreuve, $candidat, 11.00, 'Première saisie enseignant');
            app(SaisieNoteService::class)->valider($note->fresh(), $this->admin->id, 'Contrôle');

            $this->travelTo(Carbon::parse('2026-11-12 11:30:00'));
            // Motif volontairement SANS les mots-clés « correction » /
            // « modification » (ex. motif généré « Saisie (import) ») :
            // l'ancienne détection par chaîne produisait un faux négatif,
            // la détection temporelle voit la vraie modification postéro-
            // rieure à la dernière validation.
            $note = $this->saisirNote($this->epreuve, $candidat, 14.50, 'Saisie (import)');

            $donnees = app(EvaluationAuditService::class)->historiqueNote($note->fresh(), $this->admin);

            $this->assertTrue($donnees['a_ete_modifiee_apres_validation']);
            $this->assertSame('entered', $note->fresh()->statut);
        } finally {
            $this->travelBack();
        }
    }

    public function test_revalidation_efface_le_drapeau_de_modification_post_validation(): void
    {
        $etudiant = $this->creerEtudiant();
        $candidat = Candidat::create(['epreuve_id' => $this->epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'inscrit']);
        $service = app(SaisieNoteService::class);

        try {
            $this->travelTo(Carbon::parse('2026-11-11 10:00:00'));
            $note = $this->saisirNote($this->epreuve, $candidat, 11.00, 'Première saisie enseignant');
            $service->valider($note->fresh(), $this->admin->id, 'Contrôle');

            $this->travelTo(Carbon::parse('2026-11-12 11:30:00'));
            $note = $this->saisirNote($this->epreuve, $candidat, 14.50, 'Saisie (import)');
            $this->assertTrue(
                app(EvaluationAuditService::class)->historiqueNote($note->fresh(), $this->admin)['a_ete_modifiee_apres_validation']
            );

            $this->travelTo(Carbon::parse('2026-11-13 18:00:00'));
            $note = $service->valider($note->fresh(), $this->admin->id, 'Revalidation après correction');

            $donnees = app(EvaluationAuditService::class)->historiqueNote($note->fresh(), $this->admin);
            $this->assertFalse($donnees['a_ete_modifiee_apres_validation']);
            $this->assertSame('validated', $note->statut);
        } finally {
            $this->travelBack();
        }
    }

    public function test_audit_epreuve_statistiques(): void
    {
        $etudiant1 = $this->creerEtudiant();
        $etudiant2 = $this->creerEtudiant();
        $c1 = Candidat::create(['epreuve_id' => $this->epreuve->id, 'etudiant_id' => $etudiant1->id, 'statut' => 'inscrit']);
        $c2 = Candidat::create(['epreuve_id' => $this->epreuve->id, 'etudiant_id' => $etudiant2->id, 'statut' => 'inscrit']);

        $this->saisirNote($this->epreuve, $c1, 12.00, 'Saisie C1');
        $this->saisirNote($this->epreuve, $c1, 14.00, 'Recalcul C1');
        $this->saisirNote($this->epreuve, $c2, 10.00, 'Saisie C2');
        $this->saisirNote($this->epreuve, $c2, 11.50, 'Recalcul C2');

        /** @var EvaluationAuditService $auditService */
        $auditService = app(EvaluationAuditService::class);
        $stats = $auditService->statistiquesEpreuve($this->epreuve, $this->admin);

        $this->assertEquals(4, $stats['total_modifications']);
        $this->assertEquals(2, $stats['notes_modifiees_count']);
        $this->assertEquals(1, $stats['auteurs_distincts']);
    }

    public function test_isolation_faculte_historique_note_interdit_acces_hors_perimetre(): void
    {
        $etudiant = $this->creerEtudiant();
        $candidat = Candidat::create(['epreuve_id' => $this->epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'inscrit']);

        $note = $this->saisirNote($this->epreuve, $candidat, 12.00, 'Saisie initiale');

        $autreFaculte = Faculte::create([
            'nom' => 'Autre Faculté Audit',
            'code' => 'AUTRE-AUDIT',
            'active' => true,
        ]);
        $autreAdmin = User::creerCompteBackOffice([
            'name' => 'Autre Admin Audit',
            'email' => 'autre.audit@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $autreFaculte->id,
        ], 'admin_faculte');

        /** @var EvaluationAuditService $auditService */
        $auditService = app(EvaluationAuditService::class);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Accès refusé');

        $auditService->historiqueNote($note, $autreAdmin);
    }

    public function test_routes_http_historique_notes(): void
    {
        $this->actingAs($this->admin);

        $etudiant = $this->creerEtudiant();
        $candidat = Candidat::create(['epreuve_id' => $this->epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'inscrit']);

        $note = $this->saisirNote($this->epreuve, $candidat, 12.00, 'Saisie initiale');
        $this->saisirNote($this->epreuve, $candidat, 15.00, 'Correction test');

        $resIndex = $this->get(route('admin.evaluations.historique-notes.index'));
        $resIndex->assertOk();
        $resIndex->assertSee('Historique & Traçabilité des notes');

        $resDetail = $this->get(route('admin.evaluations.notes.historique', $note->id));
        $resDetail->assertOk();
        $resDetail->assertSee('Timeline différentielle');

        $revisions = $note->historique()->get();
        if ($revisions->count() >= 2) {
            $resCompare = $this->postJson(route('admin.evaluations.notes.comparer', $note->id), [
                'version_a' => $revisions[0]->id,
                'version_b' => $revisions[1]->id,
            ]);
            $resCompare->assertOk();
            $resCompare->assertJsonPath('succes', true);
        }
    }
}
