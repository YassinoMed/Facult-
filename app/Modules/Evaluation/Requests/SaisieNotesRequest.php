<?php

namespace App\Modules\Evaluation\Requests;

use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Rules\NoteDansBareme;
use Illuminate\Foundation\Http\FormRequest;

class SaisieNotesRequest extends FormRequest
{
    /**
     * Autorisation AVANT validation : permission + périmètre faculté de
     * l'épreuve (policy `saisirNote`). Une épreuve hors périmètre est
     * refusée avant tout traitement de la charge utile.
     */
    public function authorize(): bool
    {
        $epreuve = $this->epreuve();

        return $epreuve !== null && ($this->user()?->can('saisirNote', $epreuve) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lignes' => ['required', 'array', 'max:2000'],
            'lignes.*.compostage' => ['required_without:lignes.*.etudiant_id', 'nullable', 'string', 'max:50'],
            'lignes.*.numero' => ['nullable', 'string', 'max:50'],
            'lignes.*.etudiant_id' => ['required_without:lignes.*.compostage', 'nullable', 'exists:etudiants,id'],
            'lignes.*.valeur' => ['nullable', 'numeric', 'decimal:0,2', new NoteDansBareme($this->epreuve())],
            'lignes.*.commentaire' => ['nullable', 'string', 'max:500'],
            'lignes.*.motif' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'lignes.required' => 'Aucune note à enregistrer.',
            'lignes.max' => 'La saisie est limitée à 2000 lignes par envoi.',
            'lignes.*.valeur.decimal' => 'La note doit comporter au maximum deux décimales.',
            'lignes.*.valeur.numeric' => 'La note doit être un nombre.',
        ];
    }

    private function epreuve(): ?Epreuve
    {
        $epreuve = $this->route('epreuve');

        return $epreuve instanceof Epreuve ? $epreuve : null;
    }
}
