<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EcueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'nom' => $this->nom,
            'coefficient' => (float) $this->coefficient,
            'credits' => $this->credits,
            'ue' => $this->whenLoaded('ue', fn () => [
                'id' => $this->ue->id, 'code' => $this->ue->code,
            ]),
        ];
    }
}
