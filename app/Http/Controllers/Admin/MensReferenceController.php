<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\MensReference;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\StudentDossiers\Services\StudentDossierService;
use App\Services\AuditService;
use App\Services\NormalisationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Emplacement data MENS (référentiel national importé) : consultation
 * des références concernées par les inscriptions de la faculté.
 * Lecture seule — l'import reste le pipeline DataExchange
 * (`admin.donnees`, entité `referentiel-mens`, jamais dupliqué ici).
 *
 * Périmètre : global = tout ; restreint = lignes rattachables à la
 * faculté (CIN apparié à un étudiant local de la faculté OU
 * `programme_code` d'un programme de la faculté).
 */
class MensReferenceController extends Controller
{
    public function index(Request $request): View
    {
        $utilisateur = $request->user();
        $restreintId = $utilisateur->faculteRestreinteId();

        $query = MensReference::query()->orderByDesc('annee')->orderBy('nom')->orderBy('prenom');

        // Appariement local en UNE requête (sous-select, jamais de N+1).
        $query->select('mens_references.*')->selectSub(
            Etudiant::selectRaw('1')
                ->whereColumn('etudiants.cin', 'mens_references.cin_normalise')
                ->limit(1),
            'appariement_local'
        );

        if ($restreintId !== null) {
            $codesProgrammes = Programme::where('faculte_id', $restreintId)->pluck('code')->all();
            $query->where(function ($q) use ($restreintId, $codesProgrammes) {
                $q->whereExists(function ($exists) use ($restreintId) {
                    $exists->selectRaw('1')->from('etudiants')
                        ->whereColumn('etudiants.cin', 'mens_references.cin_normalise')
                        ->where('etudiants.faculte_id', $restreintId);
                });

                if ($codesProgrammes !== []) {
                    $q->orWhereIn('programme_code', $codesProgrammes);
                }
            });
        }

        if ($request->filled('q')) {
            $motif = '%'.str_replace(['%', '_'], '', (string) $request->input('q')).'%';
            $query->where(fn ($q) => $q->where('nom', 'like', $motif)
                ->orWhere('prenom', 'like', $motif)
                ->orWhere('cin_normalise', 'like', $motif));
        }

        if ($request->filled('annee')) {
            $query->where('annee', $request->input('annee'));
        }

        if ($request->filled('appariement')) {
            if ($request->input('appariement') === 'apparies') {
                $query->whereExists(function ($exists) {
                    $exists->selectRaw('1')->from('etudiants')
                        ->whereColumn('etudiants.cin', 'mens_references.cin_normalise');
                });
            } elseif ($request->input('appariement') === 'non_apparies') {
                $query->whereNotExists(function ($exists) {
                    $exists->selectRaw('1')->from('etudiants')
                        ->whereColumn('etudiants.cin', 'mens_references.cin_normalise');
                });
            }
        }

        return view('admin.mens.index', [
            'references' => $query->paginate(25)->withQueryString(),
            'annees' => MensReference::distinct()->orderByDesc('annee')->pluck('annee')->all(),
            'total' => (clone $query)->count(),
            'search' => (string) $request->input('q', ''),
        ]);
    }

    /**
     * Crée le dossier facultaire depuis une référence MENS sans
     * dossier local (Cas C). Cas D (local existant) → redirection
     * vers la fiche, jamais de doublon. Cas G (conflit CIN) → 422.
     * Transaction + idempotence (re-POST → existant).
     */
    public function creerDossier(Request $request, MensReference $reference): RedirectResponse
    {
        $utilisateur = $request->user();
        $restreintId = $utilisateur->faculteRestreinteId();

        $cinNormalise = app(NormalisationService::class)->cin((string) $reference->cin_normalise);
        abort_if($cinNormalise === null, 422, 'Référence MENS sans CIN exploitable.');

        $programme = $reference->programme_code !== null
            ? Programme::where('code', $reference->programme_code)->first()
            : null;

        // Périmètre : même règle que l'index (local apparié OU programme).
        $localApparieEnPerimetre = $restreintId !== null && Etudiant::where('faculte_id', $restreintId)
            ->where(function ($q) use ($reference, $cinNormalise) {
                $q->where('cin', $reference->cin_normalise)
                    ->orWhere('cin', $reference->cin_original)
                    ->orWhereRaw("REPLACE(UPPER(TRIM(cin)), ' ', '') = ?", [$cinNormalise]);
            })->exists();

        if ($restreintId !== null
            && ! $localApparieEnPerimetre
            && ($programme === null || (int) $programme->faculte_id !== $restreintId)) {
            abort(404);
        }

        // Locaux partageant la CIN normalisée (comparaison applicative).
        $candidats = Etudiant::where(function ($q) use ($reference, $cinNormalise) {
            $q->where('cin', $reference->cin_normalise)
                ->orWhere('cin', $reference->cin_original)
                ->orWhereRaw("REPLACE(UPPER(TRIM(cin)), ' ', '') = ?", [$cinNormalise]);
        })->get()->filter(fn (Etudiant $e) => app(NormalisationService::class)->cin((string) $e->cin) === $cinNormalise
        )->values();

        if ($candidats->count() > 1) {
            abort(422, 'BLOCAGE_IDENTITE : cette CIN est déjà attribuée à plusieurs dossiers locaux. Intervention administrative requise.');
        }

        if ($candidats->count() === 1) {
            $existant = $candidats->first();
            abort_unless($utilisateur->peutAccederFaculte((int) $existant->faculte_id), 404);

            return redirect()->route('admin.etudiants.show', $existant)
                ->with('info', 'Dossier local existant trouvé : aucun doublon créé.');
        }

        abort_if($programme === null, 422, 'Programme MENS inconnu du référentiel local : rattachement impossible.');
        abort_unless($utilisateur->peutAccederFaculte((int) $programme->faculte_id), 404);

        $niveauxValides = ['L1', 'L2', 'L3', 'M1', 'M2', 'D1', 'D2', 'D3'];
        $valide = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:etudiants,email'],
            'sexe' => ['nullable', 'in:M,F'],
            'niveau' => ['nullable', 'in:'.implode(',', $niveauxValides)],
            'adresse' => ['required', 'string', 'max:500'],
            'ville' => ['required', 'string', 'max:100'],
            'code_postal' => ['required', 'string', 'max:20'],
            'annee_bac' => ['required', 'string', 'max:10'],
        ]);

        $sexe = $valide['sexe'] ?? (in_array($reference->sexe, ['M', 'F'], true) ? $reference->sexe : null);
        abort_if($sexe === null, 422, 'Sexe indéterminé : précisez-le pour créer le dossier.');

        $niveau = $valide['niveau'] ?? (in_array($reference->niveau, $niveauxValides, true) ? $reference->niveau : null);
        abort_if($niveau === null, 422, 'Niveau indéterminé : précisez-le pour créer le dossier.');

        $anneeAcademique = AnneeUniversitaire::where('active', true)->value('libelle') ?? date('Y');

        try {
            $etudiant = DB::transaction(function () use ($reference, $cinNormalise, $programme, $valide, $sexe, $niveau, $anneeAcademique) {
                $cree = Etudiant::create([
                    'cin' => $cinNormalise,
                    'nom' => (string) ($reference->nom ?? ''),
                    'prenom' => (string) ($reference->prenom ?? ''),
                    'email' => mb_strtolower($valide['email']),
                    'date_naissance' => $reference->date_naissance?->format('Y-m-d'),
                    'sexe' => $sexe,
                    'faculte_id' => $programme->faculte_id,
                    'programme_id' => $programme->id,
                    'niveau' => $niveau,
                    'annee_academique' => $anneeAcademique,
                    'adresse' => $valide['adresse'],
                    'ville' => $valide['ville'],
                    'code_postal' => $valide['code_postal'],
                    'annee_bac' => $valide['annee_bac'],
                    'password' => str()->random(16),
                ]);

                $dossier = app(StudentDossierService::class)->obtenirOuCreer($cree);

                app(AuditService::class)->tracer(
                    $cree,
                    'inscription.dossier_cree_mens',
                    'student_dossier',
                    $dossier->id,
                    null,
                    ['reference' => $dossier->reference, 'mens_id' => $reference->id, 'annee_mens' => $reference->annee],
                );

                return $cree;
            });
        } catch (UniqueConstraintViolationException) {
            // Course perdue : le dossier existe désormais (idempotence).
            $existant = Etudiant::where('cin', $cinNormalise)->firstOrFail();
            abort_unless($utilisateur->peutAccederFaculte((int) $existant->faculte_id), 404);

            return redirect()->route('admin.etudiants.show', $existant)
                ->with('info', 'Dossier local existant trouvé : aucun doublon créé.');
        }

        return redirect()->route('admin.etudiants.show', $etudiant)
            ->with('success', "Dossier facultaire créé depuis la référence MENS ({$reference->annee}).");
    }
}
