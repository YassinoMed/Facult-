<?php

namespace Tests\Feature;

use App\Services\DataExchange\DataImportService;
use App\Services\DataExchange\DefinitionRegistry;
use App\Services\Io\CsvParser;
use App\Services\Io\IoException;
use App\Services\Io\IoFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * PHASE 1 — Contrats d'import/export : le registre, les formats, les
 * plafonds et les messages doivent correspondre au code réel. Toute
 * dérive (nouvelle définition incohérente, message mensonger) fait
 * échouer cette suite.
 */
class DataExchangeContratTest extends TestCase
{
    use RefreshDatabase;

    private function fichier(string $contenu, string $nom = 'contrat.csv'): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'contrat').'.csv';
        file_put_contents($chemin, $contenu);

        return new UploadedFile($chemin, $nom, 'text/csv', null, true);
    }

    public function test_toutes_les_entites_importables_resolvent_avec_contrat_complet(): void
    {
        $registre = app(DefinitionRegistry::class);

        $this->assertNotEmpty(DefinitionRegistry::entites());

        foreach (DefinitionRegistry::entites() as $entite) {
            $definition = $registre->import($entite);

            $this->assertNotEmpty($definition->colonnes(), "colonnes vides : {$entite}");
            $this->assertNotEmpty($definition->regles(), "règles vides : {$entite}");

            foreach ($definition->modes() as $mode) {
                $this->assertContains($mode, ['creer', 'upsert', 'remplacer'], "mode inconnu : {$entite}");
            }
        }
    }

    public function test_toutes_les_entites_exportables_ont_des_formats_pris_en_charge(): void
    {
        $registre = app(DefinitionRegistry::class);
        $serialiseurs = ['csv', 'json', 'xml', 'xlsx', 'pdf'];

        $this->assertNotEmpty(DefinitionRegistry::exportables());

        foreach (DefinitionRegistry::exportables() as $entite) {
            $definition = $registre->export($entite);

            $this->assertNotEmpty($definition->formats(), "formats vides : {$entite}");

            foreach ($definition->formats() as $format) {
                $this->assertContains($format, $serialiseurs, "format non sérialisable : {$entite}/{$format}");
                IoFactory::serializerPour($format);
            }

            $this->assertNotEmpty($definition->colonnes(), "colonnes vides : {$entite}");
        }
    }

    public function test_formats_import_coherents_avec_iofactory(): void
    {
        $resolus = [];

        foreach (array_keys(DataImportService::formats()) as $format) {
            $resolus[] = IoFactory::parserPour($format)->format();
        }

        $this->assertSame(['csv', 'xlsx', 'ods', 'json', 'xml'], $resolus);
    }

    public function test_registres_exportables_et_consultables_coherents(): void
    {
        $importables = DefinitionRegistry::entites();
        $exportables = DefinitionRegistry::exportables();
        $consultables = DefinitionRegistry::consultables();

        foreach ($exportables as $entite) {
            $this->assertContains($entite, $consultables);
        }

        foreach ($importables as $entite) {
            $this->assertContains($entite, $consultables);
        }

        // Les entités export-seules sont servies par l'API (LOOP 3).
        foreach (array_diff($exportables, $importables) as $entite) {
            $this->assertNotEmpty(app(DefinitionRegistry::class)->export($entite)->formats());
        }
    }

    public function test_message_format_invalide_mentionne_tous_les_formats_acceptes(): void
    {
        $message = IoException::formatInvalide('xls')->getMessage();

        foreach (['CSV', 'JSON', 'XML', 'XLSX', 'ODS'] as $format) {
            $this->assertStringContainsString($format, $message, 'format accepté absent du message');
        }
    }

    public function test_plafond_lignes_affiche_des_lignes_pas_des_moctets(): void
    {
        $message = IoException::tropDeLignes(DataImportService::LIGNES_MAX)->getMessage();

        $this->assertStringContainsString((string) DataImportService::LIGNES_MAX, $message);
        $this->assertStringContainsStringIgnoringCase('ligne', $message);
        $this->assertStringNotContainsString('Mo', $message);
    }

    public function test_plafond_lignes_import_remonte_un_message_en_lignes(): void
    {
        $lignes = ['nom,prenom'];

        for ($i = 0; $i <= DataImportService::LIGNES_MAX; $i++) {
            $lignes[] = "Etudiant{$i},Prenom{$i}";
        }

        try {
            app(CsvParser::class);
            app(DataImportService::class)->analyser(
                $this->fichier(implode("\n", $lignes)),
                'etudiants',
                null
            );
            $this->fail('Le plafond de lignes aurait dû être refusé.');
        } catch (IoException $e) {
            $this->assertStringContainsStringIgnoringCase('ligne', $e->getMessage());
        }
    }

    public function test_csv_windows1252_traverse_sans_transcodage_limite_connue(): void
    {
        // LIMITE CONNUE (cible PHASE 2 — prédiagnostic/ImportFileInspector) :
        // le pipeline ne transcode pas ; l'octet 0xE9 (é en Windows-1252)
        // ressort tel quel (UTF-8 invalide en aval). Ce test fige le
        // comportement actuel pour interdire toute régression silencieuse.
        $chemin = tempnam(sys_get_temp_dir(), 'contrat').'.csv';
        file_put_contents($chemin, "nom,note\nCaf\xe9,12\n");
        $fichier = new UploadedFile($chemin, 'contrat.csv', 'text/csv', null, true);

        $lignes = app(CsvParser::class)->parse($fichier);

        $this->assertSame("Caf\xe9", $lignes[0]['nom']);
    }

    public function test_route_export_web_limitee_aux_exportables(): void
    {
        $route = Route::getRoutes()->getByName('admin.donnees.export');

        $this->assertNotNull($route);
        $this->assertSame('csv|xlsx|json|pdf', $route->wheres['format'] ?? null);
    }
}
