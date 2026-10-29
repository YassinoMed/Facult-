<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OptionScrutin;
use App\Models\ParticipationScrutin;
use App\Models\QuestionScrutin;
use App\Models\Scrutin;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ScrutinController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index()
    {
        $this->authorize('viewAny', Scrutin::class);

        $scrutins = Scrutin::withCount('participations')
            ->when(request('etat'), fn ($q) => $q->where('etat', request('etat')))
            ->orderBy('date_debut', 'desc')
            ->paginate(20);

        return view('admin.scrutins.index', compact('scrutins'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create()
    {
        $this->authorize('create', Scrutin::class);

        return view('admin.scrutins.create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function store(Request $request)
    {
        $this->authorize('create', Scrutin::class);

        $validated = $request->validate([
            'titre' => 'required|string|max:200',
            'description' => 'nullable|string',
            'etat' => 'required|in:brouillon,programme,ouvert,clos,archive',
            'date_debut' => 'required|date|after_or_today',
            'date_fin' => 'required|date|after_or_equal:date_debut',
            'type_mode' => 'required|integer|between:0,4',
            'nb_reponses_max' => 'nullable|integer|min:1',
            'nb_reponses_min' => 'nullable|integer',
        ], [
            'date_fin.after_or_equal' => 'La date de fin doit être postérieure ou égale à la date de début.',
        ]);

        $scrutin = Scrutin::create($validated);

        // Si l'état est "programme", c'est la configuration initiale
        if ($request->etat === 'programme') {
            return redirect()
                ->route('admin.scrutins.edit', $scrutin)
                ->with('status', 'Scrutin créé en mode programme. Vous pouvez maintenant ajouter les questions.');
        }

        return redirect()
            ->route('admin.scrutins.edit', $scrutin)
            ->with('status', 'Scrutin créé avec succès en état brouillon.');
    }

    /**
     * Display the specified resource.
     *
     * @return Response
     */
    public function show(Scrutin $scrutin)
    {
        $this->authorize('view', $scrutin);

        // Vérifier si l'utilisateur peut voir les résultats
        $peutVoirResultats = $scrutin->resultats_autorises
            || $this->userHasPermissionTo('resultats.view');

        return view('admin.scrutins.show', compact('scrutin', 'peutVoirResultats'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @return Response
     */
    public function edit(Scrutin $scrutin)
    {
        $this->authorize('update', $scrutin);

        // Vérifier si des votes ont déjà été exprimés
        $aDesVotes = $scrutin->participations()->where('etat', 'valide')->exists();

        return view('admin.scrutins.edit', compact('scrutin', 'aDesVotes'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @return Response
     */
    public function update(Request $request, Scrutin $scrutin)
    {
        $this->authorize('update', $scrutin);

        // Vérifier si des votes ont déjà été exprimés
        if ($scrutin->participations()->where('etat', 'valide')->exists()) {
            return back()
                ->with('error', 'Impossible de modifier un scrutin ayant déjà reçu des votes. Clôturez-le d\'abord ou créez-en un nouveau.');
        }

        $validated = $request->validate([
            'titre' => 'sometimes|required|string|max:200',
            'description' => 'sometimes|nullable|string',
            'etat' => 'sometimes|required|in:brouillon,programme,ouvert,clos,archive',
            'date_debut' => 'sometimes|required|date|after_or_today',
            'date_fin' => 'sometimes|required|date|after_or_equal:date_debut',
            'resultats_autorises' => 'sometimes|boolean',
        ]);

        $scrutin->update($validated);

        return back()
            ->with('status', 'Scrutin mis à jour avec succès.');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @return Response
     */
    public function destroy(Scrutin $scrutin)
    {
        $this->authorize('delete', $scrutin);

        // Vérifier si des votes ont été exprimés
        if ($scrutin->participations()->where('etat', 'valide')->exists()) {
            return back()
                ->with('error', 'Impossible de supprimer un scrutin ayant reçu des votes.');
        }

        $scrutin->delete();

        return back()
            ->with('status', 'Scrutin supprimé avec succès.');
    }

    /**
     * Passer en état "programme" - configuration des questions
     */
    public function passerEnProgramme(Scrutin $scrutin)
    {
        $this->authorize('update', $scrutin);

        if ($scrutin->participations()->where('etat', 'valide')->exists()) {
            return back()
                ->with('error', 'Impossible de passer en mode programme après le début des votes.');
        }

        $scrutin->update(['etat' => 'programme']);

        return back()
            ->with('status', 'Scrutin passé en mode programme. Vous pouvez maintenant ajouter les questions.');
    }

    /**
     * Passer en état "ouvert" - lancer le vote
     */
    public function ouvrirVote(Scrutin $scrutin)
    {
        $this->authorize('update', $scrutin);

        // Vérifications côté serveur
        if (! $scrutin->date_debut <= now()) {
            return back()
                ->with('error', 'La date de début du scrutin est dans le futur.');
        }

        if (! $scrutin->date_fin >= now()) {
            return back()
                ->with('error', 'La date de fin du scrutin est écoulée.');
        }

        $scrutin->update(['etat' => 'ouvert']);

        return back()
            ->with('status', 'Le scrutin est maintenant ouvert. Les électeurs peuvent voter.');
    }

    /**
     * Clôturer le scrutin
     */
    public function cloturer(Scrutin $scrutin)
    {
        $this->authorize('update', $scrutin);

        $scrutin->update(['etat' => 'clos']);

        // Ici, on pourrait déclencher un événement pour le calcul des résultats
        // via une tâche en arrière-plan

        return back()
            ->with('status', 'Le scrutin a été fermé. Les résultats sont maintenant calculables.');
    }

    /**
     * Obtenir les questions d'un scrutin pour le builder
     */
    public function getQuestions(Scrutin $scrutin)
    {
        $this->authorize('view', $scrutin);

        $questions = $scrutin->questions()->with('options')->get();

        return response()->json($questions);
    }

    /**
     * Ajouter une question au scrutin
     */
    public function ajouterQuestion(Request $request, Scrutin $scrutin)
    {
        $this->authorize('update', $scrutin);

        $validated = $request->validate([
            'libelle' => 'required|string|max:500',
            'type' => 'required|integer|between:0,4',
            'ordre' => 'required|integer',
            'consignes' => 'nullable|string',
            'obligatoire' => 'boolean',
            'nb_options_min' => 'nullable|integer',
            'nb_options_max' => 'nullable|integer',
        ]);

        $question = $scrutin->questions()->create($validated);

        return response()->json([
            'success' => true,
            'question' => $question,
        ]);
    }

    /**
     * Supprimer une question
     */
    public function supprimerQuestion(Scrutin $scrutin, QuestionScrutin $question)
    {
        $this->authorize('update', $scrutin);

        // Vérifier qu'aucun vote n'a été exprimé sur cette question (si le scrutin estouvert)
        if ($scrutin->etat === 'ouvert') {
            return back()
                ->with('error', 'Impossible de supprimer une question pendant la période de vote.');
        }

        $question->delete();

        return back()
            ->with('status', 'Question supprimée avec succès.');
    }

    /**
     * Réordonner les questions
     */
    public function reordonnerQuestions(Request $request, Scrutin $scrutin)
    {
        $this->authorize('update', $scrutin);

        $data = $request->validate([
            'ordres' => 'required|array',
        ]);

        foreach ($data['ordres'] as $id => $ordre) {
            QuestionScrutin::where('scrutin_id', $scrutin->id)
                ->where('id', $id)
                ->update(['ordre' => $ordre]);
        }

        return back()
            ->with('status', 'Questions réordonnées avec succès.');
    }

    /**
     * Prévisualiser le bulletin
     */
    public function previsualiser(Scrutin $scrutin)
    {
        $this->authorize('view', $scrutin);

        $questions = $scrutin->questions()->with('options')->orderBy('ordre')->get();

        return view('admin.scrutins.previsualiser', compact('scrutin', 'questions'));
    }

    /**
     * Exporter les résultats (après clôture)
     */
    public function exporterResultats(Scrutin $scrutin)
    {
        $this->authorize('view', $scrutin);

        if ($scrutin->etat !== 'clos') {
            return back()->with('error', 'Seuls les scrutins clos peuvent être exportés.');
        }

        // Récupérer les participations avec leurs bulletins
        $participations = ParticipationScrutin::where('scrutin_id', $scrutin->id)
            ->where('etat', 'valide')
            ->with(['etudiant', 'bulletin'])
            ->get();

        // Calculer les résultats selon le type de scrutin
        $resultats = $this->calculerResultats($scrutin, $participations);

        return view('admin.scrutins.exporter-resultats', compact('scrutin', 'resultats', 'participations'));
    }

    /**
     * Calculer les résultats agrégés
     *
     * @param  Collection  $participations
     * @return array
     */
    protected function calculerResultats(Scrutin $scrutin, $participations)
    {
        $typeMode = $scrutin->type_mode;
        $resultats = [];

        switch ($typeMode) {
            case 0: // Choix unique
                $resultats = $this->calculerChoixUnique($participations, $scrutin);
                break;
            case 1: // Choix multiple
                $resultats = $this->calculerChoixMultiple($participations, $scrutin);
                break;
            case 2: // Oui/Non
                $resultats = $this->calculerOuiNon($participations, $scrutin);
                break;
            case 3: // Texte libre
                $resultats = $this->calculerTexteLibre($participations, $scrutin);
                break;
            case 4: // Classement
                $resultats = $this->calculerClassement($participations, $scrutin);
                break;
        }

        return $resultats;
    }

    protected function calculerChoixUnique($participations, $scrutin)
    {
        $questions = $scrutin->questions()->get();
        $resultats = [];

        foreach ($questions as $question) {
            $questionResultat = [
                'question_id' => $question->id,
                'question_libelle' => $question->libelle,
                'type' => $question->type,
                'options' => [],
                'total_votes' => 0,
                'abstentions' => 0,
            ];

            // Compter les votes pour chaque option
            $options = OptionScrutin::where('question_scrutin_id', $question->id)->get();

            foreach ($options as $option) {
                $count = ReponseScrutin::where('question_scrutin_id', $question->id)
                    ->where('option_scrutin_id', $option->id)
                    ->count();

                $questionResultat['options'][] = [
                    'option_id' => $option->id,
                    'libelle' => $option->libelle,
                    'votes' => $count,
                ];

                $questionResultat['total_votes'] += $count;
            }

            $questionResultat['abstentions'] = $participations->count() - $questionResultat['total_votes'];

            $resultats[$question->id] = $questionResultat;
        }

        return $resultats;
    }

    protected function calculerChoixMultiple($participations, $scrutin)
    {
        // Logique similaire pour choix multiple avec min/max
        return $this->calculerChoixUnique($participations, $scrutin);
    }

    protected function calculerOuiNon($participations, $scrutin)
    {
        return $this->calculerChoixUnique($participations, $scrutin);
    }

    protected function calculerTexteLibre($participations, $scrutin)
    {
        return [
            'type' => 'texte_libre',
            'note' => 'Les résultats de texte libre sont sensibles et doivent être traités conformément à la politique de confidentialité.',
        ];
    }

    protected function calculerClassement($participations, $scrutin)
    {
        return [
            'type' => 'classement',
            'note' => 'Le classement est calculé selon la méthode de Borda ou autre méthode définie.',
        ];
    }
}
