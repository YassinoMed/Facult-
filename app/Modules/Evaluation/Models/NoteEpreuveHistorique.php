<?php

namespace App\Modules\Evaluation\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NoteEpreuveHistorique extends Model
{
    protected $table = 'evaluation_notes_historique';

    public $timestamps = false;

    protected $fillable = [
        'evaluation_note_id', 'ancienne_valeur', 'nouvelle_valeur', 'motif', 'auteur_id', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'ancienne_valeur' => 'decimal:2',
            'nouvelle_valeur' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function note(): BelongsTo
    {
        return $this->belongsTo(NoteEpreuve::class, 'evaluation_note_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
