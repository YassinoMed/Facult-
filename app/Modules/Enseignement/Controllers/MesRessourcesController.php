<?php

namespace App\Modules\Enseignement\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Matiere;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Enseignement\Models\RessourcePedagogique;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Teacher;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Espace Enseignant — supports de cours de MES ECUE (LOOP 06).
 *
 * Publication limitée aux ECUE affectés (même règle d'éligibilité que la
 * saisie des notes). Stockage privé, nom serveur UUID, cohérence
 * extension ↔ MIME réel + signatures magiques (convention GED), cycle
 * serveur brouillon → publié → archivé.
 */
class MesRessourcesController extends Controller
{
    /**
     * MIME réels acceptés par extension (devinés depuis le contenu,
     * jamais l'en-tête client — convention DocumentStorageService).
     *
     * @var array<string, array<int, string>>
     */
    private const MIMES_PAR_EXTENSION = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'ppt' => ['application/vnd.ms-powerpoint'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'xls' => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'txt' => ['text/plain'],
        'md' => ['text/plain', 'text/markdown'],
        'csv' => ['text/plain', 'text/csv'],
        'zip' => ['application/zip'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
    ];

    public function index(Request $request): View
    {
        $enseignant = $this->enseignantConnecte($request);

        $ressources = RessourcePedagogique::with(['matiere', 'groupe'])
            ->where('teacher_id', $enseignant->id)
            ->when($request->input('statut'), fn ($q, $statut) => $q->where('statut', $statut))
            ->orderByDesc('updated_at')
            ->paginate(15)
            ->withQueryString();

        return view('modules.enseignement.mes-ressources', [
            'enseignant' => $enseignant,
            'ressources' => $ressources,
        ]);
    }

    public function create(Request $request): View
    {
        $enseignant = $this->enseignantConnecte($request);

        return view('modules.enseignement.ma-ressource-creer', [
            'enseignant' => $enseignant,
            'matieres' => $this->mesMatieresChargees($enseignant),
            'groupes' => Group::where('actif', true)->orderBy('code')->limit(500)->get(['id', 'code', 'nom']),
        ]);
    }

    public function store(Request $request)
    {
        $enseignant = $this->enseignantConnecte($request);

        $valides = $request->validate([
            'titre' => ['required', 'string', 'min:3', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'matiere_id' => ['required', 'integer'],
            'groupe_id' => ['nullable', 'integer', 'exists:groups,id'],
            'fichier' => ['required', 'file', 'max:20480'],
        ]);

        $this->assertChargeMatiere($enseignant, (int) $valides['matiere_id']);

        try {
            $fichier = $this->controlerFichier($request->file('fichier'));
        } catch (DomainException $e) {
            return back()->withErrors(['fichier' => $e->getMessage()])->withInput();
        }

        $chemin = Storage::disk('private')->putFileAs(
            'ressources-pedagogiques', $request->file('fichier'), Str::uuid().'.'.$fichier['extension']
        );

        $ressource = RessourcePedagogique::create([
            'teacher_id' => $enseignant->id,
            'matiere_id' => (int) $valides['matiere_id'],
            'groupe_id' => $valides['groupe_id'] ?? null,
            'titre' => $valides['titre'],
            'description' => $valides['description'] ?? null,
            'nom_fichier' => $request->file('fichier')->getClientOriginalName(),
            'chemin' => $chemin,
            'mime' => $fichier['mime'],
            'taille' => $request->file('fichier')->getSize(),
            'statut' => 'brouillon',
        ]);

        return redirect()->route('enseignant.ressources.show', $ressource)
            ->with('success', 'Support enregistré en brouillon. Publiez-le pour le rendre visible aux étudiants.');
    }

    public function show(Request $request, RessourcePedagogique $ressource): View
    {
        $enseignant = $this->enseignantConnecte($request);
        $this->assertPropriete($enseignant, $ressource);

        $ressource->load(['matiere', 'groupe']);

        return view('modules.enseignement.ma-ressource', [
            'enseignant' => $enseignant,
            'ressource' => $ressource,
        ]);
    }

    public function publier(Request $request, RessourcePedagogique $ressource)
    {
        return $this->transiter($request, $ressource, 'publie', 'Support publié : visible par les étudiants concernés.');
    }

    public function archiver(Request $request, RessourcePedagogique $ressource)
    {
        return $this->transiter($request, $ressource, 'archive', 'Support archivé : masqué aux étudiants.');
    }

    public function retirer(Request $request, RessourcePedagogique $ressource)
    {
        return $this->transiter($request, $ressource, 'brouillon', 'Support repassé en brouillon : masqué aux étudiants.');
    }

    public function destroy(Request $request, RessourcePedagogique $ressource)
    {
        $enseignant = $this->enseignantConnecte($request);
        $this->assertPropriete($enseignant, $ressource);

        abort_if($ressource->statut === 'publie', 422, 'Un support publié doit d’abord être archivé ou retiré.');

        Storage::disk('private')->delete($ressource->chemin);
        $ressource->delete();

        return redirect()->route('enseignant.ressources.index')
            ->with('success', 'Support supprimé.');
    }

    public function telecharger(Request $request, RessourcePedagogique $ressource): StreamedResponse
    {
        $enseignant = $this->enseignantConnecte($request);
        $this->assertPropriete($enseignant, $ressource);

        abort_unless(Storage::disk('private')->exists($ressource->chemin), 404);

        return Storage::disk('private')->download($ressource->chemin, $ressource->nom_fichier, [
            'Content-Type' => $ressource->mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function transiter(Request $request, RessourcePedagogique $ressource, string $cible, string $message)
    {
        $enseignant = $this->enseignantConnecte($request);
        $this->assertPropriete($enseignant, $ressource);

        abort_unless($ressource->peutTransiterVers($cible), 422, "Transition « {$ressource->statut} » → « {$cible} » interdite.");

        $ressource->update(['statut' => $cible]);

        return back()->with('success', $message);
    }

    /**
     * Contrôle serveur du fichier : extension whitelistée, MIME réel
     * cohérent, signatures magiques (convention GED).
     *
     * @return array{extension: string, mime: string}
     */
    private function controlerFichier(mixed $fichier): array
    {
        $extension = mb_strtolower((string) $fichier->getClientOriginalExtension());

        if (! in_array($extension, RessourcePedagogique::EXTENSIONS_AUTORISEES, true)) {
            throw new DomainException("Extension « .{$extension} » non autorisée pour un support de cours.");
        }

        $mime = (string) $fichier->getMimeType();

        if (! in_array($mime, self::MIMES_PAR_EXTENSION[$extension] ?? [], true)) {
            throw new DomainException("Contenu incohérent avec l'extension « .{$extension} » (détecté : {$mime}).");
        }

        $contenu = $fichier->get();

        if ($extension === 'pdf' && ! str_starts_with($contenu, '%PDF-')) {
            throw new DomainException('Fichier PDF invalide (signature manquante).');
        }

        if (in_array($extension, ['jpg', 'jpeg'], true) && ! str_starts_with($contenu, "\xFF\xD8\xFF")) {
            throw new DomainException('Image JPEG invalide (signature manquante).');
        }

        if ($extension === 'png' && ! str_starts_with($contenu, "\x89PNG\r\n\x1a\n")) {
            throw new DomainException('Image PNG invalide (signature manquante).');
        }

        return ['extension' => $extension, 'mime' => $mime];
    }

    /** @return Collection<int, Matiere> */
    private function mesMatieresChargees(Teacher $enseignant)
    {
        return Matiere::query()
            ->whereIn('id', Enseignement::query()
                ->whereIn('statut', ['validated', 'active', 'completed'])
                ->whereHas('affectations', fn ($q) => $q
                    ->where('teacher_id', $enseignant->id)
                    ->where('statut', 'active'))
                ->pluck('matiere_id'))
            ->orderBy('code')
            ->get(['id', 'code', 'nom']);
    }

    private function assertChargeMatiere(Teacher $enseignant, int $matiereId): void
    {
        abort_unless($this->mesMatieresChargees($enseignant)->contains('id', $matiereId), 422, 'Matière hors de votre charge d’enseignement.');
    }

    private function assertPropriete(Teacher $enseignant, RessourcePedagogique $ressource): void
    {
        abort_if((int) $ressource->teacher_id !== (int) $enseignant->id, 404);
    }

    private function enseignantConnecte(Request $request): Teacher
    {
        return Teacher::findOrFail($request->session()->get('enseignant_auth_id'));
    }
}
