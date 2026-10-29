<?php

namespace App\Modules\Evaluation\Models;

use App\Models\Etudiant;
use App\Models\InscriptionPedagogique;
use App\Models\User;
use App\Modules\Schedule\Models\Room;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Candidat extends Model
{
    protected $table = 'evaluation_candidats';

    /** Absence explicite : jamais un simple 0 (§10). */
    public const STATUT_NON_POINTE = 'non_pointe';

    /** Pointage initial « non pointé » (DM-02) + statuts d'émargement. */
    public const STATUTS = [
        'non_pointe',
        'present',
        'absent',
        'absent_provisoire',
        'absent_confirme',
        'absent_justifie',
        'absent_injustifie',
        'excuse',
        'retard',
        'regularisation_en_cours',
    ];

    protected $fillable = [
        'epreuve_id', 'etudiant_id', 'inscription_pedagogique_id', 'numero_anonyme',
        'salle_id', 'place', 'statut', 'justification', 'decide_par',
        'heure_arrivee', 'presence_provisoire',
    ];

    protected function casts(): array
    {
        return [
            // Une présence constatée reste PROVISOIRE tant qu'une décision
            // habilitée ne l'a pas tranchée (§2.9).
            'presence_provisoire' => 'boolean',
        ];
    }

    public function epreuve(): BelongsTo
    {
        return $this->belongsTo(Epreuve::class, 'epreuve_id');
    }

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    public function inscriptionPedagogique(): BelongsTo
    {
        return $this->belongsTo(InscriptionPedagogique::class, 'inscription_pedagogique_id');
    }

    public function decidePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decide_par');
    }

    public function salle(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'salle_id');
    }

    public function salleEffective(): ?Room
    {
        return $this->salle ?? $this->epreuve?->salle;
    }

    public function getPlaceAttribute(mixed $value): int|string|null
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        $str = (string) $value;

        return ctype_digit($str) ? (int) $str : $str;
    }

    public function note(): HasOne
    {
        return $this->hasOne(NoteEpreuve::class, 'candidat_id');
    }

    public function estAbsent(): bool
    {
        return in_array($this->statut, [
            'absent',
            'absent_provisoire',
            'absent_confirme',
            'absent_justifie',
            'absent_injustifie',
            'excuse',
        ], true);
    }

    public function estPointe(): bool
    {
        return $this->statut !== self::STATUT_NON_POINTE;
    }
}
