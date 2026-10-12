<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GradeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'matiere' => $this->whenLoaded('matiere', fn () => [
                'code' => $this->matiere->code,
                'nom' => $this->matiere->nom,
            ]),
            'note' => (float) $this->note,
            'coefficient' => (float) $this->coefficient,
            'credits' => $this->credits,
            'session' => $this->session,
            'semestre' => $this->whenLoaded('semestre', fn () => $this->semestre->numero),
            'verrouillee' => $this->locked_at !== null,
            'saisie_le' => $this->created_at?->toIso8601String(),
        ];
    }
}
