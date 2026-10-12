<?php

namespace App\Models;

use App\Modules\Schedule\Models\Teacher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle de demande administrative / pédagogique formulée par un enseignant (§7).
 */
class TeacherRequest extends Model
{
    protected $table = 'teacher_requests';

    protected $fillable = [
        'reference',
        'teacher_id',
        'faculte_id',
        'type_demande',
        'objet',
        'description',
        'statut',
        'reponse',
        'piece_jointe_chemin',
        'piece_jointe_nom',
        'traitee_par',
        'traitee_le',
    ];

    protected function casts(): array
    {
        return [
            'traitee_le' => 'datetime',
        ];
    }

    public const TYPES = [
        'conge' => 'Demande de congé / absence',
        'attestation' => 'Attestation de travail / fonctions',
        'ordre_mission' => 'Ordre de mission',
        'materiel' => 'Demande de matériel / ressources',
        'regularisation' => 'Régularisation administrative',
        'autre' => 'Autre demande',
    ];

    public const STATUTS = [
        'brouillon' => 'Brouillon',
        'soumise' => 'Soumise',
        'en_cours' => 'En cours d\'instruction',
        'en_attente_complement' => 'En attente de complément',
        'approuvee' => 'Approuvée',
        'refusee' => 'Refusée',
        'annulee' => 'Annulée',
    ];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class, 'faculte_id');
    }

    public function traiteePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'traitee_par');
    }

    public function typeLibelle(): string
    {
        return self::TYPES[$this->type_demande] ?? ucfirst($this->type_demande);
    }

    public function statutLibelle(): string
    {
        return self::STATUTS[$this->statut] ?? ucfirst($this->statut);
    }

    public function peutEtreAnnulee(): bool
    {
        return in_array($this->statut, ['brouillon', 'soumise'], true);
    }

    public static function genererReference(): string
    {
        $prefix = 'DEM-ENS-'.date('Y').'-';
        $dernierId = (int) (self::max('id') ?? 0) + 1;

        return $prefix.str_pad((string) $dernierId, 5, '0', STR_PAD_LEFT);
    }
}
