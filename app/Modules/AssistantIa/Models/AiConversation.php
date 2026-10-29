<?php

namespace App\Modules\AssistantIa\Models;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\AssistantIa\DTO\ActeurPortail;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Modèle de persistance d'une session de conversation UniRegistre AI.
 */
class AiConversation extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'ai_conversations';

    protected $fillable = [
        'uuid',
        'user_id',
        'etudiant_id',
        'teacher_id',
        'faculte_id',
        'portail',
        'titre',
        'mode',
        'fournisseur',
        'modele',
        'epingle',
        'dernier_message_a',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'epingle' => 'boolean',
            'metadata' => 'array',
            'dernier_message_a' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AiConversation $conversation) {
            if (empty($conversation->uuid)) {
                $conversation->uuid = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class, 'etudiant_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class, 'faculte_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'conversation_id')->orderBy('id', 'asc');
    }

    /**
     * Scope garantissant l'isolation horizontale stricte entre utilisateurs back-office.
     */
    public function scopePourUtilisateur(Builder $query, User $utilisateur): Builder
    {
        return $query->where('user_id', $utilisateur->id);
    }

    /**
     * Scope universel garantissant l'isolation horizontale stricte par acteur et par portail (anti-IDOR).
     */
    public function scopePourActeur(Builder $query, ActeurPortail $acteur): Builder
    {
        if ($acteur->estAdmin()) {
            return $query->where('user_id', $acteur->id)->where('portail', 'admin');
        }

        if ($acteur->estEnseignant()) {
            return $query->where('teacher_id', $acteur->id)->where('portail', 'enseignant');
        }

        if ($acteur->estEtudiant()) {
            return $query->where('etudiant_id', $acteur->id)->where('portail', 'etudiant');
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * Scope filtrant par mode opérationnel ('metier' ou 'developpeur').
     */
    public function scopeParMode(Builder $query, string $mode): Builder
    {
        return $query->where('mode', $mode);
    }
}
