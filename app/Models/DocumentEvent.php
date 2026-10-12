<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 16 §16.2 : journal des événements documentaires
 * (généré, téléchargé, vérifié, révoqué…).
 */
class DocumentEvent extends Model
{
    protected $table = 'document_events';

    protected $fillable = ['document_id', 'evenement', 'auteur_id', 'meta'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }
}
