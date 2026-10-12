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
use App\Modules\Evaluation\Models\SurveillanceAssignmentRun;
use App\Modules\Evaluation\Models\SurveillanceConstraintConfig;
use App\Modules\Evaluation\Models\SurveillanceConstraintRevision;
use App\Modules\Evaluation\Services\ContraintesSurveillance;
use App\Modules\Evaluation\Services\SurveillanceConstraintResolver;
use App\Modules\Evaluation\Services\SurveillanceEquilibrageService;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Contraintes configurables d'affectation des surveillances : résolution,
 * validation, moteur branché, sécurité, déterminisme, non-régression.
 */
class ContraintesSurveillanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private SessionExamen $session;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.evaluation.active' => true]);

        $this->faculteA = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'FDL', 'code' => 'FDL', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.contraintes@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id, 'code' => 'SESS-CTR', 'nom' => 'Session contraintes',
            'type' => 'principale', 'statut' => 'open', 'date_debut' => '2027-01-10', 'date_fin' => '2027-01-20',
        ]);
    }

    private function baseParams(array $surcharge = []): array
    {
        return array_merge([
            'min_surveillants' => 2,
            'ratio_etudiants' => 50,
            'nb_gardes' => 1,
            'max_surveillants' => null,
            'poids_surveillance_active' => 1,
            'poids_garde_reserve' => 0.5,
            'max_jours' => 3,
            'mode_limite' => 'souple',
            'actif_charge_totale' => true,
            'poids_charge_totale' => 10,
            'actif_charge_jour' => true,
            'poids_charge_jour' => 15,
            'actif_gardes' => true,
            'poids_gardes' => 8,
            'actif_limite_jours' => true,
            'penalite_depassement_jours' => 35,
            'actif_dispersion' => true,
            'penalite_dispersion' => 10,
            'actif_nouveau_jour' => true,
            'penalite_nouveau_jour' => 2,
        ], $surcharge);
    }

    private function creerContexte(): array
    {
        $programme = Programme::create([
            'code' => 'L-INFO', 'nom' => 'Licence Info', 'faculte_id' => $this->faculteA->id,
            'cycle' => 'licence', 'duree_annees' => 3, 'actif' => true,
        ]);
        $groupe = Group::create([
            'faculte_id' => $this->faculteA->id, 'code' => 'G1', 'nom' => 'G1', 'niveau' => 'L1', 'actif' => true,
        ]);
        $matiere = Matiere::create([
            'code' => 'ALGO', 'nom' => 'Algo', 'niveau' => 'L1', 'semestre' => 1,
            'coefficient' => 2, 'credits' => 4, 'volume_cm' => 20,
        ]);
        $salle = Room::create([
            'code' => 'A1', 'nom' => 'A1', 'capacite' => 150, 'faculte_id' => $this->faculteA->id, 'actif' => true,
        ]);
        $examen = Examen::create([
            'session_id' => $this->session->id, 'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $programme->id, 'libelle' => 'Ex', 'type' => 'examen', 'statut' => 'scheduled',
        ]);

        return compact('programme', 'groupe', 'matiere', 'salle', 'examen');
    }

    private function creerEpreuve(array $c, array $attr = []): Epreuve
    {
        return Epreuve::create(array_merge([
            'annee_universitaire_id' => $this->annee->id, 'session_id' => $this->session->id,
            'examen_id' => $c['examen']->id, 'matiere_id' => $c['matiere']->id, 'groupe_id' => $c['groupe']->id,
            'salle_id' => $c['salle']->id, 'code' => 'EPR-'.uniqid(), 'date' => '2027-01-12',
            'heure_debut' => '09:00:00', 'heure_fin' => '11:00:00', 'duree_minutes' => 120,
            'type_evaluation' => 'examen', 'coefficient' => 2, 'bareme' => 20, 'note_max' => 20,
            'capacite_necessaire' => 10, 'statut' => 'scheduled',
        ], $attr));
    }

    private function creerEnseignant(string $email): Teacher
    {
        $dep = Departement::create([
            'nom' => 'D', 'code' => 'DEP-'.uniqid(), 'faculte_id' => $this->faculteA->id, 'actif' => true,
        ]);

        return Teacher::create([
            'departement_id' => $dep->id, 'nom' => 'N', 'prenom' => 'P', 'email' => $email,
            'statut' => 'titulaire', 'actif' => true,
        ]);
    }

    // =========================================================================
    // 1. VALEURS PAR DÉFAUT = CONSTANTES (non-régression)
    // =========================================================================

    public function test_defauts_egaux_constantes_historiques(): void
    {
        $c = new ContraintesSurveillance;

        $this->assertSame(2, $c->minSurveillants);
        $this->assertSame(50, $c->ratioEtudiants);
        $this->assertSame(1, $c->nbGardes);
        $this->assertNull($c->maxSurveillants);
        $this->assertSame(1.0, $c->poidsSurveillanceActive);
        $this->assertSame(0.5, $c->poidsGardeReserve);
        $this->assertSame(3, $c->maxJours);
        $this->assertSame('souple', $c->modeLimite);
        $this->assertSame(10.0, $c->poidsChargeTotale);
        $this->assertSame(15.0, $c->poidsChargeJour);
        $this->assertSame(8.0, $c->poidsGardes);
        $this->assertSame(35.0, $c->penaliteDepassementJours);
        $this->assertSame(10.0, $c->penaliteDispersion);
        $this->assertSame(2.0, $c->penaliteNouveauJour);
        $this->assertSame(3, $c->besoinActifs(150, 2));
        $this->assertFalse($c->limiteJoursStricte());
    }

    public function test_simulation_defauts_identique_legacy(): void
    {
        $c = $this->creerContexte();
        $this->creerEpreuve($c, ['code' => 'E1', 'date' => '2027-01-11']);
        $this->creerEpreuve($c, ['code' => 'E2', 'date' => '2027-01-12']);
        for ($i = 0; $i < 6; $i++) {
            $this->creerEnseignant("rg{$i}@univ.test");
        }

        $service = app(SurveillanceEquilibrageService::class);
        $sans = $service->simulerSession($this->session, ['faculte_id' => null]);
        $avec = $service->simulerSession($this->session, ['faculte_id' => null, 'contraintes' => new ContraintesSurveillance]);

        $this->assertSame($sans['statut'], $avec['statut']);
        $this->assertSame($sans['total_surveillants_actifs'], $avec['total_surveillants_actifs']);
        $cles = fn (array $sim) => collect($sim['propositions_epreuves'])
            ->map(fn ($p) => $p['epreuve_id'].':'.collect($p['surveillants'])->pluck('teacher_id')->sort()->implode(','))
            ->sort()->values()->all();
        $this->assertSame($cles($sans), $cles($avec));
    }

    // =========================================================================
    // 2. RÉSOLUTION PAR PORTÉE
    // =========================================================================

    public function test_resolution_priorite_session_faculte_annee_systeme(): void
    {
        $resolver = app(SurveillanceConstraintResolver::class);

        // Rien : défauts système.
        $this->assertSame(50, $resolver->resoudre(null, null, null)->ratioEtudiants);

        SurveillanceConstraintConfig::create(['ratio_etudiants' => 40]);
        SurveillanceConstraintConfig::create(['annee_universitaire_id' => $this->annee->id, 'ratio_etudiants' => 30]);
        SurveillanceConstraintConfig::create([
            'faculte_id' => $this->faculteA->id, 'annee_universitaire_id' => $this->annee->id, 'ratio_etudiants' => 25,
        ]);
        SurveillanceConstraintConfig::create(['session_examen_id' => $this->session->id, 'ratio_etudiants' => 20]);

        $this->assertSame(40, $resolver->resoudre(null, null, null)->ratioEtudiants);
        $this->assertSame(30, $resolver->resoudre(null, $this->annee->id, null)->ratioEtudiants);
        $this->assertSame(25, $resolver->resoudre($this->faculteA->id, $this->annee->id, null)->ratioEtudiants);
        $this->assertSame(20, $resolver->resoudre($this->faculteA->id, $this->annee->id, $this->session->id)->ratioEtudiants);
        // Autre faculté : la ligne faculte+année ne s'applique pas.
        $this->assertSame(30, $resolver->resoudre($this->faculteB->id, $this->annee->id, null)->ratioEtudiants);
    }

    // =========================================================================
    // 3. CRUD + VALIDATION + RÉVISIONS
    // =========================================================================

    public function test_page_contraintes_et_creation(): void
    {
        $reponse = $this->actingAs($this->admin)->get(route('admin.evaluations.surveillances.contraintes.index'));
        $reponse->assertOk();
        $reponse->assertSee('Contraintes d’affectation');
        $reponse->assertSee('non modifiables');

        $reponse = $this->actingAs($this->admin)->post(
            route('admin.evaluations.surveillances.contraintes.store'),
            array_merge($this->baseParams(['ratio_etudiants' => 30]), [
                'faculte_id' => $this->faculteA->id,
                'annee_universitaire_id' => $this->annee->id,
            ])
        );
        $reponse->assertRedirect();
        $config = SurveillanceConstraintConfig::firstOrFail();
        $this->assertSame(30, $config->ratio_etudiants);
        $this->assertSame(1, $config->version);
        $this->assertSame(1, SurveillanceConstraintRevision::where('config_id', $config->id)->count());
    }

    public function test_validation_rejette_incoherences(): void
    {
        $cas = [
            ['ratio_etudiants' => 0],
            ['min_surveillants' => 0],
            ['max_surveillants' => 1, 'min_surveillants' => 2],
            ['nb_gardes' => -1],
            ['mode_limite' => 'strict', 'actif_limite_jours' => false],
            ['mode_limite' => 'inconnu'],
        ];

        foreach ($cas as $surcharge) {
            $this->actingAs($this->admin)->post(
                route('admin.evaluations.surveillances.contraintes.store'),
                array_merge($this->baseParams($surcharge), ['session_examen_id' => $this->session->id])
            )->assertSessionHasErrors();
        }

        $this->assertSame(0, SurveillanceConstraintConfig::count());
    }

    public function test_update_versionne_et_reset_restaure(): void
    {
        $config = SurveillanceConstraintConfig::create(array_merge(
            $this->baseParams(), ['session_examen_id' => $this->session->id, 'version' => 1]
        ));

        $this->actingAs($this->admin)->put(
            route('admin.evaluations.surveillances.contraintes.update', $config),
            $this->baseParams(['ratio_etudiants' => 25])
        )->assertRedirect();

        $config->refresh();
        $this->assertSame(2, $config->version);
        $this->assertSame(25, $config->ratio_etudiants);
        $this->assertSame(1, SurveillanceConstraintRevision::where('config_id', $config->id)->where('version', 2)->count());

        $this->actingAs($this->admin)
            ->post(route('admin.evaluations.surveillances.contraintes.reset'), ['session_id' => $this->session->id])
            ->assertRedirect();
        $this->assertDatabaseMissing('surveillance_constraint_configs', ['id' => $config->id]);
    }

    public function test_forme_portee_invalide_refusee(): void
    {
        // Faculté seule (sans année) : hors contrat hiérarchique.
        $this->actingAs($this->admin)->post(
            route('admin.evaluations.surveillances.contraintes.store'),
            array_merge($this->baseParams(), ['faculte_id' => $this->faculteA->id])
        )->assertSessionHasErrors('portee');

        $this->assertSame(0, SurveillanceConstraintConfig::count());
    }

    // =========================================================================
    // 4. MOTEUR BRANCHÉ : dimensionnement, jours, poids
    // =========================================================================

    public function test_ratio_personnalise_change_dimensionnement(): void
    {
        $c = $this->creerContexte();
        // 150 candidats, salle 150 : ratio 25 → 6 actifs + 1 garde.
        $epreuve = $this->creerEpreuve($c, ['code' => 'E-DIM', 'capacite_necessaire' => 150]);
        for ($i = 0; $i < 10; $i++) {
            $this->creerEnseignant("dim{$i}@univ.test");
        }

        SurveillanceConstraintConfig::create(['session_examen_id' => $this->session->id, 'ratio_etudiants' => 25]);

        $this->actingAs($this->admin)->post(route('admin.evaluations.affectations.auto'), [
            'session_id' => $this->session->id,
        ])->assertRedirect();

        $gardes = Surveillance::where('epreuve_id', $epreuve->id)->whereIn('statut', ['assigne', 'confirme'])->get();
        $this->assertGreaterThanOrEqual(7, $gardes->count());

        $run = SurveillanceAssignmentRun::latest('id')->firstOrFail();
        $this->assertSame(25, $run->config_snapshot['ratio_etudiants']);
    }

    public function test_max_jours_strict_exclut(): void
    {
        $c = $this->creerContexte();
        // P1 déjà mobilisé sur 2 jours distincts ; 3e jour en strict max 2 = exclu.
        $e1 = $this->creerEpreuve($c, ['code' => 'E-J1', 'date' => '2027-01-11']);
        $e2 = $this->creerEpreuve($c, ['code' => 'E-J2', 'date' => '2027-01-12']);
        $e3 = $this->creerEpreuve($c, ['code' => 'E-J3', 'date' => '2027-01-13']);
        $p1 = $this->creerEnseignant('strict1@univ.test');
        for ($i = 0; $i < 8; $i++) {
            $this->creerEnseignant("strictx{$i}@univ.test");
        }
        foreach ([$e1, $e2] as $e) {
            Surveillance::create(['epreuve_id' => $e->id, 'teacher_id' => $p1->id, 'role' => 'adjoint', 'statut' => 'assigne']);
        }

        $service = app(SurveillanceEquilibrageService::class);
        $dto = ContraintesSurveillance::depuisParams(['max_jours' => 2, 'mode_limite' => 'strict']);
        $sim = $service->simulerSession($this->session, ['faculte_id' => null, 'contraintes' => $dto]);

        $propJ3 = collect($sim['propositions_epreuves'])->firstWhere('epreuve_id', $e3->id);
        $idsJ3 = collect($propJ3['surveillants'])->pluck('teacher_id')->all();
        $this->assertNotContains($p1->id, $idsJ3);
    }

    public function test_objectif_desactive_ne_bloque_pas(): void
    {
        $c = $this->creerContexte();
        $this->creerEpreuve($c, ['code' => 'E-OFF']);
        for ($i = 0; $i < 6; $i++) {
            $this->creerEnseignant("off{$i}@univ.test");
        }

        $dto = ContraintesSurveillance::depuisParams([
            'actif_charge_totale' => false,
            'actif_charge_jour' => false,
            'actif_gardes' => false,
            'actif_limite_jours' => false,
            'actif_dispersion' => false,
            'actif_nouveau_jour' => false,
        ]);
        $sim = app(SurveillanceEquilibrageService::class)
            ->simulerSession($this->session, ['faculte_id' => null, 'contraintes' => $dto]);

        $this->assertContains($sim['statut'], ['OPTIMALE', 'REALISABLE']);
    }

    public function test_min_impossible_diagnostique(): void
    {
        $c = $this->creerContexte();
        $this->creerEpreuve($c, ['code' => 'E-IMP']);
        for ($i = 0; $i < 3; $i++) {
            $this->creerEnseignant("imp{$i}@univ.test");
        }

        $dto = ContraintesSurveillance::depuisParams(['min_surveillants' => 20]);
        $sim = app(SurveillanceEquilibrageService::class)
            ->simulerSession($this->session, ['faculte_id' => null, 'contraintes' => $dto]);

        $this->assertSame('IMPOSSIBLE', $sim['statut']);
        $this->assertNotEmpty($sim['anomalies']);
    }

    public function test_determinisme_meme_snapshot(): void
    {
        $c = $this->creerContexte();
        $this->creerEpreuve($c, ['code' => 'E-D1', 'date' => '2027-01-11']);
        $this->creerEpreuve($c, ['code' => 'E-D2', 'date' => '2027-01-12']);
        for ($i = 0; $i < 8; $i++) {
            $this->creerEnseignant("det{$i}@univ.test");
        }

        $dto = ContraintesSurveillance::depuisParams(['ratio_etudiants' => 30, 'max_jours' => 4]);
        $service = app(SurveillanceEquilibrageService::class);
        $cles = fn (array $sim) => collect($sim['propositions_epreuves'])
            ->map(fn ($p) => $p['epreuve_id'].':'.collect($p['surveillants'])->pluck('teacher_id')->sort()->implode(','))
            ->sort()->values()->all();

        $this->assertSame(
            $cles($service->simulerSession($this->session, ['faculte_id' => null, 'contraintes' => $dto])),
            $cles($service->simulerSession($this->session, ['faculte_id' => null, 'contraintes' => $dto]))
        );
    }

    // =========================================================================
    // 5. SÉCURITÉ
    // =========================================================================

    public function test_isolation_faculte_configurations(): void
    {
        $adminFacA = User::creerCompteBackOffice([
            'name' => 'Admin A', 'email' => 'a.ctr@univ.test', 'password' => 'Pass@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $configB = SurveillanceConstraintConfig::create(array_merge(
            $this->baseParams(), ['faculte_id' => $this->faculteB->id, 'annee_universitaire_id' => $this->annee->id]
        ));

        // Listing : B invisible pour A.
        $liste = $this->actingAs($adminFacA)->get(route('admin.evaluations.surveillances.contraintes.index'));
        $liste->assertOk();
        $liste->assertDontSee('Faculté de Droit');

        // Écriture directe : 404.
        $this->actingAs($adminFacA)->put(
            route('admin.evaluations.surveillances.contraintes.update', $configB),
            $this->baseParams()
        )->assertNotFound();

        // Création hors périmètre : 403.
        $this->actingAs($adminFacA)->post(
            route('admin.evaluations.surveillances.contraintes.store'),
            array_merge($this->baseParams(), [
                'faculte_id' => $this->faculteB->id, 'annee_universitaire_id' => $this->annee->id,
            ])
        )->assertForbidden();
    }

    public function test_resolver_une_seule_requete(): void
    {
        SurveillanceConstraintConfig::create(['ratio_etudiants' => 40]);

        DB::enableQueryLog();
        $dto = app(SurveillanceConstraintResolver::class)
            ->resoudre($this->faculteA->id, $this->annee->id, $this->session->id);
        $requetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $requetes);
        $this->assertSame(40, $dto->ratioEtudiants);
        $this->assertSame(1, $dto->configVersion);
    }

    public function test_simulation_sans_ecriture(): void
    {
        $c = $this->creerContexte();
        $this->creerEpreuve($c, ['code' => 'E-SIM']);
        for ($i = 0; $i < 6; $i++) {
            $this->creerEnseignant("sim{$i}@univ.test");
        }

        $reponse = $this->actingAs($this->admin)->post(
            route('admin.evaluations.surveillances.contraintes.simuler'),
            array_merge($this->baseParams(), ['session_id' => $this->session->id])
        );

        $reponse->assertOk();
        $reponse->assertSee('Simulation');
        $this->assertSame(0, Surveillance::whereIn('statut', ['assigne', 'confirme'])->count());
        $this->assertSame(0, SurveillanceAssignmentRun::count());
    }
}
