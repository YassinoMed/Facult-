<?php

namespace App\Modules\StudentDossiers\Resources;

use App\Modules\StudentDossiers\Models\StudentDossier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API future (§16) : jamais le modèle brut — `storage_path` exclu,
 * aucune donnée sensible (password, hash, token).
 *
 * @mixin StudentDossier
 */
class StudentDossierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'etudiant' => [
                'id' => $this->etudiant->id,
                'cin' => $this->etudiant->cin,
                'nom' => $this->etudiant->nom,
                'prenom' => $this->etudiant->prenom,
                'email' => $this->etudiant->email,
                'faculte' => $this->etudiant->faculte?->code,
            ],
            'documents_count' => $this->whenCounted('documents'),
            'documents' => StudentDocumentResource::collection($this->whenLoaded('documents')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
