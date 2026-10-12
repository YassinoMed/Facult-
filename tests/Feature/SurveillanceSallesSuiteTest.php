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
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Evaluation\Models\SurveillanceConstraintConfig;
use App\Modules\Evaluation\Services\ConflitEpreuveService;
use App\Modules\Evaluation\Services\ExamRoomAssignmentService;
use App\Modules\Evaluation\Services\SurveillanceEquilibrageService;
use App\Modules\Evaluation\Services\SurveillanceService;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite points 2/3/4 (GO 09/10/2026) : ventilation surveillant↔salle,
 * conflits multi-salles, cockpit sur besoins configurés. Moteur et services
 * existants réutilisés, aucune duplication.
 */
class SurveillanceSallesSuiteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private SessionExamen $session;

    private Examen $examen;

    private Matiere $matiere;

    private int $compteur = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.evaluation.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.suite-salles@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $programme = Programme::create([
            'code' => 'L-INFO', 'nom' => 'Licence Info', 'faculte_id' => $this->faculte->id,
            'cycle' => 'licence', 'duree_annees' => 3, 'actif' => true,
        ]);
        $this->session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id, 'code' => 'SESS-SUITE', 'nom' => 'Session suite salles',
            'type' => 'principale', 'statut' => 'open', 'date_debut' => '2027-01-10', 'date_fin' => '2027-01-20',
        ]);
        $this->examen = Examen::create([
            'session_id' => $this->session->id, 'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $programme->id, 'libelle' => 'Examens', 'type' => 'examen', 'statut' => 'scheduled',
        ]);
        $this->matiere = Matiere::create([
            'code' => 'ALGO', 'nom' => 'Algo', 'niveau' => 'L1', 'semestre' => 1,
            'coefficient' => 2, 'credits' => 4,
        ]);
    }

    private function creerSalle(string $code, int $capacite): Room
    {
        return Room::create([
            'code' => $code, 'nom' => $code, 'capacite' => $capacite,
            'actif' => true, 'faculte_id' => $this->faculte->id,
        ]);
    }

    private function creerEpreuve(array $attributs = []): Epreuve
    {
        return Epreuve::create(array_merge([
            'code' => 'EPR-S'.(++$this->compteur).'-'.uniqid(),
            'examen_id' => $this->examen->id, 'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id, 'matiere_id' => $this->matiere->id,
            'date' => '2027-01-12', 'heure_debut' => '09:00:00', 'heure_fin' => '11:00:00',
            'duree_minutes' => 120, 'type_evaluation' => 'examen', 'statut' => 'scheduled',
        ], $attributs));
    }

    private function creerEnseignants(int $nombre): array
    {
        $dep = Departement::create([
            'code' => 'DEP-SUITE-'.(++$this->compteur).'-'.uniqid(), 'nom' => 'Département',
            'faculte_id' => $this->faculte->id,
        ]);

        $liste = [];
        for ($i = 1; $i <= $nombre; $i++) {
            $liste[] = Teacher::create([
                'matricule' => 'SUI-'.$this->compteur.'-'.$i.'-'.uniqid(),
                'nom' => 'Nom'.$i, 'prenom' => 'Prenom'.$i,
                'email' => "suite{$this->compteur}_{$i}_".uniqid().'@universite.test',
                'departement_id' => $dep->id, 'actif' => true,
            ]);
        }

        return $liste;
    }

    private function ventilerCandidats(Epreuve $epreuve, array $repartition): void
    {
        foreach ($repartition as $salleId => $nombre) {
            for ($i = 0; $i < $nombre; $i++) {
                $etudiant = Etudiant::create([
                    'cin' => 'SUI'.(++$this->compteur).'C'.$i.'E'.$epreuve->id, 'nom' => 'Etu', 'prenom' => 'X'.$i,
                    'email' => "sui{$this->compteur}_{$i}_{$epreuve->id}@test.ma", 'date_naissance' => '2004-01-01',
                    'sexe' => 'M', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
                    'annee_academique' => '2026-2027', 'adresse' => '12 rue', 'ville' => 'Casa',
                    'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
                ]);
                $etudiant->definirStatut('valide');
                Candidat::create([
                    'epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id,
                    'salle_id' => $salleId, 'statut' => 'present',
                ]);
            }
        }
    }

    // ---------- Point 2 : ventilation surveillant ↔ salle ----------

    public function test_ventilation_simulee_par_salle_selon_besoins(): void
    {
        $salleA = $this->creerSalle('V-A', 40);
        $salleB = $this->creerSalle('V-B', 100);
        $epreuve = $this->creerEpreuve(['salle_id' => $salleA->id]);
        $this->ventilerCandidats($epreuve, [$salleA->id => 25, $salleB->id => 70]);
        $this->creerEnseignants(6);

        $simulation = app(SurveillanceEquilibrageService::class)->simulerSession($this->session);
        $proposition = collect($simulation['propositions_epreuves'])->firstWhere('epreuve_id', $epreuve->id);

        $this->assertTrue($proposition['complet']);
        $actifs = collect($proposition['surveillants'])->where('role', '!=', 'reserve')->values();

        // Chaque actif porte une salle de l'épreuve, au prorata des besoins (1 + 2).
        $this->assertSame(3, $actifs->count());
        $parSalle = $actifs->groupBy('salle_id');
        $this->assertSame(1, $parSalle->get($salleA->id)->count());
        $this->assertSame(2, $parSalle->get($salleB->id)->count());

        // Principal unique, dans la salle au plus fort besoin.
        $principaux = $actifs->where('role', 'principal')->values();
        $this->assertCount(1, $principaux);
        $this->assertSame($salleB->id, $principaux->first()['salle_id']);

        // La garde reste rattachée à la salle principale (comportement conservé).
        $gardes = collect($proposition['surveillants'])->where('role', 'reserve')->values();
        $this->assertCount(1, $gardes);
        $this->assertSame($salleA->id, $gardes->first()['salle_id']);
    }

    public function test_ventilation_persiste_a_l_application(): void
    {
        $salleA = $this->creerSalle('W-A', 40);
        $salleB = $this->creerSalle('W-B', 100);
        $epreuve = $this->creerEpreuve(['salle_id' => $salleA->id]);
        $this->ventilerCandidats($epreuve, [$salleA->id => 25, $salleB->id => 70]);
        $this->creerEnseignants(6);

        $service = app(SurveillanceEquilibrageService::class);
        $simulation = $service->simulerSession($this->session);
        $service->appliquerSession($this->session, $simulation, $this->admin->id);

        $ventilation = Surveillance::where('epreuve_id', $epreuve->id)
            ->where('statut', '!=', 'annule')->where('role', '!=', 'reserve')
            ->get()->groupBy('salle_id');

        $this->assertSame(1, $ventilation->get($salleA->id)->count());
        $this->assertSame(2, $ventilation->get($salleB->id)->count());
    }

    public function test_ventilation_deterministe(): void
    {
        $salleA = $this->creerSalle('D-A', 40);
        $salleB = $this->creerSalle('D-B', 200);
        $epreuve = $this->creerEpreuve(['salle_id' => $salleA->id]);
        $this->ventilerCandidats($epreuve, [$salleA->id => 25, $salleB->id => 120]);
        $this->creerEnseignants(8);

        $service = app(SurveillanceEquilibrageService::class);
        $premier = $service->simulerSession($this->session);
        $second = $service->simulerSession($this->session);

        $extraire = fn (array $sim) => collect($sim['propositions_epreuves'])
            ->firstWhere('epreuve_id', $epreuve->id)['surveillants'];

        $this->assertSame(json_encode($extraire($premier)), json_encode($extraire($second)));
    }

    public function test_salle_forgee_hors_perimetre_repliee_sans_fuite(): void
    {
        $salleA = $this->creerSalle('F-A', 100);
        $salleEtrangere = $this->creerSalle('F-X', 100);
        $epreuve = $this->creerEpreuve(['salle_id' => $salleA->id]);
        $this->ventilerCandidats($epreuve, [$salleA->id => 20]);
        $this->creerEnseignants(4);

        $service = app(SurveillanceEquilibrageService::class);
        $simulation = $service->simulerSession($this->session);

        // Proposition falsifiée : salle hors de l'épreuve → repli salle principale.
        foreach ($simulation['propositions_epreuves'] as &$prop) {
            foreach ($prop['surveillants'] as &$surv) {
                if (empty($surv['existant'])) {
                    $surv['salle_id'] = $salleEtrangere->id;
                }
            }
            unset($surv);
        }
        unset($prop);

        $service->appliquerSession($this->session, $simulation, $this->admin->id);

        $sallesPersistees = Surveillance::where('epreuve_id', $epreuve->id)
            ->where('statut', '!=', 'annule')->pluck('salle_id')->unique()->all();
        $this->assertSame([$salleA->id], $sallesPersistees);
    }

    public function test_mono_salle_non_regression_salle_principale(): void
    {
        $salle = $this->creerSalle('M-1', 100);
        $epreuve = $this->creerEpreuve(['salle_id' => $salle->id]);
        $this->ventilerCandidats($epreuve, [$salle->id => 20]);
        $this->creerEnseignants(4);

        $simulation = app(SurveillanceEquilibrageService::class)->simulerSession($this->session);
        $proposition = collect($simulation['propositions_epreuves'])->firstWhere('epreuve_id', $epreuve->id);

        foreach ($proposition['surveillants'] as $surv) {
            $this->assertSame($salle->id, $surv['salle_id']);
        }
    }

    // ---------- Point 3 : conflits multi-salles ----------

    public function test_salle_secondaire_occupee_detectee(): void
    {
        $salleA = $this->creerSalle('C-A', 100);
        $salleB = $this->creerSalle('C-B', 100);
        // Épreuve A : principale A, secondaire B via candidats.
        $epreuveA = $this->creerEpreuve(['salle_id' => $salleA->id, 'code' => 'EPR-C-A']);
        $this->ventilerCandidats($epreuveA, [$salleA->id => 10, $salleB->id => 10]);

        // Épreuve B chevauchante en salle B → conflit bloquant.
        $conflits = app(ConflitEpreuveService::class)->detecter([
            'date' => '2027-01-12', 'heure_debut' => '09:30', 'heure_fin' => '11:30',
            'salle_id' => $salleB->id,
        ], null);

        $bloquants = app(ConflitEpreuveService::class)->bloquants($conflits);
        $this->assertNotEmpty($bloquants);
        $this->assertSame('salle', $bloquants[0]['type']);
        $this->assertStringContainsString('C-B', $bloquants[0]['message']);
    }

    public function test_salle_secondaire_libre_hors_creneau(): void
    {
        $salleA = $this->creerSalle('C2-A', 100);
        $salleB = $this->creerSalle('C2-B', 100);
        $epreuveA = $this->creerEpreuve(['salle_id' => $salleA->id, 'code' => 'EPR-C2-A']);
        $this->ventilerCandidats($epreuveA, [$salleA->id => 10, $salleB->id => 10]);

        // Même salles, créneau disjoint → aucun conflit salle.
        $conflits = app(ConflitEpreuveService::class)->detecter([
            'date' => '2027-01-12', 'heure_debut' => '14:00', 'heure_fin' => '16:00',
            'salle_ids' => [$salleA->id, $salleB->id],
        ], null);

        $salle = array_values(array_filter(
            app(ConflitEpreuveService::class)->bloquants($conflits),
            fn (array $c) => $c['type'] === 'salle'
        ));
        $this->assertSame([], $salle);
    }

    public function test_repartition_refuse_salle_secondaire_occupee(): void
    {
        $salleA = $this->creerSalle('R-A', 100);
        $salleB = $this->creerSalle('R-B', 100);
        $epreuveA = $this->creerEpreuve(['salle_id' => $salleA->id, 'code' => 'EPR-R-A']);
        $this->ventilerCandidats($epreuveA, [$salleA->id => 10, $salleB->id => 10]);

        // Épreuve B chevauchante dont la principale occupe déjà la salle B.
        $epreuveB = $this->creerEpreuve(['salle_id' => $salleB->id, 'code' => 'EPR-R-B']);
        $this->ventilerCandidats($epreuveB, [$salleB->id => 5]);

        $service = app(ExamRoomAssignmentService::class);
        $simulation = $service->simulerRepartition($epreuveB, [$salleB->id, $salleA->id]);

        $this->assertFalse($simulation['valide']);
        $messages = implode("\n", array_column($simulation['conflits'], 'message'));
        $this->assertStringContainsString('R-B', $messages);
    }

    public function test_conflit_salle_principale_inchange(): void
    {
        $salle = $this->creerSalle('C3', 100);
        $this->creerEpreuve(['salle_id' => $salle->id, 'code' => 'EPR-C3']);

        $conflits = app(ConflitEpreuveService::class)->detecter([
            'date' => '2027-01-12', 'heure_debut' => '09:30', 'heure_fin' => '11:30',
            'salle_id' => $salle->id,
        ], null);

        $this->assertSame('salle', app(ConflitEpreuveService::class)->bloquants($conflits)[0]['type']);
    }

    // ---------- Point 4 : cockpit sur besoins configurés ----------

    public function test_cockpit_suit_min_configuré(): void
    {
        SurveillanceConstraintConfig::create([
            'faculte_id' => null, 'annee_universitaire_id' => null, 'session_examen_id' => null,
            'min_surveillants' => 3, 'min_par_salle' => 1, 'ratio_etudiants' => 50, 'nb_gardes' => 1,
            'max_jours' => 3, 'mode_limite' => 'souple',
            'poids_surveillance_active' => 1, 'poids_garde_reserve' => 0.5,
            'poids_charge_totale' => 10, 'poids_charge_jour' => 15, 'poids_gardes' => 8,
            'penalite_depassement_jours' => 35, 'penalite_dispersion' => 10, 'penalite_nouveau_jour' => 2,
            'version' => 1,
        ]);

        $salle = $this->creerSalle('K-1', 100);
        $epreuve = $this->creerEpreuve(['salle_id' => $salle->id]);
        $this->ventilerCandidats($epreuve, [$salle->id => 20]);
        [$t1, $t2, $t3] = $this->creerEnseignants(3);

        // 2 actifs + 1 garde : conforme à l'ancien seuil dur, incomplet à min 3.
        $service = app(SurveillanceService::class);
        $service->assigner($epreuve, ['teacher_id' => $t1->id, 'role' => 'principal']);
        $service->assigner($epreuve, ['teacher_id' => $t2->id, 'role' => 'adjoint']);
        $service->assigner($epreuve, ['teacher_id' => $t3->id, 'role' => 'reserve']);

        $cockpit = $this->actingAs($this->admin)
            ->get(route('admin.evaluations.surveillances.cockpit'))
            ->assertOk()
            ->viewData('cockpit');

        $this->assertSame(0, $cockpit['epreuves_correctes']);
        $this->assertSame(1, $cockpit['epreuves_incompletes']);
        $this->assertSame(0, $cockpit['epreuves_sans_garde']);
    }

    public function test_cockpit_complet_selon_config_et_capacite_moteur(): void
    {
        $salle = $this->creerSalle('K-2', 100);
        $epreuve = $this->creerEpreuve(['salle_id' => $salle->id, 'capacite_necessaire' => 20]);
        $this->ventilerCandidats($epreuve, [$salle->id => 20]);
        [$t1, $t2, $t3] = $this->creerEnseignants(3);

        $service = app(SurveillanceService::class);
        $service->assigner($epreuve, ['teacher_id' => $t1->id, 'role' => 'principal']);
        $service->assigner($epreuve, ['teacher_id' => $t2->id, 'role' => 'adjoint']);
        $service->assigner($epreuve, ['teacher_id' => $t3->id, 'role' => 'reserve']);

        $cockpit = $this->actingAs($this->admin)
            ->get(route('admin.evaluations.surveillances.cockpit'))
            ->assertOk()
            ->viewData('cockpit');

        $this->assertSame(1, $cockpit['epreuves_correctes']);
        $this->assertSame(0, $cockpit['epreuves_incompletes']);
        $this->assertSame(0, $cockpit['epreuves_probleme_capacite']);
    }

    public function test_cockpit_requetes_bornees(): void
    {
        $salle = $this->creerSalle('K-3', 200);
        for ($i = 0; $i < 5; $i++) {
            $epreuve = $this->creerEpreuve(['salle_id' => $salle->id, 'code' => 'EPR-K3-'.$i]);
            $this->ventilerCandidats($epreuve, [$salle->id => 20]);
        }
        $this->creerEnseignants(4);

        \DB::enableQueryLog();
        $this->actingAs($this->admin)
            ->get(route('admin.evaluations.surveillances.cockpit'))
            ->assertOk();
        $requetes = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertLessThan(40, $requetes, "Cockpit 5 épreuves : {$requetes} requêtes.");
    }
}
