<?php

namespace App\Modules\Evaluation\Requests;

use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Rules\NoteDansBareme;
use Illuminate\Foundation\Http\FormRequest;

class SaisieNotesAnonymesRequest extends FormRequest
{
    /**
     * Autorisation AVANT validation : permission + périmètre faculté de
     * l'épreuve (policy `saisirNote`). Le flux anonyme ne doit jamais
     * pouvoir sonder une épreuve d'une autre faculté via les messages
     * de validation.
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
            'fichier' => ['nullable', 'file', 'max:2048', 'mimetypes:text/plain,text/csv,application/csv,text/x-csv'],
            'lignes' => ['nullable', 'array', 'max:2000'],
            'lignes.*.numero' => ['required_with:lignes', 'string', 'regex:/^CMP-\d{6}$/i', 'max:11'],
            'lignes.*.valeur' => ['nullable', 'numeric', 'decimal:0,2', new NoteDansBareme($this->epreuve())],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'fichier.mimetypes' => 'Le fichier doit être un fichier texte CSV valide (.csv).',
            'fichier.max' => 'Le fichier ne doit pas dépasser 2 Mo.',
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
