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

/**
 * LOOP-05 — vérification indépendante du carnet de notes et des délibérations.
 *
 * Ce fichier ne remplace pas `EvaluationAuditTest` : il prouve les
 * invariants sensibles (horodatage de validation, drapeau temporel,
 * bornes de saisie, convocation, périmètre faculté, verrouillage) et
 * sert de non-régression au lot.
 */
class Loop05NotesDeliberationsVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private Faculte $autreFaculte;

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
            'nom' => 'Faculté Vérification LOOP-05',
            'code' => 'FAC-VL05',
            'description' => 'Vérification LOOP-05',
            'active' => true,
        ]);
        $this->autreFaculte = Faculte::create([
            'nom' => 'Faculté Hors Périmètre LOOP-05',
            'code' => 'FAC-VL05-B',
            'description' => 'Hors périmètre',
            'active' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité Vérification LOOP-05',
            'email' => 'loop05.verification@universite.test',
            'password' => 'MotDePasse123!',
            'faculte_id' => $this->faculte->id,
        ], 'admin_scolarite');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027 Vérification LOOP-05',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programme = Programme::create([
            'nom' => 'Licence Vérification LOOP-05',
            'code' => 'L-VL05',
            'cycle' => 'licence',
            'faculte_id' => $this->faculte->id,
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
            'actif' => true,
        ]);

        $niveau = Niveau::create([
            'code' => 'L1-VL05',
            'nom' => 'Licence 1 Vérification LOOP-05',
            'libelle' => 'Licence 1 Vérification LOOP-05',
            'programme_id' => $this->programme->id,
            'ordre' => 1,
            'actif' => true,
        ]);

        $semestre = SemestreLmd::create([
            'code' => 'S1-VL05',
            'nom' => 'Semestre 1 Vérification LOOP-05',
            'libelle' => 'Semestre 1 Vérification LOOP-05',
            'numero' => 1,
            'niveau_id' => $niveau->id,
            'actif' => true,
        ]);

        $ue = UniteEnseignement::create([
            'code' => 'UE-VL05',
            'nom' => 'UE Vérification LOOP-05',
            'programme_id' => $this->programme->id,
            'semestre_id' => $semestre->id,
            'credits' => 6,
            'coefficient' => 2,
            'type' => 'fondamentale',
            'actif' => true,
        ]);

        $this->matiere = Matiere::create([
            'code' => 'MAT-VL05',
            'nom' => 'Matière Vérification LOOP-05',
            'ue_id' => $ue->id,
            'niveau' => 'L1',
            'semestre' => 1,
            'credits' => 3,
            'coefficient' => 1.5,
            'active' => true,
        ]);

        $this->groupe = Group::create([
            'nom' => 'Groupe Vérification LOOP-05',
            'code' => 'GRP-VL05',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'actif' => true,
        ]);

        $this->salle = Room::create([
            'code' => 'SALLE-VL05',
            'nom' => 'Salle Vérification LOOP-05',
            'capacity' => 100,
            'active' => true,
        ]);

        $this->session = SessionExamen::create([
            'code' => 'SESS-VL05',
            'nom' => 'Session Vérification LOOP-05',
            'libelle' => 'Session Vérification LOOP-05',
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
            'libelle' => 'Examen Vérification LOOP-05',
            'type' => 'examen_final',
            'statut' => 'scheduled',
            'titre' => 'Examen Vérification LOOP-05',
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

    public function test_validation_horodatee_et_modification_posterieure_signalee(): void
    {
        $candidat = $this->creerCandidat();
        $service = app(SaisieNoteService::class);
        $audit = app(EvaluationAuditService::class);

        try {
            $this->travelTo(Carbon::parse('2026-11-11 10:00:00'));
            $note = $this->saisirNote($candidat, 11.0, 'Saisie initiale');
            $this->assertNull($note->fresh()->valide_at);

            $validee = $service->valider($note->fresh(), $this->admin->id, 'Contrôle');
            $this->assertNotNull($validee->fresh()->valide_at);
            $this->assertSame('validated', $validee->fresh()->statut);

            $this->travelTo(Carbon::parse('2026-11-12 11:30:00'));
            $modifiee = $this->saisirNote($candidat, 14.5, 'Saisie (import)');

            $donnees = $audit->historiqueNote($modifiee->fresh(), $this->admin);
            $this->assertTrue($donnees['a_ete_modifiee_apres_validation']);
            $this->assertSame('entered', $modifiee->fresh()->statut);
            $this->assertSame(2, $donnees['total_modifications']);
        } finally {
            $this->travelBack();
        }
    }

    public function test_revalidation_efface_le_drapeau_temporal(): void
    {
        $candidat = $this->creerCandidat();
        $service = app(SaisieNoteService::class);
        $audit = app(EvaluationAuditService::class);

        try {
            $this->travelTo(Carbon::parse('2026-11-11 10:00:00'));
            $note = $this->saisirNote($candidat, 10.0, 'Saisie initiale');
            $service->valider($note->fresh(), $this->admin->id, 'Contrôle');

            $this->travelTo(Carbon::parse('2026-11-12 11:00:00'));
            $note = $this->saisirNote($candidat, 12.0, 'Saisie (import)');
            $this->assertTrue($audit->historiqueNote($note->fresh(), $this->admin)['a_ete_modifiee_apres_validation']);

            $this->travelTo(Carbon::parse('2026-11-13 09:00:00'));
            $note = $service->valider($note->fresh(), $this->admin->id, 'Revalidation');
            $this->assertFalse($audit->historiqueNote($note->fresh(), $this->admin)['a_ete_modifiee_apres_validation']);
        } finally {
            $this->travelBack();
        }
    }

    public function test_note_hors_bornes_est_rejetee_sans_ecriture(): void
    {
        $candidat = $this->creerCandidat();

        $resultat = app(SaisieNoteService::class)->saisirLot($this->epreuve, [[
            'etudiant_id' => $candidat->etudiant_id,
            'valeur' => 21.0,
        ]], $this->admin->id);

        $this->assertSame(0, $resultat['saisies']);
        $this->assertNotEmpty($resultat['erreurs']);
        $this->assertDatabaseMissing('evaluation_notes', [
            'epreuve_id' => $this->epreuve->id,
            'etudiant_id' => $candidat->etudiant_id,
        ]);
    }

    public function test_etudiant_non_convoque_est_rejete(): void
    {
        $etudiant = $this->creerEtudiant();

        $resultat = app(SaisieNoteService::class)->saisirLot($this->epreuve, [[
            'etudiant_id' => $etudiant->id,
            'valeur' => 12.0,
        ]], $this->admin->id);

        $this->assertSame(0, $resultat['saisies']);
        $this->assertNotEmpty($resultat['erreurs']);
        $this->assertDatabaseMissing('evaluation_notes', [
            'epreuve_id' => $this->epreuve->id,
            'etudiant_id' => $etudiant->id,
        ]);
    }

    public function test_note_verrouillee_ne_peut_plus_etre_validee(): void
    {
        $candidat = $this->creerCandidat();
        $service = app(SaisieNoteService::class);

        $note = $this->saisirNote($candidat, 13.0, 'Saisie');
        $service->valider($note->fresh(), $this->admin->id, 'Contrôle');
        $service->verrouiller($note->fresh(), $this->admin->id, 'Verrouillage officiel');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Note verrouillée.');

        $service->valider($note->fresh(), $this->admin->id, 'Nouvelle validation');
    }

    public function test_isolation_faculte_de_l_historique(): void
    {
        $candidat = $this->creerCandidat();
        $note = $this->saisirNote($candidat, 12.0, 'Saisie');

        $adminAutreFaculte = User::creerCompteBackOffice([
            'name' => 'Admin Hors Périmètre',
            'email' => 'loop05.hors.perimetre@universite.test',
            'password' => 'MotDePasse123!',
            'faculte_id' => $this->autreFaculte->id,
        ], 'admin_faculte');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Accès refusé');

        app(EvaluationAuditService::class)->historiqueNote($note->fresh(), $adminAutreFaculte);
    }

    private function creerCandidat(): Candidat
    {
        $etudiant = $this->creerEtudiant();

        return Candidat::create([
            'epreuve_id' => $this->epreuve->id,
            'etudiant_id' => $etudiant->id,
            'statut' => 'inscrit',
        ]);
    }

    private function creerEtudiant(): Etudiant
    {
        $suffixe = ++$this->compteur;

        $etudiant = Etudiant::create([
            'cin' => 'L05-'.$suffixe,
            'nom' => 'Mansouri',
            'prenom' => 'Karim',
            'email' => 'loop05.'.$suffixe.'@universite.test',
            'date_naissance' => '2004-01-01',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'groupe_id' => $this->groupe->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Rue de vérification',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-VL05-'.$suffixe])->save();
        $etudiant->definirStatut('valide');

        $inscription = $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1',
            'statut' => 'en_cours',
        ]);
        $inscription->semestres()->create(['numero' => 1]);

        return $etudiant;
    }

    private function saisirNote(Candidat $candidat, float $valeur, ?string $motif = null): NoteEpreuve
    {
        app(SaisieNoteService::class)->saisirLot($this->epreuve, [[
            'etudiant_id' => $candidat->etudiant_id,
            'valeur' => $valeur,
            'motif' => $motif ?? 'Saisie test',
        ]], $this->admin->id);

        return NoteEpreuve::where('epreuve_id', $this->epreuve->id)
            ->where('etudiant_id', $candidat->etudiant_id)
            ->firstOrFail();
    }
}
