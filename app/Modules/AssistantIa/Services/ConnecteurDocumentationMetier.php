<?php

namespace App\Modules\AssistantIa\Services;

use App\Modules\AssistantIa\Models\AiDocumentIndex;
use Illuminate\Support\Facades\File;

/**
 * Connecteur dédié à la documentation métier, aux règles académiques et à l'historique d'ingénierie (LOOP).
 * Structure les métadonnées de validité (validé, brouillon, obsolète), versions et domaines métier.
 */
class ConnecteurDocumentationMetier
{
    public const DOMAINES_METIER = [
        'inscriptions',
        'lmd',
        'examens',
        'finances',
        'gouvernance',
        'technique',
        'general',
    ];

    public function __construct(
        protected ?IndexeurDocumentaire $indexeur = null
    ) {
        $this->indexeur = $indexeur ?? app(IndexeurDocumentaire::class);
    }

    /**
     * Analyse les en-têtes et métadonnées d'un document (Frontmatter Markdown ou métadonnées de texte).
     *
     * @return array{statut: string, domaine: string, version: string, titre: string, restreint: bool, corps: string}
     */
    public function analyserMetadonnees(string $contenu, string $cheminRelatif): array
    {
        $statut = 'valide';
        $domaine = 'general';
        $version = '1.0';
        $titre = pathinfo($cheminRelatif, PATHINFO_FILENAME);
        $restreint = false;
        $corps = $contenu;

        // Détection YAML frontmatter au format --- ... ---
        if (preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $contenu, $matches)) {
            $frontmatter = $matches[1];
            $corps = trim($matches[2]);

            // Extraction clé: valeur
            if (preg_match('/statut\s*:\s*([A-Za-z0-9_-]+)/i', $frontmatter, $m)) {
                $statutDetecte = strtolower(trim($m[1]));
                if (in_array($statutDetecte, ['valide', 'brouillon', 'obsolete'], true)) {
                    $statut = $statutDetecte;
                }
            }
            if (preg_match('/domaine\s*:\s*([A-Za-z0-9_-]+)/i', $frontmatter, $m)) {
                $domaineDetecte = strtolower(trim($m[1]));
                if (in_array($domaineDetecte, self::DOMAINES_METIER, true)) {
                    $domaine = $domaineDetecte;
                }
            }
            if (preg_match('/version\s*:\s*([A-Za-z0-9_.-]+)/i', $frontmatter, $m)) {
                $version = trim($m[1]);
            }
            if (preg_match('/titre\s*:\s*(.+)$/m', $frontmatter, $m)) {
                $titre = trim(trim($m[1]), "'\"");
            }
            if (preg_match('/confidentialite\s*:\s*(restreint|confidentiel)/i', $frontmatter)) {
                $restreint = true;
            }
        } else {
            // Déduction par chemin si pas de frontmatter
            if (str_contains($cheminRelatif, 'inscriptions') || str_contains($cheminRelatif, 'admission')) {
                $domaine = 'inscriptions';
            } elseif (str_contains($cheminRelatif, 'evaluation') || str_contains($cheminRelatif, 'notes') || str_contains($cheminRelatif, 'exam')) {
                $domaine = 'examens';
            } elseif (str_contains($cheminRelatif, 'lmd') || str_contains($cheminRelatif, 'referentiel') || str_contains($cheminRelatif, 'parcours')) {
                $domaine = 'lmd';
            } elseif (str_contains($cheminRelatif, 'engineering') || str_contains($cheminRelatif, 'architecture')) {
                $domaine = 'technique';
            }

            // Détection de statut dans le titre ou les premières lignes
            if (preg_match('/\b(obsol[èe]te|deprecated|archiv[ée])\b/i', $cheminRelatif) || preg_match('/#.*?\b(obsol[èe]te|deprecated)\b/i', mb_substr($contenu, 0, 300))) {
                $statut = 'obsolete';
            } elseif (preg_match('/\b(brouillon|draft|wip)\b/i', $cheminRelatif) || preg_match('/#.*?\b(brouillon|draft)\b/i', mb_substr($contenu, 0, 300))) {
                $statut = 'brouillon';
            }

            // Extraction du titre Markdown (# Titre)
            if (preg_match('/^#\s+([^\n]+)/m', $contenu, $m)) {
                $titre = trim($m[1]);
            }
        }

        return [
            'statut' => $statut,
            'domaine' => $domaine,
            'version' => $version,
            'titre' => $titre,
            'restreint' => $restreint,
            'corps' => $corps,
        ];
    }

    /**
     * Indexe un document métier avec qualification de statut et avertissements contextuels.
     */
    public function indexerDocumentMetier(string $cheminRelatif, ?string $sourceCategorie = null): int
    {
        $chemin = str_replace('\\', '/', trim($cheminRelatif));

        if (! $this->indexeur->estFichierAutorise($chemin)) {
            return 0;
        }

        $cheminAbsolu = base_path($chemin);
        if (! File::exists($cheminAbsolu) || File::isDirectory($cheminAbsolu)) {
            return 0;
        }

        $contenu = @file_get_contents($cheminAbsolu);
        if ($contenu === false || trim($contenu) === '') {
            return 0;
        }

        // Nettoyage et anonymisation PII
        $meta = $this->analyserMetadonnees($contenu, $chemin);
        $corpsNettoye = FiltreSecuriteIa::purgerSortie($meta['corps']);

        // Préfixe contextuel selon le statut officiel
        $prefixeAvertissement = '';
        if ($meta['statut'] === 'obsolete') {
            $prefixeAvertissement = "[AVERTISSEMENT : CE DOCUMENT EST OBSOLÈTE (v{$meta['version']}). NE PAS UTILISER COMME RÈGLE OFFICIELLE COURANTE]\n\n";
        } elseif ($meta['statut'] === 'brouillon') {
            $prefixeAvertissement = "[NOTE : DOCUMENT DE TRAVAIL / BROUILLON NON APPROUVÉ PAR LA COMMISSION PÉDAGOGIQUE]\n\n";
        }

        $empreinte = hash('sha256', $prefixeAvertissement.$corpsNettoye);

        // Anti-doublon incrémental
        $existant = AiDocumentIndex::where('chemin_fichier', $chemin)
            ->where('empreinte_sha256', $empreinte)
            ->count();

        if ($existant > 0) {
            return $existant;
        }

        // Remplacement : purge des anciens morceaux
        AiDocumentIndex::where('chemin_fichier', $chemin)->forceDelete();

        $morceauxBruts = $this->indexeur->decouperTexte($corpsNettoye, 1000, 150);
        $sourceType = $sourceCategorie ?? (str_starts_with($chemin, 'docs/engineering') ? 'loop_report' : 'business_procedure');
        $permissionRequise = $meta['restreint'] ? 'assistant_ia.sources_restreintes' : 'assistant_ia.mode_metier';

        $nbFragments = 0;
        foreach ($morceauxBruts as $idx => $morceau) {
            $morceauFinal = $prefixeAvertissement."Document: {$meta['titre']} (v{$meta['version']}, Statut: {$meta['statut']})\n---\n".$morceau;

            AiDocumentIndex::create([
                'source' => $sourceType,
                'titre' => $meta['titre'],
                'chemin_fichier' => $chemin,
                'index_morceau' => $idx,
                'contenu_morceau' => $morceauFinal,
                'empreinte_sha256' => $empreinte,
                'version_indexeur' => IndexeurDocumentaire::VERSION_INDEXEUR,
                'est_sensible' => $meta['restreint'],
                'permission_requise' => $permissionRequise,
                'statut_document' => $meta['statut'],
                'domaine_metier' => $meta['domaine'],
                'version_document' => $meta['version'],
                'metadata' => [
                    'source_type' => $sourceType,
                    'restreint' => $meta['restreint'],
                ],
            ]);

            $nbFragments++;
        }

        JournalisationIa::info("Document métier indexé : {$chemin} ({$nbFragments} morceaux, statut: {$meta['statut']})");

        return $nbFragments;
    }
}
