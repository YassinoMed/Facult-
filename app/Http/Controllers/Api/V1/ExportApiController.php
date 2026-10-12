<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Faculte;
use App\Models\ImportReport;
use App\Models\User;
use App\Services\Io\ExportOrchestrator;
use App\Services\Io\Exports\ExportFilters;
use App\Services\Io\IoException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API v1 — Exports sécurisés (§41-43) :
 *
 *  POST /api/v1/exports                     → création (profil + filtres)
 *  GET  /api/v1/exports/{report}            → statut du rapport
 *  GET  /api/v1/exports/{report}/download   → téléchargement (propriétaire)
 *
 * Profils en liste blanche — jamais d'export non filtré « ?all=true ».
 */
class ExportApiController extends Controller
{
    public function __construct(private readonly ExportOrchestrator $exports) {}

    public function store(Request $request): JsonResponse
    {
        $valide = $request->validate(array_merge([
            'type' => ['required', 'in:'.implode(',', ExportFilters::TYPES)],
            'format' => 'required|in:csv,json,xml,xlsx',
            'profil' => 'nullable|in:administratif,academique,statistique',
            'granularite' => ['nullable', 'in:'.implode(',', ExportFilters::GRANULARITES)],
            'columns' => ['nullable', 'array'],
            'columns.*' => ['string', 'max:30'],
        ],
            // §70/§88 : filtres multicritères centralisés en liste blanche
            ExportFilters::regles(),
        ), array_merge([
            'type.in' => 'Le type doit faire partie de : '.implode(', ', ExportFilters::TYPES).'.',
            'format.in' => 'Le format doit être csv, json, xml ou xlsx.',
            'profil.in' => 'Le profil doit être administratif, academique ou statistique.',
        ], ExportFilters::messages()));

        // Liste blanche STRICTE : toute clé de filtre inconnue est rejetée (§42/§59/§84)
        $clesInconnues = ExportFilters::clesInconnues((array) $request->input('filters', []));

        if ($clesInconnues !== []) {
            return response()->json([
                'message' => 'Filtre non autorisé : '.implode(', ', $clesInconnues).'.',
                'allowed' => ExportFilters::CLES_AUTORISEES,
            ], 422);
        }

        try {
            $rapport = $this->exports->preparer(
                type: $valide['type'],
                format: $valide['format'],
                profil: $valide['profil'] ?? 'administratif',
                filtres: $this->bornerFaculte((array) ($request->input('filters') ?? []), $request->user()),
                userId: $request->user()?->id,
                integration: 'api',
                colonnes: $valide['columns'] ?? null,
                granularite: $valide['granularite'] ?? null,
                utilisateur: $request->user(),
            );
        } catch (IoException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => $rapport->status === 'completed'
                ? 'Export généré.'
                : 'Export volumineux accepté — traitement en file d\'attente.',
            'report' => [
                'id' => $rapport->id,
                'reference' => $rapport->reference,
                'status' => $rapport->status,
                'total' => $rapport->total_rows,
                'download_url' => route('api.v1.exports.download', $rapport),
            ],
        ], 201);
    }

    public function show(Request $request, ImportReport $report): JsonResponse
    {
        abort_if($report->user_id !== $request->user()?->id || $report->direction !== 'export', 404);

        return response()->json([
            'report' => [
                'id' => $report->id,
                'reference' => $report->reference,
                'status' => $report->status,
                'statut_libelle' => $report->statut_libelle,
                'total' => $report->total_rows,
                'duree_ms' => $report->duration_ms,
                'format' => $report->format,
                'created_at' => $report->created_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Téléchargement : propriétaire du rapport uniquement (anti-IDOR).
     */
    public function download(Request $request, ImportReport $report)
    {
        abort_if($report->user_id !== $request->user()?->id || $report->direction !== 'export', 404);

        return $this->exports->telecharger($report, $request->user());
    }

    /**
     * Isolation par faculté (§17) : un token appartenant à un admin_faculte
     * ne peut exporter QUE sa faculté — quel que soit le filtre demandé.
     * Le code faculté est résolu en base, jamais depuis le client.
     *
     * @param  array<string, mixed>  $filtres
     * @return array<string, mixed>
     */
    private function bornerFaculte(array $filtres, ?User $utilisateur): array
    {
        $faculteId = $utilisateur?->faculteRestreinteId();

        if ($faculteId !== null) {
            $filtres['faculte'] = Faculte::find($faculteId)?->code;
        }

        return $filtres;
    }
}
