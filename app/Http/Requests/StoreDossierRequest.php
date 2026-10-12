<?php

namespace App\Http\Requests;

use App\Models\Etudiant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDossierRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! ($this->user()?->hasPermission('archives.create') ?? false)) {
            return false;
        }

        // Isolation faculté dès l'autorisation (avant validation) : un
        // périmètre restreint ne crée un dossier que pour ses étudiants.
        $etudiant = Etudiant::find($this->input('etudiant_id'));

        return $etudiant === null
            || $this->user()->peutAccederFaculte((int) $etudiant->faculte_id);
    }

    public function rules(): array
    {
        return [
            'etudiant_id' => [
                'required',
                'integer',
                Rule::exists('etudiants', 'id'),
                // Un étudiant ne peut avoir qu'un seul dossier académique
                Rule::unique('archives_etudiants', 'etudiant_id'),
            ],
            'matricule' => [
                'nullable',
                'string',
                'max:30',
                'regex:/^[A-Z0-9\-]+$/',
                Rule::unique('archives_etudiants', 'matricule'),
            ],
            'date_archivage' => 'nullable|date',
            'observation' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'etudiant_id.required' => 'Veuillez sélectionner un étudiant.',
            'etudiant_id.exists' => 'L\'étudiant sélectionné est introuvable.',
            'etudiant_id.unique' => 'Cet étudiant possède déjà un dossier académique.',
            'matricule.max' => 'Le matricule ne doit pas dépasser 30 caractères.',
            'matricule.regex' => 'Le matricule doit contenir uniquement des lettres majuscules, des chiffres et des tirets.',
            'matricule.unique' => 'Ce matricule est déjà attribué à un autre dossier.',
            'date_archivage.date' => 'Veuillez saisir une date d\'archivage valide.',
            'observation.max' => 'L\'observation ne doit pas dépasser 1000 caractères.',
        ];
    }

    public function etudiant(): Etudiant
    {
        return Etudiant::findOrFail($this->validated('etudiant_id'));
    }
}
