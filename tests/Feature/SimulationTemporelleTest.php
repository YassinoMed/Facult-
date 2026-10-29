<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Modules\SimulationTemporelle\Models\ExecutionTemporelle;
use App\Modules\SimulationTemporelle\Models\ScenarioTemporel;
use App\Modules\SimulationTemporelle\Models\SnapshotExecution;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Simulateur temporel — suite Feature (ST-05/06) : définition validée,
 * exécution reproductible et idempotente, taux SAISIS (absents =
 * avertissement, jamais zéro silencieux), contraintes dures, plafonds,
 * isolation périmètre, NON-ÉCRITURE des tables métier, snapshots sans
 * PII, performance. Données 100 % synthétiques.
 */
class SimulationTemporelleTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private User $admin;

    private User $adminB;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('modules.simulation-temporelle.active', true);

        $this->faculteA = Faculte::create(['nom' => 'Sciences', 'code' => 'FST', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'Droit', 'code' => 'FDEG', 'active' => true]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Sco', 'email' => 'st-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->adminB = User::creerCompteBackOffice([
            'name' => 'ScoB', 'email' => 'stb-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');

        // Effectifs synthétiques : 2 programmes, niveaux L1/L2/L3.
        foreach ([$this->faculteA, $this->faculteB] as $faculte) {
            $programme = Programme::create([
                'faculte_id' => $faculte->id, 'code' => 'P'.$faculte->code, 'nom' => 'Prog',
                'statut' => 'actif', 'actif' => true, 'duree_annees' => 3,
            ]);
            foreach (['L1' => 1, 'L2' => 2, 'L3' => 3] as $code => $ordre) {
                $niveau = Niveau::create([
                    'programme_id' => $programme->id, 'code' => $code, 'nom' => $code,
                    'ordre' => $ordre, 'actif' => true,
                ]);
                for ($i = 0; $i < 10 * $ordre; $i++) {
                    $etudiant = Etudiant::create([
                        'cin' => 'CIN-'.uniqid(), 'nom' => 'N'.uniqid(), 'prenom' => 'P',
                        'email' => 'e-'.uniqid().'@u.test', 'sexe' => 'M',
                        'faculte_id' => $faculte->id, 'niveau' => $code, 'annee_academique' => '2026-2027',
                        'adresse' => 'Rue', 'ville' => 'Dakar', 'code_postal' => '10000', 'annee_bac' => '2024',
                    ]);
                    InscriptionAcademique::create([
                        'etudiant_id' => $etudiant->id,
                        'programme_id' => $programme->id,
                        'niveau' => $code,
                        'niveau_id' => $niveau->id,
                        'annee_universitaire_id' => $this->annee->id,
                        'statut' => 'valide',
                    ]);
                }
            }
        }
    }

    private function appelPost(User $u, string $route, array $params, array $data): TestResponse
    {
        return $this->actingAs($u)->postJson(route($route, $params), $data);
    }

    private function appelGet(User $u, string $route, array $params = []): TestResponse
    {
        return $this->actingAs($u)->getJson(route($route, $params));
    }

    private function criteres(): array
    {
        return [
            'delta_admissions' => 0.1,
            'bornes' => ['min' => -0.5, 'max' => 2.0],
            'horizon_annees' => 3,
            'taux' => ['passage' => 0.7, 'redoublement' => 0.2, 'abandon' => 0.05],
        ];
    }

    public function test_scenario_cree_et_code_duplique_refuse(): void
    {
        $reponse = $this->appelPost($this->admin, 'admin.simulation-temporelle.scenarios.creer', [], [
            'code' => 'SIM-1', 'libelle' => 'Croissance modérée',
            'criteres' => $this->criteres(),
            'contraintes' => [['type' => 'plafond_total', 'valeur' => 5000]],
            'objectifs' => [['type' => 'cible_total', 'cible' => 1500, 'poids' => 1.0]],
        ])->assertStatus(201);

        $this->assertSame(1, $reponse->json('scenario.version'));

        $this->appelPost($this->admin, 'admin.simulation-temporelle.scenarios.creer', [], [
            'code' => 'SIM-1', 'libelle' => 'Doublon',
            'criteres' => $this->criteres(), 'contraintes' => [], 'objectifs' => [],
        ])->assertStatus(422);
    }

    public function test_criteres_invalides_refuses(): void
    {
        foreach ([
            ['delta_admissions' => 3.0, 'bornes' => ['min' => -0.5, 'max' => 2.0], 'horizon_annees' => 3],
            ['delta_admissions' => 0.1, 'bornes' => ['min' => -0.5, 'max' => 2.0], 'horizon_annees' => 9],
            ['delta_admissions' => 0.1, 'bornes' => ['min' => -0.5, 'max' => 2.0], 'horizon_annees' => 3,
                'taux' => ['passage' => 0.9, 'redoublement' => 0.2, 'abandon' => 0.1]],
        ] as $criteres) {
            $this->appelPost($this->admin, 'admin.simulation-temporelle.scenarios.creer', [], [
                'code' => 'SIM-INV-'.uniqid(), 'libelle' => 'Invalide', 'criteres' => $criteres,
                'contraintes' => [], 'objectifs' => [['type' => 'cible_total', 'cible' => 100, 'poids' => 1]],
            ])->assertStatus(422);
        }
        $this->assertSame(0, ScenarioTemporel::count());
    }

    public function test_execution_reproductible_et_idempotente(): void
    {
        $scenario = $this->creerScenario();

        $e1 = $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => $this->annee->id, 'graine' => 'g1', 'nb_variantes' => 5,
        ])->assertOk()->json('execution');

        $e2 = $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => $this->annee->id, 'graine' => 'g1', 'nb_variantes' => 5,
        ])->assertOk()->json('execution');

        $this->assertSame($e1['id'], $e2['id']); // idempotence : 1 seule exécution
        $this->assertSame($e1['hash_resultat'], $e2['hash_resultat']);
        $this->assertSame(1, ExecutionTemporelle::count());
        $this->assertNotEmpty($e1['resultat']['classement']);
        $this->assertStringContainsString('AUCUNE décision réelle', $e1['resultat']['rappel']);
    }

    public function test_graines_differentes_resultats_differents(): void
    {
        $scenario = $this->creerScenario();

        $a = $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => $this->annee->id, 'graine' => 'aaa', 'nb_variantes' => 5,
        ])->assertOk()->json('execution.hash_resultat');

        $b = $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => $this->annee->id, 'graine' => 'zzz', 'nb_variantes' => 5,
        ])->assertOk()->json('execution.hash_resultat');

        $this->assertNotSame($a, $b);
        $this->assertSame(2, ExecutionTemporelle::count());
    }

    public function test_contrainte_duré_isole_les_non_conformes(): void
    {
        // Plancher impossible à atteindre : TOUTES les variantes non conformes.
        $scenario = $this->creerScenario([['type' => 'plancher_total', 'valeur' => 999999]]);

        $execution = $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => $this->annee->id, 'graine' => 'g', 'nb_variantes' => 3,
        ])->assertOk()->json('execution');

        $this->assertSame([], $execution['resultat']['classement']);
        $this->assertCount(3, $execution['resultat']['non_conformes']);
        $this->assertStringContainsString('plancher', $execution['resultat']['non_conformes'][0]['violations'][0]);
    }

    public function test_taux_absents_avertissement_jamais_zero_silencieux(): void
    {
        $criteres = $this->criteres();
        unset($criteres['taux']);
        $scenario = $this->creerScenario([], $criteres);

        $execution = $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => $this->annee->id, 'graine' => 'g', 'nb_variantes' => 2,
        ])->assertOk()->json('execution');

        $this->assertStringContainsString('gelées', $execution['resultat']['avertissements'][0]);
    }

    public function test_plafonds_appliques(): void
    {
        $scenario = $this->creerScenario();

        $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => $this->annee->id, 'graine' => 'g', 'nb_variantes' => 50,
        ])->assertStatus(422);

        $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => 999999, 'graine' => 'g', 'nb_variantes' => 5,
        ])->assertStatus(422);
        $this->assertSame(0, ExecutionTemporelle::count());
    }

    public function test_isolation_perimetre_et_module(): void
    {
        $scenario = $this->creerScenario(); // faculté A

        $adminFacB = User::creerCompteBackOffice([
            'name' => 'AFB', 'email' => 'afb-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
            'faculte_id' => Faculte::where('code', 'FDEG')->first()->id,
        ], 'admin_faculte');

        $this->appelPost($adminFacB, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => $this->annee->id, 'graine' => 'g', 'nb_variantes' => 2,
        ])->assertStatus(403);

        $sansBackOffice = User::create([
            'name' => 'Etu', 'email' => 'etu-'.uniqid().'@u.test', 'password' => 'Motdepasse@123', 'role' => 'etudiant',
        ]);
        $this->appelGet($sansBackOffice, 'admin.simulation-temporelle.scenarios.index')->assertStatus(403);

        Config::set('modules.simulation-temporelle.active', false);
        $this->appelGet($this->admin, 'admin.simulation-temporelle.scenarios.index')->assertStatus(404);
    }

    public function test_aucune_ecriture_metier_et_snapshots_sans_pii(): void
    {
        $scenario = $this->creerScenario();
        $avantInscriptions = InscriptionAcademique::count();

        $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => $this->annee->id, 'graine' => 'g', 'nb_variantes' => 3,
        ])->assertOk();

        $this->assertSame($avantInscriptions, InscriptionAcademique::count()); // lecture seule

        $execution = ExecutionTemporelle::sole();
        $this->assertSame(4, SnapshotExecution::where('execution_temporelle_id', $execution->id)->count()); // pas 0..3

        $json = json_encode(SnapshotExecution::all()->pluck('etat'));
        foreach (['nom', 'cin', 'email', 'prenom'] as $champ) {
            $this->assertStringNotContainsString('"'.$champ.'"', $json); // zéro PII
        }

        $this->appelGet($this->admin, 'admin.simulation-temporelle.executions.detail', ['id' => $execution->id])
            ->assertOk()->assertJsonPath('execution.hash_resultat', $execution->hash_resultat);
    }

    public function test_performance_20_variantes_6_ans(): void
    {
        $criteres = $this->criteres();
        $criteres['horizon_annees'] = 6;
        $scenario = $this->creerScenario([], $criteres);

        $debut = microtime(true);
        $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => $this->annee->id, 'graine' => 'perf', 'nb_variantes' => 20,
        ])->assertOk();
        $duree = microtime(true) - $debut;

        $this->assertLessThan(3.0, $duree, 'Exécution trop lente : '.$duree.' s');
        $this->assertSame(20, ExecutionTemporelle::sole()->nb_variantes);
    }

    /** Groupe Schedule rattaché à un niveau (relation EXISTANTE groups.niveau_id). */
    private function groupe(string $code, int $niveauId, bool $actif = true): Group
    {
        return Group::create([
            'code' => $code, 'nom' => 'Groupe '.$code, 'faculte_id' => $this->faculteA->id,
            'niveau' => 'L1', 'actif' => $actif, 'niveau_id' => $niveauId,
        ]);
    }

    /** Niveau du programme de la faculté A (L1 par défaut). */
    private function niveauA(string $code = 'L1'): Niveau
    {
        return Niveau::where('code', $code)
            ->whereHas('programme', fn ($p) => $p->where('faculte_id', $this->faculteA->id))
            ->firstOrFail();
    }

    private function creerScenario(array $contraintes = [], ?array $criteres = null): ScenarioTemporel
    {
        return ScenarioTemporel::create([
            'code' => 'SIM-'.uniqid(), 'libelle' => 'Test',
            'faculte_id' => $this->faculteA->id,
            'criteres' => $criteres ?? $this->criteres(),
            'contraintes' => $contraintes,
            'objectifs' => [
                ['type' => 'cible_total', 'cible' => 1500, 'poids' => 1.0],
                ['type' => 'stabilite', 'poids' => 0.5],
            ],
            'version' => 1, 'cree_par' => $this->admin->id,
        ]);
    }

    /* ── HG : hiérarchie Niveau → Groupe (validation serveur + options) ── */

    public function test_groupes_du_niveau_selectionne_acceptes(): void
    {
        $niveau = $this->niveauA('L1');
        $g1 = $this->groupe('G-A', $niveau->id);
        $g2 = $this->groupe('G-B', $niveau->id);

        // Des étudiants appartiennent aux groupes (sinon état vide : donnée
        // absente correctement refusée, jamais zéro silencieux).
        Etudiant::where('niveau', 'L1')->where('faculte_id', $this->faculteA->id)
            ->take(4)->get()->each(fn ($e, $i) => $e->update(['groupe_id' => $i < 2 ? $g1->id : $g2->id]));

        $criteres = $this->criteres() + ['niveau_id' => $niveau->id, 'groupes' => [$g1->id, $g2->id]];
        $scenario = $this->creerScenario([], $criteres);
        $this->assertNotNull($scenario->id); // définition acceptée (validation service)

        $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => $this->annee->id, 'graine' => 'hg', 'nb_variantes' => 2,
        ])->assertOk(); // critères tracés dans hash_entrees (idempotence inchangée)
    }

    public function test_groupe_d_un_autre_niveau_ou_inactif_rejete(): void
    {
        $l1 = $this->niveauA('L1');
        $l2 = $this->niveauA('L2');
        $mauvais = $this->groupe('G-L2', $l2->id);          // autre niveau
        $inactif = $this->groupe('G-OFF', $l1->id, false);   // inactif
        $sansNiveau = Group::create([
            'code' => 'G-NA', 'nom' => 'Sans rattachement', 'faculte_id' => $this->faculteA->id, 'niveau' => 'L1', 'actif' => true,
        ]);

        foreach ([[$mauvais->id], [$inactif->id], [$sansNiveau->id]] as $groupes) {
            $criteres = $this->criteres() + ['niveau_id' => $l1->id, 'groupes' => $groupes];
            $this->appelPost($this->admin, 'admin.simulation-temporelle.scenarios.creer', [], [
                'code' => 'HG-'.uniqid(), 'libelle' => 'Refus', 'faculte_id' => $this->faculteA->id,
                'criteres' => $criteres, 'contraintes' => [],
                'objectifs' => [['type' => 'cible_total', 'cible' => 100, 'poids' => 1]],
            ])->assertStatus(422);
        }
        $this->assertSame(0, ScenarioTemporel::count()); // aucune fausse valeur enregistrée
    }

    public function test_groupes_sans_niveau_et_inexistants_rejetes_explicitement(): void
    {
        $g = $this->groupe('G-X', $this->niveauA('L1')->id);

        // Groupes sans niveau sélectionné : incompatible cascade.
        $this->appelPost($this->admin, 'admin.simulation-temporelle.scenarios.creer', [], [
            'code' => 'HG-SN', 'libelle' => 'Sans niveau', 'faculte_id' => $this->faculteA->id,
            'criteres' => $this->criteres() + ['groupes' => [$g->id]],
            'contraintes' => [], 'objectifs' => [['type' => 'cible_total', 'cible' => 100, 'poids' => 1]],
        ])->assertStatus(422);

        // Niveau inexistant.
        $this->appelPost($this->admin, 'admin.simulation-temporelle.scenarios.creer', [], [
            'code' => 'HG-NI', 'libelle' => 'Niveau inconnu', 'faculte_id' => $this->faculteA->id,
            'criteres' => $this->criteres() + ['niveau_id' => 999999],
            'contraintes' => [], 'objectifs' => [['type' => 'cible_total', 'cible' => 100, 'poids' => 1]],
        ])->assertStatus(422);
    }

    public function test_groupe_hors_perimetre_rejete_meme_requete_forgee(): void
    {
        $niveauB = Niveau::where('code', 'L1')
            ->whereHas('programme', fn ($p) => $p->where('faculte_id', Faculte::where('code', 'FDEG')->first()->id))
            ->firstOrFail();
        $groupeB = Group::create([
            'code' => 'G-DROIT', 'nom' => 'Droit', 'faculte_id' => $niveauB->programme->faculte_id,
            'niveau' => 'L1', 'actif' => true, 'niveau_id' => $niveauB->id,
        ]);

        // Scénario faculté A + groupe de la faculté B : requête forgée → 422 serveur.
        $criteres = $this->criteres() + ['niveau_id' => $niveauB->id, 'groupes' => [$groupeB->id]];
        $this->appelPost($this->admin, 'admin.simulation-temporelle.scenarios.creer', [], [
            'code' => 'HG-IDOR', 'libelle' => 'Forgé', 'faculte_id' => $this->faculteA->id,
            'criteres' => $criteres, 'contraintes' => [],
            'objectifs' => [['type' => 'cible_total', 'cible' => 100, 'poids' => 1]],
        ])->assertStatus(422);

        // Options : admin_faculte B ne voit que SES groupes via l'endpoint.
        $adminFacB = User::creerCompteBackOffice([
            'name' => 'AFB', 'email' => 'hgb-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $niveauB->programme->faculte_id,
        ], 'admin_faculte');

        $this->appelGet($this->admin, 'admin.simulation-temporelle.options.groupes', ['niveauId' => $niveauB->id])
            ->assertOk()->assertJsonPath('groupes.0.code', 'G-DROIT');

        // Niveau de A vu par admin B : hors périmètre → 403 sur ses groupes.
        $this->appelGet($adminFacB, 'admin.simulation-temporelle.options.groupes', ['niveauId' => $this->niveauA('L1')->id])
            ->assertStatus(403);
    }

    public function test_options_cascade_niveaux_puis_groupes_du_niveau(): void
    {
        $l1 = $this->niveauA('L1');
        $this->groupe('G-OK1', $l1->id);
        $this->groupe('G-OK2', $l1->id);
        $this->groupe('G-OFF3', $l1->id, false);           // inactif : exclu
        $this->groupe('G-L2', $this->niveauA('L2')->id);   // autre niveau : exclu

        $niveaux = $this->appelGet($this->admin, 'admin.simulation-temporelle.options.niveaux')
            ->assertOk()->json('niveaux');
        $this->assertContains('L1', array_column($niveaux, 'code'));

        $reponse = $this->appelGet($this->admin, 'admin.simulation-temporelle.options.groupes', ['niveauId' => $l1->id])
            ->assertOk();
        $codes = array_column($reponse->json('groupes'), 'code');
        $this->assertEqualsCanonicalizing(['G-OK1', 'G-OK2'], $codes); // seuls les actifs du niveau
        $this->assertStringContainsString('sans rattachement LMD', (string) $reponse->json('note')); // exclusion documentée

        $this->appelGet($this->admin, 'admin.simulation-temporelle.options.groupes', ['niveauId' => 999999])
            ->assertStatus(404); // niveau absent : explicite, jamais de fausse liste
    }

    public function test_correction_cree_par_idempotent(): void
    {
        $scenario = $this->creerScenario();
        $payload = ['annee_universitaire_id' => $this->annee->id, 'graine' => 'cc', 'nb_variantes' => 2];

        // 1. Utilisateur A crée une exécution
        $rep1 = $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], $payload)->assertOk();
        $this->assertSame('nouvelle', $rep1->json('idempotente'));

        $premier = ExecutionTemporelle::sole();
        $auteurInitial = $premier->cree_par;
        $this->assertNotNull($auteurInitial);
        $this->assertSame($this->admin->id, $auteurInitial);
        $nbSnapshots = SnapshotExecution::count();

        // 2. Utilisateur B soumet la même demande idempotente
        $autre = User::creerCompteBackOffice([
            'name' => 'Sco2', 'email' => 's2-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $rep2 = $this->appelPost($autre, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], $payload)->assertOk();
        $this->assertStringContainsString('existante', (string) $rep2->json('idempotente'));

        // 3. L'exécution existante est retournée (même ID)
        $this->assertSame($premier->id, $rep2->json('execution.id'));
        $this->assertSame(1, ExecutionTemporelle::count());

        // 4. L'auteur initial, le hash du résultat et les snapshots restent inchangés
        $rafraichi = ExecutionTemporelle::sole()->refresh();
        $this->assertSame($auteurInitial, $rafraichi->cree_par);
        $this->assertNotSame($autre->id, $rafraichi->cree_par);
        $this->assertSame($premier->hash_resultat, $rafraichi->hash_resultat);
        $this->assertSame($nbSnapshots, SnapshotExecution::count());
    }

    public function test_ecran_ui_accessible_avec_banniere_simulation_uniquement(): void
    {
        $reponse = $this->appelGet($this->admin, 'admin.simulation-temporelle.index')->assertOk();
        $html = $reponse->getContent();
        $this->assertStringContainsString('Simulation uniquement', $html);
        $this->assertStringContainsString('aucune décision réelle', $html);
        $this->assertStringContainsString('options/niveaux', $html); // cascade depuis la base, pas codée en dur
        $this->assertStringContainsString('changerNiveau', $html);   // réinitialisation cascade

        $sansBackOffice = User::create([
            'name' => 'E', 'email' => 'ui-'.uniqid().'@u.test', 'password' => 'Motdepasse@123', 'role' => 'etudiant',
        ]);
        $this->appelGet($sansBackOffice, 'admin.simulation-temporelle.index')->assertStatus(403);
    }

    public function test_groupes_selectionnes_determinent_les_donnees(): void
    {
        $niveau = $this->niveauA('L1');
        $g1 = $this->groupe('G-DATA', $niveau->id);
        Etudiant::where('niveau', 'L1')
            ->where('faculte_id', $this->faculteA->id)
            ->take(5)->update(['groupe_id' => $g1->id]);

        $criteres = $this->criteres() + ['niveau_id' => $niveau->id, 'groupes' => [$g1->id]];
        $scenario = $this->creerScenario([], $criteres);
        $execution = $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => $this->annee->id, 'graine' => 'g', 'nb_variantes' => 2,
        ])->assertOk()->json('execution');

        // Lot C : l'état initial reflète UNIQUEMENT les inscriptions des groupes sélectionnés (5 étudiants).
        $this->assertSame(5, $execution['resultat']['etat_initial']['total_initial']);
    }

    public function test_entree_navigation_sidebar_filtree_par_module_actif(): void
    {
        $registre = app(ModuleRegistry::class);

        Config::set('modules.simulation-temporelle.active', true);
        $labels = array_column($registre->elementsNavigation('admin'), 'label');
        $this->assertContains('Simulateur temporel', $labels);

        Config::set('modules.simulation-temporelle.active', false);
        $labels = array_column($registre->elementsNavigation('admin'), 'label');
        $this->assertNotContains('Simulateur temporel', $labels); // module off → invisible
    }

    public function test_options_niveaux_filtrees_par_faculte_et_interdit_hors_perimetre(): void
    {
        $faculteB = Faculte::where('code', 'FDEG')->firstOrFail();
        $adminFacA = User::creerCompteBackOffice([
            'name' => 'AFA', 'email' => 'afa-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        // Admin faculté A demande sa faculté -> 200
        $this->appelGet($adminFacA, 'admin.simulation-temporelle.options.niveaux', ['faculte_id' => $this->faculteA->id])
            ->assertOk();

        // Admin faculté A demande la faculté B -> 403 hors périmètre
        $this->appelGet($adminFacA, 'admin.simulation-temporelle.options.niveaux', ['faculte_id' => $faculteB->id])
            ->assertStatus(403);

        // Superadmin peut filtrer sur la faculté B
        $repSuper = $this->appelGet($this->admin, 'admin.simulation-temporelle.options.niveaux', ['faculte_id' => $faculteB->id])
            ->assertOk();
        $this->assertNotEmpty($repSuper->json('niveaux'));
    }

    public function test_simulation_persiste_et_retourne_totaux_trajectoire_par_variante(): void
    {
        $scenario = $this->creerScenario();
        $execution = $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => $this->annee->id, 'graine' => 'traj', 'nb_variantes' => 3,
        ])->assertOk()->json('execution');

        $this->assertNotEmpty($execution['resultat']['classement']);
        $premier = $execution['resultat']['classement'][0];

        // Lot D : chaque variante du classement fournit les totaux projetés pas à pas
        $this->assertArrayHasKey('totaux', $premier);
        $this->assertCount(4, $premier['totaux']); // pas 0..3 pour horizon = 3
        $this->assertSame($premier['total_final'], end($premier['totaux']));
    }

    public function test_source_annee_universitaire_inexistante_rejetee(): void
    {
        $scenario = $this->creerScenario();
        $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => 999999, 'graine' => 'g', 'nb_variantes' => 3,
        ])->assertStatus(422)->assertJsonValidationErrors(['annee_universitaire_id']);
    }

    public function test_graine_manquante_ou_invalide_rejetee(): void
    {
        $scenario = $this->creerScenario();
        $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => $this->annee->id, 'graine' => '', 'nb_variantes' => 3,
        ])->assertStatus(422)->assertJsonValidationErrors(['graine']);
    }

    public function test_contraintes_incompatibles_plancher_superieur_plafond_rejetee(): void
    {
        $this->appelPost($this->admin, 'admin.simulation-temporelle.scenarios.creer', [], [
            'code' => 'SIM-INCOMPATIBLE', 'libelle' => 'Incompatible',
            'criteres' => $this->criteres(),
            'contraintes' => [
                ['type' => 'plafond_total', 'valeur' => 2000],
                ['type' => 'plancher_total', 'valeur' => 3000],
            ],
            'objectifs' => [['type' => 'cible_total', 'cible' => 2500, 'poids' => 1.0]],
        ])->assertStatus(422)->assertJsonPath('erreur', 'Contraintes incompatibles : le plancher (3000) est supérieur au plafond (2000).');
    }

    public function test_meme_scenario_avec_annees_sources_differentes_produit_executions_distinctes(): void
    {
        $annee2 = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-06-30', 'active' => false,
        ]);
        // Dupliquer des inscriptions synthétiques pour la deuxième année
        $prog = Programme::where('faculte_id', $this->faculteA->id)->firstOrFail();
        $niv = Niveau::where('programme_id', $prog->id)->firstOrFail();
        $etudiant = Etudiant::firstOrFail();
        InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id, 'programme_id' => $prog->id, 'niveau' => $niv->code,
            'niveau_id' => $niv->id, 'annee_universitaire_id' => $annee2->id, 'statut' => 'valide',
        ]);

        $scenario = $this->creerScenario();

        $exec1 = $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => $this->annee->id, 'graine' => 'meme-graine', 'nb_variantes' => 2,
        ])->assertOk()->json('execution');

        $exec2 = $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => $annee2->id, 'graine' => 'meme-graine', 'nb_variantes' => 2,
        ])->assertOk()->json('execution');

        $this->assertNotEquals($exec1['id'], $exec2['id']);
        $this->assertNotEquals($exec1['hash_entrees'], $exec2['hash_entrees']);
    }

    public function test_reprise_apres_erreur_formulaire(): void
    {
        // 1. Soumission rejetée (code manquant)
        $this->appelPost($this->admin, 'admin.simulation-temporelle.scenarios.creer', [], [
            'code' => '', 'libelle' => 'Sans code', 'criteres' => $this->criteres(),
            'contraintes' => [], 'objectifs' => [['type' => 'cible_total', 'cible' => 1000, 'poids' => 1]],
        ])->assertStatus(422);

        // 2. Correction et succès
        $reponse = $this->appelPost($this->admin, 'admin.simulation-temporelle.scenarios.creer', [], [
            'code' => 'SIM-REPRISE-OK', 'libelle' => 'Avec code', 'criteres' => $this->criteres(),
            'contraintes' => [], 'objectifs' => [['type' => 'cible_total', 'cible' => 1000, 'poids' => 1]],
        ])->assertStatus(201);

        $this->assertSame('SIM-REPRISE-OK', $reponse->json('scenario.code'));
    }

    public function test_groupe_desactive_apres_affichage_rejete_a_la_creation(): void
    {
        $prog = Programme::where('faculte_id', $this->faculteA->id)->firstOrFail();
        $niv = Niveau::where('programme_id', $prog->id)->firstOrFail();
        $groupe = Group::create([
            'faculte_id' => $this->faculteA->id, 'programme_id' => $prog->id, 'niveau_id' => $niv->id,
            'niveau' => $niv->code, 'code' => 'G-INACTIF-TEST', 'nom' => 'Inactif', 'capacite' => 25, 'actif' => false,
        ]);

        $criteres = $this->criteres();
        $criteres['niveau_id'] = $niv->id;
        $criteres['groupes'] = [$groupe->id];

        $this->appelPost($this->admin, 'admin.simulation-temporelle.scenarios.creer', [], [
            'code' => 'SIM-GRP-INACTIF', 'libelle' => 'Test inactif',
            'faculte_id' => $this->faculteA->id,
            'criteres' => $criteres,
            'contraintes' => [],
            'objectifs' => [['type' => 'cible_total', 'cible' => 1000, 'poids' => 1.0]],
        ])->assertStatus(422)->assertJsonPath('erreur', 'Groupe G-INACTIF-TEST inactif : refusé.');
    }

    public function test_preuve_absolue_absence_ecriture_tables_metier(): void
    {
        // Snapshot initial de référence de toutes les tables métier
        $inscriptionsAvant = InscriptionAcademique::query()->orderBy('id')->get()->toArray();
        $etudiantsAvant = Etudiant::query()->orderBy('id')->get()->toArray();
        $programmesAvant = Programme::query()->orderBy('id')->get()->toArray();
        $niveauxAvant = Niveau::query()->orderBy('id')->get()->toArray();

        $scenario = $this->creerScenario();

        // Exécution de 10 variantes sur horizon 3 ans
        $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => $this->annee->id, 'graine' => 'preuve-metier-1', 'nb_variantes' => 10,
        ])->assertOk();

        // Ré-exécution idempotente
        $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => $this->annee->id, 'graine' => 'preuve-metier-1', 'nb_variantes' => 10,
        ])->assertOk();

        // Vérification stricte : aucune ligne modifiée, insérée ou supprimée dans les tables métier
        $this->assertSame($inscriptionsAvant, InscriptionAcademique::query()->orderBy('id')->get()->toArray());
        $this->assertSame($etudiantsAvant, Etudiant::query()->orderBy('id')->get()->toArray());
        $this->assertSame($programmesAvant, Programme::query()->orderBy('id')->get()->toArray());
        $this->assertSame($niveauxAvant, Niveau::query()->orderBy('id')->get()->toArray());

        // Seules les tables de simulation ont été écrites
        $this->assertSame(1, ExecutionTemporelle::where('scenario_temporel_id', $scenario->id)->count());
        $this->assertGreaterThan(0, SnapshotExecution::count());
    }

    public function test_st10_lot1_audit_qualite_donnees(): void
    {
        // 1. Audit sur données valides existantes
        $reponse = $this->appelGet($this->admin, 'admin.simulation-temporelle.audit-qualite', [
            'faculte_id' => $this->faculteA->id,
            'annee_id' => $this->annee->id,
        ]);
        $reponse->assertOk();
        $reponse->assertJsonPath('audit.conforme', true);
        $reponse->assertJsonStructure(['audit' => ['score_qualite', 'anomalies', 'statistiques']]);

        // 2. Audit sur faculté inexistante
        $reponseInvalide = $this->appelGet($this->admin, 'admin.simulation-temporelle.audit-qualite', [
            'faculte_id' => 99999,
            'annee_id' => $this->annee->id,
        ]);
        $reponseInvalide->assertOk();
        $this->assertFalse($reponseInvalide->json('audit.conforme'));
        $this->assertSame('QUAL_FACULTE_INEXISTANTE', $reponseInvalide->json('audit.anomalies.0.code'));
    }

    public function test_st10_lot2_comparaison_projections_et_ecarts(): void
    {
        $scenario = $this->creerScenario();
        $reponse = $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => $this->annee->id, 'graine' => 'comparaison-1', 'nb_variantes' => 3,
        ]);
        $reponse->assertOk();

        $comparaison = $reponse->json('execution.resultat.comparaison');
        $this->assertNotNull($comparaison);
        $this->assertNotEmpty($comparaison['lignes']);

        $annee0 = $comparaison['lignes'][0];
        $this->assertTrue($annee0['est_annee_source']);
        $this->assertEquals(0, $annee0['ecart_absolu']);
        $this->assertEquals(0.0, $annee0['ecart_pourcentage']);
    }

    public function test_st10_lot3_explication_scores_et_contraintes(): void
    {
        $scenario = $this->creerScenario();
        $reponse = $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => $this->annee->id, 'graine' => 'explication-1', 'nb_variantes' => 3,
        ]);
        $reponse->assertOk();

        $explications = $reponse->json('execution.resultat.explications_variantes');
        $this->assertNotNull($explications);
        $this->assertArrayHasKey(0, $explications);

        $var0 = $explications[0];
        $this->assertArrayHasKey('est_conforme', $var0);
        $this->assertArrayHasKey('contraintes', $var0);
        $this->assertArrayHasKey('objectifs', $var0);
        $this->assertArrayHasKey('regle_departage', $var0);
    }

    public function test_st10_lot4_duplication_et_versionnement_scenario(): void
    {
        $scenario = $this->creerScenario();

        // 1. Duplication
        $reponseDup = $this->appelPost($this->admin, 'admin.simulation-temporelle.scenarios.dupliquer', ['id' => $scenario->id], [
            'nouveau_code' => 'SC-CLONE-UNIQUE-1',
        ]);
        $reponseDup->assertStatus(201);
        $this->assertSame('SC-CLONE-UNIQUE-1', $reponseDup->json('scenario.code'));
        $this->assertSame(1, $reponseDup->json('scenario.version'));

        // 2. Nouvelle version incrémentée
        $reponseVer = $this->appelPost($this->admin, 'admin.simulation-temporelle.scenarios.nouvelle-version', ['id' => $scenario->id], [
            'code' => 'SC-VER-2',
            'criteres' => $scenario->criteres,
            'contraintes' => [['type' => 'plafond_total', 'valeur' => 2000]],
            'objectifs' => $scenario->objectifs,
        ]);
        $reponseVer->assertStatus(201);
        $this->assertSame(2, $reponseVer->json('scenario.version'));
        $this->assertSame('SC-VER-2', $reponseVer->json('scenario.code'));
    }

    public function test_st10_lot5_capacite_accueil_et_groupes(): void
    {
        $scenario = $this->creerScenario();
        $reponse = $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => $this->annee->id, 'graine' => 'capacites-1', 'nb_variantes' => 2,
        ]);
        $reponse->assertOk();

        $capacites = $reponse->json('execution.resultat.capacites');
        $this->assertNotNull($capacites);
        $this->assertGreaterThan(0, $capacites['groupes_theoriques_requis_total']);
        $this->assertArrayHasKey('taux_saturation_global_pct', $capacites);
        $this->assertArrayHasKey('detail_par_niveau', $capacites);
    }

    public function test_st10_lot7_analyse_sensibilite(): void
    {
        $scenario = $this->creerScenario();
        $reponse = $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => $this->annee->id, 'graine' => 'sensibilite-1', 'nb_variantes' => 2,
        ]);
        $reponse->assertOk();

        $sensibilite = $reponse->json('execution.resultat.sensibilite');
        $this->assertNotNull($sensibilite);
        $this->assertNotEmpty($sensibilite['parametres_classes']);
        $this->assertArrayHasKey('synthese', $sensibilite);
    }

    public function test_st10_lot8_export_csv_et_pdf_avec_protection(): void
    {
        $scenario = $this->creerScenario();
        $exec = $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => $this->annee->id, 'graine' => 'export-1', 'nb_variantes' => 2,
        ])->json('execution');

        // 1. Export CSV
        $csvReponse = $this->appelGet($this->admin, 'admin.simulation-temporelle.executions.export.csv', ['id' => $exec['id']]);
        $csvReponse->assertOk();
        $csvReponse->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        // 2. Export PDF
        $pdfReponse = $this->appelGet($this->admin, 'admin.simulation-temporelle.executions.export.pdf', ['id' => $exec['id']]);
        $pdfReponse->assertOk();
        $pdfReponse->assertHeader('Content-Type', 'application/pdf');

        // 3. Contrôle d'accès hors périmètre (admin B ne peut exporter la faculté A)
        $this->appelGet($this->adminB, 'admin.simulation-temporelle.executions.export.csv', ['id' => $exec['id']])
            ->assertStatus(403);
    }

    public function test_st10_lot9_frontiere_pareto(): void
    {
        $scenario = $this->creerScenario();
        $reponse = $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => $this->annee->id, 'graine' => 'pareto-1', 'nb_variantes' => 5,
        ]);
        $reponse->assertOk();

        $pareto = $reponse->json('execution.resultat.pareto');
        $this->assertNotNull($pareto);
        $this->assertArrayHasKey('frontiere_pareto', $pareto);
        $this->assertArrayHasKey('solutions_dominees', $pareto);
    }

    public function test_st10_lot10_simulation_monte_carlo(): void
    {
        $scenario = $this->creerScenario();
        $reponse = $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => $this->annee->id, 'graine' => 'montecarlo-1', 'nb_variantes' => 2,
        ]);
        $reponse->assertOk();

        $mc = $reponse->json('execution.resultat.monte_carlo');
        $this->assertNotNull($mc);
        $this->assertSame(50, $mc['nb_iterations']);
        $this->assertGreaterThan(0, $mc['mediane']);
        $this->assertGreaterThanOrEqual($mc['min'], $mc['mediane']);
        $this->assertLessThanOrEqual($mc['max'], $mc['mediane']);
    }

    public function test_st10_lot11_projection_budgetaire(): void
    {
        $scenario = $this->creerScenario();
        $reponse = $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => $this->annee->id, 'graine' => 'budget-1', 'nb_variantes' => 2,
        ]);
        $reponse->assertOk();

        $budget = $reponse->json('execution.resultat.budget');
        $this->assertNotNull($budget);
        $this->assertGreaterThan(0, $budget['cout_total_projete']);
        $this->assertArrayHasKey('solde_projete', $budget);
        $this->assertStringContainsString('aucun impact réel', $budget['avertissement']);
    }

    public function test_st10_lot12_assistant_ia_explicatif(): void
    {
        $scenario = $this->creerScenario();
        $exec = $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => $this->annee->id, 'graine' => 'ia-1', 'nb_variantes' => 2,
        ])->json('execution');

        // Question à l'assistant IA
        $reponse = $this->appelPost($this->admin, 'admin.simulation-temporelle.executions.expliquer-ia', ['id' => $exec['id']], [
            'question' => 'Pourquoi ce scénario est-il conforme et quelles sont ses hypothèses ?',
        ]);
        $reponse->assertOk();
        $this->assertNotEmpty($reponse->json('reponse'));
        $this->assertNotEmpty($reponse->json('empreinte_preuve'));

        // Test refus hors faculté
        $this->appelPost($this->adminB, 'admin.simulation-temporelle.executions.expliquer-ia', ['id' => $exec['id']], [
            'question' => 'Accès pirate',
        ])->assertStatus(403);
    }

    public function test_st10_lot13_alertes_seuils(): void
    {
        $scenario = $this->creerScenario();
        $reponse = $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => $this->annee->id, 'graine' => 'alertes-1', 'nb_variantes' => 2,
        ]);
        $reponse->assertOk();

        $alertes = $reponse->json('execution.resultat.alertes_seuils');
        $this->assertIsArray($alertes);
    }

    public function test_st10_lot14_gouvernance_quatre_yeux(): void
    {
        $scenario = $this->creerScenario();
        $exec = $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => $this->annee->id, 'graine' => 'gov-1', 'nb_variantes' => 2,
        ])->json('execution');

        // 1. Soumission par admin
        $prop = $this->appelPost($this->admin, 'admin.simulation-temporelle.executions.gouvernance.proposer', ['id' => $exec['id']], [
            'titre' => 'Planification Triennale Licence',
            'justification' => 'Conformité avec les orientations rectorales',
        ]);
        $prop->assertStatus(201);
        $this->assertSame('soumise', $prop->json('proposition.statut'));

        // 2. Interdiction stricte de l'auto-approbation
        $autoApprobation = $this->appelPost($this->admin, 'admin.simulation-temporelle.executions.gouvernance.decider', ['id' => $exec['id']], [
            'decision' => 'approuvee',
            'motif' => 'Je valide mon propre travail',
        ]);
        $autoApprobation->assertStatus(422);
        $this->assertStringContainsString('4 yeux', $autoApprobation->json('erreur'));

        // 3. Approbation légitime par un second utilisateur autorisé pour la faculté A
        $admin2 = User::creerCompteBackOffice([
            'name' => 'Admin2', 'email' => 'adm2-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_scolarite');
        $approbation = $this->appelPost($admin2, 'admin.simulation-temporelle.executions.gouvernance.decider', ['id' => $exec['id']], [
            'decision' => 'approuvee',
            'motif' => 'Validation revue par le second responsable',
        ]);
        $approbation->assertOk();
        $this->assertSame('approuvee', $approbation->json('proposition.statut'));
    }

    /* ── ST-10 corrections d'audit (non-régression) ── */

    /** Lot 6 — sémantique exacte des transitions LMD (passage / redoublement / abandon). */
    public function test_st10_lot6_transitions_lmd_deterministes_et_conservation(): void
    {
        // Périmètre connu du setup (faculté A) : L1=10, L2=20, L3=30 (total 60).
        $criteres = [
            'delta_admissions' => 0.0,          // pas de croissance : math pure
            'bornes' => ['min' => 0.0, 'max' => 0.0],
            'horizon_annees' => 1,
            'taux' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2],
        ];
        $scenario = $this->creerScenario([], $criteres);
        $execution = $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => $this->annee->id, 'graine' => 'lot6', 'nb_variantes' => 1,
        ])->assertOk()->json('execution');

        $snapshots = SnapshotExecution::where('execution_temporelle_id', $execution['id'])
            ->orderBy('pas')->get();

        $pas0 = $snapshots->firstWhere('pas', 0)->etat;
        $pas1 = $snapshots->firstWhere('pas', 1)->etat;

        // Pas 0 = état source réel.
        $this->assertSame(60, $pas0['total']);

        // Pas 1 : L3 diplômés sortent (15), abandons (L1 2, L2 4, L3 6),
        // passages L1→L2 (5) et L2→L3 (10), redoublants (3/6/9),
        // admissions L1 = 10 (delta 0 sur base L1).
        $this->assertSame(['L1' => 13, 'L2' => 11, 'L3' => 19], $pas1['cohortes']);
        $this->assertSame(10, $pas1['admissions']);

        // Conservation : 60 initial + 10 admissions - 15 diplômés - 12 abandons = 43.
        $this->assertSame(43, $pas1['total']);
        $this->assertSame([60, 43], $execution['resultat']['classement'][0]['totaux']);
    }

    /** Taux absents : JAMAIS substitués dans Monte-Carlo ni dans l'analyse de sensibilité. */
    public function test_st10_taux_absents_non_inventes_monte_carlo_et_sensibilite(): void
    {
        $criteres = $this->criteres();
        unset($criteres['taux']);
        $scenario = $this->creerScenario([], $criteres);
        $execution = $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => $this->annee->id, 'graine' => 'sans-taux', 'nb_variantes' => 2,
        ])->assertOk()->json('execution');

        // Monte-Carlo : gel explicite, aucun taux substitué.
        $mc = $execution['resultat']['monte_carlo'];
        $this->assertTrue($mc['taux_absents']);
        $this->assertStringContainsString('gelées', (string) $mc['avertissement']);

        // Sensibilité : lignes taux en N/A (non mesurables), jamais ±5% inventé.
        $lignes = collect($execution['resultat']['sensibilite']['parametres_classes']);
        $this->assertSame('N/A', $lignes->firstWhere('parametre', 'taux_passage')['variation_testee']);
        $this->assertSame('N/A', $lignes->firstWhere('parametre', 'taux_redoublement')['variation_testee']);
        $this->assertSame(0.0, (float) $lignes->firstWhere('parametre', 'taux_passage')['sensibilite_pct']);
        // Le delta reste analysable (paramètre réellement saisi).
        $this->assertSame('±10%', $lignes->firstWhere('parametre', 'delta_admissions')['variation_testee']);

        // Avertissement global conservé.
        $this->assertStringContainsString('gelées', $execution['resultat']['avertissements'][0]);
    }

    /** Export CSV : nombres négatifs intacts, formules neutralisées. */
    public function test_st10_export_csv_preserve_negatifs_et_neutralise_formules(): void
    {
        $scenario = ScenarioTemporel::create([
            'code' => 'CSV-'.uniqid(), 'libelle' => '=SOMME(A1:A9)', 'domaine' => 'effectifs_cohortes',
            'faculte_id' => $this->faculteA->id,
            'criteres' => $this->criteres(), 'contraintes' => [], 'objectifs' => [['type' => 'cible_total', 'cible' => 100, 'poids' => 1]],
            'version' => 1, 'cree_par' => $this->admin->id,
        ]);
        ExecutionTemporelle::create([
            'scenario_temporel_id' => $scenario->id, 'statut' => 'terminee',
            'hash_entrees' => 'h1', 'hash_resultat' => 'h2', 'graine' => 'g', 'version_moteur' => '1.0',
            'nb_variantes' => 1, 'horizon_annees' => 1,
            'resultat' => ['comparaison' => ['lignes' => [[
                'pas' => 1, 'annee' => '2027', 'effectif_reference' => 100, 'effectif_projete' => 88,
                'ecart_absolu' => -12, 'ecart_pourcentage' => -12.0, 'cible_metier' => null,
                'conforme_contraintes' => true,
            ]]], 'classement' => []],
        ]);

        $reponse = $this->appelGet($this->admin, 'admin.simulation-temporelle.executions.export.csv', ['id' => ExecutionTemporelle::sole()->id]);
        $reponse->assertOk();
        $csv = $reponse->streamedContent();

        // Écarts négatifs (nombre et pourcentage) : inchangés — un nombre
        // n'est pas une formule, et l'échapper corromprait le rapport.
        $this->assertStringContainsString(';-12;', $csv);
        $this->assertStringContainsString(';-12%;', $csv);
        $this->assertStringNotContainsString("'-12", $csv);
        // Libellé forgé : neutralisé.
        $this->assertStringContainsString("'=SOMME(A1:A9)", $csv);
    }

    /** Gouvernance : la re-soumission n'écrase jamais une proposition (trace conservée). */
    public function test_st10_gouvernance_resoumission_refusee_et_trace_conservee(): void
    {
        $scenario = $this->creerScenario();
        $exec = $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => $this->annee->id, 'graine' => 'gov-trace', 'nb_variantes' => 1,
        ])->json('execution');

        $this->appelPost($this->admin, 'admin.simulation-temporelle.executions.gouvernance.proposer', ['id' => $exec['id']], [
            'titre' => 'Revue initiale', 'justification' => 'Hypothèses de base',
        ])->assertStatus(201);

        $valideur = User::creerCompteBackOffice([
            'name' => 'Valideur', 'email' => 'val-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_scolarite');
        $this->appelPost($valideur, 'admin.simulation-temporelle.executions.gouvernance.decider', ['id' => $exec['id']], [
            'decision' => 'approuvee', 'motif' => 'Revue conforme',
        ])->assertOk();

        // Re-soumission par n'importe qui : refusée, l'historique reste intact.
        $resoumission = $this->appelPost($this->admin, 'admin.simulation-temporelle.executions.gouvernance.proposer', ['id' => $exec['id']], [
            'titre' => 'Nouvelle tentative', 'justification' => 'Écraser la trace',
        ])->assertStatus(422);
        $this->assertStringContainsString('existe déjà', $resoumission->json('erreur'));

        $rafraichi = $this->appelGet($this->admin, 'admin.simulation-temporelle.executions.detail', ['id' => $exec['id']])
            ->assertOk()->json('execution.resultat.gouvernance_proposition');
        $this->assertSame('approuvee', $rafraichi['statut']);
        $this->assertSame($valideur->id, $rafraichi['statue_par_id']);
        $this->assertCount(2, $rafraichi['historique']); // soumission + approbation
    }

    /** Capacité de référence : hypothèse marquée quand aucun groupe réel ne la fournit. */
    public function test_st10_capacite_hypothetique_explicite_selon_groupes(): void
    {
        // Sans groupe : capacité de référence = défaut du moteur → hypothèse.
        $sansGroupe = $this->creerScenario();
        $execution = $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $sansGroupe->id], [
            'annee_universitaire_id' => $this->annee->id, 'graine' => 'cap-hyp-1', 'nb_variantes' => 1,
        ])->assertOk()->json('execution');
        $this->assertTrue($execution['resultat']['capacites']['capacite_groupe_hypothese']);

        // Avec groupes réels (et étudiants rattachés) : capacité = moyenne
        // mesurée → hypothèse fausse.
        $niveau = $this->niveauA('L1');
        $groupe = $this->groupe('G-CAP', $niveau->id);
        $groupe->update(['capacite' => 40]);
        Etudiant::where('niveau', 'L1')
            ->where('faculte_id', $this->faculteA->id)
            ->take(5)->update(['groupe_id' => $groupe->id]);
        $criteres = $this->criteres() + ['niveau_id' => $niveau->id, 'groupes' => [$groupe->id]];
        $avecGroupe = $this->creerScenario([], $criteres);
        $execution2 = $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $avecGroupe->id], [
            'annee_universitaire_id' => $this->annee->id, 'graine' => 'cap-hyp-2', 'nb_variantes' => 1,
        ])->assertOk()->json('execution');
        $this->assertFalse($execution2['resultat']['capacites']['capacite_groupe_hypothese']);
        $this->assertSame(40, $execution2['resultat']['capacites']['capacite_groupe_reference']);
    }

    /** Budget : paramètres non saisis = hypothèse fictive explicite. */
    public function test_st10_budget_defauts_marques_comme_hypothese(): void
    {
        $scenario = $this->creerScenario();
        $execution = $this->appelPost($this->admin, 'admin.simulation-temporelle.executer', ['id' => $scenario->id], [
            'annee_universitaire_id' => $this->annee->id, 'graine' => 'budget-hyp', 'nb_variantes' => 1,
        ])->assertOk()->json('execution');

        $budget = $execution['resultat']['budget'];
        $this->assertFalse($budget['parametres_configures']);
        $this->assertStringContainsString('aucun impact réel', $budget['avertissement']);
    }
}
