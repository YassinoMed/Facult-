<?php

use App\Services\Identity\Engines\ColabOcrEngine;
use App\Services\Identity\Engines\HfVlmOcrEngine;
use App\Services\Identity\Engines\NullOcrEngine;
use App\Services\Identity\Engines\PpocrOcrEngine;
use App\Services\Identity\Engines\TesseractOcrEngine;

return [

    /*
    |--------------------------------------------------------------------------
    | Scan de documents d'identité — CIN tunisienne / Passeport
    |--------------------------------------------------------------------------
    | Le document téléversé est TEMPORAIRE (§13 option A, minimisation) :
    | upload → OCR → extraction → SUPPRESSION immédiate du fichier.
    | Aucune pièce d'identité n'est archivée dans le dossier étudiant.
    */

    // Moteur OCR brut (texte) — interchangeable sans toucher au reste
    // de l'application. Ajouter un driver ici + son implémentation
    // OcrEngineInterface (ex : tesseract, cloud).
    'ocr' => [
        'driver' => env('IDENTITY_OCR_DRIVER', 'null'),
        'drivers' => [
            'null' => NullOcrEngine::class, // placeholder : aucun texte extrait
            'tesseract' => TesseractOcrEngine::class, // OCR réel local (ara+fra)
            'ppocr' => PpocrOcrEngine::class, // OCR PaddleOCR (RapidOCR/ONNX)
            'hfvlm' => HfVlmOcrEngine::class, // VLM Qwen3-VL-4B (MLX 4-bit)
            'colab' => ColabOcrEngine::class, // OCR distant via API FastAPI (Colab GPU)
        ],
        // Délai maximal du traitement OCR (secondes, null = sans limite)
        'timeout' => env('IDENTITY_OCR_TIMEOUT', 30),
        'tesseract' => [
            // Binaire (macOS : brew install tesseract + données de
            // langue ara/fra dans tessdata — cf. docs/IDENTITY-SCAN.md)
            'binaire' => env('IDENTITY_TESSERACT_BIN', 'tesseract'),
            // Langue par défaut des documents (CIN tunisienne : arabe+français)
            'langue_defaut' => env('IDENTITY_TESSERACT_LANGUE', 'ara+fra'),
            // Mode de segmentation de page (3 = automatique)
            'psm' => (int) env('IDENTITY_TESSERACT_PSM', 3),
            // Conversion PDF → PNG de la 1re page (macOS : sips)
            'sips' => env('IDENTITY_SIPS_BIN', 'sips'),
        ],
        // ── Driver PaddleOCR via RapidOCR/ONNX Runtime ──
        // Modèles (config « server » par défaut, meilleure précision) :
        // det PP-OCRv5_server (~101 Mo) + rec PP-OCRv6 medium (latin/en)
        // ou arabic PP-OCRv5 mobile (~16 Mo — seul modèle arabe).
        // Install : cf. docs/IDENTITY-SCAN.md.
        'ppocr' => [
            // Interpréteur du venv scripts/ocr (hors PATH autorisé via env)
            'python' => env('IDENTITY_PPOCR_PYTHON', base_path('scripts/ocr/.venv/bin/python')),
            // Tranche CPU forcée via `arch` (macOS Apple Silicon : un PHP
            // x86_64 sous Rosetta rendrait les wheels arm64 du venv
            // inutilisables — dlopen échoue). Vide = comportement natif.
            'arch' => env('IDENTITY_PPOCR_ARCH', PHP_OS_FAMILY === 'Darwin' ? 'arm64' : ''),
            // Runner RapidOCR appelé en tableau d'arguments par Process
            'script' => env('IDENTITY_PPOCR_SCRIPT', base_path('scripts/ocr/ppocr_runner.py')),
            // Délai par appel (le 1er lancement télécharge puis charge les
            // modèles ONNX « server » ~220 Mo)
            'timeout' => (int) env('IDENTITY_PPOCR_TIMEOUT', 180),
            // Gabarit de modèles : mobile (défaut — rapide : CIN
            // multi-zones = ~32 appels/scan, ~1-2 s/appel ; scan complet
            // ~1 min sur M2 8 Go) | server (précision max — det v5 server
            // + rec PP-OCRv6 medium latin/en : ~10-18 s/appel, scan CIN
            // ~9 min — réserver aux appels uniques pleine page)
            'modele' => env('IDENTITY_PPOCR_MODELE', 'mobile'),
            // Langue de reconnaissance si le code reçu est inconnu de la
            // table ci-dessous la CIN tunisienne est majoritairement arabe
            'langue_defaut' => env('IDENTITY_PPOCR_LANGUE', 'arabic'),
            // Codes applicatifs (config/ocr.php + zones cin_tunisia) →
            // langue rec RapidOCR : en | latin | arabic | ch
            //   en      = anglais/MRZ           (en_PP-OCRv5_mobile_rec)
            //   latin   = français accentué…    (latin_PP-OCRv5_mobile_rec)
            //   arabic  = arabe + chiffres      (arabic_PP-OCRv5_mobile_rec)
            'langues' => [
                'eng' => 'en',
                'fra' => 'latin',
                'lat' => 'latin',
                'latin' => 'latin',
                'eng+fra' => 'latin', // passeport : MRZ + mentions françaises
                'ara' => 'arabic',
                'ara+eng' => 'arabic',
                'ara+fra' => 'arabic',
                'ara+lat' => 'arabic',
            ],
        ],
        // ── Driver VLM Qwen3-VL-4B-Instruct (MLX 4-bit) ──
        // Transcription exacte du document via scripts/ocr/hf_runner.py
        // (venv scripts/ocr + mlx-vlm + torchvision — docs/IDENTITY-SCAN.md).
        'hfvlm' => [
            // INTERRUPTEUR IA — désactivé par défaut : le modèle VLM
            // (~2,6 Go, sortie non déterministe, 20-60 s/appel) ne
            // s'exécute QUE si IDENTITY_HFVLM_ENABLED=true. Désactivé →
            // fail-soft immédiat '' (repli saisie manuelle, §19).
            'active' => env('IDENTITY_HFVLM_ENABLED', false),
            // Interpréteur du venv scripts/ocr (hors PATH autorisé via env)
            'python' => env('IDENTITY_HFVLM_PYTHON', base_path('scripts/ocr/.venv/bin/python')),
            // Runner VLM appelé en tableau d'arguments par Process
            'script' => env('IDENTITY_HFVLM_SCRIPT', base_path('scripts/ocr/hf_runner.py')),
            // Dépôt HF du modèle (4-bit MLX — ~2,6 Go, 1er téléchargement
            // mis en cache dans ~/.cache/huggingface ; ~4-5 Go RAM en
            // pointe — Apple Silicon ≥ 8 Go)
            'modelle' => env('IDENTITY_HFVLM_MODEL', 'mlx-community/Qwen3-VL-4B-Instruct-4bit'),
            // Délai par appel (chargement du modèle + génération)
            'timeout' => (int) env('IDENTITY_HFVLM_TIMEOUT', 300),
            // Tranche CPU forcée via `arch` — MLX est natif arm64 :
            // obligatoire si PHP est une build x86_64 sous Rosetta
            'arch' => env('IDENTITY_HFVLM_ARCH', PHP_OS_FAMILY === 'Darwin' ? 'arm64' : ''),
            // Codes applicatifs (config/ocr.php + zones cin_tunisia) →
            // langue naturelle annoncée au prompt du VLM
            'langues' => [
                'eng' => 'English',
                'fra' => 'français',
                'lat' => 'français',
                'latin' => 'français',
                'eng+fra' => 'français et anglais', // passeport : MRZ + mentions françaises
                'ara' => 'arabe',
                'ara+eng' => 'arabe',
                'ara+fra' => 'arabe',
                'ara+lat' => 'arabe',
            ],
            'langue_defaut' => env('IDENTITY_HFVLM_LANGUE', 'français'),
        ],
        // ── Driver Colab (API FastAPI distante, GPU Google Colab) ──
        // L'image est envoyée via HTTP multipart à l'API FastAPI exposée
        // par un tunnel (localtunnel / ngrok). Le modèle OCR (EasyOCR,
        // PaddleOCR, etc.) tourne côté GPU Colab — aucune dépendance
        // locale. Cf. docs/IDENTITY-SCAN.md pour la configuration Colab.
        'colab' => [
            // URL publique du tunnel (localtunnel / ngrok) pointant vers
            // le notebook Colab exécutant l'API FastAPI.
            'url' => env('COLAB_API_URL', ''),
            // Délai maximal HTTP (secondes) : l'inférence GPU Colab est
            // rapide mais le tunnel peut ajouter de la latence.
            'timeout' => (int) env('COLAB_API_TIMEOUT', 120),
        ],
    ],

    // Contraintes d'upload (§14) — vérifiées en profondeur côté serveur :
    // extension, MIME réel (finfo), signature, image/PDF valide, taille.
    'upload' => [
        'taille_max' => (int) env('IDENTITY_UPLOAD_MAX', 8388608), // 8 Mo
        'extensions' => ['jpg', 'jpeg', 'png', 'pdf'],
        'mimes' => ['image/jpeg', 'image/png', 'application/pdf'],
    ],

    // Rate limiting du scan (§19) — traitement coûteux, anti-uploads massifs.
    'throttle' => [
        'scan' => (int) env('IDENTITY_THROTTLE_SCAN', 5), // scans / minute / IP
    ],

    /*
     | Seuils de confiance par champ (§8/§20) :
     | - >= eleve   : pré-remplissage automatique silencieux ;
     | - >= moyen   : pré-remplissage + avertissement visuel ;
     | - <  moyen   : champ laissé vide / vérification manuelle demandée ;
     | - validation : seuil MINIMAL pour pré-remplir sans avertissement
     |   (§20 — « ⚠ Vérification manuelle requise » en dessous).
     */
    'confiance' => [
        'eleve' => (float) env('IDENTITY_CONFIANCE_ELEVE', 0.90),
        'moyen' => (float) env('IDENTITY_CONFIANCE_MOYEN', 0.70),
        'seuil_validation' => (float) env('IDENTITY_CONFIANCE_SEUIL', 0.85),
    ],

    // Répertoire temporaire PRIVATE (disque local = storage/app/private),
    // nom de fichier aléatoire, suppression systématique après OCR.
    'repertoire_temporaire' => 'identity-scan/tmp',
];
