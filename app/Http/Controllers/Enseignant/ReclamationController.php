<?php

namespace App\Http\Controllers\Enseignant;

use App\Http\Controllers\Controller;
use App\Models\TeacherReclamation;
use App\Modules\Attendance\Models\TeacherPresence;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Signalements et réclamations des enseignants (§8).
 * Permet de signaler des anomalies sur planning, présences, surveillances, etc.
 * Stockage sécurisé des pièces jointes, historique traçable, anti-IDOR strict.
 */
class ReclamationController extends Controller
{
    public function index(Request $request): View
    {
        $enseignant = Teacher::with('departement.faculte')->findOrFail($request->session()->get('enseignant_auth_id'));

        $query = TeacherReclamation::where('teacher_id', $enseignant->id)
            ->latest();

        if ($request->filled('statut')) {
            $query->where('statut', $request->input('statut'));
        }

        if ($request->filled('categorie')) {
            $query->where('categorie', $request->input('categorie'));
        }

        $reclamations = $query->paginate(15)->withQueryString();

        $statuts = TeacherReclamation::STATUTS;
        $categories = TeacherReclamation::CATEGORIES;

        return view('enseignant.reclamations.index', [
            'enseignant' => $enseignant,
            'reclamations' => $reclamations,
            'statuts' => $statuts,
            'categories' => $categories,
            'filtreStatut' => $request->input('statut'),
            'filtreCategorie' => $request->input('categorie'),
        ]);
    }

    public function create(Request $request): View
    {
        $enseignant = Teacher::with('departement.faculte')->findOrFail($request->session()->get('enseignant_auth_id'));
        $categories = TeacherReclamation::CATEGORIES;

        return view('enseignant.reclamations.create', [
            'enseignant' => $enseignant,
            'categories' => $categories,
            'categoriePreselectionnee' => $request->query('categorie'),
            'objetPreselectionne' => $request->query('objet'),
            'presenceId' => $request->query('presence_id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $enseignant = Teacher::with('departement.faculte')->findOrFail($request->session()->get('enseignant_auth_id'));

        $valide = $request->validate([
            'categorie' => 'required|string|in:'.implode(',', array_keys(TeacherReclamation::CATEGORIES)),
            'objet' => 'required|string|max:255',
            'description' => 'required|string|max:3000',
            'priorite' => 'sometimes|in:normale,urgente',
            'piece_jointe' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'presence_id' => 'nullable|integer|exists:teacher_presences,id',
        ], [
            'categorie.required' => 'La catégorie de signalement est obligatoire.',
            'objet.required' => 'L\'objet est obligatoire.',
            'description.required' => 'La description détaillée est requise.',
            'piece_jointe.max' => 'La pièce jointe ne doit pas dépasser 5 Mo.',
            'piece_jointe.mimes' => 'La pièce jointe doit être un fichier PDF, JPG ou PNG.',
        ]);

        // Liaison régularisation↔présence : la présence contestée doit
        // appartenir à l'enseignant connecté (ownership serveur, 422 sinon)
        // et impose la catégorie `presence`.
        $presenceId = null;
        if (! empty($valide['presence_id'])) {
            $presence = TeacherPresence::findOrFail($valide['presence_id']);
            abort_if((int) $presence->teacher_id !== (int) $enseignant->id, 404);

            if ($valide['categorie'] !== 'presence') {
                return back()->withErrors(['categorie' => 'Une contestation de présence exige la catégorie « Présence / Pointage contesté ».'])->withInput();
            }

            $presenceId = $presence->id;
        }

        $pieceJointeChemin = null;
        $pieceJointeNom = null;

        if ($request->hasFile('piece_jointe')) {
            $fichier = $request->file('piece_jointe');
            $pieceJointeNom = $fichier->getClientOriginalName();
            $pieceJointeChemin = $fichier->store('private/enseignant/reclamations', 'private');
        }

        $historique = [
            [
                'date' => now()->toIso8601String(),
                'action' => 'creation',
                'auteur' => $enseignant->nomComplet(),
                'message' => 'Signalement enregistré par l\'enseignant.',
            ],
        ];

        $reclamation = TeacherReclamation::create([
            'reference' => TeacherReclamation::genererReference(),
            'teacher_id' => $enseignant->id,
            'teacher_presence_id' => $presenceId,
            'faculte_id' => $enseignant->faculteId(),
            'categorie' => $valide['categorie'],
            'objet' => $valide['objet'],
            'description' => $valide['description'],
            'priorite' => $valide['priorite'] ?? 'normale',
            'statut' => 'ouverte',
            'piece_jointe_chemin' => $pieceJointeChemin,
            'piece_jointe_nom' => $pieceJointeNom,
            'historique' => $historique,
        ]);

        return redirect()->route('enseignant.reclamations.show', $reclamation)
            ->with('success', 'Votre signalement a été enregistré avec la référence '.$reclamation->reference.'.');
    }

    public function show(Request $request, TeacherReclamation $reclamation): View
    {
        $enseignantId = (int) $request->session()->get('enseignant_auth_id');

        // Anti-IDOR : consultation réservée à l'enseignant propriétaire
        abort_if($reclamation->teacher_id !== $enseignantId, 404);

        $reclamation->load(['faculte', 'traiteePar']);

        return view('enseignant.reclamations.show', [
            'reclamation' => $reclamation,
        ]);
    }

    public function pieceJointe(Request $request, TeacherReclamation $reclamation): StreamedResponse
    {
        $enseignantId = (int) $request->session()->get('enseignant_auth_id');

        abort_if($reclamation->teacher_id !== $enseignantId, 404);
        abort_if(! $reclamation->piece_jointe_chemin || ! Storage::disk('private')->exists($reclamation->piece_jointe_chemin), 404);

        return Storage::disk('private')->download($reclamation->piece_jointe_chemin, $reclamation->piece_jointe_nom);
    }

    public function cloturer(Request $request, TeacherReclamation $reclamation): RedirectResponse
    {
        $enseignantId = (int) $request->session()->get('enseignant_auth_id');

        abort_if($reclamation->teacher_id !== $enseignantId, 404);

        if (! $reclamation->peutEtreCloturee()) {
            return back()->with('error', 'Cette réclamation est déjà clôturée.');
        }

        $historique = $reclamation->historique ?? [];
        $historique[] = [
            'date' => now()->toIso8601String(),
            'action' => 'cloture',
            'auteur' => 'Enseignant',
            'message' => 'Clôture volontaire par l\'enseignant.',
        ];

        $reclamation->update([
            'statut' => 'fermee',
            'historique' => $historique,
        ]);

        return redirect()->route('enseignant.reclamations.show', $reclamation)
            ->with('success', 'Votre réclamation a été clôturée.');
    }
}
