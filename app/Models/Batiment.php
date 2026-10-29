<?php

namespace App\Models;

use App\Modules\Schedule\Models\Room;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class, 'batiment_id');
    }
}
