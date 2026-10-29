<?php

namespace App\Modules\AiAssistant\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Métrique IA non sensible (Lot 11, table ai_metrics).
 */
class AiMetrique extends Model
{
    public const ETAT_OK = 'ok';

    public const ETAT_ECHEC = 'echec';

    public const ETAT_REFUSE = 'refuse';

    public const ETAT_PLAFOND = 'plafond';

    protected $table = 'ai_metrics';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'conversation_id', 'utilisateur_id', 'fournisseur', 'modele', 'outil',
        'etat', 'code_erreur', 'latence_ms', 'jetons_entree', 'jetons_sortie',
    ];

    public function scopeParUtilisateur(Builder $query, int $utilisateurId): Builder
    {
        return $query->where('utilisateur_id', $utilisateurId);
    }
}
