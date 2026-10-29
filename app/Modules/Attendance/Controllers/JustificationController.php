<?php

namespace App\Modules\Attendance\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Modules\Attendance\Models\Justification;
use App\Modules\Attendance\Models\Presence;
use App\Modules\Attendance\Requests\DeposerJustificationRequest;
use App\Modules\Attendance\Services\AssiduiteService;
use App\Services\AuditService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JustificationController extends Controller
{
    /**
     * File d'attente des justifications (scolarité, scopée faculté).
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Justification::class);

        $query = Justification::with(['etudiant.faculte', 'presence'])
            ->when($request->input('statut'), fn ($q, $statut) => $q->where('statut', $statut))
            ->latest();

        if ($faculteId = $request->user()->faculteRestreinteId()) {
            $query->whereHas('etudiant', fn ($q) => $q->where('faculte_id', $faculteId));
        }

        return view('modules.attendance.justifications', [
            'justifications' => $query->paginate(15)->withQueryString(),
        ]);
    }

    public function valider(Request $request, Justification $justification, AssiduiteService $service): RedirectResponse
    {
        Gate::authorize('validate', $justification);

        $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'commentaire_validation' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $service->validerJustification(
                $justification,
                (int) $request->user()->id,
                $request->input('decision') === 'approved',
                $request->input('commentaire_validation'),
            );
        } catch (DomainException $e) {
            return back()->withErrors(['justification' => $e->getMessage()]);
        }

        return back()->with('success', 'Justification traitée.');
    }

    /**
     * Dépôt étudiant : SA justification uniquement (session-scopé).
     */
    public function deposer(DeposerJustificationRequest $request, Presence $presence, AssiduiteService $service): RedirectResponse
    {
        $etudiant = Etudiant::findOrFail($request->session()->get('etudiant_auth_id'));

        abort_unless((int) $presence->etudiant_id === (int) $etudiant->id, 404);

        $valides = $request->validated();

        $chemin = null;
        $nom = null;

        if ($request->hasFile('piece')) {
            $fichier = $request->file('piece');
            $nom = $fichier->getClientOriginalName();
            $chemin = $fichier->storeAs(
                "justifications/etudiant_{$etudiant->id}",
                (string) Str::uuid().'.'.$fichier->getClientOriginalExtension(),
                'local'
            );
        }

        $service->deposerJustification($presence, $etudiant, $valides['motif'], $chemin, $nom);

        return back()->with('success', 'Justification déposée, en attente de vérification.');
    }

    /**
     * D-02 — téléchargement de la pièce par son propriétaire étudiant.
     *
     * Session étudiante uniquement, appartenance vérifiée, justification
     * validée (`approved`) seulement : aucun état en attente, rejeté ou
     * ambigu n'est exposé. Toute anomalie répond 404, sans confirmer
     * l'existence d'un document tiers.
     */
    public function telechargerPieceEtudiant(Request $request, Justification $justification, AuditService $audit): StreamedResponse
    {
        $etudiant = Etudiant::findOrFail($request->session()->get('etudiant_auth_id'));

        abort_unless((int) $justification->etudiant_id === (int) $etudiant->id, 404);
        abort_unless($justification->statut === 'approved', 404);

        $chemin = $this->cheminPieceValide($justification);
        abort_if($chemin === null, 404);

        $mime = $this->mimePieceAutorise($chemin);
        abort_if($mime === null, 404);

        $audit->tracer(
            $etudiant,
            'attendance.justificatif_telecharge',
            'justification',
            $justification->id,
            null,
            ['par' => 'etudiant', 'statut' => $justification->statut],
        );

        return $this->reponsePiece($justification, $chemin, $mime);
    }

    /**
     * D-02 — téléchargement de la pièce par la scolarité validatrice.
     *
     * Réutilise `JustificationPolicy::validate` (permission
     * `attendance.justify` + périmètre faculté) : seuls les agents
     * habilités à statuer voient la pièce, tous statuts nécessaires à
     * la décision. Le refus répond 404, comme la pièce jointe des
     * régularisations, pour ne pas révéler l'existence d'un document.
     */
    public function telechargerPiece(Request $request, Justification $justification, AuditService $audit): StreamedResponse
    {
        abort_unless((bool) $request->user()?->can('validate', $justification), 404);

        $chemin = $this->cheminPieceValide($justification);
        abort_if($chemin === null, 404);

        $mime = $this->mimePieceAutorise($chemin);
        abort_if($mime === null, 404);

        $audit->tracer(
            $justification->etudiant,
            'attendance.justificatif_telecharge',
            'justification',
            $justification->id,
            null,
            ['par' => 'scolarite', 'statut' => $justification->statut],
        );

        return $this->reponsePiece($justification, $chemin, $mime);
    }

    /**
     * Chemin serveur uniquement : `piece_chemin` est écrit au dépôt,
     * jamais fourni par le client. Rejet restrictif des chemins vides,
     * absolus, malformés, hors du dossier de l'étudiant ou contenant
     * une traversée de répertoires.
     */
    private function cheminPieceValide(Justification $justification): ?string
    {
        $chemin = $justification->piece_chemin;

        if (! is_string($chemin) || trim($chemin) === '') {
            return null;
        }

        if (str_contains($chemin, "\0") || str_contains($chemin, '\\')) {
            return null;
        }

        if (str_starts_with($chemin, '/') || preg_match('/^[A-Za-z]:\//', $chemin) === 1) {
            return null;
        }

        foreach (explode('/', $chemin) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        $prefixe = "justifications/etudiant_{$justification->etudiant_id}/";

        return str_starts_with($chemin, $prefixe) ? $chemin : null;
    }

    /**
     * MIME réel du fichier stocké, limité à la liste blanche du dépôt
     * (`DeposerJustificationRequest`). Un contenu actif ou inconnu ne
     * est jamais servi.
     */
    private function mimePieceAutorise(string $chemin): ?string
    {
        $disque = Storage::disk('local');

        if (! $disque->exists($chemin)) {
            return null;
        }

        $mime = $disque->mimeType($chemin);

        if (! is_string($mime) || ! in_array($mime, DeposerJustificationRequest::MIME_PAR_EXTENSION, true)) {
            return null;
        }

        return $mime;
    }

    /**
     * Réponse de téléchargement contrôlée : disque privé `local`,
     * disposition `attachment`, type MIME vérifié, nom assaini, aucun
     * cache intermédiaire.
     */
    private function reponsePiece(Justification $justification, string $chemin, string $mime): StreamedResponse
    {
        return Storage::disk('local')->download(
            $chemin,
            $this->nomTelechargement($justification, $chemin),
            [
                'Content-Type' => $mime,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
        );
    }

    /**
     * Nom de téléchargement maîtrisé : le nom d'origine est fourni par
     * le client au dépôt. Même règle que
     * `DocumentService::nomTelechargementSecurise` (basename, retrait
     * des caractères de contrôle, guillemets et antislashs), complétée
     * par l'extension du fichier stocké si elle manque.
     */
    private function nomTelechargement(Justification $justification, string $chemin): string
    {
        $nom = preg_replace(
            '/[\x00-\x1F\x7F"\\\\]+/',
            '',
            basename((string) $justification->piece_nom),
        ) ?? '';
        $nom = trim($nom, ' .');

        if ($nom === '') {
            $nom = 'justificatif';
        }

        if (pathinfo($nom, PATHINFO_EXTENSION) === '') {
            $extension = pathinfo($chemin, PATHINFO_EXTENSION);

            if ($extension !== '') {
                $nom .= '.'.$extension;
            }
        }

        return mb_substr($nom, 0, 255);
    }
}
