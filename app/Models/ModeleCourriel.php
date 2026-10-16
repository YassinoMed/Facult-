<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modèle e-mail du studio (LOOP-MAIL-TEMPLATE-STUDIO-03) : cycle de vie
 * explicite BROUILLON → À_VALIDER → PUBLIÉ → ARCHIVÉ. Les schémas vivent
 * dans les versions immuables ; `verrou_systeme` protège les modèles
 * critiques (jamais modifiables via le studio).
 */
class ModeleCourriel extends Model
{
    protected $table = 'modeles_courriel';

    public const STATUT_BROUILLON = 'brouillon';

    public const STATUT_A_VALIDER = 'a_valider';

    public const STATUT_PUBLIE = 'publie';

    public const STATUT_ARCHIVE = 'archive';

    public const STATUTS = [
        self::STATUT_BROUILLON,
        self::STATUT_A_VALIDER,
        self::STATUT_PUBLIE,
        self::STATUT_ARCHIVE,
    ];

    protected $fillable = [
        'code',
        'nom',
        'categorie',
        'statut',
        'version_courante',
        'theme_id',
        'verrou_systeme',
    ];

    protected function casts(): array
    {
        return [
            'version_courante' => 'integer',
            'verrou_systeme' => 'boolean',
        ];
    }

    /**
     * @return HasMany<VersionModeleCourriel, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(VersionModeleCourriel::class, 'modele_id')->orderBy('numero');
    }

    public function theme(): BelongsTo
    {
        return $this->belongsTo(ThemeCourriel::class, 'theme_id');
    }

    public function derniereVersion(): ?VersionModeleCourriel
    {
        return $this->versions()->reorder('numero', 'desc')->first();
    }

    public function versionPubliee(): ?VersionModeleCourriel
    {
        if ($this->version_courante === null) {
            return null;
        }

        return $this->versions()->where('numero', $this->version_courante)->first();
    }

    public function estModifiable(): bool
    {
        return ! $this->verrou_systeme && $this->statut !== self::STATUT_ARCHIVE;
    }
}
