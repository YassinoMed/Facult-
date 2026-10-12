<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DocumentAcademique;
use App\Modules\OnlineRequests\Models\RequestAttachment;
use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Support\Recherche;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * GED — recherche documentaire unifiée (§22.11) : les 4 silos
 * (archives, dossiers, demandes, générés) en lecture seule, chaque
 * résultat pointant vers sa route de téléchargement autorisée.
 * Aucun stockage déplacé, aucun doublon créé.
 */
class GedController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->can('documents.view'), 403);

        $q = trim($request->input('q', ''));
        $resultats = [];
        $faculteId = $request->user()->faculteRestreinteId();

        if (mb_strlen($q) >= 2) {
            $motif = Recherche::contient($q);

            if ($request->user()->can('documents.view')) {
                $resultats['Archives académiques'] = DocumentAcademique::with('etudiant')
                    ->where(fn ($w) => $w->where('nom_original', 'like', $motif)->orWhere('type_document', 'like', $motif))
                    ->when($faculteId !== null, fn ($w) => $w->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculteId)))
                    ->limit(10)->get()
                    ->map(fn ($d) => [
                        'titre' => $d->nom_original,
                        'detail' => $d->type_document.' · '.$d->etudiant?->nom_complet,
                        'lien' => route('admin.archives.documents.download', $d),
                    ]);
            }

            if ($request->user()->can('student_documents.view')) {
                $resultats['Dossiers étudiants'] = StudentDocument::with('dossier.etudiant')
                    ->where(fn ($w) => $w->where('title', 'like', $motif)->orWhere('original_filename', 'like', $motif)->orWhere('category', 'like', $motif))
                    ->when($faculteId !== null, fn ($w) => $w->whereHas('dossier.etudiant', fn ($e) => $e->where('faculte_id', $faculteId)))
                    ->limit(10)->get()
                    ->map(fn ($d) => [
                        'titre' => $d->title,
                        'detail' => $d->category.' · '.$d->status.' · '.$d->dossier?->etudiant?->nom_complet,
                        'lien' => route('admin.student-dossiers.documents.download', [$d->student_dossier_id, $d]),
                    ]);
            }

            if ($request->user()->can('demandes.documents.download')) {
                $resultats['Pièces de demandes'] = RequestAttachment::with('demande.etudiant')
                    ->where(fn ($w) => $w->where('original_filename', 'like', $motif))
                    ->when($faculteId !== null, fn ($w) => $w->whereHas('demande', fn ($x) => $x->where('faculte_id', $faculteId)))
                    ->limit(10)->get()
                    ->map(fn ($d) => [
                        'titre' => $d->original_filename,
                        'detail' => $d->demande?->reference.' · '.$d->demande?->etudiant?->nom_complet,
                        'lien' => route('admin.demandes.pieces.download', [$d->demande, $d]),
                    ]);
            }
        }

        return view('admin.ged.index', ['q' => $q, 'sections' => $resultats]);
    }
}
