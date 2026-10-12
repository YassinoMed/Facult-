<?php

namespace App\Modules\Evaluation\Models;

use App\Models\Etudiant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Modèle de compostage d'anonymisation pour les épreuves d'évaluation (§2-§4).
 *
 * Lie un étudiant réel à un numéro de compostage unique pour une épreuve donnée.
 * Ne remplace pas l'identité en base, mais garantit l'anonymat à la saisie.
 */
class EvaluationCompostage extends Model
{
    protected $table = 'evaluation_compostages';

    protected $fillable = [
        'uuid',
        'epreuve_id',
        'etudiant_id',
        'evaluation_id',
        'student_id',
        'numero',
        'verrouille_at',
    ];

    protected function casts(): array
    {
        return [
            'verrouille_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (EvaluationCompostage $compostage): void {
            if (empty($compostage->uuid)) {
                $compostage->uuid = (string) Str::uuid();
            }

            // Miroirs de colonnes pour compatibilité universelle
            if (empty($compostage->evaluation_id) && ! empty($compostage->epreuve_id)) {
                $compostage->evaluation_id = $compostage->epreuve_id;
            } elseif (! empty($compostage->evaluation_id) && empty($compostage->epreuve_id)) {
                $compostage->epreuve_id = $compostage->evaluation_id;
            }

            if (empty($compostage->student_id) && ! empty($compostage->etudiant_id)) {
                $compostage->student_id = $compostage->etudiant_id;
            } elseif (! empty($compostage->student_id) && empty($compostage->etudiant_id)) {
                $compostage->etudiant_id = $compostage->student_id;
            }
        });
    }

    public function epreuve(): BelongsTo
    {
        return $this->belongsTo(Epreuve::class, 'epreuve_id');
    }

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class, 'etudiant_id');
    }

    public function estVerrouille(): bool
    {
        return $this->verrouille_at !== null;
    }

    public function scopePourEpreuve(Builder $query, int $epreuveId): Builder
    {
        return $query->where('epreuve_id', $epreuveId);
    }
}
