<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\AppNotification;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\TeacherDocument;
use App\Models\TeacherReclamation;
use App\Models\TeacherRequest;
use App\Modules\Attendance\Models\TeacherPointage;
use App\Modules\Attendance\Models\TeacherPresence;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Suite complète de tests pour l'Espace Enseignant :
 * - Authentification et cycle de session
 * - Tableau de bord et calcul des indicateurs réels
 * - Consultation et modification du profil (champs autorisés vs protégés)
 * - Documents privés et isolation anti-IDOR
 * - Emploi du temps personnel et exclusion des tiers
 * - Présences et pointages personnels
 * - Surveillances d'examen et distinction du rôle 'reserve'
 * - Demandes administratives et workflow d'annulation
 * - Réclamations et traçabilité
 * - Notifications et contrôle de propriété
 */
class EnseignantPortailTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private Departement $departementA;

    private Departement $departementB;

    private Teacher $enseignantA;

    private Teacher $enseignantB;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->faculteA = Faculte::create(['nom' => 'Faculté des Sciences', 'code' => 'FS', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'Faculté de Droit', 'code' => 'FD', 'active' => true]);

        $this->departementA = Departement::create([
            'code' => 'DEP-INFO', 'nom' => 'Informatique', 'faculte_id' => $this->faculteA->id,
        ]);
        $this->departementB = Departement::create([
            'code' => 'DEP-DROIT', 'nom' => 'Droit Privé', 'faculte_id' => $this->faculteB->id,
        ]);

        $this->enseignantA = Teacher::create([
            'nom' => 'Ben Ali', 'prenom' => 'Sami',
            'email' => 'sami.benali@univ.test',
            'matricule' => 'ENS-001',
            'telephone' => '0611223344',
            'bureau' => 'B204',
            'departement_id' => $this->departementA->id,
            'actif' => true, 'statut' => 'actif',
            'password' => 'Secret@123',
        ]);

        $this->enseignantB = Teacher::create([
            'nom' => 'Mansour', 'prenom' => 'Leila',
            'email' => 'leila.mansour@univ.test',
            'matricule' => 'ENS-002',
            'telephone' => '0655667788',
            'bureau' => 'C101',
            'departement_id' => $this->departementB->id,
            'actif' => true, 'statut' => 'actif',
            'password' => 'Secret@123',
        ]);
    }

    /* ── 1. AUTHENTIFICATION & SÉCURITÉ DE SESSION ── */

    public function test_connexion_enseignant_valide_avec_email(): void
    {
        $response = $this->post(route('enseignant.connexion.login'), [
            'identifiant' => 'sami.benali@univ.test',
            'password' => 'Secret@123',
        ]);

        $response->assertRedirect(route('enseignant.dashboard'));
        $this->assertSame($this->enseignantA->id, session('enseignant_auth_id'));
    }

    public function test_connexion_enseignant_valide_avec_matricule(): void
    {
        $response = $this->post(route('enseignant.connexion.login'), [
            'identifiant' => 'ens-001', // insensible à la casse
            'password' => 'Secret@123',
        ]);

        $response->assertRedirect(route('enseignant.dashboard'));
        $this->assertSame($this->enseignantA->id, session('enseignant_auth_id'));
    }

    public function test_connexion_refusee_si_compte_inactif(): void
    {
        $this->enseignantA->update(['actif' => false]);

        $response = $this->post(route('enseignant.connexion.login'), [
            'identifiant' => 'sami.benali@univ.test',
            'password' => 'Secret@123',
        ]);

        $response->assertSessionHasErrors(['identifiant']);
        $this->assertFalse(session()->has('enseignant_auth_id'));
    }

    public function test_redirection_invites_vers_connexion(): void
    {
        $this->get(route('enseignant.dashboard'))->assertRedirect(route('enseignant.connexion.show'));
        $this->get(route('enseignant.documents.index'))->assertRedirect(route('enseignant.connexion.show'));
        $this->get(route('enseignant.emploi-du-temps.semaine'))->assertRedirect(route('enseignant.connexion.show'));
        $this->get(route('enseignant.presences.index'))->assertRedirect(route('enseignant.connexion.show'));
        $this->get(route('enseignant.surveillances.index'))->assertRedirect(route('enseignant.connexion.show'));
        $this->get(route('enseignant.demandes.index'))->assertRedirect(route('enseignant.connexion.show'));
        $this->get(route('enseignant.reclamations.index'))->assertRedirect(route('enseignant.connexion.show'));
    }

    public function test_deconnexion_purifie_la_session(): void
    {
        $response = $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->post(route('enseignant.connexion.logout'));

        $response->assertRedirect(route('enseignant.connexion.show'));
        $this->assertFalse(session()->has('enseignant_auth_id'));
    }

    /* ── 2. TABLEAU DE BORD & KPIS ── */

    public function test_tableau_de_bord_affiche_indicateurs_reels(): void
    {
        // Création d'une demande et d'une réclamation
        TeacherRequest::create([
            'reference' => 'DEM-001', 'teacher_id' => $this->enseignantA->id,
            'type_demande' => 'conge', 'objet' => 'Congé annuel', 'description' => 'Demande de test',
            'statut' => 'en_cours',
        ]);

        TeacherReclamation::create([
            'reference' => 'REC-001', 'teacher_id' => $this->enseignantA->id,
            'categorie' => 'emploi_du_temps', 'objet' => 'Erreur planning', 'description' => 'Conflit de test',
            'statut' => 'ouverte',
        ]);

        AppNotification::create([
            'teacher_id' => $this->enseignantA->id, 'type' => 'info',
            'titre' => 'Alerte test', 'message' => 'Contenu alerte',
        ]);

        $response = $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->get(route('enseignant.dashboard'));

        $response->assertOk()
            ->assertSee('Bonjour Sami')
            ->assertSee('Demandes en cours');
    }

    /* ── 3. PROFIL ENSEIGNANT ── */

    public function test_profil_affiche_donnees_et_met_a_jour_coordonnees(): void
    {
        $response = $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->patch(route('enseignant.profil.update'), [
                'email' => 'sami.nouveau@univ.test',
                // L'email est un identifiant de connexion : le changer exige
                // le mot de passe actuel (LOOP-SECURITY-REMEDIATION-01).
                'current_password' => 'Secret@123',
                'telephone' => '0699887766',
                'bureau' => 'B301',
                // Tentative d'injection de champs administratifs
                'matricule' => 'PIRATE',
                'statut' => 'inactif',
                'departement_id' => 9999,
            ]);

        $response->assertRedirect(route('enseignant.profil.show'));

        $frais = $this->enseignantA->fresh();
        $this->assertSame('sami.nouveau@univ.test', $frais->email);
        $this->assertSame('0699887766', $frais->telephone);
        $this->assertSame('B301', $frais->bureau);
        // Les champs administratifs restent strictement intacts
        $this->assertSame('ENS-001', $frais->matricule);
        $this->assertSame('actif', $frais->statut);
        $this->assertSame($this->departementA->id, $frais->departement_id);
    }

    /* ── 4. DOCUMENTS & ANTI-IDOR ── */

    public function test_consultation_et_telechargement_document_autorise(): void
    {
        $cheminFichier = 'private/enseignant/documents/attestation.pdf';
        Storage::disk('private')->put($cheminFichier, 'CONTENU_SECRET_ATTESTATION');

        $docA = TeacherDocument::create([
            'teacher_id' => $this->enseignantA->id,
            'faculte_id' => $this->faculteA->id,
            'titre' => 'Attestation de travail 2026',
            'categorie' => 'attestation',
            'nom_fichier' => 'attestation.pdf',
            'chemin' => $cheminFichier,
            'mime_type' => 'application/pdf',
            'taille' => 1024,
        ]);

        $response = $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->get(route('enseignant.documents.telecharger', $docA));

        $response->assertOk();
    }

    public function test_interdiction_anti_idor_telechargement_document_autre_enseignant(): void
    {
        $cheminFichier = 'private/enseignant/documents/doc_leila.pdf';
        Storage::disk('private')->put($cheminFichier, 'DONNEES_PRIVEES_LEILA');

        $docB = TeacherDocument::create([
            'teacher_id' => $this->enseignantB->id,
            'faculte_id' => $this->faculteB->id,
            'titre' => 'Dossier personnel Leila',
            'categorie' => 'administratif',
            'nom_fichier' => 'doc_leila.pdf',
            'chemin' => $cheminFichier,
            'mime_type' => 'application/pdf',
            'taille' => 1024,
        ]);

        // L'enseignant A tente d'accéder au document de l'enseignant B
        $response = $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->get(route('enseignant.documents.telecharger', $docB));

        // Rejet 404 strict anti-énumération et anti-IDOR
        $response->assertStatus(404);
    }

    /* ── 5. EMPLOI DU TEMPS ── */

    public function test_emploi_du_temps_affiche_creneaux_propres_et_isole_tiers(): void
    {
        $salle = Room::create(['code' => 'AMPHI-A', 'nom' => 'Amphi A', 'capacite' => 100]);
        $matiereA = Matiere::create(['code' => 'INFO101', 'nom' => 'Algorithmique', 'niveau' => 'L1', 'semestre' => 1]);
        $matiereB = Matiere::create(['code' => 'DROIT101', 'nom' => 'Droit Civil', 'niveau' => 'L1', 'semestre' => 1]);

        $groupe = Group::create(['code' => 'G1', 'nom' => 'Groupe 1', 'faculte_id' => $this->faculteA->id, 'niveau' => 'L1']);
        $schedule = Schedule::create(['nom' => 'EDT G1 S1', 'groupe_id' => $groupe->id, 'annee_universitaire_id' => $this->annee->id, 'semestre' => 1]);

        $slotA = ScheduleSlot::create([
            'schedule_id' => $schedule->id,
            'matiere_id' => $matiereA->id,
            'teacher_id' => $this->enseignantA->id,
            'room_id' => $salle->id,
            'day_of_week' => 'monday',
            'start_time' => '08:30:00',
            'end_time' => '10:30:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $slotB = ScheduleSlot::create([
            'schedule_id' => $schedule->id,
            'matiere_id' => $matiereB->id,
            'teacher_id' => $this->enseignantB->id,
            'room_id' => $salle->id,
            'day_of_week' => 'monday',
            'start_time' => '10:30:00',
            'end_time' => '12:30:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $response = $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->get(route('enseignant.emploi-du-temps.semaine'));

        $response->assertOk()
            ->assertSee('Algorithmique')
            ->assertDontSee('Droit Civil');
    }

    /* ── 6. PRÉSENCES ── */

    public function test_presences_affiche_pointages_et_seances_personnelles(): void
    {
        $salle = Room::create(['code' => 'SALLE-1', 'nom' => 'Salle 1', 'capacite' => 30]);
        $matiere = Matiere::create(['code' => 'DEV1', 'nom' => 'Programmation Web', 'niveau' => 'L1', 'semestre' => 1]);
        $groupe = Group::create(['code' => 'G2', 'nom' => 'Groupe 2', 'faculte_id' => $this->faculteA->id, 'niveau' => 'L1']);
        $schedule = Schedule::create(['nom' => 'EDT G2 S1', 'groupe_id' => $groupe->id, 'annee_universitaire_id' => $this->annee->id, 'semestre' => 1]);

        $slot = ScheduleSlot::create([
            'schedule_id' => $schedule->id,
            'matiere_id' => $matiere->id,
            'teacher_id' => $this->enseignantA->id,
            'room_id' => $salle->id,
            'day_of_week' => 'tuesday',
            'start_time' => '14:00:00',
            'end_time' => '16:00:00',
            'type' => 'td',
            'status' => 'scheduled',
        ]);

        TeacherPresence::create([
            'schedule_slot_id' => $slot->id,
            'teacher_id' => $this->enseignantA->id,
            'date' => Carbon::today()->toDateString(),
            'heure_arrivee' => '14:05:00',
            'statut_calcule' => 'retard_potentiel',
            'retard_minutes' => 5,
        ]);

        TeacherPointage::create([
            'teacher_id' => $this->enseignantA->id,
            'date' => Carbon::today()->toDateString(),
            'horodatage' => Carbon::today()->setTime(14, 5),
            'type' => 'entree',
            'source' => 'badgeuse',
        ]);

        $response = $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->get(route('enseignant.presences.index'));

        $response->assertOk()
            ->assertSee('Programmation Web')
            ->assertSee('Retard potentiel')
            ->assertSee('badgeuse');
    }

    /* ── 7. EXAMENS & SURVEILLANCES (DISTINCTION RESERVE) ── */

    public function test_surveillances_affiche_gardes_et_distingue_reserve(): void
    {
        $session = SessionExamen::create([
            'code' => 'SESS-2027-01',
            'nom' => 'Session Principale Janvier 2027',
            'type' => 'principale',
            'annee_universitaire_id' => $this->annee->id,
            'date_debut' => '2027-01-05',
            'date_fin' => '2027-01-20',
            'statut' => 'open',
        ]);

        $matiere = Matiere::create(['code' => 'EXAM1', 'nom' => 'Systèmes d\'Exploitation', 'niveau' => 'L2', 'semestre' => 3]);
        $salle = Room::create(['code' => 'AMPHI-B', 'nom' => 'Amphi B', 'capacite' => 120]);

        $examen = Examen::create([
            'code' => 'EXAM-SE',
            'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examen SE',
            'type' => 'examen',
            'statut' => 'scheduled',
        ]);

        $epreuve = Epreuve::create([
            'code' => 'EPR-SE-01',
            'examen_id' => $examen->id,
            'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $matiere->id,
            'salle_id' => $salle->id,
            'date' => '2027-01-10',
            'heure_debut' => '09:00:00',
            'heure_fin' => '11:00:00',
            'duree_minutes' => 120,
            'statut' => 'scheduled',
        ]);

        Surveillance::create([
            'epreuve_id' => $epreuve->id,
            'teacher_id' => $this->enseignantA->id,
            'salle_id' => $salle->id,
            'role' => 'reserve', // Rôle garde/réserve explicite
            'statut' => 'assigne',
        ]);

        $response = $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->get(route('enseignant.surveillances.index'));

        $response->assertOk()
            ->assertSee('Systèmes d\'Exploitation')
            ->assertSee('Garde / Réserve');
    }

    /* ── 8. DEMANDES EN LIGNE & WORKFLOW ── */

    public function test_creation_et_annulation_demande_administrative(): void
    {
        $fichier = UploadedFile::fake()->create('ordre_mission.pdf', 200, 'application/pdf');

        $response = $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->post(route('enseignant.demandes.store'), [
                'type_demande' => 'ordre_mission',
                'objet' => 'Mission colloque scientifique',
                'description' => 'Participation au colloque national d\'informatique.',
                'piece_jointe' => $fichier,
            ]);

        $demande = TeacherRequest::where('teacher_id', $this->enseignantA->id)->first();
        $this->assertNotNull($demande);
        $this->assertSame('soumise', $demande->statut);
        $this->assertNotNull($demande->piece_jointe_chemin);

        $response->assertRedirect(route('enseignant.demandes.show', $demande));

        // L'enseignant annule sa demande tant qu'elle est à l'état 'soumise'
        $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->post(route('enseignant.demandes.annuler', $demande))
            ->assertRedirect(route('enseignant.demandes.show', $demande));

        $this->assertSame('annulee', $demande->fresh()->statut);
    }

    public function test_interdiction_anti_idor_demande_autre_enseignant(): void
    {
        $demandeB = TeacherRequest::create([
            'reference' => 'DEM-B-001',
            'teacher_id' => $this->enseignantB->id,
            'type_demande' => 'conge',
            'objet' => 'Demande confidentielle Leila',
            'description' => 'Description privée',
            'statut' => 'soumise',
        ]);

        // L'enseignant A tente d'accéder ou annuler la demande de l'enseignant B
        $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->get(route('enseignant.demandes.show', $demandeB))
            ->assertStatus(404);

        $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->post(route('enseignant.demandes.annuler', $demandeB))
            ->assertStatus(404);
    }

    /* ── 9. RÉCLAMATIONS ET SIGNALEMENTS ── */

    public function test_depot_reclamation_et_cloture(): void
    {
        $response = $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->post(route('enseignant.reclamations.store'), [
                'categorie' => 'emploi_du_temps',
                'objet' => 'Chevauchement de cours le jeudi',
                'description' => 'Deux cours programmés simultanément en salle 102 et 204.',
                'priorite' => 'urgente',
            ]);

        $reclamation = TeacherReclamation::where('teacher_id', $this->enseignantA->id)->first();
        $this->assertNotNull($reclamation);
        $this->assertSame('ouverte', $reclamation->statut);

        $response->assertRedirect(route('enseignant.reclamations.show', $reclamation));

        // L'enseignant clôture sa réclamation
        $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->post(route('enseignant.reclamations.cloturer', $reclamation))
            ->assertRedirect(route('enseignant.reclamations.show', $reclamation));

        $this->assertSame('fermee', $reclamation->fresh()->statut);
    }

    public function test_interdiction_anti_idor_reclamation_autre_enseignant(): void
    {
        $reclamationB = TeacherReclamation::create([
            'reference' => 'REC-B-001',
            'teacher_id' => $this->enseignantB->id,
            'categorie' => 'presence',
            'objet' => 'Contestation pointage Leila',
            'description' => 'Description confidentielle',
            'statut' => 'ouverte',
        ]);

        $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->get(route('enseignant.reclamations.show', $reclamationB))
            ->assertStatus(404);

        $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->post(route('enseignant.reclamations.cloturer', $reclamationB))
            ->assertStatus(404);
    }

    /* ── 10. NOTIFICATIONS PERSONNELLES ── */

    public function test_gestion_notifications_personnelles_et_anti_idor(): void
    {
        $notifA = AppNotification::create([
            'teacher_id' => $this->enseignantA->id,
            'type' => 'info',
            'titre' => 'Notification pour Sami',
            'message' => 'Contenu du message',
        ]);

        $notifB = AppNotification::create([
            'teacher_id' => $this->enseignantB->id,
            'type' => 'info',
            'titre' => 'Notification pour Leila',
            'message' => 'Message privé',
        ]);

        // Sami consulte ses notifications : il ne voit que la sienne
        $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->get(route('enseignant.notifications.index'))
            ->assertOk()
            ->assertSee('Notification pour Sami')
            ->assertDontSee('Notification pour Leila');

        // Sami tente de marquer comme lue la notification de Leila : 404
        $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->patch(route('enseignant.notifications.lire', $notifB))
            ->assertStatus(404);

        // Sami marque la sienne comme lue : succès
        $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->patch(route('enseignant.notifications.lire', $notifA))
            ->assertRedirect(route('enseignant.notifications.index'));

        $this->assertNotNull($notifA->fresh()->read_at);
    }
}
