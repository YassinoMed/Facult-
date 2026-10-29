<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Semestre;
use App\Models\User;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tableaux de bord étudiant + scolarité (§13/§15) :
 * - dashboard étudiant LÉGER : moyenne/crédits lus depuis les colonnes
 *   persistées, relation `notes` jamais chargée ;
 * - dashboard admin AGRÉGÉ : stats exactes + isolation par faculté.
 */
class TableauDeBordTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculte;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences et Techniques',
            'code' => 'FST',
            'description' => 'Sciences et technologies',
            'active' => true,
        ]);
    }

    private function creerEtudiant(Faculte $faculte, string $cin, string $statut = 'en_attente'): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => 'Test',
            'prenom' => 'Etudiant',
            'email' => strtolower($cin).'@example.com',
            'telephone' => '+212600000000',
            'date_naissance' => '2004-03-15',
            'sexe' => 'M',
            'faculte_id' => $faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '12 Rue de la Liberté',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'mention_bac' => 'Bien',
            'password' => 'motdepasse1',
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-2026-'.$cin])->save();
        $etudiant->definirStatut($statut);

        return $etudiant->fresh();
    }

    private function connecterEtudiant(Etudiant $etudiant): void
    {
        session([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_auth_prenom' => $etudiant->prenom,
            'etudiant_auth_nom' => $etudiant->nom,
        ]);
    }

    public function test_invite_redirige_vers_connexion(): void
    {
        $this->get(route('etudiant.dashboard'))->assertRedirect(route('etudiant.connexion.show'));
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.connexion.show'));
    }

    public function test_dashboard_etudiant_leger_sans_notes(): void
    {
        $etudiant = $this->creerEtudiant($this->faculte, 'BK900001', 'valide');
        $this->connecterEtudiant($etudiant);

        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);
        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $annee->id,
            'niveau' => 'L1',
            'filiere' => 'Informatique',
            'statut' => 'valide',
            'moyenne_annuelle' => 14.25,
            'credits_obtenus' => 42,
            'credits_total' => 60,
            'decision' => 'Admis(e)',
        ]);
        Semestre::create([
            'inscription_academique_id' => $inscription->id,
            'numero' => 1,
            'moyenne' => 14.25,
            'credits_obtenus' => 42,
            'credits_total' => 60,
            'decision' => 'Validé',
        ]);

        // D-01 : le dashboard n'affiche un résultat que si la décision du
        // jury est PUBLIÉE. La colonne `decision` de l'inscription est un
        // calcul automatique, jamais une publication.
        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $annee->id,
            'statut' => 'published',
            'published_at' => now(),
        ]);
        DecisionDeliberation::create([
            'deliberation_id' => $deliberation->id,
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'moyenne' => 14.25,
            'credits_obtenus' => 42,
            'credits_total' => 60,
            'decision' => 'admis',
            'publie' => true,
        ]);

        $reponse = $this->get(route('etudiant.dashboard'));

        $reponse->assertOk()
            ->assertSee('Bonjour Etudiant')
            ->assertSee('14.25')
            ->assertSee('42')
            ->assertSee('2026-2027');

        // Le dashboard ne charge JAMAIS les notes : dossier complet réservé aux pages dédiées.
        $derniere = $reponse->original->getData()['derniereInscription'];
        $this->assertFalse($derniere->relationLoaded('notes'));
        foreach ($derniere->semestres as $semestre) {
            $this->assertFalse($semestre->relationLoaded('notes'));
        }
    }

    public function test_dashboard_etudiant_sans_inscription_academique(): void
    {
        $etudiant = $this->creerEtudiant($this->faculte, 'BK900002');
        $this->connecterEtudiant($etudiant);

        $this->get(route('etudiant.dashboard'))
            ->assertOk()
            ->assertSee('Aucune année académique');
    }

    public function test_etudiant_ne_voit_que_son_dashboard(): void
    {
        $etudiantA = $this->creerEtudiant($this->faculte, 'BK900003');
        $etudiantB = $this->creerEtudiant($this->faculte, 'BK900004');
        $this->connecterEtudiant($etudiantA);

        $reponse = $this->get(route('etudiant.dashboard'));

        $reponse->assertOk()->assertSee('Bonjour Etudiant');
        $this->assertSame($etudiantA->id, $reponse->original->getData()['etudiant']->id);
        $this->assertNotSame($etudiantB->id, $reponse->original->getData()['etudiant']->id);
    }

    public function test_dashboard_admin_stats_agregees(): void
    {
        $this->creerEtudiant($this->faculte, 'BK910001', 'en_attente');
        $this->creerEtudiant($this->faculte, 'BK910002', 'valide');
        $this->creerEtudiant($this->faculte, 'BK910003', 'valide');
        $this->creerEtudiant($this->faculte, 'BK910004', 'rejete');

        $admin = User::creerCompteBackOffice([
            'name' => 'Scolarité Test',
            'email' => 'scolarite.test@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $reponse = $this->actingAs($admin)->get(route('admin.dashboard'));

        $reponse->assertOk()
            ->assertSee('Tableau de bord')
            ->assertSee('Dernières inscriptions');

        $stats = $reponse->original->getData()['stats'];
        $this->assertSame(4, $stats['total']);
        $this->assertSame(1, $stats['en_attente']);
        $this->assertSame(2, $stats['valide']);
        $this->assertSame(1, $stats['rejete']);
    }

    public function test_dashboard_admin_isolation_faculte(): void
    {
        $autre = Faculte::create([
            'nom' => 'Faculté de Médecine',
            'code' => 'FMP',
            'description' => 'Médecine',
            'active' => true,
        ]);
        $this->creerEtudiant($this->faculte, 'BK920001', 'valide');
        $this->creerEtudiant($autre, 'BK920002', 'valide');

        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Admin FST',
            'email' => 'admin.fst@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculte->id,
        ], 'admin_faculte');

        $reponse = $this->actingAs($adminFaculte)->get(route('admin.dashboard'));

        $reponse->assertOk()->assertSee('Faculté des Sciences et Techniques');

        $stats = $reponse->original->getData()['stats'];
        $this->assertSame(1, $stats['total']);
        $reponse->assertDontSee('BK920002');
    }

    public function test_budget_requetes_dashboard_etudiant_sans_n_plus_un(): void
    {
        config(['modules.schedule.active' => true]);

        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $groupe = Group::create([
            'code' => 'G-INFO', 'nom' => 'Groupe Info', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'actif' => true,
        ]);
        $etudiant = $this->creerEtudiant($this->faculte, 'BK990001', 'valide');
        $etudiant->update(['groupe_id' => $groupe->id]);
        $this->connecterEtudiant($etudiant);

        InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $annee->id,
            'niveau' => 'L1',
            'filiere' => 'Informatique',
            'statut' => 'valide',
            'moyenne_annuelle' => 15.0,
            'credits_obtenus' => 60,
            'credits_total' => 60,
        ]);

        $matiere = Matiere::create(['code' => 'ALGO-1', 'nom' => 'Algorithmique', 'niveau' => 'L1', 'semestre' => 1]);
        $planning = Schedule::create([
            'nom' => 'Planning L1',
            'groupe_id' => $groupe->id,
            'annee_universitaire_id' => $annee->id,
            'semestre' => 1,
            'statut' => 'publie',
            'actif' => true,
        ]);

        $jour = strtolower(now()->format('l'));
        foreach (['08:30:00', '10:30:00', '14:00:00'] as $debut) {
            ScheduleSlot::create([
                'schedule_id' => $planning->id,
                'matiere_id' => $matiere->id,
                'day_of_week' => $jour,
                'start_time' => $debut,
                'end_time' => date('H:i:s', strtotime($debut) + 5400),
                'type' => 'cours',
                'status' => 'scheduled',
            ]);
        }

        DB::enableQueryLog();

        $reponse = $this->get(route('etudiant.dashboard'));
        $reponse->assertOk()->assertSee('Bonjour Etudiant');

        $requetes = DB::getQueryLog();
        DB::disableQueryLog();

        $requetesSchedule = array_filter($requetes, fn ($q) => str_contains($q['query'], 'schedules'));
        $this->assertLessThanOrEqual(1, count($requetesSchedule), 'Le planning ne doit pas être interrogé deux fois.');
        $this->assertLessThanOrEqual(22, count($requetes), 'Budget de requêtes du dashboard étudiant dépassé (mesure : '.count($requetes).', seuil : 22).');
    }
}
