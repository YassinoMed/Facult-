<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'nom' => $this->nom,
            'coefficient' => (float) $this->coefficient,
            'credits' => $this->credits,
            'niveau' => $this->niveau,
            'semestre' => $this->semestre,
        ];
    }
}
