<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\HistoriqueAcademique;
use App\Models\MensReference;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\StudentDossiers\Services\StudentDossierService;
use App\Services\AuditService;
use App\Services\NormalisationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * Emplacement data MENS (référentiel national) : consultation scopée,
 * manipulation directe contrôlée (ajout, correction, suppression
 * protégée) et import de masse via le pipeline DataExchange
 * (`admin.donnees`, entité `referentiel-mens`, jamais dupliqué ici).
 *
 * Périmètre : global = tout ; restreint = lignes rattachables à la
 * faculté (CIN apparié à un étudiant local de la faculté OU
 * `programme_code` d'un programme de la faculté) — même règle pour la
 * consultation, la correction et la suppression : lisible ⇔ agissable.
 *
 * La clé d'identité reste [CIN normalisée, année] : l'ajout/correction
 * rejoue les règles de l'import (CIN 8 chiffres, unicité CIN+année,
 * empreinte du contenu) sans jamais passer par lui — l'import
 * idempotent et l'identification fail-closed restent inchangés.
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
            $this->appliquerPerimetre($query, $restreintId);
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
     * Formulaire d'ajout manuel — une référence absente du fichier
     * officiel peut être saisie directement, sans passer par l'import.
     */
    public function create(): View
    {
        return view('admin.mens.form', ['reference' => null]);
    }

    /**
     * Ajout direct : mêmes règles que l'import (CIN 8 chiffres, clé
     * [CIN normalisée, année], empreinte du contenu) sans jamais
     * passer par le pipeline DataExchange — celui-ci reste la voie de
     * masse, inchangé. Un compte restreint ne peut créer qu'une
     * référence rattachable à SA faculté (créable ⇔ visible).
     */
    public function store(Request $request): RedirectResponse
    {
        $valide = $this->valider($request);
        $cinNormalise = MensReference::normaliserCin((string) $valide['cin']);
        $this->verifierRattachement($request, $cinNormalise, $valide['programme_code'] ?? null);

        try {
            $reference = DB::transaction(function () use ($valide, $cinNormalise) {
                $reference = MensReference::create($this->donneesCanoniques($valide, $cinNormalise));

                // Journalisation sans PII : CIN masquée, ni nom ni date
                // de naissance (règle absolue de tracerReferentiel).
                app(AuditService::class)->tracerReferentiel(
                    HistoriqueAcademique::ACTION_MENS_CREATION,
                    'mens_reference',
                    $reference->id,
                    null,
                    ['annee' => $reference->annee, 'cin_masquee' => $this->masquerCin($cinNormalise), 'saisie' => 'manuelle'],
                );

                return $reference;
            });
        } catch (UniqueConstraintViolationException) {
            return back()->withInput()->withErrors([
                'cin' => 'Cette CIN est déjà référencée pour cette année (conflit de saisie concurrente).',
            ]);
        }

        return redirect()->route('admin.mens.show', $reference)
            ->with('success', 'Référence MENS ajoutée.');
    }

    /**
     * Fiche détail : données complètes, provenance, dossiers locaux
     * rapprochés (filtrés au périmètre) et journal des actions sur la
     * référence — support explicite des règles de suppression.
     */
    public function show(Request $request, MensReference $reference): View
    {
        $this->verifierPerimetre($request, $reference);

        $etudiantsRapproches = $this->etudiantsRapproches($reference)
            ->filter(fn (Etudiant $etudiant) => $request->user()->peutAccederFaculte((int) $etudiant->faculte_id))
            ->values();
        $etudiantsRapproches->loadMissing('faculte');

        return view('admin.mens.show', [
            'reference' => $reference,
            'etudiantsRapproches' => $etudiantsRapproches,
            'journal' => HistoriqueAcademique::where('entite', 'mens_reference')
                ->where('entite_id', $reference->id)
                ->with('utilisateur')
                ->latest('id')
                ->limit(15)
                ->get(),
            'blocagesSuppression' => $this->blocagesSuppression($reference),
        ]);
    }

    /**
     * Formulaire de correction contrôlée (champs de données seulement).
     */
    public function edit(Request $request, MensReference $reference): View
    {
        $this->verifierPerimetre($request, $reference);

        return view('admin.mens.form', ['reference' => $reference]);
    }

    /**
     * Correction contrôlée : unicité CIN+année re-vérifiée en
     * s'excluant elle-même, périmètre re-vérifié sur l'état CIBLE,
     * provenance d'import préservée, empreinte recalculée, action
     * journalisée (CIN masquée, valeurs jamais journalisées).
     */
    public function update(Request $request, MensReference $reference): RedirectResponse
    {
        $this->verifierPerimetre($request, $reference);

        $valide = $this->valider($request, $reference->id);
        $cinNormalise = MensReference::normaliserCin((string) $valide['cin']);
        $this->verifierRattachement($request, $cinNormalise, $valide['programme_code'] ?? null);

        $donnees = $this->donneesCanoniques($valide, $cinNormalise);
        $champsModifies = $this->champsModifies($reference, $donnees);

        if ($champsModifies === []) {
            return redirect()->route('admin.mens.show', $reference)
                ->with('info', 'Aucune modification : la référence est inchangée.');
        }

        $avant = [
            'annee' => $reference->annee,
            'cin_masquee' => $this->masquerCin((string) $reference->cin_normalise),
        ];

        try {
            DB::transaction(function () use ($reference, $donnees, $avant, $champsModifies) {
                // La provenance d'import (imported_by/imported_at) reste
                // intacte : une correction manuelle n'est pas un import.
                $reference->update($donnees);

                app(AuditService::class)->tracerReferentiel(
                    HistoriqueAcademique::ACTION_MENS_MODIFICATION,
                    'mens_reference',
                    $reference->id,
                    $avant,
                    [
                        'annee' => $reference->annee,
                        'cin_masquee' => $this->masquerCin((string) $reference->cin_normalise),
                        'champs_modifies' => $champsModifies,
                    ],
                );
            });
        } catch (UniqueConstraintViolationException) {
            return back()->withInput()->withErrors([
                'cin' => 'Cette CIN est déjà référencée pour cette année (conflit de saisie concurrente).',
            ]);
        }

        return redirect()->route('admin.mens.show', $reference)
            ->with('success', 'Référence MENS corrigée.');
    }

    /**
     * Suppression explicite, refusée si la référence a des dépendances
     * — dossier(s) local(aux) rapproché(s) (identification,
     * préremplissage) ou traces historiques de création de dossier —
     * et toujours journalisée. Jamais de suppression de données
     * ambiguës ou déjà consommées.
     */
    public function destroy(Request $request, MensReference $reference): RedirectResponse
    {
        $this->verifierPerimetre($request, $reference);

        $blocages = $this->blocagesSuppression($reference);

        if ($blocages !== []) {
            return redirect()->route('admin.mens.show', $reference)
                ->withErrors(['reference' => 'Suppression bloquée : '.implode(' ; ', $blocages).'. Corrigez la référence ou signalez-la plutôt que de la retirer.']);
        }

        $avant = [
            'annee' => $reference->annee,
            'cin_masquee' => $this->masquerCin((string) $reference->cin_normalise),
        ];

        DB::transaction(function () use ($reference, $avant) {
            app(AuditService::class)->tracerReferentiel(
                HistoriqueAcademique::ACTION_MENS_SUPPRESSION,
                'mens_reference',
                $reference->id,
                $avant,
                null,
            );

            $reference->delete();
        });

        return redirect()->route('admin.mens.index')
            ->with('success', 'Référence MENS supprimée.');
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
        $candidats = $this->etudiantsRapproches($reference);

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

    /**
     * Assistance de saisie CIN (JSON borné) : pré-remplissage d'identité
     * (dossier local apparié, sinon référence d'une autre année déjà
     * connue) et alerte d'unicité [CIN, année]. Mêmes comparateurs et
     * mêmes règles de périmètre que l'index et l'enregistrement :
     * aucune recherche partielle (égalité stricte sur la forme
     * normalisée), rien n'est révélé hors périmètre facultaire.
     */
    public function assistance(Request $request): JsonResponse
    {
        $valide = $request->validate([
            'cin' => ['required', 'string', 'max:30'],
            'annee' => ['nullable', 'string', 'max:20'],
            'ignorer' => ['nullable', 'integer'],
        ]);

        $utilisateur = $request->user();
        $restreintId = $utilisateur->faculteRestreinteId();
        $normalise = app(NormalisationService::class)->cin($valide['cin']);
        $ignorer = isset($valide['ignorer']) ? (int) $valide['ignorer'] : null;

        $reponse = ['prefill' => null, 'apercu' => null, 'ambigu' => false, 'doublon' => false];

        if ($normalise === null) {
            return response()->json($reponse);
        }

        // Alerte d'unicité : même contrôle que `valider()` — le message
        // d'erreur à l'enregistrement est lui aussi global, la contrainte
        // de base l'étant (aucune découverte hors périmètre supplémentaire).
        if (! empty($valide['annee'])) {
            $reponse['doublon'] = MensReference::query()
                ->where('cin_normalise', $normalise)
                ->where('annee', $valide['annee'])
                ->when($ignorer !== null, fn ($q) => $q->whereKeyNot($ignorer))
                ->exists();
        }

        $etudiants = $this->etudiantsParCin($valide['cin'])
            ->filter(fn (Etudiant $etudiant) => $utilisateur->peutAccederFaculte((int) $etudiant->faculte_id));

        if ($etudiants->count() > 1) {
            // Même refus que `creerDossier` : jamais de fusion ni de
            // choix silencieux quand plusieurs dossiers partagent la CIN.
            $reponse['ambigu'] = true;

            return response()->json($reponse);
        }

        if ($etudiants->count() === 1) {
            $etudiant = $etudiants->first();

            $reponse['prefill'] = [
                'nom' => (string) $etudiant->nom,
                'prenom' => (string) $etudiant->prenom,
                'date_naissance' => $etudiant->date_naissance?->format('Y-m-d'),
                'sexe' => in_array($etudiant->sexe, ['M', 'F'], true) ? $etudiant->sexe : null,
            ];
            $reponse['apercu'] = 'Dossier local : '.trim($etudiant->nom.' '.$etudiant->prenom);

            return response()->json($reponse);
        }

        // Repli : référence MENS d'une autre année portant la même CIN
        // (uniformise une réinscription), cloisonnée par périmètre.
        $autre = MensReference::query()
            ->where('cin_normalise', $normalise)
            ->when($ignorer !== null, fn ($q) => $q->whereKeyNot($ignorer))
            ->when($restreintId !== null, fn ($q) => $this->appliquerPerimetre($q, $restreintId))
            ->orderByDesc('annee')
            ->first();

        if ($autre !== null) {
            $reponse['prefill'] = [
                'nom' => (string) $autre->nom,
                'prenom' => (string) $autre->prenom,
                'date_naissance' => $autre->date_naissance?->format('Y-m-d'),
                'sexe' => in_array($autre->sexe, ['M', 'F'], true) ? $autre->sexe : null,
            ];
            $reponse['apercu'] = 'Référence MENS '.$autre->annee.' : '.trim((string) $autre->nom.' '.(string) $autre->prenom);
        }

        return response()->json($reponse);
    }

    /**
     * Référentiels d'auto-complétion du formulaire : listes bornées,
     * cloisonnées par périmètre (un compte restreint ne reçoit que les
     * programmes de sa faculté). Uniquement des valeurs déjà visibles
     * sur les écrans MENS — jamais de dossier ni de donnée personnelle.
     */
    public function referentiels(Request $request): JsonResponse
    {
        $restreintId = $request->user()->faculteRestreinteId();

        $programmes = Programme::query()
            ->whereNotNull('code')
            ->where('code', '!=', '')
            ->when($restreintId !== null, fn ($q) => $q->where('faculte_id', $restreintId))
            ->orderBy('code')
            ->limit(500)
            ->get(['code', 'nom', 'sigle'])
            ->map(fn (Programme $programme) => [
                'code' => (string) $programme->code,
                'libelle' => trim($programme->code.' — '.$programme->nom.($programme->sigle ? ' ('.$programme->sigle.')' : '')),
            ])
            ->values();

        $annees = AnneeUniversitaire::query()
            ->orderByDesc('libelle')
            ->limit(10)
            ->pluck('libelle')
            ->merge(MensReference::query()->distinct()->orderByDesc('annee')->limit(50)->pluck('annee'))
            ->filter(fn ($annee) => is_string($annee) && $annee !== '')
            ->unique()
            ->sortDesc()
            ->values();

        return response()->json([
            'annees' => $annees,
            'programmes' => $programmes,
            'etablissements' => MensReference::query()
                ->whereNotNull('etablissement')->where('etablissement', '!=', '')
                ->distinct()->orderBy('etablissement')->limit(200)->pluck('etablissement')->values(),
            'nationalites' => MensReference::query()
                ->whereNotNull('nationalite')->where('nationalite', '!=', '')
                ->distinct()->orderBy('nationalite')->limit(100)->pluck('nationalite')->values(),
        ]);
    }

    /**
     * Validation commune ajout/correction — MÊMES règles que l'import
     * (`MensImportDefinition::regles`) : aucune voie ne contourne la
     * CIN 8 chiffres. Unicité [CIN normalisée, année] vérifiée en
     * s'excluant elle-même en correction ; la contrainte BD reste la
     * garantie finale (course → UniqueConstraintViolationException).
     *
     * @return array<string, mixed>
     */
    private function valider(Request $request, ?int $ignoreId = null): array
    {
        $validateur = Validator::make($request->all(), [
            'cin' => ['required', 'string', 'max:30', NormalisationService::REGLE_CIN],
            'date_naissance' => 'nullable|date',
            'nom' => 'required|string|max:100',
            'prenom' => 'required|string|max:100',
            'nom_ar' => 'nullable|string|max:100',
            'prenom_ar' => 'nullable|string|max:100',
            'sexe' => 'nullable|in:M,F',
            'nationalite' => 'nullable|string|max:50',
            'matricule_national' => 'nullable|string|max:30',
            'etablissement' => 'nullable|string|max:150',
            'annee' => 'required|string|max:20',
            'programme_code' => 'nullable|string|max:30',
            'niveau' => 'nullable|in:L1,L2,L3,M1,M2,D1,D2,D3',
        ]);

        $validateur->after(function ($validateur) use ($request, $ignoreId) {
            $cin = $request->input('cin');
            $annee = $request->input('annee');

            if (! is_string($cin) || $cin === '' || ! is_string($annee) || $annee === '') {
                return;
            }

            $duplicat = MensReference::query()
                ->where('cin_normalise', MensReference::normaliserCin($cin))
                ->where('annee', $annee)
                ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
                ->exists();

            if ($duplicat) {
                $validateur->errors()->add('cin', 'Cette CIN est déjà référencée pour cette année. Corrigez l\'année ou la CIN.');
            }
        });

        return $validateur->validate();
    }

    /**
     * Dépendances bloquantes de suppression : dossier(s) local(aux)
     * rapproché(s) (comparateur identique à `creerDossier`) et traces
     * historiques `inscription.dossier_cree_mens` référençant la ligne.
     *
     * @return array<int, string>
     */
    private function blocagesSuppression(MensReference $reference): array
    {
        $blocages = [];

        $rapproches = $this->etudiantsRapproches($reference)->count();

        if ($rapproches > 0) {
            $blocages[] = $rapproches.' dossier(s) local(aux) rapproché(s) (identification et préremplissage en dépendent)';
        }

        if ($this->aTracesCreationDossier($reference)) {
            $blocages[] = 'des traces historiques de création de dossier référencent cette ligne (traçabilité)';
        }

        return $blocages;
    }

    /**
     * Une trace `inscription.dossier_cree_mens` référence-t-elle cette
     * ligne ? Filtrage applicatif exact sur le payload json — jamais de
     * LIKE approximatif (indépendant du SGBD et de l'ordre des clés).
     */
    private function aTracesCreationDossier(MensReference $reference): bool
    {
        return HistoriqueAcademique::query()
            ->where('action', 'inscription.dossier_cree_mens')
            ->get(['id', 'nouvelle_valeur'])
            ->contains(function (HistoriqueAcademique $trace) use ($reference) {
                $payload = json_decode((string) $trace->nouvelle_valeur, true);

                return is_array($payload) && (int) ($payload['mens_id'] ?? 0) === $reference->id;
            });
    }

    /**
     * Étudiants locaux partageant la CIN de la référence — même
     * comparateur que `creerDossier` et l'assistance de saisie.
     *
     * @return Collection<int, Etudiant>
     */
    private function etudiantsRapproches(MensReference $reference): Collection
    {
        return $this->etudiantsParCin((string) $reference->cin_normalise, $reference->cin_original);
    }

    /**
     * Étudiants locaux partageant une CIN sous ses formes connues —
     * comparateur unique partagé par `creerDossier`, la pré-vérification
     * de suppression et l'assistance de saisie (CIN normalisée, forme
     * d'origine, forme espacée), puis filtre applicatif sur la forme
     * normalisée exacte : jamais de correspondance approximative.
     *
     * @return Collection<int, Etudiant>
     */
    private function etudiantsParCin(string $cinNormalise, ?string $cinOriginal = null): Collection
    {
        $normalise = app(NormalisationService::class)->cin($cinNormalise);

        if ($normalise === null) {
            return collect();
        }

        return Etudiant::query()
            ->where(function ($q) use ($cinNormalise, $cinOriginal, $normalise) {
                $q->where('cin', $cinNormalise);

                if ($cinOriginal !== null) {
                    $q->orWhere('cin', $cinOriginal);
                }

                $q->orWhereRaw("REPLACE(UPPER(TRIM(cin)), ' ', '') = ?", [$normalise]);
            })
            ->get()
            ->filter(fn (Etudiant $etudiant) => app(NormalisationService::class)->cin((string) $etudiant->cin) === $normalise)
            ->values();
    }

    /**
     * Périmètre facultaire d'une requête `mens_references` — même règle
     * que l'index et `verifierPerimetre` (CIN appariée à un étudiant de
     * la faculté OU `programme_code` d'un programme de la faculté).
     */
    private function appliquerPerimetre(Builder $query, int $restreintId): Builder
    {
        $codesProgrammes = Programme::where('faculte_id', $restreintId)->pluck('code')->all();

        return $query->where(function ($q) use ($restreintId, $codesProgrammes) {
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

    /**
     * Périmètre facultaire d'une référence EXISTANTE — même règle que
     * l'index (CIN appariée à un étudiant de la faculté OU
     * `programme_code` d'un programme de la faculté) : hors périmètre
     * → 404, l'existence de la ligne n'est jamais révélée.
     */
    private function verifierPerimetre(Request $request, MensReference $reference): void
    {
        $this->verifierRattachement($request, (string) $reference->cin_normalise, $reference->programme_code);
    }

    /**
     * Rattachement facultaire d'un état CIBLE (création ou correction) :
     * « créable ⇔ visible ». Compte global : aucun contrôle.
     */
    private function verifierRattachement(Request $request, string $cinNormalise, ?string $programmeCode): void
    {
        $restreintId = $request->user()->faculteRestreinteId();

        if ($restreintId === null) {
            return;
        }

        $appariee = Etudiant::query()
            ->where('faculte_id', $restreintId)
            ->where('cin', $cinNormalise)
            ->exists();

        $programmeEnPerimetre = $programmeCode !== null && $programmeCode !== ''
            && Programme::query()->where('faculte_id', $restreintId)->where('code', $programmeCode)->exists();

        abort_unless($appariee || $programmeEnPerimetre, 404);
    }

    /**
     * Données canoniques écrites en base — même forme que l'import
     * (`MensImportDefinition::persister`) : clé [cin_normalise, annee],
     * `cin_original` conservée telle que saisie, empreinte sha256 du
     * contenu de données (la provenance d'import est exclue : une
     * saisie manuelle n'importe pas, elle est tracée par l'audit).
     *
     * @param  array<string, mixed>  $valide
     * @return array<string, mixed>
     */
    private function donneesCanoniques(array $valide, string $cinNormalise): array
    {
        $donnees = [
            'cin_normalise' => $cinNormalise,
            'annee' => (string) $valide['annee'],
            'cin_original' => mb_substr(trim((string) $valide['cin']), 0, 30),
            'date_naissance' => ($valide['date_naissance'] ?? null) !== null && $valide['date_naissance'] !== ''
                ? Carbon::parse((string) $valide['date_naissance'])->format('Y-m-d')
                : null,
            'nom' => $valide['nom'],
            'prenom' => $valide['prenom'],
            'nom_ar' => $valide['nom_ar'] ?? null,
            'prenom_ar' => $valide['prenom_ar'] ?? null,
            'sexe' => $valide['sexe'] ?? null,
            'nationalite' => $valide['nationalite'] ?? null,
            'matricule_national' => $valide['matricule_national'] ?? null,
            'etablissement' => $valide['etablissement'] ?? null,
            'programme_code' => $valide['programme_code'] ?? null,
            'niveau' => $valide['niveau'] ?? null,
        ];

        $contenu = [
            'cin_original' => $donnees['cin_original'],
            'date_naissance' => $donnees['date_naissance'],
            'nom' => $donnees['nom'],
            'prenom' => $donnees['prenom'],
            'nom_ar' => $donnees['nom_ar'],
            'prenom_ar' => $donnees['prenom_ar'],
            'sexe' => $donnees['sexe'],
            'nationalite' => $donnees['nationalite'],
            'matricule_national' => $donnees['matricule_national'],
            'etablissement' => $donnees['etablissement'],
            'programme_code' => $donnees['programme_code'],
            'niveau' => $donnees['niveau'],
        ];

        $donnees['source_hash'] = MensReference::empreinte(
            ['cin' => $cinNormalise, 'annee' => $donnees['annee']]
                + array_filter($contenu, fn ($valeur) => $valeur !== null)
        );

        return $donnees;
    }

    /**
     * Libellés des champs réellement modifiés (noms seuls, jamais les
     * valeurs) pour le journal d'audit.
     *
     * @param  array<string, mixed>  $donnees
     * @return array<int, string>
     */
    private function champsModifies(MensReference $reference, array $donnees): array
    {
        $avant = [
            'cin' => (string) $reference->cin_normalise,
            'date_naissance' => $reference->date_naissance?->format('Y-m-d'),
            'nom' => $reference->nom,
            'prenom' => $reference->prenom,
            'nom_ar' => $reference->nom_ar,
            'prenom_ar' => $reference->prenom_ar,
            'sexe' => $reference->sexe,
            'nationalite' => $reference->nationalite,
            'matricule_national' => $reference->matricule_national,
            'etablissement' => $reference->etablissement,
            'annee' => (string) $reference->annee,
            'programme_code' => $reference->programme_code,
            'niveau' => $reference->niveau,
        ];

        $apres = [
            'cin' => $donnees['cin_normalise'],
            'date_naissance' => $donnees['date_naissance'],
            'nom' => $donnees['nom'],
            'prenom' => $donnees['prenom'],
            'nom_ar' => $donnees['nom_ar'],
            'prenom_ar' => $donnees['prenom_ar'],
            'sexe' => $donnees['sexe'],
            'nationalite' => $donnees['nationalite'],
            'matricule_national' => $donnees['matricule_national'],
            'etablissement' => $donnees['etablissement'],
            'annee' => $donnees['annee'],
            'programme_code' => $donnees['programme_code'],
            'niveau' => $donnees['niveau'],
        ];

        $champs = [];

        foreach ($avant as $champ => $valeurAvant) {
            if ((string) $valeurAvant !== (string) $apres[$champ]) {
                $champs[] = $champ;
            }
        }

        return $champs;
    }

    /**
     * Forme masquée pour les journaux (jamais de CIN complète dans
     * l'audit) : « 12345678 » → « 12***678 ».
     */
    private function masquerCin(string $cin): string
    {
        if (mb_strlen($cin) <= 4) {
            return '***';
        }

        return mb_substr($cin, 0, 2).'***'.mb_substr($cin, -3);
    }
}
