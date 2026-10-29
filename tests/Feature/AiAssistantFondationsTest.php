<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AiAssistant\Data\AiDemande;
use App\Modules\AiAssistant\Data\ChunkDocumentaire;
use App\Modules\AiAssistant\Data\ContexteExecution;
use App\Modules\AiAssistant\Exceptions\ActionRefuseeException;
use App\Modules\AiAssistant\Exceptions\AiAssistantException;
use App\Modules\AiAssistant\Exceptions\FournisseurIndisponibleException;
use App\Modules\AiAssistant\Exceptions\OutilRefuseException;
use App\Modules\AiAssistant\Exceptions\PlafondAtteintException;
use App\Modules\AiAssistant\Support\PermissionsAi;
use App\Modules\AiAssistant\Support\RegistreOutils;
use App\Modules\AiAssistant\Support\SandboxFichier;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * LOT 1 — Fondations et architecture : contrats, erreurs typées,
 * politique module-locale, registre d'outils, sandbox, configuration.
 *
 * Contraintes du lot : aucun réseau, aucune clé, aucun impact sur les
 * modules métier (aucune base requise pour ces tests).
 */
final class AiAssistantFondationsTest extends TestCase
{
    /* ── Configuration ─────────────────────────────────────────── */

    public function test_la_configuration_ia_est_definie_sans_secret(): void
    {
        $config = config('ai-assistant');

        $this->assertIsArray($config);
        $this->assertSame('factice', $config['defaut']);
        $this->assertSame('', $config['fournisseurs']['distant_compat']['cle']);
        $this->assertSame('', $config['fournisseurs']['local_compat']['cle']);
        $this->assertGreaterThan(0, $config['limites']['iterations_agent_max']);
        $this->assertGreaterThan(0, $config['limites']['appels_par_heure']);
    }

    public function test_le_module_ia_est_desactive_par_defaut(): void
    {
        $this->assertFalse((bool) config('modules.ai-assistant.active'));
    }

    public function test_le_module_ia_est_declare_en_navigation_admin(): void
    {
        $entrees = config('modules.nav.admin');

        $ia = array_values(array_filter(
            $entrees,
            fn (array $entree) => $entree['module'] === 'ai-assistant'
        ));

        $this->assertSame([[
            'module' => 'ai-assistant',
            'label' => 'Assistant IA',
            'route' => 'admin.ai.index',
            'permission' => 'ai.acceder',
        ]], $ia, 'Entrée de navigation IA unique attendue.');
    }

    /* ── Matrice des permissions module-locale ─────────────────── */

    public function test_la_matrice_ia_couple_des_permissions_distinctes_aux_roles(): void
    {
        $this->assertCount(9, PermissionsAi::TOUTES);
        $this->assertSame(PermissionsAi::TOUTES, array_values(array_unique(PermissionsAi::TOUTES)));

        $this->assertSame(
            PermissionsAi::TOUTES,
            PermissionsAi::pourRole('super_admin'),
            'Le super_admin possède toutes les permissions IA.'
        );

        $this->assertTrue(PermissionsAi::permise(PermissionsAi::DEVELOPPEUR, 'admin_scolarite'));
        $this->assertFalse(PermissionsAi::permise(PermissionsAi::ADMIN_CONFIGURER, 'admin_scolarite'));

        $this->assertTrue(PermissionsAi::permise(PermissionsAi::METIER, 'agent_scolarite'));
        $this->assertFalse(
            PermissionsAi::permise(PermissionsAi::DEVELOPPEUR, 'agent_scolarite'),
            'Le mode développeur est interdit aux agents de scolarité.'
        );

        $agentSimulation = ['consultation', 'role_inconnu'];
        foreach ($agentSimulation as $role) {
            $this->assertFalse(
                PermissionsAi::permise(PermissionsAi::METIER, $role),
                "Le mode métier est refusé pour « {$role} »."
            );
        }
    }

    public function test_les_gates_ia_sont_enregistrees_par_le_fournisseur_module(): void
    {
        foreach (PermissionsAi::TOUTES as $permission) {
            $this->assertTrue(Gate::has($permission), "Gate IA attendue : {$permission}");
        }
    }

    public function test_les_gates_sont_calculees_depuis_le_role_back_office(): void
    {
        $superAdmin = new User;
        $superAdmin->forceFill(['role' => 'super_admin']);

        $agent = new User;
        $agent->forceFill(['role' => 'agent_scolarite']);

        $this->assertTrue(Gate::forUser($superAdmin)->allows(PermissionsAi::ADMIN_CONFIGURER));
        $this->assertTrue(Gate::forUser($agent)->allows(PermissionsAi::METIER));
        $this->assertFalse(Gate::forUser($agent)->allows(PermissionsAi::DEVELOPPEUR));
        $this->assertFalse(Gate::forUser($agent)->allows(PermissionsAi::ACTION_APPROUVER));
    }

    /* ── Erreurs typées ────────────────────────────────────────── */

    public function test_les_erreurs_ia_sont_typed_et_exposent_un_code_public(): void
    {
        $erreurs = [
            new FournisseurIndisponibleException('factice'),
            new PlafondAtteintException('appels_par_heure'),
            new OutilRefuseException('note', 'permission absente'),
            new ActionRefuseeException('appliquer_patch', 'liste blanche absente'),
        ];

        foreach ($erreurs as $erreur) {
            $this->assertInstanceOf(AiAssistantException::class, $erreur);
            $this->assertMatchesRegularExpression('/^ai\.[a-z.]+$/', $erreur->codePublic());
        }
    }

    /* ── DTOs structurés ───────────────────────────────────────── */

    public function test_la_demande_ia_valide_son_mode(): void
    {
        $demande = new AiDemande(
            question: 'explique le cycle d’inscription',
            mode: 'metier',
            utilisateurId: 1,
            faculteId: null,
        );

        $this->assertSame('metier', $demande->mode);

        $this->expectException(\InvalidArgumentException::class);
        new AiDemande(question: 'x', mode: 'hacker', utilisateurId: 1, faculteId: null);
    }

    public function test_le_chunk_documentaire_prepare_citation_et_contexte(): void
    {
        $chunk = new ChunkDocumentaire(7, 'Règles d’inscription', 'docs/inscriptions/rules.md', 'extrait', 3);

        $this->assertSame(['titre' => 'Règles d’inscription', 'chemin' => 'docs/inscriptions/rules.md'], $chunk->pourCitation());
        $this->assertSame('extrait', $chunk->pourContexte()['extrait']);
    }

    /* ── Sandbox de fichiers ───────────────────────────────────── */

    /**
     * @return array<string, array{0: string}>
     */
    public static function fournisseurCheminInterdits(): array
    {
        return [
            'env racine' => ['.env'],
            'env secondaire' => ['.env.example'],
            'traversée vers env' => ['app/../.env'],
            'absolu unix' => ['/etc/passwd'],
            'absolu windows' => ['C:/Windows/win.ini'],
            'vendor' => ['vendor/composer/autoload_real.php'],
            'storage journaux' => ['storage/logs/laravel.log'],
            'cache framework' => ['bootstrap/cache/packages.php'],
            'node_modules' => ['node_modules/vue/package.json'],
            'public' => ['public/robots.txt'],
            'dump sql' => ['database/data/export.sql'],
            'secrets docs' => ['docs/secrets/keys.md'],
            'traversée racine fuori' => ['config/../.env'],
            'hors racines autorisées' => ['README.md'],
        ];
    }

    #[DataProvider('fournisseurCheminInterdits')]
    public function test_la_sandbox_refuse_les_chemins_sensibles_ou_hors_racines(string $chemin): void
    {
        $this->assertNull(
            SandboxFichier::resoudre($chemin),
            "Chemin qui doit rester interdit : {$chemin}"
        );
    }

    public function test_la_sandbox_autorise_les_fichiers_code_et_docs_legitimes(): void
    {
        foreach (['app/Providers/AppServiceProvider.php', 'tests/TestCase.php'] as $chemin) {
            $this->assertNotSame(null, SandboxFichier::resoudre($chemin), $chemin);
        }
    }

    public function test_la_sandbox_refuse_les_dumps_oversize(): void
    {
        $chemin = 'tests/Support/sandbox-dump-trop-gros.md';
        $dossier = dirname(base_path($chemin));
        if (! is_dir($dossier)) {
            mkdir($dossier, 0777, true);
        }
        file_put_contents(base_path($chemin), str_repeat('x', SandboxFichier::TAILLE_FICHIER_MAX + 1));

        try {
            $this->assertNull(SandboxFichier::resoudre($chemin));
        } finally {
            unlink(base_path($chemin));
        }
    }

    public function test_l_extrait_de_fichier_est_borne_et_numerote(): void
    {
        $extrait = SandboxFichier::lireExtrait('app/Providers/AppServiceProvider.php', 1, 15);

        $this->assertSame('app/Providers/AppServiceProvider.php', $extrait['chemin']);
        $this->assertSame(1, $extrait['depuis']);
        $this->assertLessThanOrEqual(15, count($extrait['lignes']));
        $this->assertArrayHasKey('1', $extrait['lignes']);
        $this->assertStringStartsWith('<?php', (string) $extrait['lignes']['1']);
    }

    public function test_l_extrait_de_fichier_refuse_les_demandes_invalides(): void
    {
        foreach ([0, -5] as $depuis) {
            try {
                SandboxFichier::lireExtrait('app/Providers/AppServiceProvider.php', $depuis, 10);
                $this->fail('Ligne de départ invalide non refusée.');
            } catch (OutilRefuseException $erreur) {
                $this->assertStringContainsString('bornes invalides', $erreur->getMessage());
            }
        }

        try {
            SandboxFichier::lireExtrait('.env', 1, 10);
            $this->fail('.env lu malgré la sandbox.');
        } catch (OutilRefuseException $erreur) {
            $this->assertStringContainsString('hors périmètre', $erreur->getMessage());
        }
    }

    /* ── Registre des outils ───────────────────────────────────── */

    public function test_le_registre_refuse_un_outil_inconnu_ou_deja_enregistre(): void
    {
        $registre = new RegistreOutils;

        try {
            $registre->invoquer('tache.note', [], $this->contexte('super_admin', 'metier'));
            $this->fail('Outil inconnu accepté ?!');
        } catch (OutilRefuseException $erreur) {
            $this->assertStringContainsString('inconnu du registre', $erreur->getMessage());
        }

        $registre->enregistre('note', 'note passive', PermissionsAi::METIER, 'metier', fn () => ['ok' => true]);

        try {
            $registre->enregistre('note', 'note passive bis', PermissionsAi::METIER, 'metier', fn () => []);
            $this->fail('Doublon d’outil accepté ?!');
        } catch (\LogicException $erreur) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('note', $registre->outil('note')->nom);
    }

    public function test_le_registre_verifie_permission_mode_et_parametres_avant_execution(): void
    {
        $registre = new RegistreOutils;
        $registre->enregistre(
            'outil.metier',
            'outils métier de démonstration',
            PermissionsAi::METIER,
            'metier',
            fn (array $params, ContexteExecution $contexte) => ['ok' => true, 'param' => $params['param']],
            fn (array $parametres) => isset($parametres['param']) && is_string($parametres['param'])
        );

        $contexteSuperAdmin = $this->contexte('super_admin', 'metier');
        $this->assertSame(
            ['ok' => true, 'param' => 'valeur'],
            $registre->invoquer('outil.metier', ['param' => 'valeur'], $contexteSuperAdmin)
        );

        $contexteConsultation = $this->contexte('consultation', 'metier');
        try {
            $registre->invoquer('outil.metier', ['param' => 'valeur'], $contexteConsultation);
            $this->fail('Permission absente non vérifiée.');
        } catch (OutilRefuseException $erreur) {
            $this->assertStringContainsString('permission absente', $erreur->getMessage());
        }

        $contexteAgentAvecModeDeveloppeur = new ContexteExecution(1, 'super_admin', 'developpeur');
        try {
            $registre->invoquer('outil.metier', ['param' => 'valeur'], $contexteAgentAvecModeDeveloppeur);
            $this->fail('Mode développeur accepté pour un outil métier ?!');
        } catch (OutilRefuseException $erreur) {
            $this->assertStringContainsString('réservé au mode', $erreur->getMessage());
        }

        try {
            $registre->invoquer('outil.metier', ['param' => 42], $contexteSuperAdmin);
            $this->fail('Paramètre invalide accepté ?!');
        } catch (OutilRefuseException $erreur) {
            $this->assertStringContainsString('paramètres invalides', $erreur->getMessage());
        }
    }

    private function contexte(string $role, string $mode): ContexteExecution
    {
        return new ContexteExecution(1, $role, $mode, null);
    }
}
