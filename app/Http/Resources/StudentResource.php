<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Étudiant exposé via API — surface STRICTEMENT contrôlée :
 * jamais de mot de passe, jamais de hash, jamais de données internes.
 */
class StudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // CIN complète = moindre privilège au niveau du TOKEN (scope
        // archives:read explicite), jamais sur le seul rôle. Token
        // students:read seul → cin_masquee uniquement.
        $peutVoirCin = (bool) $request->user()?->currentAccessToken()?->can('archives:read');

        return [
            'id' => $this->id,
            'cin_masquee' => $this->cin_masquee,
            'cin' => $this->when($peutVoirCin, fn () => $this->cin),
            'numero_inscription' => $this->num_inscription,
            'nom' => $this->nom,
            'prenom' => $this->prenom,
            'email' => $this->email,
            'sexe' => $this->sexe,
            'niveau' => $this->niveau,
            'annee_academique' => $this->annee_academique,
            'ville' => $this->ville,
            'statut' => $this->statut,
            'faculte' => $this->whenLoaded('faculte', fn () => [
                'id' => $this->faculte->id,
                'code' => $this->faculte->code,
                'nom' => $this->faculte->nom,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
