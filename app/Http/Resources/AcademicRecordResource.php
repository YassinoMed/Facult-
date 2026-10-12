<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dossier académique complet d'un étudiant (API).
 */
class AcademicRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'matricule' => $this->matricule,
            'statut' => $this->statut,
            'etudiant' => new StudentResource($this->whenLoaded('etudiant', fn () => $this->etudiant)),
            'inscriptions' => InscriptionAcademiqueResource::collection(
                $this->whenLoaded('inscriptions'),
            ),
        ];
    }
}
