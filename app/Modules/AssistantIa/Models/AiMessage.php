<?php

namespace App\Modules\AssistantIa\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle de persistance d'un message échangé dans l'assistant IA.
 */
class AiMessage extends Model
{
    use HasFactory;

    protected $table = 'ai_messages';

    protected $fillable = [
        'conversation_id',
        'role',
        'contenu',
        'appels_outils',
        'resultats_outils',
        'sources',
        'tokens_prompt',
        'tokens_reponse',
        'latence_ms',
    ];

    protected function casts(): array
    {
        return [
            'appels_outils' => 'array',
            'resultats_outils' => 'array',
            'sources' => 'array',
            'tokens_prompt' => 'integer',
            'tokens_reponse' => 'integer',
            'latence_ms' => 'float',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }
}
