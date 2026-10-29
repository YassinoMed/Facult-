<?php

namespace App\Modules\StudentDossiers\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Module StudentDossiers (§2) : métadonnées d'un document confidentiel.
 * `storage_path` est masqué (hidden) : jamais exposé au client ni à
 * l'API — seuls les contrôleurs gated y accèdent via le disque privé.
 */
class StudentDocument extends Model
{
    protected $table = 'student_documents';

    public const STATUTS = ['pending', 'validated', 'rejected', 'expired', 'archived'];

    protected $fillable = [
        'student_dossier_id',
        'category',
        'document_type',
        'title',
        'description',
        'original_filename',
        'storage_path',
        'mime_type',
        'extension',
        'size',
        'sha256',
        'status',
        'uploaded_by',
        'validated_by',
        'validated_at',
        'rejected_reason',
        'expires_at',
    ];

    protected $hidden = ['storage_path'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'validated_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StudentDossier, $this>
     */
    public function dossier(): BelongsTo
    {
        return $this->belongsTo(StudentDossier::class, 'student_dossier_id');
    }

    /**
     * @return HasMany<StudentDocumentVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(StudentDocumentVersion::class, 'student_document_id')->orderBy('version');
    }

    /**
     * LOOP-UNIREGISTRE-DOCUMENTS-REUTILISABLES-01 : inscriptions annuelles
     * auxquelles ce document a été associé (réutilisation ou validation).
     *
     * @return HasMany<InscriptionDocument, $this>
     */
    public function associationsInscription(): HasMany
    {
        return $this->hasMany(InscriptionDocument::class, 'student_document_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function televersePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function validePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }
}
