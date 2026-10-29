<?php

namespace App\Modules\ParcoursAcademique\Controllers;

use App\Contracts\SimulationParcoursInterface;
use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\ParcoursAcademique\Models\ParcoursSimulation;
use App\Modules\ParcoursAcademique\Requests\MaSimulationParcoursRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Simulations SELF-SERVICE du portail étudiant (ADVANCED-01 LOT B).
 *
 * Contrôleur fin, moteur partagé avec le back-office
 * (SimulationParcoursInterface) : zéro duplication du calcul.
 *
 * Règles :
 * - l'étudiant est résolu depuis la session (`etudiant.auth`) —
 *   jamais depuis un paramètre client (anti-IDOR) ;
 * - sans inscription académique : état d'indisponibilité EXPLICITE,
 *   aucune substitution silencieuse de règle ni de formule ;
 * - persistance append-only avec `auteur_id` null (self-service) ;
 * - AUCUNE écriture dans notes, crédits, niveaux ou délibérations :
 *   le résultat est présenté comme non officiel.
 */
class MaSimulationParcoursController extends Controller
{
    private const SIMULATION_DISPONIBLE = 'disponible';

    private const SANS_INSCRIPTION = 'sans_inscription';

    private const RESULTATS_NON_PUBLIES = 'resultats_non_publies';

    private const MESSAGES_INDISPONIBILITE = [
        self::SANS_INSCRIPTION => 'Aucune inscription académique trouvée : la simulation est indisponible. Adressez-vous à la scolarité.',
        self::RESULTATS_NON_PUBLIES => 'Vos résultats ne sont pas encore publiés : la simulation sera disponible après la délibération du jury.',
    ];

    public function index(Request $request): View
    {
        $etudiant = $this->etudiantConnecte($request);

        // D-01 : seules les simulations SELF-SERVICE (auteur_id null) sont
        // exposées. Une simulation créée au back-office peut s'appuyer sur
        // des notes non publiées : elle reste un document de travail interne.
        $simulations = ParcoursSimulation::where('etudiant_id', $etudiant->id)
            ->whereNull('auteur_id')
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString();

        return view('modules.parcours-academique.ma-simulations-index', [
            'simulations' => $simulations,
        ]);
    }

    public function create(Request $request): View
    {
        $etudiant = $this->etudiantConnecte($request);

        return view('modules.parcours-academique.ma-simulation-create', [
            'scenarios' => ParcoursSimulation::LIBELLES,
            'etatSimulation' => $this->etatSimulation($etudiant),
        ]);
    }

    public function store(MaSimulationParcoursRequest $request, SimulationParcoursInterface $simulations): RedirectResponse
    {
        $etudiant = $this->etudiantConnecte($request);
        $donnees = $request->validated();

        // États d'indisponibilité explicites : le moteur exige une
        // inscription réelle ET des résultats publiés. On ne devine
        // jamais les données absentes, et on ne divulgue jamais un
        // résultat non publié (D-01).
        $etat = $this->etatSimulation($etudiant);
        if ($etat !== self::SIMULATION_DISPONIBLE) {
            return redirect()->route('etudiant.parcours.simulations.create')
                ->with('error', self::MESSAGES_INDISPONIBILITE[$etat]);
        }

        $simulation = $simulations->simuler(
            $etudiant,
            $donnees['scenario'],
            $donnees['hypotheses'] ?? [],
            null, // source : dernière inscription réelle, résolue serveur.
            null, // auteur self-service (portail étudiant, pas de User back-office).
        );

        return redirect()->route('etudiant.parcours.simulations.show', $simulation)
            ->with('success', 'Simulation enregistrée — résultat NON officiel, sans effet sur votre dossier.');
    }

    public function show(Request $request, ParcoursSimulation $simulation): View
    {
        // Anti-IDOR + D-01 : appartenance stricte ET simulation self-service.
        // Une simulation back-office (auteur renseigné) n'est jamais exposée
        // à l'étudiant, même si elle porte son identifiant.
        abort_unless(
            (int) $simulation->etudiant_id === (int) $this->etudiantConnecte($request)->id
                && $simulation->auteur_id === null,
            404,
        );

        return view('modules.parcours-academique.ma-simulation-show', [
            'simulation' => $simulation,
        ]);
    }

    private function etudiantConnecte(Request $request): Etudiant
    {
        /** @var Etudiant $etudiant */
        $etudiant = $request->attributes->get('etudiant');
        abort_if($etudiant === null, 404);

        return $etudiant;
    }

    /**
     * État de disponibilité de la simulation self-service.
     *
     * Le moteur partagé (SimulationParcoursService) agrège les notes
     * BRUTES de toutes les inscriptions pour les crédits, dettes et
     * simulations de semestre. Il est donc réservé au back-office tant
     * qu'une inscription portant des notes n'a pas de délibération
     * publiée : sinon l'étudiant verrait, via la simulation, des
     * résultats non publiés (D-01). Le comportement administrateur
     * reste inchangé (aucun paramètre supplémentaire au moteur).
     */
    private function etatSimulation(Etudiant $etudiant): string
    {
        $inscription = InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->orderByDesc('annee_universitaire_id')
            ->first();

        if ($inscription === null) {
            return self::SANS_INSCRIPTION;
        }

        $publiees = DecisionDeliberation::inscriptionsPublieesPour($etudiant->id);

        $notesNonPubliees = InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->whereNotIn('id', $publiees)
            ->whereHas('semestres.notes')
            ->exists();

        return $notesNonPubliees ? self::RESULTATS_NON_PUBLIES : self::SIMULATION_DISPONIBLE;
    }
}
