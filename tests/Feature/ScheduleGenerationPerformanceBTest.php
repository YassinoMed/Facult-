<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleAvailability;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\Schedule\Models\TimeSlot;
use App\Modules\Schedule\Services\ScheduleGeneratorService;
use App\Modules\Schedule\Services\SeanceDemande;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PHASE B (Smart Timetables) — preuves de performance du scoring qualitatif.
 *
 * Le contexte qualitatif (registre de contraintes, pondérations déclarées,
 * plages `souhaitee` des enseignants/salles/groupes) est préchargé une seule
 * fois par génération : le coût ne dépend pas du nombre de candidats évalués.
 */
class ScheduleGenerationPerformanceBTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculte;

    protected AnneeUniversitaire $annee;

    protected Group $groupe;

    protected Matiere $matiereAlgo;

    protected Matiere $matiereWeb;

    protected Teacher $enseignant;

    protected Room $salleA;

    protected Room $salleB;

    protected Schedule $planning;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.schedule.active' => true]);

        $this->faculte = Faculte::create([
            'nom' => 'Faculté Performance B',
            'code' => 'FST_PERF_B',
            'description' => 'Sciences exactes',
            'active' => true,
        ]);

        $admin = User::creerCompteBackOffice([
            'name' => 'Scolarité Performance',
            'email' => 'scolarite.perf.b@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->assertNotNull($admin);

        $this->groupe = Group::create([
            'code' => 'L2-INFO-PERF',
            'nom' => 'L2 Informatique Performance',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L2',
            'filiere' => 'Informatique',
            'capacite' => 30,
            'actif' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2027-2028-PERF',
            'date_debut' => '2027-09-01',
            'date_fin' => '2028-06-30',
            'active' => true,
        ]);

        $this->matiereAlgo = Matiere::create([
            'code' => 'ALGO_PERF',
            'nom' => 'Algorithmique',
            'niveau' => 'L2',
            'semestre' => 1,
        ]);

        $this->matiereWeb = Matiere::create([
            'code' => 'WEB_PERF',
            'nom' => 'Développement web',
            'niveau' => 'L2',
            'semestre' => 1,
        ]);

        $this->enseignant = Teacher::create([
            'matricule' => 'ENS-PERF-001',
            'nom' => 'Ben Ali',
            'prenom' => 'Karim',
            'email' => 'karim.benali@universite.test',
            'qualifications' => ['ALGO_PERF', 'WEB_PERF'],
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->salleA = Room::create([
            'code' => 'PA101',
            'nom' => 'Salle PA101',
            'capacite' => 60,
            'faculte_id' => $this->faculte->id,
            'type_salle' => 'td',
            'heure_ouverture' => '08:00:00',
            'heure_fermeture' => '19:00:00',
            'actif' => true,
        ]);

        $this->salleB = Room::create([
            'code' => 'PB102',
            'nom' => 'Salle PB102',
            'capacite' => 60,
            'faculte_id' => $this->faculte->id,
            'type_salle' => 'td',
            'heure_ouverture' => '08:00:00',
            'heure_fermeture' => '19:00:00',
            'actif' => true,
        ]);

        $this->planning = Schedule::create([
            'annee_universitaire_id' => $this->annee->id,
            'semestre' => 1,
            'groupe_id' => $this->groupe->id,
            'nom' => 'L2 Info — grille de performance',
            'statut' => 'brouillon',
            'actif' => true,
        ]);

        // Grille réaliste : 3 jours × 4 créneaux = 12 séances plaçables.
        $tranches = [
            ['08:30:00', '10:00:00'],
            ['10:15:00', '11:45:00'],
            ['14:00:00', '15:30:00'],
            ['15:45:00', '17:15:00'],
        ];

        foreach (['monday', 'tuesday', 'wednesday'] as $jour) {
            foreach ($tranches as $index => [$debut, $fin]) {
                TimeSlot::create([
                    'faculte_id' => $this->faculte->id,
                    'code' => 'PERF_'.strtoupper(substr($jour, 0, 3)).'_'.$index,
                    'nom' => "Créneau {$jour} {$debut}",
                    'day_of_week' => $jour,
                    'start_time' => $debut,
                    'end_time' => $fin,
                    'type' => 'course',
                    'is_mandatory_break' => false,
                    'actif' => true,
                ]);
            }
        }
    }

    protected function moteur(): ScheduleGeneratorService
    {
        return app(ScheduleGeneratorService::class);
    }

    protected function demande(string $id, string $matiere = 'algo'): SeanceDemande
    {
        return new SeanceDemande(
            id: $id,
            matiereId: $matiere === 'web' ? $this->matiereWeb->id : $this->matiereAlgo->id,
            type: 'course',
            titre: "Séance {$id}",
            dureeMinutes: 90,
            teacherId: $this->enseignant->id,
        );
    }

    /**
     * @param  array<string, mixed>  $surcharges
     */
    protected function souhait(array $surcharges): ScheduleAvailability
    {
        return ScheduleAvailability::create($surcharges + [
            'annee_universitaire_id' => $this->annee->id,
            'type' => 'souhaitee',
        ]);
    }

    public function test_les_plages_souhaitees_ne_sont_pas_relues_par_seance_evaluee(): void
    {
        // Deux préférences déclarées : enseignant (lundi matin) et salle B.
        $this->souhait([
            'teacher_id' => $this->enseignant->id,
            'day_of_week' => 'monday',
            'start_time' => '08:30:00',
            'end_time' => '10:00:00',
        ]);
        $this->souhait([
            'room_id' => $this->salleB->id,
            'day_of_week' => 'tuesday',
            'start_time' => '10:15:00',
            'end_time' => '11:45:00',
        ]);

        $compter = function (int $nombre): int {
            $demandes = [];
            for ($i = 1; $i <= $nombre; $i++) {
                $demandes[] = $this->demande('LECT_'.$i, $i % 2 === 0 ? 'web' : 'algo');
            }

            DB::flushQueryLog();
            DB::enableQueryLog();

            $generation = $this->moteur()->simuler($this->planning, ['seances_demandes' => $demandes]);

            $lectures = collect(DB::getQueryLog())
                ->filter(fn (array $entree) => str_contains($entree['query'], 'schedule_availabilities')
                    && in_array('souhaitee', array_map('strval', $entree['bindings']), true))
                ->count();

            DB::disableQueryLog();
            DB::flushQueryLog();

            $this->assertTrue($generation->estSucces());
            $this->assertSame($nombre, $generation->nombre_seances_placees);

            return $lectures;
        };

        // Même planning, même enseignant : seule la charge évaluée change.
        $uneSeance = $compter(1);
        $dixSeances = $compter(10);

        // Gates : garde de fraîcheur du cache + préchargement unique, et la
        // vérification indépendante lit les préférences par enseignant, pas par
        // séance. Le repérage est donc réel mais ne dépend pas du nombre de
        // séances évaluées — un N+1 per-candidat ferait exploser le compteur.
        $this->assertGreaterThanOrEqual(1, $uneSeance, 'Aucune lecture de plages souhaitées détectée : le compteur serait aveugle.');
        $this->assertLessThanOrEqual(4, $dixSeances, "Plages souhaitées lues {$dixSeances} fois.");
        $this->assertSame(
            $uneSeance,
            $dixSeances,
            "Plages souhaitées : {$uneSeance} lecture(s) pour 1 séance, {$dixSeances} pour 10."
        );
    }

    public function test_generation_de_huit_seances_reste_sous_le_budget_de_temps(): void
    {
        $demandes = [];
        for ($i = 1; $i <= 8; $i++) {
            $demandes[] = $this->demande('BUDGET_'.$i, $i % 2 === 0 ? 'web' : 'algo');
        }

        $debut = hrtime(true);
        $generation = $this->moteur()->simuler($this->planning, [
            'seances_demandes' => $demandes,
            'timeout_secondes' => 15,
        ]);
        $dureeMs = (hrtime(true) - $debut) / 1_000_000;

        $this->assertTrue($generation->estSucces());
        $this->assertSame(8, $generation->nombre_seances_placees);
        $this->assertSame([], $generation->erreurs);
        // Marge très large : le point mesuré est l'absence d'explosion
        // combinatoire (scoring préchargé), pas une garantie de latence machine.
        $this->assertLessThan(
            10000,
            $dureeMs,
            sprintf('Génération de 8 séances évaluée en %.1f ms.', $dureeMs)
        );
    }
}
