<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnomalieQualite extends Model
{
    protected $table = 'anomalies_qualite';

    public const GRAVITES = ['info', 'avertissement', 'erreur', 'critique'];

    public const STATUTS = ['ouverte', 'acquittee', 'en_cours', 'resolue', 'ignoree'];

    /** Transitions serveur. */
    public const TRANSITIONS = [
        'ouverte' => ['acquittee', 'en_cours', 'resolue', 'ignoree'],
        'acquittee' => ['en_cours', 'resolue', 'ignoree'],
        'en_cours' => ['resolue', 'ignoree', 'ouverte'],
        'resolue' => ['ouverte'],
        'ignoree' => ['ouverte'],
    ];

    protected $fillable = [
        'cle_unique', 'source', 'gravite', 'entite_type', 'entite_id',
        'entite_libelle', 'message', 'lien', 'action_recommandee', 'statut',
        'faculte_id',
        'assignee_a', 'resolution', 'resolue_par', 'resolue_a', 'derniere_detection_a',
    ];

    protected function casts(): array
    {
        return ['resolue_a' => 'datetime', 'derniere_detection_a' => 'datetime'];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_a');
    }

    public function peutTransiterVers(string $cible): bool
    {
        return in_array($cible, self::TRANSITIONS[$this->statut] ?? [], true);
    }

    public function estActive(): bool
    {
        return in_array($this->statut, ['ouverte', 'acquittee', 'en_cours'], true);
    }
}
