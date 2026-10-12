<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\TeacherReclamation;
use App\Models\User;
use App\Modules\Attendance\Models\TeacherPointage;
use App\Modules\Attendance\Models\TeacherPresence;
use App\Modules\Attendance\Services\PresenceEmploiDuTempsService;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleException;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Suite de tests de validation complète du système de présence intelligente
 * des enseignants (19 critères stricts du Prompt Loop §8).
 */
class TeacherAttendanceIntelligentTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculteA;

    protected Faculte $faculteB;

    protected Departement $departementA;

    protected Departement $departementB;

    protected User $adminGlobal;

    protected User $adminFaculteA;

    protected User $adminFaculteB;

    protected Teacher $enseignantA;

    protected Teacher $enseignantB;

    protected Group $groupeA;

    protected Room $salleA;

    protected Matiere $matiereA;

    protected Schedule $planningA;

    protected ScheduleSlot $slotLundi;

    protected AnneeUniversitaire $annee;

    protected PresenceEmploiDuTempsService $service;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.attendance.active' => true]);
        Storage::fake('private');

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté des Sciences de Tunis',
            'code' => 'FST',
            'active' => true,
        ]);

        $this->faculteB = Faculte::create([
            'nom' => 'Faculté de Droit',
            'code' => 'FD',
            'active' => true,
        ]);

        $this->departementA = Departement::create([
            'faculte_id' => $this->faculteA->id,
            'code' => 'INFO',
            'nom' => 'Informatique',
            'actif' => true,
        ]);

        $this->departementB = Departement::create([
            'faculte_id' => $this->faculteB->id,
            'code' => 'DROIT',
            'nom' => 'Droit Privé',
            'actif' => true,
        ]);

        $this->adminGlobal = User::creerCompteBackOffice([
            'name' => 'Super Admin Scolarité',
            'email' => 'super.admin@universite.test',
            'password' => 'Motdepasse@123',
        ], 'super_admin');

        $this->adminFaculteA = User::creerCompteBackOffice([
            'name' => 'Admin Scolarité Sciences',
            'email' => 'admin.sciences@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $this->adminFaculteB = User::creerCompteBackOffice([
            'name' => 'Admin Scolarité Droit',
            'email' => 'admin.droit@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->enseignantA = Teacher::create([
            'nom' => 'Trabelsi',
            'prenom' => 'Hedi',
            'email' => 'hedi.trabelsi@universite.test',
            'matricule' => 'ENS-101',
            'departement_id' => $this->departementA->id,
            'actif' => true,
        ]);

        $this->enseignantB = Teacher::create([
            'nom' => 'Mabrouk',
            'prenom' => 'Sonia',
            'email' => 'sonia.mabrouk@universite.test',
            'matricule' => 'ENS-102',
            'departement_id' => $this->departementB->id,
            'actif' => true,
        ]);

        $this->groupeA = Group::create([
            'nom' => 'L3 Informatique G1',
            'code' => 'L3-INFO-G1',
            'niveau' => 'L3',
            'faculte_id' => $this->faculteA->id,
            'annee_universitaire_id' => $this->annee->id,
            'actif' => true,
        ]);

        $this->salleA = Room::create([
            'code' => 'AMPHI-A',
            'nom' => 'Amphithéâtre A',
            'capacite' => 150,
            'faculte_id' => $this->faculteA->id,
            'active' => true,
        ]);

        $this->matiereA = Matiere::create([
            'code' => 'ALGO-3',
            'nom' => 'Algorithmique & Graphes',
            'niveau' => 'L3',
            'semestre' => 1,
            'actif' => true,
        ]);

        $this->planningA = Schedule::create([
            'nom' => 'EDT L3 Informatique S1',
            'groupe_id' => $this->groupeA->id,
            'annee_universitaire_id' => $this->annee->id,
            'semestre' => 1,
            'actif' => true,
            'statut' => 'publie',
        ]);

        $this->slotLundi = ScheduleSlot::create([
            'schedule_id' => $this->planningA->id,
            'teacher_id' => $this->enseignantA->id,
            'room_id' => $this->salleA->id,
            'matiere_id' => $this->matiereA->id,
            'day_of_week' => 'monday',
            'start_time' => '08:30:00',
            'end_time' => '10:30:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $this->service = app(PresenceEmploiDuTempsService::class);
    }

    /**
     * 1. Accès à ses propres présences par l'enseignant authentifié.
     */
    public function test_1_acces_a_ses_propres_presences(): void
    {
        TeacherPresence::create([
            'schedule_slot_id' => $this->slotLundi->id,
            'teacher_id' => $this->enseignantA->id,
            'date' => '2026-10-12',
            'statut_calcule' => 'presence_confirmee',
        ]);

        $reponse = $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->get(route('enseignant.presences.index'));

        $reponse->assertOk();
        $reponse->assertSee('Mes présences');
        $reponse->assertSee('Taux de présence');
        $reponse->assertSee('Algorithmique &amp; Graphes', false);
        $reponse->assertSee('12/10/2026');
    }

    /**
     * 2. Refus d'accès pour un utilisateur non authentifié (redirection connexion).
     */
    public function test_2_refus_acces_utilisateur_non_authentifie(): void
    {
        $this->get(route('enseignant.presences.index'))
            ->assertRedirect(route('enseignant.connexion.show'));
    }

    /**
     * 3. Refus d'accès pour un utilisateur sans session enseignant valide.
     */
    public function test_3_refus_acces_utilisateur_sans_role_enseignant(): void
    {
        // Sans session enseignant valide -> redirection vers la page de connexion
        $this->withSession(['enseignant_auth_id' => 99999])
            ->get(route('enseignant.presences.index'))
            ->assertRedirect(route('enseignant.connexion.show'));
    }

    /**
     * 4. Isolation stricte entre deux enseignants (anti-IDOR).
     */
    public function test_4_isolation_entre_deux_enseignants_anti_idor(): void
    {
        $slotB = ScheduleSlot::create([
            'schedule_id' => $this->planningA->id,
            'teacher_id' => $this->enseignantB->id,
            'room_id' => $this->salleA->id,
            'matiere_id' => $this->matiereA->id,
            'day_of_week' => 'tuesday',
            'start_time' => '14:00:00',
            'end_time' => '16:00:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $presenceA = TeacherPresence::create([
            'schedule_slot_id' => $this->slotLundi->id,
            'teacher_id' => $this->enseignantA->id,
            'date' => '2026-10-12',
            'statut_calcule' => 'presence_confirmee',
        ]);

        $presenceB = TeacherPresence::create([
            'schedule_slot_id' => $slotB->id,
            'teacher_id' => $this->enseignantB->id,
            'date' => '2026-10-13',
            'statut_calcule' => 'absence_a_verifier',
        ]);

        // L'enseignant A ne voit pas la ligne de l'enseignant B dans sa liste
        $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->get(route('enseignant.presences.index'))
            ->assertSee('12/10/2026')
            ->assertDontSee('13/10/2026');

        // L'enseignant A ne peut pas consulter le détail de la présence de l'enseignant B (404)
        $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->get(route('enseignant.presences.show', $presenceB))
            ->assertNotFound();

        // L'enseignant A accède bien à sa propre fiche de présence
        $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->get(route('enseignant.presences.show', $presenceA))
            ->assertOk();
    }

    /**
     * 5. Isolation entre facultés pour les gestionnaires administratifs.
     */
    public function test_5_isolation_entre_facultes(): void
    {
        $presenceB = TeacherPresence::create([
            'schedule_slot_id' => $this->slotLundi->id,
            'teacher_id' => $this->enseignantB->id,
            'date' => '2026-10-12',
            'statut_calcule' => 'absence_a_verifier',
        ]);

        $reclamationB = TeacherReclamation::create([
            'reference' => 'REC-ENS-2026-00001',
            'teacher_id' => $this->enseignantB->id,
            'teacher_presence_id' => $presenceB->id,
            'faculte_id' => $this->faculteB->id,
            'categorie' => 'presence',
            'objet' => 'Régularisation présence',
            'description' => 'Badge non reconnu',
            'statut' => 'ouverte',
        ]);

        // L'admin de la Faculté A (Sciences) ne peut ni voir ni traiter la réclamation de la Faculté B (Droit)
        $this->actingAs($this->adminFaculteA)
            ->get(route('admin.presences.regularisations.show', $reclamationB))
            ->assertNotFound();

        $this->actingAs($this->adminFaculteA)
            ->post(route('admin.presences.regularisations.traiter', $reclamationB), [
                'decision' => 'resoudre',
                'statut_valide' => 'presence_confirmee',
                'motif' => 'Essai illicite',
            ])
            ->assertNotFound();

        // L'admin de la Faculté B y a bien accès
        $this->actingAs($this->adminFaculteB)
            ->get(route('admin.presences.regularisations.show', $reclamationB))
            ->assertOk();
    }

    /**
     * 6. Détection d'une séance terminée sans pointage -> anomalie à vérifier.
     */
    public function test_6_detection_seance_terminee_sans_pointage(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 18:00:00')); // Lundi soir, séance terminée à 10:30

        $seances = $this->service->seancesDuJour('2026-10-12', $this->faculteA->id);

        $this->assertNotEmpty($seances);
        $seance = $seances->firstWhere('slot_id', $this->slotLundi->id);
        $this->assertNotNull($seance);
        $this->assertSame('absence_a_verifier', $seance['statut_calcule']);
        $this->assertStringContainsString('Séance terminée sans aucun pointage', $seance['diagnostic']);
        $this->assertTrue($seance['a_verifier']);
    }

    /**
     * 7. Règle fondamentale : absence NON confirmée automatiquement.
     */
    public function test_7_absence_non_confirmee_automatiquement(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 18:00:00'));

        $this->service->reconcilierEtPersister('2026-10-12', $this->faculteA->id);

        $presence = TeacherPresence::where('schedule_slot_id', $this->slotLundi->id)
            ->whereDate('date', '2026-10-12')
            ->first();

        $this->assertNotNull($presence);
        $this->assertSame('absence_a_verifier', $presence->statut_calcule);
        $this->assertNull($presence->statut_valide, 'Le statut validé doit impérativement rester null : aucune absence confirmée automatique.');
        $this->assertSame('absence_a_verifier', $presence->statutEffectif());
        $this->assertNotSame('absence_validee', $presence->statutEffectif());
    }

    /**
     * 8. Exclusion des séances annulées ou déplacées des absences sanctionnées.
     */
    public function test_8_exclusion_seances_annulees_ou_deplacees(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 18:00:00'));

        // Annulation ponctuelle via ScheduleException
        ScheduleException::create([
            'schedule_slot_id' => $this->slotLundi->id,
            'exception_date' => '2026-10-12',
            'status' => 'cancelled',
            'reason' => 'Jour férié universitaire exceptionnel',
        ]);

        $seances = $this->service->seancesDuJour('2026-10-12', $this->faculteA->id);
        $seance = $seances->firstWhere('slot_id', $this->slotLundi->id);

        $this->assertNotNull($seance);
        $this->assertSame('seance_annulee', $seance['statut_calcule']);
        $this->assertFalse($seance['a_verifier'], 'Une séance annulée ne doit pas être marquée comme une anomalie à vérifier.');

        // Vérification dans les statistiques : l'annulation ne dégrade pas le taux de présence
        $this->service->reconcilierEtPersister('2026-10-12', $this->faculteA->id);
        $stats = $this->service->statistiquesEnseignant($this->enseignantA->id, '2026-10-12', '2026-10-12');

        $this->assertSame(1, $stats['annulees']);
        $this->assertSame(0, $stats['prises_en_compte']);
        $this->assertNull($stats['taux'], 'Aucune séance échue prise en compte, aucun taux négatif calculé.');
    }

    /**
     * 9. Calcul du retard selon la configuration administrable.
     */
    public function test_9_calcul_du_retard_selon_configuration(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 12:00:00'));

        // Séances débute à 08:30. Pointage à 08:42 (12 min après).
        // Avec tolérance par défaut de 15 min -> présence confirmée (retard toléré).
        $this->service->enregistrerPointage($this->enseignantA, '2026-10-12 08:42:00', 'entree', 'badgeuse');

        $seances = $this->service->seancesDuJour('2026-10-12', $this->faculteA->id, [], 15);
        $seance = $seances->firstWhere('slot_id', $this->slotLundi->id);

        $this->assertSame('presence_confirmee', $seance['statut_calcule']);
        $this->assertSame(12, $seance['retard_minutes']);

        // Si la tolérance est abaissée à 10 min -> retard potentiel
        $seancesAvecTol10 = $this->service->seancesDuJour('2026-10-12', $this->faculteA->id, [], 10);
        $seanceTol10 = $seancesAvecTol10->firstWhere('slot_id', $this->slotLundi->id);

        $this->assertSame('retard_potentiel', $seanceTol10['statut_calcule']);
    }

    /**
     * 10. Prévention des pointages en double (anti-rebond matériel ou double clic).
     */
    public function test_10_prevention_des_pointages_en_double(): void
    {
        $premier = $this->service->enregistrerPointage($this->enseignantA, '2026-10-12 08:28:15', 'entree', 'badgeuse');
        $second = $this->service->enregistrerPointage($this->enseignantA, '2026-10-12 08:28:40', 'entree', 'badgeuse');

        // L'anti-doublon retourne le même enregistrement dans la même minute
        $this->assertSame($premier->id, $second->id);
        $this->assertSame(1, TeacherPointage::where('teacher_id', $this->enseignantA->id)->count());
    }

    /**
     * 11. Gestion des pointages incomplets, orphelins et incompatibles.
     */
    public function test_11_gestion_pointages_incomplets_et_incompatibles(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 12:00:00'));

        // Pointage de sortie AVANT arrivée (inversion d'horodatage erronée)
        $this->service->enregistrerPointage($this->enseignantA, '2026-10-12 08:35:00', 'entree', 'badgeuse');
        $this->service->enregistrerPointage($this->enseignantA, '2026-10-12 08:20:00', 'sortie', 'badgeuse');

        $seances = $this->service->seancesDuJour('2026-10-12', $this->faculteA->id);
        $seance = $seances->firstWhere('slot_id', $this->slotLundi->id);

        // Ne plante pas et conserve les deux pointages
        $this->assertCount(2, $seance['pointages']);
        $this->assertStringContainsString('le pointage de sortie précède le pointage d\'arrivée', $seance['diagnostic']);

        // Pointage orphelin (dimanche, sans créneau)
        $orphelin = $this->service->enregistrerPointage($this->enseignantA, '2026-10-11 15:00:00', 'presence', 'badgeuse');
        $orphelins = $this->service->pointagesOrphelins($this->enseignantA->id, '2026-10-11', '2026-10-11');

        $this->assertTrue($orphelins->contains('id', $orphelin->id));
    }

    /**
     * 12. Dépôt d'une demande de régularisation par l'enseignant.
     */
    public function test_12_depot_demande_regularisation(): void
    {
        $presence = TeacherPresence::create([
            'schedule_slot_id' => $this->slotLundi->id,
            'teacher_id' => $this->enseignantA->id,
            'date' => '2026-10-12',
            'statut_calcule' => 'absence_a_verifier',
        ]);

        $fichierFake = UploadedFile::fake()->create('justificatif_panne.pdf', 100, 'application/pdf');

        $reponse = $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->post(route('enseignant.reclamations.store'), [
                'categorie' => 'presence',
                'presence_id' => $presence->id,
                'objet' => 'Borne badgeuse défaillante',
                'description' => 'J\'étais bien présent en salle mais la badgeuse affichait une erreur réseau.',
                'piece_jointe' => $fichierFake,
            ]);

        $reponse->assertRedirect();
        $this->assertDatabaseHas('teacher_reclamations', [
            'teacher_id' => $this->enseignantA->id,
            'teacher_presence_id' => $presence->id,
            'categorie' => 'presence',
            'statut' => 'ouverte',
            'piece_jointe_nom' => 'justificatif_panne.pdf',
        ]);
    }

    /**
     * 13. Validation ou refus par un acteur autorisé de la scolarité.
     */
    public function test_13_validation_ou_refus_par_acteur_autorise(): void
    {
        $presence = TeacherPresence::create([
            'schedule_slot_id' => $this->slotLundi->id,
            'teacher_id' => $this->enseignantA->id,
            'date' => '2026-10-12',
            'statut_calcule' => 'absence_a_verifier',
        ]);

        $reclamation = TeacherReclamation::create([
            'reference' => 'REC-ENS-2026-00042',
            'teacher_id' => $this->enseignantA->id,
            'teacher_presence_id' => $presence->id,
            'faculte_id' => $this->faculteA->id,
            'categorie' => 'presence',
            'objet' => 'Régularisation',
            'description' => 'Feuille émargement papier signée',
            'statut' => 'ouverte',
        ]);

        // L'admin de la faculté valide la demande
        $reponse = $this->actingAs($this->adminFaculteA)
            ->post(route('admin.presences.regularisations.traiter', $reclamation), [
                'decision' => 'resoudre',
                'statut_valide' => 'presence_confirmee',
                'motif' => 'Vérification émargement manuscrit effectuée avec succès.',
            ]);

        $reponse->assertRedirect();
        $reclamation->refresh();
        $presence->refresh();

        $this->assertSame('resolue', $reclamation->statut);
        $this->assertSame('presence_confirmee', $presence->statut_valide);
        $this->assertSame('Vérification émargement manuscrit effectuée avec succès.', $presence->motif);
        $this->assertSame($this->adminFaculteA->id, $presence->valide_par);
    }

    /**
     * 14. Impossibilité d'approuver sa propre demande (séparation des responsabilités).
     */
    public function test_14_impossibilite_approuver_sa_propre_demande(): void
    {
        // Créer un compte admin scolarité rattaché à l'enseignant A
        $adminEnseignant = User::creerCompteBackOffice([
            'name' => 'Dr Hedi Trabelsi (Double Casquette)',
            'email' => 'hedi.admin@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $this->enseignantA->update(['user_id' => $adminEnseignant->id]);

        $presence = TeacherPresence::create([
            'schedule_slot_id' => $this->slotLundi->id,
            'teacher_id' => $this->enseignantA->id,
            'date' => '2026-10-12',
            'statut_calcule' => 'absence_a_verifier',
        ]);

        $reclamation = TeacherReclamation::create([
            'reference' => 'REC-ENS-2026-00099',
            'teacher_id' => $this->enseignantA->id,
            'teacher_presence_id' => $presence->id,
            'faculte_id' => $this->faculteA->id,
            'categorie' => 'presence',
            'objet' => 'Auto-régularisation',
            'description' => 'Je m\'auto-valide',
            'statut' => 'ouverte',
        ]);

        // La tentative de traitement par l'enseignant lui-même doit lever un 403 strict
        $this->actingAs($adminEnseignant)
            ->post(route('admin.presences.regularisations.traiter', $reclamation), [
                'decision' => 'resoudre',
                'statut_valide' => 'presence_confirmee',
                'motif' => 'Auto-validation interdite',
            ])
            ->assertForbidden();

        $reclamation->refresh();
        $this->assertSame('ouverte', $reclamation->statut);
    }

    /**
     * 15. Protection des justificatifs stockés en espace privé.
     */
    public function test_15_protection_des_justificatifs_prives(): void
    {
        $cheminPrive = 'private/enseignant/reclamations/secret.pdf';
        Storage::disk('private')->put($cheminPrive, 'Contenu confidentiel médical');

        $reclamation = TeacherReclamation::create([
            'reference' => 'REC-ENS-2026-00100',
            'teacher_id' => $this->enseignantA->id,
            'faculte_id' => $this->faculteA->id,
            'categorie' => 'presence',
            'objet' => 'Justificatif privé',
            'description' => 'Pièce jointe privée',
            'piece_jointe_chemin' => $cheminPrive,
            'piece_jointe_nom' => 'secret.pdf',
            'statut' => 'ouverte',
        ]);

        // L'enseignant B tente de télécharger la pièce jointe de l'enseignant A -> 404
        $this->withSession(['enseignant_auth_id' => $this->enseignantB->id])
            ->get(route('enseignant.reclamations.piece-jointe', $reclamation))
            ->assertNotFound();

        // L'admin de la faculté B tente de télécharger -> 404
        $this->actingAs($this->adminFaculteB)
            ->get(route('admin.presences.regularisations.piece-jointe', $reclamation))
            ->assertNotFound();

        // L'enseignant A propriétaire peut la télécharger
        $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->get(route('enseignant.reclamations.piece-jointe', $reclamation))
            ->assertOk();

        // L'admin de la faculté A autorisée peut la télécharger
        $this->actingAs($this->adminFaculteA)
            ->get(route('admin.presences.regularisations.piece-jointe', $reclamation))
            ->assertOk();
    }

    /**
     * 16. Traçabilité des corrections et historique d'audit non destructeur.
     */
    public function test_16_tracabilite_des_corrections(): void
    {
        $presence = TeacherPresence::create([
            'schedule_slot_id' => $this->slotLundi->id,
            'teacher_id' => $this->enseignantA->id,
            'date' => '2026-10-12',
            'statut_calcule' => 'absence_a_verifier',
        ]);

        $this->service->corrigerPresence(
            $presence,
            ['statut_valide' => 'presence_confirmee', 'heure_arrivee' => '08:30:00'],
            'Présence constatée physiquement par le doyen',
            $this->adminGlobal->id
        );

        $presence->refresh();
        $this->assertSame('presence_confirmee', $presence->statut_valide);
        $this->assertNotEmpty($presence->historique);

        $historique = $presence->historique;
        $trace = end($historique);
        $this->assertSame('correction_manuelle', $trace['action']);
        $this->assertSame('Présence constatée physiquement par le doyen', $trace['motif']);
        $this->assertSame($this->adminGlobal->id, $trace['user_id']);
    }

    /**
     * 17. Idempotence des recalculs automatiques et préservation des décisions humaines.
     */
    public function test_17_idempotence_des_recalculs(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 18:00:00'));

        // 1er recalcul : observation créée
        $this->service->reconcilierEtPersister('2026-10-12', $this->faculteA->id);
        $countInitial = TeacherPresence::count();

        // Décision humaine enregistrée
        $presence = TeacherPresence::first();
        $this->service->dispenserOuMission($presence, 'Ordre de mission rectorat N°42', $this->adminGlobal->id);

        // 2e recalcul : aucune duplication de ligne, décision humaine conservée
        $this->service->reconcilierEtPersister('2026-10-12', $this->faculteA->id);

        $this->assertSame($countInitial, TeacherPresence::count());
        $presence->refresh();
        $this->assertSame('dispense_mission', $presence->statut_valide);
        $this->assertSame('dispense_mission', $presence->statutEffectif());
    }

    /**
     * 18. Performance et absence de requêtes N+1 sur un volume représentatif.
     */
    public function test_18_performance_sur_volume_representatif_sans_n_plus_un(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 18:00:00'));

        // Créer 20 créneaux supplémentaires pour différents enseignants et matières
        for ($i = 1; $i <= 20; $i++) {
            $m = Matiere::create([
                'code' => 'MAT-'.$i,
                'nom' => 'Matière '.$i,
                'niveau' => 'L3',
                'semestre' => 1,
                'actif' => true,
            ]);

            ScheduleSlot::create([
                'schedule_id' => $this->planningA->id,
                'teacher_id' => $this->enseignantA->id,
                'room_id' => $this->salleA->id,
                'matiere_id' => $m->id,
                'day_of_week' => 'monday',
                'start_time' => sprintf('%02d:00:00', 8 + ($i % 10)),
                'end_time' => sprintf('%02d:00:00', 9 + ($i % 10)),
                'type' => 'td',
                'status' => 'scheduled',
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $seances = $this->service->seancesDuJour('2026-10-12', $this->faculteA->id);

        $nbRequetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThanOrEqual(21, $seances->count());
        // Doit s'exécuter en moins de 20 requêtes fixes grâce à l'eager loading groupé (pas de N+1)
        $this->assertLessThan(20, $nbRequetes, "Nombre de requêtes excessif ({$nbRequetes}) dénotant un N+1 potentiel.");
    }

    /**
     * 19. Absence de régression dans les modules Emploi du temps, Demandes et Notifications.
     */
    public function test_19_absence_de_regression_modules_transverses(): void
    {
        // 1. EDT : vérification que les relations de ScheduleSlot restent intactes
        $slot = ScheduleSlot::with(['matiere', 'teacher', 'room', 'presences'])->findOrFail($this->slotLundi->id);
        $this->assertSame('Trabelsi', $slot->teacher->nom);
        $this->assertSame('Algorithmique & Graphes', $slot->matiere->nom);

        // 2. Notifications : vérifier que le service de notification enseignant fonctionne
        $serviceNotif = app(NotificationService::class);
        $notif = $serviceNotif->notifierEnseignant(
            $this->enseignantA,
            'regularisation.info',
            'Test Notification',
            'Message de test pour vérifier la non-régression',
            '/enseignant/presences'
        );
        $this->assertNotNull($notif);
        $this->assertSame('Test Notification', $notif->titre);

        // 3. Export CSV : vérifier le relevé enseignant
        $reponseCsv = $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->get(route('enseignant.presences.export'));
        $reponseCsv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $reponseCsv->headers->get('Content-Type'));
    }
}
