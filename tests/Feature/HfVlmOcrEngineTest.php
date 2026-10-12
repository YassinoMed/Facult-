<?php

namespace Tests\Feature;

use App\Services\Identity\Engines\HfVlmOcrEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Moteur OCR « modèle IA HF » (driver `hfvlm` — Qwen3-VL-4B-Instruct en
 * MLX 4-bit via scripts/ocr/hf_runner.py).
 *
 * - fail-soft GARANTI : script/python/mlx-vlm absent, erreur runner,
 *   timeout → '' (la saisie manuelle reste le repli — jamais d'exception) ;
 * - intégration RÉELLE esquivée proprement si le venv scripts/ocr (ou le
 *   modèle HF) n'est pas installé sur la machine d'exécution — le modèle
 *   coûte ~20-60 s par appel : on teste le CONTRAT (rawText), pas le
 *   pipeline CIN multi-zones complet (trop long pour la suite — §19).
 */
class HfVlmOcrEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_ia_desactivee_par_defaut_rend_chaine_vide_sans_appel_modele(): void
    {
        // Interrupteur IA : même avec un runner présent, désactivé →
        // '' immédiat, aucun processus Python lancé.
        config(['identity.ocr.hfvlm.active' => false]);

        $engine = new HfVlmOcrEngine;

        $this->assertSame('', $engine->rawText('/nimporte/quoi.png', 'ara+lat'));
        $this->assertFalse((bool) config('identity.ocr.hfvlm.active'));
    }

    public function test_script_absent_rend_une_chaine_vide_sans_erreur(): void
    {
        config([
            'identity.ocr.hfvlm.active' => true,
            'identity.ocr.hfvlm.script' => '/nimporte/ou/hf_runner.py',
        ]);

        $engine = new HfVlmOcrEngine;

        // Fail-soft §19 : aucun texte, AUCUNE exception
        $this->assertSame('', $engine->rawText('/nimporte/quoi.png', 'ara+lat'));
    }

    public function test_python_sans_mlx_vlm_rend_une_chaine_vide_sans_erreur(): void
    {
        // Interpréteur valide mais SANS mlx-vlm (php artisan sert de probe)
        config([
            'identity.ocr.hfvlm.active' => true,
            'identity.ocr.hfvlm.python' => '/usr/bin/false',
        ]);

        $engine = new HfVlmOcrEngine;

        $this->assertSame('', $engine->rawText('/x.png', 'eng'));
    }

    public function test_runner_tue_par_signal_rend_une_chaine_vide_sans_erreur(): void
    {
        // Venv OK (sonde import mlx_vlm) mais le runner crashe :
        // Process::run LÈVE ProcessSignaledException — le contrat
        // fail-soft (§19) exige '' sans exception (absorbée par executer()).
        $script = tempnam(sys_get_temp_dir(), 'ocr').'.py';
        file_put_contents($script, "import os, signal\nos.kill(os.getpid(), signal.SIGABRT)\n");

        config([
            'identity.ocr.hfvlm.active' => true,
            'identity.ocr.hfvlm.script' => $script,
        ]);

        $engine = new HfVlmOcrEngine;

        $this->assertSame('', $engine->rawText('/x.png', 'eng'));

        @unlink($script);
    }

    public function test_runner_en_timeout_rend_une_chaine_vide_sans_erreur(): void
    {
        // Process::run LÈVE ProcessTimedOutException : absorbée → ''.
        $script = tempnam(sys_get_temp_dir(), 'ocr').'.py';
        file_put_contents($script, "import time\ntime.sleep(30)\n");

        config([
            'identity.ocr.hfvlm.active' => true,
            'identity.ocr.hfvlm.script' => $script,
            'identity.ocr.hfvlm.timeout' => 2,
        ]);

        $engine = new HfVlmOcrEngine;

        $this->assertSame('', $engine->rawText('/x.png', 'eng'));

        @unlink($script);
    }

    public function test_pdf_non_convertible_ne_plante_jamais(): void
    {
        config(['identity.ocr.hfvlm.active' => true]);

        $pdf = tempnam(sys_get_temp_dir(), 'cin').'.pdf';
        file_put_contents($pdf, "%PDF-1.4\n%%EOF\n");

        $engine = new HfVlmOcrEngine;
        $texte = $engine->rawText($pdf, 'eng');

        $this->assertIsString($texte);

        unlink($pdf);
    }

    public function test_transcription_reelle_dune_image_lisible(): void
    {
        // IA désactivée par défaut : le modèle réel (~2,6 Go, sortie non
        // déterministe, ~20-60 s/appel) ne tourne qu'avec le flag explicite.
        if (! config('identity.ocr.hfvlm.active', false)) {
            $this->markTestSkipped('Driver IA hfvlm désactivé (IDENTITY_HFVLM_ENABLED=false par défaut) — modèle non exécuté.');
        }

        $python = (string) config('identity.ocr.hfvlm.python');

        if (! file_exists($python)
            || ! Process::timeout(60)->run([
                '/usr/bin/arch', '-arm64', $python, '-c', 'import mlx_vlm',
            ])->successful()) {
            $this->markTestSkipped('Venv scripts/ocr (mlx-vlm) non installé — test d\'intégration esquivé (docs/IDENTITY-SCAN.md).');
        }

        // Image CIN générée à la volée (police gd ×4 pour la lisibilité VLM)
        $petite = imagecreatetruecolor(300, 175);
        imagefill($petite, 0, 0, imagecolorallocate($petite, 250, 250, 250));
        $noir = imagecolorallocate($petite, 20, 20, 20);

        $lignes = [
            'REPUBLIQUE TUNISIENNE',
            'CARTE DIDENTITE NATIONALE',
            'CIN : 12345678',
            'Nom : BEN ALI',
            'Prenom : Mohamed',
            'Ne(e) le : 15/02/2002',
        ];

        $y = 10;
        foreach ($lignes as $ligne) {
            imagestring($petite, 5, 10, $y, $ligne, $noir);
            $y += 27;
        }

        $image = imagecreatetruecolor(1200, 700);
        imagefill($image, 0, 0, imagecolorallocate($image, 250, 250, 250));
        imagecopyresampled($image, $petite, 0, 0, 0, 0, 1200, 700, 300, 175);
        imagedestroy($petite);

        $cheminPng = tempnam(sys_get_temp_dir(), 'cin').'.png';
        imagepng($image, $cheminPng);
        imagedestroy($image);

        $engine = new HfVlmOcrEngine;
        $texte = $engine->rawText($cheminPng, 'eng');

        @unlink($cheminPng);

        $texteNormalise = strtoupper(str_replace(' ', '', $texte));

        // Contrat : texte non vide contenant le numéro CIN et le nom —
        // comparaison normalisée (espaces) : le bruit d'accentuation et
        // la fusion de lignes sont tolérés (§8 — OCR jamais fiable à 100 %).
        $this->assertStringContainsString('12345678', $texteNormalise);
        $this->assertStringContainsString('BEN', $texteNormalise);
    }
}
