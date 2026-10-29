<?php

namespace App\Modules\CentreServicesIt\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catégorie IT : taxonomie et cibles de service en JOURS OUVRÉS.
 *
 * Le catalogue livré par la migration est un jeu de valeurs par défaut
 * modifiable ; il ne constitue pas une norme métier opposable.
 */
class CategorieIt extends Model
{
    protected $table = 'it_categories';

    public const PRIORITES = ['P1', 'P2', 'P3'];

    protected $fillable = [
        'code', 'libelle', 'description',
        'sla_jours_p1', 'sla_jours_p2', 'sla_jours_p3',
        'priorite_defaut', 'actif', 'origine',
    ];

    protected function casts(): array
    {
        return [
            'sla_jours_p1' => 'integer',
            'sla_jours_p2' => 'integer',
            'sla_jours_p3' => 'integer',
            'actif' => 'boolean',
        ];
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(TicketIt::class, 'categorie_id');
    }

    public function slaPour(string $priorite): int
    {
        return match ($priorite) {
            'P1' => $this->sla_jours_p1,
            'P2' => $this->sla_jours_p2,
            default => $this->sla_jours_p3,
        };
    }
}
