<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\IntegrationLog;
use App\Models\VueSauvegardee;
use App\Modules\Finances\Services\FinanceService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Modules\StudentArchive\Services\StudentArchiveService;
use App\Services\AcademicProgressionService;
use App\Services\AuditService;
use App\Services\Exports\ListeEtudiantsService;
use App\Services\FicheEtudiantService;
use App\Services\Interface\RecentsService;
use App\Services\PreferencesService;
use App\Services\StudentStatusService;
use App\Services\Verification\VerificationEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class EtudiantController extends Controller
{
    /**
     * Registre étudiants : MOTEUR CENTRAL ListeEtudiantsService (UNE query
     * scopée, §22) — mêmes filtres/population que preview/export/PDF.
     */
    public function index(Request $request, ListeEtudiantsService $listes)
    {
        Gate::authorize('viewAny', Etudiant::class);

        $faculteRestreinteId = $request->user()->faculteRestreinteId();

        $valide = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'faculte_id' => ['nullable', 'integer', 'exists:facultes,id'],
            'programme_id' => ['nullable', 'integer', 'exists:programmes,id'],
            'niveau' => ['nullable', 'in:L1,L2,L3,M1,M2,D1,D2,D3'],
            'groupe_id' => ['nullable', 'integer', 'exists:groups,id'],
            'sous_groupe_id' => ['nullable', 'integer', 'exists:sous_groupes,id'],
            'annee_universitaire_id' => ['nullable', 'integer', 'exists:annees_universitaires,id'],
            'statut' => ['nullable', 'in:en_attente,valide,rejete'],
            'sans_groupe' => ['nullable', 'boolean'],
            'depose_depuis' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'tri' => ['nullable', 'in:'.implode(',', ListeEtudiantsService::TRIS)],
            'sens' => ['nullable', 'in:asc,desc'],
        ]);

        if ($faculteRestreinteId !== null) {
            $valide['faculte_id'] = $faculteRestreinteId;
        }

        // Phase 7 §7.4 : vue sauvegardée par défaut (filtres pré-remplis
        // seulement si l'URL n'en porte aucun) + lignes par page perso.
        $preferences = app(PreferencesService::class)->lire((int) $request->user()->id);

        if ($request->query() === []) {
            $defaut = VueSauvegardee::where('user_id', $request->user()->id)
                ->where('tableau', 'etudiants')->where('par_defaut', true)->first();

            if ($defaut !== null) {
                $valide = array_merge($valide, array_intersect_key(
                    $defaut->filtres ?? [], array_flip(['search', 'faculte_id', 'programme_id', 'niveau', 'groupe_id', 'statut', 'tri', 'sens'])
                ));
            }
        }

        $query = $listes->requete($valide, $request->user());
        $etudiants = $query->paginate($preferences['lignes_par_page'])->withQueryString();

        // Statistiques (§15) : UNE requête agrégée pour les 4 cartes,
        // bornée au périmètre de faculté de l'utilisateur (§6).
        $ligneStats = Etudiant::query()
            ->when($faculteRestreinteId !== null, fn ($q) => $q->where('faculte_id', $faculteRestreinteId))
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(statut = 'en_attente') as en_attente")
            ->selectRaw("SUM(statut = 'valide') as valide")
            ->selectRaw("SUM(statut = 'rejete') as rejete")
            ->selectRaw('SUM(groupe_id IS NULL) as sans_groupe')
            ->first();
        $stats = [
            'total' => (int) $ligneStats->total,
            'en_attente' => (int) $ligneStats->en_attente,
            'valide' => (int) $ligneStats->valide,
            'rejete' => (int) $ligneStats->rejete,
            'sans_groupe' => (int) ($ligneStats->sans_groupe ?? 0),
        ];

        // Sélecteur de facultés : restreint au périmètre (§6)
        $facultes = $faculteRestreinteId !== null
            ? Faculte::where('id', $faculteRestreinteId)->orderBy('nom')->get()
            : Faculte::orderBy('nom')->get();
        $niveaux = ['L1', 'L2', 'L3', 'M1', 'M2', 'D1', 'D2', 'D3'];
        $programmes = Programme::orderBy('code')
            ->when($faculteRestreinteId !== null, fn ($q) => $q->where('faculte_id', $faculteRestreinteId))->get();
        $groupes = Group::orderBy('code')
            ->when($faculteRestreinteId !== null, fn ($q) => $q->where('faculte_id', $faculteRestreinteId))->get();

        return view('admin.etudiants.index', [
            'etudiants' => $etudiants,
            'facultes' => $facultes,
            'niveaux' => $niveaux,
            'stats' => $stats,
            'programmes' => $programmes,
            'groupes' => $groupes,
            'vues' => VueSauvegardee::where('user_id', $request->user()->id)
                ->where('tableau', 'etudiants')->orderBy('nom')->get(),
        ]);
    }

    /**
     * Étudiants sans groupe (§15) : même moteur, filtre forcé + actions
     * masse/export. Ne duplique jamais la query.
     */
    public function sansGroupe(Request $request, ListeEtudiantsService $listes): View
    {
        Gate::authorize('viewAny', Etudiant::class);

        $filtres = array_merge($request->validate([
            'annee_universitaire_id' => ['nullable', 'integer', 'exists:annees_universitaires,id'],
            'programme_id' => ['nullable', 'integer', 'exists:programmes,id'],
            'niveau' => ['nullable', 'in:L1,L2,L3,M1,M2,D1,D2,D3'],
            'statut' => ['nullable', 'in:en_attente,valide,rejete'],
            'search' => ['nullable', 'string', 'max:120'],
        ]), ['sans_groupe' => true]);

        if ($request->user()->faculteRestreinteId() !== null) {
            $filtres['faculte_id'] = $request->user()->faculteRestreinteId();
        }

        $query = $listes->requete($filtres, $request->user());

        // Pagination configurable (LOOP 1) : même préférence bornée que
        // l'index (PreferencesService, défaut 12) — jamais de 2e source.
        $preferences = app(PreferencesService::class)->lire((int) $request->user()->id);

        return view('admin.etudiants.sans-groupe', [
            'etudiants' => $query->paginate($preferences['lignes_par_page'])->withQueryString(),
            'total' => (clone $query)->count(),
            'filtres' => $filtres,
            'groupes' => Group::orderBy('code')
                ->when($request->user()->faculteRestreinteId() !== null,
                    fn ($q) => $q->where('faculte_id', $request->user()->faculteRestreinteId()))->get(),
            'annees' => AnneeUniversitaire::orderByDesc('libelle')->get(),
        ]);
    }

    public function show(Etudiant $etudiant)
    {
        // Anti-IDOR : fiche accessible uniquement dans le périmètre
        Gate::authorize('view', $etudiant);

        app(RecentsService::class)->enregistrer(
            (int) request()->user()->id, 'etudiant', (string) $etudiant->id,
            $etudiant->nom_complet, route('admin.etudiants.show', $etudiant)
        );

        $etudiant->loadMissing([
            'faculte', 'programmeRef', 'niveauRef', 'groupe',
            'affectations.groupe', 'affectations.anneeUniversitaire',
            'situations', 'inscriptionsAcademiques.anneeUniversitaire',
        ]);

        $user = request()->user();
        $faculteId = $user->faculteRestreinteId();

        $fiche = app(FicheEtudiantService::class);

        $derniereInscription = $etudiant->inscriptionsAcademiques
            ->sortByDesc(fn ($i) => $i->anneeUniversitaire?->date_debut ?? '')->first();

        return view('admin.etudiants.show', array_merge(compact('etudiant'), [
            'groupesOptions' => Group::orderBy('code')
                ->withCount(['affectations as effectif_actuel' => fn ($q) => $q->where('statut', 'active')])
                ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))->get(),
            'programmesOptions' => Programme::orderBy('code')
                ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))->get(),
            'niveauxOptions' => Niveau::with('programme')->orderBy('ordre')
                ->when($faculteId !== null, fn ($q) => $q->whereHas('programme',
                    fn ($p) => $p->where('faculte_id', $faculteId)))->get(),
            'anneesOptions' => AnneeUniversitaire::orderByDesc('date_debut')->get(),
            'resume' => $fiche->resume($etudiant),
            'timeline' => $fiche->timeline($etudiant),
            'completude' => $fiche->completude($etudiant),
            'anomaliesFiche' => $fiche->anomalies($etudiant),
            // Doublons limités au périmètre de l'agent : jamais de CIN/email
            // d'une autre faculté sur cette fiche (le moteur central reste
            // global pour la vérification qualité).
            'doublons' => $fiche->doublonsPotentiels($etudiant, $faculteId),
            'soldeFinance' => request()->user()->can('finances.view') && config('modules.finances.active')
                ? app(FinanceService::class)->soldeEtudiant($etudiant)
                : null,
            'progression' => $derniereInscription !== null
                ? app(AcademicProgressionService::class)->evaluer($etudiant, $derniereInscription)
                : null,
            'derniereInscription' => $derniereInscription,
            'verification' => app(VerificationEngine::class)->verifier($etudiant),
            'ficheEligibilite' => $fiche->eligibiliteAffectation($etudiant, $derniereInscription?->anneeUniversitaire),
        ]));
    }

    public function updateStatut(Request $request, Etudiant $etudiant)
    {
        Gate::authorize('update', $etudiant);

        $valide = $request->validate([
            'statut' => 'required|in:en_attente,valide,rejete',
            'motif' => 'nullable|string|max:255',
        ]);

        // Transition contrôlée + historisée via le service (point unique).
        try {
            app(StudentStatusService::class)->changer(
                $etudiant, $valide['statut'], (int) $request->user()->id, $valide['motif'] ?? null
            );
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['statut' => $e->getMessage()]);
        }

        $labels = [
            'en_attente' => 'en attente',
            'valide' => 'validé',
            'rejete' => 'rejeté',
        ];

        return redirect()->back()
            ->with('success', "Le statut de l'étudiant {$etudiant->nom_complet} ({$etudiant->num_inscription}) a été changé en « {$labels[$valide['statut']]} ».");
    }

    /**
     * Réinitialisation du mot de passe étudiant (LOOP 10, trappe « mot de
     * passe perdu ») : l'étudiant n'a pas de canal email — la scolarité
     * est l'unique porte de secours. Mot de passe fort généré serveur,
     * affiché UNE fois en flash (jamais stocké en clair, jamais loggé),
     * audit sans PII. Permission `archives.update` + périmètre faculté.
     */
    public function reinitialiserMotDePasse(Request $request, Etudiant $etudiant): RedirectResponse
    {
        Gate::authorize('update', $etudiant);

        $valide = $request->validate([
            'confirmation' => ['required', 'accepted'],
        ]);

        $nouveau = Str::password(16, symbols: false);

        $etudiant->update(['password' => $nouveau]);

        // AUTH-01 (correctif 13/10) : le reset invalide les sessions
        // étudiant ouvertes (cookie volé compris) via la version de mot
        // de passe, re-vérifiée par EnsureStudent à chaque requête.
        $etudiant->increment('mot_de_passe_version');

        IntegrationLog::tracer(
            'etudiants',
            'internal',
            'etudiant.mot_de_passe_reinitialise',
            'completed',
            null,
            200,
            null,
            null,
            (string) Str::uuid(),
            'ETU-'.$etudiant->id,
        );

        return redirect()->route('admin.etudiants.show', $etudiant)
            ->with('mot_de_passe_provisoire', $nouveau)
            ->with('success', 'Mot de passe réinitialisé — communiquez-le à l\'étudiant maintenant, il ne sera plus affiché.');
    }

    /**
     * Changement de statut en masse (sélection) : permission + périmètre
     * par étudiant, transaction unique, audit individuel. Confirmation
     * explicite exigée (jamais de validation silencieuse en masse).
     */
    public function bulkStatut(Request $request): RedirectResponse|JsonResponse
    {
        $valide = $request->validate([
            'ids' => 'required|array|min:1|max:200',
            'ids.*' => 'integer|exists:etudiants,id',
            'statut' => 'required|in:valide,rejete',
            'confirmation' => 'required|accepted',
        ]);

        // Dédupliquer : un doublon d'ID ne doit ni compter double ni
        // écrire deux fois (idempotence du lot).
        $valide['ids'] = array_values(array_unique($valide['ids']));

        $etudiants = Etudiant::whereIn('id', $valide['ids'])->get();
        $compteur = 0;
        $service = app(StudentStatusService::class);

        DB::transaction(function () use ($etudiants, $valide, $service, &$compteur) {
            foreach ($etudiants as $etudiant) {
                Gate::authorize('update', $etudiant);
                $service->changer($etudiant, $valide['statut'], null, 'bulk');
                $compteur++;
            }
        });

        $libelle = $valide['statut'] === 'valide' ? 'validés' : 'rejetés';

        if ($request->expectsJson()) {
            return response()->json(['message' => "{$compteur} dossier(s) {$libelle}.", 'count' => $compteur]);
        }

        return redirect()->back()
            ->with('success', "{$compteur} dossier(s) {$libelle}.");
    }

    /**
     * Export dossier individuel unifié (Phase 8 §25-26) : synthèse +
     * parcours + académique + documents (métadonnées) + demandes +
     * timeline bornée — depuis StudentArchiveService (zéro recopie).
     * Sensibles exclus par construction. Audité `export_dossier`.
     */
    public function exportDossier(Etudiant $etudiant): JsonResponse
    {
        Gate::authorize('view', $etudiant);

        $archives = app(StudentArchiveService::class);

        // Synthèse SANS le modèle étudiant complet (overview() embarque
        // l'entité avec CIN/téléphone/adresse — exclus ici, le bloc
        // 'etudiant' curé ci-dessus fait foi).
        $synthese = $archives->overview($etudiant);
        unset($synthese['etudiant']);

        $export = [
            'etudiant' => [
                'id' => $etudiant->id,
                'num_inscription' => $etudiant->num_inscription,
                'nom' => $etudiant->nom,
                'prenom' => $etudiant->prenom,
                'email' => $etudiant->email,
                'faculte' => $etudiant->faculte?->code,
                'statut' => $etudiant->statut,
            ],
            'synthese' => $synthese,
            'parcours' => $archives->registrations($etudiant)->map(fn ($r) => [
                'annee' => $r['inscription']->anneeUniversitaire?->libelle,
                'niveau' => $r['inscription']->niveau,
                'statut' => $r['inscription']->statut,
                'type' => $r['inscription']->type,
            ])->values()->all(),
            'documents' => $archives->documents($etudiant, [], request()->user())->map(fn ($d) => [
                'source' => $d['source'], 'titre' => $d['titre'], 'type' => $d['type'],
                'reference' => $d['reference'], 'statut' => $d['statut'],
            ])->values()->all(),
            'demandes' => $archives->demandes($etudiant)['demandes']->map(fn ($d) => [
                'reference' => $d->reference, 'objet' => $d->objet, 'status' => $d->status,
            ])->values()->all(),
            'timeline' => $archives->timeline($etudiant, ['limite' => 100]),
        ];

        app(AuditService::class)->tracer(
            $etudiant, 'export_dossier', 'dossier', $etudiant->id,
        );

        return response()->json($export, 200, [
            'Content-Disposition' => 'attachment; filename="dossier-'.$etudiant->num_inscription.'.json"',
        ]);
    }

    public function destroy(Etudiant $etudiant)
    {
        Gate::authorize('delete', $etudiant);

        $nom = $etudiant->nom_complet;
        $num = $etudiant->num_inscription;

        /*
         | Transaction : les documents physiques disparaissent AVEC les
         | lignes BD (cascade). Sans ceci, la suppression laisserait des
         | fichiers PII orphelins sur disque (audit §P1-8) — la DB cascade
         | bien les lignes documents_academiques, mais jamais les fichiers.
         */
        DB::transaction(function () use ($etudiant) {
            $etudiant->documentsAcademiques()->get()
                ->each(fn ($document) => Storage::disk('local')->delete($document->chemin));

            $etudiant->delete();
        });

        // Traçabilité technique de la destruction (sans PII) — miroir de
        // la réinitialisation de mot de passe. Ordre fichiers-puis-DB
        // conservé : jamais de PII orpheline sur disque ; les orphelins
        // inverses éventuels sont repris par `documents:purger-orphelins`.
        IntegrationLog::tracer(
            'etudiants',
            'internal',
            'etudiant.supprime',
            'completed',
            null,
            200,
            null,
            null,
            (string) Str::uuid(),
            'ETU-'.$etudiant->id,
        );

        return redirect()->route('admin.etudiants.index')
            ->with('success', "Le dossier de l'étudiant « {$nom} » ({$num}) a été supprimé avec succès.");
    }
}
