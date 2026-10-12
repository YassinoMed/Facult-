<?php

namespace App\Services\Identity\Engines;

use App\Services\Identity\OcrEngineInterface;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Moteur OCR « VLM Hugging Face » — Qwen3-VL-4B-Instruct en MLX 4-bit
 * (§1/§4) — branché sur l'INTERFACE exactement comme les autres
 * drivers, via la configuration :
 *
 *   IDENTITY_OCR_DRIVER=hfvlm + IDENTITY_HFVLM_ENABLED=true
 *
 * (l'IA est DÉSACTIVÉE par défaut : sans le flag explicite, rawText()
 * rend '' immédiatement — aucun téléchargement/exécution de modèle).
 *
 * Modèle par défaut : mlx-community/Qwen3-VL-4B-Instruct-4bit (~2,6 Go,
 * multilingue dont arabe, transcription exacte ligne par ligne) — ▶
 * nettement plus précis sur documents photographiés qu'un OCR à
 * segments, mais chaque appel recharge le modèle (~20-60 s) :
 * privilégier pour le passeport/pleine image ; le pipeline CIN
 * multi-zones (§5) fonctionne tel quel, simplement plus lent
 * (voir IDENTITY_HFVLM_TIMEOUT).
 *
 * Exigences système (cf. docs/IDENTITY-SCAN.md) :
 *   - venv scripts/ocr/.venv + `pip install mlx-vlm torchvision` ;
 *   - Apple Silicon : exécuté via `arch -arm64` (MLX est natif arm64 —
 *     les PHP x86_64 sous Rosetta ne peuvent pas charger les wheels) ;
 *   - macOS/Linux x86_64 : prévoir un runtime alternatif (transformers+torch)
 *     et ajuster `hfvlm.python`.
 *
 * Règles absolues — IDENTIQUES aux autres drivers :
 * - fail-soft : venv/script/modèle absent, erreur runner, timeout → ''
 *   (JAMAIS d'exception — saisie manuelle en repli) ;
 * - arguments passés en TABLEAU au Process (aucune injection possible) ;
 * - le chemin analysé est TOUJOURS le temporaire privé UUID serveur ;
 * - $psm est ignoré (spécifique Tesseract) — le pipeline zones (§5) de
 *   CinOcrService fonctionne sans modification.
 */
class HfVlmOcrEngine implements OcrEngineInterface
{
    public function rawText(string $filePath, string $langue = OcrEngineInterface::LANGUE_ARABE_LATIN, ?int $psm = null): string
    {
        // IA désactivée par défaut (IDENTITY_HFVLM_ENABLED=false) :
        // aucun appel modèle, fail-soft immédiat vers la saisie manuelle.
        if (! config('identity.ocr.hfvlm.active', false)) {
            return '';
        }

        $script = (string) config('identity.ocr.hfvlm.script', '');

        // Runner introuvable → fail-soft immédiat
        if ($script === '' || ! is_file($script)) {
            return '';
        }

        $commande = $this->commandePython();
        $sonde = $commande === []
            ? null
            : $this->executer([...$commande, '-c', 'import mlx_vlm'], 20);

        if ($sonde === null || ! $sonde->successful()) {
            return ''; // venv/mlx-vlm non installé → saisie manuelle (fail-soft)
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
                (string) config('identity.ocr.hfvlm.modelle', ''),
            ], (int) config('identity.ocr.hfvlm.timeout', 300));

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
     * Rosetta) exécuterait Python en x86_64 alors que MLX et les wheels
     * du venv sont arm64 → dlopen impossible. On force donc la tranche
     * native via `arch -arm64` (config identity.ocr.hfvlm.arch, vide hors Mac).
     */
    private function commandePython(): array
    {
        $python = (string) config('identity.ocr.hfvlm.python', '');

        if ($python === '') {
            return [];
        }

        $arch = trim((string) config('identity.ocr.hfvlm.arch', ''));

        return $arch !== ''
            ? ['/usr/bin/arch', '-'.$arch, $python]
            : [$python];
    }

    /**
     * Code langue applicatif (config/ocr.php : 'ara+eng', 'eng+fra',
     * zones cin_tunisia : 'ara'…) → langue naturelle du prompt VLM —
     * table centralisée en config (§3).
     */
    private function resoudreLangue(string $langue): string
    {
        $correspondance = (array) config('identity.ocr.hfvlm.langues', []);

        return (string) (
            $correspondance[$langue]
            ?? config('identity.ocr.hfvlm.langue_defaut', 'français')
        );
    }

    /**
     * Le VLM ne lit pas les PDF : conversion de la première page en
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
