<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Enseignement\Services\ServiceEnseignantService;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\RemplacementCandidature;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\Schedule\Services\CandidatureRemplacementService;
use App\Modules\Schedule\Services\ScheduleRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * LOT 09 — Charges d'enseignement et remplacements (audit §F9).
 *
 * Couvre les deux manques nommés par l'audit :
 * 1. Candidature de remplacement pédagogique : volontariat déclaré par
 *    matière, retrait propriétaire, acceptation administrative qui
 *    RÉUTILISE le remplacement EDT transactionnel existant (gardes +
 *    historisation `ScheduleChange`), écart motivé, et priorisation
 *    des candidats dans les suggestions de remplacement.
 * 2. Imputation EDT → charge : `chargeAvecEdt` expose la charge
 *    planifiée hebdomadaire (`Teacher::chargeHebdoPlanifiee`, calcul
 *    unique) à côté de la charge contractuelle, sans fusion
 *    silencieuse — le volume affecté reste la référence des seuils.
 */
class RemplacementCandidatureTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Matiere $matiere;

    private Matiere $autreMatiere;

    private Teacher $titulaire;

    private Teacher $volontaire;

    private Teacher $horsQualification;

    private User $admin;

    private Schedule $planning;

    private ScheduleSlot $creneau;

    private CandidatureRemplacementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.schedule.active' => true]);

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences LOT09',
            'code' => 'FS09_'.uniqid(),
            'actif' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->matiere = Matiere::create([
            'code' => 'MAT_R09A_'.substr(uniqid(), -6),
            'nom' => 'Algorithmique LOT09',
            'ue_id' => null,
            'niveau' => 'L3',
            'semestre' => 5,
            'faculte_id' => $this->faculte->id,
        ]);

        $this->autreMatiere = Matiere::create([
            'code' => 'MAT_R09B_'.substr(uniqid(), -6),
            'nom' => 'Réseaux LOT09',
            'ue_id' => null,
            'niveau' => 'L3',
            'semestre' => 5,
            'faculte_id' => $this->faculte->id,
        ]);

        $this->titulaire = Teacher::create([
            'matricule' => 'ENS_T09_'.substr(uniqid(), -5),
            'nom' => 'Titulaire',
            'prenom' => 'Omar',
            'email' => 'titulaire.lot09@univ.test',
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->volontaire = Teacher::create([
            'matricule' => 'ENS_V09_'.substr(uniqid(), -5),
            'nom' => 'Volontaire',
            'prenom' => 'Nadia',
            'email' => 'volontaire.lot09@univ.test',
            'faculte_id' => $this->faculte->id,
            'qualifications' => [$this->matiere->code, $this->autreMatiere->code],
            'actif' => true,
        ]);

        $this->horsQualification = Teacher::create([
            'matricule' => 'ENS_H09_'.substr(uniqid(), -5),
            'nom' => 'HorsQual',
            'prenom' => 'Karim',
            'email' => 'horsqual.lot09@univ.test',
            'faculte_id' => $this->faculte->id,
            'qualifications' => ['MAT_INEXISTANTE_XYZ'],
            'actif' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin EDT LOT09',
            'email' => 'admin.lot09@univ.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $groupe = Group::create([
            'nom' => 'Groupe L3 LOT09',
            'code' => 'G_R09_'.substr(uniqid(), -6),
            'niveau' => 'L3',
            'capacite' => 25,
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->planning = Schedule::create([
            'nom' => 'EDT L3 Info LOT09',
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculte->id,
            'semestre' => 1,
            'groupe_id' => $groupe->id,
        ]);

        $this->creneau = ScheduleSlot::create([
            'schedule_id' => $this->planning->id,
            'matiere_id' => $this->matiere->id,
            'teacher_id' => $this->titulaire->id,
            'day_of_week' => 'monday',
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $this->service = app(CandidatureRemplacementService::class);
    }

    public function test_depot_exige_qualification_et_interdit_le_doublon(): void
    {
        $candidature = $this->service->deposer($this->volontaire, [
            'matiere_id' => $this->matiere->id,
            'message' => 'Disponible en semaine impaire.',
        ]);
        $this->assertSame('soumise', $candidature->statut);

        // Doublon actif refusé.
        try {
            $this->service->deposer($this->volontaire, ['matiere_id' => $this->matiere->id]);
            $this->fail('Le doublon de candidature doit être refusé.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('matiere_id', $exception->errors());
        }

        // Hors qualification refusé.
        try {
            $this->service->deposer($this->horsQualification, ['matiere_id' => $this->matiere->id]);
            $this->fail('Un enseignant non qualifié ne peut pas se porter candidat.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('matiere_id', $exception->errors());
        }

        $this->assertSame(1, RemplacementCandidature::count());
    }

    public function test_retrait_proprietaire_uniquement(): void
    {
        $candidature = $this->service->deposer($this->volontaire, [
            'matiere_id' => $this->matiere->id,
        ]);

        // Un autre enseignant ne peut pas retirer la candidature.
        try {
            $this->service->retirer($candidature, $this->titulaire);
            $this->fail('Seul le propriétaire peut retirer sa candidature.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('candidature', $exception->errors());
        }
        $this->assertSame('soumise', $candidature->fresh()->statut);

        // Le propriétaire retire : retrait définitif, re-dépôt possible.
        $retiree = $this->service->retirer($candidature, $this->volontaire);
        $this->assertSame('retiree', $retiree->statut);

        $redepot = $this->service->deposer($this->volontaire, ['matiere_id' => $this->matiere->id]);
        $this->assertSame('soumise', $redepot->statut);
    }

    public function test_acceptation_reutilise_le_remplacement_edt_et_historise(): void
    {
        $candidature = $this->service->deposer($this->volontaire, [
            'matiere_id' => $this->matiere->id,
            'message' => 'Volontaire.',
        ]);

        $acceptee = $this->service->accepter($candidature, $this->creneau, $this->admin->id);

        // Le remplacement EDT a eu lieu (transactionnel + historisé).
        $this->assertSame('acceptee', $acceptee->statut);
        $this->assertSame($this->admin->id, (int) $acceptee->decided_by);
        $this->assertSame($this->volontaire->id, (int) $this->creneau->fresh()->teacher_id);

        $this->assertDatabaseHas('schedule_changes', [
            'schedule_slot_id' => $this->creneau->id,
            'change_type' => 'teacher_changed',
            'old_values' => json_encode(['teacher_id' => $this->titulaire->id]),
            'new_values' => json_encode(['teacher_id' => $this->volontaire->id]),
        ]);
    }

    public function test_acceptation_refusee_si_matiere_ou_disponibilite_incompatibles(): void
    {
        // Candidature sur une autre matière que le créneau : refus.
        $candidatureAutre = $this->service->deposer($this->volontaire, [
            'matiere_id' => $this->autreMatiere->id,
        ]);

        try {
            $this->service->accepter($candidatureAutre, $this->creneau, $this->admin->id);
            $this->fail('La candidature doit porter sur la matière du créneau.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('slot', $exception->errors());
        }
        $this->assertSame('soumise', $candidatureAutre->fresh()->statut);
        $this->assertSame($this->titulaire->id, (int) $this->creneau->fresh()->teacher_id);

        // Remplaçant devenu indisponible sur le créneau : le remplacement
        // EDT échoue, la candidature reste soumise (transaction commune).
        $candidature = $this->service->deposer($this->volontaire, [
            'matiere_id' => $this->matiere->id,
        ]);
        $this->volontaire->update(['actif' => false]);

        try {
            $this->service->accepter($candidature, $this->creneau, $this->admin->id);
            $this->fail('Le remplacement doit échouer (enseignant inactif).');
        } catch (\DomainException) {
            // Garde du remplacement EDT existant.
        }
        $this->assertSame('soumise', $candidature->fresh()->statut);
        $this->assertSame($this->titulaire->id, (int) $this->creneau->fresh()->teacher_id);
    }

    public function test_suggestions_prioritisent_les_candidats_declares(): void
    {
        $reparations = app(ScheduleRepairService::class);

        // Sans candidature : volontaire flaggé non candidat.
        $sansCandidature = collect($reparations->suggererRemplacants($this->creneau))
            ->firstWhere('teacher_id', $this->volontaire->id);
        $this->assertNotNull($sansCandidature);
        $this->assertFalse((bool) $sansCandidature['candidat_declare']);
        $scoreSans = $sansCandidature['score_pertinence'];

        // Avec candidature : +30 et flag candidat déclaré.
        $this->service->deposer($this->volontaire, ['matiere_id' => $this->matiere->id]);

        $avecCandidature = collect($reparations->suggererRemplacants($this->creneau))
            ->firstWhere('teacher_id', $this->volontaire->id);
        $this->assertTrue((bool) $avecCandidature['candidat_declare']);
        // Le score est plafonné à 100 (garde du service) : le boost candidat
        // s'ajoute au score de base puis le cap s'applique.
        $this->assertSame(min(100, $scoreSans + 30), $avecCandidature['score_pertinence']);
        $this->assertStringContainsString('Candidature de remplacement déclarée', $avecCandidature['justification']);
    }

    public function test_charge_avec_edt_expose_le_planifie_sans_fusionner(): void
    {
        // Le titulaire a le créneau (2h hebdo) planifié et aucune affectation.
        $services = app(ServiceEnseignantService::class);

        $charge = $services->chargeAvecEdt($this->titulaire->id, $this->annee->id, $this->planning->id);

        $this->assertSame(2.0, (float) $charge['planifie_hebdo']);
        $this->assertSame(0.0, (float) $charge['affecte']);
        $this->assertSame(0.0, (float) $charge['total']);

        // La charge contractuelle de base reste inchangée (aucune clé perdue).
        $this->assertSame(
            array_keys($services->charge($this->titulaire->id, $this->annee->id)),
            array_slice(array_keys($charge), 0, 7)
        );

        // Sans contexte de planning : toutes les EDT de l'enseignant.
        $chargeGlobale = $services->chargeAvecEdt($this->titulaire->id, $this->annee->id);
        $this->assertSame(2.0, (float) $chargeGlobale['planifie_hebdo']);
    }

    public function test_portail_et_admin_http_avec_guards(): void
    {
        // Portail enseignant : dépôt + liste + retrait, session enseignant.
        session(['enseignant_auth_id' => $this->volontaire->id]);

        $this->post(route('enseignant.remplacement-candidatures.store'), [
            'matiere_id' => $this->matiere->id,
            'message' => 'Disponible.',
        ])->assertRedirect();
        $this->assertDatabaseHas('remplacement_candidatures', [
            'teacher_id' => $this->volontaire->id,
            'statut' => 'soumise',
        ]);

        $liste = $this->get(route('enseignant.remplacement-candidatures.index'))->assertOk()->json();
        $this->assertCount(1, $liste);
        $this->assertSame($this->matiere->code, $liste[0]['code']);

        // IDOR : un autre enseignant ne peut pas retirer.
        session(['enseignant_auth_id' => $this->titulaire->id]);
        $candidature = RemplacementCandidature::firstOrFail();
        $this->delete(route('enseignant.remplacement-candidatures.destroy', $candidature))
            ->assertRedirect();
        $this->assertSame('soumise', $candidature->fresh()->statut);

        // Admin : registre + acceptation (remplacement réel) + écart.
        $candidature = $candidature->fresh();
        $this->actingAs($this->admin)
            ->get(route('admin.remplacement-candidatures.index'))
            ->assertOk();

        $this->actingAs($this->admin)
            ->post(route('admin.remplacement-candidatures.accepter', $candidature), [
                'schedule_slot_id' => $this->creneau->id,
            ])->assertRedirect();
        $this->assertSame('acceptee', $candidature->fresh()->statut);
        $this->assertSame($this->volontaire->id, (int) $this->creneau->fresh()->teacher_id);

        // Sans schedules.manage : refus.
        $consultation = User::creerCompteBackOffice([
            'name' => 'Consultation LOT09',
            'email' => 'consultation.lot09@univ.test',
            'password' => 'Motdepasse@123',
        ], 'consultation');
        $autreCandidature = $this->service->deposer($this->volontaire, [
            'matiere_id' => $this->autreMatiere->id,
        ]);
        $this->actingAs($consultation)
            ->post(route('admin.remplacement-candidatures.accepter', $autreCandidature), [
                'schedule_slot_id' => $this->creneau->id,
            ])->assertForbidden();
    }

    public function test_admin_faculte_ne_voit_ni_ne_mute_les_candidatures_d_une_autre_faculte(): void
    {
        // Deuxième faculté : matière, enseignants, groupe, EDT et candidature.
        $autreFaculte = Faculte::create([
            'nom' => 'Faculté des Lettres LOT09',
            'code' => 'FL09_'.uniqid(),
            'actif' => true,
        ]);

        $matiereAutre = Matiere::create([
            'code' => 'MAT_R09C_'.substr(uniqid(), -6),
            'nom' => 'Littérature LOT09',
            'ue_id' => null,
            'niveau' => 'L3',
            'semestre' => 5,
        ]);

        $departementAutre = Departement::create([
            'faculte_id' => $autreFaculte->id,
            'code' => 'DEP09A_'.substr(uniqid(), -5),
            'nom' => 'Département Lettres LOT09',
            'actif' => true,
        ]);

        $titulaireAutre = Teacher::create([
            'matricule' => 'ENS_TA09_'.substr(uniqid(), -5),
            'nom' => 'TitulaireAutre',
            'prenom' => 'Sami',
            'email' => 'titulaire.autre.lot09@univ.test',
            'departement_id' => $departementAutre->id,
            'actif' => true,
        ]);

        $volontaireAutre = Teacher::create([
            'matricule' => 'ENS_VA09_'.substr(uniqid(), -5),
            'nom' => 'VolontaireAutre',
            'prenom' => 'Leila',
            'email' => 'volontaire.autre.lot09@univ.test',
            'departement_id' => $departementAutre->id,
            'qualifications' => [$matiereAutre->code],
            'actif' => true,
        ]);

        $groupeAutre = Group::create([
            'nom' => 'Groupe L3 Lettres LOT09',
            'code' => 'G_RA09_'.substr(uniqid(), -6),
            'niveau' => 'L3',
            'capacite' => 25,
            'faculte_id' => $autreFaculte->id,
            'actif' => true,
        ]);

        $planningAutre = Schedule::create([
            'nom' => 'EDT L3 Lettres LOT09',
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $autreFaculte->id,
            'semestre' => 1,
            'groupe_id' => $groupeAutre->id,
        ]);

        $creneauAutre = ScheduleSlot::create([
            'schedule_id' => $planningAutre->id,
            'matiere_id' => $matiereAutre->id,
            'teacher_id' => $titulaireAutre->id,
            'day_of_week' => 'tuesday',
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $candidatureAutre = $this->service->deposer($volontaireAutre, [
            'matiere_id' => $matiereAutre->id,
        ]);

        // Candidature légitime dans la faculté de l'admin (via département).
        $departement = Departement::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'DEP09_'.substr(uniqid(), -5),
            'nom' => 'Département LOT09',
            'actif' => true,
        ]);
        $this->volontaire->update(['departement_id' => $departement->id]);
        $candidatureOwn = $this->service->deposer($this->volontaire, [
            'matiere_id' => $this->matiere->id,
        ]);

        // Admin restreint à la faculté du setup : ne doit voir que la sienne.
        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Admin Faculté LOT09',
            'email' => 'admin.faculte.lot09@univ.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculte->id,
        ], 'admin_faculte');

        $this->actingAs($adminFaculte)
            ->get(route('admin.remplacement-candidatures.index'))
            ->assertOk()
            ->assertJsonFragment(['id' => $candidatureOwn->id])
            ->assertJsonMissing(['id' => $candidatureAutre->id]);

        // Acceptation légitime dans sa faculté.
        $this->actingAs($adminFaculte)
            ->post(route('admin.remplacement-candidatures.accepter', $candidatureOwn), [
                'schedule_slot_id' => $this->creneau->id,
            ])->assertRedirect();
        $this->assertSame('acceptee', $candidatureOwn->fresh()->statut);

        // Ni accepter un créneau d'une autre faculté…
        $this->actingAs($adminFaculte)
            ->post(route('admin.remplacement-candidatures.accepter', $candidatureAutre), [
                'schedule_slot_id' => $creneauAutre->id,
            ])->assertNotFound();

        $this->assertSame('soumise', $candidatureAutre->fresh()->statut);
        $this->assertSame($titulaireAutre->id, (int) $creneauAutre->fresh()->teacher_id);

        // …ni écarter la candidature d'une autre faculté.
        $this->actingAs($adminFaculte)
            ->post(route('admin.remplacement-candidatures.ecarter', $candidatureAutre), [
                'motif' => 'Tentative hors périmètre',
            ])->assertNotFound();

        $this->assertSame('soumise', $candidatureAutre->fresh()->statut);
    }
}
