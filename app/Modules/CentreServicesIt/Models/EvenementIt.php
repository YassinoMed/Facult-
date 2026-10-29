<?php

namespace App\Modules\CentreServicesIt\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Journal des événements PROPRES à l'IT.
 *
 * Les transitions de statut restent journalisées par `TaskService`
 * (`task_evenements`). Cette table porte la création, l'escalade et
 * l'affectation vues depuis le centre de services, avec une clé
 * d'idempotence pour les escalades rejouées.
 */
class EvenementIt extends Model
{
    protected $table = 'it_evenements';

    protected $fillable = [
        'ticket_id', 'type', 'cle_idempotence', 'de', 'vers', 'message', 'auteur_id',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(TicketIt::class, 'ticket_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
