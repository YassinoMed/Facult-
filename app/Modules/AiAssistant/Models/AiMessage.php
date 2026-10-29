<?php

namespace App\Modules\AiAssistant\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Message IA (table canonique partagée ai_messages, migration du lot
 * concurrent). L'ordre est déterministe et basé sur l'identifiant auto
 * incrémente (index conversation_id, id) ; les tailles sont bornées par
 * le service (limites.caracteres_message).
 */
class AiMessage extends Model
{
    protected $table = 'ai_messages';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'conversation_id', 'role', 'contenu', 'appels_outils', 'resultats_outils',
        'sources', 'tokens_prompt', 'tokens_reponse', 'latence_ms',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'appels_outils' => 'array',
            'resultats_outils' => 'array',
            'sources' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    /**
     * Ordre déterministe ascend (insertion).
     */
    public function scopeOrdonneParConversation(Builder $query, int $conversationId): Builder
    {
        return $query->where('conversation_id', $conversationId)->orderBy('id');
    }
}
