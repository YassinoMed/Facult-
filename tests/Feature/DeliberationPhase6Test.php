<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\AppNotification;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\Semestre;
use App\Models\User;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Evaluation\Models\RegleEvaluation;
use App\Modules\Evaluation\Services\CalculEvaluationService;
use App\Modules\Evaluation\Services\DeliberationService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Services\AcademicProgressionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 6 — Délibération + progression (§8, §12, §30, §35-36) :
 * règles versionnées, compensation load-bearing, workflow verrouillé,
 * redoublement explicite. Moteurs existants, sans duplication.
 */
class DeliberationPhase6Test extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Niveau $niveau;

    private UniteEnseignement $ue1;

    private UniteEnseignement $ue2;

    private Matiere $ecue1;

    private Matiere $ecue2;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.ph6@universite.test', 'password' => 'Motdepasse@123',
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
        $this->ue1 = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $this->programme->id,
            'code' => 'UE01', 'nom' => 'Bases', 'credits' => 6, 'coefficient' => 2,
        ]);
        $this->ue2 = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $this->programme->id,
            'code' => 'UE02', 'nom' => 'Outils', 'credits' => 6, 'coefficient' => 2,
        ]);
        $this->ecue1 = Matiere::create([
            'code' => 'INF101', 'nom' => 'Info', 'ue_id' => $this->ue1->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 6, 'coefficient' => 2,
        ]);
        $this->ecue2 = Matiere::create([
            'code' => 'INF102', 'nom' => 'Algo', 'ue_id' => $this->ue2->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 6, 'coefficient' => 2,
        ]);
    }

    public function test_compensation_semestre_est_load_bearing(): void
    {
        // UE1 validée (14), UE2 non validée (9) : moyenne 11.5 ≥ 10,
        // crédits 6/12 incomplets.
        $etudiant = $this->creerCas(14.0, 9.0);

        $synthese = app(CalculEvaluationService::class)
            ->syntheseEtudiant($etudiant, $this->annee->id, $this->programme->id);

        // Socle (compensation active) : admis avec compensation.
        $this->assertSame('admis_compensation', $synthese['decision_proposee']);

        // Règlement sans compensation semestrielle : ajourné.
        RegleEvaluation::create([
            'annee_universitaire_id' => $this->annee->id, 'programme_id' => null,
            'seuil_validation' => 10, 'seuil_rattrapage' => 8,
            'compensation_ecue' => true, 'compensation_ue' => true,
            'compensation_semestre' => false, 'arrondi' => '2dec',
            'version' => 1, 'actif' => true,
        ]);

        $synthese = app(CalculEvaluationService::class)
            ->syntheseEtudiant($etudiant, $this->annee->id, $this->programme->id);

        $this->assertSame('ajourne', $synthese['decision_proposee']);
    }

    public function test_regles_crud_versionne(): void
    {
        $this->actingAs($this->admin)->post(route('admin.evaluations.regles.store'), [
            'annee_universitaire_id' => $this->annee->id,
            'seuil_validation' => 10, 'seuil_rattrapage' => 8, 'arrondi' => '2dec',
        ])->assertRedirect();

        $this->actingAs($this->admin)->post(route('admin.evaluations.regles.store'), [
            'annee_universitaire_id' => $this->annee->id,
            'seuil_validation' => 12, 'seuil_rattrapage' => 9, 'arrondi' => '1dec',
        ])->assertRedirect();

        // Deux versions coexistantes, historique jamais réécrit.
        $this->assertSame([1, 2], RegleEvaluation::orderBy('version')->pluck('version')->all());

        $v1 = RegleEvaluation::where('version', 1)->firstOrFail();

        // Active : suppression refusée.
        $this->actingAs($this->admin)->delete(route('admin.evaluations.regles.destroy', $v1))
            ->assertSessionHasErrors('regle');

        // Bascule puis suppression de l'inactive : OK.
        $this->actingAs($this->admin)->patch(route('admin.evaluations.regles.bascule', $v1))->assertRedirect();
        $this->assertFalse($v1->fresh()->actif);
        $this->actingAs($this->admin)->delete(route('admin.evaluations.regles.destroy', $v1))->assertRedirect();
        $this->assertNull(RegleEvaluation::find($v1->id));
    }

    public function test_workflow_publier_notifie_et_verrouille(): void
    {
        $etudiant = $this->creerCas(14.0, 12.0);
        $service = app(DeliberationService::class);

        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $this->annee->id, 'programme_id' => $this->programme->id,
            'statut' => 'draft', 'created_by' => $this->admin->id,
        ]);

        $service->preparer($deliberation);
        $this->assertSame('prepared', $deliberation->fresh()->statut);

        // Re-préparation idempotente : mêmes décisions, pas de doublons.
        $service->preparer($deliberation->fresh());
        $this->assertSame(1, $deliberation->decisions()->count());

        $decision = $deliberation->decisions()->firstOrFail();
        $this->assertSame('admis', $decision->decision);

        // Optimistic locking : horodatage périmé refusé.
        try {
            $service->decider($decision, 'ajourne', null, '2000-01-01 00:00:00');
            $this->fail('Décision concurrente aurait dû être refusée.');
        } catch (\DomainException) {
            $this->assertSame('admis', $decision->fresh()->decision);
        }

        // Quorum puis validation.
        try {
            $service->valider($deliberation->fresh());
            $this->fail('Sans jury, la validation aurait dû échouer.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Quorum', $e->getMessage());
        }

        $deliberation->membres()->create(['user_id' => $this->admin->id, 'fonction' => 'president', 'present' => true]);
        $deliberation->membres()->create(['fonction' => 'membre', 'present' => true]);
        $deliberation->membres()->create(['fonction' => 'membre', 'present' => true]);

        $service->valider($deliberation->fresh());
        $this->assertSame('validated', $deliberation->fresh()->statut);

        // Double validation : refusée (verrou pessimiste + transition).
        try {
            $service->valider($deliberation->fresh());
            $this->fail('Double validation aurait dû être refusée.');
        } catch (\DomainException) {
            $this->assertSame('validated', $deliberation->fresh()->statut);
        }

        $service->publier($deliberation->fresh());

        $this->assertSame('published', $deliberation->fresh()->statut);
        $this->assertTrue($deliberation->decisions()->where('publie', true)->exists());
        $this->assertTrue(AppNotification::where('type', 'deliberation.publiee')->exists());

        // Immuabilité post-publication.
        try {
            $service->preparer($deliberation->fresh());
            $this->fail('Préparation post-publication aurait dû être refusée.');
        } catch (\DomainException) {
            $this->assertTrue(true);
        }

        try {
            $service->decider($decision->fresh(), 'ajourne', null, $decision->fresh()->updated_at->toJSON());
            $this->fail('Décision post-publication aurait dû être refusée.');
        } catch (\DomainException) {
            $this->assertSame('admis', $decision->fresh()->decision);
        }
    }

    public function test_redoublement_explicite_lie_decision(): void
    {
        // Année suivante inactive (la garde LOT 2 interdit deux années
        // actives ; `appliquer()` la résout par les dates, pas par `active`).
        AnneeUniversitaire::create([
            'libelle' => '2027-2028', 'date_debut' => '2027-09-01', 'date_fin' => '2028-06-30', 'active' => false,
        ]);

        $etudiant = $this->creerCas(7.0, 6.0);
        $inscription = $etudiant->inscriptionsAcademiques()->firstOrFail();

        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $this->annee->id, 'statut' => 'published',
            'created_by' => $this->admin->id, 'published_at' => now(),
        ]);
        DecisionDeliberation::create([
            'deliberation_id' => $deliberation->id, 'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'moyenne' => 6.5, 'credits_obtenus' => 0, 'credits_total' => 12,
            'decision' => 'ajourne', 'publie' => true,
        ]);

        $nouvelle = app(AcademicProgressionService::class)->appliquer($etudiant, $inscription, $this->admin->id);

        $this->assertSame('reinscription', $nouvelle->type);
        $this->assertSame('L1', $nouvelle->niveau);
        $this->assertStringContainsString('Redoublement', (string) $nouvelle->observation);

        // Idempotence : rejeu → même inscription.
        $rejeu = app(AcademicProgressionService::class)->appliquer($etudiant, $inscription, $this->admin->id);
        $this->assertSame($nouvelle->id, $rejeu->id);
    }

    private function creerCas(float $note1, float $note2): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => 'BKPH6'.random_int(100, 999), 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'ph6-'.uniqid().'@test.test', 'date_naissance' => '2004-01-01',
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
}
