<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ImportReport;
use App\Services\Io\ImportOrchestrator;
use App\Services\Io\IoException;
use App\Services\Io\Templates\ImportTemplateDefinition;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API v1 — Imports en deux phases (§37-40) :
 *
 *  POST /api/v1/imports                    → analyse + prévisualisation (202)
 *  POST /api/v1/imports/{report}/confirm   → exécution (202, sync ou job)
 *  GET  /api/v1/imports/{report}           → rapport détaillé
 *
 * Un rapport n'est consultable/confirmable que par son propriétaire (anti-IDOR).
 */
class ImportApiController extends Controller
{
    public function __construct(private readonly ImportOrchestrator $imports) {}

    /**
     * Phase 1 : upload + analyse. Aucune écriture métier.
     */
    public function store(Request $request): JsonResponse
    {
        $valide = $request->validate([
            'type' => 'required|in:'.implode(',', ImportTemplateDefinition::types()),
            'file' => [
                'required',
                'file',
                'max:10240',
                'mimes:csv,txt,json,xml,xlsx',
            ],
        ], [
            'type.in' => 'Le type doit faire partie de : '.implode(', ', ImportTemplateDefinition::types()).'.',
            'file.required' => 'Un fichier est requis.',
            'file.max' => 'Le fichier ne doit pas dépasser 10 Mo.',
            'file.mimes' => 'Formats acceptés : CSV, JSON, XML, XLSX.',
        ]);

        try {
            $rapport = $this->imports->preparer(
                $request->file('file'),
                $valide['type'],
                $request->user()?->id,
                'api',
            );
        } catch (IoException|DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Fichier analysé. Confirmez l\'import pour exécuter.',
            'report' => $this->rapportJson($rapport),
            'preview' => $rapport->preview,
        ], 202);
    }

    /**
     * Phase 2 : confirmation — mode atomique ou partiel (§39).
     */
    public function confirm(Request $request, ImportReport $report): JsonResponse
    {
        abort_if($report->user_id !== $request->user()?->id, 404);

        $valide = $request->validate([
            'mode' => 'required|in:atomique,partiel',
        ], [
            'mode.in' => 'Le mode doit être « atomique » ou « partiel ».',
        ]);

        try {
            $rapport = $this->imports->confirmer($report, $valide['mode']);
        } catch (IoException|DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $code = $rapport->status === 'processing' ? 202 : 200;

        return response()->json([
            'message' => $rapport->status === 'processing'
                ? 'Import volumineux accepté — traitement en file d\'attente.'
                : 'Import terminé.',
            'report' => $this->rapportJson($rapport),
        ], $code);
    }

    /**
     * Rapport d'import (§40).
     */
    public function show(Request $request, ImportReport $report): JsonResponse
    {
        abort_if($report->user_id !== $request->user()?->id, 404);

        return response()->json([
            'report' => $this->rapportJson($report->fresh()),
            'errors' => $report->errors ?? [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rapportJson(ImportReport $rapport): array
    {
        return [
            'id' => $rapport->id,
            'reference' => $rapport->reference,
            'direction' => $rapport->direction,
            'type' => $rapport->type,
            'format' => $rapport->format,
            'status' => $rapport->status,
            'statut_libelle' => $rapport->statut_libelle,
            'total' => $rapport->total_rows,
            'valides' => $rapport->valid_rows,
            'doublons' => $rapport->duplicate_rows,
            'erreurs' => $rapport->error_rows,
            'duree_ms' => $rapport->duration_ms,
            'created_at' => $rapport->created_at?->toIso8601String(),
        ];
    }
}
