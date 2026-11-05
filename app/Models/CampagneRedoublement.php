<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Campagne de redoublement de masse persistée (constat A3).
 *
 * Une campagne porte sa référence stable, ses critères de cohorte (sans
 * donnée nominative), son statut d'avancement et les agrégats de son bilan.
 * Les détails nominatifs ne sont JAMAIS stockés ici : ils restent dans la
 * réponse HTTP autorisée et les tables métier d'origine.
 */
class CampagneRedoublement extends Model
{
    protected $table = 'campagnes_redoublement';

    public const STATUT_PREPAREE = 'preparee';

    public const STATUT_EN_FILE = 'en_file';

    public const STATUT_EN_COURS = 'en_cours';

    public const STATUT_TERMINEE = 'terminee';

    public const STATUT_ECHEC = 'echec';

    public const MODE_SIMULATION = 'simulation';

    public const MODE_EXECUTION = 'execution';

    protected $fillable = [
        'reference', 'statut', 'mode',
        'annee_universitaire_id', 'programme_id', 'groupe_id', 'faculte_id',
        'ignorer_alertes_mineures',
        'dossiers_total', 'dossiers_traites',
        'bilan', 'erreur', 'lancee_par',
        'demarree_a', 'terminee_a',
    ];

    protected function casts(): array
    {
        return [
            'bilan' => 'array',
            'ignorer_alertes_mineures' => 'boolean',
            'dossiers_total' => 'integer',
            'dossiers_traites' => 'integer',
            'demarree_a' => 'datetime',
            'terminee_a' => 'datetime',
        ];
    }

    /** @return BelongsTo<AnneeUniversitaire, $this> */
    public function anneeUniversitaire(): BelongsTo
    {
        return $this->belongsTo(AnneeUniversitaire::class, 'annee_universitaire_id');
    }

    /** @return BelongsTo<User, $this> */
    public function lanceePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lancee_par');
    }

    public function estTerminee(): bool
    {
        return in_array($this->statut, [self::STATUT_TERMINEE, self::STATUT_ECHEC], true);
    }
}
