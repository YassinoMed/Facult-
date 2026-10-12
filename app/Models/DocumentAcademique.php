<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Document académique sensible — fichier stocké hors de public/
 * (storage/app/private/archives/etudiant_{id}/…).
 */
class DocumentAcademique extends Model
{
    protected $table = 'documents_academiques';

    protected $fillable = [
        'etudiant_id',
        'annee_universitaire_id',
        'type_document',
        'nom_original',
        'chemin',
        'mime_type',
        'taille',
        'hash_fichier',
        'uploaded_by',
    ];

    /**
     * Le chemin interne n'est jamais exposé dans les tableaux/JSON.
     */
    protected $hidden = [
        'chemin',
    ];

    protected $casts = [
        'taille' => 'integer',
    ];

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    public function anneeUniversitaire(): BelongsTo
    {
        return $this->belongsTo(AnneeUniversitaire::class);
    }

    public function televersePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Taille lisible : « 245 Ko ».
     */
    public function getTailleLisibleAttribute(): string
    {
        $octets = $this->taille;

        if ($octets >= 1048576) {
            return round($octets / 1048576, 1).' Mo';
        }
        if ($octets >= 1024) {
            return round($octets / 1024, 1).' Ko';
        }

        return $octets.' o';
    }
}
