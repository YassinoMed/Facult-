<?php

namespace App\Modules\Alumni\Models;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Modules\Referentiel\Models\Programme;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Profil annuaire d'un alumni — UNIQUEMENT après consentement opt-in.
 *
 * Aucune donnée académique (notes, crédits, décisions, CIN, date de
 * naissance) n'est stockée ici. Les coordonnées privées ne sont visibles
 * que selon `visibilite` et jamais via une route employeur (il n'en
 * existe aucune).
 */
class ProfilAlumni extends Model
{
    protected $table = 'profils_alumni';

    public const VISIBILITE_AGREGATS = 'agregats';

    public const VISIBILITE_ANNUAIRE = 'annuaire';

    public const VISIBILITE_ANNUAIRE_CONTACT = 'annuaire_contact';

    public const VISIBILITES = [
        self::VISIBILITE_AGREGATS,
        self::VISIBILITE_ANNUAIRE,
        self::VISIBILITE_ANNUAIRE_CONTACT,
    ];

    /** Version du texte de consentement accepté (traçabilité). */
    public const VERSION_CONSENTEMENT = '2026-10-v1';

    protected $fillable = [
        'etudiant_id', 'diplome_id', 'faculte_id', 'programme_id',
        'nom_affichage', 'email_contact', 'telephone_contact',
        'poste_actuel', 'employeur', 'secteur', 'ville', 'pays', 'linkedin',
        'visibilite', 'consentement_le', 'consentement_version', 'retire_le',
    ];

    protected function casts(): array
    {
        return [
            'consentement_le' => 'datetime',
            'retire_le' => 'datetime',
        ];
    }

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    public function diplome(): BelongsTo
    {
        return $this->belongsTo(Diplome::class);
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class);
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    public function consentements(): HasMany
    {
        return $this->hasMany(ConsentementAlumni::class, 'profil_alumni_id')->latest('survenu_le');
    }

    public function estConsenti(): bool
    {
        return $this->consentement_le !== null && $this->retire_le === null;
    }

    public function estDansAnnuaire(): bool
    {
        return $this->estConsenti()
            && in_array($this->visibilite, [self::VISIBILITE_ANNUAIRE, self::VISIBILITE_ANNUAIRE_CONTACT], true);
    }

    public function partageSesContacts(): bool
    {
        return $this->estDansAnnuaire() && $this->visibilite === self::VISIBILITE_ANNUAIRE_CONTACT;
    }

    /**
     * Annuaire : consentement actif + visibilité annuaire.
     */
    public function scopeConsentis(Builder $query): Builder
    {
        return $query
            ->whereNotNull('consentement_le')
            ->whereNull('retire_le')
            ->whereIn('visibilite', [self::VISIBILITE_ANNUAIRE, self::VISIBILITE_ANNUAIRE_CONTACT]);
    }

    /**
     * Agrégats : tous les consentements actifs, y compris la visibilité
     * `agregats` (l'alumni accepte de compter, pas d'apparaître).
     */
    public function scopeConsentisPourAgregats(Builder $query): Builder
    {
        return $query->whereNotNull('consentement_le')->whereNull('retire_le');
    }

    public function scopePortee(Builder $query, ?int $faculteId): Builder
    {
        return $query->when($faculteId !== null, fn (Builder $q) => $q->where('faculte_id', $faculteId));
    }
}
