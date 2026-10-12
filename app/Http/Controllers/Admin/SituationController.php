<?php

namespace App\Http\Controllers\Admin;

use App\Events\PopulationChanged;
use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Models\HistoriqueAcademique;
use App\Models\SituationEtudiant;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Services\AuditService;
use App\Services\CommunicationDispatcher;
use App\Services\StudentStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Situations : suspension, transfert, rattachement LMD (§26-28).
 * Chaque opération est motivée, datée, historisée — jamais d'écrasement.
 */
class SituationController extends Controller
{
    public function __construct(
        private readonly StudentStatusService $statuts,
    ) {}

    public function suspendre(Request $request, Etudiant $etudiant): RedirectResponse
    {
        Gate::authorize('update', $etudiant);

        $valide = $request->validate([
            'date_debut' => 'required|date',
            'date_fin' => 'nullable|date|after_or_equal:date_debut',
            'motif' => 'required|string|max:255',
        ]);

        $this->statuts->suspendre(
            $etudiant, $valide['date_debut'], $valide['date_fin'] ?? null,
            (int) $request->user()->id, $valide['motif'],
        );

        return redirect()->route('admin.etudiants.show', $etudiant)
            ->with('success', 'Suspension enregistrée.');
    }

    public function transferer(Request $request, Etudiant $etudiant): RedirectResponse
    {
        Gate::authorize('update', $etudiant);

        $valide = $request->validate([
            'programme_id' => 'required|exists:programmes,id',
            'motif' => 'required|string|max:255',
        ]);

        $programme = Programme::findOrFail($valide['programme_id']);
        abort_unless($request->user()->peutAccederFaculte((int) $programme->faculte_id), 403);

        DB::transaction(function () use ($etudiant, $programme, $valide, $request) {
            $ancien = $etudiant->programmeRef?->code;

            // Cohérence périmètre : l'étudiant suit la faculté du programme
            // cible (l'historique reste dans les situations).
            $etudiant->update([
                'programme_id' => $programme->id,
                'niveau_id' => null,
                'faculte_id' => $programme->faculte_id,
            ]);

            SituationEtudiant::create([
                'etudiant_id' => $etudiant->id,
                'type' => 'transfert',
                'ancienne_valeur' => $ancien,
                'nouvelle_valeur' => $programme->code,
                'motif' => $valide['motif'],
                'auteur_id' => (int) $request->user()->id,
                'date_effet' => now()->toDateString(),
            ]);

            app(AuditService::class)->tracer(
                $etudiant,
                HistoriqueAcademique::ACTION_MODIFICATION_INSCRIPTION,
                'etudiant',
                $etudiant->id,
                ['programme' => $ancien],
                ['programme' => $programme->code, 'motif' => $valide['motif']],
            );
        });

        PopulationChanged::dispatch('etudiants', 'transfert',
            $etudiant->id, (int) $request->user()->id, ['programme' => $programme->code]);

        // Phase 15 §15.4 : envoi via le centre (texte contractuel inchangé).
        app(CommunicationDispatcher::class)->dispatcher(
            'inscription.transferee',
            $etudiant,
            ['programme' => $programme->code],
            route('etudiant.dashboard'),
        );

        return redirect()->route('admin.etudiants.show', $etudiant)
            ->with('success', "Transfert vers {$programme->code} enregistré (dossier conservé).");
    }

    public function rattacher(Request $request, Etudiant $etudiant): RedirectResponse
    {
        Gate::authorize('update', $etudiant);

        $valide = $request->validate([
            'programme_id' => 'nullable|exists:programmes,id',
            'niveau_id' => 'nullable|exists:niveaux,id',
        ]);

        // Isolation faculté sur la CIBLE (audit sécurité 04/10/2026) :
        // comme `transferer`, un rattachement vers le programme d'une
        // autre faculté est refusé (404).
        if ($valide['programme_id'] ?? null) {
            $programmeCible = Programme::findOrFail($valide['programme_id']);
            abort_unless($programmeCible->faculte_id === null
                || $request->user()->peutAccederFaculte((int) $programmeCible->faculte_id), 404);
        }

        if ($valide['niveau_id'] ?? null) {
            $niveau = Niveau::findOrFail($valide['niveau_id']);
            abort_unless((int) $niveau->programme_id === (int) ($valide['programme_id'] ?? $etudiant->programme_id), 422,
                'Le niveau doit appartenir au programme.');
        }

        $etudiant->update(array_filter($valide, fn ($v) => $v !== null));

        SituationEtudiant::create([
            'etudiant_id' => $etudiant->id,
            'type' => 'programme',
            'nouvelle_valeur' => ($valide['programme_id'] ?? null) ?: null,
            'motif' => 'rattachement LMD',
            'auteur_id' => (int) $request->user()->id,
            'date_effet' => now()->toDateString(),
        ]);

        return redirect()->route('admin.etudiants.show', $etudiant)
            ->with('success', 'Rattachement LMD enregistré.');
    }
}
