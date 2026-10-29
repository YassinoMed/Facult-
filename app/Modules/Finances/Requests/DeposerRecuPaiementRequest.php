<?php

namespace App\Modules\Finances\Requests;

use App\Models\InscriptionAcademique;
use Illuminate\Foundation\Http\FormRequest;

/**
 * LOOP-UNIREGISTRE-INSCRIPTIONS-PAIEMENTS-01 : dépôt d'un reçu par
 * l'étudiant connecté (session `etudiant_auth_id`).
 *
 * La validation HTTP est une première barrière ; `DocumentStorageService`
 * refait la validation profonde (MIME réel, signature, taille) et
 * l'appartenance de l'inscription est vérifiée dans le service.
 */
class DeposerRecuPaiementRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! session()->has('etudiant_auth_id')) {
            return false;
        }

        $inscription = $this->route('inscription');
        if ($inscription instanceof InscriptionAcademique) {
            abort_if((int) $inscription->etudiant_id !== (int) session('etudiant_auth_id'), 404);
        } elseif ($inscription !== null) {
            $etudiantId = InscriptionAcademique::whereKey($inscription)->value('etudiant_id');
            abort_if($etudiantId === null || (int) $etudiantId !== (int) session('etudiant_auth_id'), 404);
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tailleMaxKo = max(1, (int) ceil(((int) config('student-dossiers.taille_max', 10 * 1024 * 1024)) / 1024));

        return [
            'recu' => [
                'required',
                'file',
                'max:'.$tailleMaxKo,
                'mimes:pdf,jpg,jpeg,png,webp',
            ],
            'montant' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
            'reference_paiement' => [
                'required',
                'string',
                'min:3',
                'max:100',
                'not_regex:/[\x00-\x1F\x7F]/',
            ],
            'date_paiement' => ['required', 'date', 'before_or_equal:today'],
            'engagement' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'recu.required' => 'Veuillez sélectionner votre reçu.',
            'recu.file' => 'Le reçu doit être un fichier valide.',
            'recu.max' => 'Le reçu dépasse la taille maximale autorisée.',
            'recu.mimes' => 'Formats acceptés : PDF, JPG, PNG ou WEBP.',
            'montant.required' => 'Le montant payé est obligatoire.',
            'montant.numeric' => 'Le montant doit être un nombre.',
            'montant.min' => 'Le montant doit être strictement positif.',
            'reference_paiement.required' => 'La référence du paiement est obligatoire.',
            'reference_paiement.min' => 'La référence du paiement est trop courte.',
            'reference_paiement.max' => 'La référence du paiement est trop longue (100 caractères maximum).',
            'reference_paiement.not_regex' => 'La référence contient des caractères non autorisés.',
            'date_paiement.required' => 'La date du paiement est obligatoire.',
            'date_paiement.date' => 'La date du paiement est invalide.',
            'date_paiement.before_or_equal' => 'La date du paiement ne peut pas être dans le futur.',
            'engagement.accepted' => 'Vous devez certifier que le reçu correspond à un paiement réel.',
        ];
    }
}
