<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 16 §16.2 : séquence de numérotation par type/année/périmètre.
 */
class DocumentNumberingSequence extends Model
{
    protected $table = 'document_numbering_sequences';

    protected $fillable = ['type', 'annee', 'perimetre', 'prochain_numero', 'format'];
}
