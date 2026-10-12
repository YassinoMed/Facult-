<?php

namespace App\Http\Controllers\Enseignant;

use App\Http\Controllers\Controller;
use App\Models\TeacherRequest;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Gestion des demandes en ligne de l'enseignant (§7).
 * Workflow validé côté serveur, pièces jointes sur stockage privé, anti-IDOR strict.
 */
class DemandeController extends Controller
{
    public function index(Request $request): View
    {
        $enseignant = Teacher::with('departement.faculte')->findOrFail($request->session()->get('enseignant_auth_id'));

        $query = TeacherRequest::where('teacher_id', $enseignant->id)
            ->latest();

        if ($request->filled('statut')) {
            $query->where('statut', $request->input('statut'));
        }

        if ($request->filled('type')) {
            $query->where('type_demande', $request->input('type'));
        }

        $demandes = $query->paginate(15)->withQueryString();

        $statuts = TeacherRequest::STATUTS;
        $types = TeacherRequest::TYPES;

        return view('enseignant.demandes.index', [
            'enseignant' => $enseignant,
            'demandes' => $demandes,
            'statuts' => $statuts,
            'types' => $types,
            'filtreStatut' => $request->input('statut'),
            'filtreType' => $request->input('type'),
        ]);
    }

    public function create(Request $request): View
    {
        $enseignant = Teacher::with('departement.faculte')->findOrFail($request->session()->get('enseignant_auth_id'));
        $types = TeacherRequest::TYPES;

        return view('enseignant.demandes.create', [
            'enseignant' => $enseignant,
            'types' => $types,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $enseignant = Teacher::with('departement.faculte')->findOrFail($request->session()->get('enseignant_auth_id'));

        $valide = $request->validate([
            'type_demande' => 'required|string|in:'.implode(',', array_keys(TeacherRequest::TYPES)),
            'objet' => 'required|string|max:255',
            'description' => 'required|string|max:3000',
            'est_brouillon' => 'sometimes|boolean',
            'piece_jointe' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ], [
            'type_demande.required' => 'Le type de demande est obligatoire.',
            'objet.required' => 'L\'objet de la demande est obligatoire.',
            'description.required' => 'Veuillez préciser la description de votre demande.',
            'piece_jointe.max' => 'La pièce jointe ne doit pas dépasser 5 Mo.',
            'piece_jointe.mimes' => 'La pièce jointe doit être un fichier PDF, JPG ou PNG.',
        ]);

        $statut = $request->boolean('est_brouillon') ? 'brouillon' : 'soumise';

        $pieceJointeChemin = null;
        $pieceJointeNom = null;

        if ($request->hasFile('piece_jointe')) {
            $fichier = $request->file('piece_jointe');
            $pieceJointeNom = $fichier->getClientOriginalName();
            $pieceJointeChemin = $fichier->store('private/enseignant/demandes', 'private');
        }

        $demande = TeacherRequest::create([
            'reference' => TeacherRequest::genererReference(),
            'teacher_id' => $enseignant->id,
            'faculte_id' => $enseignant->faculteId(),
            'type_demande' => $valide['type_demande'],
            'objet' => $valide['objet'],
            'description' => $valide['description'],
            'statut' => $statut,
            'piece_jointe_chemin' => $pieceJointeChemin,
            'piece_jointe_nom' => $pieceJointeNom,
        ]);

        $message = $statut === 'brouillon'
            ? 'Votre demande a été enregistrée en tant que brouillon.'
            : 'Votre demande a été transmise aux services administratifs avec succès.';

        return redirect()->route('enseignant.demandes.show', $demande)
            ->with('success', $message);
    }

    public function show(Request $request, TeacherRequest $demande): View
    {
        $enseignantId = (int) $request->session()->get('enseignant_auth_id');

        // Anti-IDOR : propriétaire de la demande uniquement
        abort_if($demande->teacher_id !== $enseignantId, 404);

        $demande->load(['faculte', 'traiteePar']);

        return view('enseignant.demandes.show', [
            'demande' => $demande,
        ]);
    }

    public function pieceJointe(Request $request, TeacherRequest $demande): StreamedResponse
    {
        $enseignantId = (int) $request->session()->get('enseignant_auth_id');

        abort_if($demande->teacher_id !== $enseignantId, 404);
        abort_if(! $demande->piece_jointe_chemin || ! Storage::disk('private')->exists($demande->piece_jointe_chemin), 404);

        return Storage::disk('private')->download($demande->piece_jointe_chemin, $demande->piece_jointe_nom);
    }

    public function annuler(Request $request, TeacherRequest $demande): RedirectResponse
    {
        $enseignantId = (int) $request->session()->get('enseignant_auth_id');

        abort_if($demande->teacher_id !== $enseignantId, 404);

        if (! $demande->peutEtreAnnulee()) {
            return back()->with('error', 'Cette demande ne peut plus être annulée car elle est déjà prise en charge ou traitée.');
        }

        $demande->update(['statut' => 'annulee']);

        return redirect()->route('enseignant.demandes.show', $demande)
            ->with('success', 'Votre demande a été annulée.');
    }
}
