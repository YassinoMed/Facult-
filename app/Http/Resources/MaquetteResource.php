<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaquetteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'statut' => $this->statut,
            'programme' => $this->whenLoaded('programme', fn () => [
                'id' => $this->programme->id, 'code' => $this->programme->code,
            ]),
            'annee_universitaire' => $this->whenLoaded('anneeUniversitaire', fn () => [
                'id' => $this->anneeUniversitaire->id, 'libelle' => $this->anneeUniversitaire->libelle,
            ]),
        ];
    }
}
