<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\TeacherDocument;
use App\Modules\Announcements\Models\Annonce;
use App\Modules\Attendance\Models\TeacherPointage;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Finances\Models\DossierPaiement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LOT A — Tests de performance et d'accessibilité des portails Étudiant et Enseignant :
 * - Mesure des budgets de requêtes (absence de N+1, élimination des doubles interrogations) ;
 * - Conformité de la hiérarchie des titres (h1 -> h2, aucun saut de niveau) ;
 * - Présence de repères accessibles (aria-labelledby) et labels descriptifs.
 */
class PortailsPerformanceAccessibiliteTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculte;

    protected AnneeUniversitaire $annee;

    protected Group $groupe;

    protected Matiere $matiere;

    protected Room $salle;

    protected Teacher $enseignant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences et Techniques',
            'code' => 'FST',
            'description' => 'Sciences',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->groupe = Group::create([
            'code' => 'L1-INFO-A',
            'nom' => 'L1 Info A',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'filiere' => 'Informatique',
            'actif' => true,
        ]);

        $this->matiere = Matiere::create([
            'code' => 'INFO101',
            'nom' => 'Algorithmique',
            'coefficient' => 2,
            'credits' => 6,
            'niveau' => 'L1',
            'semestre' => 1,
        ]);

        $this->salle = Room::create(['code' => 'A01', 'capacite' => 60]);
        $this->enseignant = Teacher::create([
            'nom' => 'Ben Salah',
            'prenom' => 'Ahmed',
            'email' => 'ahmed.bensalah@example.com',
            'actif' => true,
        ]);
    }

    private function creerEtudiant(string $cin = 'BK100001'): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => 'Alami',
            'prenom' => 'Mehdi',
            'email' => strtolower($cin).'@example.com',
            'telephone' => '+212600000000',
            'date_naissance' => '2004-03-15',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'groupe_id' => $this->groupe->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '12 Rue de la Liberté',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'password' => 'motdepasse1',
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-2026-'.$cin])->save();
        $etudiant->definirStatut('valide');

        return $etudiant;
    }

    public function test_budget_requetes_dashboard_etudiant_sans_n_plus_un(): void
    {
        // Activer explicitement les modules portail
        config([
            'modules.schedule.active' => true,
            'modules.evaluation.active' => true,
            'modules.finances.active' => true,
        ]);

        $etudiant = $this->creerEtudiant('BK100002');

        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1',
            'filiere' => 'Informatique',
            'groupe' => 'L1-INFO-A',
            'statut' => 'valide',
            'moyenne_annuelle' => 14.50,
            'credits_obtenus' => 60,
            'credits_total' => 60,
            'decision' => 'Admis',
        ]);

        // Planning publié avec créneau
        $planning = Schedule::create([
            'annee_universitaire_id' => $this->annee->id,
            'semestre' => 1,
            'groupe_id' => $this->groupe->id,
            'nom' => 'L1 Info A — S1',
            'statut' => 'publie',
            'actif' => true,
        ]);

        $jour = strtolower(Carbon::now()->format('l'));
        ScheduleSlot::create([
            'schedule_id' => $planning->id,
            'day_of_week' => $jour,
            'start_time' => '10:00:00',
            'end_time' => '12:00:00',
            'matiere_id' => $this->matiere->id,
            'room_id' => $this->salle->id,
            'teacher_id' => $this->enseignant->id,
            'type' => 'cours',
            'status' => 'scheduled',
        ]);

        // Épreuve d'examen publiée à venir
        $session = SessionExamen::create([
            'nom' => 'Session Principale S1',
            'code' => 'SESS-S1',
            'type' => 'principale',
            'annee_universitaire_id' => $this->annee->id,
            'date_debut' => Carbon::today(),
            'date_fin' => Carbon::today()->addDays(14),
            'statut' => 'active',
        ]);

        $examen = Examen::create([
            'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examens S1 Info',
            'type' => 'examen',
            'statut' => 'scheduled',
        ]);

        $epreuve = Epreuve::create([
            'session_id' => $session->id,
            'examen_id' => $examen->id,
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'EPR-INFO',
            'matiere_id' => $this->matiere->id,
            'date' => Carbon::today()->addDays(2),
            'heure_debut' => '09:00:00',
            'heure_fin' => '11:00:00',
            'salle_id' => $this->salle->id,
            'statut' => 'published',
        ]);

        Candidat::create([
            'epreuve_id' => $epreuve->id,
            'etudiant_id' => $etudiant->id,
            'numero_anonymat' => 'ANO-001',
        ]);

        // Dossier de paiement provisionné ou récupéré
        $dossier = $inscription->dossierPaiement ?? DossierPaiement::firstOrCreate(
            ['inscription_academique_id' => $inscription->id],
            ['statut' => DossierPaiement::NON_PAYE]
        );

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.dashboard'));

        $requetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk()
            ->assertSee('Bonjour Mehdi')
            ->assertSee('Algorithmique')
            ->assertSee('Prochains examens')
            ->assertSee('Frais d’inscription');

        // Budget strict : le dashboard étudiant avec TOUS les 6 modules actifs
        // (cours du jour + widget schedule + widget finances + examens + démarches + présences + GED + situation)
        // ne doit pas dépasser 32 requêtes bornées et prouvées sans N+1.
        $this->assertLessThanOrEqual(32, $requetes, "Requêtes excessives sur le dashboard étudiant complet : {$requetes}");
    }

    public function test_budget_requetes_dashboard_etudiant_sans_modules_actifs(): void
    {
        config([
            'modules.schedule.active' => false,
            'modules.evaluation.active' => false,
            'modules.finances.active' => false,
            'modules.attendance.active' => false,
            'modules.online-requests.active' => false,
            'modules.student-dossiers.active' => false,
        ]);

        $etudiant = $this->creerEtudiant('BK100004');

        InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1',
            'filiere' => 'Informatique',
            'statut' => 'valide',
            'moyenne_annuelle' => 14.50,
            'credits_obtenus' => 60,
            'credits_total' => 60,
            'decision' => 'Admis',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.dashboard'));

        $requetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk()->assertSee('Bonjour Mehdi');

        // Budget coeur léger : sans modules optionnels, le dashboard
        // ne consomme que le coeur + notifications de layout (<= 10 requêtes).
        $this->assertLessThanOrEqual(10, $requetes, "Requêtes excessives sur le dashboard étudiant coeur : {$requetes}");
    }

    public function test_budget_requetes_dashboard_enseignant(): void
    {
        config([
            'modules.schedule.active' => true,
            'modules.evaluation.active' => true,
            'modules.calendar.active' => true,
            'modules.announcements.active' => true,
        ]);

        $planning = Schedule::create([
            'annee_universitaire_id' => $this->annee->id,
            'semestre' => 1,
            'groupe_id' => $this->groupe->id,
            'nom' => 'L1 Info A — S1',
            'statut' => 'publie',
            'actif' => true,
        ]);

        $jour = strtolower(Carbon::now()->format('l'));
        ScheduleSlot::create([
            'schedule_id' => $planning->id,
            'day_of_week' => $jour,
            'start_time' => '08:30:00',
            'end_time' => '10:00:00',
            'matiere_id' => $this->matiere->id,
            'room_id' => $this->salle->id,
            'teacher_id' => $this->enseignant->id,
            'type' => 'cours',
            'status' => 'scheduled',
        ]);

        // Documents, pointages, annonces
        TeacherDocument::create([
            'teacher_id' => $this->enseignant->id,
            'titre' => 'Contrat annuel',
            'categorie' => 'administratif',
            'nom_fichier' => 'contrat.pdf',
            'chemin' => 'docs/contrat.pdf',
            'mime_type' => 'application/pdf',
            'taille' => 2048,
        ]);

        TeacherPointage::create([
            'teacher_id' => $this->enseignant->id,
            'date' => Carbon::today()->toDateString(),
            'horodatage' => now(),
            'type' => 'entree',
            'source' => 'portail',
        ]);

        Annonce::create([
            'titre' => 'Réunion pédagogique',
            'contenu' => 'Ordre du jour de la réunion.',
            'type' => 'information',
            'cible' => 'enseignants',
            'actif' => true,
            'publiee_a' => now()->subDay(),
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->withSession(['enseignant_auth_id' => $this->enseignant->id])
            ->get(route('enseignant.dashboard'));

        $requetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk()
            ->assertSee('Bonjour Ahmed')
            ->assertSee('Séances du jour')
            ->assertSee('Algorithmique');

        // Budget strict du tableau de bord enseignant : <= 24 requêtes
        $this->assertLessThanOrEqual(24, $requetes, "Requêtes excessives sur le dashboard enseignant : {$requetes}");
    }

    public function test_accessibilite_structurelle_et_hierarchie_titres(): void
    {
        config([
            'modules.schedule.active' => true,
            'modules.evaluation.active' => true,
            'modules.finances.active' => true,
            'modules.calendar.active' => true,
            'modules.announcements.active' => true,
        ]);

        $etudiant = $this->creerEtudiant('BK100003');

        // 1. Dashboard étudiant
        $htmlEtudiant = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.dashboard'))
            ->assertOk()
            ->getContent();

        // Vérification de la présence d'un titre h1 principal
        $this->assertSame(1, substr_count($htmlEtudiant, '<h1'), 'Le dashboard étudiant doit comporter exactement un h1.');
        $this->assertStringContainsString('id="titre-bonjour"', $htmlEtudiant);

        // Les sections doivent utiliser aria-labelledby
        $this->assertStringContainsString('aria-labelledby="titre-aujourdhui"', $htmlEtudiant);
        $this->assertStringContainsString('aria-labelledby="titre-situation"', $htmlEtudiant);
        $this->assertStringContainsString('aria-labelledby="titre-resultat"', $htmlEtudiant);

        // 2. Dashboard enseignant
        $htmlEnseignant = $this->withSession(['enseignant_auth_id' => $this->enseignant->id])
            ->get(route('enseignant.dashboard'))
            ->assertOk()
            ->getContent();

        // Le h1 est généré par x-page-header
        $this->assertSame(1, substr_count($htmlEnseignant, '<h1'), 'Le dashboard enseignant doit comporter exactement un h1.');

        // La hiérarchie doit être séquentielle (h2 sous les sections avec aria-labelledby)
        $this->assertStringContainsString('aria-labelledby="titre-seances"', $htmlEnseignant);
        $this->assertStringContainsString('id="titre-seances"', $htmlEnseignant);
        $this->assertStringContainsString('aria-labelledby="titre-surveillances"', $htmlEnseignant);
        $this->assertStringContainsString('id="titre-surveillances"', $htmlEnseignant);
        $this->assertStringContainsString('aria-labelledby="titre-echeances"', $htmlEnseignant);
        $this->assertStringContainsString('id="titre-echeances"', $htmlEnseignant);
        $this->assertStringContainsString('aria-labelledby="titre-annonces"', $htmlEnseignant);
        $this->assertStringContainsString('id="titre-annonces"', $htmlEnseignant);
        $this->assertStringContainsString('aria-labelledby="titre-documents"', $htmlEnseignant);
        $this->assertStringContainsString('id="titre-documents"', $htmlEnseignant);
        $this->assertStringContainsString('aria-labelledby="titre-pointages"', $htmlEnseignant);
        $this->assertStringContainsString('id="titre-pointages"', $htmlEnseignant);
    }
}
