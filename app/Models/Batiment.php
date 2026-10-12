<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Batiment extends Model
{
    protected $table = 'batiments';

    protected $fillable = [
        'code',
        'nom',
        'faculte_id',
    ];

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class);
    }
}
