<?php

namespace Tests\Feature;

use App\Services\Identity\Engines\PpocrOcrEngine;
use App\Services\Identity\IdentityDocumentService;
use App\Services\Identity\OcrEngineInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Moteur OCR PaddleOCR (driver `ppocr` — RapidOCR/ONNX) : gabarit
 * « server » par défaut — det PP-OCRv5 server + rec PP-OCRv6 medium
 * (latin/en) / arabic v5 mobile (§1/§4).
 *
 * - fail-soft GARANTI : script/python absent, erreur runner, timeout → ''
 *   (la saisie manuelle reste le repli — jamais d'exception) ;
 * - intégration RÉELLE esquivée proprement si le venv scripts/ocr n'est
 *   pas installé sur la machine d'exécution (cf. docs/IDENTITY-SCAN.md).
 */
class PpocrEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_script_absent_rend_une_chaine_vide_sans_erreur(): void
    {
        config(['identity.ocr.ppocr.script' => '/nimporte/ou/ppocr_runner.py']);

        $engine = new PpocrOcrEngine;

        // Fail-soft §19 : aucun texte, AUCUNE exception
        $this->assertSame('', $engine->rawText('/nimporte/quoi.png', 'ara+lat'));
    }

    public function test_python_absent_rend_une_chaine_vide_sans_erreur(): void
    {
        config(['identity.ocr.ppocr.python' => '/venvs/inexistant/bin/python']);

        $engine = new PpocrOcrEngine;

        $this->assertSame('', $engine->rawText(/** fichier inutile ici */ '/x.png', 'eng'));
    }

    public function test_runner_tue_par_signal_rend_une_chaine_vide_sans_erreur(): void
    {
        // Venv OK (sonde --version) mais le runner crashe : Process::run
        // LÈVE ProcessSignaledException — le contrat fail-soft (§19)
        // exige '' sans exception (absorbée par executer()).
        $script = tempnam(sys_get_temp_dir(), 'ocr').'.py';
        file_put_contents($script, "import os, signal\nos.kill(os.getpid(), signal.SIGABRT)\n");

        config(['identity.ocr.ppocr.script' => $script]);

        $engine = new PpocrOcrEngine;

        $this->assertSame('', $engine->rawText('/x.png', 'eng'));

        @unlink($script);
    }

    public function test_runner_en_timeout_rend_une_chaine_vide_sans_erreur(): void
    {
        // Process::run LÈVE ProcessTimedOutException : absorbée → ''.
        $script = tempnam(sys_get_temp_dir(), 'ocr').'.py';
        file_put_contents($script, "import time\ntime.sleep(30)\n");

        config([
            'identity.ocr.ppocr.script' => $script,
            'identity.ocr.ppocr.timeout' => 2,
        ]);

        $engine = new PpocrOcrEngine;

        $this->assertSame('', $engine->rawText('/x.png', 'eng'));

        @unlink($script);
    }

    public function test_pdf_non_convertible_ne_plante_jamais(): void
    {
        $pdf = tempnam(sys_get_temp_dir(), 'cin').'.pdf';
        file_put_contents($pdf, "%PDF-1.4\n%%EOF\n");

        $engine = new PpocrOcrEngine;
        $texte = $engine->rawText($pdf, 'eng');

        $this->assertIsString($texte);

        unlink($pdf);
    }

    public function test_pipeline_complet_reel_ocr_vers_prefill(): void
    {
        $python = (string) config('identity.ocr.ppocr.python');

        if (! file_exists($python)) {
            $this->markTestSkipped('Venv scripts/ocr non installé — test d\'intégration esquivé (docs/IDENTITY-SCAN.md).');
        }

        // Ce test valide LE DRIVER PPOCR : explicite, indépendant de
        // IDENTITY_OCR_DRIVER du .env.
        $this->app->bind(OcrEngineInterface::class, PpocrOcrEngine::class);

        // Image CIN générée à la volée (zone latine nette, grande police
        // pour la détection PP-OCRv5 mobile)
        $image = imagecreatetruecolor(1200, 700);
        imagefill($image, 0, 0, imagecolorallocate($image, 250, 250, 250));
        $noir = imagecolorallocate($image, 20, 20, 20);

        $lignes = [
            'REPUBLIQUE TUNISIENNE',
            'CARTE DIDENTITE NATIONALE',
            'CIN : 12345678',
            'Nom : BEN ALI',
            'Prenom : Mohamed',
            'Ne(e) le : 15/02/2002',
        ];

        // GD imagestring(5) ≈ 9 px — trop petit pour la détection :
        // on multiplie par 4 via imagecopyresampled.
        $x4 = imagecreatetruecolor(1200, 700);
        imagefill($x4, 0, 0, imagecolorallocate($x4, 250, 250, 250));
        $petite = imagecreatetruecolor(300, 175);
        imagefill($petite, 0, 0, imagecolorallocate($petite, 250, 250, 250));

        $y = 10;
        foreach ($lignes as $ligne) {
            imagestring($petite, 5, 10, $y, $ligne, $noir);
            $y += 27;
        }

        imagecopyresampled($x4, $petite, 0, 0, 0, 0, 1200, 700, 300, 175);
        imagedestroy($petite);
        imagedestroy($image);

        $cheminPng = tempnam(sys_get_temp_dir(), 'cin').'.png';
        imagepng($x4, $cheminPng);
        imagedestroy($x4);

        Storage::fake('local');

        $service = app(IdentityDocumentService::class);
        $fichier = new UploadedFile($cheminPng, 'cin.png', 'image/png', null, true);

        $chemin = $service->uploadDocument($fichier, 'cin');

        try {
            $resultat = $service->extractData($chemin, 'cin');
        } finally {
            $service->supprimerDocument($chemin);
            @unlink($cheminPng);
        }

        // Le moteur RÉEL a lu le document : les champs latins majeurs
        // sont extraits — le nom est comparé normalisé (majuscules, sans
        // espaces) : un espace parasite inter-mot est du bruit OCR
        // toléré (§8 — l'OCR n'est jamais fiable à 100 %).
        $this->assertSame('12345678', $resultat->documentNumber);
        $this->assertSame('2002-02-15', $resultat->dateOfBirth);
        $this->assertSame('BENALI', strtoupper(str_replace(' ', '', (string) $resultat->lastName)));
    }
}
