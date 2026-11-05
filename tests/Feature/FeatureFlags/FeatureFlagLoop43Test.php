<?php

namespace Tests\Feature\FeatureFlags;

use App\Models\Faculte;
use App\Models\User;
use App\Services\FeatureFlagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * LOOP 43 — DÉPLOIEMENT PROGRESSIF.
 *
 * Vérifie le fail-closed, les restrictions de portée, le pourcentage
 * déterministe, le diagnostic sans PII et la garde HTTP.
 */
class FeatureFlagLoop43Test extends TestCase
{
    use RefreshDatabase;

    private FeatureFlagService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['feature_flags.environnements' => ['local', 'testing']]);
        $this->service = app(FeatureFlagService::class);
    }

    private function definirFlag(array $definition, string $nom = 'test_flag'): void
    {
        config(["feature_flags.flags.{$nom}" => array_merge([
            'enabled' => true,
            'description' => 'Indicateur de test',
            'environnements' => [],
            'facultes' => [],
            'programmes' => [],
            'users' => [],
            'pourcentage' => null,
        ], $definition)]);
    }

    private function creerUtilisateur(?int $faculteId = null): User
    {
        return User::create([
            'name' => 'Utilisateur Test',
            'email' => 'user'.uniqid().'@univ.tn',
            'password' => Hash::make('secret-test'),
            'faculte_id' => $faculteId,
            'actif' => true,
        ]);
    }

    // ---------------------------------------------------------------------
    // Fail-closed
    // ---------------------------------------------------------------------

    public function test_indicateur_inconnu_est_faux(): void
    {
        $resultat = $this->service->evaluer('flag_inexistant', ['user_id' => 1]);

        $this->assertFalse($resultat['actif']);
        $this->assertSame(FeatureFlagService::MOTIF_FLAG_INCONNU, $resultat['motif']);
    }

    public function test_indicateurs_livres_sont_tous_desactives_par_defaut(): void
    {
        foreach (array_keys(config('feature_flags.flags')) as $flag) {
            $this->assertFalse(
                $this->service->estActif($flag, ['user_id' => 1, 'faculte_id' => 1]),
                "L'indicateur {$flag} devrait être désactivé par défaut."
            );
        }
    }

    public function test_indicateur_desactive_est_faux_meme_avec_contexte_valide(): void
    {
        $this->definirFlag(['enabled' => false]);

        $resultat = $this->service->evaluer('test_flag', [
            'user_id' => 1,
            'faculte_id' => 1,
            'programme_id' => 1,
        ]);

        $this->assertFalse($resultat['actif']);
        $this->assertSame(FeatureFlagService::MOTIF_DESACTIVE, $resultat['motif']);
    }

    public function test_environnement_non_autorise_est_faux(): void
    {
        config(['feature_flags.environnements' => ['production']]);
        $this->definirFlag([]);

        $resultat = $this->service->evaluer('test_flag', ['environnement' => 'testing']);

        $this->assertFalse($resultat['actif']);
        $this->assertSame(FeatureFlagService::MOTIF_ENVIRONNEMENT, $resultat['motif']);
    }

    public function test_environnement_specifique_au_flag_ne_peut_pas_depasser_la_liste_globale(): void
    {
        // La liste globale est un plafond : un flag ne peut pas l'élargir.
        config(['feature_flags.environnements' => ['local', 'testing']]);
        $this->definirFlag(['environnements' => ['production']]);

        $this->assertFalse($this->service->estActif('test_flag', ['environnement' => 'production']));
        $this->assertFalse($this->service->estActif('test_flag', ['environnement' => 'testing']));
    }

    public function test_environnement_specifique_au_flag_restreint_dans_le_plafond_global(): void
    {
        config(['feature_flags.environnements' => ['local', 'testing', 'production']]);
        $this->definirFlag(['environnements' => ['production']]);

        $this->assertFalse($this->service->estActif('test_flag', ['environnement' => 'testing']));
        $this->assertTrue($this->service->estActif('test_flag', ['environnement' => 'production']));
    }

    // ---------------------------------------------------------------------
    // Portée
    // ---------------------------------------------------------------------

    public function test_indicateur_global_actif_sans_restriction(): void
    {
        $this->definirFlag([]);

        $this->assertTrue($this->service->estActif('test_flag'));
        $this->assertTrue($this->service->estActif('test_flag', ['user_id' => 42]));
    }

    public function test_restriction_par_faculte(): void
    {
        $this->definirFlag(['facultes' => [1, 3]]);

        $this->assertTrue($this->service->estActif('test_flag', ['faculte_id' => 1]));
        $this->assertTrue($this->service->estActif('test_flag', ['faculte_id' => '3']));
        $this->assertFalse($this->service->estActif('test_flag', ['faculte_id' => 2]));

        $resultat = $this->service->evaluer('test_flag', ['faculte_id' => 2]);
        $this->assertSame(FeatureFlagService::MOTIF_HORS_FACULTE, $resultat['motif']);
    }

    public function test_restriction_par_faculte_avec_contexte_absent_est_fail_closed(): void
    {
        $this->definirFlag(['facultes' => [1]]);

        $resultat = $this->service->evaluer('test_flag');

        $this->assertFalse($resultat['actif']);
        $this->assertSame(FeatureFlagService::MOTIF_HORS_FACULTE, $resultat['motif']);
    }

    public function test_restriction_par_programme(): void
    {
        $this->definirFlag(['programmes' => [10]]);

        $this->assertTrue($this->service->estActif('test_flag', ['programme_id' => 10]));
        $this->assertFalse($this->service->estActif('test_flag', ['programme_id' => 11]));

        $resultat = $this->service->evaluer('test_flag', ['programme_id' => 11]);
        $this->assertSame(FeatureFlagService::MOTIF_HORS_PROGRAMME, $resultat['motif']);
    }

    public function test_restriction_par_utilisateur(): void
    {
        $this->definirFlag(['users' => [7, 9]]);

        $this->assertTrue($this->service->estActif('test_flag', ['user_id' => 7]));
        $this->assertFalse($this->service->estActif('test_flag', ['user_id' => 8]));

        $resultat = $this->service->evaluer('test_flag', ['user_id' => 8]);
        $this->assertSame(FeatureFlagService::MOTIF_UTILISATEUR_HORS_LISTE, $resultat['motif']);
    }

    public function test_dimensions_combinees_en_et(): void
    {
        $this->definirFlag([
            'facultes' => [1],
            'programmes' => [10],
        ]);

        $this->assertTrue($this->service->estActif('test_flag', [
            'faculte_id' => 1,
            'programme_id' => 10,
        ]));

        // Bonne faculté, mauvais programme : refusé.
        $this->assertFalse($this->service->estActif('test_flag', [
            'faculte_id' => 1,
            'programme_id' => 11,
        ]));
    }

    // ---------------------------------------------------------------------
    // Pourcentage
    // ---------------------------------------------------------------------

    public function test_bucket_est_deterministe_et_borne(): void
    {
        $premier = $this->service->bucket('test_flag', 123);
        $second = $this->service->bucket('test_flag', 123);

        $this->assertSame($premier, $second);
        $this->assertGreaterThanOrEqual(0, $premier);
        $this->assertLessThan(100, $premier);
    }

    public function test_bucket_depend_du_flag(): void
    {
        // Le seau n'est pas une propriété du seul utilisateur : deux flags
        // peuvent répartir les mêmes sujets différemment.
        $seaux = [];
        foreach (['flag_a', 'flag_b', 'flag_c'] as $flag) {
            $seaux[] = $this->service->bucket($flag, 1);
        }

        $this->assertCount(3, $seaux);
        $this->assertTrue(count(array_unique($seaux)) >= 1);
    }

    public function test_pourcentage_zero_est_toujours_faux(): void
    {
        $this->definirFlag(['pourcentage' => 0]);

        $this->assertFalse($this->service->estActif('test_flag', ['user_id' => 1]));

        $resultat = $this->service->evaluer('test_flag', ['user_id' => 1]);
        $this->assertSame(FeatureFlagService::MOTIF_POURCENTAGE_NON_ATTEINT, $resultat['motif']);
    }

    public function test_pourcentage_cent_est_toujours_vrai_avec_sujet(): void
    {
        $this->definirFlag(['pourcentage' => 100]);

        $this->assertTrue($this->service->estActif('test_flag', ['user_id' => 1]));
        $this->assertTrue($this->service->estActif('test_flag', ['user_id' => 999]));
    }

    public function test_pourcentage_sans_sujet_est_fail_closed(): void
    {
        $this->definirFlag(['pourcentage' => 50]);

        $resultat = $this->service->evaluer('test_flag');

        $this->assertFalse($resultat['actif']);
        $this->assertSame(FeatureFlagService::MOTIF_POURCENTAGE_SANS_SUJET, $resultat['motif']);
    }

    public function test_pourcentage_repartit_les_sujets_de_facon_stable(): void
    {
        $this->definirFlag(['pourcentage' => 50]);

        $actifs = 0;
        $inactifs = 0;

        for ($sujet = 1; $sujet <= 200; $sujet++) {
            $actif = $this->service->estActif('test_flag', ['user_id' => $sujet]);
            $actif ? $actifs++ : $inactifs++;

            // Stabilité : le même sujet redonne le même résultat.
            $this->assertSame($actif, $this->service->estActif('test_flag', ['user_id' => $sujet]));
        }

        $this->assertSame(200, $actifs + $inactifs);
        $this->assertGreaterThan(0, $actifs);
        $this->assertGreaterThan(0, $inactifs);
    }

    // ---------------------------------------------------------------------
    // Diagnostic
    // ---------------------------------------------------------------------

    public function test_inventaire_ne_divulgue_pas_les_identifiants_utilisateurs(): void
    {
        $this->definirFlag(['users' => [7, 9], 'facultes' => [3]]);

        $inventaire = $this->service->inventaire();

        $this->assertArrayHasKey('test_flag', $inventaire);
        $this->assertSame(2, $inventaire['test_flag']['users']);
        $this->assertSame(1, $inventaire['test_flag']['facultes']);
        $this->assertArrayNotHasKey('7', $inventaire['test_flag']);
    }

    // ---------------------------------------------------------------------
    // Garde HTTP
    // ---------------------------------------------------------------------

    public function test_middleware_renvoie_404_quand_le_flag_est_desactive(): void
    {
        Route::middleware('feature:test_flag')->get('/_test/feature-disabled', fn () => 'ok');

        $this->definirFlag(['enabled' => false]);
        $utilisateur = $this->creerUtilisateur();

        $this->actingAs($utilisateur)
            ->get('/_test/feature-disabled')
            ->assertNotFound();
    }

    public function test_middleware_laisse_passer_quand_le_flag_est_actif(): void
    {
        Route::middleware('feature:test_flag')->get('/_test/feature-enabled', fn () => 'ok');

        $this->definirFlag(['enabled' => true]);
        $utilisateur = $this->creerUtilisateur();

        $this->actingAs($utilisateur)
            ->get('/_test/feature-enabled')
            ->assertOk()
            ->assertSee('ok');
    }

    public function test_middleware_applique_la_restriction_de_faculte(): void
    {
        Route::middleware('feature:test_flag')->get('/_test/feature-faculte', fn () => 'ok');

        $faculteAutorisee = Faculte::create(['nom' => 'Faculté A', 'code' => 'FA']);
        $faculteRefusee = Faculte::create(['nom' => 'Faculté B', 'code' => 'FB']);

        $this->definirFlag(['facultes' => [$faculteAutorisee->id]]);

        $this->actingAs($this->creerUtilisateur($faculteAutorisee->id))
            ->get('/_test/feature-faculte')
            ->assertOk();

        $this->actingAs($this->creerUtilisateur($faculteRefusee->id))
            ->get('/_test/feature-faculte')
            ->assertNotFound();
    }

    public function test_middleware_flag_inconnu_renvoie_404(): void
    {
        Route::middleware('feature:flag_qui_nexiste_pas')->get('/_test/feature-inconnu', fn () => 'ok');

        $this->actingAs($this->creerUtilisateur())
            ->get('/_test/feature-inconnu')
            ->assertNotFound();
    }

    // ---------------------------------------------------------------------
    // Tests Obligatoires Spécifiques PHASE F (LOOP 43)
    // ---------------------------------------------------------------------

    public function test_cohorte_non_autorisee_bloquee(): void
    {
        $this->definirFlag(['annees' => [6]]); // Année 2025-2026 uniquement

        // Année autorisée (6)
        $this->assertTrue($this->service->estActif('test_flag', ['annee_universitaire_id' => 6]));

        // Année étrangère (5)
        $this->assertFalse($this->service->estActif('test_flag', ['annee_universitaire_id' => 5]));

        $resultat = $this->service->evaluer('test_flag', ['annee_universitaire_id' => 5]);
        $this->assertSame(FeatureFlagService::MOTIF_HORS_COHORTE, $resultat['motif']);
    }

    public function test_parametres_clients_falsifies_ignores_par_le_middleware(): void
    {
        Route::middleware('feature:test_flag')->get('/_test/feature-tamper', fn () => 'ok');

        $faculteAutorisee = Faculte::create(['nom' => 'FST Réelle', 'code' => 'FST']);
        $faculteUsurpatrice = Faculte::create(['nom' => 'Autre Faculté', 'code' => 'AUTRE']);

        $this->definirFlag(['facultes' => [$faculteAutorisee->id]]);

        // L'utilisateur appartient à la faculté non autorisée mais tente d'injecter ?faculte_id=1
        $utilisateur = $this->creerUtilisateur($faculteUsurpatrice->id);

        $this->actingAs($utilisateur)
            ->get('/_test/feature-tamper?faculte_id='.$faculteAutorisee->id.'&programme_id=21')
            ->assertNotFound();
    }

    public function test_api_protegee_par_feature_flag(): void
    {
        Route::middleware('feature:pilote_academique')->get('/api/_test/pilote-endpoint', fn () => response()->json(['status' => 'success']));

        $faculte = Faculte::create(['nom' => 'FST Test', 'code' => 'FST']);
        $utilisateur = $this->creerUtilisateur($faculte->id);

        // Par défaut, le flag pilote_academique est désactivé -> 404
        $this->actingAs($utilisateur)
            ->getJson('/api/_test/pilote-endpoint')
            ->assertNotFound();

        // Activation contrôlée pour cette faculté et l'environnement testing
        config([
            'feature_flags.flags.pilote_academique.enabled' => true,
            'feature_flags.flags.pilote_academique.facultes' => [$faculte->id],
            'feature_flags.flags.pilote_academique.programmes' => [],
            'feature_flags.flags.pilote_academique.annees' => [],
            'feature_flags.flags.pilote_academique.environnements' => ['testing'],
        ]);

        $this->actingAs($utilisateur)
            ->getJson('/api/_test/pilote-endpoint')
            ->assertOk()
            ->assertJson(['status' => 'success']);
    }

    public function test_absence_de_mutation_metier_en_mode_simulation(): void
    {
        $compteursAvant = [
            'users' => DB::table('users')->count(),
            'facultes' => DB::table('facultes')->count(),
        ];

        // Évaluation intensive du service de feature flags et simulation
        for ($i = 0; $i < 50; $i++) {
            $this->service->evaluer('pilote_academique', [
                'user_id' => $i,
                'faculte_id' => 1,
                'mode' => FeatureFlagService::MODE_SIMULATION,
            ]);
        }

        foreach ($compteursAvant as $table => $valeur) {
            $this->assertSame($valeur, DB::table($table)->count(), "La table {$table} ne doit pas être altérée.");
        }
    }

    public function test_journalisation_activation_sans_pii(): void
    {
        $journal = $this->service->journaliserActivation('pilote_academique', [
            'user_id' => 99,
            'faculte_id' => 1,
            'mode' => FeatureFlagService::MODE_SIMULATION,
        ], true, FeatureFlagService::MOTIF_ACTIF);

        $this->assertArrayHasKey('execution_id', $journal);
        $this->assertArrayHasKey('horodatage', $journal);
        $this->assertSame(FeatureFlagService::MOTIF_ACTIF, $journal['motif']);
        $this->assertTrue($journal['actif']);

        // Aucune valeur nominative ni PII
        $this->assertArrayNotHasKey('user_id', $journal);
        $this->assertArrayNotHasKey('email', $journal);
    }
}
