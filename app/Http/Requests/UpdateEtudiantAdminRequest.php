<?php

namespace App\Http\Requests;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Modification administrative de la fiche d'un étudiant existant.
 *
 * Miroir de `StoreEtudiantAdminRequest` : mêmes champs éditables, mêmes
 * normalisations et mêmes règles de cohérence LMD (faculté → parcours →
 * niveau). Les écarts sont strictement délimités :
 *
 * - le CIN et l'email s'appuient sur `Rule::unique()->ignore()` afin que
 *   la valeur courante de l'étudiant ne soit jamais rejetée ;
 * - le statut, le groupe, le mot de passe et l'année d'inscription ne
 *   sont PAS des champs de ce formulaire : ils restent délégués à leurs
 *   workflows dédiés (aucune duplication de règle métier) ;
 * - le type d'inscription, le régime et le paiement reprennent la valeur
 *   COURANTE de l'inscription lorsque le POST est partiel : aucune
 *   valeur d'usine ne doit écraser silencieusement une inscription réelle ;
 * - sans inscription courante, ces trois champs restent absents du POST et
 *   leurs règles deviennent tolérantes (`sometimes` / `nullable`) ;
 * - les champs optionnels de la fiche (`identifiant_national`,
 *   `telephone`, `mention_bac`) ne sont fusionnés QUE s'ils sont
 *   RÉELLEMENT transmis : une clé ABSENTE vaut « inchangé » (le service
 *   conserve la valeur courante), une clé VIDE vaut « effacement
 *   volontaire » (§2.5.b) ;
 * - le motif de modification est obligatoire et journalisé.
 *
 * Anti-IDOR : l'agent doit non seulement posséder `archives.update`, mais
 * aussi pouvoir accéder à la faculté de l'étudiant visé.
 */
class UpdateEtudiantAdminRequest extends FormRequest
{
    /**
     * Étudiant visé par la route, mémoïsé (la route est liée par modèle :
     * aucune requête supplémentaire en régime nominal).
     */
    private ?Etudiant $etudiantCibleMemoire = null;

    /**
     * Drapeau de résolution de l'étudiant cible : `null` est une réponse
     * valide (route sans modèle), il faut donc distinguer « pas encore
     * résolu » de « résolu à null ».
     */
    private bool $etudiantCibleResolu = false;

    /**
     * Inscription courante mémoïsée (scope `InscriptionAcademique::courante()`
     * — source unique, partagée avec le service et le contrôleur).
     */
    private ?InscriptionAcademique $inscriptionCouranteMemoire = null;

    /**
     * Drapeau de résolution de l'inscription courante (idem : `null` est
     * une réponse valide pour un étudiant sans inscription).
     */
    private bool $inscriptionCouranteResolue = false;

    public function authorize(): bool
    {
        $utilisateur = $this->user();
        $etudiant = $this->etudiantCible();

        if ($utilisateur === null || $etudiant === null) {
            return false;
        }

        return $utilisateur->hasPermission('archives.update')
            && $utilisateur->peutAccederFaculte((int) $etudiant->faculte_id);
    }

    /**
     * Étudiant visé par la route (`etudiants/{etudiant}`).
     */
    protected function etudiantCible(): ?Etudiant
    {
        if ($this->etudiantCibleResolu) {
            return $this->etudiantCibleMemoire;
        }

        $this->etudiantCibleResolu = true;

        $cible = $this->route('etudiant');

        if ($cible instanceof Etudiant) {
            $this->etudiantCibleMemoire = $cible;

            return $this->etudiantCibleMemoire;
        }

        if (is_numeric($cible)) {
            $this->etudiantCibleMemoire = Etudiant::find((int) $cible);

            return $this->etudiantCibleMemoire;
        }

        return null;
    }

    /**
     * Inscription courante de l'étudiant : année universitaire la plus
     * récente (scope `InscriptionAcademique::courante()` — source unique).
     *
     * Résolution paresseuse ET mémoïsée : `authorize()`,
     * `prepareForValidation()` et `rules()` partagent la même instance,
     * soit UNE seule requête par requête HTTP (au lieu de trois).
     */
    protected function inscriptionCourante(): ?InscriptionAcademique
    {
        if ($this->inscriptionCouranteResolue) {
            return $this->inscriptionCouranteMemoire;
        }

        $this->inscriptionCouranteResolue = true;

        $etudiant = $this->etudiantCible();

        $this->inscriptionCouranteMemoire = $etudiant === null
            ? null
            : InscriptionAcademique::courante()
                ->where('inscriptions_academiques.etudiant_id', $etudiant->id)
                ->first();

        return $this->inscriptionCouranteMemoire;
    }

    /**
     * Valeur transmise, sinon valeur de repli. Une valeur vide est
     * traitée comme « non transmise » ; une valeur malformée (tableau)
     * est conservée pour que la validation la refuse.
     */
    protected function valeurOuDefaut(string $cle, mixed $defaut): mixed
    {
        $valeur = $this->input($cle);

        if ($valeur === null || $valeur === '') {
            return $defaut;
        }

        return $valeur;
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

        $inscription = $this->inscriptionCourante();

        $fusion = [
            'cin' => mb_strtoupper(preg_replace('/\s+/', '', $texte('cin')) ?? ''),
            'nom' => $texte('nom'),
            'prenom' => $texte('prenom'),
            'email' => mb_strtolower($texte('email')),
            'adresse' => $texte('adresse'),
            'ville' => $texte('ville'),
            'code_postal' => $texte('code_postal'),
            'annee_bac' => $texte('annee_bac'),
            'motif_modification' => $texte('motif_modification'),
        ];

        // Champs optionnels de la fiche : fusionnés UNIQUEMENT s'ils sont
        // RÉELLEMENT transmis (`$this->has()`), pour distinguer :
        //
        // - clé ABSENTE  → non fusionnée → absente de `validated()` → le
        //   service conserve la valeur COURANTE (champ INCHANGÉ) ;
        // - clé présente mais VIDE → `null` → effacement VOLONTAIRE.
        //
        // §2.5.b : « une absence de champ est traitée comme "inchangé",
        // jamais comme "remit à zéro" ».
        foreach (['identifiant_national', 'telephone', 'mention_bac'] as $cleOptionnelle) {
            if ($this->has($cleOptionnelle)) {
                $fusion[$cleOptionnelle] = $texteOuNull($cleOptionnelle);
            }
        }

        // Type / régime / paiement : la valeur COURANTE de l'inscription
        // fait référence. Un POST PARTIEL ne doit jamais réécrire le type
        // (transfert), le régime (continue) ou le paiement réels : les
        // valeurs d'usine ('premiere' / 'initiale' / 0) corrompraient
        // silencieusement l'inscription.
        if ($inscription !== null) {
            $fusion['type_inscription'] = $this->valeurOuDefaut('type_inscription', $inscription->type);
            $fusion['regime'] = $this->valeurOuDefaut('regime', $inscription->regime);
            $fusion['paye'] = $this->valeurOuDefaut('paye', $inscription->paye ? '1' : '0');
        }

        // Sans inscription courante, ces trois champs restent ABSENTS : le
        // formulaire ne propose pas le bloc « inscription » et les règles
        // de validation deviennent tolérantes (`sometimes` / `nullable`).

        $this->merge($fusion);
    }

    public function rules(): array
    {
        $etudiant = $this->etudiantCible();
        $identifiant = $etudiant?->id;

        // Sans inscription courante, le bloc « inscription » du formulaire
        // est absent du POST : les trois champs deviennent facultatifs.
        // Requête mémoïsée (une seule résolution par requête HTTP).
        $avecInscription = $this->inscriptionCourante() !== null;

        return [
            'nom' => ['required', 'string', 'min:2', 'max:100'],
            'prenom' => ['required', 'string', 'min:2', 'max:100'],
            'cin' => [
                'required',
                'string',
                'regex:/^[A-Z0-9-]{5,30}$/',
                // Unicité par rapport à la BASE (pas de closure globale) :
                // la valeur courante de l'étudiant est ignorée.
                Rule::unique('etudiants', 'cin')->ignore($identifiant),
            ],
            'identifiant_national' => ['nullable', 'string', 'max:30'],
            'email' => [
                'required',
                'string',
                'email',
                'max:254',
                Rule::unique('etudiants', 'email')->ignore($identifiant),
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
            'type_inscription' => $avecInscription
                ? ['required', 'in:premiere,reinscription,transfert,mutation,reprise,equivalence,autre']
                : ['sometimes', 'nullable', 'in:premiere,reinscription,transfert,mutation,reprise,equivalence,autre'],
            'regime' => $avecInscription
                ? ['required', 'in:initiale,continue,alternance']
                : ['sometimes', 'nullable', 'in:initiale,continue,alternance'],
            'adresse' => ['required', 'string', 'min:5', 'max:500'],
            'ville' => ['required', 'string', 'min:2', 'max:100'],
            'code_postal' => ['required', 'string', 'max:20'],
            'annee_bac' => ['required', 'string', 'regex:/^\d{4}$/'],
            'mention_bac' => ['nullable', 'in:Passable,Assez Bien,Bien,Très Bien,Excellent'],
            'motif_modification' => ['required', 'string', 'min:10', 'max:255'],
            'paye' => $avecInscription
                ? ['required', 'boolean']
                : ['sometimes', 'nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $utilisateur = $this->user();
            $donnees = $validator->getData();

            $faculteId = is_numeric($donnees['faculte_id'] ?? null) ? (int) $donnees['faculte_id'] : 0;

            if ($faculteId > 0 && $utilisateur !== null && ! $utilisateur->peutAccederFaculte($faculteId)) {
                $validator->errors()->add('faculte_id', 'Vous ne pouvez modifier un étudiant que dans votre faculté.');
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
        });
    }

    public function messages(): array
    {
        return [
            'nom.required' => 'Le nom est obligatoire.',
            'nom.string' => 'Le nom doit être une chaîne de caractères.',
            'nom.min' => 'Le nom doit contenir au moins 2 caractères.',
            'nom.max' => 'Le nom ne doit pas dépasser 100 caractères.',
            'prenom.required' => 'Le prénom est obligatoire.',
            'prenom.string' => 'Le prénom doit être une chaîne de caractères.',
            'prenom.min' => 'Le prénom doit contenir au moins 2 caractères.',
            'prenom.max' => 'Le prénom ne doit pas dépasser 100 caractères.',
            'cin.required' => 'Le numéro de CIN est obligatoire.',
            'cin.string' => 'Le numéro de CIN doit être une chaîne de caractères.',
            'cin.regex' => 'Le numéro de CIN doit contenir entre 5 et 30 caractères (lettres, chiffres ou tirets).',
            'cin.max' => 'Le numéro de CIN ne doit pas dépasser 30 caractères.',
            'cin.unique' => 'Ce numéro de CIN est déjà enregistré pour un autre étudiant.',
            'identifiant_national.string' => 'L’identifiant national doit être une chaîne de caractères.',
            'identifiant_national.max' => 'L’identifiant national ne doit pas dépasser 30 caractères.',
            'email.required' => 'L’adresse email est obligatoire.',
            'email.string' => 'L’adresse email doit être une chaîne de caractères.',
            'email.email' => 'Veuillez saisir une adresse email valide.',
            'email.max' => 'L’adresse email ne doit pas dépasser 254 caractères.',
            'email.unique' => 'Cette adresse email est déjà utilisée pour un autre étudiant.',
            'telephone.string' => 'Le numéro de téléphone doit être une chaîne de caractères.',
            'telephone.max' => 'Le numéro de téléphone ne doit pas dépasser 30 caractères.',
            'telephone.regex' => 'Le numéro de téléphone doit contenir entre 7 et 30 caractères (chiffres, espaces, tirets ou parenthèses).',
            'date_naissance.required' => 'La date de naissance est obligatoire.',
            'date_naissance.date_format' => 'La date de naissance doit être au format AAAA-MM-JJ.',
            'date_naissance.before' => 'La date de naissance doit être antérieure à aujourd’hui.',
            'date_naissance.after' => 'La date de naissance doit être postérieure au 01/01/1920.',
            'sexe.required' => 'Le sexe est obligatoire.',
            'sexe.in' => 'Veuillez sélectionner Masculin ou Féminin.',
            'faculte_id.required' => 'La faculté est obligatoire.',
            'faculte_id.integer' => 'La faculté sélectionnée est invalide.',
            'faculte_id.exists' => 'La faculté sélectionnée est introuvable ou inactive.',
            'programme_id.required' => 'Le parcours (filière) est obligatoire.',
            'programme_id.integer' => 'Le parcours sélectionné est invalide.',
            'programme_id.exists' => 'Le parcours sélectionné est introuvable ou inactif pour cette faculté.',
            'niveau_id.required' => 'Le niveau est obligatoire.',
            'niveau_id.integer' => 'Le niveau sélectionné est invalide.',
            'niveau_id.exists' => 'Le niveau sélectionné est introuvable ou inactif pour ce parcours.',
            'type_inscription.required' => 'Le type d’inscription est obligatoire.',
            'type_inscription.in' => 'Le type d’inscription sélectionné n’est pas valide.',
            'regime.required' => 'Le régime d’études est obligatoire.',
            'regime.in' => 'Le régime sélectionné n’est pas valide.',
            'adresse.required' => 'L’adresse est obligatoire.',
            'adresse.string' => 'L’adresse doit être une chaîne de caractères.',
            'adresse.min' => 'L’adresse doit contenir au moins 5 caractères.',
            'adresse.max' => 'L’adresse ne doit pas dépasser 500 caractères.',
            'ville.required' => 'La ville est obligatoire.',
            'ville.string' => 'La ville doit être une chaîne de caractères.',
            'ville.min' => 'La ville doit contenir au moins 2 caractères.',
            'ville.max' => 'La ville ne doit pas dépasser 100 caractères.',
            'code_postal.required' => 'Le code postal est obligatoire.',
            'code_postal.string' => 'Le code postal doit être une chaîne de caractères.',
            'code_postal.max' => 'Le code postal ne doit pas dépasser 20 caractères.',
            'annee_bac.required' => 'L’année d’obtention du baccalauréat est obligatoire.',
            'annee_bac.string' => 'L’année du baccalauréat doit être une chaîne de caractères.',
            'annee_bac.max' => 'L’année d’obtention du baccalauréat ne doit pas dépasser 4 caractères.',
            'annee_bac.regex' => 'L’année du baccalauréat doit être une année sur 4 chiffres (ex : 2024).',
            'mention_bac.in' => 'La mention sélectionnée n’est pas valide.',
            'motif_modification.required' => 'Le motif de la modification est obligatoire : il justifie la correction de la fiche.',
            'motif_modification.string' => 'Le motif de la modification doit être une chaîne de caractères.',
            'motif_modification.min' => 'Le motif de la modification doit contenir au moins 10 caractères.',
            'motif_modification.max' => 'Le motif de la modification ne doit pas dépasser 255 caractères.',
            'paye.required' => 'Veuillez indiquer si le paiement a été effectué.',
            'paye.boolean' => 'Le statut de paiement est invalide (oui ou non).',
        ];
    }
}
