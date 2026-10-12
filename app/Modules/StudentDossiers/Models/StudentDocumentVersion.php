<?php

namespace App\Modules\StudentDossiers\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module StudentDossiers (§3) : une version = un fichier conservé.
 * Le remplacement n'écrase jamais l'historique.
 */
class StudentDocumentVersion extends Model
{
    protected $table = 'student_document_versions';

    protected $fillable = [
        'student_document_id',
        'version',
        'original_filename',
        'storage_path',
        'mime_type',
        'extension',
        'size',
        'sha256',
        'uploaded_by',
    ];

    protected $hidden = ['storage_path'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['size' => 'integer', 'version' => 'integer'];
    }

    /**
     * @return BelongsTo<StudentDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(StudentDocument::class, 'student_document_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function televersePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
