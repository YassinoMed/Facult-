<?php

namespace App\Models;

use App\Modules\Enseignement\Models\SousGroupe;
use App\Modules\Schedule\Models\Group;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Affectation historisée étudiant → groupe (§17-18) : une seule active
 * à la fois ; `etudiants.groupe_id` reste le pointeur courant.
 */
class AffectationGroupe extends Model
{
    protected $table = 'affectations_groupe';

    protected $fillable = [
        'etudiant_id', 'groupe_id', 'sous_groupe_id', 'annee_universitaire_id',
        'date_debut', 'date_fin', 'statut', 'verrouille', 'confirme_le', 'confirme_par',
        'motif', 'auteur_id',
    ];

    protected function casts(): array
    {
        return ['date_debut' => 'date', 'date_fin' => 'date', 'verrouille' => 'boolean', 'confirme_le' => 'datetime'];
    }

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    public function groupe(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function sousGroupe(): BelongsTo
    {
        return $this->belongsTo(SousGroupe::class, 'sous_groupe_id');
    }

    public function anneeUniversitaire(): BelongsTo
    {
        return $this->belongsTo(AnneeUniversitaire::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }

    public function scopeActives($query)
    {
        return $query->where('statut', 'active');
    }
}
