<?php

namespace App\Http\Controllers;

use App\Models\Etudiant;
use App\Modules\Announcements\Models\Annonce;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Enseignement\Models\RessourcePedagogique;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recherche portails (LOOP 18) : périmètres strictement personnels,
 * scopes appliqués DANS les requêtes, résultats bornés, LIKE échappé.
 *
 * Enseignant : ses étudiants (groupes de ses créneaux — nom/prénom/
 * n° inscription uniquement, jamais CIN/e-mail), ses séances, ses
 * épreuves à saisir, ses supports. Étudiant : ses supports visibles,
 * ses demandes, ses séances, ses annonces. Aucun champ privé inutile.
 */
class RecherchePortailController extends Controller
{
    public function enseignant(Request $request): View|JsonResponse
    {
        $enseignant = Teacher::findOrFail($request->session()->get('enseignant_auth_id'));
        $motif = $this->motif($request);

        $sections = $motif === null ? [] : [
            'Étudiants de mes groupes' => $this->etudiantsEnseignant($enseignant, $motif),
            'Mes séances' => $this->seancesEnseignant($enseignant, $motif),
            'Mes épreuves à saisir' => $this->epreuvesEnseignant($enseignant, $motif),
            'Mes supports' => $this->supportsEnseignant($enseignant, $motif),
        ];

        if ($request->wantsJson()) {
            return response()->json(['sections' => $sections]);
        }

        return view('enseignant.recherche', ['motif' => $request->input('q', ''), 'sections' => $sections]);
    }

    public function etudiant(Request $request): View|JsonResponse
    {
        $etudiant = Etudiant::findOrFail($request->session()->get('etudiant_auth_id'));
        $motif = $this->motif($request);

        $sections = $motif === null ? [] : [
            'Supports de cours' => $this->supportsEtudiant($etudiant, $motif),
            'Mes demandes' => $this->demandesEtudiant($etudiant, $motif),
            'Mes séances' => $this->seancesEtudiant($etudiant, $motif),
            'Annonces' => $this->annoncesEtudiant($etudiant, $motif),
        ];

        if ($request->wantsJson()) {
            return response()->json(['sections' => $sections]);
        }

        return view('etudiant.recherche', ['motif' => $request->input('q', ''), 'sections' => $sections]);
    }

    /**
     * Motif normalisé : 2 caractères minimum, 100 maximum. NULL = pas de
     * recherche (état vide, aucune requête exécutée).
     */
    private function motif(Request $request): ?string
    {
        $brut = trim((string) $request->input('q', ''));

        if (mb_strlen($brut) < 2) {
            return null;
        }

        return mb_substr($brut, 0, 100);
    }

    private function like(string $motif): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $motif).'%';
    }

    /** @return array<int, array{titre: string, detail: string, lien: ?string}> */
    private function etudiantsEnseignant(Teacher $enseignant, string $motif): array
    {
        $groupes = ScheduleSlot::where('teacher_id', $enseignant->id)
            ->whereHas('schedule', fn ($q) => $q->whereNotNull('groupe_id'))
            ->with('schedule:id,groupe_id')
            ->get()
            ->map(fn ($slot) => $slot->schedule?->groupe_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($groupes === []) {
            return [];
        }

        $like = $this->like($motif);

        return Etudiant::query()
            ->whereIn('groupe_id', $groupes)
            ->where(fn ($q) => $q
                ->where('nom', 'like', $like)
                ->orWhere('prenom', 'like', $like)
                ->orWhere('num_inscription', 'like', $like))
            ->with('groupe:id,code')
            ->limit(10)
            ->get(['id', 'nom', 'prenom', 'num_inscription', 'groupe_id'])
            ->map(fn (Etudiant $e) => [
                'titre' => $e->prenom.' '.$e->nom,
                'detail' => ($e->num_inscription ?? '—').' · '.($e->groupe?->code ?? '—'),
                'lien' => null,
            ])
            ->all();
    }

    /** @return array<int, array{titre: string, detail: string, lien: ?string}> */
    private function seancesEnseignant(Teacher $enseignant, string $motif): array
    {
        $like = $this->like($motif);

        return ScheduleSlot::query()
            ->where('teacher_id', $enseignant->id)
            ->where(fn ($q) => $q
                ->whereHas('matiere', fn ($m) => $m->where('code', 'like', $like)->orWhere('nom', 'like', $like))
                ->orWhereHas('schedule.groupe', fn ($g) => $g->where('code', 'like', $like)))
            ->with(['matiere:id,code,nom', 'schedule.groupe:id,code'])
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->limit(10)
            ->get()
            ->map(fn (ScheduleSlot $s) => [
                'titre' => ($s->matiere?->code ?? 'Séance').' · '.($s->schedule?->groupe?->code ?? '—'),
                'detail' => $s->matiere?->nom ?? '',
                'lien' => route('enseignant.emploi-du-temps.semaine'),
            ])
            ->all();
    }

    /** @return array<int, array{titre: string, detail: string, lien: ?string}> */
    private function epreuvesEnseignant(Teacher $enseignant, string $motif): array
    {
        $matieres = Enseignement::query()
            ->whereIn('statut', ['validated', 'active', 'completed'])
            ->whereHas('affectations', fn ($q) => $q
                ->where('teacher_id', $enseignant->id)
                ->where('statut', 'active'))
            ->pluck('matiere_id')
            ->unique()
            ->values()
            ->all();

        if ($matieres === []) {
            return [];
        }

        $like = $this->like($motif);

        return Epreuve::query()
            ->where('statut', '!=', 'cancelled')
            ->whereIn('matiere_id', $matieres)
            ->where(fn ($q) => $q
                ->where('code', 'like', $like)
                ->orWhereHas('matiere', fn ($m) => $m->where('nom', 'like', $like)))
            ->with('matiere:id,nom')
            ->orderBy('date')
            ->limit(10)
            ->get()
            ->map(fn (Epreuve $e) => [
                'titre' => $e->code,
                'detail' => $e->matiere?->nom ?? '',
                'lien' => route('enseignant.notes.saisie', $e),
            ])
            ->all();
    }

    /** @return array<int, array{titre: string, detail: string, lien: ?string}> */
    private function supportsEnseignant(Teacher $enseignant, string $motif): array
    {
        return RessourcePedagogique::query()
            ->where('teacher_id', $enseignant->id)
            ->where('titre', 'like', $this->like($motif))
            ->with('matiere:id,code')
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get()
            ->map(fn (RessourcePedagogique $r) => [
                'titre' => $r->titre,
                'detail' => ($r->matiere?->code ?? '—').' · '.$r->statut,
                'lien' => route('enseignant.ressources.show', $r),
            ])
            ->all();
    }

    /** @return array<int, array{titre: string, detail: string, lien: ?string}> */
    private function supportsEtudiant(Etudiant $etudiant, string $motif): array
    {
        return RessourcePedagogique::query()
            ->visiblesPourEtudiant($etudiant)
            ->where('titre', 'like', $this->like($motif))
            ->with('matiere:id,code')
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get()
            ->map(fn (RessourcePedagogique $r) => [
                'titre' => $r->titre,
                'detail' => $r->matiere?->code ?? '—',
                'lien' => route('etudiant.ressources.index'),
            ])
            ->all();
    }

    /** @return array<int, array{titre: string, detail: string, lien: ?string}> */
    private function demandesEtudiant(Etudiant $etudiant, string $motif): array
    {
        $like = $this->like($motif);

        return OnlineRequest::query()
            ->where('etudiant_id', $etudiant->id)
            ->where(fn ($q) => $q
                ->where('reference', 'like', $like)
                ->orWhere('objet', 'like', $like))
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'reference', 'objet', 'status'])
            ->map(fn (OnlineRequest $d) => [
                'titre' => $d->reference ?? ('Demande #'.$d->id),
                'detail' => ($d->objet ?? '').' · '.$d->status,
                'lien' => route('etudiant.demandes.show', $d),
            ])
            ->all();
    }

    /** @return array<int, array{titre: string, detail: string, lien: ?string}> */
    private function seancesEtudiant(Etudiant $etudiant, string $motif): array
    {
        if ($etudiant->groupe_id === null) {
            return [];
        }

        $like = $this->like($motif);

        return ScheduleSlot::query()
            ->whereHas('schedule', fn ($q) => $q->where('groupe_id', $etudiant->groupe_id))
            ->where(fn ($q) => $q
                ->whereHas('matiere', fn ($m) => $m->where('code', 'like', $like)->orWhere('nom', 'like', $like)))
            ->with(['matiere:id,code,nom', 'room:id,code'])
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->limit(10)
            ->get()
            ->map(fn (ScheduleSlot $s) => [
                'titre' => ($s->matiere?->code ?? 'Séance').' · '.($s->room?->code ?? '—'),
                'detail' => $s->matiere?->nom ?? '',
                'lien' => route('etudiant.emploi-du-temps.semaine'),
            ])
            ->all();
    }

    /** @return array<int, array{titre: string, detail: string, lien: ?string}> */
    private function annoncesEtudiant(Etudiant $etudiant, string $motif): array
    {
        $like = $this->like($motif);

        $candidates = Annonce::query()
            ->where('titre', 'like', $like)
            ->whereIn('audience', ['tous', 'etudiants', 'programme', 'groupe'])
            ->where(fn ($q) => $q
                ->whereNull('faculte_id')
                ->orWhere('faculte_id', $etudiant->faculte_id))
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        return $candidates
            ->filter(fn (Annonce $a) => $a->visiblePourEtudiant($etudiant))
            ->take(10)
            ->map(fn (Annonce $a) => [
                'titre' => $a->titre,
                'detail' => $a->audience,
                'lien' => route('etudiant.annonces.show', $a),
            ])
            ->values()
            ->all();
    }
}
