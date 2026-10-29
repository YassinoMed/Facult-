<?php

namespace App\Modules\AssistantIa\Services;

use App\Modules\AssistantIa\Exceptions\SecuriteIaException;
use App\Modules\AssistantIa\Models\AiDocumentIndex;
use Illuminate\Support\Facades\File;

/**
 * Connecteur spécialisé dans l'indexation sécurisée et incrémentale du code source UniRegistre.
 * Extrait les symboles (classes, méthodes, routes), garantit l'exclusion absolue des secrets
 * et isole les fragments sous la permission 'assistant_ia.mode_developpeur'.
 */
class ConnecteurCodeUniRegistre
{
    public const DOSSIERS_CODE_AUTORISES = [
        'app',
        'routes',
        'database/migrations',
        'database/seeders',
        'config',
        'resources/views',
        'tests',
    ];

    public const EXTENSIONS_CODE_AUTORISEES = [
        'php',
        'blade.php',
        'json',
    ];

    public const MOTIFS_EXCLUSION_STRICTS = [
        '.env',
        '*.key',
        '*.pem',
        '*.crt',
        '*.pfx',
        'vendor',
        'node_modules',
        'storage',
        'database/backups',
        '*.sql',
        '*.sqlite',
        '*.csv',
        '*.dump',
        'public/build',
    ];

    public function __construct(
        protected ?IndexeurDocumentaire $indexeur = null
    ) {
        $this->indexeur = $indexeur ?? app(IndexeurDocumentaire::class);
    }

    /**
     * Vérifie si un chemin de fichier code est strictement autorisé à l'analyse.
     */
    public function estFichierCodeAutorise(string $cheminRelatif): bool
    {
        $chemin = str_replace('\\', '/', trim($cheminRelatif));

        // Rejet absolu du path traversal
        if (str_contains($chemin, '..') || str_starts_with($chemin, '/') || str_contains($chemin, './')) {
            return false;
        }

        // Vérification des motifs d'exclusion stricts
        foreach (self::MOTIFS_EXCLUSION_STRICTS as $motif) {
            $regex = str_replace(['*', '.'], ['.*', '\.'], $motif);
            if (preg_match("#(^|/){$regex}($|/)#i", $chemin) || str_contains($chemin, $motif)) {
                return false;
            }
        }

        // Vérification du dossier autorisé
        $dansDossierAutorise = false;
        foreach (self::DOSSIERS_CODE_AUTORISES as $dossier) {
            if (str_starts_with($chemin, rtrim($dossier, '/').'/') || $chemin === $dossier) {
                $dansDossierAutorise = true;
                break;
            }
        }

        if (! $dansDossierAutorise) {
            return false;
        }

        // Vérification des extensions autorisées
        $estExtensionValide = false;
        foreach (self::EXTENSIONS_CODE_AUTORISEES as $ext) {
            if (str_ends_with(strtolower($chemin), '.'.$ext)) {
                $estExtensionValide = true;
                break;
            }
        }

        return $estExtensionValide;
    }

    /**
     * Analyse le contenu d'un fichier à la recherche de secrets avant indexation.
     */
    public function contientSecrets(string $contenu): bool
    {
        $motifsSecrets = [
            '/APP_KEY\s*=\s*base64:[A-Za-z0-9+\/=]+/i',
            '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/i',
            '/(?:api[_-]?key|secret[_-]?key|access[_-]?token)\s*[:=]\s*[\'"][A-Za-z0-9_\-]{20,}[\'"]/i',
            '/(?:password|mot_de_passe|db_password)\s*[:=]\s*[\'"][^\'"]{6,}[\'"]/i',
        ];

        foreach ($motifsSecrets as $motif) {
            if (preg_match($motif, $contenu)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extrait les symboles significatifs (classes, méthodes, routes) pour enrichir la recherche.
     *
     * @return array{classes: array<string>, methodes: array<string>, routes: array<string>}
     */
    public function extraireSymboles(string $contenu): array
    {
        $classes = [];
        $methodes = [];
        $routes = [];

        // Extraction des classes et interfaces
        if (preg_match_all('/\b(?:class|interface|trait|enum)\s+([A-Za-z0-9_]+)/i', $contenu, $matches)) {
            $classes = array_values(array_unique($matches[1]));
        }

        // Extraction des méthodes publiques et protégées
        if (preg_match_all('/\b(?:public|protected)\s+function\s+([A-Za-z0-9_]+)\s*\(/i', $contenu, $matches)) {
            $methodes = array_values(array_unique($matches[1]));
        }

        // Extraction des définitions de routes
        if (preg_match_all('/Route::(get|post|put|patch|delete|resource)\s*\(\s*[\'"]([^\'"]+)[\'"]/i', $contenu, $matches)) {
            foreach ($matches[2] as $idx => $uri) {
                $verbe = strtoupper($matches[1][$idx]);
                $routes[] = "{$verbe} {$uri}";
            }
        }

        return [
            'classes' => $classes,
            'methodes' => $methodes,
            'routes' => $routes,
        ];
    }

    /**
     * Indexe un fichier de code de manière incrémentale.
     *
     * @return int Nombre de fragments indexés (0 si inchangé ou rejeté)
     */
    public function indexerFichierCode(string $cheminRelatif): int
    {
        if (! $this->estFichierCodeAutorise($cheminRelatif)) {
            return 0;
        }

        $cheminAbsolu = base_path($cheminRelatif);
        if (! File::exists($cheminAbsolu) || File::isDirectory($cheminAbsolu)) {
            return 0;
        }

        // Rejet des fichiers volumineux (> 350 Ko) pour préserver la mémoire
        if (File::size($cheminAbsolu) > 350 * 1024) {
            JournalisationIa::avertissement("Fichier code ignoré (taille supérieure à 350Ko) : {$cheminRelatif}");

            return 0;
        }

        $contenu = @file_get_contents($cheminAbsolu);
        if ($contenu === false || trim($contenu) === '') {
            return 0;
        }

        // Vérification de sécurité absolue contre la fuite de secrets
        if ($this->contientSecrets($contenu)) {
            JournalisationIa::alerteSecurite("Tentative d'indexation d'un fichier contenant des secrets détectés : {$cheminRelatif}");
            throw new SecuriteIaException("Le fichier '{$cheminRelatif}' contient des clés ou secrets et ne peut être indexé.");
        }

        // Calcul d'empreinte sha256
        $empreinte = hash('sha256', $contenu);

        // Détection de modification incrémentale
        $existant = AiDocumentIndex::where('chemin_fichier', $cheminRelatif)
            ->where('empreinte_sha256', $empreinte)
            ->count();

        if ($existant > 0) {
            return $existant; // Déjà à jour
        }

        // Purge des anciens fragments de ce fichier
        AiDocumentIndex::where('chemin_fichier', $cheminRelatif)->forceDelete();

        // Extraction des symboles
        $symboles = $this->extraireSymboles($contenu);
        $enTeteSymboles = '';
        if (! empty($symboles['classes']) || ! empty($symboles['routes']) || ! empty($symboles['methodes'])) {
            $enTeteSymboles = "Fichier: {$cheminRelatif}\n";
            if (! empty($symboles['classes'])) {
                $enTeteSymboles .= 'Classes/Types: '.implode(', ', $symboles['classes'])."\n";
            }
            if (! empty($symboles['routes'])) {
                $enTeteSymboles .= 'Routes: '.implode(', ', array_slice($symboles['routes'], 0, 10))."\n";
            }
            if (! empty($symboles['methodes'])) {
                $enTeteSymboles .= 'Méthodes: '.implode(', ', array_slice($symboles['methodes'], 0, 15))."\n";
            }
            $enTeteSymboles .= "---\n";
        }

        $titre = ! empty($symboles['classes'])
            ? implode(', ', $symboles['classes'])." ({$cheminRelatif})"
            : pathinfo($cheminRelatif, PATHINFO_BASENAME);

        // Découpage du code
        $morceauxBruts = $this->indexeur->decouperTexte($contenu, 1200, 150);
        $nbIndexe = 0;

        foreach ($morceauxBruts as $idx => $morceau) {
            $contenuFinal = $enTeteSymboles.$morceau;

            AiDocumentIndex::create([
                'source' => 'project_code',
                'titre' => $titre,
                'chemin_fichier' => $cheminRelatif,
                'index_morceau' => $idx,
                'contenu_morceau' => $contenuFinal,
                'empreinte_sha256' => $empreinte,
                'version_indexeur' => IndexeurDocumentaire::VERSION_INDEXEUR,
                'est_sensible' => true,
                'permission_requise' => 'assistant_ia.mode_developpeur',
            ]);

            $nbIndexe++;
        }

        JournalisationIa::info("Fichier code indexé : {$cheminRelatif} ({$nbIndexe} fragments)");

        return $nbIndexe;
    }

    /**
     * Indexe un sous-répertoire de code de façon sécurisée.
     *
     * @return array{fichiers: int, fragments: int}
     */
    public function indexerDossierCode(string $dossierRelatif, int $limiteFichiers = 50): array
    {
        $dossierAbsolu = base_path($dossierRelatif);
        if (! File::exists($dossierAbsolu) || ! File::isDirectory($dossierAbsolu)) {
            return ['fichiers' => 0, 'fragments' => 0];
        }

        $fichiers = File::allFiles($dossierAbsolu);
        $totalFichiers = 0;
        $totalFragments = 0;

        foreach ($fichiers as $f) {
            if ($totalFichiers >= $limiteFichiers) {
                break;
            }

            $rel = str_replace(base_path().'/', '', $f->getPathname());
            $rel = str_replace('\\', '/', $rel);

            if (! $this->estFichierCodeAutorise($rel)) {
                continue;
            }

            try {
                $fragments = $this->indexerFichierCode($rel);
                if ($fragments > 0) {
                    $totalFichiers++;
                    $totalFragments += $fragments;
                }
            } catch (\Throwable $e) {
                JournalisationIa::avertissement("Erreur indexation fichier {$rel} : ".$e->getMessage());
            }
        }

        return ['fichiers' => $totalFichiers, 'fragments' => $totalFragments];
    }
}
