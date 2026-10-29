<?php

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Enseignement\Models\AffectationEnseignement;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Enseignement\Models\TypeEnseignement;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Room;
use App\Modules\SimulateurScenarios\Services\ServiceScenarios;
use App\Modules\SimulateurScenarios\Support\CriteresScenario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Lot 14 — Simulateur de scénarios institutionnels : tests du cahier §F
 * (scénario normal, vide, limite, au-dessus de la capacité, salle absente,
 * données manquantes explicitées, reproductibilité, isolation facultaire,
 * IDOR, absence d'écriture officielle, performance synthétique).
 *
 * Données 100% synthétiques — aucune donnée réelle d'étudiant.
 */
class SimulateurScenariosTest extends TestCase
{
    use RefreshDatabase;

    private const DOSSIERS = [
        'faculteA', 'faculteB', 'annee',
        'programmeA', 'programmeB', 'typeEns', 'matiere', 'enseignant',
    ];

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private Programme $programmeA;

    private TypeEnseignement $typeEns;

    private static $enseignant;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('modules.simulateur-scenarios.active', true);

        $this->faculteA = Faculte::create(['nom' => 'Sciences Test', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'Droit Test', 'code' => 'FDEG', 'description' => 'T', 'active' => true]);
        $this->annee = AnneeUniversitaire::create(['libelle' => '2024-2025', 'date_debut' => '2024-09-01', 'date_fin' => '2025-07-15']);
        $this->programmeA = Programme::create([
            'faculte_id' => $this->faculteA->id, 'code' => 'LIC-A', 'nom' => 'Licence A',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $this->typeEns = TypeEnseignement::create(['code' => 'CM', 'nom' => 'Cours magistral']);
        self::$enseignant = DB::table('teachers')->insertGetId(['nom' => 'Diop', 'prenom' => 'Test', 'actif' => true]);
    }

    /* ── Données de synthèse ─────────────────────────────────────── */

    private function creerInscriptions(int $quantite, ?Programme $programme, string $statut = 'valide'): void
    {
        for ($i = 0; $i < $quantite; $i++) {
            $etudiant = Etudiant::create([
                'cin' => 'CIN-'.uniqid(),
                'nom' => 'Nom'.$i, 'prenom' => 'Prenom'.$i,
                'email' => 'etu'.$i.'-'.uniqid().'@example.test', 'sexe' => 'M',
                'faculte_id' => $programme?->faculte_id ?? $this->faculteA->id, 'niveau' => 'L1', 'annee_academique' => '2024-2025',
                'adresse' => 'Rue test', 'ville' => 'Dakar', 'code_postal' => '10000', 'annee_bac' => '2020',
            ]);
            InscriptionAcademique::create([
                'etudiant_id' => $etudiant->id,
                'annee_universitaire_id' => $this->annee->id,
                'programme_id' => $programme?->id,
                'niveau' => 'L1',
                'statut' => $statut,
            ]);
        }
    }

    private function creerRoom(string $code, int $capacite, Faculte $faculte, bool $actif = true): Room
    {
        return Room::create(['code' => $code, 'nom' => 'Salle '.$code, 'capacite' => $capacite, 'faculte_id' => $faculte->id, 'actif' => $actif]);
    }

    private function creerChargeActive(int $volume, Programme $programme, string $statut = 'active'): AffectationEnseignement
    {
        // Une matière par enseignement : (matiere_id, type_id, annee) est
        // UNIQUE en base — deux enseignements partagant la même matière sur
        // la même année violent cette contrainte (constat LOOP-03).
        $matiereId = DB::table('matieres')->insertGetId([
            'code' => 'MAT-'.uniqid(), 'nom' => 'Matiere Test', 'niveau' => 'L1', 'semestre' => 1,
        ]);

        $enseignement = Enseignement::create([
            'code' => 'ENS-'.uniqid(), 'matiere_id' => $matiereId, 'type_id' => $this->typeEns->id,
            'annee_universitaire_id' => $this->annee->id, 'programme_id' => $programme->id, 'statut' => 'validated',
        ]);

        return AffectationEnseignement::create([
            'enseignement_id' => $enseignement->id, 'teacher_id' => self::$enseignant,
            'role' => 'principal', 'volume_heures' => $volume, 'statut' => $statut,
        ]);
    }

    private function consultateur(): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Consultation', 'email' => 'consult-'.uniqid().'@universite.test', 'password' => 'Motdepasse@123',
        ], 'consultation');
    }

    private function adminFaculte(Faculte $faculte): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Admin Faculte', 'email' => 'adminfac-'.uniqid().'@universite.test',
            'password' => 'Motdepasse@123', 'faculte_id' => $faculte->id,
        ], 'admin_faculte');
    }

    private function envoiExecuter(User $utilisateur, array $charges = []): TestResponse
    {
        return $this->actingAs($utilisateur)->postJson(route('admin.simulateur-scenarios.executer'), $charges);
    }

    /* ── Scénario normal, effectifs, capacité, saturation ───────── */

    public function test_le_scenario_normal_projette_les_trois_axes_avec_unites(): void
    {
        $this->creerInscriptions(80, $this->programmeA);
        $this->creerRoom('A1', 60, $this->faculteA);
        $this->creerRoom('A2', 50, $this->faculteA);
        $this->creerRoom('A3', 40, $this->faculteA);
        $this->creerChargeActive(30, $this->programmeA);
        $this->creerChargeActive(20, $this->programmeA);

        $reponse = $this->envoiExecuter($this->consultateur(), [
            'faculte_id' => $this->faculteA->id,
            'annee_id' => $this->annee->id,
            'delta_effectifs' => 0.5,   // +50%
            'delta_salles' => 1,        // +1 salle de 50 (moyenne 150/3 arrondie bas)
            'delta_charges' => 0.1,     // +10%
        ])->assertOk();

        $this->assertIsArray($reponse->json('axes.effectifs'));
        $this->assertSame('etudiants', $reponse->json('axes.effectifs.unite'));
        $reponse->assertJsonPath('axes.effectifs.base', 80);
        $reponse->assertJsonPath('axes.effectifs.projete', 120);   // 80 * 1.5
        $reponse->assertJsonPath('axes.capacite.base', 150);
        $reponse->assertJsonPath('axes.capacite.capacite_moyenne', 50);
        $reponse->assertJsonPath('axes.capacite.projete', 200);    // 150 + 1*50
        $reponse->assertJsonPath('axes.saturation.etat', 'calcule');
        $reponse->assertJsonPath('axes.saturation.valeur', 0.6);   // 120/200
        $this->assertSame(50.0, (float) $reponse->json('axes.charges.base'));
        $this->assertSame(55.0, (float) $reponse->json('axes.charges.projete'));   // * 1.1
        $reponse->assertJsonPath('axes.programmes.recense', 1);
        $reponse->assertJsonPath('version_regles', '1.0');
    }

    public function test_le_calcul_est_reproductible_memes_entrees_meme_version(): void
    {
        $this->creerInscriptions(10, $this->programmeA);
        $this->creerRoom('A1', 40, $this->faculteA);

        $criteres = CriteresScenario::depuisTableau([
            'faculte_id' => $this->faculteA->id, 'annee_id' => $this->annee->id,
            'delta_effectifs' => 0.25, 'delta_salles' => -1, 'delta_charges' => -0.5,
        ]);

        $service = app(ServiceScenarios::class);
        $premier = $service->calculer($criteres);
        $deuxieme = $service->calculer($criteres);

        $this->assertSame($premier['hash_entrees'], $deuxieme['hash_entrees']);
        $this->assertSame($premier['axes'], $deuxieme['axes']);
        $this->assertSame($premier, $deuxieme);
    }

    /* ── Limites et refus ────────────────────────────────────────── */

    public function test_les_hypothese_hors_bornes_sont_refusees_sans_clamp_silencieux(): void
    {
        $jets = [
            ['cas' => ['delta_effectifs' => -0.75], 'fragment' => 'hypothèse'],
            ['cas' => ['delta_salles' => 21], 'fragment' => 'hypothèse'],
            ['cas' => ['delta_charges' => 1.5], 'fragment' => 'hypothèse'],
            ['cas' => ['delta_effectifs' => 'beaucoup'], 'fragment' => 'numérique'],
        ];

        foreach ($jets as $jet) {
            try {
                CriteresScenario::depuisTableau($jet['cas']);
                $this->fail('Hypothèse hors bornes acceptée pour le cas « '.$jet['fragment'].' ».');
            } catch (InvalidArgumentException $erreur) {
                $this->assertStringContainsString($jet['fragment'], $erreur->getMessage());
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function test_les_effectifs_au_dessus_de_la_capacite_marquent_la_saturation(): void
    {
        $this->creerInscriptions(120, $this->programmeA);
        $this->creerRoom('A1', 50, $this->faculteA);

        $reponse = $this->envoiExecuter($this->consultateur(), [
            'faculte_id' => $this->faculteA->id, 'delta_effectifs' => 0.5,
        ])->assertOk();

        $reponse->assertJsonPath('axes.effectifs.projete', 180);   // 120 * 1.5
        $reponse->assertJsonPath('axes.capacite.projete', 50);
        $reponse->assertJsonPath('axes.saturation.valeur', 3.6);   // au-dessus de 1
    }

    public function test_l_absence_de_salle_active_rend_la_saturation_indisponible(): void
    {
        // Aucune salle de faculté B : capacité absente (jamais un zéro silencieux).
        $reponse = $this->envoiExecuter($this->consultateur(), [
            'faculte_id' => $this->faculteB->id,
        ])->assertOk();

        $reponse->assertJsonPath('axes.capacite.salles_actives', 0);
        $reponse->assertJsonPath('axes.saturation.etat', 'indisponible');
        $this->assertStringContainsString('Aucune salle', (string) $reponse->json('axes.saturation.raison'));
    }

    public function test_les_inscriptions_sans_programme_sont_comptees_hors_chaine(): void
    {
        $this->creerInscriptions(7, $this->programmeA);
        $this->creerInscriptions(3, null); // programme_id NULL

        $reponse = $this->envoiExecuter($this->consultateur(), [
            'faculte_id' => $this->faculteA->id,
        ])->assertOk();

        $reponse->assertJsonPath('axes.effectifs.base', 7);
        $reponse->assertJsonPath('axes.effectifs.hors_chaine_programme', 3);
    }

    public function test_les_statuts_non_valides_ne_comptent_pas_dans_les_effectifs(): void
    {
        $this->creerInscriptions(5, $this->programmeA);
        $this->creerInscriptions(4, $this->programmeA, 'en_cours');

        $reponse = $this->envoiExecuter($this->consultateur(), [
            'faculte_id' => $this->faculteA->id,
        ])->assertOk();

        $reponse->assertJsonPath('axes.effectifs.base', 5);
    }

    /* ── Isolation facultaire / IDOR ─────────────────────────────── */

    public function test_admin_faculte_ne_peut_pas_consulter_une_autre_faculte_idor(): void
    {
        $admin = $this->adminFaculte($this->faculteA);

        $this->envoiExecuter($admin, ['faculte_id' => $this->faculteB->id])->assertForbidden();
    }

    public function test_admin_faculte_est_force_sur_son_perimetre_propre(): void
    {
        $this->creerInscriptions(6, $this->programmeA);
        $admin = $this->adminFaculte($this->faculteA);

        $reponse = $this->envoiExecuter($admin)->assertOk();

        $reponse->assertJsonPath('perimetre.faculte_id', $this->faculteA->id);
        $reponse->assertJsonPath('axes.effectifs.base', 6);
    }

    public function test_un_compte_sans_back_office_est_refuse(): void
    {
        $etudiant = User::create([
            'name' => 'Etudiant', 'email' => 'etu-'.uniqid().'@universite.test', 'password' => 'Motdepasse@123', 'role' => 'etudiant',
        ]);

        $this->envoiExecuter($etudiant)->assertForbidden();
    }

    /* ── Non-écriture officielle ─────────────────────────────────── */

    public function test_l_execution_ne_modifie_aucune_table_officielle(): void
    {
        $this->creerInscriptions(4, $this->programmeA);
        $this->creerRoom('A1', 40, $this->faculteA);
        $this->creerChargeActive(30, $this->programmeA);
        $this->creerChargeActive(99, $this->programmeA, 'cloturee');

        $tables = ['inscriptions_academiques', 'etudiants', 'rooms', 'enseignements', 'affectations_enseignement', 'programmes'];
        $avant = $this->comptageDirect($tables);

        $this->envoiExecuter($this->consultateur(), ['faculte_id' => $this->faculteA->id])->assertOk();

        $apres = $this->comptageDirect($tables);
        $this->assertSame($avant, $apres, 'Aucune table officielle ne doit être modifiée.');
        $this->assertDatabaseCount('affectations_enseignement', 2);
    }

    /**
     * @param  array<int, string>  $tables
     * @return array<string, int>
     */
    private function comptageDirect(array $tables): array
    {
        $comptes = [];
        foreach ($tables as $table) {
            $comptes[$table] = (int) DB::table($table)->count();
        }

        return $comptes;
    }

    /* ── Charges : convention statut active ─────────────────────── */

    public function test_les_charges_ne_comptent_que_les_affectations_actives(): void
    {
        $this->creerChargeActive(30, $this->programmeA);
        $this->creerChargeActive(20, $this->programmeA);
        $this->creerChargeActive(999, $this->programmeA, 'cloturee');

        $reponse = $this->envoiExecuter($this->consultateur(), [
            'faculte_id' => $this->faculteA->id,
        ])->assertOk();

        $reponse->assertJsonPath('axes.charges.affectations_actives', 2);
        $this->assertSame(50.0, (float) $reponse->json('axes.charges.base'));
    }

    /* ── Comparateur ─────────────────────────────────────────────── */

    public function test_le_comparateur_pose_les_ecarts_sur_le_meme_perimetre(): void
    {
        $this->creerInscriptions(10, $this->programmeA);
        $this->creerRoom('A1', 50, $this->faculteA);

        $reponse = $this->actingAs($this->consultateur())->postJson(route('admin.simulateur-scenarios.comparer'), [
            'faculte_id' => $this->faculteA->id,
            'scenario_a' => ['delta_effectifs' => 0.0],
            'scenario_b' => ['delta_effectifs' => 0.5, 'delta_salles' => 1],
        ])->assertOk();

        $ecarts = collect($reponse->json('ecarts'))->keyBy('axe');

        $this->assertSame(5.0, (float) $ecarts['effectifs']['ecart']);    // 10 → 15
        $this->assertSame(50.0, (float) $ecarts['capacite']['ecart']);    // 50 → 50 + 1×50
        $this->assertSame($reponse->json('scenarios.a.perimetre'), $reponse->json('scenarios.b.perimetre'));
    }

    public function test_le_comparateur_refuse_les_perimetres_differents(): void
    {
        try {
            app(ServiceScenarios::class)->comparer(
                CriteresScenario::depuisTableau(['faculte_id' => $this->faculteA->id]),
                CriteresScenario::depuisTableau(['faculte_id' => $this->faculteB->id]),
            );
            $this->fail('Des périmètres différents ont été comparés.');
        } catch (InvalidArgumentException $erreur) {
            $this->assertStringContainsString('même périmètre', $erreur->getMessage());
        }
    }

    /* ── Module désactivé : 404 ─────────────────────────────────── */

    public function test_module_desactive_repond_404_meme_en_appel_direct(): void
    {
        Config::set('modules.simulateur-scenarios.active', false);

        // Route POST uniquement : un GET sur cette route renverrait 405
        // (method not allowed) avant le contrôle du module.
        $this->actingAs($this->consultateur())->postJson(route('admin.simulateur-scenarios.executer'), [
            'delta_effectifs' => 0.5,
        ])->assertNotFound();
    }

    /* ── Performance sur jeu synthétique volumineux ─────────────── */

    public function test_le_calcul_reste_borne_sur_un_jeu_synthetique_volumineux(): void
    {
        $this->creerInscriptions(500, $this->programmeA);
        $this->creerRoom('A1', 60, $this->faculteA);
        $debut = microtime(true);

        $reponse = $this->envoiExecuter($this->consultateur(), ['faculte_id' => $this->faculteA->id])->assertOk();
        $reponse->assertJsonPath('axes.effectifs.base', 500);

        $this->assertLessThan(3.0, microtime(true) - $debut, 'Le calcul doit rester sous 3 s.');
    }
}
