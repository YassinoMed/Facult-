<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInscriptionAcademiqueRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! ($this->user()?->hasPermission('archives.update') ?? false)) {
            return false;
        }

        // Isolation faculté dès l'autorisation (avant validation) — même
        // convention que StoreDossierRequest/StoreNoteRequest : une requête
        // visant une inscription hors périmètre est refusée (403), jamais
        // traitée jusqu'à la validation.
        $inscription = $this->route('inscription');

        return $inscription === null
            || $this->user()->peutAccederFaculte((int) $inscription->etudiant->faculte_id);
    }

    /**
     * La moyenne annuelle, les crédits et la décision ne sont JAMAIS
     * modifiables directement : ils sont recalculés par le service.
     */
    public function rules(): array
    {
        return [
            'niveau' => 'required|in:L1,L2,L3,M1,M2,D1,D2,D3',
            'filiere' => 'nullable|string|max:150',
            'groupe' => 'nullable|string|max:20',
            'statut' => 'required|in:en_cours,valide,ajourne,abandon',
            'observation' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'niveau.required' => 'Le niveau est obligatoire.',
            'niveau.in' => 'Le niveau sélectionné n\'est pas valide.',
            'statut.required' => 'Le statut est obligatoire.',
            'statut.in' => 'Le statut sélectionné n\'est pas valide.',
            'filiere.max' => 'La filière ne doit pas dépasser 150 caractères.',
            'groupe.max' => 'Le groupe ne doit pas dépasser 20 caractères.',
            'observation.max' => 'L\'observation ne doit pas dépasser 1000 caractères.',
        ];
    }
}
