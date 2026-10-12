<?php

namespace App\Modules\StudentDossiers\Resources;

use App\Modules\StudentDossiers\Models\StudentDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API future (§16) : métadonnées uniquement — `storage_path` exclu
 * (téléchargement réservé aux endpoints gated avec audit).
 *
 * @mixin StudentDocument
 */
class StudentDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category,
            'document_type' => $this->document_type,
            'title' => $this->title,
            'description' => $this->description,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'extension' => $this->extension,
            'size' => $this->size,
            'sha256' => $this->sha256,
            'status' => $this->status,
            'validated_at' => $this->validated_at,
            'rejected_reason' => $this->when($this->status === 'rejected', $this->rejected_reason),
            'expires_at' => $this->expires_at,
            'versions_count' => $this->versions->count(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
