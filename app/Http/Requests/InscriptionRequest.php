<?php

namespace App\Http\Requests;

use App\Models\Etudiant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class InscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            // La CIN n'est pas trimmée afin que les espaces résiduels soient invalidés (§3)
            'cin' => is_string($this->cin) ? $this->cin : '',
            'nom' => trim($this->nom ?? ''),
            'prenom' => trim($this->prenom ?? ''),
            'email' => strtolower(trim($this->email ?? '')),
            'telephone' => trim($this->telephone ?? ''),
            'adresse' => trim($this->adresse ?? ''),
            'ville' => trim($this->ville ?? ''),
            'code_postal' => trim($this->code_postal ?? ''),
            'annee_bac' => trim($this->annee_bac ?? ''),
        ]);
    }

    public function rules(): array
    {
        // Détection de rejeu idempotent (LOOP 09) : si un jeton d'idempotence est présent
        // avec le même email, on ignore l'étudiant existant pour permettre la redirection sécurisée.
        $cinNormalise = strtoupper(preg_replace('/\s+/', '', (string) $this->input('cin')) ?? '');
        $idempotencyToken = $this->string('idempotency_token')->toString();
        $emailNormalise = mb_strtolower(trim((string) $this->input('email')));

        $etudiantExistantId = null;
        if ($idempotencyToken !== '' && $cinNormalise !== '') {
            $existant = Etudiant::whereNotNull('cin')
                ->whereRaw("REPLACE(UPPER(TRIM(cin)), ' ', '') = ?", [$cinNormalise])
                ->first();

            if ($existant) {
                $tokenSessionKey = "idempotency_token_{$idempotencyToken}";
                $estMemeEmail = mb_strtolower(trim((string) $existant->email)) === $emailNormalise;

                $estMemeTokenSession = session($tokenSessionKey) === $existant->num_inscription;
                $estCreationRecenteMemeEmail = $existant->created_at
                    && $existant->created_at->diffInSeconds(now()) < 60
                    && $estMemeEmail;

                if ($estMemeEmail && ($estMemeTokenSession || $estCreationRecenteMemeEmail)) {
                    $etudiantExistantId = $existant->id;
                }
            }
        }

        $cinRule = Rule::unique('etudiants', 'cin');
        $emailRule = Rule::unique('etudiants', 'email');
        if ($etudiantExistantId !== null) {
            $cinRule = $cinRule->ignore($etudiantExistantId);
            $emailRule = $emailRule->ignore($etudiantExistantId);
        }

        return [
            'cin' => [
                'required',
                'string',
                'regex:/\A[0-9]{8}\z/',
                $cinRule,
            ],
            'nom' => 'required|string|min:2|max:100',
            'prenom' => 'required|string|min:2|max:100',
            'email' => [
                'required',
                'string',
                'email',
                'max:254',
                $emailRule,
            ],
            'telephone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9\s\-()]{7,30}$/'],
            'date_naissance' => ['nullable', 'date_format:Y-m-d', 'before:today', 'after:1920-01-01'],
            'sexe' => 'required|in:M,F',
            'faculte_id' => ['required', Rule::exists('facultes', 'id')->where('active', true)],
            'programme_id' => ['nullable', 'integer', Rule::exists('programmes', 'id')],
            'niveau' => 'required|in:L1,L2,L3,M1,M2,D1,D2,D3',
            'annee_academique' => 'required|string|max:20',
            'adresse' => 'required|string|min:5|max:500',
            'ville' => 'required|string|min:2|max:100',
            'code_postal' => 'required|string|max:20',
            'annee_bac' => ['required', 'string', 'regex:/^\d{4}$/'],
            'mention_bac' => 'nullable|in:Passable,Assez Bien,Bien,Très Bien,Excellent',
            'type_inscription' => 'nullable|string|in:premiere,reinscription,transfert',
            'derogation_administrative' => 'nullable|string|max:100',
            'confirmation' => ['sometimes', 'accepted'],
            'idempotency_token' => 'nullable|string|max:64',
            'password' => ['required', 'string', 'max:64', 'confirmed', Password::defaults()],
        ];
    }

    public function messages(): array
    {
        return [
            'confirmation.accepted' => 'Vous devez certifier sur l’honneur l’exactitude des renseignements fournis pour soumettre votre dossier.',
            'cin.required' => 'Le numéro de CIN est obligatoire.',
            'cin.regex' => 'Le numéro de CIN doit être composé d\'exactement 8 chiffres (chiffres 0 à 9 uniquement, sans lettre, espace ou tiret).',
            'cin.unique' => 'Ce numéro de CIN est déjà enregistré pour un autre étudiant.',
            'nom.required' => 'Le nom est obligatoire.',
            'nom.min' => 'Le nom doit contenir au moins 2 caractères.',
            'nom.max' => 'Le nom ne doit pas dépasser 100 caractères.',
            'prenom.required' => 'Le prénom est obligatoire.',
            'prenom.min' => 'Le prénom doit contenir au moins 2 caractères.',
            'prenom.max' => 'Le prénom ne doit pas dépasser 100 caractères.',
            'email.required' => 'L\'adresse email est obligatoire.',
            'email.email' => 'Veuillez saisir une adresse email valide.',
            'email.max' => 'L\'adresse email ne doit pas dépasser 254 caractères.',
            'email.unique' => 'Cette adresse email est déjà utilisée pour une inscription.',
            'telephone.max' => 'Le numéro de téléphone ne doit pas dépasser 30 caractères.',
            'telephone.regex' => 'Le numéro de téléphone saisi n\'est pas valide.',
            'date_naissance.date_format' => 'La date de naissance doit être au format AAAA-MM-JJ (ex : 2004-03-15).',
            'date_naissance.before' => 'La date de naissance doit être antérieure à aujourd\'hui.',
            'date_naissance.after' => 'La date de naissance doit être postérieure au 01/01/1920.',
            'sexe.required' => 'Le sexe est obligatoire.',
            'sexe.in' => 'Veuillez sélectionner Masculin ou Féminin.',
            'faculte_id.required' => 'La faculté est obligatoire.',
            'faculte_id.exists' => 'La faculté sélectionnée est introuvable ou inactive.',
            'programme_id.exists' => 'Le parcours sélectionné est introuvable.',
            'niveau.required' => 'Le niveau d\'études est obligatoire.',
            'niveau.in' => 'Le niveau sélectionné n\'est pas valide.',
            'annee_academique.required' => 'L\'année académique est obligatoire.',
            'adresse.required' => 'L\'adresse est obligatoire.',
            'adresse.min' => 'L\'adresse doit contenir au moins 5 caractères.',
            'adresse.max' => 'L\'adresse ne doit pas dépasser 500 caractères.',
            'ville.required' => 'La ville est obligatoire.',
            'ville.min' => 'La ville doit contenir au moins 2 caractères.',
            'ville.max' => 'La ville ne doit pas dépasser 100 caractères.',
            'code_postal.required' => 'Le code postal est obligatoire.',
            'code_postal.max' => 'Le code postal ne doit pas dépasser 20 caractères.',
            'annee_bac.required' => 'L\'année d\'obtention du baccalauréat est obligatoire.',
            'annee_bac.regex' => 'L\'année du baccalauréat doit être une année sur 4 chiffres (ex : 2024).',
            'mention_bac.in' => 'La mention sélectionnée n\'est pas valide.',
            'type_inscription.in' => 'La catégorie d\'inscription sélectionnée n\'est pas valide.',
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.min' => 'Le mot de passe doit contenir au moins 10 caractères.',
            'password.max' => 'Le mot de passe ne doit pas dépasser 64 caractères.',
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
            'password.letters' => 'Le mot de passe doit contenir au moins une lettre.',
            'password.numbers' => 'Le mot de passe doit contenir au moins un chiffre.',
            'password.uncompromised' => 'Ce mot de passe a été compromis dans une fuite de données connue. Veuillez en choisir un autre.',
        ];
    }
}
