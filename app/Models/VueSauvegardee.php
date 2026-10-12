<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VueSauvegardee extends Model
{
    protected $table = 'vues_sauvegardees';

    protected $fillable = ['user_id', 'tableau', 'nom', 'filtres', 'par_defaut'];

    protected function casts(): array
    {
        return ['filtres' => 'array', 'par_defaut' => 'boolean'];
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
