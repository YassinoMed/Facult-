<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'nom' => $this->nom,
            'credits' => $this->credits,
            'coefficient' => (float) $this->coefficient,
            'type' => $this->type,
            'semestre' => $this->whenLoaded('semestre', fn () => [
                'id' => $this->semestre->id, 'code' => $this->semestre->code,
            ]),
            'programme' => $this->whenLoaded('programme', fn () => [
                'id' => $this->programme->id, 'code' => $this->programme->code,
            ]),
        ];
    }
}
