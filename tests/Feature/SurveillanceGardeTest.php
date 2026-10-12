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
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Teacher;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP SURVEILLANCE & GARDE — Règles 1-2, machine d'états, verrou,
 * scope : refus propre du doublon (personne déjà en garde, deux salles
 * = impossibilité physique), émargement gardé, remplacement gardé,
 * index scopé, logique unique DS/examen/rattrapage.
 */
class SurveillanceGardeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Matiere $ecue;

    private Group $groupe;

    private Room $salle;

    private Teacher $enseignant1;

    private Teacher $enseignant2;

    private int $compteur = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.garde@universite.test', 'password' => 'Motdepasse@123',
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
        $this->enseignant1 = Teacher::create([
            'matricule' => 'ENS-G1', 'nom' => 'Ben Amor', 'prenom' => 'Tarek',
            'email' => 'tarek.garde@universite.test', 'actif' => true,
        ]);
        $this->enseignant2 = Teacher::create([
            'matricule' => 'ENS-G2', 'nom' => 'Trabelsi', 'prenom' => 'Sonia',
            'email' => 'sonia.garde@universite.test', 'actif' => true,
        ]);
    }

    private function creerEpreuve(string $typeSession = 'principale'): Epreuve
    {
        $this->compteur++;
        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id, 'code' => 'S-GARDE-'.$this->compteur,
            'nom' => 'Session', 'type' => $typeSession,
            'date_debut' => '2027-01-05', 'date_fin' => '2027-06-30', 'statut' => 'open', 'created_by' => $this->admin->id,
        ]);
        $examen = Examen::create([
            'session_id' => $session->id, 'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examen S1', 'type' => 'examen', 'statut' => 'scheduled', 'responsable_id' => $this->admin->id,
        ]);

        return app(EpreuveService::class)->creer($examen, [
            'session_id' => $session->id, 'matiere_id' => $this->ecue->id,
            'groupe_id' => $this->groupe->id, 'salle_id' => $this->salle->id,
            'date' => '2027-03-'.sprintf('%02d', $this->compteur),
            'heure_debut' => '09:00', 'heure_fin' => '11:00',
        ]);
    }

    public function test_double_affectation_meme_personne_refusee_proprement(): void
    {
        $epreuve = $this->creerEpreuve();
        $service = app(SurveillanceService::class);

        $service->assigner($epreuve, ['teacher_id' => $this->enseignant1->id, 'role' => 'principal']);

        // Règle 2 : une personne ne garde pas deux salles à la fois —
        // refus propre, jamais de doublon (ni silencieux, ni 500).
        try {
            $service->assigner($epreuve, ['teacher_id' => $this->enseignant1->id, 'role' => 'principal']);
            $this->fail('Le doublon aurait dû être refusé.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('déjà affecté', $e->getMessage());
        }

        $this->assertSame(1, Surveillance::where('epreuve_id', $epreuve->id)
            ->where('teacher_id', $this->enseignant1->id)->count());
    }

    public function test_multi_salles_enseignants_distincts(): void
    {
        $epreuve = $this->creerEpreuve();
        $autreSalle = Room::create([
            'code' => 'B13', 'nom' => 'B13', 'capacite' => 30, 'actif' => true, 'faculte_id' => $this->faculte->id,
        ]);
        $service = app(SurveillanceService::class);

        $service->assigner($epreuve, ['teacher_id' => $this->enseignant1->id, 'salle_id' => $this->salle->id]);
        $service->assigner($epreuve, ['teacher_id' => $this->enseignant2->id, 'salle_id' => $autreSalle->id]);

        $this->assertSame(2, Surveillance::where('epreuve_id', $epreuve->id)->count());
    }

    public function test_emargement_present_confirme(): void
    {
        $epreuve = $this->creerEpreuve();
        $service = app(SurveillanceService::class);
        $surveillance = $service->assigner($epreuve, ['teacher_id' => $this->enseignant1->id]);

        $service->enregistrerPresence($surveillance->fresh(), 'present', '08:45');

        $this->assertSame('confirme', $surveillance->fresh()->statut);
        $this->assertSame('present', $surveillance->fresh()->presence);
        $this->assertSame('08:45', substr((string) $surveillance->fresh()->heure_arrivee, 0, 5));
    }

    public function test_emargement_historique_remplace_refuse(): void
    {
        $epreuve = $this->creerEpreuve();
        $service = app(SurveillanceService::class);
        $surveillance = $service->assigner($epreuve, ['teacher_id' => $this->enseignant1->id]);
        $service->remplacer($surveillance->fresh(), ['teacher_id' => $this->enseignant2->id], 'Absent');

        // L'historique (remplacée) est immuable : seul le remplaçant s'émarge.
        $this->expectException(DomainException::class);
        $service->enregistrerPresence($surveillance->fresh(), 'present');
    }

    public function test_emargement_tardif_epreuve_verrouillee_autorise(): void
    {
        // Constat post-hoc comme les incidents : le verrou protège les
        // notes, pas l'enregistrement tardif d'une présence réelle.
        $epreuve = $this->creerEpreuve();
        $surveillance = app(SurveillanceService::class)->assigner($epreuve, [
            'teacher_id' => $this->enseignant1->id,
        ]);
        $epreuve->update(['statut' => 'locked']);

        app(SurveillanceService::class)->enregistrerPresence($surveillance->fresh(), 'retard', '09:10');

        $this->assertSame('retard', $surveillance->fresh()->presence);
    }

    public function test_remplacer_deja_remplace_refuse(): void
    {
        $epreuve = $this->creerEpreuve();
        $service = app(SurveillanceService::class);
        $surveillance = $service->assigner($epreuve, ['teacher_id' => $this->enseignant1->id]);
        $service->remplacer($surveillance->fresh(), ['teacher_id' => $this->enseignant2->id], 'Absent');

        $this->expectException(DomainException::class);
        $service->remplacer($surveillance->fresh(), ['teacher_id' => $this->enseignant2->id], 'Re-absent');
    }

    public function test_remplacer_epreuve_verrouillee_refuse_sans_toucher_ancien(): void
    {
        $epreuve = $this->creerEpreuve();
        $service = app(SurveillanceService::class);
        $surveillance = $service->assigner($epreuve, ['teacher_id' => $this->enseignant1->id]);
        $epreuve->update(['statut' => 'locked']);

        try {
            $service->remplacer($surveillance->fresh(), ['teacher_id' => $this->enseignant2->id]);
            $this->fail('Le remplacement aurait dû être refusé.');
        } catch (DomainException $e) {
            $this->assertSame('assigne', $surveillance->fresh()->statut);
            $this->assertSame(1, Surveillance::where('epreuve_id', $epreuve->id)->count());
        }
    }

    public function test_desaffecter_conserve_historique_motif(): void
    {
        $epreuve = $this->creerEpreuve();
        $service = app(SurveillanceService::class);
        $surveillance = $service->assigner($epreuve, ['teacher_id' => $this->enseignant1->id]);

        $service->desaffecter($surveillance->fresh(), 'Erreur de saisie');

        $this->assertSame('annule', $surveillance->fresh()->statut);
        $this->assertStringContainsString('Erreur de saisie', (string) $surveillance->fresh()->observations);
    }

    public function test_logique_unique_rattrapage(): void
    {
        // Même moteur pour principale et rattrapage : le type est une
        // donnée (session), pas une duplication de code.
        $epreuve = $this->creerEpreuve('rattrapage');
        $surveillance = app(SurveillanceService::class)->assigner($epreuve, [
            'teacher_id' => $this->enseignant1->id, 'role' => 'principal',
        ]);

        $this->assertSame('assigne', $surveillance->statut);
        $this->assertSame('rattrapage', $surveillance->epreuve->session->type);
    }

    public function test_index_scope_charges_et_enseignants(): void
    {
        $autreFaculte = Faculte::create(['nom' => 'FSJES', 'code' => 'FSJES', 'description' => 'T', 'active' => true]);
        $departement = Departement::create([
            'faculte_id' => $autreFaculte->id, 'code' => 'DEPT-X', 'nom' => 'Département X',
        ]);
        // Enseignant rattaché à l'autre faculté, SANS garde dans le
        // périmètre : ni sa charge ni son nom ne doivent fuiter.
        Teacher::create([
            'matricule' => 'ENS-X', 'nom' => 'Zargouni', 'prenom' => 'Rim',
            'email' => 'rim.autre@universite.test', 'actif' => true, 'departement_id' => $departement->id,
        ]);
        $this->creerEpreuve();

        $adminRestreint = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.garde@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculte->id,
        ], 'admin_faculte');

        $reponse = $this->actingAs($adminRestreint)->get(route('admin.evaluations.surveillances.index'));
        $reponse->assertOk();
        // Ni la charge ni le nom de l'enseignant hors périmètre ne fuient…
        $reponse->assertDontSee('Zargouni');
        $reponse->assertDontSee('rim.autre@universite.test');
        // …mais les enseignants sans rattachement restent proposés.
        $reponse->assertSee('Ben Amor');
    }

    public function test_store_sans_permission_403(): void
    {
        $epreuve = $this->creerEpreuve();
        $lecteur = User::creerCompteBackOffice([
            'name' => 'Lecteur', 'email' => 'lecteur.garde@universite.test', 'password' => 'Motdepasse@123',
        ], 'consultation');

        $this->actingAs($lecteur)->post(route('admin.evaluations.surveillants.store', $epreuve), [
            'teacher_id' => $this->enseignant1->id,
        ])->assertForbidden();
        $this->assertSame(0, Surveillance::count());
    }

    public function test_performance_simulation_100_enseignants(): void
    {
        $epreuve = $this->creerEpreuve();
        $ids = [$this->enseignant1->id, $this->enseignant2->id];

        for ($i = 0; $i < 98; $i++) {
            $ids[] = Teacher::create([
                'matricule' => 'ENS-P'.$i, 'nom' => 'Nom'.$i, 'prenom' => 'Prenom'.$i,
                'email' => "perf{$i}.garde@universite.test", 'actif' => true,
            ])->id;
        }

        $debut = microtime(true);
        \DB::enableQueryLog();
        $bilan = app(SurveillanceService::class)->simuler($epreuve, $ids);
        $requetes = count(\DB::getQueryLog());
        \DB::disableQueryLog();
        $duree = microtime(true) - $debut;

        fwrite(
            STDERR,
            "\n[PERF-GARDE] simulation 100 enseignants : ".round($duree, 2)."s, {$requetes} requêtes, ".
            "éligibles={$bilan['eligibles']}, pic=".round(memory_get_peak_usage(true) / 1048576, 1)."Mo\n"
        );

        $this->assertSame(100, $bilan['total_propositions']);
        $this->assertSame(100, $bilan['eligibles']);
        $this->assertLessThanOrEqual(10, $requetes, 'Le nombre de requêtes SQL pour 100 enseignants doit être <= 10.');
        $this->assertLessThan(30, $duree, 'Simulation 100 enseignants trop lente.');
    }

    public function test_integrite_sql_unicite_surveillance_active(): void
    {
        $epreuve = $this->creerEpreuve();

        // 1. Première surveillance active : OK
        Surveillance::create([
            'epreuve_id' => $epreuve->id,
            'teacher_id' => $this->enseignant1->id,
            'statut' => 'assigne',
        ]);

        // 2. Deuxième surveillance active pour le même enseignant en direct SQL : violation d'intégrité
        $exceptionLevee = false;
        try {
            Surveillance::create([
                'epreuve_id' => $epreuve->id,
                'teacher_id' => $this->enseignant1->id,
                'statut' => 'confirme',
            ]);
        } catch (QueryException $e) {
            $exceptionLevee = true;
        }

        $this->assertTrue($exceptionLevee, "L'insertion d'un doublon actif doit échouer au niveau SQL (contrainte d'unicité).");

        // 3. Plusieurs surveillances annulées pour le même enseignant : autorisées pour l'historique
        $annule1 = Surveillance::create([
            'epreuve_id' => $epreuve->id,
            'teacher_id' => $this->enseignant1->id,
            'statut' => 'annule',
        ]);
        $annule2 = Surveillance::create([
            'epreuve_id' => $epreuve->id,
            'teacher_id' => $this->enseignant1->id,
            'statut' => 'annule',
        ]);

        $this->assertNotNull($annule1->id);
        $this->assertNotNull($annule2->id);
    }

    public function test_performance_cockpit_requetes_constantes(): void
    {
        $epreuve = $this->creerEpreuve();

        // Création d'un jeu de surveillances avec statuts variés
        Surveillance::create(['epreuve_id' => $epreuve->id, 'teacher_id' => $this->enseignant1->id, 'statut' => 'confirme', 'presence' => 'present']);
        Surveillance::create(['epreuve_id' => $epreuve->id, 'teacher_id' => $this->enseignant2->id, 'statut' => 'assigne', 'presence' => 'retard']);

        $service = app(SurveillanceService::class);

        \DB::enableQueryLog();
        $cockpit = $service->obtenirCockpit();
        $requetes = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        // 1 requête agrégée compteurs + 1 requête épreuves non couvertes + 1 requête top charges = 3 requêtes
        $this->assertLessThanOrEqual(4, $requetes, "Le cockpit doit s'exécuter en un nombre constant minimal de requêtes.");
        $this->assertSame(2, $cockpit['total_gardes']);
        $this->assertSame(1, $cockpit['gardes_confirmees']);
        $this->assertSame(1, $cockpit['gardes_en_attente']);
        $this->assertSame(1, $cockpit['surveillants_presents']);
        $this->assertSame(1, $cockpit['surveillants_retard']);
        $this->assertSame(0, $cockpit['surveillants_absents']);
        $this->assertSame(0, $cockpit['remplacements']);
    }
}
