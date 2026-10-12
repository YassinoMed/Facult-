<?php

namespace Tests\Feature\Security;

use App\Services\Io\CsvParser;
use App\Services\Io\CsvSerializer;
use App\Services\Io\ImportOrchestrator;
use App\Services\Io\IoException;
use App\Services\Io\JsonParser;
use App\Services\Io\XmlParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * Sécurité imports/exports (§21-24, §54) : parsers plafonnés, XXE refusé,
 * MIME cohérent, doublons, rollback.
 */
class ImportExportSecurityTest extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    private function fichier(string $nom, string $contenu, string $mime): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nom, $contenu, $mime);
    }

    public function test_csv_plafond_lignes(): void
    {
        config(['integrations.import_max_rows' => 5]);

        $contenu = "code;nom\n".implode("\n", array_map(
            fn ($i) => "B{$i};Salle {$i}",
            range(1, 6)
        ))."\n";

        $this->expectException(IoException::class);

        app(CsvParser::class)->parse($this->fichier('s.csv', $contenu, 'text/csv'));
    }

    public function test_csv_plafond_colonnes(): void
    {
        $entetes = implode(';', array_map(fn ($i) => "col{$i}", range(1, 201)));
        $valeurs = implode(';', array_fill(0, 201, 'x'));

        $this->expectException(IoException::class);

        app(CsvParser::class)->parse($this->fichier('s.csv', "{$entetes}\n{$valeurs}\n", 'text/csv'));
    }

    public function test_json_plafond_enregistrements(): void
    {
        config(['integrations.import_max_rows' => 5]);

        $contenu = json_encode(array_map(
            fn ($i) => ['code' => "B{$i}"],
            range(1, 6)
        ));

        $this->expectException(IoException::class);

        app(JsonParser::class)->parse($this->fichier('s.json', $contenu, 'application/json'));
    }

    public function test_xml_plafond_enregistrements(): void
    {
        config(['integrations.import_max_rows' => 5]);

        $rows = implode('', array_map(
            fn ($i) => "<row><code>B{$i}</code></row>",
            range(1, 6)
        ));

        $this->expectException(IoException::class);

        app(XmlParser::class)->parse(
            $this->fichier('s.xml', "<?xml version=\"1.0\"?><rows>{$rows}</rows>", 'application/xml')
        );
    }

    public function test_xml_xxe_billion_laughs_refuse(): void
    {
        $malveillant = <<<'XML'
            <?xml version="1.0"?>
            <!DOCTYPE lolz [
              <!ENTITY lol "lololololol">
              <!ENTITY lol2 "&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;">
            ]>
            <rows><row><code>&lol2;</code></row></rows>
            XML;

        $this->expectException(IoException::class);

        app(XmlParser::class)->parse($this->fichier('evil.xml', $malveillant, 'application/xml'));
    }

    public function test_xml_ssrf_externe_refuse(): void
    {
        $malveillant = <<<'XML'
            <?xml version="1.0"?>
            <!DOCTYPE foo [<!ENTITY xxe SYSTEM "http://169.254.169.254/latest/meta-data/">]>
            <rows><row><code>&xxe;</code></row></rows>
            XML;

        $this->expectException(IoException::class);

        app(XmlParser::class)->parse($this->fichier('evil.xml', $malveillant, 'application/xml'));
    }

    public function test_orchestrateur_refuse_mime_incoherent(): void
    {
        $this->scolarite('admin_scolarite');

        // Vrai fichier temporaire (finfo réel) : contenu PDF binaire avec
        // extension .csv — la cohérence MIME doit rejeter avant tout parse.
        // (UploadedFile::fake()->createWithContent ignore le MIME : inutilisable ici.)
        $chemin = tempnam(sys_get_temp_dir(), 'dx').'.csv';
        file_put_contents($chemin, "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n1 0 obj\n<< /Type /Catalog >>\n");
        $fichier = new UploadedFile($chemin, 'salles.csv', null, null, true);

        $this->assertSame('application/pdf', $fichier->getMimeType());

        try {
            $this->expectException(IoException::class);

            app(ImportOrchestrator::class)->preparer($fichier, 'students', 1, 'web');
        } finally {
            @unlink($chemin);
        }
    }

    public function test_export_csv_neutralise_formules(): void
    {
        $contenu = app(CsvSerializer::class)->serialize(
            ['code', 'nom'],
            [['code' => '=CMD|calc', 'nom' => '@exfiltration']]
        );

        $this->assertStringContainsString("'=CMD|calc", $contenu);
        $this->assertStringContainsString("'@exfiltration", $contenu);
    }
}
