<?php

namespace App\Modules\Evaluation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Evaluation\Events\EvaluationChanged;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Services\CompostageService;
use App\Modules\Evaluation\Services\SaisieNoteService;
use App\Modules\Schedule\Models\Teacher;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Espace Enseignant — saisie des notes de MES épreuves (LOOP 05).
 *
 * Éligibilité : épreuve dont l'ECUE m'est affectée (affectation
 * `active` sur un enseignement dispensé) — jamais la surveillance
 * seule, jamais une épreuve d'autrui, jamais une correspondance
 * CMP → identité (réservée au back-office).
 *
 * Voie strictement anonyme : `SaisieNoteService::saisirLotParCompostages`
 * (numéros résolus serveur, auteur NULL = portail, origine tracée
 * « Enseignant »). Mêmes gardes métier que le back-office : candidats
 * convoqués, bornes 0..note_max, absents explicites, verrou refusé
 * proprement. Ni validation, ni verrouillage, ni publication ici
 * (workflow scolarité).
 */
class MesNotesController extends Controller
{
    public function index(Request $request): View
    {
        $enseignant = $this->enseignantConnecte($request);

        $epreuves = $this->mesEpreuves($enseignant)
            ->with(['matiere', 'session', 'groupe'])
            ->withCount(['candidats', 'notes', 'compostages'])
            ->orderBy('date')
            ->orderBy('heure_debut')
            ->paginate(15)
            ->withQueryString();

        return view('modules.evaluation.mes-notes', [
            'enseignant' => $enseignant,
            'epreuves' => $epreuves,
        ]);
    }

    public function saisie(Request $request, Epreuve $epreuve, CompostageService $compostages): View
    {
        $enseignant = $this->enseignantConnecte($request);
        $this->maSaisie($enseignant, $epreuve);

        $epreuve->load(['matiere', 'session', 'groupe', 'notes', 'candidats']);

        if ($epreuve->compostages()->count() < $epreuve->candidats->count() && ! $epreuve->estVerrouillee()) {
            $compostages->genererPourEpreuve($epreuve, 0);
            $epreuve->unsetRelation('compostages');
        }

        $lignes = $epreuve->compostages()->orderBy('numero')->get();
        $notes = $epreuve->notes->keyBy('etudiant_id');

        return view('modules.evaluation.ma-saisie-notes', [
            'enseignant' => $enseignant,
            'epreuve' => $epreuve,
            'lignes' => $lignes,
            'notes' => $notes,
        ]);
    }

    public function store(Request $request, Epreuve $epreuve, SaisieNoteService $service)
    {
        $enseignant = $this->enseignantConnecte($request);
        $this->maSaisie($enseignant, $epreuve);

        $valides = $request->validate([
            'lignes' => ['required', 'array', 'min:1', 'max:2000'],
            'lignes.*.numero' => ['required', 'string', 'regex:/^CMP-\d{6}$/i', 'max:11'],
            'lignes.*.valeur' => ['nullable', 'numeric', 'min:0', 'max:1000'],
        ]);

        try {
            $resultat = $service->saisirLotParCompostages($epreuve, array_values($valides['lignes']), null, 'Enseignant');
        } catch (DomainException $e) {
            return back()->withErrors(['epreuve' => $e->getMessage()])->withInput();
        }

        if ($resultat['erreurs'] !== []) {
            return back()->withErrors($resultat['erreurs'])->withInput();
        }

        return back()->with('success', "{$resultat['saisies']} note(s) enregistrée(s).");
    }

    /**
     * Import CSV anonyme (`numero,note`) sur mes épreuves : rejet absolu
     * de toute colonne identifiante, mêmes gardes que la saisie manuelle.
     */
    public function importer(Request $request, Epreuve $epreuve, SaisieNoteService $service)
    {
        $enseignant = $this->enseignantConnecte($request);
        $this->maSaisie($enseignant, $epreuve);

        $request->validate(['fichier' => ['required', 'file', 'max:2048', 'mimetypes:text/plain,text/csv,application/csv']]);

        if ($epreuve->estVerrouillee()) {
            return back()->withErrors(['fichier' => 'Épreuve verrouillée : import interdit.']);
        }

        $chemin = $request->file('fichier')->getRealPath();
        $handle = fopen($chemin, 'r');

        if ($handle === false) {
            return back()->withErrors(['fichier' => 'Impossible de lire le fichier.']);
        }

        $entetes = fgetcsv($handle, 1000, ',');

        if ($entetes === false || $entetes === null) {
            fclose($handle);

            return back()->withErrors(['fichier' => 'Le fichier CSV est vide.']);
        }

        $normalisees = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $entetes);

        foreach (['student_id', 'etudiant_id', 'nom', 'prenom', 'cin', 'email', 'matricule'] as $interdite) {
            if (in_array($interdite, $normalisees, true)) {
                fclose($handle);

                return back()->withErrors(['fichier' => 'Le flux anonyme ne doit contenir aucune donnée identifiante.']);
            }
        }

        $colNumero = array_search('numero', $normalisees, true);
        if ($colNumero === false) {
            $colNumero = array_search('compostage', $normalisees, true);
        }
        $colNote = array_search('note', $normalisees, true);
        if ($colNote === false) {
            $colNote = array_search('valeur', $normalisees, true);
        }

        if ($colNumero === false || $colNote === false) {
            fclose($handle);

            return back()->withErrors(['fichier' => 'Le fichier CSV doit contenir les colonnes numero (ou compostage) et note.']);
        }

        $lignes = [];

        while (($ligne = fgetcsv($handle, 1000, ',')) !== false) {
            $numero = trim((string) ($ligne[$colNumero] ?? ''));
            $brut = trim((string) ($ligne[$colNote] ?? ''));

            if ($numero === '') {
                continue;
            }

            if (! preg_match('/^CMP-\d{6}$/i', $numero)) {
                fclose($handle);

                return back()->withErrors(['fichier' => "Numéro « {$numero} » invalide (format CMP-XXXXXX attendu)."]);
            }

            $lignes[] = ['numero' => $numero, 'valeur' => $brut === '' ? null : (float) str_replace(',', '.', $brut)];

            if (count($lignes) > 2000) {
                fclose($handle);

                return back()->withErrors(['fichier' => 'Fichier trop volumineux (2000 lignes maximum).']);
            }
        }

        fclose($handle);

        if ($lignes === []) {
            return back()->withErrors(['fichier' => 'Aucune ligne de note à importer.']);
        }

        try {
            $resultat = $service->saisirLotParCompostages($epreuve, $lignes, null, 'Enseignant');
        } catch (DomainException $e) {
            return back()->withErrors(['fichier' => $e->getMessage()]);
        }

        if ($resultat['erreurs'] !== []) {
            return back()->withErrors($resultat['erreurs'])->withInput();
        }

        EvaluationChanged::dispatch('notes.saisies', $epreuve->id);

        return back()->with('success', "{$resultat['saisies']} note(s) importée(s).");
    }

    /**
     * Export anonymisé de mes saisies (compostage + note + statut) —
     * jamais d'identité étudiante.
     */
    public function exporter(Request $request, Epreuve $epreuve): StreamedResponse
    {
        $enseignant = $this->enseignantConnecte($request);
        $this->maSaisie($enseignant, $epreuve);

        $epreuve->load(['compostages', 'notes', 'candidats']);
        $notes = $epreuve->notes->keyBy('etudiant_id');
        $statuts = $epreuve->candidats->keyBy('etudiant_id');

        return response()->streamDownload(function () use ($epreuve, $notes, $statuts) {
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF");
            fputcsv($sortie, ['compostage', 'note', 'statut'], ';');

            foreach ($epreuve->compostages->sortBy('numero') as $compostage) {
                $note = $notes->get($compostage->etudiant_id);
                fputcsv($sortie, [
                    $compostage->numero,
                    $note?->valeur,
                    $note?->statut ?? $statuts->get($compostage->etudiant_id)?->statut,
                ], ';');
            }

            fclose($sortie);
        }, 'mes-notes-anonymes-epreuve-'.$epreuve->id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Mes épreuves à saisir : ECUE affectée (affectation `active` sur un
     * enseignement dispensé), épreuve non annulée.
     */
    private function mesEpreuves(Teacher $enseignant): Builder
    {
        return Epreuve::query()
            ->where('statut', '!=', 'cancelled')
            ->whereIn('matiere_id', $this->mesMatieres($enseignant));
    }

    /** @return array<int> */
    private function mesMatieres(Teacher $enseignant): array
    {
        return Enseignement::query()
            ->whereIn('statut', ['validated', 'active', 'completed'])
            ->whereHas('affectations', fn ($q) => $q
                ->where('teacher_id', $enseignant->id)
                ->where('statut', 'active'))
            ->pluck('matiere_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Autorisation de saisie sur l'épreuve (404 si épreuve annulée,
     * hors périmètre faculté ou ECUE non affectée — anti-IDOR/BOLA).
     */
    private function maSaisie(Teacher $enseignant, Epreuve $epreuve): void
    {
        abort_if($epreuve->statut === 'cancelled', 404);

        $faculteEpreuve = $epreuve->faculteId();
        $maFaculte = $enseignant->faculteId();

        if ($faculteEpreuve !== null && $maFaculte !== null && $faculteEpreuve !== $maFaculte) {
            abort(404);
        }

        $autorise = Enseignement::query()
            ->where('matiere_id', $epreuve->matiere_id)
            ->whereIn('statut', ['validated', 'active', 'completed'])
            ->whereHas('affectations', fn ($q) => $q
                ->where('teacher_id', $enseignant->id)
                ->where('statut', 'active'))
            ->exists();

        abort_unless($autorise, 404);
    }

    private function enseignantConnecte(Request $request): Teacher
    {
        return Teacher::findOrFail($request->session()->get('enseignant_auth_id'));
    }
}
