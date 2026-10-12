<?php

namespace App\Modules\Calendar\Models;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvenementCalendrier extends Model
{
    protected $table = 'calendrier_evenements';

    public const TYPES = ['rentree', 'inscription', 'enseignement', 'examen', 'deliberation', 'vacances', 'ferie', 'evenement', 'administratif'];

    public const AUDIENCES = ['tous', 'etudiants', 'enseignants', 'personnel', 'programme', 'groupe'];

    protected $fillable = [
        'annee_universitaire_id', 'titre', 'type', 'date_debut', 'date_fin',
        'heure', 'description', 'lieu', 'audience', 'faculte_id',
        'programme_id', 'groupe_id', 'statut', 'created_by',
    ];

    protected function casts(): array
    {
        return ['date_debut' => 'date', 'date_fin' => 'date'];
    }

    public function anneeUniversitaire(): BelongsTo
    {
        return $this->belongsTo(AnneeUniversitaire::class);
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class);
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    public function groupe(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'groupe_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
