<?php

namespace App\Services\Identity\Engines;

use App\Services\Identity\OcrEngineInterface;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Moteur OCR PaddleOCR — RapidOCR/ONNX Runtime (§1/§4) — branché sur
 * l'INTERFACE exactement comme les autres drivers, via la configuration :
 *
 *   IDENTITY_OCR_DRIVER=ppocr
 *
 * Modèles (gabarit « server » par défaut — cf. identity.ocr.ppocr.modele) :
 *   - détection zones de texte : PP-OCRv5_server_det      (~101 Mo)
 *   - reconnaissance latin/en  : PP-OCRv6_rec_medium      (multilingue)
 *   - reconnaissance arabe      : arabic_PP-OCRv5_rec_mobile (~16 Mo —
 *     seul modèle arabe disponible)
 *   Gabarit « mobile » (IDENTITY_PPOCR_MODELE=mobile) : PP-OCRv5 mobile
 *   partout, le plus rapide (~2-4 s/appel). Langues résolues depuis
 *   config/identity.php → ocr.ppocr.langues.
 *
 * Exigences système (cf. docs/IDENTITY-SCAN.md) :
 *   - Python 3 + venv : scripts/ocr/.venv (pip install -r requirements.txt) ;
 *   - runner : scripts/ocr/ppocr_runner.py ;
 *   - PDF : conversion 1re page via pdftoppm (repli sips macOS).
 *
 * Règles absolues — IDENTIQUES au driver tesseract :
 * - fail-soft : python/venv/script absent, modèle indisponible,
 *   timeout → '' (JAMAIS d'exception — saisie manuelle en repli) ;
 * - arguments passés en TABLEAU au Process (aucune injection possible) ;
 * - le chemin analysé est TOUJOURS le temporaire privé UUID serveur ;
 * - $psm est ignoré (spécifique Tesseract) : le pipeline zones (§5) de
 *   CinOcrService fonctionne tel quel — chaque zone est ocr-isée seule.
 */
class PpocrOcrEngine implements OcrEngineInterface
{
    public function rawText(string $filePath, string $langue = OcrEngineInterface::LANGUE_ARABE_LATIN, ?int $psm = null): string
    {
        $script = (string) config('identity.ocr.ppocr.script', '');

        // Runner introuvable → fail-soft immédiat
        if ($script === '' || ! is_file($script)) {
            return '';
        }

        $commande = $this->commandePython();

        if ($commande === []) {
            return ''; // python non configuré → saisie manuelle (fail-soft)
        }

        $image = $this->preparerImage($filePath);

        if ($image === null) {
            return ''; // PDF non convertible ou image illisible
        }

        try {
            $resultat = $this->executer([
                ...$commande,
                $script,
                $image,
                $this->resoudreLangue($langue),
                $this->resoudreModele(),
            ], (int) config('identity.ocr.ppocr.timeout', 180));

            return $resultat !== null && $resultat->successful()
                ? trim((string) $resultat->output())
                : '';
        } finally {
            // Le PNG de conversion (s'il y en avait un) est supprimé
            // quoi qu'il arrive — §13 option A.
            if ($image !== $filePath && file_exists($image)) {
                @unlink($image);
            }
        }
    }

    /**
     * Exécute une commande en TABLEAU via Process en absorbant TOUT
     * échec létal : timeout et crash signalé LÈVENT (ProcessTimedOut-
     * Exception / ProcessSignaledException) — le contrat fail-soft
     * interdit à rawText de lever quoi qu'il arrive.
     *
     * @param  array<int, string>  $commande
     */
    private function executer(array $commande, int $delai): ?ProcessResult
    {
        try {
            return Process::timeout($delai)->run($commande);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Commande de lancement du venv Python, en TABLEAU (aucun shell).
     *
     * macOS / Apple Silicon : un PHP x86_64 (Homebrew /usr/local sous
     * Rosetta) exécuterait Python en x86_64 alors que les wheels du venv
     * sont arm64 → dlopen impossible. On force donc la tranche native
     * via `arch -arm64` (config identity.ocr.ppocr.arch, vide hors Mac).
     */
    private function commandePython(): array
    {
        $python = (string) config('identity.ocr.ppocr.python', '');

        if ($python === '') {
            return [];
        }

        $arch = trim((string) config('identity.ocr.ppocr.arch', ''));

        return $arch !== ''
            ? ['/usr/bin/arch', '-'.$arch, $python]
            : [$python];
    }

    /**
     * Code langue applicatif (config/ocr.php : 'ara+eng', 'eng+fra',
     * zones cin_tunisia : 'ara'…) → langue de reconnaissance RapidOCR
     * (en | latin | arabic | ch) — table centralisée en config (§3).
     */
    private function resoudreLangue(string $langue): string
    {
        $correspondance = (array) config('identity.ocr.ppocr.langues', []);

        return (string) (
            $correspondance[$langue]
            ?? config('identity.ocr.ppocr.langue_defaut', 'arabic')
        );
    }

    /**
     * Gabarit de modèles RapidOCR (§3) : « server » (défaut — précision
     * maximale) ou « mobile » (historique, plus rapide). Toute valeur
     * inconnue retombe sur « server ».
     */
    private function resoudreModele(): string
    {
        $modele = strtolower((string) config('identity.ocr.ppocr.modele', 'server'));

        return $modele === 'mobile' ? 'mobile' : 'server';
    }

    /**
     * PP-OCRv5 ne lit pas les PDF : conversion de la première page en
     * PNG — pdftoppm (poppler, 300 dpi) en priorité, repli sips (macOS).
     * Retourne null en cas d'échec (fail-soft).
     */
    private function preparerImage(string $filePath): ?string
    {
        if (strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION)) !== 'pdf') {
            return $filePath;
        }

        $cible = dirname($filePath).'/'.Str::uuid()->toString().'.png';

        // 1) pdftoppm (poppler) : rendu 300 dpi de la 1re page
        $pdftoppm = $this->executer([
            'pdftoppm', '-png', '-r', '300', '-singlefile',
            $filePath, substr($cible, 0, -4),
        ], 60);

        if ($pdftoppm !== null && $pdftoppm->successful() && file_exists($cible)) {
            return $cible;
        }

        // 2) Repli macOS : sips
        $sips = (string) config('identity.ocr.tesseract.sips', 'sips');
        $conversion = $this->executer([
            $sips, '-s', 'format', 'png', $filePath, '--out', $cible,
        ], 30);

        return $conversion !== null && $conversion->successful() && file_exists($cible)
            ? $cible
            : null;
    }
}
