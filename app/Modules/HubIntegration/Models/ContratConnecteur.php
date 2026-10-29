<?php

namespace App\Modules\HubIntegration\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Lot 18 — Contrat versionné d'un connecteur du hub d'intégration.
 *
 * - `connecteur` : clé du `DefinitionRegistry` (validée à la saisie) ;
 * - `schema` : structure attendue SAISIE par l'administration (jamais
 *   inventée ni déduite) ;
 * - `environnement` : production | sandbox — un contrat sandbox n'est
 *   activable QUE si l'établissement a explicitement autorisé le bac à
 *   sable (séparation des environnements de test, règle du cahier) ;
 * - cycle : brouillon → actif (activation tracée) → deprecie.
 */
class ContratConnecteur extends Model
{
    protected $table = 'contrats_connecteur';

    public const STATUTS = ['brouillon', 'actif', 'deprecie'];

    public const ENVIRONNEMENTS = ['production', 'sandbox'];

    /** @var array<int, string> */
    protected $fillable = [
        'connecteur', 'version', 'schema', 'statut', 'environnement',
        'changelog', 'cree_par', 'active_par', 'active_le',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['schema' => 'array', 'active_le' => 'datetime'];
    }

    public function activePar()
    {
        return $this->belongsTo(User::class, 'active_par');
    }
}
