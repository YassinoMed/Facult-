<?php

namespace App\Http\Requests;

use App\Models\InscriptionAcademique;
use App\Models\Semestre;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Saisie d'une note.
 *
 * Sécurité : l'étudiant, l'inscription et le coefficient/crédits sont
 * dérivés côté serveur ; le client ne fournit que la matière, la note,
 * la session et l'observation. Cohérence matière/semestre/niveau vérifiée
 * par le service (défense en profondeur).
 */
class StoreNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! ($this->user()?->hasPermission('notes.create') ?? false)) {
            return false;
        }

        $inscription = $this->route('inscription');

        return $inscription === null
            || $this->user()->peutAccederFaculte((int) $inscription->etudiant->faculte_id);
    }

    public function rules(): array
    {
        /** @var InscriptionAcademique $inscription */
        $inscription = $this->route('inscription');

        // Résolution du semestre ciblé (S1/S2 de cette inscription) pour la
        // règle d'unicité (semestre_id, matiere_id, session) — contrainte réelle en base.
        $semestre = Semestre::where('inscription_academique_id', $inscription->id)
            ->where('numero', (int) $this->input('semestre_numero'))
            ->first();

        $uniciteMatiere = Rule::unique('notes', 'matiere_id')
            ->where('session', $this->input('session', 'normale'));

        if ($semestre) {
            $uniciteMatiere = $uniciteMatiere->where('semestre_id', $semestre->id);
        }

        return [
            'semestre_numero' => [
                'required',
                'integer',
                'in:1,2',
                // le semestre doit appartenir à l'inscription ciblée
                Rule::exists('semestres', 'numero')
                    ->where('inscription_academique_id', $inscription->id),
            ],
            'matiere_id' => [
                'required',
                'integer',
                Rule::exists('matieres', 'id'),
                // une seule note par (semestre, matière, session)
                $uniciteMatiere,
            ],
            'note' => 'required|numeric|min:0|max:20',
            'session' => 'nullable|in:normale,rattrapage',
            'observation' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'semestre_numero.required' => 'Veuillez sélectionner le semestre.',
            'semestre_numero.in' => 'Le semestre doit être S1 ou S2.',
            'matiere_id.required' => 'Veuillez sélectionner la matière.',
            'matiere_id.exists' => 'La matière sélectionnée est introuvable.',
            'matiere_id.unique' => 'Une note existe déjà pour cette matière, ce semestre et cette session.',
            'note.required' => 'La note est obligatoire.',
            'note.numeric' => 'La note doit être une valeur numérique.',
            'note.min' => 'La note ne peut pas être inférieure à 0.',
            'note.max' => 'La note ne peut pas être supérieure à 20.',
            'session.in' => 'La session doit être « normale » ou « rattrapage ».',
            'observation.max' => 'L\'observation ne doit pas dépasser 500 caractères.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['session' => $this->input('session') ?: 'normale']);
    }
}
