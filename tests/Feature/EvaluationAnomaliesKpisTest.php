<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\NoteEpreuve;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\EpreuveService;
use App\Modules\Evaluation\Services\EvaluationQualityService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOT 03 (dette LOT 05) — les KPI d'anomalies dérivent de la même passe
 * de détection que la liste (sans refaire un recalcul complet à chaque
 * appel). Sémantique préservée : KPI = périmètre global, liste = périmètre
 * filtré, un seul passage quand aucun filtre n'est actif.
 */
class EvaluationAnomaliesKpisTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Matiere $matiere;

    private Group $groupe;

    private int $compteur = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculte = Faculte::create([
            'nom' => 'FST KPI',
            'code' => 'FST-KPI',
            'description' => 'Test',
            'active' => true,
        ]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin KPI',
            'email' => 'admin.kpi@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'LGL-KPI',
            'nom' => 'Licence KPI',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
            'type' => 'licence',
            'statut' => 'actif',
        ]);
        $niveau = Niveau::create([
            'programme_id' => $this->programme->id,
            'code' => 'L1',
            'nom' => 'L1',
            'ordre' => 1,
        ]);
        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id,
            'code' => 'S1',
            'nom' => 'S1',
            'numero' => 1,
            'ordre' => 1,
        ]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id,
            'programme_id' => $this->programme->id,
            'code' => 'UE-KPI',
            'nom' => 'Algo KPI',
            'credits' => 6,
            'coefficient' => 2,
        ]);
        $this->matiere = Matiere::create([
            'code' => 'MAT-KPI',
            'nom' => 'Algorithmique KPI',
            'ue_id' => $ue->id,
            'niveau' => 'L1',
            'semestre' => 1,
            'credits' => 6,
            'coefficient' => 2,
        ]);
        $this->groupe = Group::create([
            'code' => 'G1-KPI',
            'nom' => 'Groupe 1 KPI',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'actif' => true,
        ]);
        Room::create([
            'code' => 'B12-KPI',
            'nom' => 'B12 KPI',
            'capacite' => 40,
            'actif' => true,
        ]);
    }

    public function test_kpis_depuis_anomalies_correspondent_a_kpis(): void
    {
        $service = app(EvaluationQualityService::class);

        $detectees = $service->detecterAnomalies(null);

        $this->assertSame(
            $service->kpis(null),
            $service->kpisDepuisAnomalies($detectees),
        );
    }

    public function test_la_page_sans_filtre_derive_les_kpis_de_la_meme_passe(): void
    {
        $epreuve = $this->creerEpreuve();
        $epreuve->update(['bareme' => 10.00]);
        $etudiant = $this->creerEtudiant();
        Candidat::create([
            'epreuve_id' => $epreuve->id,
            'etudiant_id' => $etudiant->id,
            'statut' => 'present',
        ]);
        NoteEpreuve::create([
            'epreuve_id' => $epreuve->id,
            'etudiant_id' => $etudiant->id,
            'valeur' => 14.00, // > barème de 10 : anomalie bloquante
            'note_max' => 20.00,
            'coefficient' => 1.0,
            'statut' => 'entered',
        ]);

        $service = app(EvaluationQualityService::class);
        $attendu = $service->kpis(null);

        $reponse = $this->actingAs($this->admin)
            ->get(route('admin.evaluations.anomalies.index'));

        $reponse->assertOk();

        $kpis = $reponse->viewData('kpis');
        $this->assertSame($attendu['total'], $kpis['total']);
        $this->assertSame($attendu['bloquante'], $kpis['bloquante']);
        $this->assertSame($attendu['avertissement'], $kpis['avertissement']);
        $this->assertSame($attendu['info'], $kpis['info']);
        $this->assertGreaterThanOrEqual(1, $kpis['total']);
    }

    public function test_le_filtre_affichage_conserve_des_kpis_de_perimetre_global(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerEtudiant();
        Candidat::create([
            'epreuve_id' => $epreuve->id,
            'etudiant_id' => $etudiant->id,
            'statut' => 'present',
        ]);

        // Une note en attente (anomalie en attente, pas bloquante) : la
        // liste filtrée sur une recherche impossible est vide, mais le
        // KPI total reprend la détection complète du périmètre
        // (sémantique dashboard, jamais réduite au filtre d'affichage).
        NoteEpreuve::create([
            'epreuve_id' => $epreuve->id,
            'etudiant_id' => $etudiant->id,
            'valeur' => 12.50,
            'note_max' => 20.00,
            'coefficient' => 1.0,
            'statut' => 'submitted',
        ]);

        $service = app(EvaluationQualityService::class);
        $attendu = $service->kpis(null);

        $reponse = $this->actingAs($this->admin)
            ->get(route('admin.evaluations.anomalies.index', ['recherche' => 'BLOC-INEXISTANT-ZZ']));

        $reponse->assertOk();

        $kpis = $reponse->viewData('kpis');
        $this->assertSame($attendu['total'], $kpis['total']);

        $listeFiltree = $reponse->viewData('anomalies');
        $this->assertSame(0, $listeFiltree->total());
    }

    /** Épreuve sans salle (mêmes repères que ServiceQualitéTest). */
    private function creerEpreuve(): Epreuve
    {
        $this->compteur++;
        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'S-KPI-'.$this->compteur.'-'.random_int(1000, 9999),
            'nom' => 'Principale KPI',
            'type' => 'principale',
            'date_debut' => '2027-01-05',
            'date_fin' => '2027-01-20',
            'statut' => 'open',
            'created_by' => $this->admin->id,
        ]);
        $examen = Examen::create([
            'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examen KPI S1',
            'type' => 'examen',
            'statut' => 'scheduled',
            'responsable_id' => $this->admin->id,
        ]);

        return app(EpreuveService::class)->creer($examen, [
            'session_id' => $session->id,
            'matiere_id' => $this->matiere->id,
            'groupe_id' => $this->groupe->id,
            'date' => '2027-01-'.sprintf('%02d', 10 + $this->compteur),
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
        ]);
    }

    private function creerEtudiant(): Etudiant
    {
        static $compteurEtudiant = 300000;
        $suffixe = ++$compteurEtudiant;

        $etudiant = Etudiant::create([
            'cin' => 'KP'.$suffixe,
            'nom' => 'Bennani',
            'prenom' => 'Yassine',
            'email' => 'yassine.'.$suffixe.'@test.ma',
            'date_naissance' => '2004-01-01',
            'sexe' => 'H',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '12 rue des Tests',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-2026-'.$suffixe])->save();
        $etudiant->definirStatut('valide');

        $inscription = $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1',
            'statut' => 'en_cours',
        ]);
        $inscription->semestres()->create(['numero' => 1]);
        $inscription->semestres()->create(['numero' => 2]);

        return $etudiant;
    }
}
