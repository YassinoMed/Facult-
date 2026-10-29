<?php

namespace App\Modules\CentreServicesIt\Models;

use App\Models\Faculte;
use App\Models\User;
use App\Modules\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ticket IT : métadonnées propres au centre de services.
 *
 * Le statut, l'affectation et la clôture ne sont PAS stockés ici : ils
 * sont lus sur la tâche liée (`tasks`). Une tâche absente produit le
 * statut explicite `indisponible`, jamais un statut inventé.
 */
class TicketIt extends Model
{
    protected $table = 'it_tickets';

    public const PRIORITES = ['P1', 'P2', 'P3'];

    /** Statuts de tâche considérés comme actifs. */
    public const STATUTS_ACTIFS = ['pending', 'in_progress', 'waiting', 'expired'];

    protected $fillable = [
        'reference', 'cle_unique', 'task_id', 'demandeur_id', 'faculte_id',
        'categorie_id', 'priorite', 'sujet', 'description',
        'echeance_sla', 'escalade_le',
    ];

    protected $appends = ['statut', 'en_retard'];

    protected function casts(): array
    {
        return [
            'echeance_sla' => 'datetime',
            'escalade_le' => 'datetime',
        ];
    }

    public function tache(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function demandeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'demandeur_id');
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class);
    }

    public function categorie(): BelongsTo
    {
        return $this->belongsTo(CategorieIt::class, 'categorie_id');
    }

    public function evenements(): HasMany
    {
        return $this->hasMany(EvenementIt::class, 'ticket_id')->latest('id');
    }

    protected function statut(): Attribute
    {
        return Attribute::get(fn (): string => $this->tache?->statut ?? 'indisponible');
    }

    protected function enRetard(): Attribute
    {
        return Attribute::get(fn (): bool => $this->tache !== null
            && in_array($this->tache->statut, self::STATUTS_ACTIFS, true)
            && $this->echeance_sla !== null
            && $this->echeance_sla->isPast());
    }

    /**
     * Périmètre facultaire : un acteur restreint ne voit que sa faculté.
     */
    public function scopePortee(Builder $query, ?int $faculteId): Builder
    {
        return $query->when($faculteId !== null, fn (Builder $q) => $q->where('faculte_id', $faculteId));
    }
}
