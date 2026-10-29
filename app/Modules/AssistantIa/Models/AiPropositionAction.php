<?php

namespace App\Modules\AssistantIa\Models;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\AssistantIa\DTO\ActeurPortail;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Modèle de proposition d'action sensible nécessitant approbation humaine (2-person rule / 4-eyes).
 */
class AiPropositionAction extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'ai_propositions_actions';

    protected $fillable = [
        'uuid',
        'user_id',
        'etudiant_id',
        'teacher_id',
        'approbateur_id',
        'faculte_id',
        'portail',
        'type_action',
        'ressource_type',
        'ressource_id',
        'description',
        'parametres',
        'resume_impact',
        'niveau_risque',
        'statut',
        'empreinte_sha256',
        'expire_a',
        'execute_a',
        'decision_commentaire',
    ];

    protected function casts(): array
    {
        return [
            'parametres' => 'array',
            'expire_a' => 'datetime',
            'execute_a' => 'datetime',
            'ressource_id' => 'integer',
            'faculte_id' => 'integer',
            'etudiant_id' => 'integer',
            'teacher_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AiPropositionAction $proposition) {
            if (empty($proposition->uuid)) {
                $proposition->uuid = (string) Str::uuid();
            }

            if (empty($proposition->empreinte_sha256)) {
                $proposition->empreinte_sha256 = self::genererEmpreinte(
                    $proposition->type_action,
                    $proposition->ressource_type,
                    $proposition->ressource_id,
                    $proposition->parametres ?? []
                );
            }

            if (empty($proposition->expire_a)) {
                $duree = (int) config('assistant-ia.approbations.duree_validite_minutes', 60);
                $proposition->expire_a = now()->addMinutes($duree);
            }
        });
    }

    /**
     * Calcule l'empreinte cryptographique sha256 inviolable des paramètres de l'action.
     *
     * @param  array<string, mixed>  $parametres
     */
    public static function genererEmpreinte(string $typeAction, string $ressourceType, int $ressourceId, array $parametres): string
    {
        ksort($parametres);
        $payload = json_encode([
            'type_action' => $typeAction,
            'ressource_type' => $ressourceType,
            'ressource_id' => $ressourceId,
            'parametres' => $parametres,
        ], JSON_UNESCAPED_UNICODE);

        return hash('sha256', (string) $payload);
    }

    /**
     * Vérifie si le payload a été altéré depuis la proposition.
     */
    public function verifierIntegrite(): bool
    {
        $calculee = self::genererEmpreinte(
            $this->type_action,
            $this->ressource_type,
            $this->ressource_id,
            $this->parametres ?? []
        );

        return hash_equals($this->empreinte_sha256, $calculee);
    }

    public function estExpiree(): bool
    {
        return $this->expire_a->isPast();
    }

    public function estEnAttente(): bool
    {
        return $this->statut === 'pending_approval' && ! $this->estExpiree();
    }

    public function demandeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class, 'etudiant_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    public function approbateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approbateur_id');
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class, 'faculte_id');
    }

    public function scopePourFaculte(Builder $query, ?int $faculteId): Builder
    {
        if ($faculteId === null) {
            return $query;
        }

        return $query->where(function ($sub) use ($faculteId) {
            $sub->whereNull('faculte_id')->orWhere('faculte_id', $faculteId);
        });
    }

    public function scopePourActeur(Builder $query, ActeurPortail $acteur): Builder
    {
        if ($acteur->estAdmin()) {
            return $query->where('user_id', $acteur->id)->where('portail', 'admin');
        }

        if ($acteur->estEnseignant()) {
            return $query->where('teacher_id', $acteur->id)->where('portail', 'enseignant');
        }

        if ($acteur->estEtudiant()) {
            return $query->where('etudiant_id', $acteur->id)->where('portail', 'etudiant');
        }

        return $query->whereRaw('1 = 0');
    }
}
