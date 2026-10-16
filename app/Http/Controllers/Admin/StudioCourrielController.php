<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Courriel;
use App\Models\JournalModele;
use App\Models\ModeleCourriel;
use App\Models\ThemeCourriel;
use App\Services\ControleQualiteCourriel;
use App\Services\MoteurRenduCourriel;
use App\Services\StudioCourrielService;
use App\Services\ValidateurSchemaCourriel;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Studio de modèles e-mail (LOOP-MAIL-TEMPLATE-STUDIO-03) : bibliothèque,
 * éditeur (sauvegardes JSON validées serveur), cycle de vie, thèmes,
 * comparatif, envoi de test autorisé. Permissions distinctes par action,
 * CSRF du groupe web, limitation de débit sur les écritures. Les 7
 * modèles du catalogue restent verrouillés et intacts (lecture seule).
 */
class StudioCourrielController extends Controller
{
    public function __construct(
        private readonly StudioCourrielService $studio,
        private readonly MoteurRenduCourriel $moteur,
        private readonly ControleQualiteCourriel $qualite,
    ) {}

    public function index(Request $request): View
    {
        return view('admin.studio.index', [
            'modeles' => $this->studio->bibliotheque(
                $request->string('q')->toString() ?: null,
                $request->string('statut')->toString() ?: null,
                $request->string('categorie')->toString() ?: null,
            ),
            'systeme' => (array) config('courriels.modeles', []),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'code' => ['required', 'string', 'max:60'],
            'nom' => ['required', 'string', 'max:150'],
            'categorie' => ['nullable', 'string', 'max:40'],
            'schema' => ['required', 'array'],
        ]);

        try {
            $modele = $this->studio->creer(
                $donnees['code'],
                $donnees['nom'],
                (string) ($donnees['categorie'] ?? ''),
                $donnees['schema'],
                $request->user()?->id
            );
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('admin.studio.editer', $modele)
            ->with('success', 'Modèle créé en brouillon (version 1).');
    }

    public function voir(Request $request, ModeleCourriel $modele): View
    {
        $versions = $modele->versions()->orderByDesc('numero')->get();
        $comparatif = null;

        $numeroA = (int) $request->integer('v1');
        $numeroB = (int) $request->integer('v2');
        if ($numeroA > 0 && $numeroB > 0 && $numeroA !== $numeroB) {
            $versionA = $versions->firstWhere('numero', $numeroA);
            $versionB = $versions->firstWhere('numero', $numeroB);
            if ($versionA !== null && $versionB !== null) {
                $comparatif = $this->studio->comparer($versionA, $versionB);
            }
        }

        return view('admin.studio.voir', [
            'modele' => $modele,
            'versions' => $versions,
            'journal' => JournalModele::where('modele_id', $modele->id)->latest('id')->limit(50)->get(),
            'comparatif' => $comparatif,
            'numeroA' => $numeroA,
            'numeroB' => $numeroB,
        ]);
    }

    public function editer(ModeleCourriel $modele): View|RedirectResponse
    {
        if (! $modele->estModifiable()) {
            return redirect()->route('admin.studio.voir', $modele)
                ->with('error', 'Modèle verrouillé ou archivé : consultation seule.');
        }

        $derniere = $modele->derniereVersion();

        $vues = [
            'editer' => 'admin.studio.editer',
            'v2' => 'admin.studio.editer-v2',
        ];

        // Déploiement progressif : éditeur v1 conservé par défaut.
        $vue = ((bool) config('courriels.editeur_v2', false)) ? $vues['v2'] : $vues['editer'];

        return view($vue, [
            'modele' => $modele,
            'schema' => $derniere?->schema ?? [],
            'baseVersion' => $derniere?->numero ?? 0,
            'themes' => ThemeCourriel::where('actif', true)->orderBy('nom')->get(),
            'variables' => (array) config('courriels.variables_autorisees', []),
            'squelettes' => $vue === $vues['v2'] ? StudioCourrielService::squelettes() : [],
        ]);
    }

    /**
     * Sauvegarde explicite (JSON) : nouvelle version immuable, verrou
     * optimiste (409 en cas de conflit).
     */
    public function enregistrer(Request $request, ModeleCourriel $modele): JsonResponse
    {
        $donnees = $request->validate([
            'schema' => ['required', 'array'],
            'base_version' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $version = $this->studio->enregistrer(
                $modele,
                $donnees['schema'],
                (int) $donnees['base_version'],
                $request->user()?->id,
                $donnees['note'] ?? null
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['schema' => [$e->getMessage()]],
            ], 422);
        }

        return response()->json(['ok' => true, 'numero' => $version->numero]);
    }

    /**
     * Aperçu sans effet (JSON) : validation + rendu fictif.
     */
    public function apercu(Request $request): JsonResponse
    {
        $donnees = $request->validate(['schema' => ['required', 'array']]);

        try {
            $normalise = app(ValidateurSchemaCourriel::class)->valider($donnees['schema']);
            $rendu = $this->moteur->rendre($normalise, $this->moteur->contexteFictif());
            $qualite = $this->qualite->analyser($normalise);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['schema' => [$e->getMessage()]],
            ], 422);
        }

        return response()->json(['ok' => true, 'qualite' => $qualite] + $rendu);
    }

    public function soumettre(ModeleCourriel $modele, Request $request): RedirectResponse
    {
        return $this->transition($modele, 'soumettre', $request, 'Modèle soumis à validation.');
    }

    public function publier(ModeleCourriel $modele, Request $request): RedirectResponse
    {
        return $this->transition($modele, 'publier', $request, 'Modèle publié.');
    }

    public function archiver(ModeleCourriel $modele, Request $request): RedirectResponse
    {
        return $this->transition($modele, 'archiver', $request, 'Modèle archivé.');
    }

    public function restaurer(Request $request, ModeleCourriel $modele): RedirectResponse
    {
        $donnees = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        try {
            $version = $this->studio->restaurer($modele, (int) $donnees['version'], $request->user()?->id);
        } catch (DomainException|InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.studio.voir', $modele)
            ->with('success', 'Version '.$version->numero.' publiée (restauration).');
    }

    public function dupliquer(ModeleCourriel $modele, Request $request): RedirectResponse
    {
        try {
            $copie = $this->studio->dupliquer($modele, $request->user()?->id);
        } catch (DomainException|InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.studio.editer', $copie)
            ->with('success', 'Copie créée en brouillon.');
    }

    public function supprimer(ModeleCourriel $modele): RedirectResponse
    {
        try {
            $this->studio->supprimer($modele);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.studio.index')
            ->with('success', 'Brouillon supprimé définitivement.');
    }

    /**
     * Envoi de test en deux temps (aperçu fictif puis confirmation
     * explicite), allowlist, débit limité, journal masqué.
     */
    public function envoiTest(Request $request, ModeleCourriel $modele): View|RedirectResponse
    {
        $donnees = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'destinataire' => ['required', 'string', 'max:254'],
            'confirmation' => ['sometimes', 'accepted'],
        ]);

        $cleDebit = 'studio-test:'.($request->ip() ?? '').':'.$request->user()?->id;
        if (RateLimiter::tooManyAttempts($cleDebit, (int) config('courriels.debit_manuel_par_minute', 5))) {
            return back()->with('error', 'Débit maximal atteint : patientez une minute.')->withInput();
        }

        if (! isset($donnees['confirmation'])) {
            try {
                $version = $modele->versions()->where('numero', (int) $donnees['version'])->firstOrFail();
                $rendu = $this->moteur->rendre(
                    app(ValidateurSchemaCourriel::class)->valider((array) $version->schema),
                    $this->moteur->contexteFictif()
                );
            } catch (DomainException|InvalidArgumentException $e) {
                return back()->with('error', $e->getMessage())->withInput();
            }

            return view('admin.studio.envoi-test', [
                'modele' => $modele,
                'version' => (int) $donnees['version'],
                'destinataire' => $donnees['destinataire'],
                'rendu' => $rendu,
            ]);
        }

        RateLimiter::hit($cleDebit);

        try {
            $courriel = $this->studio->envoyerTest(
                $modele,
                (int) $donnees['version'],
                $donnees['destinataire'],
                $request->user()?->id
            );
        } catch (DomainException|InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        if ($courriel->statut !== Courriel::STATUT_ACCEPTE_SMTP) {
            return redirect()->route('admin.courriels.voir', $courriel)
                ->with('error', 'Échec d’envoi de test ('.$courriel->categorie_erreur.').');
        }

        return redirect()->route('admin.courriels.voir', $courriel)
            ->with('success', 'Envoi de test transmis (modèle '.$modele->code.' v'.$donnees['version'].').');
    }

    public function themes(): View
    {
        return view('admin.studio.themes', [
            'themes' => ThemeCourriel::orderBy('nom')->get(),
        ]);
    }

    public function themeStore(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:100'],
            'config' => ['required', 'array'],
        ]);

        try {
            $this->studio->creerTheme($donnees['nom'], $donnees['config']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('admin.studio.themes')->with('success', 'Thème créé.');
    }

    public function themeUpdate(Request $request, ThemeCourriel $theme): RedirectResponse
    {
        $donnees = $request->validate(['config' => ['required', 'array']]);

        try {
            $this->studio->modifierTheme($theme, $donnees['config']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('admin.studio.themes')->with('success', 'Thème mis à jour.');
    }

    private function transition(ModeleCourriel $modele, string $action, Request $request, string $succes): RedirectResponse
    {
        try {
            $this->studio->{$action}($modele, $request->user()?->id);
        } catch (DomainException|InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.studio.voir', $modele)->with('success', $succes);
    }
}
