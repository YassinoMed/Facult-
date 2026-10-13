<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Code PIN de verrouillage secondaire (LOOP N) : hash bcrypt uniquement,
 * compteur d'échecs et temporisation. Aucune autre donnée.
 */
class VerrouillagePin extends Model
{
    protected $table = 'verrouillage_pins';

    protected $fillable = [
        'user_id',
        'pin_hash',
        'echecs',
        'bloque_until',
    ];

    protected function casts(): array
    {
        return [
            'echecs' => 'integer',
            'bloque_until' => 'datetime',
        ];
    }

    protected $hidden = [
        'pin_hash',
    ];

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
