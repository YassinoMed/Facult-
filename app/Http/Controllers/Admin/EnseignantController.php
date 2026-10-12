<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GradeEnseignant;
use App\Models\HistoriqueAcademique;
use App\Models\Matiere;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Evaluation\Models\JuryMembre;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Referentiel\Models\Specialite;
use App\Modules\Schedule\Models\Teacher;
use App\Services\AuditService;
use App\Services\PreferencesService;
use App\Support\Recherche;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Référentiel enseignants (§5) : entités à rattachement facultaire
 * via le département (NULL = transversal partagé, comme les salles
 * globales). Les comptes restreints (`admin_faculte`) ne voient et ne
 * manipulent que leur périmètre (transversaux + leur faculté) — accès
 * hors périmètre → 404 (modèle salles, Phase B).
 */
class EnseignantController extends Controller
{
    public function index(Request $request): View
    {
        $restreinte = $request->user()->faculteRestreinteId();
        $preferences = app(PreferencesService::class)->lire((int) $request->user()->id);

        $filtres = $request->validate([
            'search' => 'nullable|string|max:100',
            'departement_id' => 'nullable|integer|exists:departements,id',
            'grade_id' => 'nullable|integer|exists:grades_enseignants,id',
            'statut' => 'nullable|in:actif,inactif,suspendu,retraite',
        ]);

        $enseignants = Teacher::withCount('slots')
            ->when($restreinte !== null, fn ($q) => $q->where(
                fn ($w) => $w->whereNull('departement_id')
                    ->orWhereHas('departement', fn ($d) => $d->where('faculte_id', $restreinte))
            ))
            ->when($filtres['search'] ?? null, function ($q, $s) {
                $motif = Recherche::contient(trim($s));

                return $q->where(
                    fn ($w) => $w->where('nom', 'like', $motif)
                        ->orWhere('prenom', 'like', $motif)
                        ->orWhere('email', 'like', $motif)
                        ->orWhere('matricule', 'like', $motif)
                );
            })
            ->when($filtres['departement_id'] ?? null, fn ($q, $id) => $q->where('departement_id', (int) $id))
            ->when($filtres['grade_id'] ?? null, fn ($q, $id) => $q->where('grade_id', (int) $id))
            ->when($filtres['statut'] ?? null, fn ($q, $statut) => $q->where('statut', $statut))
            ->orderBy('nom')
            ->paginate($preferences['lignes_par_page'])
            ->withQueryString();

        return view('admin.enseignants.index', [
            'enseignants' => $enseignants,
            'filtres' => $filtres,
        ] + $this->optionsReferentiel($restreinte));
    }

    public function create(Request $request): View
    {
        return view('admin.enseignants.form', $this->optionsReferentiel($request->user()->faculteRestreinteId()));
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'nom' => 'required|string|max:100',
            'prenom' => 'required|string|max:100',
            'email' => 'nullable|email|max:255|unique:teachers,email',
            'matricule' => 'nullable|string|max:30|unique:teachers,matricule',
            'grade_id' => 'nullable|exists:grades_enseignants,id',
            'departement_id' => 'nullable|exists:departements,id',
            'specialite_id' => 'nullable|exists:specialites,id',
            'bureau' => 'nullable|string|max:50',
            'date_embauche' => 'nullable|date',
            'statut' => 'nullable|in:actif,inactif,suspendu,retraite',
            'password' => ['nullable', 'string', 'max:64', 'confirmed', Password::defaults()],
        ]);

        if (empty($donnees['password'])) {
            unset($donnees['password']);
        }

        $this->abortDepartementHorsPerimetre($request, $donnees['departement_id'] ?? null);

        // Phase B, option A (`actif` fait foi) : le statut explicite
        // synchronise `actif` (suspendu/inactif/retraite = compte fermé).
        $donnees['statut'] ??= 'actif';
        $donnees['actif'] = $donnees['statut'] === 'actif';

        $enseignant = Teacher::create($donnees);

        // Phase E : traçabilité sans PII (journal central réutilisé).
        app(AuditService::class)->tracerReferentiel(
            HistoriqueAcademique::ACTION_ENSEIGNANT_CREATION,
            'enseignant',
            $enseignant->id,
            null,
            $this->clicheAudit($enseignant),
        );

        return redirect()->route('admin.enseignants.index')
            ->with('success', 'Enseignant créé.');
    }

    public function show(Request $request, Teacher $enseignant): View
    {
        $this->abortHorsPerimetre($request, $enseignant);

        // Eager complet (Phase D) : aucune requête dans la vue.
        $enseignant->loadMissing([
            'grade', 'departement.faculte', 'specialite', 'affectationsEnseignement',
            'slots.schedule.groupe', 'slots.matiere',
        ]);

        $charge = $enseignant->slots->where('status', '!=', 'cancelled');
        $heures = $charge->sum(fn ($slot) => max(0,
            (int) substr($slot->end_time, 0, 2) * 60 + (int) substr($slot->end_time, 3, 2)
            - ((int) substr($slot->start_time, 0, 2) * 60 + (int) substr($slot->start_time, 3, 2))
        )) / 60;

        $enseignements = Enseignement::whereHas(
            'affectations', fn ($q) => $q->where('teacher_id', $enseignant->id)->where('statut', 'active')
        )->with(['matiere', 'type'])->get();

        return view('admin.enseignants.show', [
            'enseignant' => $enseignant,
            'heures' => round($heures, 1),
            'nbCours' => $charge->count(),
            'enseignements' => $enseignements,
        ]);
    }

    public function edit(Request $request, Teacher $enseignant): View
    {
        $this->abortHorsPerimetre($request, $enseignant);

        return view('admin.enseignants.form', ['enseignant' => $enseignant] + $this->optionsReferentiel($request->user()->faculteRestreinteId()));
    }

    /**
     * @return array<string, mixed>
     */
    private function optionsReferentiel(?int $restreinte): array
    {
        return [
            'grades' => GradeEnseignant::where('actif', true)->orderBy('ordre')->get(),
            'departements' => Departement::when($restreinte !== null, fn ($q) => $q->where('faculte_id', $restreinte))
                ->orderBy('code')->get(),
            'specialites' => Specialite::orderBy('code')->get(),
        ];
    }

    /**
     * Isolation faculté (Phase B, modèle salles) : fiche d'une autre
     * faculté → 404 (les transversaux restent partagés).
     */
    private function abortHorsPerimetre(Request $request, Teacher $enseignant): void
    {
        $restreinte = $request->user()->faculteRestreinteId();
        $faculteId = $enseignant->faculteId();

        abort_unless($faculteId === null || $restreinte === null || $faculteId === $restreinte, 404);
    }

    /**
     * Isolation faculté en écriture : rattachement à un département
     * étranger → 404 (nul = transversal partagé, autorisé).
     */
    private function abortDepartementHorsPerimetre(Request $request, mixed $departementId): void
    {
        $restreinte = $request->user()->faculteRestreinteId();

        if ($restreinte === null || $departementId === null) {
            return;
        }

        $departement = Departement::find($departementId);

        abort_unless($departement !== null && (int) $departement->faculte_id === $restreinte, 404);
    }

    public function update(Request $request, Teacher $enseignant): RedirectResponse
    {
        $this->abortHorsPerimetre($request, $enseignant);

        $donnees = $request->validate([
            'nom' => 'required|string|max:100',
            'prenom' => 'required|string|max:100',
            'email' => 'nullable|email|max:255|unique:teachers,email,'.$enseignant->id,
            'matricule' => 'nullable|string|max:30|unique:teachers,matricule,'.$enseignant->id,
            'grade_id' => 'nullable|exists:grades_enseignants,id',
            'departement_id' => 'nullable|exists:departements,id',
            'specialite_id' => 'nullable|exists:specialites,id',
            'bureau' => 'nullable|string|max:50',
            'date_embauche' => 'nullable|date',
            'statut' => 'nullable|in:actif,inactif,suspendu,retraite',
            'actif' => 'sometimes|boolean',
            'password' => ['nullable', 'string', 'max:64', 'confirmed', Password::defaults()],
        ]);

        // Mot de passe vide = conservation de l'existant (jamais d'écrasement).
        if (empty($donnees['password'])) {
            unset($donnees['password']);
        }

        $this->abortDepartementHorsPerimetre($request, array_key_exists('departement_id', $donnees) ? $donnees['departement_id'] : null);

        // Phase B, option A (`actif` fait foi) : le statut explicite
        // synchronise `actif` ; à défaut (chemin historique : case seule),
        // `statut` suit `actif`. Sans les deux : rien n'est touché.
        if (array_key_exists('statut', $donnees) && $donnees['statut'] !== null) {
            $donnees['actif'] = $donnees['statut'] === 'actif';
        } elseif (array_key_exists('actif', $donnees)) {
            $donnees['statut'] = $donnees['actif'] ? 'actif' : 'inactif';
        }

        $avant = $this->clicheAudit($enseignant);
        $enseignant->update($donnees);

        app(AuditService::class)->tracerReferentiel(
            HistoriqueAcademique::ACTION_ENSEIGNANT_MODIFICATION,
            'enseignant',
            $enseignant->id,
            $avant,
            $this->clicheAudit($enseignant->fresh()),
        );

        return redirect()->route('admin.enseignants.index')
            ->with('success', 'Enseignant mis à jour.');
    }

    public function destroy(Request $request, Teacher $enseignant): RedirectResponse
    {
        $this->abortHorsPerimetre($request, $enseignant);

        // Garde métier : les relations en `restrictOnDelete` (affectations
        // d'enseignement, surveillances, présences/pointages) et les
        // créneaux lèveraient une violation FK (500) — refus explicite,
        // historiques conservés, jamais de détachement automatique.
        $attaches = [];
        if ($enseignant->slots()->exists()) {
            $attaches[] = 'des créneaux';
        }
        if ($enseignant->affectationsEnseignement()->exists()) {
            $attaches[] = "des affectations d'enseignement";
        }
        if ($enseignant->surveillances()->exists()) {
            $attaches[] = 'des surveillances';
        }
        if ($enseignant->presencesSeances()->exists() || $enseignant->pointages()->exists()) {
            $attaches[] = 'des présences';
        }
        if (JuryMembre::where('teacher_id', $enseignant->id)->exists()) {
            $attaches[] = 'des jurys de délibération';
        }
        if (Matiere::where('responsable_teacher_id', $enseignant->id)->exists()) {
            $attaches[] = 'des matières (responsable)';
        }

        if ($attaches !== []) {
            return redirect()->back()->withErrors([
                'enseignant' => 'Impossible : cet enseignant est lié à '.implode(', ', $attaches).'.',
            ]);
        }

        $avant = $this->clicheAudit($enseignant);
        $enseignant->delete();

        app(AuditService::class)->tracerReferentiel(
            HistoriqueAcademique::ACTION_ENSEIGNANT_SUPPRESSION,
            'enseignant',
            $enseignant->id,
            $avant,
        );

        return redirect()->route('admin.enseignants.index')
            ->with('success', 'Enseignant supprimé.');
    }

    /**
     * Cliché d'audit sans PII (Phase E) : identifiants techniques et
     * statuts uniquement — jamais nom, prénom, email, téléphone,
     * matricule ou mot de passe.
     *
     * @return array{statut: mixed, actif: bool, departement_id: mixed, grade_id: mixed}
     */
    private function clicheAudit(Teacher $enseignant): array
    {
        return [
            'statut' => $enseignant->statut,
            'actif' => (bool) $enseignant->actif,
            'departement_id' => $enseignant->departement_id,
            'grade_id' => $enseignant->grade_id,
        ];
    }
}
