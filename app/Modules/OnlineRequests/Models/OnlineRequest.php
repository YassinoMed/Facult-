<?php

namespace App\Modules\OnlineRequests\Models;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Module OnlineRequests : demande en ligne. Le statut est une machine
 * à états serveur (RequestWorkflowService) — jamais mass-assignable
 * depuis le client (voir $fillable : aucun champ de workflow).
 */
class OnlineRequest extends Model
{
    protected $table = 'online_requests';

    public const STATUTS = [
        'draft', 'submitted', 'received', 'in_review',
        'waiting_student', 'approved', 'rejected', 'cancelled', 'completed',
    ];

    protected $fillable = [
        'reference', // serveur uniquement (jamais validé depuis le client)
        'etudiant_id',
        'request_type_id',
        'annee_universitaire_id',
        'objet',
        'description',
        'form_data',
        'version_workflow', // LOT 01 : version de la définition épinglée (serveur uniquement)
    ];

    /**
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'form_data' => 'array',
            'submitted_at' => 'datetime',
            'processed_at' => 'datetime',
            'completed_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Etudiant, $this>
     */
    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    /**
     * @return BelongsTo<RequestType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(RequestType::class, 'request_type_id');
    }

    /**
     * @return BelongsTo<AnneeUniversitaire, $this>
     */
    public function anneeUniversitaire(): BelongsTo
    {
        return $this->belongsTo(AnneeUniversitaire::class);
    }

    /**
     * @return BelongsTo<Faculte, $this>
     */
    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return HasMany<RequestAttachment, $this>
     */
    public function pieces(): HasMany
    {
        return $this->hasMany(RequestAttachment::class, 'online_request_id')->latest();
    }

    /**
     * @return HasMany<OnlineRequestDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(OnlineRequestDocument::class, 'online_request_id')->latest();
    }

    /**
     * Fil de discussion (Phase 9 §61) : ordre chronologique.
     *
     * @return HasMany<RequestMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(RequestMessage::class, 'online_request_id')->oldest();
    }

    /**
     * Échéance indicative (§16) : soumission + délai du type (calendaire).
     */
    public function echeance(): ?Carbon
    {
        if ($this->submitted_at === null) {
            return null;
        }

        return $this->submitted_at->copy()->addDays((int) $this->type->processing_days);
    }

    public function enRetard(): bool
    {
        $echeance = $this->echeance();

        return $echeance !== null
            && in_array($this->status, ['submitted', 'received', 'in_review', 'waiting_student'], true)
            && now()->greaterThan($echeance);
    }
}
