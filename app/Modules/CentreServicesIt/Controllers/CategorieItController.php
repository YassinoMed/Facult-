<?php

namespace App\Modules\CentreServicesIt\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CentreServicesIt\Models\CategorieIt;
use App\Modules\CentreServicesIt\Services\ServiceTicketsIt;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Catalogue IT : lecture pour tous les agents, écriture réservée à la
 * permission `it.categories` (le catalogue est global, pas facultaire).
 *
 * Le catalogue par défaut est modifiable ; aucune valeur n'est gravée
 * comme norme métier.
 */
final class CategorieItController extends Controller
{
    public function __construct(private readonly ServiceTicketsIt $service) {}

    public function index(): JsonResponse
    {
        return response()->json(
            CategorieIt::query()->orderByDesc('actif')->orderBy('libelle')->get()
        );
    }

    public function store(Request $requete): JsonResponse
    {
        $valides = $requete->validate([
            'code' => ['required', 'string', 'regex:/^[a-z0-9-]{2,40}$/'],
            'libelle' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'sla_jours_p1' => ['required', 'integer', 'between:1,30'],
            'sla_jours_p2' => ['required', 'integer', 'between:1,30'],
            'sla_jours_p3' => ['required', 'integer', 'between:1,30'],
            'priorite_defaut' => ['required', Rule::in(CategorieIt::PRIORITES)],
        ]);

        try {
            $categorie = $this->service->creerCategorie($valides);
        } catch (DomainException $e) {
            return response()->json(['erreur' => $e->getMessage()], 409);
        }

        return response()->json($categorie, 201);
    }

    public function update(Request $requete, CategorieIt $categorie): JsonResponse
    {
        $valides = $requete->validate([
            'libelle' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sla_jours_p1' => ['sometimes', 'integer', 'between:1,30'],
            'sla_jours_p2' => ['sometimes', 'integer', 'between:1,30'],
            'sla_jours_p3' => ['sometimes', 'integer', 'between:1,30'],
            'priorite_defaut' => ['sometimes', Rule::in(CategorieIt::PRIORITES)],
            'actif' => ['sometimes', 'boolean'],
        ]);

        try {
            $categorie = $this->service->modifierCategorie($categorie, $valides);
        } catch (DomainException $e) {
            return response()->json(['erreur' => $e->getMessage()], 409);
        }

        return response()->json($categorie);
    }
}
