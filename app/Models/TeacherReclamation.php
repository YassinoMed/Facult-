<?php

namespace App\Models;

use App\Modules\Attendance\Models\TeacherPresence;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle de réclamation / signalement formulé par un enseignant (§8).
 */
class TeacherReclamation extends Model
{
    protected $table = 'teacher_reclamations';

    protected $fillable = [
        'reference',
        'teacher_id',
        'teacher_presence_id',
        'faculte_id',
        'categorie',
        'objet',
        'description',
        'priorite',
        'statut',
        'reponse',
        'piece_jointe_chemin',
        'piece_jointe_nom',
        'historique',
        'traitee_par',
        'traitee_le',
    ];

    protected function casts(): array
    {
        return [
            'historique' => 'array',
            'traitee_le' => 'datetime',
        ];
    }

    public const CATEGORIES = [
        'emploi_du_temps' => 'Erreur dans l\'emploi du temps',
        'presence' => 'Présence / Pointage contesté',
        'surveillance' => 'Surveillance attribuée ou conflit',
        'document' => 'Document manquant ou erroné',
        'donnees_personnelles' => 'Information officielle / profil erroné',
        'autre' => 'Autre problème',
    ];

    public const STATUTS = [
        'ouverte' => 'Ouverte',
        'en_cours' => 'En cours de traitement',
        'resolue' => 'Résolue',
        'rejetee' => 'Rejetée',
        'fermee' => 'Clôturée',
    ];

    public const PRIORITES = [
        'normale' => 'Normale',
        'urgente' => 'Urgente',
    ];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    /**
     * @return BelongsTo<TeacherPresence, $this>
     */
    public function presence(): BelongsTo
    {
        return $this->belongsTo(TeacherPresence::class, 'teacher_presence_id');
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class, 'faculte_id');
    }

    public function traiteePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'traitee_par');
    }

    public function categorieLibelle(): string
    {
        return self::CATEGORIES[$this->categorie] ?? ucfirst($this->categorie);
    }

    public function statutLibelle(): string
    {
        return self::STATUTS[$this->statut] ?? ucfirst($this->statut);
    }

    public function prioriteLibelle(): string
    {
        return self::PRIORITES[$this->priorite] ?? ucfirst($this->priorite);
    }

    public function peutEtreCloturee(): bool
    {
        return $this->statut !== 'fermee';
    }

    public static function genererReference(): string
    {
        $prefix = 'REC-ENS-'.date('Y').'-';
        $dernierId = (int) (self::max('id') ?? 0) + 1;

        return $prefix.str_pad((string) $dernierId, 5, '0', STR_PAD_LEFT);
    }
}
