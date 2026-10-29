<?php

namespace App\Modules\AiAssistant\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Conversation IA (table canonique partagée ai_conversations, définie
 * par la migration 2026_10_23_100000 du lot concurrent — CONSOMMÉE,
 * pas redéfinie). PRIVÉE par défaut : propriétaire = user_id, jamais
 * exposée à un autre utilisateur (policy + scopes serveur).
 */
class AiConversation extends Model
{
    use SoftDeletes;

    protected $table = 'ai_conversations';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid', 'user_id', 'faculte_id', 'titre', 'mode',
        'fournisseur', 'modele', 'epingle', 'dernier_message_a', 'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'epingle' => 'boolean',
            'dernier_message_a' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'conversation_id');
    }

    /**
     * ISOLATION INTER-UTILISATEUR : toute lecture filtrée serveur.
     */
    public function scopePourUtilisateur(Builder $query, int $utilisateurId): Builder
    {
        return $query->where('user_id', $utilisateurId);
    }

    public function scopeParDernierMessage(Builder $query): Builder
    {
        return $query->orderByDesc('epingle')->orderByDesc('dernier_message_a')->orderByDesc('created_at');
    }

    public function retenirDernierMessage(): void
    {
        DB::table('ai_conversations')
            ->where('id', $this->id)
            ->update(['dernier_message_a' => now(), 'updated_at' => now()]);
    }
}
