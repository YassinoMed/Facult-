<?php

namespace Tests\Feature;

use App\Services\DataExchange\ExportRegistry;
use App\Services\DataExchange\ImportFileInspector;
use App\Services\DataExchange\ImportRegistry;
use App\Services\DataExchange\ValueNormalizer;
use App\Services\Io\IoException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 17 §17.8 : centre import/export (handlers, inspection,
 * normalisation, masquage, cas rares avec fixtures inline).
 */
class ImportCenterPhase17Test extends TestCase
{
    use RefreshDatabase;

    public function test_registre_handlers_sans_duplication(): void
    {
        $registre = app(ImportRegistry::class);

        $this->assertContains('enseignants', $registre->entites());

        $handler = $registre->handler('enseignants');
        $this->assertSame('enseignants', $handler->entite());
        $this->assertContains('nom', $handler->champs());
        $this->assertContains('nom', $handler->champsRequis());
    }

    public function test_inspection_xxe_refuse_et_mojibake_signale(): void
    {
        $inspecteur = app(ImportFileInspector::class);

        $this->expectException(IoException::class);

        try {
            $inspecteur->inspecter('<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY xxe "pwn">]><row/>', 'import.xml');
        } catch (IoException $e) {
            $this->assertStringContainsString('XXE', $e->getMessage());
            throw $e;
        }
    }

    public function test_inspection_mojibake_et_delimiteur(): void
    {
        $resultat = app(ImportFileInspector::class)->inspecter(
            "nom;prÃ©nom\nBenali;Sara\n", 'etudiants.csv'
        );

        $this->assertSame(';', $resultat['delimiteur']);
        $this->assertNotEmpty($resultat['avertissements']);
    }

    public function test_normalizer_chiffres_arabes_et_dates(): void
    {
        $this->assertSame('2026', ValueNormalizer::chiffresLatins('٢٠٢٦'));

        $excel = ValueNormalizer::date('45000');
        $this->assertNotNull($excel['date']);
        $this->assertStringContainsString('Excel', (string) $excel['avertissement']);

        $arabe = ValueNormalizer::date('٣ أبريل 2026');
        $this->assertSame('2026-04-03', $arabe['date']);

        $ambigue = ValueNormalizer::date('03/04/2026');
        $this->assertSame('2026-04-03', $ambigue['date']);
        $this->assertNotNull($ambigue['avertissement']);
    }

    public function test_normalizer_notes_et_telephones_et_formules(): void
    {
        $abs = ValueNormalizer::nombre('ABS');
        $this->assertSame('absent', $abs['valeur']);

        $virgule = ValueNormalizer::nombre('12,5');
        $this->assertSame(12.5, $virgule['valeur']);

        $this->assertSame('+21620123456', ValueNormalizer::telephone('20 123 456'));
        $this->assertSame("'=1+1", ValueNormalizer::celluleExport('=1+1'));
        $this->assertSame("'+cmd", ValueNormalizer::celluleExport('+cmd'));
    }

    public function test_export_masque_colonnes_sensibles(): void
    {
        $registre = app(ExportRegistry::class);

        $plein = $registre->colonnesAutorisees('enseignants', ['nom', 'cin', 'telephone'], true);
        $this->assertContains('cin', $plein);

        $restreint = $registre->colonnesAutorisees('enseignants', ['nom', 'cin', 'telephone'], false);
        $this->assertContains('nom', $restreint);
        $this->assertNotContains('cin', $restreint);
        $this->assertNotContains('telephone', $restreint);
    }

    public function test_normalizer_texte_nettoie_invisibles(): void
    {
        $sale = "Mohamed\xE2\x80\x8B  Ben\xC2\xA0Ahmed";
        $propre = ValueNormalizer::texte($sale);

        $this->assertSame('Mohamed Ben Ahmed', $propre);
    }
}
