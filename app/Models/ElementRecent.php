<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ElementRecent extends Model
{
    protected $table = 'elements_recents';

    protected $fillable = ['user_id', 'type', 'reference', 'titre', 'lien'];

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
