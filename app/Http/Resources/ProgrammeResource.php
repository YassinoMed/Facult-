<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProgrammeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'nom' => $this->nom,
            'duree_annees' => $this->duree_annees,
            'semestres_nombre' => $this->semestres_nombre,
            'credits_requis' => $this->credits_requis,
            'faculte' => $this->whenLoaded('faculte', fn () => [
                'id' => $this->faculte->id, 'code' => $this->faculte->code, 'nom' => $this->faculte->nom,
            ]),
        ];
    }
}
