<?php

namespace App\Models\Iam;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Journal IAM append-only. Le modèle refuse explicitement toute mise à
 * jour et toute suppression applicative ; la table ne possède pas de
 * `updated_at`. Chaque entrée porte une empreinte chaînée (SHA-256) qui
 * permet de détecter une altération de l'historique.
 */
class EvenementIam extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'iam_evenements';

    /** @var array<int, string> */
    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'avant' => 'array',
            'apres' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): bool {
            return false;
        });

        static::deleting(function (): bool {
            return false;
        });
    }

    public function acteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acteur_id');
    }

    public function cible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cible_user_id');
    }
}
