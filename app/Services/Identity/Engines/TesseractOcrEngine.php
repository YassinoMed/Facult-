<?php

namespace App\Services\Identity\Engines;

use App\Services\Identity\OcrEngineInterface;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Moteur OCR réel : Tesseract (§1/§4) — branché sur l'INTERFACE
 * exactement comme le placeholder NullOcrEngine, via la configuration :
 *
 *   IDENTITY_OCR_DRIVER=tesseract
 *
 * Exigences système :
 *   - binaire `tesseract` (macOS : brew install tesseract) ;
 *   - données de langue : `ara` (arabe) et `fra` (français) dans
 *     tessdata — cf. docs/IDENTITY-SCAN.md ;
 *   - PDF : conversion de la première page via `sips` (macOS).
 *
 * Règles absolues :
 * - fail-soft : binaire absent, données de langue manquantes,
 *   conversion impossible, timeout → '' (la saisie manuelle reste
 *   le parcours de repli, JAMAIS d'exception vers l'utilisateur) ;
 * - arguments passés en TABLEAU au Process (aucun shell, aucun
 *   échappement à gérer : aucune injection possible) ;
 * - le chemin analysé est TOUJOURS notre fichier temporaire privé
 *   (nom UUID généré serveur), jamais un nom fourni par le client.
 */
class TesseractOcrEngine implements OcrEngineInterface
{
    /*
     * Les codes de langue sont résolus par les SERVICES APPELANTS via
     * config/ocr.php (§3 — jamais codés en dur dans le moteur).
     * Le moteur reçoit déjà un code Tesseract ('ara', 'ara+eng'…).
     */

    public function rawText(string $filePath, string $langue = OcrEngineInterface::LANGUE_ARABE_LATIN, ?int $psm = null): string
    {
        // Binaire configuré introuvable → fail-soft immédiat
        $binaire = (string) config('identity.ocr.tesseract.binaire', 'tesseract');
        $probe = $this->executer([$binaire, '--version'], 10);

        if ($probe === null || ! $probe->successful()) {
            return '';
        }

        $image = $this->preparerImage($filePath);

        if ($image === null) {
            return ''; // PDF non convertible ou image illisible
        }

        try {
            $codeLangue = $langue !== OcrEngineInterface::LANGUE_ARABE_LATIN && $langue !== ''
                ? $langue
                : (string) config('ocr.cin_language', 'ara+eng');

            $resultat = $this->executer([
                $binaire,
                $image,
                'stdout',
                '-l', $codeLangue,
                '--psm', (string) ($psm ?? config('identity.ocr.tesseract.psm', 3)),
            ], (int) config('identity.ocr.timeout', 30));

            return $resultat !== null && $resultat->successful()
                ? trim((string) $resultat->output())
                : '';
        } finally {
            // Le PNG de conversion (s'il y en avait un) est supprimé
            // quoi qu'il arrive — le fichier original est géré par le
            // cycle de vie du service (§13 option A).
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
     * Tesseract ne lit pas les PDF : conversion de la première page
     * en PNG — pdftoppm (poppler, qualité 300 dpi) en priorité, repli
     * sips (macOS). Retourne null en cas d'échec (fail-soft).
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
