<?php

namespace App\Http\Controllers\Enseignant;

use App\Http\Controllers\Controller;
use App\Models\TeacherDocument;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Consultation et téléchargement sécurisé des documents personnels de l'enseignant (§5).
 * Disque privé (storage/app/private), contrôle strict d'accès anti-IDOR.
 */
class DocumentController extends Controller
{
    public function index(Request $request): View
    {
        $enseignant = Teacher::with('departement.faculte')->findOrFail($request->session()->get('enseignant_auth_id'));

        $query = TeacherDocument::where('teacher_id', $enseignant->id)
            ->with(['faculte', 'publiePar'])
            ->latest();

        if ($request->filled('categorie')) {
            $query->where('categorie', $request->input('categorie'));
        }

        if ($request->filled('recherche')) {
            $terme = '%'.$request->input('recherche').'%';
            $query->where(function ($q) use ($terme) {
                $q->where('titre', 'like', $terme)
                    ->orWhere('description', 'like', $terme)
                    ->orWhere('nom_fichier', 'like', $terme);
            });
        }

        $documents = $query->paginate(15)->withQueryString();

        $categories = TeacherDocument::CATEGORIES;

        return view('enseignant.documents', [
            'enseignant' => $enseignant,
            'documents' => $documents,
            'categories' => $categories,
            'filtreCategorie' => $request->input('categorie'),
            'recherche' => $request->input('recherche'),
        ]);
    }

    /**
     * Téléchargement privé et sécurisé du fichier (anti-IDOR, vérification ownership et faculté).
     */
    public function download(Request $request, TeacherDocument $document): StreamedResponse
    {
        $enseignantId = (int) $request->session()->get('enseignant_auth_id');
        $enseignant = Teacher::with('departement')->findOrFail($enseignantId);

        // Anti-IDOR : l'enseignant ne peut télécharger QUE ses propres documents
        abort_if($document->teacher_id !== $enseignant->id, 404);

        // Contrôle de périmètre facultaire
        if ($document->faculte_id !== null && $enseignant->faculteId() !== null) {
            abort_if($document->faculte_id !== $enseignant->faculteId(), 403, 'Document hors de votre périmètre facultaire.');
        }

        // Vérification de l'existence sur disque privé
        if (! Storage::disk('private')->exists($document->chemin)) {
            abort(404, 'Fichier introuvable sur le stockage sécurisé.');
        }

        return Storage::disk('private')->download(
            $document->chemin,
            $document->nom_fichier,
            [
                'Content-Type' => $document->mime_type ?: 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}
