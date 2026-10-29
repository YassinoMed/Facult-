<?php

namespace App\Http\Livewire\Admin\Scrutins;

use App\Http\Livewire\LivewireBase;
use App\Models\Etudiant;
use App\Models\Scrutin;

class Builder extends LivewireBase
{
    public Scrutin $scrutin;

    public $etatActuel;

    public $questionLibelle = '';

    public $questionType = 0; // 0=choix_unique, 1=choix_multiple, 2=oui_non, 3=texte_libre, 4=classement

    public $questionConsignes = '';

    public $questionOblique = false;

    public $nbOptionsMin = 0;

    public $nbOptionsMax = 0;

    public $ordre = 1;

    public $editingQuestionId = null;

    public $showDeleteConfirmation = false;

    public $questions = [];

    public $typesOptions = [];

    public $nouvelleOptionLibelle = '';

    public $mode = 'composition'; // composition, preview

    public $previewWidth = 'w-full';

    public $isMobile = false;

    protected $listeners = [
        'question:ajouter',
        'question:supprimer',
        'question:reordonner',
        'question:selectionner',
        'mode:basculer',
    ];

    protected $rules = [
        'questionLibelle' => 'required|string|max:500',
        'questionType' => 'integer|between:0,4',
        'nbOptionsMin' => 'nullable|integer',
        'nbOptionsMax' => 'nullable|integer',
    ];

    public function mount(Scrutin $scrutin)
    {
        $this->scrutin = $scrutin;
        $this->etatActuel = $scrutin->etat;
        $this->questions = $scrutin->questions()->with('options')->orderBy('ordre')->get();
        $this->isMobile = str_contains(request()->header('User-Agent', ''), 'Mobile');

        // Mettre à jour l'ordre des questions
        $this->questions = $this->questions->mapWithKeys(fn ($q) => [$q->id => $q]);
    }

    public function updatedEtatActuel($value)
    {
        // Validation serveur du changement d'état
        if ($value === 'ouvert' && ! $this->scrutin->date_debut <= now()) {
            // Revenir à l'état précédent
            // ... gérer l'erreur
        }
    }

    public function ajouterQuestion()
    {
        $this->validate();

        $question = $this->scrutin->questions()->create([
            'libelle' => $this->questionLibelle,
            'type' => $this->questionType,
            'ordre' => max($this->questions->pluck('ordre')->toArray(), default: 0) + 1,
            'consignes' => $this->questionConsignes,
            'obligatoire' => $this->questionOblique,
            'nb_options_min' => $this->nbOptionsMin,
            'nb_options_max' => $this->nbOptionsMax,
        ]);

        $this->questions[$question->id] = $question;
        $this->resetFormulaire();
        $this->emit('question:ajoutree', $question->id);
    }

    public function resetFormulaire()
    {
        $this->questionLibelle = '';
        $this->questionConsignes = '';
        $this->questionOblique = false;
        $this->nbOptionsMin = 0;
        $this->nbOptionsMax = 0;
    }

    public function supprimerQuestion($questionId)
    {
        if ($this->scrutin->etat === 'ouvert') {
            $this->emit('erreur', 'Impossible de supprimer une question pendant la période de vote.');

            return;
        }

        $question = $this->questions[$questionId] ?? null;

        if ($question) {
            $question->delete();
            unset($this->questions[$questionId]);
            $this->emit('question:supprimee', $questionId);
        }
    }

    public function reordonnerQuestions($nouveauxOrdres)
    {
        // Cette méthode est appelée depuis JavaScript avec l'ordre nouveau
        // On met à jour les champsordre en base
        foreach ($nouveauxOrdres as $index => $questionId) {
            if (isset($this->questions[$questionId])) {
                $this->questions[$questionId]->update(['ordre' => $index + 1]);
            }
        }

        // Recharger les questions
        $this->questions = $this->scrutin->questions()->with('options')->orderBy('ordre')->get()
            ->mapWithKeys(fn ($q) => [$q->id => $q]);
        $this->emit('reordonnancement:termine');
    }

    public function basculerMode($nouveauMode)
    {
        $this->mode = $nouveauMode;

        if ($nouveauMode === 'preview') {
            $this->previewWidth = 'w-96';
        } else {
            $this->previewWidth = 'w-full';
        }
    }

    public function obtenirApercu()
    {
        return $this->mode === 'preview';
    }

    public function supprimerConfirmation($questionId)
    {
        $this->showDeleteConfirmation = true;
        $this->emit('question:supprimer-confirmer', $questionId);
    }

    public function verifierEligibiliteEtudiant($cin)
    {
        // Vérifier l'éligibilité côté serveur
        $etudiant = Etudiant::where('cin', $cin)->first();

        if (! $etudiant) {
            return ['eligible' => false, 'message' => 'Numéro CIN introuvable.'];
        }

        // Vérifier si l'étudiant est éligible au scrutin actuel
        // Cela dépendra des groupes faculte, programme, etc.
        // Pour l'instant, on retourne que l'étudiant existe
        return [
            'eligible' => true,
            'etudiant' => [
                'nom' => $etudiant->nomComplet,
                'faculte' => $etudiant->faculte->nom,
            ],
            'message' => "Étudiant trouvé : {$etudiant->nomComplet}",
        ];
    }

    public function voter($bulletinId)
    {
        // Rediriger vers la page de vote
        return redirect()
            ->route('admin.scrutins.vote', ['scrutin' => $this->scrutin->id, 'bulletin' => $bulletinId]);
    }
}
