<?php

namespace App\Http\Requests;

use App\Models\DossierAcademique;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création d'une inscription académique.
 * Le champ etudiant_id est TOUJOURS dérivé du dossier ciblé par la route,
 * jamais des données soumises par le client (anti-mass-assignment / IDOR).
 */
class StoreInscriptionAcademiqueRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! ($this->user()?->hasPermission('archives.create') ?? false)) {
            return false;
        }

        $dossier = $this->route('dossier');

        return $dossier === null
            || $this->user()->peutAccederFaculte((int) $dossier->etudiant->faculte_id);
    }

    public function rules(): array
    {
        /** @var DossierAcademique $dossier */
        $dossier = $this->route('dossier');

        return [
            'annee_universitaire_id' => [
                'required',
                'integer',
                Rule::exists('annees_universitaires', 'id'),
                // Unicité : pas deux inscriptions pour le même étudiant et la même année
                Rule::unique('inscriptions_academiques', 'annee_universitaire_id')
                    ->where('etudiant_id', $dossier->etudiant_id),
            ],
            'niveau' => 'required|in:L1,L2,L3,M1,M2,D1,D2,D3',
            'filiere' => 'nullable|string|max:150',
            'groupe' => 'nullable|string|max:20',
            'statut' => 'nullable|in:en_cours,valide,ajourne,abandon',
            'programme_id' => 'nullable|integer|exists:programmes,id',
            'niveau_id' => 'nullable|integer|exists:niveaux,id',
            'type' => 'nullable|in:premiere,reinscription,transfert,mutation,reprise,equivalence,autre',
            'regime' => 'nullable|in:initiale,continue,alternance',
            'observation' => 'nullable|string|max:1000',
        ];
    }

    /**
     * Gardes métier post-validation : programme/niveau actifs et cohérents
     * (le niveau doit appartenir au programme).
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $donnees = $validator->getData();

            if (! empty($donnees['programme_id'])) {
                $programme = Programme::find($donnees['programme_id']);

                if ($programme === null || ! $programme->actif) {
                    $validator->errors()->add('programme_id', 'Le programme est introuvable ou inactif.');
                }
            }

            if (! empty($donnees['niveau_id'])) {
                $niveau = Niveau::find($donnees['niveau_id']);

                if ($niveau === null || ! $niveau->actif) {
                    $validator->errors()->add('niveau_id', 'Le niveau est introuvable ou inactif.');
                } elseif (! empty($donnees['programme_id'])
                    && (int) $niveau->programme_id !== (int) $donnees['programme_id']) {
                    $validator->errors()->add('niveau_id', 'Le niveau doit appartenir au programme.');
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'annee_universitaire_id.required' => 'Veuillez sélectionner l\'année universitaire.',
            'annee_universitaire_id.exists' => 'L\'année universitaire sélectionnée est introuvable.',
            'annee_universitaire_id.unique' => 'Cet étudiant possède déjà une inscription pour cette année universitaire.',
            'niveau.required' => 'Le niveau est obligatoire.',
            'niveau.in' => 'Le niveau sélectionné n\'est pas valide.',
            'filiere.max' => 'La filière ne doit pas dépasser 150 caractères.',
            'groupe.max' => 'Le groupe ne doit pas dépasser 20 caractères.',
            'statut.in' => 'Le statut sélectionné n\'est pas valide.',
            'observation.max' => 'L\'observation ne doit pas dépasser 1000 caractères.',
        ];
    }
}
