<?php

namespace App\Modules\SimulateurScenarios\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Modules\SimulateurScenarios\Services\ServiceScenarios;
use App\Modules\SimulateurScenarios\Support\CriteresScenario;
use App\Modules\SimulateurScenarios\Support\FacultePerimetre;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Lot 14 — Points d'entrée du simulateur de scénarios.
 *
 * Contrôles toxicaires appliqués ICI (jamais au navigateur) :
 * - permission `statistiques.view` (registre existante, aucune permission
 *   nouvellement inventée) ;
 * - périmètre facultaire forcé côté serveur (pattern perimetreEffectif) ;
 * - aucune écriture : le service est strictement en lecture.
 */
final class ScenariosController extends Controller
{
    public function __construct(private readonly ServiceScenarios $service) {}

    /**
     * Permission `statistiques.view` (registre existante) — le contrôleur
     * de base de ce projet n'embarque pas AuthorizesRequests : le pattern
     * du dépôt est `$user->can()` + abort explicite (DataMeshController).
     */
    private function verifierPermission(Request $requete): void
    {
        abort_unless($requete->user()?->can('statistiques.view') === true, 403, 'Permission « statistiques.view » requise.');
    }

    /**
     * Écran minimal du simulateur (LOOP-14, réserve R-14/6).
     *
     * Lecture seule : le formulaire GET ne modifie rien. Le POST reste
     * disponible pour les clients API ; cet écran rend le moteur utilisable
     * par un administrateur sans client HTTP.
     */
    public function index(Request $requete): View
    {
        $this->verifierPermission($requete);

        $faculteRestreinte = $requete->user()->faculteRestreinteId();

        $facultes = Faculte::query()
            ->when($faculteRestreinte !== null, fn ($q) => $q->where('id', $faculteRestreinte))
            ->orderBy('nom')
            ->get(['id', 'nom', 'code']);

        $annees = AnneeUniversitaire::query()
            ->orderByDesc('libelle')
            ->get(['id', 'libelle']);

        $resultat = null;
        $erreur = null;

        if ($requete->boolean('executer')) {
            $donnees = $requete->validate([
                'faculte_id' => ['nullable', 'integer', 'exists:facultes,id'],
                'annee_id' => ['nullable', 'integer', 'exists:annees_universitaires,id'],
                'delta_effectifs' => ['nullable', 'numeric'],
                'delta_salles' => ['nullable', 'integer'],
                'delta_charges' => ['nullable', 'numeric'],
            ]);

            try {
                $perimetre = FacultePerimetre::resoudre(
                    $requete->user(),
                    isset($donnees['faculte_id']) ? (int) $donnees['faculte_id'] : null,
                );
                $criteres = CriteresScenario::depuisTableau(
                    array_replace($donnees, ['faculte_id' => $perimetre]),
                );
                $resultat = $this->service->calculer($criteres);
            } catch (AuthorizationException) {
                abort(403, 'Simulateur de scénarios : faculté hors périmètre.');
            } catch (InvalidArgumentException $exception) {
                $erreur = $exception->getMessage();
            }
        }

        return view('modules.simulateur-scenarios.index', [
            'facultes' => $facultes,
            'annees' => $annees,
            'resultat' => $resultat,
            'erreur' => $erreur,
            'faculteRestreinte' => $faculteRestreinte,
        ]);
    }

    /**
     * Exécute un scénario unique.
     */
    public function executer(Request $requete): JsonResponse
    {
        $this->verifierPermission($requete);

        $donnees = $requete->validate([
            'faculte_id' => ['nullable', 'integer', 'exists:facultes,id'],
            'annee_id' => ['nullable', 'integer', 'exists:annees_universitaires,id'],
            'delta_effectifs' => ['nullable', 'numeric'],
            'delta_salles' => ['nullable', 'integer'],
            'delta_charges' => ['nullable', 'numeric'],
        ]);

        try {
            $perimetre = FacultePerimetre::resoudre($requete->user(), isset($donnees['faculte_id']) ? (int) $donnees['faculte_id'] : null);
            // `array_replace` et non `+` : une clé `faculte_id` envoyée
            // explicitement à null existe dans $donnees, donc `+` la
            // conserverait — le DTO verrait alors un périmètre transversal.
            $criteres = CriteresScenario::depuisTableau(array_replace($donnees, ['faculte_id' => $perimetre]));
            $resultat = $this->service->calculer($criteres);
        } catch (AuthorizationException) {
            abort(403, 'Simulateur de scénarios : faculté hors périmètre.');
        } catch (InvalidArgumentException $erreur) {
            return response()->json(['erreur' => $erreur->getMessage()], 422);
        }

        return response()->json($resultat);
    }

    /**
     * Compare deux scénarios sur le MÊME périmètre.
     */
    public function comparer(Request $requete): JsonResponse
    {
        $this->verifierPermission($requete);

        $donnees = $requete->validate([
            'faculte_id' => ['nullable', 'integer', 'exists:facultes,id'],
            'annee_id' => ['nullable', 'integer', 'exists:annees_universitaires,id'],
            'scenario_a' => ['required', 'array'],
            'scenario_b' => ['required', 'array'],
        ]);

        try {
            $perimetre = FacultePerimetre::resoudre($requete->user(), isset($donnees['faculte_id']) ? (int) $donnees['faculte_id'] : null);
            // Le périmètre est une décision SERVEUR : les sous-tableaux ne
            // peuvent pas le réécrire. Sans ce forçage, un admin_faculte
            // pourrait glisser `faculte_id` d'une autre faculté dans les
            // deux scénarios et contourner FacultePerimetre (IDOR).
            $perimetreCommun = [
                'faculte_id' => $perimetre,
                'annee_id' => isset($donnees['annee_id']) ? (int) $donnees['annee_id'] : null,
            ];
            $criteresA = CriteresScenario::depuisTableau(array_replace($donnees['scenario_a'], $perimetreCommun));
            $criteresB = CriteresScenario::depuisTableau(array_replace($donnees['scenario_b'], $perimetreCommun));
            [$resultatA, $resultatB] = $this->service->comparer($criteresA, $criteresB);
            $ecarts = $this->service->ecarts($resultatA, $resultatB);
        } catch (AuthorizationException) {
            abort(403, 'Simulateur de scénarios : faculté hors périmètre.');
        } catch (InvalidArgumentException $erreur) {
            return response()->json(['erreur' => $erreur->getMessage()], 422);
        }

        return response()->json([
            'version_regles' => ServiceScenarios::VERSION_REGLES,
            'perimetre' => $resultatA['perimetre'],
            'unites' => 'identiques-par-construction',
            'ecarts' => $ecarts,
            'scenarios' => ['a' => $resultatA, 'b' => $resultatB],
            'avertissement' => 'Appui à la décision uniquement : jamais une décision institutionnelle officielle.',
        ]);
    }
}
