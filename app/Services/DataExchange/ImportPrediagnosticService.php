<?php

namespace App\Services\DataExchange;

use App\Models\ImportReport;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Services\Io\IoException;
use App\Services\Io\NormaliseurEnTetes;
use Illuminate\Http\UploadedFile;

/**
 * Prédiagnostic d'import (PHASE 2) : lecture du schéma, mapping des
 * en-têtes, suggestions JAMAIS appliquées automatiquement, volume,
 * empreinte et détection des demandes déjà traitées — SANS AUCUNE
 * ÉCRITURE (ni session, ni rapport, ni base). Les refus sont des
 * données (`refus`/`blocages`), jamais des 500 : tout fichier est
 * rejeté proprement avec ligne/colonne/solution quand c'est possible.
 *
 * @return array{entite: string, format: string, nom_fichier: string, taille_octets: int, inspection: array<string, mixed>, entetes: array<int, string>, mapping: array<int, array{brut: string, cle: string, statut: string, champ: ?string, suggestion: ?string}>, inconnues: array<int, string>, manquantes: array<int, string>, conflits: array<int, string>, volume: array<string, mixed>, empreinte: ?string, deja_traite: bool, pret: bool, blocages: array<int, string>, refus: ?string}
 */
class ImportPrediagnosticService
{
    public function __construct(
        private readonly DefinitionRegistry $definitions,
        private readonly DataImportService $imports,
        private readonly ImportFileInspector $inspecteur,
    ) {}

    public function diagnostiquer(UploadedFile $fichier, string $entite, ?int $auteurId = null): array
    {
        $base = [
            'entite' => $entite,
            'format' => mb_strtolower($fichier->getClientOriginalExtension() ?: ''),
            'nom_fichier' => $fichier->getClientOriginalName(),
            'taille_octets' => $fichier->getSize() ?: 0,
            'inspection' => [],
            'entetes' => [],
            'mapping' => [],
            'inconnues' => [],
            'manquantes' => [],
            'conflits' => [],
            'volume' => [],
            'empreinte' => null,
            'deja_traite' => false,
            'pret' => false,
            'blocages' => [],
            'refus' => null,
        ];

        try {
            $definition = $this->definitions->import($entite);
        } catch (\InvalidArgumentException) {
            $base['refus'] = "Entité « {$entite} » non importable.";

            return $base;
        }

        if (! array_key_exists($base['format'], DataImportService::formats())) {
            $base['refus'] = IoException::formatInvalide($base['format'] !== '' ? $base['format'] : 'inconnu')->getMessage();

            return $base;
        }

        // Inspection non destructive (encodage, délimiteur, pièges).
        try {
            $contenu = $fichier->getContent();
            $base['inspection'] = $this->inspecteur->inspecter($contenu, $base['nom_fichier']);
        } catch (IoException $e) {
            $base['refus'] = $e->getMessage();

            return $base;
        }

        // Taille déclarée (contrôle serveur repris du pipeline chaud).
        $tailleMax = max(1, (int) config('integrations.upload_max', 10 * 1024 * 1024));

        if ($base['taille_octets'] > $tailleMax) {
            $base['refus'] = IoException::tropVolumineux($tailleMax)->getMessage();

            return $base;
        }

        // Parse lecture seule (mêmes parsers, enveloppes et plafonds que l'import).
        try {
            $brut = $this->imports->parser($fichier, $base['format']);
        } catch (IoException $e) {
            $base['refus'] = $e->getMessage();

            return $base;
        } catch (\Throwable) {
            $base['refus'] = IoException::malforme('fichier illisible ou corrompu.')->getMessage();

            return $base;
        }

        if ($brut === []) {
            $base['refus'] = IoException::malforme('fichier vide.')->getMessage();

            return $base;
        }

        $entetes = array_keys($brut[0]);
        $base['entetes'] = array_values(array_map(strval(...), $entetes));

        // Mapping réutilisé du moteur (mêmes règles, sans écriture).
        [
            'correspondances' => $correspondances,
            'conflits' => $conflits,
            'manquantes' => $manquantes,
        ] = $this->imports->resoudreCorrespondances($base['entetes'], $definition);

        $base['conflits'] = $conflits;
        $base['manquantes'] = $manquantes;

        $connus = $this->clesConnues($definition);

        foreach ($base['entetes'] as $brute) {
            $cle = NormaliseurEnTetes::cle($brute);

            if (isset($correspondances[$brute])) {
                $base['mapping'][] = [
                    'brut' => $brute,
                    'cle' => $cle,
                    'statut' => $this->estCanonique($definition, $correspondances[$brute]) ? 'canonique' : 'alias',
                    'champ' => $correspondances[$brute],
                    'suggestion' => null,
                ];

                continue;
            }

            $suggestion = $this->suggerer($cle, $connus);
            $base['mapping'][] = [
                'brut' => $brute,
                'cle' => $cle,
                'statut' => 'inconnue',
                'champ' => null,
                'suggestion' => $suggestion,
            ];
            $base['inconnues'][] = $brute;
        }

        $nombre = count($brut);
        $base['volume'] = [
            'lignes' => $nombre,
            'plafond_lignes' => DataImportService::LIGNES_MAX,
            'depasse_lignes' => $nombre > DataImportService::LIGNES_MAX,
            'plafond_octets' => $tailleMax,
        ];

        $hash = hash_file('sha256', $fichier->getRealPath());
        $base['empreinte'] = $hash === false ? null : $hash;
        $base['deja_traite'] = $hash !== false && ImportReport::where('type', 'data.'.$entite)
            ->where('file_hash', $hash)
            ->where('status', 'completed')
            ->exists();

        if ($conflits !== []) {
            $base['blocages'][] = 'colonnes ambiguës : '.implode(', ', $conflits).'.';
        }

        foreach ($manquantes as $manquante) {
            $suggestion = $this->suggerer(NormaliseurEnTetes::cle($manquante), array_map(
                fn (array $m): string => $m['cle'], $base['mapping']
            ));
            $base['blocages'][] = "colonne requise manquante : {$manquante}"
                .($suggestion !== null ? " (vouliez-vous dire : {$suggestion} ?)" : '.');
        }

        if ($base['volume']['depasse_lignes']) {
            $base['blocages'][] = IoException::tropDeLignes(DataImportService::LIGNES_MAX)->getMessage();
        }

        $base['pret'] = $base['blocages'] === [] && $base['refus'] === null;

        return $base;
    }

    /**
     * @return array<int, string> clés normalisées connues (canoniques + alias).
     */
    private function clesConnues(object $definition): array
    {
        $norme = NormaliseurEnTetes::cle(...);
        $connues = array_map($norme, $definition->colonnes());

        if ($definition instanceof ImportValidationAvancee) {
            foreach ($definition->aliasesColonnes() as $variantes) {
                foreach ((array) $variantes as $variante) {
                    $connues[] = $norme((string) $variante);
                }
            }
        }

        return array_values(array_unique($connues));
    }

    private function estCanonique(object $definition, string $champ): bool
    {
        return in_array($champ, $definition->colonnes(), true);
    }

    /**
     * Suggestion la plus proche (Levenshtein ≤ 3), jamais appliquée
     * automatiquement : l'import ignore la colonne inconnue.
     */
    private function suggerer(string $cle, array $connues): ?string
    {
        $meilleure = null;
        $meilleureDistance = 4;

        foreach ($connues as $connue) {
            $distance = levenshtein($cle, $connue);

            if ($distance >= 0 && $distance < $meilleureDistance) {
                $meilleure = $connue;
                $meilleureDistance = $distance;
            }
        }

        return $meilleure;
    }
}
