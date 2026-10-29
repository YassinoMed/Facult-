<?php

namespace App\Modules\AiAssistant\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Source documentaire indexée (Lot 6). Seules les sources déclarées
 * EXPLICITEMENT dans config('ai-assistant.rag.sources') peuvent être
 * actives ici : toute autre chemin est refusé par l'indexeur.
 */
class AiSourceDocumentaire extends Model
{
    public const STATUT_ACTIF = 'actif';

    public const STATUT_RETIREE = 'retire';

    protected $table = 'ai_knowledge_sources';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'titre', 'chemin', 'type', 'restreinte', 'sha256',
        'statut', 'version_indexeur', 'indexe_le',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'restreinte' => 'boolean',
            'indexe_le' => 'datetime',
        ];
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(AiChunkDocumentaire::class, 'source_id');
    }

    public function scopeActives(Builder $query): Builder
    {
        return $query->where('statut', self::STATUT_ACTIF);
    }
}
