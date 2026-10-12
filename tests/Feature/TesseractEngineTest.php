<?php

namespace Tests\Feature;

use App\Models\LieuNaissance;
use App\Services\Identity\Engines\TesseractOcrEngine;
use App\Services\Identity\IdentityDocumentService;
use App\Services\Identity\OcrEngineInterface;
use Database\Seeders\LieuNaissanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Moteur OCR Tesseract (§1/§4) : le driver réel branché sur l'interface.
 *
 * - fail-soft GARANTI : binaire absent/timeout/erreur → '' (la saisie
 *   manuelle reste le repli — jamais d'exception vers l'utilisateur) ;
 * - intégration RÉELLE (OCR → parseur → normalisation → payload) sur
 *   une image CIN générée à la volée — esquivée proprement si le
 *   binaire `tesseract` n'est pas installé sur la machine d'exécution.
 */
class TesseractEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_binaire_absent_rend_une_chaine_vide_sans_erreur(): void
    {
        config(['identity.ocr.tesseract.binaire' => '/binaire/inexistant-tesseract']);

        $engine = new TesseractOcrEngine;

        // Fail-soft §19 : aucun texte, AUCUNE exception
        $this->assertSame('', $engine->rawText('/nimporte/quoi.png', 'ara+lat'));
    }

    public function test_pdf_est_converti_et_le_pipeline_ne_plante_jamais(): void
    {
        // Sans tesseract la conversion/lecture peut échouer : '' attendu,
        // sans exception — fail-soft.
        $pdf = tempnam(sys_get_temp_dir(), 'cin').'.pdf';
        file_put_contents($pdf, "%PDF-1.4\n%%EOF\n");

        $engine = new TesseractOcrEngine;
        $texte = $engine->rawText($pdf, 'ara+lat');

        $this->assertIsString($texte);

        unlink($pdf);
    }

    public function test_pipeline_complet_reel_ocr_vers_prefill(): void
    {
        if (! Process::timeout(10)->run(['tesseract', '--version'])->successful()) {
            $this->markTestSkipped('Binaire `tesseract` non installé — test d\'intégration esquivé.');
        }

        // Ce test valide LE DRIVER TESSERACT : explicite, indépendant de
        // IDENTITY_OCR_DRIVER du .env (ppocr est désormais le défaut).
        $this->app->bind(OcrEngineInterface::class, TesseractOcrEngine::class);

        // Données de langue minimales pour le pipeline
        $this->seed(LieuNaissanceSeeder::class);

        // Image CIN générée à la volée (zone latine lisible par OCR)
        $image = imagecreatetruecolor(800, 500);
        imagefill($image, 0, 0, imagecolorallocate($image, 250, 250, 250));
        $noir = imagecolorallocate($image, 20, 20, 20);

        $lignes = [
            'REPUBLIQUE TUNISIENNE',
            'CARTE DIDENTITE NATIONALE',
            'CIN : 12345678',
            'Nom : BEN ALI',
            'Prenom : Mohamed',
            'Ne(e) le : 15/02/2002',
            'Lieu de naissance : Sfax',
            'Sexe : Masculin',
        ];

        $y = 40;
        foreach ($lignes as $ligne) {
            imagestring($image, 5, 60, $y, $ligne, $noir);
            $y += 48;
        }

        $cheminPng = tempnam(sys_get_temp_dir(), 'cin').'.png';
        imagepng($image, $cheminPng);
        imagedestroy($image);

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

        // Le moteur RÉEL a lu le document : extraction effective
        $this->assertSame('12345678', $resultat->documentNumber);
        $this->assertSame('BEN ALI', $resultat->lastName);
        $this->assertSame('MOHAMED', $resultat->firstName);
        $this->assertSame('2002-02-15', $resultat->dateOfBirth);
        $this->assertSame('M', $resultat->sex);
        $this->assertSame('SFAX', $resultat->placeOfBirth);

        // Et la table administrative des lieux est fonctionnelle
        $this->assertSame('Sfax', LieuNaissance::resoudreNomLatin('صفاقس'));
    }
}
