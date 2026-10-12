<?php

namespace App\Modules\Announcements\Models;

use App\Models\Etudiant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnnonceLecture extends Model
{
    protected $table = 'annonce_lectures';

    protected $fillable = ['annonce_id', 'etudiant_id', 'user_id', 'lue_a'];

    protected function casts(): array
    {
        return ['lue_a' => 'datetime'];
    }

    public function annonce(): BelongsTo
    {
        return $this->belongsTo(Annonce::class, 'annonce_id');
    }

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
