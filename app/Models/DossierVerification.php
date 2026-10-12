<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LOOP vérificateur §13 : exécution persistée (verification_id,
 * verdict, score, métriques). Historique append-only ; les anomalies
 * courantes restent dédupliquées via `anomalies_qualite.cle_unique`.
 */
class DossierVerification extends Model
{
    protected $table = 'dossier_verifications';

    protected $fillable = [
        'etudiant_id', 'verdict', 'score', 'regles_verifiees',
        'regles_reussies', 'regles_echouees', 'anomalies_count', 'duree_ms', 'source', 'auteur_id',
    ];

    protected function casts(): array
    {
        return ['regles_echouees' => 'array'];
    }

    /** @return BelongsTo<Etudiant, $this> */
    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }
}
