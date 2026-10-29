<?php

namespace App\Modules\Alumni\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Alumni\Models\Diplome;
use App\Modules\Alumni\Models\ImportDiplomes;
use App\Modules\Alumni\Services\ServiceAlumni;
use App\Modules\Alumni\Services\ServiceImportDiplomes;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Back-office alumni : registre officiel, annuaire et agrégats.
 *
 * Aucune route n'expose de notes, crédits, décisions ou dossier
 * académique. Aucune route employeur n'existe.
 */
final class DiplomeController extends Controller
{
    public function __construct(
        private readonly ServiceImportDiplomes $import,
        private readonly ServiceAlumni $alumni,
    ) {}

    public function index(Request $requete): JsonResponse
    {
        $valides = $requete->validate([
            'annee' => ['nullable', 'integer', 'between:1900,2100'],
            'recherche' => ['nullable', 'string', 'max:60'],
            'programme_id' => ['nullable', 'integer'],
        ]);

        $faculteId = $requete->user()->faculteRestreinteId();

        $query = Diplome::query()
            ->portee($faculteId)
            ->with([
                // Identité minimale : ni CIN, ni e-mail, ni téléphone.
                'etudiant' => fn ($q) => $q->select('id', 'nom', 'prenom'),
                'faculte:id,nom',
                'programme:id,nom',
                'typeDiplome:id,nom',
            ])
            ->latest('date_delivrance')
            ->latest('id');

        if (! empty($valides['annee'])) {
            $query->whereYear('date_delivrance', (int) $valides['annee']);
        }

        if (! empty($valides['programme_id'])) {
            $query->where('programme_id', (int) $valides['programme_id']);
        }

        if (! empty($valides['recherche'])) {
            $query->where('numero_diplome', 'like', '%'.$valides['recherche'].'%');
        }

        return response()->json($query->paginate(50)->withQueryString());
    }

    public function importer(Request $requete): JsonResponse
    {
        $valides = $requete->validate([
            'fichier' => ['required', 'file', 'max:2048', 'mimes:csv,txt'],
        ]);

        $fichier = $valides['fichier'];
        $extension = strtolower((string) $fichier->getClientOriginalExtension());

        // `mimes` valide le type deviné ; l'extension d'origine est vérifiée
        // séparément pour refuser un `.php` au contenu texte.
        if (! in_array($extension, ['csv', 'txt'], true)) {
            return response()->json(['erreur' => 'Seuls les fichiers .csv ou .txt sont acceptés.'], 422);
        }

        $contenu = file_get_contents($fichier->getRealPath());

        if ($contenu === false) {
            return response()->json(['erreur' => 'Fichier illisible.'], 422);
        }

        try {
            $resultat = $this->import->importer(
                $contenu,
                $fichier->getClientOriginalName(),
                $requete->user(),
            );
        } catch (DomainException $e) {
            return response()->json(['erreur' => $e->getMessage()], 422);
        }

        return response()->json(
            $resultat['import']->loadCount('diplomes'),
            $resultat['rejoue'] ? 200 : 201,
        );
    }

    public function imports(Request $requete): JsonResponse
    {
        $imports = ImportDiplomes::query()
            ->withCount('diplomes')
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return response()->json($imports);
    }

    public function showImport(ImportDiplomes $import): JsonResponse
    {
        return response()->json($import->loadCount('diplomes'));
    }

    public function lienConsentement(Request $requete, Diplome $diplome): JsonResponse
    {
        try {
            $lien = $this->alumni->genererLien($diplome, $requete->user());
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        return response()->json($lien);
    }

    public function annuaire(Request $requete): JsonResponse
    {
        $valides = $requete->validate([
            'faculte_id' => ['nullable', 'integer'],
            'programme_id' => ['nullable', 'integer'],
            'annee' => ['nullable', 'integer', 'between:1900,2100'],
            'secteur' => ['nullable', 'string', 'max:80'],
            'recherche' => ['nullable', 'string', 'max:80'],
        ]);

        return response()->json($this->alumni->annuaire($requete->user(), $valides));
    }

    public function agregats(Request $requete): JsonResponse
    {
        return response()->json($this->alumni->agregats($requete->user()));
    }
}
