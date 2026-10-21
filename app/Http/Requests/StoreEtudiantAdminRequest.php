<?php

namespace App\Http\Requests;

use App\Models\Etudiant;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création administrative d'un étudiant complet : identité, rattachement
 * LMD (faculté → parcours → niveau → groupe) et statut de paiement
 * oui/non (paiement effectué sur un site externe, sans facture locale).
 *
 * La validation de l'inscription est un acte administratif explicite :
 * le motif est obligatoire et sera journalisé (aucune validation
 * silencieuse, même si elle contourne le contrôle documentaire).
 */
class StoreEtudiantAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('archives.create') === true;
    }

    protected function prepareForValidation(): void
    {
        $texte = function (string $cle): string {
            $valeur = $this->input($cle);

            return is_string($valeur) ? trim($valeur) : '';
        };

        $texteOuNull = function (string $cle) use ($texte): ?string {
            $valeur = $texte($cle);

            return $valeur === '' ? null : $valeur;
        };

        $this->merge([
            'cin' => mb_strtoupper(preg_replace('/\s+/', '', $texte('cin')) ?? ''),
            'nom' => $texte('nom'),
            'prenom' => $texte('prenom'),
            'email' => mb_strtolower($texte('email')),
            'telephone' => $texteOuNull('telephone'),
            'identifiant_national' => $texteOuNull('identifiant_national'),
            'adresse' => $texte('adresse'),
            'ville' => $texte('ville'),
            'code_postal' => $texte('code_postal'),
            'annee_bac' => $texte('annee_bac'),
            'motif_validation' => $texte('motif_validation'),
            'type_inscription' => $this->input('type_inscription') ?: 'premiere',
            'regime' => $this->input('regime') ?: 'initiale',
            'paye' => $this->input('paye', '0'),
        ]);
    }

    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'min:2', 'max:100'],
            'prenom' => ['required', 'string', 'min:2', 'max:100'],
            'cin' => [
                'required',
                'string',
                'regex:/^[A-Z0-9-]{5,30}$/',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $existe = Etudiant::whereRaw('UPPER(TRIM(cin)) = ?', [mb_strtoupper(trim((string) $value))])->exists();

                    if ($existe) {
                        $fail('Ce numéro de CIN est déjà enregistré pour un autre étudiant.');
                    }
                },
            ],
            'identifiant_national' => ['nullable', 'string', 'max:30'],
            'email' => [
                'required',
                'string',
                'email',
                'max:254',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $existe = Etudiant::whereRaw('LOWER(TRIM(email)) = ?', [mb_strtolower(trim((string) $value))])->exists();

                    if ($existe) {
                        $fail('Cette adresse email est déjà utilisée pour un autre étudiant.');
                    }
                },
            ],
            'telephone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9\s\-()]{7,30}$/'],
            'date_naissance' => ['required', 'date_format:Y-m-d', 'before:today', 'after:1920-01-01'],
            'sexe' => ['required', 'in:M,F'],
            'faculte_id' => ['required', 'integer', Rule::exists('facultes', 'id')->where('active', true)],
            'programme_id' => [
                'required',
                'integer',
                Rule::exists('programmes', 'id')->where(fn ($query) => $query
                    ->where('statut', Programme::STATUT_ACTIF)
                    ->where('faculte_id', $this->input('faculte_id'))),
            ],
            'niveau_id' => [
                'required',
                'integer',
                Rule::exists('niveaux', 'id')->where(fn ($query) => $query
                    ->where('actif', true)
                    ->where('programme_id', $this->input('programme_id'))),
            ],
            'groupe_id' => [
                'nullable',
                'integer',
                Rule::exists('groups', 'id')->where(fn ($query) => $query
                    ->where('actif', true)
                    ->where('faculte_id', $this->input('faculte_id'))),
            ],
            'annee_universitaire_id' => ['required', 'integer', Rule::exists('annees_universitaires', 'id')],
            'type_inscription' => ['required', 'in:premiere,reinscription,transfert,mutation,reprise,equivalence,autre'],
            'regime' => ['required', 'in:initiale,continue,alternance'],
            'adresse' => ['required', 'string', 'min:5', 'max:500'],
            'ville' => ['required', 'string', 'min:2', 'max:100'],
            'code_postal' => ['required', 'string', 'max:20'],
            'annee_bac' => ['required', 'string', 'regex:/^\d{4}$/'],
            'mention_bac' => ['nullable', 'in:Passable,Assez Bien,Bien,Très Bien,Excellent'],
            'motif_validation' => ['required', 'string', 'min:10', 'max:255'],
            'paye' => ['required', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $utilisateur = $this->user();
            $donnees = $validator->getData();

            $faculteId = is_numeric($donnees['faculte_id'] ?? null) ? (int) $donnees['faculte_id'] : 0;
            $anneeId = is_numeric($donnees['annee_universitaire_id'] ?? null) ? (int) $donnees['annee_universitaire_id'] : null;

            if ($faculteId > 0 && $utilisateur !== null && ! $utilisateur->peutAccederFaculte($faculteId)) {
                $validator->errors()->add('faculte_id', 'Vous ne pouvez créer un étudiant que dans votre faculté.');
            }

            $programmeId = is_numeric($donnees['programme_id'] ?? null) ? (int) $donnees['programme_id'] : null;
            $programme = $programmeId !== null ? Programme::find($programmeId) : null;

            if ($programme !== null && $faculteId > 0 && (int) $programme->faculte_id !== $faculteId) {
                $validator->errors()->add('programme_id', 'Le parcours sélectionné n’appartient pas à cette faculté.');
            }

            $niveauId = is_numeric($donnees['niveau_id'] ?? null) ? (int) $donnees['niveau_id'] : null;
            $niveau = $niveauId !== null ? Niveau::find($niveauId) : null;

            if ($niveau !== null && $programme !== null && (int) $niveau->programme_id !== (int) $programme->id) {
                $validator->errors()->add('niveau_id', 'Le niveau sélectionné n’appartient pas à ce parcours.');
            }

            $groupeId = is_numeric($donnees['groupe_id'] ?? null) ? (int) $donnees['groupe_id'] : null;
            $groupe = $groupeId !== null ? Group::find($groupeId) : null;

            if ($groupe !== null) {
                if (! $groupe->actif) {
                    $validator->errors()->add('groupe_id', 'Le groupe sélectionné est inactif.');
                }

                if ($faculteId > 0 && (int) $groupe->faculte_id !== $faculteId) {
                    $validator->errors()->add('groupe_id', 'Le groupe sélectionné n’appartient pas à cette faculté.');
                }

                if ($programme !== null && $groupe->programme_id !== null && (int) $groupe->programme_id !== (int) $programme->id) {
                    $validator->errors()->add('groupe_id', 'Le groupe sélectionné n’appartient pas à ce parcours.');
                }

                if ($niveau !== null) {
                    $niveauGroupe = $groupe->niveau_id !== null
                        ? (int) $groupe->niveau_id === (int) $niveau->id
                        : ($groupe->niveau === null || $groupe->niveau === $niveau->code);

                    if (! $niveauGroupe) {
                        $validator->errors()->add('groupe_id', 'Le groupe sélectionné n’appartient pas à ce niveau.');
                    }
                }

                if ($anneeId !== null
                    && $groupe->annee_universitaire_id !== null
                    && (int) $groupe->annee_universitaire_id !== $anneeId) {
                    $validator->errors()->add('groupe_id', 'Le groupe sélectionné n’appartient pas à cette année universitaire.');
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'nom.required' => 'Le nom est obligatoire.',
            'nom.min' => 'Le nom doit contenir au moins 2 caractères.',
            'prenom.required' => 'Le prénom est obligatoire.',
            'prenom.min' => 'Le prénom doit contenir au moins 2 caractères.',
            'cin.required' => 'Le numéro de CIN est obligatoire.',
            'cin.regex' => 'Le numéro de CIN doit contenir entre 5 et 30 caractères (lettres, chiffres ou tirets).',
            'email.required' => 'L’adresse email est obligatoire.',
            'email.email' => 'Veuillez saisir une adresse email valide.',
            'date_naissance.required' => 'La date de naissance est obligatoire.',
            'date_naissance.date_format' => 'La date de naissance doit être au format AAAA-MM-JJ.',
            'date_naissance.before' => 'La date de naissance doit être antérieure à aujourd’hui.',
            'date_naissance.after' => 'La date de naissance doit être postérieure au 01/01/1920.',
            'sexe.required' => 'Le sexe est obligatoire.',
            'sexe.in' => 'Veuillez sélectionner Masculin ou Féminin.',
            'faculte_id.required' => 'La faculté est obligatoire.',
            'faculte_id.exists' => 'La faculté sélectionnée est introuvable ou inactive.',
            'programme_id.required' => 'Le parcours (filière) est obligatoire.',
            'programme_id.exists' => 'Le parcours sélectionné est introuvable ou inactif pour cette faculté.',
            'niveau_id.required' => 'Le niveau est obligatoire.',
            'niveau_id.exists' => 'Le niveau sélectionné est introuvable ou inactif pour ce parcours.',
            'groupe_id.exists' => 'Le groupe sélectionné est introuvable ou inactif.',
            'annee_universitaire_id.required' => 'L’année universitaire est obligatoire.',
            'annee_universitaire_id.exists' => 'L’année universitaire sélectionnée est introuvable.',
            'type_inscription.in' => 'Le type d’inscription sélectionné n’est pas valide.',
            'regime.in' => 'Le régime sélectionné n’est pas valide.',
            'adresse.required' => 'L’adresse est obligatoire.',
            'adresse.min' => 'L’adresse doit contenir au moins 5 caractères.',
            'ville.required' => 'La ville est obligatoire.',
            'ville.min' => 'La ville doit contenir au moins 2 caractères.',
            'code_postal.required' => 'Le code postal est obligatoire.',
            'annee_bac.required' => 'L’année d’obtention du baccalauréat est obligatoire.',
            'annee_bac.regex' => 'L’année du baccalauréat doit être une année sur 4 chiffres (ex : 2024).',
            'mention_bac.in' => 'La mention sélectionnée n’est pas valide.',
            'motif_validation.required' => 'Le motif de validation est obligatoire : il justifie la validation immédiate.',
            'motif_validation.min' => 'Le motif de validation doit contenir au moins 10 caractères.',
            'paye.required' => 'Veuillez indiquer si le paiement a été effectué.',
            'paye.boolean' => 'Le statut de paiement est invalide (oui ou non).',
        ];
    }
}
