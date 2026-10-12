<?php

namespace App\Modules\StudentDossiers\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module StudentDossiers (§8) : registre des PDF générés. `storage_path`
 * masqué comme les documents originaux — téléchargement réservé aux
 * endpoints gated avec audit.
 */
class GeneratedDocument extends Model
{
    protected $table = 'generated_documents';

    protected $fillable = [
        'reference',
        'numero',
        'code_verification',
        'student_dossier_id',
        'type',
        'version',
        'statut',
        'storage_path',
        'sha256',
        'contenu_hash',
        'snapshot_donnees',
        'locale',
        'template_version_id',
        'emis_le',
        'emis_par',
        'approuve_le',
        'approuve_par',
        'valide_jusquau',
        'remplace_id',
        'lot_id',
        'revoque_le',
        'revoque_par',
        'motif_revocation',
        'duplicatas',
        'generated_by',
        'expires_at',
    ];

    /** Cycle de vie officiel (Phase 7 §53) : jamais de suppression. */
    public const STATUTS = ['valide', 'remplace', 'revoque'];

    protected $hidden = ['storage_path'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'expires_at' => 'datetime',
            'snapshot_donnees' => 'array',
            'emis_le' => 'datetime',
            'approuve_le' => 'datetime',
            'valide_jusquau' => 'datetime',
            'revoque_le' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function generePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function revoquePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoque_par');
    }
}
