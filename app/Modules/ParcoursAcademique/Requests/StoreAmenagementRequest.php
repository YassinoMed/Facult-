<?php

namespace App\Modules\ParcoursAcademique\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Aménagements (équivalence, dispense, réorientation,
 * passerelle) : validation forte + normalisation FR.
 */
class StoreAmenagementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('parcours.gerer');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $categorie = (string) $this->route('categorie');

        return match ($categorie) {
            'equivalences' => [
                'etudiant_id' => ['required', 'integer', 'exists:etudiants,id'],
                'origine_libelle' => ['required', 'string', 'max:200'],
                'origine_credits' => ['nullable', 'integer', 'min:0', 'max:60'],
                'cible_matiere_id' => ['nullable', 'integer', 'exists:matieres,id'],
                'cible_ue_id' => ['nullable', 'integer', 'exists:unites_enseignement,id'],
                'cible_libelle' => ['required', 'string', 'max:200'],
                'etablissement' => ['nullable', 'string', 'max:200'],
                'justification' => ['nullable', 'string', 'max:2000'],
            ],
            'dispenses' => [
                'etudiant_id' => ['required', 'integer', 'exists:etudiants,id'],
                'matiere_id' => ['nullable', 'integer', 'exists:matieres,id'],
                'ue_id' => ['nullable', 'integer', 'exists:unites_enseignement,id'],
                'cible_libelle' => ['required', 'string', 'max:200'],
                'motif' => ['required', 'string', 'max:2000'],
                'responsable' => ['nullable', 'string', 'max:150'],
                'date_debut' => ['nullable', 'date'],
                'date_fin' => ['nullable', 'date', 'after_or_equal:date_debut'],
            ],
            'reorientations' => [
                'etudiant_id' => ['required', 'integer', 'exists:etudiants,id'],
                'nouveau_programme_id' => ['required', 'integer', 'exists:programmes,id'],
                'nouveau_niveau_id' => ['nullable', 'integer', 'exists:niveaux,id'],
                'annee_universitaire_id' => ['required', 'integer', 'exists:annees_universitaires,id'],
                'justification' => ['nullable', 'string', 'max:2000'],
            ],
            default => [
                'etudiant_id' => ['required', 'integer', 'exists:etudiants,id'],
                'programme_origine_id' => ['required', 'integer', 'exists:programmes,id'],
                'niveau_origine_id' => ['nullable', 'integer', 'exists:niveaux,id'],
                'programme_cible_id' => ['required', 'integer', 'exists:programmes,id'],
                'niveau_cible_id' => ['nullable', 'integer', 'exists:niveaux,id'],
                'justification' => ['nullable', 'string', 'max:2000'],
            ],
        };
    }
}
