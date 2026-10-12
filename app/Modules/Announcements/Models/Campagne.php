<?php

namespace App\Modules\Announcements\Models;

use App\Models\Faculte;
use App\Models\User;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Campagne extends Model
{
    protected $table = 'campagnes';

    public const STATUTS = ['brouillon', 'planifiee', 'envoyee', 'annulee'];

    public const AUDIENCES = ['etudiants', 'personnel', 'tous'];

    protected $fillable = [
        'titre', 'message', 'audience', 'faculte_id', 'programme_id', 'groupe_id',
        'statut', 'cle_unique', 'planifiee_a', 'envoyee_a',
        'destinataires_estimes', 'envoyes', 'lus', 'created_by',
    ];

    protected function casts(): array
    {
        return ['planifiee_a' => 'datetime', 'envoyee_a' => 'datetime'];
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

    public function tauxLecture(): ?float
    {
        if ($this->envoyes === 0) {
            return null;
        }

        return round($this->lus / $this->envoyes * 100, 1);
    }
}
