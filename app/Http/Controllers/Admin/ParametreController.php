<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Parametre;
use App\Models\ParametreVersion;
use App\Services\ParametreService;
use App\Services\SettingDefinition;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * Paramètres centralisés (§48) : lecture groupée par catégorie,
 * modification validée selon le type, chaque changement audité
 * (acteur, ancienne/nouvelle valeur — jamais de secret ici).
 */
class ParametreController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Parametre::class);

        $parametres = Parametre::with('auteur')->orderBy('categorie')->orderBy('cle')->get()->groupBy('categorie');

        // Historique récent groupé (1 requête, pas de N+1) pour la section
        // versions de la vue (LOOP G).
        $historiques = ParametreVersion::with('acteur')
            ->whereIn('parametre_id', $parametres->flatten()->pluck('id'))
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->groupBy('parametre_id');

        return view('admin.parametres.index', [
            'groupes' => $parametres,
            'definitions' => SettingDefinition::catalogue(),
            'historiques' => $historiques,
        ]);
    }

    public function update(Request $request, Parametre $parametre, ParametreService $service): RedirectResponse
    {
        Gate::authorize('update', $parametre);

        // Registre par clé (LOOP C) avec repli historique par type :
        // les règles métier priment, l'ancien comportement est conservé
        // pour toute clé hors registre (ex. sauvegarde.plan.*).
        $regles = SettingDefinition::reglesPour($parametre->cle) ?? match ($parametre->type) {
            'entier' => ['required', 'integer'],
            'booleen' => ['required', 'in:0,1,vrai,faux,true,false,oui,non'],
            default => ['nullable', 'string', 'max:2000'],
        };

        // Redirection explicite vers l'index (LOOP D) : `back()` sans
        // historique renvoyait à `/` et perdait les erreurs. Sac nommé
        // par paramètre : l'erreur ne s'affiche que sur son formulaire.
        $sac = 'p'.$parametre->id;
        $validateur = Validator::make(
            $request->only(['valeur', 'attendu', 'motif']),
            array_merge(
                ['valeur' => $regles],
                ['attendu' => ['nullable', 'string', 'max:64'], 'motif' => ['nullable', 'string', 'max:500']]
            )
        );

        if ($validateur->fails()) {
            return redirect()->route('admin.parametres.index')
                ->withErrors($validateur, $sac)
                ->withInput();
        }
        $donnees = $validateur->validated();

        try {
            $service->set(
                $parametre->cle,
                $donnees['valeur'],
                (int) $request->user()->id,
                $donnees['attendu'] ?? null,
                $donnees['motif'] ?? null
            );
        } catch (DomainException $e) {
            return redirect()->route('admin.parametres.index')
                ->withErrors(['concurrence' => $e->getMessage()], $sac)
                ->withInput();
        }

        return back()->with('success', "Paramètre « {$parametre->cle} » mis à jour.");
    }

    public function restaurer(Request $request, Parametre $parametre, ParametreService $service): RedirectResponse
    {
        Gate::authorize('update', $parametre);

        $versionId = (int) $request->validate(['version_id' => ['required', 'integer']])['version_id'];

        try {
            $service->restaurer($parametre, $versionId, (int) $request->user()->id);
        } catch (ModelNotFoundException $e) {
            return redirect()->route('admin.parametres.index')
                ->withErrors(['version' => 'Version introuvable pour ce paramètre.']);
        }

        return back()->with('success', "Paramètre « {$parametre->cle} » restauré (nouvelle version créée).");
    }
}
