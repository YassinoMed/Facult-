<?php

namespace App\Models;

use App\Modules\Referentiel\Models\Departement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Profil du personnel administratif (§14, §43) : extension d'un compte
 * User — le dossier reste indépendant de l'authentification.
 */
class Personnel extends Model
{
    protected $fillable = [
        'user_id', 'matricule', 'fonction_id', 'departement_id',
        'bureau', 'date_entree', 'statut',
    ];

    protected function casts(): array
    {
        return ['date_entree' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fonction(): BelongsTo
    {
        return $this->belongsTo(Fonction::class);
    }

    public function departement(): BelongsTo
    {
        return $this->belongsTo(Departement::class);
    }
}
