<?php

namespace App\Modules\Referentiel\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Referentiel\Services\ReordonnancementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Réordonnancement drag-drop (§18-19) : JSON {type, ordres}, contrôleur fin.
 */
class ReordonnancementController extends Controller
{
    public function __construct(
        private readonly ReordonnancementService $ordres,
    ) {}

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('referentiel.manage');

        $valide = $request->validate([
            'type' => 'required|in:ue,matiere',
            'ordres' => 'required|array|min:1|max:500',
            'ordres.*' => 'integer|min:0|max:65000',
        ]);

        $nombre = $this->ordres->reordonner(
            $valide['type'],
            array_map('intval', $valide['ordres']),
            $request->user(),
        );

        return response()->json(['message' => "{$nombre} élément(s) réordonné(s).", 'count' => $nombre]);
    }
}
