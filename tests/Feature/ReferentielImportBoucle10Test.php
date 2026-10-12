<?php

namespace Tests\Feature;

use App\Jobs\ExecuteDataImportJob;
use App\Models\Faculte;
use App\Models\ImportJob;
use App\Models\ImportReport;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Services\DataExchange\DataImportService;
use App\Services\Io\IoException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Import intelligent du référentiel (LOOP 10) : aperçu sans écriture,
 * mapping contrôlé, idempotence (empreinte + token à consommation
 * unique), revalidation anti-TOCTOU, périmètre facultaire (upsert
 * scopé faculte+code), atomicité, file + reprise, audit.
 */
class ReferentielImportBoucle10Test extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculteA;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.referentiel.active' => true]);

        $this->faculteA = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.boucle10@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    private function fichier(string $nom, string $contenu): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nom, $contenu, 'text/csv');
    }

    private function preparer(string $entite, string $contenu, ?int $auteurId = null): array
    {
        return app(DataImportService::class)->preparer(
            $this->fichier("{$entite}.csv", $contenu), $entite, $auteurId ?? $this->admin->id
        );
    }

    /** 1. Aperçu sans modification de la base. */
    public function test_apercu_sans_ecriture(): void
    {
        $avant = [Programme::count(), Niveau::count(), ImportReport::count()];

        $preparation = $this->preparer('programmes', "code;nom;faculte\nPX10;Programme X;FST\n");

        $this->assertNotEmpty($preparation['token']);
        $this->assertSame($avant, [Programme::count(), Niveau::count(), ImportReport::count()]);
    }

    /** 2. Mapping valide et mapping invalide (colonne requise absente). */
    public function test_mapping_valide_et_invalide(): void
    {
        $ok = $this->preparer('programmes', "code;nom\nPX10;Programme X\n");
        $this->assertSame(0, $ok['resultat']->bloque() ? 1 : 0);

        try {
            $this->preparer('programmes', "code;faculte\nPX10;FST\n");
            $this->fail('Colonne requise absente acceptée.');
        } catch (IoException $e) {
            $this->assertStringContainsString('manquantes', $e->getMessage());
        }

        $this->assertSame(0, Programme::count());
    }

    /** 3. Champ protégé refusé (colonne `id` ignorée, jamais forcée). */
    public function test_champ_protege_ignore(): void
    {
        $preparation = $this->preparer('programmes', "id;code;nom\n999;PX10;Programme X\n");
        app(DataImportService::class)->confirmer($preparation['token'], 'creer', $this->admin->id);

        $cree = Programme::where('code', 'PX10')->firstOrFail();
        $this->assertNotSame(999, $cree->id);
    }

    /** 4. Référence inexistante : programme inconnu pour une UE. */
    public function test_reference_inexistante_bloque(): void
    {
        $preparation = $this->preparer(
            'ues', "code;semestre;programme;nom;credits\nUE-X;S1;PROG-ABSENT;UE X;6\n"
        );

        $this->assertTrue($preparation['resultat']->bloque());
        $this->assertSame(0, UniteEnseignement::count());
    }

    /** 5. Doublon intra-fichier : signalé en aperçu, ligne exclue du lot. */
    public function test_doublon_intra_fichier_bloque(): void
    {
        $preparation = $this->preparer(
            'programmes', "code;nom;faculte\nPX10;Programme X;FST\nPX10;Programme X bis;FST\n"
        );

        // L'aperçu signale le doublon comme erreur bloquante de la ligne.
        $this->assertTrue($preparation['resultat']->bloque());

        // Contrat moteur : la confirmation n'applique que les lignes
        // saines (la doublonnée est exclue, jamais importée en double).
        app(DataImportService::class)->confirmer($preparation['token'], 'creer', $this->admin->id);

        $this->assertSame(1, Programme::where('code', 'PX10')->count());
        $this->assertSame('Programme X', Programme::where('code', 'PX10')->firstOrFail()->nom);
    }

    /** 6. Fichier déjà importé : avertissement IMPORT_ALREADY_PROCESSED. */
    public function test_fichier_deja_importe_signale(): void
    {
        $csv = "code;nom;faculte\nPX10;Programme X;FST\n";
        $premier = $this->preparer('programmes', $csv);
        app(DataImportService::class)->confirmer($premier['token'], 'creer', $this->admin->id);

        $second = $this->preparer('programmes', $csv);
        $trouve = false;

        foreach ($second['resultat']->avertissements as $avertissement) {
            if (str_contains((string) $avertissement, 'IMPORT_ALREADY_PROCESSED')) {
                $trouve = true;
            }
        }

        $this->assertTrue($trouve, 'Avertissement idempotence absent.');
    }

    /** 7. Données modifiées entre aperçu et confirmation : rien d'écrit. */
    public function test_revalidation_anti_toctou(): void
    {
        $programme = Programme::create([
            'faculte_id' => $this->faculteA->id, 'code' => 'PX10', 'nom' => 'Programme X',
            'statut' => 'actif', 'actif' => true,
        ]);
        $niveau = Niveau::create([
            'programme_id' => $programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1, 'actif' => true,
        ]);
        SemestreLmd::create([
            'niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1',
            'numero' => 1, 'ordre' => 1, 'actif' => true,
        ]);

        $preparation = $this->preparer(
            'ues', "code;semestre;programme;nom;credits\nUE-X;S1;PX10;UE X;6\n"
        );
        $this->assertFalse($preparation['resultat']->bloque());

        // Le programme est archivé après l'aperçu : la confirmation
        // revalide et refuse (tout ou rien).
        $programme->update(['statut' => 'archive']);

        try {
            app(DataImportService::class)->confirmer($preparation['token'], 'creer', $this->admin->id);
            $this->fail('Confirmation malgré archivage post-aperçu.');
        } catch (IoException) {
            $this->assertTrue(true);
        }

        $this->assertSame(0, UniteEnseignement::count());
    }

    /** 8. Second aperçu après changement d'état : reclassé mise à jour. */
    public function test_apercu_reflete_etat_courant(): void
    {
        $csv = "code;nom;faculte\nPX10;Programme X;FST\n";
        $premier = $this->preparer('programmes', $csv);
        app(DataImportService::class)->confirmer($premier['token'], 'creer', $this->admin->id);

        $second = $this->preparer('programmes', $csv);
        $miseAJour = false;

        foreach ($second['resultat']->avertissements as $avertissement) {
            if (str_contains((string) $avertissement, 'mise à jour')) {
                $miseAJour = true;
            }
        }

        $this->assertTrue($miseAJour, 'Reclassification mise à jour absente.');
        $this->assertSame(1, Programme::where('code', 'PX10')->count());
    }

    /** 9. Confirmation par un autre utilisateur : refusée. */
    public function test_confirmation_autre_auteur_refusee(): void
    {
        $autre = User::creerCompteBackOffice([
            'name' => 'Autre', 'email' => 'autre.boucle10@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $preparation = $this->preparer('programmes', "code;nom;faculte\nPX10;Programme X;FST\n");

        try {
            app(DataImportService::class)->confirmer($preparation['token'], 'creer', $autre->id);
            $this->fail('Confirmation par un tiers acceptée.');
        } catch (IoException $e) {
            $this->assertStringContainsString('expirée', $e->getMessage());
        }

        $this->assertSame(0, Programme::count());
    }

    /** 10. Isolation : périmètre refusé + homonymes inter-facultés séparés. */
    public function test_isolation_facultes(): void
    {
        $faculteB = Faculte::create(['nom' => 'FDL', 'code' => 'FDL', 'active' => true]);
        $restreint = User::creerCompteBackOffice([
            'name' => 'Admin A', 'email' => 'a.boucle10@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        Programme::create([
            'faculte_id' => $this->faculteA->id, 'code' => 'PX10', 'nom' => 'Programme A',
            'statut' => 'actif', 'actif' => true,
        ]);
        $programmeB = Programme::create([
            'faculte_id' => $faculteB->id, 'code' => 'PX10', 'nom' => 'Programme B',
            'statut' => 'actif', 'actif' => true,
        ]);

        // Restreint A vers B : refusé.
        $pirate = $this->preparer('programmes', "code;nom;faculte\nPX10;Programme pirate;FDL\n", $restreint->id);

        try {
            app(DataImportService::class)->confirmer($pirate['token'], 'upsert', $restreint->id);
            $this->fail('Écriture hors périmètre acceptée.');
        } catch (IoException) {
            $this->assertTrue(true);
        }

        $this->assertSame('Programme B', $programmeB->fresh()->nom);

        // Global vers B : seule la ligne B est mise à jour, A intacte.
        $global = $this->preparer('programmes', "code;nom;faculte\nPX10;Programme B v2;FDL\n");
        app(DataImportService::class)->confirmer($global['token'], 'upsert', $this->admin->id);

        $this->assertSame('Programme B v2', $programmeB->fresh()->nom);
        $this->assertSame('Programme A', Programme::where('code', 'PX10')->where('faculte_id', $this->faculteA->id)->firstOrFail()->nom);
    }

    /** 11. Échec en confirmation : annulation complète (tout ou rien). */
    public function test_echec_confirmation_tout_ou_rien(): void
    {
        $preparation = $this->preparer(
            'programmes', "code;nom;faculte\nPX10;Programme X;FST\n"
        );

        // Mode invalide : refusé avant toute écriture.
        try {
            app(DataImportService::class)->confirmer($preparation['token'], 'inconnu', $this->admin->id);
            $this->fail('Mode inconnu accepté.');
        } catch (IoException) {
            $this->assertTrue(true);
        }

        $this->assertSame(0, Programme::count());
        $this->assertSame(0, ImportReport::where('status', 'completed')->count());
    }

    /** 12. File + reprise : lot mis en file, traité, non rejoué. */
    public function test_file_et_reprise_idempotente(): void
    {
        config(['integrations.queue_threshold' => 1]);

        $preparation = $this->preparer(
            'programmes', "code;nom;faculte\nPX10;Programme X;FST\nPX11;Programme Y;FST\n"
        );
        $resultat = app(DataImportService::class)->confirmer($preparation['token'], 'creer', $this->admin->id);

        $this->assertTrue((bool) ($resultat['queued'] ?? false));

        // File synchrone en test : déjà traité.
        $job = ImportJob::where('entite', 'programmes')->firstOrFail();
        $this->assertSame('termine', $job->statut);
        $this->assertSame(1, Programme::where('code', 'PX10')->count());
        $this->assertSame(1, ImportReport::where('status', 'completed')->count());

        // Rejeu du worker : garde atomique, aucun doublon, aucun rapport.
        app()->call([new ExecuteDataImportJob($job->id), 'handle']);

        $this->assertSame(1, Programme::where('code', 'PX10')->count());
        $this->assertSame(1, ImportReport::where('status', 'completed')->count());
    }

    /** 13. Deux confirmations : la seconde expire, un seul import. */
    public function test_double_confirmation_unique(): void
    {
        $preparation = $this->preparer('programmes', "code;nom;faculte\nPX10;Programme X;FST\n");
        app(DataImportService::class)->confirmer($preparation['token'], 'creer', $this->admin->id);

        try {
            app(DataImportService::class)->confirmer($preparation['token'], 'creer', $this->admin->id);
            $this->fail('Double confirmation acceptée.');
        } catch (IoException $e) {
            $this->assertStringContainsString('expirée', $e->getMessage());
        }

        $this->assertSame(1, Programme::where('code', 'PX10')->count());
        $this->assertSame(1, ImportReport::where('status', 'completed')->count());
    }

    /** 14. Sans effet sur les autres consommateurs de DataExchange. */
    public function test_sans_effet_secondaire(): void
    {
        $preparation = $this->preparer('programmes', "code;nom;faculte\nPX10;Programme X;FST\n");
        app(DataImportService::class)->confirmer($preparation['token'], 'creer', $this->admin->id);

        $this->assertSame(0, Niveau::count());
        $this->assertSame(0, UniteEnseignement::count());

        // Un import matières reste fonctionnel après (même moteur).
        $matieres = $this->preparer('matieres', "code;nom;niveau;semestre;coefficient;credits\nALG101;Algorithmique;L1;1;2;4\n");
        app(DataImportService::class)->confirmer($matieres['token'], 'creer', $this->admin->id);

        $this->assertSame(1, Matiere::where('code', 'ALG101')->count());
    }
}
