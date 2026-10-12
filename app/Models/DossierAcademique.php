<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dossier académique (archive) d'un étudiant — un seul par étudiant.
 */
class DossierAcademique extends Model
{
    protected $table = 'archives_etudiants';

    protected $fillable = [
        'etudiant_id',
        'matricule',
        'date_archivage',
        'statut',
        'observation',
    ];

    protected $casts = [
        'date_archivage' => 'date',
    ];

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    /**
     * Inscriptions académiques historisées (les plus récentes en premier).
     */
    public function inscriptions()
    {
        return $this->hasMany(InscriptionAcademique::class, 'etudiant_id', 'etudiant_id');
    }
}
