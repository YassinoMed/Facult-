<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Io\Templates\ImportTemplateDefinition;
use App\Services\Io\Templates\ImportTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API des modèles d'import (§114) : catalogue par permission du token,
 * schéma détaillé, téléchargement — auth, scopes, rate limiting.
 */
class ImportTemplateApiController extends Controller
{
    public function __construct(private readonly ImportTemplateService $modeles) {}

    /**
     * GET /api/v1/import-templates — catalogue des types autorisés pour CE token.
     */
    public function index(Request $request): JsonResponse
    {
        $catalogue = [];
        foreach (ImportTemplateDefinition::definitions() as $type => $definition) {
            $scopesRequis = $definition['permissionApi'];
            $autorise = collect($scopesRequis)->every(fn ($s) => $request->user()?->tokenCan($s));

            if ($autorise) {
                $catalogue[] = [
                    'type' => $type,
                    'label' => $definition['label'],
                    'description' => $definition['description'],
                    'version' => ImportTemplateDefinition::VERSIONS[$type],
                    'formats' => $definition['formats'],
                    'colonnes' => array_keys($definition['colonnes']),
                ];
            }
        }

        return response()->json(['data' => $catalogue]);
    }

    /**
     * GET /api/v1/import-templates/{type} — schéma détaillé.
     */
    public function show(Request $request, string $type): JsonResponse
    {
        $definition = ImportTemplateDefinition::definition($type);
        abort_if($definition === null, 404);

        $autorise = collect($definition['permissionApi'])->every(fn ($s) => $request->user()?->tokenCan($s));
        abort_unless($autorise, 403, 'Le token ne permet pas d\'accéder à ce modèle.');

        return response()->json([
            'data' => [
                'type' => $type,
                'label' => $definition['label'],
                'version' => ImportTemplateDefinition::VERSIONS[$type],
                'formats' => $definition['formats'],
                'colonnes' => collect($definition['colonnes'])->map(fn ($meta, $cle) => [
                    'colonne' => $cle,
                    'obligatoire' => $meta['obligatoire'],
                    'format' => $meta['format'],
                    'exemple' => $meta['exemple'],
                    'description' => $meta['description'],
                ])->values(),
                'references' => ImportTemplateDefinition::referencesDynamiques(),
            ],
        ]);
    }

    /**
     * GET /api/v1/import-templates/{type}/download?format=xlsx — génération.
     */
    public function download(Request $request, string $type)
    {
        $definition = ImportTemplateDefinition::definition($type);
        abort_if($definition === null, 404);

        $autorise = collect($definition['permissionApi'])->every(fn ($s) => $request->user()?->tokenCan($s));
        abort_unless($autorise, 403);

        $format = (string) $request->query('format', $definition['formats'][0] ?? 'xlsx');
        abort_unless(in_array($format, $definition['formats'], true), 422, 'Format non disponible.');

        $fichier = $this->modeles->generer($type, $format, ! $request->boolean('vide'));

        return response()->streamDownload(
            fn () => print ($fichier['contenu']),
            $fichier['nom'],
            ['Content-Type' => $fichier['mime']],
        );
    }
}
