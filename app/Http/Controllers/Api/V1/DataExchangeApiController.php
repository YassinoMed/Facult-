<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DataExchange\DataExportService;
use App\Services\DataExchange\DataImportService;
use App\Services\DataExchange\DefinitionRegistry;
use App\Services\Io\IoException;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * API d'échange unifiée (§31-33) : stateless (fichier re-transmis à
 * chaque phase), preview = dry-run sans écriture (§32), scope dédié
 * data:exchange, throttle, audit avec request_id (§34).
 */
class DataExchangeApiController extends Controller
{
    public function __construct(
        private readonly DataImportService $imports,
        private readonly DataExportService $exports,
        private readonly DefinitionRegistry $definitions,
    ) {}

    /**
     * Phase 1 / dry-run : analyse sans aucune écriture.
     */
    public function preview(Request $request, string $entite): JsonResponse
    {
        $this->verifierEntite($entite);

        $request->validate([
            'fichier' => 'required|file|max:10240|mimetypes:text/plain,text/csv,application/csv,application/json,application/xml,text/xml,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip',
            'dry_run' => 'sometimes|boolean',
        ]);

        try {
            ['format' => $format, 'resultat' => $resultat] = $this->imports->analyser(
                $request->file('fichier'), $entite, (int) $request->user()->id
            );
        } catch (IoException|DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json([
            'valid' => ! $resultat->bloque(),
            'format' => $format,
            'total_rows' => $resultat->total(),
            'valid_rows' => count($resultat->valides),
            'warnings' => count($resultat->avertissements),
            'errors' => count($resultat->erreurs),
            'detail_erreurs' => array_slice($resultat->erreurs, 0, 50),
        ]);
    }

    /**
     * Phase 2 : le fichier est re-transmis (stateless), re-validé côté
     * serveur, puis persisté en transaction unique. Seules les lignes
     * valides sont importées ; les erreurs bloquent (422 + détail).
     */
    public function confirmer(Request $request, string $entite): JsonResponse
    {
        $definition = $this->verifierEntite($entite);

        $valide = $request->validate([
            'fichier' => 'required|file|max:10240|mimetypes:text/plain,text/csv,application/csv,application/json,application/xml,text/xml,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip',
            'mode' => 'sometimes|in:creer,upsert',
        ]);

        $mode = $valide['mode'] ?? 'creer';

        try {
            ['format' => $format, 'lignes' => $lignes] = $this->imports->analyser(
                $request->file('fichier'), $entite, (int) $request->user()->id
            );
            $recontrole = $this->imports->revalider($definition, $lignes, (int) $request->user()->id);

            if ($recontrole->bloque()) {
                return response()->json([
                    'message' => 'Le fichier contient des erreurs bloquantes.',
                    'detail_erreurs' => array_slice($recontrole->erreurs, 0, 50),
                ], 422);
            }

            $hash = hash_file('sha256', $request->file('fichier')->getRealPath());
            ['rapport' => $rapport, 'importes' => $importes] = $this->imports->executer(
                $definition, $entite, $lignes,
                $request->file('fichier')->getClientOriginalName(), $format,
                $mode, (int) $request->user()->id, 'api', $hash === false ? null : $hash,
            );
        } catch (IoException|DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json([
            'message' => 'Import terminé.',
            'rapport_id' => $rapport->id,
            'importes' => $importes,
        ]);
    }

    public function exporter(Request $request, string $entite, string $format): StreamedResponse
    {
        // P1 (LOOP 3) — l'export se valide contre le registre d'EXPORT
        // (pas celui d'import) : les entités export-seules (presences,
        // factures, demandes…) sont servies, les inconnues restent 404.
        try {
            $definition = $this->definitions->export($entite);
        } catch (\InvalidArgumentException) {
            abort(404);
        }

        abort_unless(in_array($format, $definition->formats(), true), 404);

        // Entités sensibles : même garde métier que le web (jamais
        // d'exfiltration via l'API avec le seul scope data:exchange).
        foreach (['factures' => 'finances.view'] as $sensible => $permission) {
            if ($entite === $sensible) {
                abort_unless($request->user()->can($permission), 403);
            }
        }

        $export = $this->exports->exporter($request, $entite, $format);

        return response()->streamDownload(
            function () use ($export) {
                echo $export['contenu'];
            },
            $export['nom'],
            ['Content-Type' => $export['mime'], 'X-Content-Type-Options' => 'nosniff']
        );
    }

    private function verifierEntite(string $entite)
    {
        try {
            return $this->definitions->import($entite);
        } catch (\InvalidArgumentException) {
            abort(404);
        }
    }
}
