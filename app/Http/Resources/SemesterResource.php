<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SemesterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'moyenne' => $this->moyenne !== null ? (float) $this->moyenne : null,
            'credits_obtenus' => $this->credits_obtenus,
            'credits_total' => $this->credits_total,
            'decision' => $this->decision,
            'notes' => GradeResource::collection($this->whenLoaded('notes')),
        ];
    }
}
