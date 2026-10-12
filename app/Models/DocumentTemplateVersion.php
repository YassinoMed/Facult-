<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 16 §16.2 : version immuable d'un modèle (contenu figé).
 */
class DocumentTemplateVersion extends Model
{
    protected $table = 'document_template_versions';

    protected $fillable = [
        'document_template_id', 'version', 'contenu', 'schema_variables', 'gabarit', 'cree_par',
    ];

    protected function casts(): array
    {
        return ['schema_variables' => 'array'];
    }

    /** @return BelongsTo<DocumentTemplate, $this> */
    public function modele(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }
}
