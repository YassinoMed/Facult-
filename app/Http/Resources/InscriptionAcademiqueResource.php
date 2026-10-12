<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InscriptionAcademiqueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'annee_universitaire' => $this->whenLoaded('anneeUniversitaire', fn () => $this->anneeUniversitaire?->libelle),
            'niveau' => $this->niveau,
            'filiere' => $this->filiere,
            'statut' => $this->statut,
            'moyenne_annuelle' => $this->moyenne_annuelle !== null ? (float) $this->moyenne_annuelle : null,
            'credits_obtenus' => $this->credits_obtenus,
            'credits_total' => $this->credits_total,
            'decision' => $this->decision,
            'semestres' => SemesterResource::collection($this->whenLoaded('semestres')),
        ];
    }
}
