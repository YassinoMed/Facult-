<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Attendance\Models\Presence;
use App\Modules\Attendance\Models\SeanceAppel;
use App\Modules\Attendance\Services\AppelPresenceService;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite de tests pour l'appel intelligent et le pointage des étudiants (15 points obligatoires).
 */
class StudentAttendanceRollCallTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculteA;

    protected Faculte $faculteB;

    protected Departement $departementA;

    protected Departement $departementB;

    protected AnneeUniversitaire $annee;

    protected Teacher $enseignantA;

    protected Teacher $enseignantB;

    protected Group $groupeA;

    protected Group $groupeB;

    protected Room $salleA;

    protected Matiere $matiereA;

    protected Schedule $planningA;

    protected ScheduleSlot $slotA;

    protected Etudiant $etudiant1;

    protected Etudiant $etudiant2;

    protected Etudiant $etudiant3;

    protected Etudiant $etudiantHorsGroupe;

    protected User $adminScolarite;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.attendance.active' => true, 'modules.schedule.active' => true]);

        Carbon::setTestNow('2026-10-09 10:00:00'); // Vendredi (Friday)

        $this->annee = AnneeUniversitaire::create([
            'code' => '2026-2027',
            'libelle' => 'Année universitaire 2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté des Sciences de Tunis',
            'code' => 'FST',
            'active' => true,
        ]);

        $this->faculteB = Faculte::create([
            'nom' => 'Faculté de Droit de Tunis',
            'code' => 'FDT',
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
            'nom' => 'Droit',
            'actif' => true,
        ]);

        $this->enseignantA = Teacher::create([
            'nom' => 'Ben Salah',
            'prenom' => 'Karim',
            'email' => 'karim.bensalah@universite.tn',
            'matricule' => 'ENS-ROLL-01',
            'departement_id' => $this->departementA->id,
            'actif' => true,
        ]);

        $this->enseignantB = Teacher::create([
            'nom' => 'Trabelsi',
            'prenom' => 'Leila',
            'email' => 'leila.trabelsi@universite.tn',
            'matricule' => 'ENS-ROLL-02',
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

        $this->groupeB = Group::create([
            'nom' => 'L3 Droit G1',
            'code' => 'L3-DROIT-G1',
            'niveau' => 'L3',
            'faculte_id' => $this->faculteB->id,
            'annee_universitaire_id' => $this->annee->id,
            'actif' => true,
        ]);

        $this->salleA = Room::create([
            'code' => 'LAB-INFO-1',
            'nom' => 'Laboratoire Info 1',
            'capacite' => 40,
            'faculte_id' => $this->faculteA->id,
            'active' => true,
        ]);

        $this->matiereA = Matiere::create([
            'code' => 'DEV-WEB',
            'nom' => 'Développement Web & Systèmes',
            'niveau' => 'L3',
            'semestre' => 1,
            'actif' => true,
        ]);

        $this->planningA = Schedule::create([
            'nom' => 'Emploi L3 Info G1 S1',
            'groupe_id' => $this->groupeA->id,
            'annee_universitaire_id' => $this->annee->id,
            'semestre' => 1,
            'actif' => true,
            'statut' => 'publie',
        ]);

        $this->slotA = ScheduleSlot::create([
            'schedule_id' => $this->planningA->id,
            'matiere_id' => $this->matiereA->id,
            'teacher_id' => $this->enseignantA->id,
            'room_id' => $this->salleA->id,
            'day_of_week' => 'friday',
            'start_time' => '08:30:00',
            'end_time' => '10:00:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $this->etudiant1 = $this->creerEtudiant([
            'nom' => 'Mansour',
            'prenom' => 'Yassine',
            'cin' => '09876541',
            'sexe' => 'M',
            'email' => 'yassine.mansour@etudiant.tn',
            'faculte_id' => $this->faculteA->id,
            'groupe_id' => $this->groupeA->id,
        ]);

        $this->etudiant2 = $this->creerEtudiant([
            'nom' => 'Kefi',
            'prenom' => 'Meriem',
            'cin' => '09876542',
            'sexe' => 'F',
            'email' => 'meriem.kefi@etudiant.tn',
            'faculte_id' => $this->faculteA->id,
            'groupe_id' => $this->groupeA->id,
        ]);

        $this->etudiant3 = $this->creerEtudiant([
            'nom' => 'Gharbi',
            'prenom' => 'Sami',
            'cin' => '09876543',
            'sexe' => 'M',
            'email' => 'sami.gharbi@etudiant.tn',
            'faculte_id' => $this->faculteA->id,
            'groupe_id' => $this->groupeA->id,
        ]);

        $this->etudiantHorsGroupe = $this->creerEtudiant([
            'nom' => 'Bouazizi',
            'prenom' => 'Nader',
            'cin' => '09876599',
            'sexe' => 'M',
            'email' => 'nader.bouazizi@etudiant.tn',
            'faculte_id' => $this->faculteB->id,
            'groupe_id' => $this->groupeB->id,
        ]);

        $this->adminScolarite = User::creerCompteBackOffice([
            'name' => 'Agent Scolarité FST',
            'email' => 'scolarite.fst@universite.tn',
            'password' => 'Scolarite@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_scolarite');
    }

    /**
     * Test 1 : L’enseignant peut ouvrir l’appel de sa propre séance.
     */
    public function test_enseignant_peut_ouvrir_appel_de_sa_propre_seance(): void
    {
        $response = $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->get(route('enseignant.appel.ouvrir', [
                'slot' => $this->slotA->id,
                'date' => '2026-10-09',
            ]));

        $response->assertRedirect();

        $appel = SeanceAppel::where('schedule_slot_id', $this->slotA->id)
            ->whereDate('date', '2026-10-09')
            ->first();

        $this->assertNotNull($appel);
        $this->assertSame((int) $this->enseignantA->id, (int) $appel->teacher_id);
        $this->assertSame('en_cours', $appel->statut);
        $this->assertFalse($appel->estCloture());

        $response->assertRedirect(route('enseignant.appel.feuille', $appel));
    }

    /**
     * Test 2 : Un enseignant ne peut pas ouvrir la séance d’un autre enseignant.
     */
    public function test_enseignant_ne_peut_pas_ouvrir_la_seance_d_un_autre_enseignant(): void
    {
        // Enseignant B tente d'ouvrir la séance de Enseignant A
        $response = $this->withSession(['enseignant_auth_id' => $this->enseignantB->id])
            ->get(route('enseignant.appel.ouvrir', [
                'slot' => $this->slotA->id,
                'date' => '2026-10-09',
            ]));

        $response->assertNotFound();

        $this->assertDatabaseMissing('seance_appels', [
            'schedule_slot_id' => $this->slotA->id,
            'teacher_id' => $this->enseignantB->id,
        ]);
    }

    /**
     * Test 3 : Un utilisateur non authentifié ne peut pas enregistrer de présence.
     */
    public function test_utilisateur_non_authentifie_ne_peut_pas_enregistrer_de_presence(): void
    {
        $appel = SeanceAppel::create([
            'schedule_slot_id' => $this->slotA->id,
            'date' => '2026-10-09',
            'statut' => 'en_cours',
            'teacher_id' => $this->enseignantA->id,
        ]);

        $response = $this->post(route('enseignant.appel.enregistrer', $appel), [
            'releves' => [
                ['etudiant_id' => $this->etudiant1->id, 'statut' => 'present'],
            ],
        ]);

        $response->assertRedirect(route('enseignant.connexion.show'));

        $this->assertDatabaseMissing('presences', [
            'etudiant_id' => $this->etudiant1->id,
            'schedule_slot_id' => $this->slotA->id,
        ]);
    }

    /**
     * Test 4 : Un étudiant hors du groupe ne peut pas être ajouté à l’appel.
     */
    public function test_etudiant_hors_du_groupe_ne_peut_pas_etre_ajoute_a_l_appel(): void
    {
        $appel = SeanceAppel::create([
            'schedule_slot_id' => $this->slotA->id,
            'date' => '2026-10-09',
            'statut' => 'en_cours',
            'teacher_id' => $this->enseignantA->id,
        ]);

        $response = $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->post(route('enseignant.appel.enregistrer', $appel), [
                'releves' => [
                    ['etudiant_id' => $this->etudiant1->id, 'statut' => 'present'],
                    ['etudiant_id' => $this->etudiantHorsGroupe->id, 'statut' => 'present'],
                ],
            ]);

        $response->assertSessionHasErrors('appel');

        $this->assertDatabaseMissing('presences', [
            'etudiant_id' => $this->etudiantHorsGroupe->id,
            'schedule_slot_id' => $this->slotA->id,
        ]);
    }

    /**
     * Test 5 : Les statuts Présent, Absent, En retard et Non renseigné sont correctement persistés.
     */
    public function test_statuts_present_absent_retard_et_non_renseigne_sont_correctement_persistes(): void
    {
        $appel = SeanceAppel::create([
            'schedule_slot_id' => $this->slotA->id,
            'date' => '2026-10-09',
            'statut' => 'en_cours',
            'teacher_id' => $this->enseignantA->id,
        ]);

        $response = $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->post(route('enseignant.appel.enregistrer', $appel), [
                'releves' => [
                    ['etudiant_id' => $this->etudiant1->id, 'statut' => 'present'],
                    ['etudiant_id' => $this->etudiant2->id, 'statut' => 'absent'],
                    ['etudiant_id' => $this->etudiant3->id, 'statut' => 'retard'],
                ],
            ]);

        $response->assertRedirect(route('enseignant.appel.feuille', $appel));

        $this->assertDatabaseHas('presences', [
            'etudiant_id' => $this->etudiant1->id,
            'schedule_slot_id' => $this->slotA->id,
            'statut' => 'present',
        ]);
        $this->assertDatabaseHas('presences', [
            'etudiant_id' => $this->etudiant2->id,
            'schedule_slot_id' => $this->slotA->id,
            'statut' => 'absent',
        ]);
        $this->assertDatabaseHas('presences', [
            'etudiant_id' => $this->etudiant3->id,
            'schedule_slot_id' => $this->slotA->id,
            'statut' => 'retard',
        ]);

        $appel->refresh();
        $this->assertSame(1, (int) $appel->presents);
        $this->assertSame(1, (int) $appel->absents);
        $this->assertSame(1, (int) $appel->retards);
        $this->assertSame(0, (int) $appel->non_renseignes);
    }

    /**
     * Test 6 : Un étudiant non traité n’est pas automatiquement marqué absent.
     */
    public function test_etudiant_non_traite_n_est_pas_automatiquement_marque_absent(): void
    {
        $appel = SeanceAppel::create([
            'schedule_slot_id' => $this->slotA->id,
            'date' => '2026-10-09',
            'statut' => 'en_cours',
            'teacher_id' => $this->enseignantA->id,
        ]);

        // On ne marque que l'étudiant 1, les étudiants 2 et 3 sont non traités
        $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->post(route('enseignant.appel.enregistrer', $appel), [
                'releves' => [
                    ['etudiant_id' => $this->etudiant1->id, 'statut' => 'present'],
                ],
            ]);

        // Les étudiants 2 et 3 ne doivent avoir AUCUNE ligne dans presences
        $this->assertDatabaseMissing('presences', [
            'etudiant_id' => $this->etudiant2->id,
            'schedule_slot_id' => $this->slotA->id,
        ]);
        $this->assertDatabaseMissing('presences', [
            'etudiant_id' => $this->etudiant3->id,
            'schedule_slot_id' => $this->slotA->id,
        ]);

        $appel->refresh();
        $this->assertSame(1, (int) $appel->presents);
        $this->assertSame(0, (int) $appel->absents);
        $this->assertSame(2, (int) $appel->non_renseignes);
    }

    /**
     * Test 7 : Les actions groupées sont correctement validées.
     */
    public function test_actions_groupees_sont_correctement_validees(): void
    {
        $appel = SeanceAppel::create([
            'schedule_slot_id' => $this->slotA->id,
            'date' => '2026-10-09',
            'statut' => 'en_cours',
            'teacher_id' => $this->enseignantA->id,
        ]);

        // Action groupée "Tout marquer présent"
        $response = $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->post(route('enseignant.appel.enregistrer', $appel), [
                'releves' => [
                    ['etudiant_id' => $this->etudiant1->id, 'statut' => 'present'],
                    ['etudiant_id' => $this->etudiant2->id, 'statut' => 'present'],
                    ['etudiant_id' => $this->etudiant3->id, 'statut' => 'present'],
                ],
            ]);

        $response->assertRedirect(route('enseignant.appel.feuille', $appel));

        $this->assertSame(3, Presence::where('schedule_slot_id', $this->slotA->id)->where('statut', 'present')->count());

        $appel->refresh();
        $this->assertSame(3, (int) $appel->presents);
        $this->assertSame(0, (int) $appel->absents);
        $this->assertSame(0, (int) $appel->non_renseignes);
    }

    /**
     * Test 8 : Un appel enregistré peut être repris selon les règles métier.
     */
    public function test_appel_enregistre_peut_etre_repris_selon_les_regles_metier(): void
    {
        $appel = SeanceAppel::create([
            'schedule_slot_id' => $this->slotA->id,
            'date' => '2026-10-09',
            'statut' => 'en_cours',
            'teacher_id' => $this->enseignantA->id,
        ]);

        // Premier passage partiel
        $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->post(route('enseignant.appel.enregistrer', $appel), [
                'releves' => [
                    ['etudiant_id' => $this->etudiant1->id, 'statut' => 'present'],
                ],
            ]);

        // L'enseignant recharge la feuille
        $response = $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->get(route('enseignant.appel.feuille', $appel));

        $response->assertOk();
        $response->assertSee('Mansour');
        $response->assertSee('Kefi');

        // Deuxième passage : mise à jour et complétion
        $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->post(route('enseignant.appel.enregistrer', $appel), [
                'releves' => [
                    ['etudiant_id' => $this->etudiant1->id, 'statut' => 'present'],
                    ['etudiant_id' => $this->etudiant2->id, 'statut' => 'absent'],
                    ['etudiant_id' => $this->etudiant3->id, 'statut' => 'present'],
                ],
            ]);

        $this->assertSame(3, Presence::where('schedule_slot_id', $this->slotA->id)->count());
        $this->assertSame(2, Presence::where('schedule_slot_id', $this->slotA->id)->where('statut', 'present')->count());
        $this->assertSame(1, Presence::where('schedule_slot_id', $this->slotA->id)->where('statut', 'absent')->count());
    }

    /**
     * Test 9 : Les doublons sont empêchés.
     */
    public function test_les_doublons_sont_empeches(): void
    {
        $appel = SeanceAppel::create([
            'schedule_slot_id' => $this->slotA->id,
            'date' => '2026-10-09',
            'statut' => 'en_cours',
            'teacher_id' => $this->enseignantA->id,
        ]);

        // Première soumission
        $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->post(route('enseignant.appel.enregistrer', $appel), [
                'releves' => [
                    ['etudiant_id' => $this->etudiant1->id, 'statut' => 'present'],
                ],
            ]);

        // Seconde soumission identique (re-clic / réseau)
        $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->post(route('enseignant.appel.enregistrer', $appel), [
                'releves' => [
                    ['etudiant_id' => $this->etudiant1->id, 'statut' => 'present'],
                ],
            ]);

        $this->assertSame(
            1,
            Presence::where('schedule_slot_id', $this->slotA->id)
                ->where('etudiant_id', $this->etudiant1->id)
                ->count()
        );
    }

    /**
     * Test 10 : La clôture traite explicitement les étudiants non renseignés.
     */
    public function test_la_cloture_traite_explicitement_les_etudiants_non_renseignes(): void
    {
        $appel = SeanceAppel::create([
            'schedule_slot_id' => $this->slotA->id,
            'date' => '2026-10-09',
            'statut' => 'en_cours',
            'teacher_id' => $this->enseignantA->id,
        ]);

        // Seulement l'étudiant 1 est marqué
        $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->post(route('enseignant.appel.enregistrer', $appel), [
                'releves' => [
                    ['etudiant_id' => $this->etudiant1->id, 'statut' => 'present'],
                ],
            ]);

        // Tentative de clôture directe sans confirmation explicite des non-renseignés
        $responseRefus = $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->post(route('enseignant.appel.cloturer', $appel), [
                'confirmer_incomplet' => 0,
            ]);

        $responseRefus->assertSessionHasErrors('appel');
        $appel->refresh();
        $this->assertSame('en_cours', $appel->statut);

        // Clôture avec confirmation explicite
        $responseSucces = $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->post(route('enseignant.appel.cloturer', $appel), [
                'confirmer_incomplet' => 1,
            ]);

        $responseSucces->assertRedirect(route('enseignant.appel.feuille', $appel));

        $appel->refresh();
        $this->assertSame('cloture', $appel->statut);
        $this->assertNotNull($appel->cloture_le);
        $this->assertSame(1, (int) $appel->presents);
        $this->assertSame(0, (int) $appel->absents);
        $this->assertSame(2, (int) $appel->non_renseignes);

        // Les étudiants non renseignés ne doivent toujours pas être créés en absents
        $this->assertDatabaseMissing('presences', ['etudiant_id' => $this->etudiant2->id]);
    }

    /**
     * Test 11 : Les corrections sont autorisées, motivées et tracées.
     */
    public function test_les_corrections_sont_autorisees_motivees_et_tracees(): void
    {
        $appel = SeanceAppel::create([
            'schedule_slot_id' => $this->slotA->id,
            'date' => '2026-10-09',
            'statut' => 'cloture',
            'teacher_id' => $this->enseignantA->id,
            'presents' => 3,
            'absents' => 0,
            'retards' => 0,
            'non_renseignes' => 0,
            'cloture_le' => now(),
        ]);

        Presence::create([
            'etudiant_id' => $this->etudiant1->id,
            'schedule_slot_id' => $this->slotA->id,
            'teacher_id' => $this->enseignantA->id,
            'date' => '2026-10-09',
            'statut' => 'present',
            'seance' => 'APPEL-'.$this->slotA->id.'-20261009',
        ]);

        // Modification normale (enregistrer) refusée car l'appel est clôturé
        $responseEnregistrerRefus = $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->post(route('enseignant.appel.enregistrer', $appel), [
                'releves' => [
                    ['etudiant_id' => $this->etudiant1->id, 'statut' => 'absent'],
                ],
            ]);
        $responseEnregistrerRefus->assertSessionHasErrors('appel');

        // Correction sans motif refusée
        $responseSansMotif = $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->post(route('enseignant.appel.corriger', $appel), [
                'etudiant_id' => $this->etudiant1->id,
                'statut' => 'absent',
                'motif' => '',
            ]);
        $responseSansMotif->assertSessionHasErrors('motif');

        // Correction motivée autorisée
        $responseSucces = $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->post(route('enseignant.appel.corriger', $appel), [
                'etudiant_id' => $this->etudiant1->id,
                'statut' => 'absent',
                'motif' => 'Erreur de saisie pendant la séance, l’étudiant était absent.',
            ]);

        $responseSucces->assertRedirect(route('enseignant.appel.feuille', $appel));

        $presence = Presence::where('etudiant_id', $this->etudiant1->id)
            ->where('schedule_slot_id', $this->slotA->id)
            ->first();

        $this->assertNotNull($presence);
        $this->assertSame('absent', $presence->statut);
        $this->assertSame('Erreur de saisie pendant la séance, l’étudiant était absent.', $presence->motif);
        $historique = $presence->historique ?? [];
        $trace = end($historique);
        $this->assertSame('correction_appel', $trace['action']);
        $this->assertSame('present', $trace['details']['ancien_statut']);
        $this->assertSame('absent', $trace['details']['nouveau_statut']);
    }

    /**
     * Test 12 : L’isolation entre facultés est respectée.
     */
    public function test_l_isolation_entre_facultes_est_respectee(): void
    {
        // Enseignant B (Faculté de Droit) tente d'ouvrir une séance de la Faculté des Sciences
        $slotFaculteA = ScheduleSlot::create([
            'schedule_id' => $this->planningA->id,
            'matiere_id' => $this->matiereA->id,
            'teacher_id' => $this->enseignantB->id, // affecté anormalement sur un groupe FST
            'room_id' => $this->salleA->id,
            'day_of_week' => 'friday',
            'start_time' => '10:00:00',
            'end_time' => '11:30:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $response = $this->withSession(['enseignant_auth_id' => $this->enseignantB->id])
            ->get(route('enseignant.appel.ouvrir', [
                'slot' => $slotFaculteA->id,
                'date' => '2026-10-09',
            ]));

        $response->assertForbidden();
    }

    /**
     * Test 13 : Les effectifs et compteurs correspondent aux données enregistrées.
     */
    public function test_effectifs_et_compteurs_correspondent_aux_donnees_enregistrees(): void
    {
        $appel = SeanceAppel::create([
            'schedule_slot_id' => $this->slotA->id,
            'date' => '2026-10-09',
            'statut' => 'en_cours',
            'teacher_id' => $this->enseignantA->id,
        ]);

        $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->post(route('enseignant.appel.enregistrer', $appel), [
                'releves' => [
                    ['etudiant_id' => $this->etudiant1->id, 'statut' => 'present'],
                    ['etudiant_id' => $this->etudiant2->id, 'statut' => 'retard'],
                ],
            ]);

        $service = app(AppelPresenceService::class);
        $compteurs = $service->compteurs($appel);

        $this->assertSame(1, $compteurs['presents']);
        $this->assertSame(0, $compteurs['absents']);
        $this->assertSame(1, $compteurs['retards']);
        $this->assertSame(1, $compteurs['non_renseignes']);

        $totalEffectif = $compteurs['presents'] + $compteurs['absents'] + $compteurs['retards'] + $compteurs['non_renseignes'];
        $this->assertSame(3, $totalEffectif);
    }

    /**
     * Test 14 : Les accès concurrents et doubles soumissions ne corrompent pas les données.
     */
    public function test_acces_concurrents_et_doubles_soumissions_ne_corrompent_pas_les_donnees(): void
    {
        $service = app(AppelPresenceService::class);

        // Simulation de 2 appels ouvrir simultanés (double clic réseau)
        $appel1 = $service->ouvrir($this->slotA, '2026-10-09', $this->enseignantA);
        $appel2 = $service->ouvrir($this->slotA, '2026-10-09', $this->enseignantA);

        $this->assertSame($appel1->id, $appel2->id);
        $this->assertSame(1, SeanceAppel::where('schedule_slot_id', $this->slotA->id)->whereDate('date', '2026-10-09')->count());

        // Soumission concurrente de deux relevés différents pour le même étudiant
        $service->enregistrer($appel1, [
            ['etudiant_id' => $this->etudiant1->id, 'statut' => 'present'],
        ], $this->enseignantA);

        $service->enregistrer($appel2, [
            ['etudiant_id' => $this->etudiant1->id, 'statut' => 'absent'],
        ], $this->enseignantA);

        $this->assertSame(
            1,
            Presence::where('schedule_slot_id', $this->slotA->id)
                ->where('etudiant_id', $this->etudiant1->id)
                ->count()
        );
    }

    /**
     * Test 15 : Les performances sont vérifiées sur un groupe de grande taille (100 étudiants).
     */
    public function test_performances_sur_un_groupe_de_grande_taille(): void
    {
        $grandGroupe = Group::create([
            'nom' => 'Grande Promotion Info',
            'code' => 'PROMO-100',
            'niveau' => 'L1',
            'faculte_id' => $this->faculteA->id,
            'annee_universitaire_id' => $this->annee->id,
            'actif' => true,
        ]);

        $planningGrand = Schedule::create([
            'nom' => 'EDT Promo 100',
            'groupe_id' => $grandGroupe->id,
            'annee_universitaire_id' => $this->annee->id,
            'semestre' => 1,
            'actif' => true,
            'statut' => 'publie',
        ]);

        $slotGrand = ScheduleSlot::create([
            'schedule_id' => $planningGrand->id,
            'matiere_id' => $this->matiereA->id,
            'teacher_id' => $this->enseignantA->id,
            'room_id' => $this->salleA->id,
            'day_of_week' => 'friday',
            'start_time' => '14:00:00',
            'end_time' => '16:00:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        // Création de 100 étudiants
        $relevesPayload = [];
        for ($i = 1; $i <= 100; $i++) {
            $e = $this->creerEtudiant([
                'nom' => "Etudiant{$i}",
                'prenom' => "Test{$i}",
                'cin' => sprintf('88%06d', $i),
                'sexe' => ($i % 2 === 0 ? 'F' : 'M'),
                'email' => "etudiant{$i}@promo.tn",
                'faculte_id' => $this->faculteA->id,
                'groupe_id' => $grandGroupe->id,
            ]);

            $statut = ($i % 5 === 0) ? 'absent' : (($i % 10 === 0) ? 'retard' : 'present');
            $relevesPayload[] = ['etudiant_id' => $e->id, 'statut' => $statut];
        }

        $appel = SeanceAppel::create([
            'schedule_slot_id' => $slotGrand->id,
            'date' => '2026-10-09',
            'statut' => 'en_cours',
            'teacher_id' => $this->enseignantA->id,
        ]);

        // Mesure du temps d'enregistrement pour 100 étudiants
        $debut = microtime(true);

        $response = $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->post(route('enseignant.appel.enregistrer', $appel), [
                'releves' => $relevesPayload,
            ]);

        $dureeMs = (microtime(true) - $debut) * 1000;

        $response->assertRedirect();
        $this->assertSame(100, Presence::where('schedule_slot_id', $slotGrand->id)->count());

        // Doit s'exécuter rapidement (seuil large 2500ms sous SQLite test)
        $this->assertLessThan(2500, $dureeMs, "L'enregistrement de 100 étudiants a pris trop de temps ({$dureeMs} ms).");
    }

    private function creerEtudiant(array $attributs = []): Etudiant
    {
        static $compteur = 1;
        $num = $compteur++;

        return Etudiant::create(array_merge([
            'cin' => sprintf('0987%04d', $num),
            'nom' => "Nom{$num}",
            'prenom' => "Prenom{$num}",
            'email' => "etudiant{$num}@universite.tn",
            'date_naissance' => '2004-03-15',
            'sexe' => 'M',
            'niveau' => 'L3',
            'annee_academique' => '2026-2027',
            'adresse' => 'Avenue Habib Bourguiba',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2023',
            'faculte_id' => $this->faculteA->id,
            'groupe_id' => $this->groupeA->id,
            'statut' => 'valide',
        ], $attributs));
    }
}
