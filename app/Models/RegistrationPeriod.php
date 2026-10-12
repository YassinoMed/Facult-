<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Période d'inscription (LOOP) : normale (ouverture planifiée) ou
 * exception (ouverture temporaire, explicite, limitée, auditée).
 * Portée nullable = tous (faculté/programme/niveau/type/étudiant).
 * Une exception individuelle (`etudiant_id`) est à usage unique.
 */
class RegistrationPeriod extends Model
{
    protected $table = 'registration_periods';

    protected $fillable = [
        'annee_universitaire_id', 'faculte_id', 'programme_id', 'niveau',
        'type_inscription', 'type_periode', 'nom', 'etudiant_id',
        'opened_at', 'closed_at', 'motif', 'statut', 'created_by', 'used_at',
    ];

    public const TYPES = ['normale', 'exception'];

    public const STATUTS = ['active', 'revoquee', 'utilisee'];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'closed_at' => 'datetime', 'used_at' => 'datetime'];
    }

    public function anneeUniversitaire(): BelongsTo
    {
        return $this->belongsTo(AnneeUniversitaire::class);
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class);
    }

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    public function creatrice(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Ouverte MAINTENANT : statut actif + fenêtre temporelle (serveur).
     */
    public function estOuverte(): bool
    {
        if ($this->statut !== 'active') {
            return false;
        }

        $maintenant = now();

        return $this->opened_at <= $maintenant && $maintenant <= $this->closed_at;
    }

    public function estException(): bool
    {
        return $this->type_periode === 'exception';
    }

    public function estIndividuelle(): bool
    {
        return $this->etudiant_id !== null;
    }

    /**
     * Statut calculé d'affichage (§3) : jamais une règle métier (les
     * gardes utilisent `estOuverte()`). CLOSING_SOON = moins de 48 h
     * avant fermeture, affichage uniquement.
     *
     * @return 'SCHEDULED'|'OPEN'|'CLOSING_SOON'|'CLOSED'|'CANCELLED'
     */
    public function statutCalcule(): string
    {
        if ($this->statut === 'revoquee') {
            return 'CANCELLED';
        }

        if ($this->statut === 'utilisee') {
            return 'CLOSED';
        }

        $maintenant = now();

        if ($this->opened_at > $maintenant) {
            return 'SCHEDULED';
        }

        if ($this->closed_at < $maintenant) {
            return 'CLOSED';
        }

        return $this->estBientotFermee() ? 'CLOSING_SOON' : 'OPEN';
    }

    public function estBientotFermee(): bool
    {
        return $this->statut === 'active'
            && $this->opened_at <= now()
            && $this->closed_at > now()
            && $this->closed_at <= now()->addHours(48);
    }
}
