<?php

namespace App\Modules\Alumni\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Journal de consentement APPEND-ONLY.
 *
 * Un consentement ne se « modifie » pas : un changement crée une nouvelle
 * ligne (opt_in / opt_out). Toute mise à jour ou suppression est refusée
 * au niveau du modèle, pas seulement par convention.
 */
class ConsentementAlumni extends Model
{
    protected $table = 'consentements_alumni';

    public const ACTIONS = ['opt_in', 'opt_out'];

    protected $fillable = [
        'profil_alumni_id', 'action', 'version_texte', 'canal',
        'cle_idempotence', 'diplome_id', 'survenu_le',
    ];

    protected function casts(): array
    {
        return ['survenu_le' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException('Journal de consentement append-only : mise à jour interdite.'));
        static::deleting(fn () => throw new DomainException('Journal de consentement append-only : suppression interdite.'));
    }

    public function profil(): BelongsTo
    {
        return $this->belongsTo(ProfilAlumni::class, 'profil_alumni_id');
    }

    public function diplome(): BelongsTo
    {
        return $this->belongsTo(Diplome::class);
    }
}
