<?php

namespace App\Services\DataExchange;

use App\Models\IntegrationLog;
use App\Services\Io\IoFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Export unifié (§3/§19-23) : requête scopée + filtres/sélection →
 * projection colonnes autorisées → sérialisation → téléchargement.
 * Anti CSV-injection (§39) : neutralisation des préfixes de formule.
 * Journalisé avec request_id (§34).
 */
class DataExportService
{
    public function __construct(
        private readonly DefinitionRegistry $definitions,
    ) {}

    /**
     * @return array{contenu: string, nom: string, mime: string}
     */
    public function exporter(Request $request, string $entite, string $format): array
    {
        $definition = $this->definitions->export($entite);

        if (! in_array($format, $definition->formats(), true)) {
            throw new \InvalidArgumentException("Format « {$format} » non supporté pour « {$entite} ».");
        }

        $debut = microtime(true);
        $requestId = (string) Str::uuid();

        try {
            $query = $definition->filtrer($definition->requete($request), $request);
            $lignes = $query->limit(10000)->get()->map(
                fn ($modele) => $this->neutraliser($definition->ligne($modele))
            )->all();

            $entetes = array_values($definition->colonnes());
            $contenu = IoFactory::serializerPour($format)->serialize($entetes, $lignes);

            IntegrationLog::tracer(
                'data-exchange', 'export', "data.export.{$entite}", 'completed',
                null, 200, (int) ((microtime(true) - $debut) * 1000), null, $requestId
            );

            return [
                'contenu' => $contenu,
                'nom' => "{$entite}-".now()->format('Ymd-His').".{$format}",
                'mime' => $this->mime($format),
            ];
        } catch (\Throwable $e) {
            IntegrationLog::tracer(
                'data-exchange', 'export', "data.export.{$entite}", 'failed',
                null, null, (int) ((microtime(true) - $debut) * 1000), 'export_failed', $requestId
            );

            throw $e;
        }
    }

    /**
     * Anti CSV-injection (§39) : préfixe ' aux valeurs commençant par
     * =, +, - ou @ (texte, jamais formule).
     *
     * @param  array<string, mixed>  $ligne
     * @return array<string, mixed>
     */
    public function neutraliser(array $ligne): array
    {
        foreach ($ligne as $cle => $valeur) {
            if (is_string($valeur) && preg_match('/^[=+\-@]/', $valeur) === 1) {
                $ligne[$cle] = "'".$valeur;
            }
        }

        return $ligne;
    }

    private function mime(string $format): string
    {
        return match ($format) {
            'csv' => 'text/csv',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'json' => 'application/json',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }
}
