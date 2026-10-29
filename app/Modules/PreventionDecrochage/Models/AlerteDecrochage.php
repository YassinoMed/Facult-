<?php

namespace App\Modules\PreventionDecrochage\Models;

use App\Models\Etudiant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lot 15 — Alerte de prévention du décrochage.
 *
 * Une alerte est un SIGNAL pour accompagnement humain :
 * - JAMAIS une modification de note, de décision ou de statut d'inscription ;
 * - flux humain : generee → examinee (examen) → cloturee (clôture tracée) ;
 * - `preuve` embarque les sources datées (ids, dates, extraits) pour que
 *   le suivi reste vérifiable ;
 * - `retention_a` reste NULL tant qu'aucune politique de rétention n'est
 *   approuvée (jamais inventée).
 */
class AlerteDecrochage extends Model
{
    protected $table = 'alertes_decrochage';

    public const STATUTS = ['generee', 'examinee', 'cloturee'];

    /** @var array<int, string> */
    protected $fillable = [
        'regle_id', 'etudiant_id', 'annee_universitaire_id',
        'valeur', 'seuil', 'preuve', 'statut',
        'commentaire_examen', 'examen_par', 'examine_le',
        'motif_cloture', 'cloturee_par', 'cloturee_le', 'retention_a',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valeur' => 'decimal:2',
            'seuil' => 'decimal:2',
            'preuve' => 'array',
            'examine_le' => 'datetime',
            'cloturee_le' => 'datetime',
            'retention_a' => 'datetime',
        ];
    }

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    public function regle(): BelongsTo
    {
        return $this->belongsTo(RegleDecrochage::class, 'regle_id');
    }

    public function examineePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'examen_par');
    }

    public function clotureePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cloturee_par');
    }

    public function suivis(): HasMany
    {
        return $this->hasMany(SuiviDecrochage::class, 'alerte_id');
    }
}
