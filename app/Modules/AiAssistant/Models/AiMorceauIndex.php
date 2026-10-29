<?php

namespace App\Modules\AiAssistant\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Morceau documentaire indexé (Lot 6) — mappé sur la TABLE PARTAGÉE
 * `ai_documents_index`, définie par la migration 2026_10_23_110000 du
 * lot concurrent (CONSOMMÉE, pas redéfinie). Un seul index par projet ;
 * ce modèle n'est qu'un lecteur/écrivain contractuel du module
 * AiAssistant, sans couplage à leurs classes.
 *
 * Colonnes sensibles : `est_sensible` + `permission_requise` filtrent
 * la restitution AVANT tout renvoi au modèle de langue.
 */
class AiMorceauIndex extends Model
{
    use SoftDeletes;

    protected $table = 'ai_documents_index';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'source', 'titre', 'chemin_fichier', 'index_morceau',
        'contenu_morceau', 'empreinte_sha256', 'version_indexeur',
        'est_sensible', 'permission_requise',
    ];

    protected function casts(): array
    {
        return [
            'est_sensible' => 'boolean',
        ];
    }

    /**
     * Portée de restitution : limitée à l'espace autorisé du demandeur.
     * Par défaut (documentsRestreints=false), seuls les chunks publics
     * et sans permission spéciale sont retournables.
     */
    public function scopeRestituable(Builder $query, bool $documentsRestreints): Builder
    {
        if ($documentsRestreints) {
            return $query;
        }

        return $query->where('est_sensible', false)->whereNull('permission_requise');
    }
}
