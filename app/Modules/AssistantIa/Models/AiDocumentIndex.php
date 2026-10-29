<?php

namespace App\Modules\AssistantIa\Models;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Entrée dans l'index de connaissances documentaires RAG de UniRegistre AI.
 */
class AiDocumentIndex extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'ai_documents_index';

    protected $fillable = [
        'source',
        'titre',
        'chemin_fichier',
        'index_morceau',
        'contenu_morceau',
        'empreinte_sha256',
        'version_indexeur',
        'est_sensible',
        'permission_requise',
        'statut_document',
        'domaine_metier',
        'version_document',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'index_morceau' => 'integer',
            'version_indexeur' => 'integer',
            'est_sensible' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function scopeValide(Builder $query): Builder
    {
        return $query->where('statut_document', 'valide');
    }

    public function scopeSansObsoletes(Builder $query): Builder
    {
        return $query->where('statut_document', '!=', 'obsolete');
    }

    public function scopeParDomaine(Builder $query, string $domaine): Builder
    {
        return $query->where('domaine_metier', $domaine);
    }

    /**
     * Scope filtrant les documents selon les habilitations de l'utilisateur.
     */
    public function scopeAccessiblePar(Builder $query, ?User $utilisateur): Builder
    {
        if ($utilisateur === null) {
            return $query->where('est_sensible', false)->whereNull('permission_requise');
        }

        if ($utilisateur->estSuperAdmin()) {
            return $query;
        }

        return $query->where(function (Builder $sub) use ($utilisateur) {
            $sub->where('est_sensible', false)
                ->orWhere(function (Builder $sensibleSub) use ($utilisateur) {
                    if ($utilisateur->hasPermission('assistant_ia.sources_restreintes')) {
                        $sensibleSub->where('est_sensible', true);
                    } else {
                        $sensibleSub->whereRaw('1 = 0');
                    }
                });
        })->where(function (Builder $permSub) use ($utilisateur) {
            $permSub->whereNull('permission_requise')
                ->orWhere(function (Builder $pSub) use ($utilisateur) {
                    $pSub->whereNotNull('permission_requise')
                        ->whereIn('permission_requise', Permissions::pourRole($utilisateur->role ?? ''));
                });
        });
    }
}
