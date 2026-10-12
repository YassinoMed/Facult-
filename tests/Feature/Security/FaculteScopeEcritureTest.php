<?php

namespace Tests\Feature\Security;

use App\Models\AnneeUniversitaire;
use App\Models\AssignmentItem;
use App\Models\AssignmentSimulation;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\RegistrationPeriod;
use App\Models\User;
use App\Modules\Announcements\Models\Annonce;
use App\Modules\Calendar\Models\EvenementCalendrier;
use App\Modules\Enseignement\Models\ComposanteEvaluation;
use App\Modules\Enseignement\Models\SeuilService;
use App\Modules\Enseignement\Models\TypeEnseignement;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\RegleEvaluation;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\StudentArchive\Models\StudentSensitiveRecord;
use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Modules\StudentDossiers\Models\StudentDossier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * Isolation faculté — ÉCRITURES (audit sécurité 04/10/2026).
 *
 * Un `admin_faculte` A ne doit JAMAIS créer, modifier ou supprimer une
 * ressource rattachée à la faculté B, en changeant simplement un ID d'URL
 * ou un identifiant cible dans le corps de la requête. Stratégie du
 * projet : périmètre → 404 (contrôleur), policy → 403.
 *
 * Chaque test PROUVAIT une écriture cross-faculté avant la correction
 * (tous rouges le 04/10/2026 matin).
 */
class FaculteScopeEcritureTest extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private Programme $programmeB;

    private Group $groupeA;

    private Group $groupeB;

    private Niveau $niveauB;

    private Matiere $ecueB;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.schedule.active' => true]);
        config(['modules.evaluation.active' => true]);
        config(['modules.enseignement.active' => true]);
        config(['modules.calendar.active' => true]);
        config(['modules.announcements.active' => true]);
        config(['modules.student-archive.active' => true]);

        $this->faculteA = Faculte::create(['nom' => 'FA', 'code' => 'FA', 'description' => 'T', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'FB', 'code' => 'FB', 'description' => 'T', 'active' => true]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $this->programmeB = Programme::create([
            'faculte_id' => $this->faculteB->id, 'code' => 'PBSEC', 'nom' => 'Prog Secret B',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $this->niveauB = Niveau::create(['programme_id' => $this->programmeB->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $niveauB = $this->niveauB;
        $semestreB = SemestreLmd::create(['niveau_id' => $niveauB->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1]);
        $ueB = UniteEnseignement::create([
            'semestre_id' => $semestreB->id, 'programme_id' => $this->programmeB->id,
            'code' => 'UEB1', 'nom' => 'UE B', 'credits' => 6, 'coefficient' => 2,
        ]);
        $this->ecueB = Matiere::create([
            'code' => 'MATSECB', 'nom' => 'ECUE Secret B', 'ue_id' => $ueB->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 4,
        ]);

        $this->groupeA = Group::create([
            'code' => 'GA-1', 'nom' => 'GA', 'faculte_id' => $this->faculteA->id,
            'niveau' => 'L1', 'filiere' => 'Info', 'capacite' => 30, 'actif' => true,
        ]);
        $this->groupeB = Group::create([
            'code' => 'GB-SECRET', 'nom' => 'GB', 'faculte_id' => $this->faculteB->id,
            'niveau' => 'L1', 'filiere' => 'Info', 'capacite' => 30, 'actif' => true,
        ]);
    }

    private function adminFaculteA(): User
    {
        $user = User::creerCompteBackOffice([
            'name' => 'Admin A', 'email' => uniqid('aa.', true).'@scolarite.test',
            'password' => 'Motdepasse@123', 'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');
        $this->actingAs($user);

        return $user;
    }

    private function etudiantDe(Faculte $faculte): Etudiant
    {
        return Etudiant::create([
            'cin' => 'BK'.random_int(100000, 999999),
            'nom' => 'Iso', 'prenom' => 'Test',
            'email' => uniqid('iso.', true).'@example.com',
            'sexe' => 'M', 'faculte_id' => $faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027',
            'adresse' => '1 rue', 'ville' => 'Sfax',
            'code_postal' => '3000', 'annee_bac' => '2024',
            'password' => 'motdepasse1',
        ]);
    }

    private function sessionExamen(): SessionExamen
    {
        return SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id, 'code' => 'S-P-'.random_int(1000, 9999),
            'nom' => 'Principale', 'type' => 'principale',
            'date_debut' => '2027-01-05', 'date_fin' => '2027-01-20',
            'statut' => 'draft', 'created_by' => $this->auteur()->id,
        ]);
    }

    private function auteur(): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Sco', 'email' => uniqid('sc.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    // ── SCHEDULE ────────────────────────────────────────────────

    public function test_creation_planning_groupe_autre_faculte_refusee(): void
    {
        $this->adminFaculteA();

        $this->post(route('admin.emplois-du-temps.store'), [
            'annee_universitaire_id' => $this->annee->id, 'semestre' => 1,
            'groupe_id' => $this->groupeB->id, 'nom' => 'EDT pirate',
        ])->assertNotFound();

        $this->assertSame(0, Schedule::count());
    }

    public function test_creation_planning_groupe_de_sa_faculte_acceptee(): void
    {
        $this->adminFaculteA();

        $this->post(route('admin.emplois-du-temps.store'), [
            'annee_universitaire_id' => $this->annee->id, 'semestre' => 1,
            'groupe_id' => $this->groupeA->id, 'nom' => 'EDT legitime',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, Schedule::count());
    }

    // ── EVALUATION ──────────────────────────────────────────────

    public function test_creation_examen_programme_autre_faculte_refusee(): void
    {
        $session = $this->sessionExamen();
        $this->adminFaculteA();

        $this->post(route('admin.evaluations.examens.store'), [
            'session_id' => $session->id, 'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programmeB->id, 'libelle' => 'Examen pirate', 'type' => 'examen',
        ])->assertNotFound();

        $this->assertSame(0, Examen::count());
    }

    public function test_creation_epreuve_dans_examen_autre_faculte_refusee(): void
    {
        $session = $this->sessionExamen();
        $examenB = Examen::create([
            'session_id' => $session->id, 'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programmeB->id, 'libelle' => 'Examen B',
            'type' => 'examen', 'statut' => 'draft', 'responsable_id' => $session->created_by,
        ]);

        $this->adminFaculteA();

        $this->post(route('admin.evaluations.epreuves.store', $examenB), [
            'session_id' => $session->id, 'matiere_id' => $this->ecueB->id,
            'date' => '2027-01-06', 'heure_debut' => '08:00', 'heure_fin' => '10:00',
        ])->assertNotFound();

        $this->assertSame(0, Epreuve::count());
    }

    public function test_generation_epreuves_vers_programme_autre_faculte_refusee(): void
    {
        $session = $this->sessionExamen();
        $this->adminFaculteA();

        $this->post(route('admin.evaluations.sessions.generer', $session), [
            'programme_id' => $this->programmeB->id, 'date_debut' => '2027-01-06',
        ])->assertNotFound();

        $this->assertSame(0, Epreuve::count());
    }

    public function test_deliberation_programme_autre_faculte_refusee(): void
    {
        $this->adminFaculteA();

        $this->post(route('admin.evaluations.deliberations.store'), [
            'annee_universitaire_id' => $this->annee->id, 'programme_id' => $this->programmeB->id,
        ])->assertNotFound();

        $this->assertSame(0, Deliberation::count());
    }

    public function test_regle_evaluation_autre_faculte_refusee(): void
    {
        $regleB = RegleEvaluation::create([
            'annee_universitaire_id' => $this->annee->id, 'programme_id' => $this->programmeB->id,
            'seuil_validation' => 10, 'seuil_rattrapage' => 8,
            'arrondi' => '2dec', 'version' => 1, 'actif' => true,
        ]);

        $this->adminFaculteA();

        $this->post(route('admin.evaluations.regles.store'), [
            'annee_universitaire_id' => $this->annee->id, 'programme_id' => $this->programmeB->id,
            'seuil_validation' => 10, 'seuil_rattrapage' => 8, 'arrondi' => '2dec',
        ])->assertNotFound();
        $this->assertSame(1, RegleEvaluation::count());

        $this->patch(route('admin.evaluations.regles.bascule', $regleB))->assertNotFound();
        $this->assertTrue((bool) $regleB->fresh()->actif);
    }

    public function test_listings_evaluation_isoles_par_faculte(): void
    {
        $session = $this->sessionExamen();
        $examenB = Examen::create([
            'session_id' => $session->id, 'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programmeB->id, 'libelle' => 'EXAM-SECRET-B',
            'type' => 'examen', 'statut' => 'scheduled', 'responsable_id' => $session->created_by,
        ]);
        Epreuve::create([
            'examen_id' => $examenB->id, 'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id, 'matiere_id' => $this->ecueB->id,
            'groupe_id' => $this->groupeB->id, 'date' => '2027-01-06',
            'heure_debut' => '08:00', 'heure_fin' => '10:00', 'statut' => 'scheduled',
        ]);
        Deliberation::create([
            'annee_universitaire_id' => $this->annee->id, 'programme_id' => $this->programmeB->id,
            'statut' => 'draft', 'created_by' => $session->created_by,
        ]);

        $this->adminFaculteA();

        $this->get(route('admin.evaluations.examens.index'))->assertOk()->assertDontSee('EXAM-SECRET-B');
        $this->get(route('admin.evaluations.epreuves.index'))->assertOk()->assertDontSee('GB-SECRET');
        $this->get(route('admin.evaluations.deliberations.index'))->assertOk()->assertDontSee('Prog Secret B');
        $this->get(route('admin.evaluations.index'))->assertOk()->assertViewHas('epreuvesSansSalle', 0);
    }

    // ── ENSEIGNEMENT ────────────────────────────────────────────

    public function test_modalites_evaluation_ecue_autre_faculte_refusees(): void
    {
        $auteur = $this->auteur();
        $composanteB = ComposanteEvaluation::create([
            'matiere_id' => $this->ecueB->id, 'code' => 'EX', 'libelle' => 'Examen', 'pourcentage' => 100,
        ]);

        $this->adminFaculteA();

        $this->get(route('admin.enseignements.evaluation.edit', $this->ecueB))->assertNotFound();

        $this->put(route('admin.enseignements.evaluation.composantes', $this->ecueB), [
            'composantes' => [
                ['code' => 'P1', 'libelle' => 'Piratée', 'pourcentage' => 100],
            ],
        ])->assertNotFound();

        $this->patch(route('admin.enseignements.evaluation.responsable', $this->ecueB), [
            'responsable_teacher_id' => null,
        ])->assertNotFound();

        $this->delete(route('admin.enseignements.evaluation.composantes.destroy', $composanteB))
            ->assertNotFound();

        $this->assertDatabaseHas('composantes_evaluation', ['id' => $composanteB->id]);
        $this->assertSame('Examen', $composanteB->fresh()->libelle);
        $this->assertTrue($auteur->id > 0);
    }

    public function test_enseignement_matiere_autre_faculte_refuse(): void
    {
        $type = TypeEnseignement::create(['code' => 'C'.random_int(100, 999), 'nom' => 'Cours', 'actif' => true]);

        $this->adminFaculteA();

        $this->post(route('admin.enseignements.store'), [
            'matiere_id' => $this->ecueB->id, 'type_id' => $type->id,
            'annee_universitaire_id' => $this->annee->id,
        ])->assertNotFound();
    }

    public function test_seuils_autre_faculte_refuses(): void
    {
        $seuilB = SeuilService::create([
            'faculte_id' => $this->faculteB->id, 'service_min' => 100,
            'service_cible' => 192, 'service_max' => 300,
        ]);

        $this->adminFaculteA();

        // Cible explicite autre faculté : refusée.
        $this->post(route('admin.enseignements.seuils.store'), [
            'faculte_id' => $this->faculteB->id, 'service_min' => 10,
            'service_cible' => 20, 'service_max' => 30,
        ])->assertNotFound();

        $this->delete(route('admin.enseignements.seuils.destroy', $seuilB))->assertNotFound();

        $this->assertDatabaseHas('seuils_service', ['id' => $seuilB->id]);
    }

    // ── CALENDRIER ──────────────────────────────────────────────

    public function test_calendrier_cible_et_suppression_autre_faculte_refusees(): void
    {
        $evenementB = EvenementCalendrier::create([
            'annee_universitaire_id' => $this->annee->id, 'titre' => 'Evt B',
            'type' => 'evenement', 'date_debut' => '2026-10-05', 'audience' => 'tous',
            'faculte_id' => $this->faculteB->id, 'statut' => 'publie', 'created_by' => $this->auteur()->id,
        ]);

        $this->adminFaculteA();

        $this->post(route('admin.calendrier.store'), [
            'annee_universitaire_id' => $this->annee->id, 'titre' => 'Evt pirate',
            'type' => 'evenement', 'date_debut' => '2026-10-10', 'audience' => 'tous',
            'faculte_id' => $this->faculteB->id,
        ])->assertNotFound();

        $this->delete(route('admin.calendrier.destroy', $evenementB))->assertForbidden();

        $this->assertDatabaseHas('calendrier_evenements', ['id' => $evenementB->id]);
        $this->assertSame(1, EvenementCalendrier::count());
    }

    // ── ANNONCES (côté étudiant) ────────────────────────────────

    public function test_annonce_personnel_ou_autre_faculte_non_lisible_par_etudiant(): void
    {
        $etudiantA = $this->etudiantDe($this->faculteA);
        $auteur = $this->auteur();

        $perso = Annonce::create([
            'titre' => 'Note interne RH', 'contenu' => 'Réservé au personnel.',
            'type' => 'information', 'statut' => 'published', 'audience' => 'personnel',
            'publiee_a' => now()->subHour(), 'created_by' => $auteur->id,
        ]);
        $autreFaculte = Annonce::create([
            'titre' => 'Annonce B', 'contenu' => 'Faculté B seulement.',
            'type' => 'information', 'statut' => 'published', 'audience' => 'tous',
            'faculte_id' => $this->faculteB->id, 'publiee_a' => now()->subHour(), 'created_by' => $auteur->id,
        ]);
        $legitime = Annonce::create([
            'titre' => 'Rentrée', 'contenu' => 'Bienvenue.',
            'type' => 'information', 'statut' => 'published', 'audience' => 'tous',
            'publiee_a' => now()->subHour(), 'created_by' => $auteur->id,
        ]);

        $this->etudiantConnecte($etudiantA);

        $this->get(route('etudiant.annonces.show', $perso))->assertNotFound();
        $this->get(route('etudiant.annonces.show', $autreFaculte))->assertNotFound();
        // Contrôle positif : une annonce publique reste lisible.
        $this->get(route('etudiant.annonces.show', $legitime))->assertOk();
    }

    // ── SALLES ──────────────────────────────────────────────────

    public function test_salle_autre_faculte_non_modifiable_ni_supprimable(): void
    {
        $salleB = Room::create(['code' => 'B99', 'nom' => 'Salle B', 'capacite' => 40, 'actif' => true]);
        $salleB->forceFill(['faculte_id' => $this->faculteB->id])->save();

        $this->adminFaculteA();

        $this->patch(route('admin.salles.update', $salleB), ['nom' => 'Piratée'])->assertNotFound();
        $this->delete(route('admin.salles.destroy', $salleB))->assertNotFound();
        $this->post(route('admin.salles.store'), [
            'code' => 'PIRATE', 'faculte_id' => $this->faculteB->id,
        ])->assertNotFound();

        $this->assertSame('Salle B', $salleB->fresh()->nom);
        $this->assertNull(Room::where('code', 'PIRATE')->first());
    }

    // ── RATTACHEMENT / RÉINSCRIPTION / PROGRESSION ──────────────

    public function test_rattachement_vers_programme_autre_faculte_refuse(): void
    {
        $etudiantA = $this->etudiantDe($this->faculteA);
        $this->adminFaculteA();

        $this->patch(route('admin.etudiants.rattachement', $etudiantA), [
            'programme_id' => $this->programmeB->id,
        ])->assertNotFound();

        $this->assertNull($etudiantA->fresh()->programme_id);
    }

    public function test_reinscription_vers_programme_autre_faculte_refusee(): void
    {
        $etudiantA = $this->etudiantDe($this->faculteA);
        // Période ouverte : la garde « périmètre faculté de la CIBLE »
        // doit refuser AVANT toute règle métier (décision, doublon…).
        RegistrationPeriod::create([
            'annee_universitaire_id' => $this->annee->id, 'type_periode' => 'normale',
            'statut' => 'active',
            'opened_at' => now()->subDay(), 'closed_at' => now()->addMonth(),
            'created_by' => $this->auteur()->id,
        ]);

        $this->adminFaculteA();

        $this->post(route('admin.etudiants.reinscriptions.store', $etudiantA), [
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programmeB->id, 'niveau_id' => $this->niveauB->id,
            'confirmer' => '1',
        ])->assertNotFound();

        $this->assertSame(0, InscriptionAcademique::count());
    }

    public function test_progression_vers_programme_autre_faculte_refusee(): void
    {
        $etudiantA = $this->etudiantDe($this->faculteA);
        $inscriptionA = InscriptionAcademique::create([
            'etudiant_id' => $etudiantA->id, 'niveau' => 'L1', 'filiere' => 'Info',
            'annee_universitaire_id' => $this->annee->id, 'statut' => 'valide',
        ]);
        $this->adminFaculteA();

        $this->post(route('admin.inscriptions.progresser', $inscriptionA), [
            'programme_id' => $this->programmeB->id, 'confirmer' => '1',
        ])->assertNotFound();

        $this->assertSame(1, InscriptionAcademique::count());
    }

    // ── AFFECTATION AUTOMATIQUE ─────────────────────────────────

    public function test_modification_item_simulation_autre_faculte_refusee(): void
    {
        $etudiantB = $this->etudiantDe($this->faculteB);
        InscriptionAcademique::create([
            'etudiant_id' => $etudiantB->id, 'niveau' => 'L1', 'filiere' => 'Info',
            'annee_universitaire_id' => $this->annee->id, 'statut' => 'en_cours',
        ]);
        $simulationB = AssignmentSimulation::create([
            'reference' => 'SIM-B-'.random_int(1000, 9999), 'statut' => 'simulee',
            'annee_universitaire_id' => $this->annee->id, 'seed' => 42,
            'parametres' => ['groupe_ids' => [$this->groupeB->id]],
            'hash_entrees' => 'x', 'auteur_id' => $this->auteur()->id,
        ]);
        $item = AssignmentItem::create([
            'simulation_id' => $simulationB->id, 'etudiant_id' => $etudiantB->id,
            'groupe_propose_id' => $this->groupeB->id, 'statut' => 'proposition',
        ]);

        $this->adminFaculteA();

        // Déplacer un item de la simulation de B vers un groupe de A :
        // la cible est légitime pour A, mais l'ITEM ne relève pas de son périmètre.
        $this->patch(route('admin.affectations.automatique.modifier', $item), [
            'groupe_id' => $this->groupeA->id,
        ])->assertNotFound();

        $this->assertSame('proposition', $item->fresh()->statut);
    }

    // ── ARCHIVE SENSIBLE (liaison étudiant) ─────────────────────

    public function test_telechargement_sensible_dossier_autre_etudiant_refuse(): void
    {
        Storage::fake('private');

        $proprietaire = $this->etudiantDe($this->faculteA);
        $autreEtudiant = $this->etudiantDe($this->faculteA);

        $dossierAutre = StudentDossier::create([
            'etudiant_id' => $autreEtudiant->id, 'reference' => 'DOS-'.uniqid(), 'status' => 'open',
        ]);
        Storage::disk('private')->put('dossiers/secret-x.pdf', 'contenu sensible');
        $documentAutre = StudentDocument::create([
            'student_dossier_id' => $dossierAutre->id, 'category' => 'identite',
            'document_type' => 'cin', 'title' => 'CIN autre',
            'original_filename' => 'secret.pdf', 'storage_path' => 'dossiers/secret-x.pdf',
            'mime_type' => 'application/pdf', 'extension' => 'pdf', 'size' => 15, 'sha256' => str_repeat('a', 64),
            'statut' => 'valide', 'uploaded_by' => $this->auteur()->id,
        ]);

        $record = StudentSensitiveRecord::create([
            'etudiant_id' => $proprietaire->id, 'type' => 'situation_particuliere',
            'titre' => 'Dossier test', 'statut' => 'ouvert', 'niveau' => 'confidentiel',
            'document_source' => 'dossier', 'document_id' => $documentAutre->id,
            'created_by' => $this->auteur()->id,
        ]);

        // admin_scolarite = rôle GLOBAL : le garde-fou ne peut pas venir du
        // périmètre faculté, seule la LIAISON étudiant du document protège.
        $this->scolarite('admin_scolarite');

        $this->get(route('admin.etudiants.archive.sensible.download', [$proprietaire, $record]))
            ->assertNotFound();
    }
}
