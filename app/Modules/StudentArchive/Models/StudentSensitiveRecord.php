<?php

namespace App\Modules\StudentArchive\Models;

use App\Models\Etudiant;
use App\Models\User;
use App\Modules\StudentArchive\Enums\TypeDossierSensible;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Dossier sensible (§9-10) : métadonnées métier UNIQUEMENT (statuts, dates,
 * références, acteurs, lien GED) — minimum necessary, JAMAIS de contenu
 * médical détaillé. Fichiers : GED existante via (document_source,
 * document_id), jamais copiés. Soft delete : aucune destruction silencieuse.
 */
class StudentSensitiveRecord extends Model
{
    use SoftDeletes;

    protected $table = 'student_sensitive_records';

    protected $fillable = [
        'uuid', 'etudiant_id', 'type', 'titre', 'description', 'statut', 'niveau',
        'date_debut', 'date_fin', 'document_source', 'document_id',
        'retention_policy', 'retention_until', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'date_debut' => 'date',
            'date_fin' => 'date',
            'retention_until' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (StudentSensitiveRecord $record) {
            $record->uuid ??= (string) Str::uuid();
        });
    }

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function typeEnum(): TypeDossierSensible
    {
        return TypeDossierSensible::from($this->type);
    }
}
