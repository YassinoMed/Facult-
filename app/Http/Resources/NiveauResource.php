<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NiveauResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'nom' => $this->nom,
            'ordre' => $this->ordre,
            'programme' => $this->whenLoaded('programme', fn () => [
                'id' => $this->programme->id, 'code' => $this->programme->code,
            ]),
        ];
    }
}
