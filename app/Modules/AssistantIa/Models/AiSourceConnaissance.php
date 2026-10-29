<?php

namespace App\Modules\AssistantIa\Models;

use App\Modules\AssistantIa\Services\JournalisationIa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Modèle de gestion d'une source de connaissance du Knowledge Hub UniRegistre AI.
 *
 * @property int $id
 * @property string $uuid
 * @property string $nom
 * @property string|null $description
 * @property string $type
 * @property string $origine
 * @property string $statut
 * @property string $politique_actualisation
 * @property Carbon|null $derniere_synchronisation_a
 * @property string|null $derniere_erreur
 * @property string|null $derniere_empreinte
 * @property int $nombre_documents
 * @property int $nombre_morceaux
 * @property array|null $metadata
 */
class AiSourceConnaissance extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPES_VALIDES = [
        'project_code',
        'internal_document',
        'technical_documentation',
        'business_procedure',
        'loop_report',
        'approved_web_source',
    ];

    public const STATUTS_VALIDES = [
        'actif',
        'suspendu',
        'erreur',
    ];

    protected $table = 'ai_sources_connaissances';

    protected $fillable = [
        'uuid',
        'nom',
        'description',
        'type',
        'origine',
        'statut',
        'politique_actualisation',
        'derniere_synchronisation_a',
        'derniere_erreur',
        'derniere_empreinte',
        'nombre_documents',
        'nombre_morceaux',
        'metadata',
    ];

    protected $casts = [
        'derniere_synchronisation_a' => 'datetime',
        'nombre_documents' => 'integer',
        'nombre_morceaux' => 'integer',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $source) {
            if (empty($source->uuid)) {
                $source->uuid = (string) Str::uuid();
            }
        });
    }

    public function estActive(): bool
    {
        return $this->statut === 'actif';
    }

    public function estSuspendue(): bool
    {
        return $this->statut === 'suspendu';
    }

    public function enErreur(): bool
    {
        return $this->statut === 'erreur';
    }

    public function marquerSucces(int $docs, int $morceaux, ?string $empreinte = null): self
    {
        $this->update([
            'statut' => 'actif',
            'derniere_synchronisation_a' => now(),
            'derniere_erreur' => null,
            'derniere_empreinte' => $empreinte ?? $this->derniere_empreinte,
            'nombre_documents' => $docs,
            'nombre_morceaux' => $morceaux,
        ]);

        return $this;
    }

    public function marquerErreur(string $messageErreur): self
    {
        // Purge systématique des secrets et tokens avant stockage
        $erreurExpurgee = JournalisationIa::masquerDonneesSensibles($messageErreur);

        $this->update([
            'statut' => 'erreur',
            'derniere_erreur' => $erreurExpurgee,
        ]);

        return $this;
    }

    public function suspendre(): self
    {
        $this->update(['statut' => 'suspendu']);

        return $this;
    }

    public function reactiver(): self
    {
        $this->update(['statut' => 'actif']);

        return $this;
    }

    public function scopeActif(Builder $query): Builder
    {
        return $query->where('statut', 'actif');
    }

    public function scopeParType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Chunks RAG liés à cette source.
     */
    public function documentsIndex(): HasMany
    {
        return $this->hasMany(AiDocumentIndex::class, 'source', 'origine');
    }
}
