<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 16 §16.3 : consultation publique de vérification (IP hachée,
 * résultat). Aucune PII conservée.
 */
class DocumentVerification extends Model
{
    protected $table = 'document_verifications';

    protected $fillable = ['document_id', 'code', 'ip_hachee', 'resultat'];
}
