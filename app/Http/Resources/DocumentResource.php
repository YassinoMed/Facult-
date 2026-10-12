<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Document académique — MÉTADONNÉES uniquement (§21) :
 * le chemin interne (chemin) n'est JAMAIS exposé.
 */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type_document,
            'nom_original' => $this->nom_original,
            'taille_octets' => $this->taille,
            'mime_type' => $this->mime_type,
            'hash_sha256' => $this->hash_fichier,
            'annee_universitaire' => $this->whenLoaded('anneeUniversitaire', fn () => $this->anneeUniversitaire?->libelle),
            'televerse_le' => $this->created_at?->toIso8601String(),
        ];
    }
}
