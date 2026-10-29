<?php

namespace App\Modules\Referentiel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Niveau d'un programme — L1..D3, ordre pédagogique (§10).
 */
class Niveau extends Model
{
    protected $table = 'niveaux';

    protected $fillable = [
        'programme_id', 'code', 'nom', 'ordre', 'semestres_nombre',
        'credits_attendus', 'actif',
    ];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    /**
     * Résout un niveau par son code DANS un programme donné.
     *
     * `niveaux.code` n'est unique que par programme : plusieurs
     * programmes peuvent porter un « L1 » distinct. Toute résolution
     * globale est donc ambiguë et ne doit jamais être utilisée pour
     * rattacher une inscription, un étudiant ou un groupe.
     */
    public static function resoudreDansProgramme(?string $code, ?int $programmeId): ?self
    {
        $code = mb_strtoupper(trim((string) $code));

        if ($code === '' || $programmeId === null) {
            return null;
        }

        return static::query()
            ->where('programme_id', $programmeId)
            ->where('code', $code)
            ->first();
    }

    /**
     * Résolution de repli SANS programme : uniquement si le code désigne
     * un niveau globalement unique. Retourne null en cas d'ambiguïté —
     * jamais de sélection arbitraire du premier niveau venu.
     */
    public static function resoudreCodeUnique(?string $code): ?self
    {
        $code = mb_strtoupper(trim((string) $code));

        if ($code === '') {
            return null;
        }

        $niveaux = static::query()
            ->where('code', $code)
            ->limit(2)
            ->get();

        return $niveaux->count() === 1 ? $niveaux->first() : null;
    }

    public function semestres(): HasMany
    {
        return $this->hasMany(SemestreLmd::class)->orderBy('ordre');
    }
}
