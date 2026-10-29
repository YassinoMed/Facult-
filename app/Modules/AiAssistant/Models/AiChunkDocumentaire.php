<?php

namespace App\Modules\AiAssistant\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Chunk indexé d'une source documentaire (Lot 6). Recherche v1 par
 * mots-clés (LIKE) compatible SQLite + MySQL — aucune dépendance
 * vectorielle externe.
 */
class AiChunkDocumentaire extends Model
{
    protected $table = 'ai_knowledge_chunks';

    /**
     * @var list<string>
     */
    protected $fillable = ['source_id', 'ordre', 'contenu'];

    public function source(): BelongsTo
    {
        return $this->belongsTo(AiSourceDocumentaire::class, 'source_id');
    }

    /**
     * Restitution FIXÉE : uniquement des sources ACTIVEs, filtrage
     * restreint appliqué côté service selon permission du demandeur.
     */
    public function scopeRecherchables(Builder $query): Builder
    {
        return $query
            ->join('ai_knowledge_sources', 'ai_knowledge_sources.id', '=', 'ai_knowledge_chunks.source_id')
            ->where('ai_knowledge_sources.statut', AiSourceDocumentaire::STATUT_ACTIF);
    }
}
