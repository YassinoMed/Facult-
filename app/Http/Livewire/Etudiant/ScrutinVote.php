<?php

namespace App\Http\Livewire\Etudiant\ScrutinVote;

use App\Http\Livewire\LivewireBase;
use App\Models\Etudiant;
use App\Models\ParticipationScrutin;
use App\Models\Scrutin;

class ScrutinVote extends LivewireBase
{
    public Scrutin $scrutin;

    public $reponses = [];

    public $estValide = false;

    public $confirmationEnvoyee = false;

    public $reponsesExistees = [];

    public $alerteDoubleVote = false;

    public $estOuvert = false;

    public $peutVoirResultats = false;

    public $reponseEnCours = [];

    protected $listeners = [
        'vote:soumettre',
        'vote:confirmer',
    ];

    public function mount($scrutinId)
    {
        $this->scrutin = Scrutin::with('questions.options')->findOrFail($scrutinId);

        // Vérifier si le scrutin est ouvert
        $this->estOuvert = $this->scrutin->estEligibleAuVote();

        // Vérifier si l'utilisateur peut voir les résultats (uniquement après clôture)
        if ($this->scrutin->etat === 'clos') {
            $this->peutVoirResultats = $this->scrutin->resultats_autorises;
        }

        // Vérifier si l'étudiant a déjà voté
        $this->verifierParticipation();
    }

    public function verifierParticipation()
    {
        // Récupérer l'étudiant connecté
        $etudiant = auth()->user(); // ou auth()->guard('etudiant')->user()

        if (! $etudiant instanceof Etudiant) {
            $this->alerteDoubleVote = true;
            $this->estValide = false;

            return;
        }

        // Vérifier s'il a déjà participé
        $participation = ParticipationScrutin::where('etudiant_id', $etudiant->id)
            ->where('scrutin_id', $this->scrutin->id)
            ->first();

        if ($participation && $participation->estValide()) {
            // L'étudiant a déjà voté
            $this->alerteDoubleVote = true;
            $this->estValide = false;

            // Récupérer ses réponses précédentes si le scrutin est clos
            if ($this->scrutin->etat === 'clos') {
                $this->chargerReponsesPrecedentes($participation);
            }

            return;
        }

        $this->alerteDoubleVote = false;
        $this->estValide = true;

        // Charger les réponses existantes si on peut les modifier (avant clôture)
        if ($this->scrutin->etat !== 'clos') {
            $this->chargerReponsesEnCours();
        }
    }

    public function chargerReponsesEnCours()
    {
        $participation = ParticipationScrutin::where('etudiant_id', auth()->id())
            ->where('scrutin_id', $this->scrutin->id)
            ->first();

        if ($participation && $participation->bulletin) {
            $this->reponsesExistees = $participation->bulletin->contenus_reponses ?? [];
        }
    }

    public function chargerReponsesPrecedentes(ParticipationScrutin $participation)
    {
        if ($participation->bulletin) {
            $this->reponses = $participation->bulletin->contenus_reponses ?? [];
        }
    }

    public function miseAJourReponse($questionId, $valeur)
    {
        $this->reponses[$questionId] = $valeur;
    }

    public function sauvegarderBrouillon()
    {
        // Sauvegarder les réponses en tant que participation non valide
        // (pour permettre de revenir plus tard)
        $etudiant = auth()->user();

        if (! $etudiant instanceof Etudiant) {
            return;
        }

        $participation = ParticipationScrutin::firstOrCreate([
            'etudiant_id' => $etudiant->id,
            'scrutin_id' => $this->scrutin->id,
            'jeton_unicite' => Str::random(64),
            'etat' => 'valide',
        ]);

        // Mettre à jour le bulletin
        $bulletin = $participation->bulletin()->firstOrCreate([]);

        $bulletin->contenus_reponses = $this->reponses;
        $bulletin->save();

        $this->emit('sauvegarde:reussie', 'Votre vote a été sauvegardé en brouillon.');
    }

    public function soumettreVote()
    {
        $etudiant = auth()->user();

        if (! $etudiant instanceof Etudiant) {
            $this->emit('erreur:auth', 'Vous devez être connecté en tant qu\'étudiant.');

            return;
        }

        // Vérifier que le scrutin est ouvert
        if (! $this->estOuvert) {
            $this->emit('erreur:delai', 'La période de vote n\'est pas ouverte.');

            return;
        }

        // Valider les réponses
        $validation = $this->validerReponses();

        if (! $validation['valide']) {
            $this->emit('erreur:validation', $validation['message']);

            return;
        }

        // Créer ou récupérer la participation
        $participation = ParticipationScrutin::firstOrCreate([
            'etudiant_id' => $etudiant->id,
            'scrutin_id' => $this->scrutin->id,
            'jeton_unicite' => Str::random(64),
            'etat' => 'valide',
        ]);

        // Créer/mettre à jour le bulletin
        $bulletin = $participation->bulletin()->firstOrCreate([]);
        $bulletin->contenus_reponses = $this->reponses;
        $bulletin->save();

        // Mettre à jour l'état de la participation
        $participation->update(['etat' => 'valide']);

        // Marquer comme validé
        $this->estValide = true;
        $this->confirmationEnvoyee = true;

        // Émettre l'événement de confirmation
        $this->emit('vote:confirme', [
            'scrutin' => $this->scrutin->titre,
            'message' => 'Votre vote a été enregistré avec succès. Votre choix reste anonyme.',
        ]);

        // Après un court délai, masquer la confirmation
        // (on pourrait utiliser setTimeout côté JS)
    }

    protected function validerReponses()
    {
        $messages = [];
        $valide = true;

        foreach ($this->scrutin->questions as $question) {
            $reponse = $this->reponses[$question->id] ?? null;

            // Vérifier la présence obligatoire
            if ($question->obligatoire && (! $reponse || (is_string($reponse) && trim($reponse) === ''))) {
                $messages[] = "La question « {$question->libelle} » est obligatoire.";
                $valide = false;

                continue;
            }

            // Valider selon le type
            switch ($question->type) {
                case 0: // Choix unique
                    if ($reponse && ! is_numeric($reponse)) {
                        $messages[] = "Veuillez sélectionner une option valide pour la question « {$question->libelle} ».";
                        $valide = false;
                    }
                    break;

                case 1: // Choix multiple
                    if ($reponse) {
                        $optionsCount = count($reponse);
                        if ($optionsCount < $question->nb_options_min ?? 1) {
                            $messages[] = "Vous devez sélectionner au moins {$question->nb_options_min} option(s).";
                            $valide = false;
                        }
                        if ($optionsCount > $question->nb_options_max ?? 999) {
                            $messages[] = "Vous ne pouvez pas sélectionner plus de {$question->nb_options_max} option(s).";
                            $valide = false;
                        }
                    }
                    break;

                case 2: // Oui/Non
                    if ($reponse !== false && $reponse !== true) {
                        $messages[] = "Veuillez répondre Oui ou Non à la question « {$question->libelle} ».";
                        $valide = false;
                    }
                    break;

                case 3: // Texte libre
                    if ($reponse && strlen($reponse) > 1000) {
                        $messages[] = 'La réponse texte est trop longue (maximum 1000 caractères).';
                        $valide = false;
                    }
                    break;

                case 4: // Classement
                    if ($reponse && is_array($reponse)) {
                        // Vérifier qu'il n'y a pas de doublons
                        $classes = array_count_values($reponse);
                        $doublons = array_filter($classes, fn ($c) => $c > 1);
                        if (! empty($doublons)) {
                            $messages[] = "Chaque option ne peut être classée qu'une seule fois.";
                            $valide = false;
                        }
                    }
                    break;
            }
        }

        return [
            'valide' => $valide,
            'message' => $messages ? implode(' ', $messages) : '',
        ];
    }
}
