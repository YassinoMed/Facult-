<?php

namespace App\Modules\Referentiel\Models;

use App\Models\Etudiant;
use App\Models\Faculte;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Parcours / programme (§8) : relié à l'établissement et, selon le
 * cas, au département, à la spécialité et au type de diplôme.
 */
class Programme extends Model
{
    /**
     * Statuts workflow LOT 2 §13 (source unique — jamais de chaînes
     * dispersées). `actif` booléen est dérivé (`statut === 'actif'`)
     * pour compatibilité des lecteurs existants.
     */
    public const STATUT_BROUILLON = 'brouillon';

    public const STATUT_ACTIF = 'actif';

    public const STATUT_INACTIF = 'inactif';

    public const STATUT_ARCHIVE = 'archive';

    public const STATUTS = [self::STATUT_BROUILLON, self::STATUT_ACTIF, self::STATUT_INACTIF, self::STATUT_ARCHIVE];

    protected $fillable = [
        'faculte_id', 'departement_id', 'specialite_id', 'type_diplome_id',
        'code', 'nom', 'intitule_officiel', 'sigle', 'description',
        'duree_annees', 'semestres_nombre', 'credits_requis', 'responsable',
        'mode', 'date_debut', 'date_fin', 'actif', 'statut', 'capacite',
    ];

    protected function casts(): array
    {
        return ['actif' => 'boolean', 'capacite' => 'integer', 'date_debut' => 'date', 'date_fin' => 'date'];
    }

    protected static function booted(): void
    {
        // `actif` reste la projection effective du statut (tous les
        // lecteurs existants filtrent dessus — synchronisé ici, en un
        // seul point, jamais dans les contrôleurs).
        static::saving(function (self $programme) {
            $programme->statut ??= self::STATUT_ACTIF;
            $programme->actif = $programme->statut === self::STATUT_ACTIF;
        });
    }

    /**
     * Programmes proposables (jamais un archivé/brouillon/inactif).
     */
    public function scopeActifs(Builder $query): Builder
    {
        return $query->where('statut', self::STATUT_ACTIF);
    }

    /**
     * Résolution canonique par code normalisé, optionally scopée
     * faculté (LOOP A4) — remplace les `where('code', …)` bruts des
     * définitions d'import : un code dupliqué inter-facultés (légalité
     * : unicité = faculte+code) ne se résout plus au hasard quand la
     * colonne `faculte` est fournie ; repli premier résultat sinon
     * (historique documenté, fail-closed pour les restreints via les
     * contrôles de périmètre des persisters).
     */
    public static function resoudreParCode(?string $code, ?string $faculteCode = null): ?self
    {
        $normalise = mb_strtoupper(trim((string) $code));

        if ($normalise === '') {
            return null;
        }

        $query = self::where('code', $normalise);

        if ($faculteCode !== null && trim($faculteCode) !== '') {
            $recherche = trim($faculteCode);
            $faculteId = Faculte::where('code', mb_strtoupper($recherche))->first()?->id
                ?? Faculte::where('nom', $recherche)->first()?->id;

            if ($faculteId === null) {
                return null;
            }

            $query->where('faculte_id', $faculteId);
        }

        return $query->first();
    }

    public function estArchive(): bool
    {
        return $this->statut === self::STATUT_ARCHIVE;
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class);
    }

    public function departement(): BelongsTo
    {
        return $this->belongsTo(Departement::class);
    }

    public function specialite(): BelongsTo
    {
        return $this->belongsTo(Specialite::class);
    }

    public function typeDiplome(): BelongsTo
    {
        return $this->belongsTo(TypeDiplome::class);
    }

    public function niveaux(): HasMany
    {
        return $this->hasMany(Niveau::class)->orderBy('ordre');
    }

    public function unitesEnseignement(): HasMany
    {
        return $this->hasMany(UniteEnseignement::class);
    }

    public function maquettes(): HasMany
    {
        return $this->hasMany(Maquette::class)->orderByDesc('version');
    }

    public function etudiants(): HasMany
    {
        return $this->hasMany(Etudiant::class, 'programme_id');
    }
}
