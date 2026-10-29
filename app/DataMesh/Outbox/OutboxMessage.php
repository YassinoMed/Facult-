<?php

namespace App\DataMesh\Outbox;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Modèle Eloquent pour le Transactional Outbox du Data Mesh.
 *
 * @property int $id
 * @property string $type_evenement
 * @property string $type_agregat
 * @property int $agregat_id
 * @property string $cle_deduplication
 * @property array $charge_utile
 * @property string $statut
 * @property int $tentatives
 * @property string|null $derniere_erreur
 * @property Carbon|null $publie_le
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class OutboxMessage extends Model
{
    protected $table = 'data_mesh_outbox';

    public const STATUT_PENDING = 'pending';

    public const STATUT_PUBLISHED = 'published';

    public const STATUT_FAILED = 'failed';

    protected $fillable = [
        'type_evenement',
        'type_agregat',
        'agregat_id',
        'cle_deduplication',
        'charge_utile',
        'statut',
        'tentatives',
        'derniere_erreur',
        'publie_le',
    ];

    protected function casts(): array
    {
        return [
            'charge_utile' => 'array',
            'tentatives' => 'integer',
            'publie_le' => 'datetime',
        ];
    }

    public function scopeEnAttente(Builder $query): Builder
    {
        return $query->where('statut', self::STATUT_PENDING);
    }

    public function scopeEchoues(Builder $query): Builder
    {
        return $query->where('statut', self::STATUT_FAILED);
    }

    public function marquerPublie(): void
    {
        // Terminal idempotent : republier un message déjà publié est un
        // no-op (l'horodatage d'origine est conservé, pas de régression).
        if ($this->statut === self::STATUT_PUBLISHED) {
            return;
        }

        $this->update([
            'statut' => self::STATUT_PUBLISHED,
            'publie_le' => now(),
            'derniere_erreur' => null,
        ]);
    }

    public function marquerEchec(string $erreur): void
    {
        // Garde terminale : un message publié ne doit jamais retomber en
        // échec (ni perte ni republication fantôme après confirmation).
        if ($this->statut === self::STATUT_PUBLISHED) {
            throw new \LogicException('Outbox : transition interdite published → failed.');
        }

        $this->increment('tentatives');
        $this->update([
            'statut' => self::STATUT_FAILED,
            'derniere_erreur' => mb_substr($erreur, 0, 1000),
        ]);
    }
}
