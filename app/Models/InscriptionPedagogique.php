<?php

namespace App\Models;

use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Inscription pédagogique (§23-24) : rattachement à semestre/UE/ECUE,
 * validé contre la maquette ACTIVE du programme.
 */
class InscriptionPedagogique extends Model
{
    protected $table = 'inscriptions_pedagogiques';

    public const STATUTS = ['inscrit', 'valide', 'ajourne'];

    protected $fillable = [
        'inscription_id', 'semestre_id', 'ue_id', 'matiere_id', 'statut',
    ];

    public function inscription(): BelongsTo
    {
        return $this->belongsTo(InscriptionAcademique::class, 'inscription_id');
    }

    public function semestre(): BelongsTo
    {
        return $this->belongsTo(SemestreLmd::class, 'semestre_id');
    }

    public function ue(): BelongsTo
    {
        return $this->belongsTo(UniteEnseignement::class, 'ue_id');
    }

    public function matiere(): BelongsTo
    {
        return $this->belongsTo(Matiere::class);
    }
}
