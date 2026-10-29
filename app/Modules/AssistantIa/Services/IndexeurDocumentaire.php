<?php

namespace App\Modules\AssistantIa\Services;

use App\Modules\AssistantIa\Models\AiDocumentIndex;

/**
 * Service d'indexation et de découpage (Chunking) documentaire pour le RAG UniRegistre AI.
 */
class IndexeurDocumentaire
{
    public const VERSION_INDEXEUR = 1;

    protected array $dossiersAutorises;

    protected array $extensionsAutorisees;

    protected array $motifsExclus;

    public function __construct(?array $config = null)
    {
        $config = $config ?? config('assistant-ia.rag', []);
        $this->dossiersAutorises = $config['dossiers_autorises'] ?? ['docs/engineering', 'docs'];
        $this->extensionsAutorisees = $config['extensions_autorisees'] ?? ['md', 'txt', 'json'];
        $this->motifsExclus = $config['motifs_strictement_exclus'] ?? ['.env', '*.key', '*.pem', 'vendor', 'node_modules', 'storage'];
    }

    /**
     * Vérifie si un chemin de fichier est éligible à l'indexation RAG.
     */
    public function estFichierAutorise(string $cheminRelatif): bool
    {
        $cheminNormalise = str_replace('\\', '/', trim($cheminRelatif));

        // Bloquer immédiatement toute tentative de traversée de répertoire ou chemin absolu
        if (str_contains($cheminNormalise, '..') || str_starts_with($cheminNormalise, '/') || str_contains($cheminNormalise, './')) {
            return false;
        }

        // 1. Vérification stricte des motifs interdits
        foreach ($this->motifsExclus as $motif) {
            $motifNormalise = str_replace(['*', '.'], ['.*', '\.'], $motif);
            if (preg_match("#(^|/){$motifNormalise}($|/)#i", $cheminNormalise) || str_contains($cheminNormalise, $motif)) {
                return false;
            }
        }

        // 2. Vérification de l'extension
        $extension = pathinfo($cheminNormalise, PATHINFO_EXTENSION);
        if (! in_array(strtolower($extension), $this->extensionsAutorisees, true)) {
            return false;
        }

        // 3. Vérification de l'appartenance à un dossier autorisé
        $appartientADossierAutorise = false;
        foreach ($this->dossiersAutorises as $dossier) {
            $dossierNormalise = str_replace('\\', '/', trim($dossier));
            if ($dossierNormalise === '.' || $dossierNormalise === '') {
                // Autoriser les fichiers directement à la racine si '.' est dans les dossiers autorisés
                if (! str_contains($cheminNormalise, '/')) {
                    $appartientADossierAutorise = true;
                    break;
                }
            } elseif (str_starts_with($cheminNormalise, rtrim($dossierNormalise, '/').'/') || $cheminNormalise === $dossierNormalise) {
                $appartientADossierAutorise = true;
                break;
            }
        }

        return $appartientADossierAutorise;
    }

    /**
     * Découpe un texte en morceaux cohérents avec chevauchement.
     *
     * @return array<int, string>
     */
    public function decouperTexte(string $texte, int $tailleMorceau = 1000, int $chevauchement = 100): array
    {
        $texte = trim($texte);
        if (mb_strlen($texte) <= $tailleMorceau) {
            return [$texte];
        }

        $morceaux = [];
        $longueur = mb_strlen($texte);
        $debut = 0;

        while ($debut < $longueur) {
            $fin = min($debut + $tailleMorceau, $longueur);

            // Tenter de couper sur une fin de phrase ou un saut de ligne
            if ($fin < $longueur) {
                $posSaut = mb_strrpos(mb_substr($texte, $debut, $tailleMorceau), "\n");
                $posPoint = mb_strrpos(mb_substr($texte, $debut, $tailleMorceau), '. ');

                if ($posSaut !== false && $posSaut > ($tailleMorceau * 0.5)) {
                    $fin = $debut + $posSaut + 1;
                } elseif ($posPoint !== false && $posPoint > ($tailleMorceau * 0.5)) {
                    $fin = $debut + $posPoint + 2;
                }
            }

            $morceau = trim(mb_substr($texte, $debut, $fin - $debut));
            if (! empty($morceau)) {
                $morceaux[] = $morceau;
            }

            if ($fin >= $longueur) {
                break;
            }

            $debut = max($debut + 1, $fin - $chevauchement);
        }

        return $morceaux;
    }

    /**
     * Indexe un document en découpant son contenu et en calculant son empreinte sha256.
     *
     * @return int Nombre de morceaux indexés
     */
    public function indexerContenu(
        string $cheminRelatif,
        string $titre,
        string $contenu,
        string $source = 'docs',
        bool $estSensible = false,
        ?string $permissionRequise = null,
        ?string $statutDocument = 'valide',
        ?string $domaineMetier = 'general',
        ?string $versionDocument = '1.0',
        array $metadata = []
    ): int {
        if (! $this->estFichierAutorise($cheminRelatif)) {
            JournalisationIa::avertissement("Tentative d'indexation d'un fichier interdit ou hors périmètre : {$cheminRelatif}");

            return 0;
        }

        // Nettoyage préalable (sécurité : pas de secrets ni de balises de script)
        $contenuNettoye = FiltreSecuriteIa::purgerSortie($contenu);
        $empreinte = hash('sha256', $contenuNettoye);

        // Vérifier si une version identique est déjà indexée
        $existant = AiDocumentIndex::where('chemin_fichier', $cheminRelatif)
            ->where('empreinte_sha256', $empreinte)
            ->count();

        if ($existant > 0) {
            return $existant;
        }

        // Remplacement : supprimer définitivement les anciens morceaux du fichier
        AiDocumentIndex::where('chemin_fichier', $cheminRelatif)->forceDelete();

        $morceaux = $this->decouperTexte($contenuNettoye, 1000, 150);

        foreach ($morceaux as $idx => $morceau) {
            AiDocumentIndex::create([
                'source' => $source,
                'titre' => $titre,
                'chemin_fichier' => $cheminRelatif,
                'index_morceau' => $idx,
                'contenu_morceau' => $morceau,
                'empreinte_sha256' => $empreinte,
                'version_indexeur' => self::VERSION_INDEXEUR,
                'est_sensible' => $estSensible,
                'permission_requise' => $permissionRequise,
                'statut_document' => $statutDocument ?? 'valide',
                'domaine_metier' => $domaineMetier ?? 'general',
                'version_document' => $versionDocument ?? '1.0',
                'metadata' => $metadata,
            ]);
        }

        $nbMorceaux = count($morceaux);
        JournalisationIa::info("Document indexé avec succès dans le RAG : {$cheminRelatif} ({$nbMorceaux} morceaux)");

        return $nbMorceaux;
    }
}
