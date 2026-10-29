<?php

namespace App\Modules\Attendance\Requests;

use App\Modules\Attendance\Models\Presence;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Dépôt étudiant d'une pièce justificative : validation forte côté
 * serveur. Au-delà de `mimes:` (qui devine le type depuis le
 * contenu), le MIME réel (finfo) doit correspondre à l'extension —
 * un script renommé `.jpg` est refusé avant tout stockage.
 */
class DeposerJustificationRequest extends FormRequest
{
    /**
     * Extensions acceptées et leur MIME réel (finfo) exigé.
     *
     * @var array<string, string>
     */
    public const MIME_PAR_EXTENSION = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
    ];

    public function authorize(): bool
    {
        if (! $this->session()->has('etudiant_auth_id')) {
            return false;
        }

        $presence = $this->route('presence');
        if ($presence instanceof Presence) {
            abort_if((int) $presence->etudiant_id !== (int) $this->session()->get('etudiant_auth_id'), 404);
        } elseif ($presence !== null) {
            $etudiantId = Presence::whereKey($presence)->value('etudiant_id');
            abort_if($etudiantId === null || (int) $etudiantId !== (int) $this->session()->get('etudiant_auth_id'), 404);
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'motif' => ['required', 'string', 'max:1000'],
            'piece' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motif.required' => 'Le motif est obligatoire.',
            'piece.mimes' => 'Format accepté : PDF, JPG ou PNG (5 Mo max).',
        ];
    }

    /**
     * @param  Validator  $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(function (Validator $validator) {
            $fichier = $this->file('piece');

            if ($fichier === null || ! $fichier->isValid()) {
                return;
            }

            $extension = strtolower((string) $fichier->getClientOriginalExtension());
            $attendu = self::MIME_PAR_EXTENSION[$extension] ?? null;
            // finfo direct sur le contenu (jamais getMimeType(), qui
            // peut refléter le type déclaré par le client).
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $reel = (string) $finfo->file((string) $fichier->getRealPath());

            if ($attendu === null || $reel !== $attendu) {
                $validator->errors()->add(
                    'piece',
                    "Document illisible ou falsifié (contenu réel : {$reel}). Veuillez rescanner."
                );
            }
        });
    }
}
