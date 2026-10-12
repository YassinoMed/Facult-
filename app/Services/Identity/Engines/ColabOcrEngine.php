<?php

namespace App\Services\Identity\Engines;

use App\Services\Identity\OcrEngineInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Moteur OCR distant « Colab » — API FastAPI hébergée sur Google Colab
 * (§1/§4) — branché sur l'INTERFACE exactement comme les autres
 * drivers, via la configuration :
 *
 *   IDENTITY_OCR_DRIVER=colab
 *
 * L'API Colab exécute un modèle OCR (EasyOCR, PaddleOCR, etc.)
 * côté GPU Colab et retourne le texte brut extrait. Le tunnel
 * (localtunnel / ngrok) expose l'API via une URL publique
 * configurée dans COLAB_API_URL.
 *
 * Règles absolues — IDENTIQUES aux autres drivers :
 * - fail-soft : API injoignable, tunnel fermé, timeout → ''
 *   (JAMAIS d'exception — saisie manuelle en repli) ;
 * - le chemin analysé est TOUJOURS le temporaire privé UUID serveur ;
 * - AUCUNE donnée OCR/PII n'est journalisée (§15) — seuls le statut
 *   HTTP et la durée sont tracés ;
 * - $psm est ignoré (spécifique Tesseract) — le pipeline zones (§5)
 *   de CinOcrService fonctionne sans modification.
 */
class ColabOcrEngine implements OcrEngineInterface
{
    public function rawText(string $filePath, string $langue = OcrEngineInterface::LANGUE_ARABE_LATIN, ?int $psm = null): string
    {
        $baseUrl = trim((string) config('identity.ocr.colab.url', ''));

        // URL non configurée → fail-soft immédiat
        if ($baseUrl === '') {
            return '';
        }

        // Fichier introuvable → fail-soft
        if (! is_file($filePath)) {
            return '';
        }

        $endpoint = rtrim($baseUrl, '/').'/extract';
        $timeout = (int) config('identity.ocr.colab.timeout', 120);
        $docType = $this->resoudreDocType($langue);

        // Optimisation GPU Colab : redimensionner si l'image est trop grande (> 1024px)
        // pour éviter l'erreur "CUDA out of memory" sur le GPU distant.
        $fichierAEnvoyer = $this->optimiserTailleImage($filePath);
        $cheminEnvoi = $fichierAEnvoyer ?? $filePath;

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout(15)
                ->attach(
                    'file',
                    file_get_contents($cheminEnvoi),
                    basename($filePath),
                )
                ->post($endpoint, [
                    'doc_type' => $docType,
                ]);

            if (! $response->successful()) {
                Log::notice('identity.ocr.colab.erreur_http', [
                    'status' => $response->status(),
                ]);

                return '';
            }

            $resultat = $response->json();

            return $this->extraireTexte($resultat);

        } catch (\Throwable $e) {
            // Tunnel fermé, Colab arrêté, timeout réseau… → fail-soft
            Log::notice('identity.ocr.colab.echec', [
                'erreur' => class_basename($e),
            ]);

            return '';
        } finally {
            if ($fichierAEnvoyer !== null && file_exists($fichierAEnvoyer)) {
                @unlink($fichierAEnvoyer);
            }
        }
    }

    /**
     * Redimensionne les photos trop volumineuses (ex. photos smartphone > 1024px)
     * pour garantir une inférence ultra-rapide et prévenir les plantages CUDA OOM.
     */
    private function optimiserTailleImage(string $filePath): ?string
    {
        if (! extension_loaded('gd') || ! is_file($filePath)) {
            return null;
        }

        $taille = @getimagesize($filePath);
        if ($taille === false) {
            return null;
        }

        [$largeur, $hauteur] = $taille;
        $maxDimension = 800;

        if ($largeur <= $maxDimension && $hauteur <= $maxDimension) {
            return null;
        }

        $ratio = min($maxDimension / $largeur, $maxDimension / $hauteur);
        $nouvelleLargeur = (int) round($largeur * $ratio);
        $nouvelleHauteur = (int) round($hauteur * $ratio);

        $contenu = @file_get_contents($filePath);
        if ($contenu === false) {
            return null;
        }

        $source = @imagecreatefromstring($contenu);
        if ($source === false) {
            return null;
        }

        $destination = imagecreatetruecolor($nouvelleLargeur, $nouvelleHauteur);
        imagecopyresampled($destination, $source, 0, 0, 0, 0, $nouvelleLargeur, $nouvelleHauteur, $largeur, $hauteur);
        imagedestroy($source);

        $tempPath = sys_get_temp_dir().'/colab_opt_'.uniqid().'.jpg';
        imagejpeg($destination, $tempPath, 85);
        imagedestroy($destination);

        return $tempPath;
    }

    /**
     * Extrait le texte brut depuis la réponse JSON de l'API Colab.
     *
     * Formats supportés :
     * 1) { "status": "success", "data": { "raw_text": "..." } }
     * 2) { "status": "success", "data": { "numero_cin": "...", "nom_fr": "...", ... } }
     * 3) { "text": "..." } ou { "lines": [...] }
     *
     * @param  array<string, mixed>|null  $resultat
     */
    private function extraireTexte(?array $resultat): string
    {
        if ($resultat === null) {
            return '';
        }

        $data = $resultat['data'] ?? $resultat;

        if (is_string($data)) {
            return trim($data);
        }

        if (is_array($data)) {
            // Clés directes de texte brut
            foreach (['raw_text', 'text', 'texte', 'ocr_text'] as $cle) {
                if (isset($data[$cle]) && is_string($data[$cle])) {
                    return trim($data[$cle]);
                }
            }

            // Liste de lignes
            if (isset($data['lines']) && is_array($data['lines'])) {
                return trim(implode("\n", array_filter($data['lines'], 'is_string')));
            }

            // Dictionnaire structuré { numero_cin: '...', nom_fr: '...', ... }
            $mappingLibelles = [
                'numero_cin' => 'CIN',
                'cin' => 'CIN',
                'doc_number' => 'CIN',
                'passport_number' => 'Passport',
                'nom_fr' => 'Nom',
                'nom_ar' => 'اللقب',
                'nom' => 'Nom',
                'last_name' => 'Nom',
                'prenom_fr' => 'Prénom',
                'prenom_ar' => 'الاسم',
                'prenom' => 'Prénom',
                'first_name' => 'Prénom',
                'date_naissance' => 'Né(e) le',
                'birth_date' => 'Né(e) le',
                'lieu_naissance' => 'Lieu de naissance',
                'birth_place' => 'Lieu de naissance',
                'filiation' => 'Filiation',
                'sexe' => 'Sexe',
                'sex' => 'Sexe',
            ];

            $lignes = [];
            foreach ($data as $k => $v) {
                if (is_scalar($v) && (string) $v !== '' && ! in_array($k, ['status', 'success', 'doc_type', 'nb_blocs'], true)) {
                    $libelle = $mappingLibelles[$k] ?? $k;
                    $lignes[] = "{$libelle} : {$v}";
                }
            }

            if (! empty($lignes)) {
                return implode("\n", $lignes);
            }
        }

        return '';
    }

    /**
     * Résout l'indice de langue vers le type de document attendu par
     * l'API Colab (CIN ou passport). La convention est « CIN » par
     * défaut — les codes contenant 'eng+fra' ou le hint passeport
     * envoient « passport ».
     */
    private function resoudreDocType(string $langue): string
    {
        // eng+fra est le code langue passeport dans la config
        if ($langue === 'eng+fra') {
            return 'passport';
        }

        return 'CIN';
    }
}
