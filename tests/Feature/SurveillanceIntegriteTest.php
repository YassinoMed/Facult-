<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Evaluation\Services\EpreuveService;
use App\Modules\Evaluation\Services\SurveillanceService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LOOP DURCISSEMENT — P1 intégrité SQL (clé d'unicité active,
 * compatible MySQL, historique annulé préservé) + P2 perfs (simulation
 * et cockpit bornés en requêtes, résultats strictement identiques).
 */
class SurveillanceIntegriteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Matiere $ecue;

    private Group $groupe;

    private Room $salle;

    private int $compteur = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.durcir@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LGL', 'nom' => 'Licence GL',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niveau = Niveau::create(['programme_id' => $programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $semestre = SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $programme->id,
            'code' => 'UE01', 'nom' => 'Algo', 'credits' => 6, 'coefficient' => 2,
        ]);
        $this->ecue = Matiere::create([
            'code' => 'A101', 'nom' => 'Algorithmique', 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 6, 'coefficient' => 2,
        ]);
        $this->groupe = Group::create([
            'code' => 'G1', 'nom' => 'Groupe 1', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'actif' => true,
        ]);
        $this->salle = Room::create([
            'code' => 'B12', 'nom' => 'B12', 'capacite' => 40, 'actif' => true, 'faculte_id' => $this->faculte->id,
        ]);
    }

    private function creerEnseignant(string $suffixe): Teacher
    {
        return Teacher::create([
            'matricule' => 'ENS-D'.$suffixe, 'nom' => 'Nom'.$suffixe, 'prenom' => 'Prenom'.$suffixe,
            'email' => "durcir{$suffixe}@universite.test", 'actif' => true,
        ]);
    }

    private function creerEpreuve(): Epreuve
    {
        $this->compteur++;
        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id, 'code' => 'S-DUR-'.$this->compteur,
            'nom' => 'Principale', 'type' => 'principale',
            'date_debut' => '2027-01-05', 'date_fin' => '2027-06-30', 'statut' => 'open', 'created_by' => $this->admin->id,
        ]);
        $examen = Examen::create([
            'session_id' => $session->id, 'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examen S1', 'type' => 'examen', 'statut' => 'scheduled', 'responsable_id' => $this->admin->id,
        ]);

        return app(EpreuveService::class)->creer($examen, [
            'session_id' => $session->id, 'matiere_id' => $this->ecue->id,
            'groupe_id' => $this->groupe->id, 'salle_id' => $this->salle->id,
            'date' => '2027-04-'.sprintf('%02d', $this->compteur),
            'heure_debut' => '09:00', 'heure_fin' => '11:00',
        ]);
    }

    public function test_doublon_actif_refuse_niveau_sql(): void
    {
        $epreuve = $this->creerEpreuve();
        $enseignant = $this->creerEnseignant('SQL1');

        Surveillance::create([
            'epreuve_id' => $epreuve->id, 'teacher_id' => $enseignant->id, 'statut' => 'assigne',
        ]);

        // Écriture contournant le service : la base refuse (0 doublon
        // physique même hors moteur métier).
        $this->expectException(QueryException::class);

        Surveillance::create([
            'epreuve_id' => $epreuve->id, 'teacher_id' => $enseignant->id, 'statut' => 'confirme',
        ]);
    }

    public function test_historique_annule_hors_contrainte(): void
    {
        $epreuve = $this->creerEpreuve();
        $enseignant = $this->creerEnseignant('SQL2');

        Surveillance::create([
            'epreuve_id' => $epreuve->id, 'teacher_id' => $enseignant->id, 'statut' => 'annule',
        ]);
        Surveillance::create([
            'epreuve_id' => $epreuve->id, 'teacher_id' => $enseignant->id, 'statut' => 'annule',
        ]);

        // L'historique annulé n'est jamais bloqué par la clé d'actifs.
        $this->assertSame(2, Surveillance::where('epreuve_id', $epreuve->id)->count());
    }

    public function test_simulation_100_enseignants_moins_de_10_requetes(): void
    {
        $epreuve = $this->creerEpreuve();
        $ids = [];

        for ($i = 0; $i < 100; $i++) {
            $ids[] = $this->creerEnseignant('SIM'.$i)->id;
        }

        DB::enableQueryLog();
        $bilan = app(SurveillanceService::class)->simuler($epreuve, $ids);
        $requetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        fwrite(STDERR, "\n[PERF-SIMUL] 100 enseignants : {$requetes} requêtes\n");

        $this->assertSame(100, $bilan['total_propositions']);
        $this->assertSame(100, $bilan['eligibles']);
        $this->assertLessThan(10, $requetes, "Simulation 100 enseignants : {$requetes} requêtes (cible < 10).");
    }

    public function test_cockpit_agrege_requetes_constantes_et_valeurs(): void
    {
        $epreuve = $this->creerEpreuve();
        $service = app(SurveillanceService::class);

        $g1 = $service->assigner($epreuve, ['teacher_id' => $this->creerEnseignant('CK1')->id]);
        $service->assigner($epreuve, ['teacher_id' => $this->creerEnseignant('CK2')->id]);
        $service->assigner($epreuve, ['teacher_id' => $this->creerEnseignant('CK3')->id]);
        $service->enregistrerPresence($g1->fresh(), 'present', '08:50');
        $service->desaffecter(
            Surveillance::where('epreuve_id', $epreuve->id)->where('statut', 'assigne')->firstOrFail(),
            'Erreur'
        );

        DB::enableQueryLog();
        $cockpit = $service->obtenirCockpit($this->faculte->id);
        $requetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        fwrite(STDERR, "\n[PERF-COCKPIT] requêtes : {$requetes}\n");

        // Valeurs métier exactes (garde-fou anti-dérive du refactoring).
        $this->assertSame(2, $cockpit['total_gardes']);
        $this->assertSame(1, $cockpit['gardes_confirmees']);
        $this->assertSame(1, $cockpit['gardes_en_attente']);
        $this->assertSame(1, $cockpit['surveillants_presents']);
        $this->assertSame(0, $cockpit['surveillants_retard']);
        $this->assertSame(0, $cockpit['surveillants_absents']);
        $this->assertSame(0, $cockpit['remplacements']);

        // 1 agrégation (7 KPI) + épreuves sans surveillant + top charges +
        // 1 eager d'affichage = 4 requêtes constantes (contre 10 avant).
        $this->assertLessThanOrEqual(4, $requetes, "Cockpit : {$requetes} requêtes (cible ≤ 4).");
    }
}
