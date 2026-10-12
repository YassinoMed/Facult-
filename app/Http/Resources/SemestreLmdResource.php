<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SemestreLmdResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'nom' => $this->nom,
            'numero' => $this->numero,
            'niveau' => $this->whenLoaded('niveau', fn () => [
                'id' => $this->niveau->id, 'code' => $this->niveau->code,
                'programme' => $this->niveau->relationLoaded('programme') ? [
                    'id' => $this->niveau->programme->id, 'code' => $this->niveau->programme->code,
                ] : null,
            ]),
        ];
    }
}
