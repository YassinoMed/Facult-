<?php

namespace App\Modules\StudentDossiers\Models;

use App\Models\InscriptionAcademique;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LOOP-UNIREGISTRE-DOCUMENTS-REUTILISABLES-01 : état documentaire d'une
 * inscription académique annuelle.
 *
 * Le document physique et ses versions restent dans `student_documents` /
 * `student_document_versions` : cette ligne ne fait que les ASSOCIER à
 * l'année et conserver la décision prise (réutilisé, manquant, refusé,
 * expiré, non conforme, en attente). Aucun fichier n'est dupliqué.
 */
class InscriptionDocument extends Model
{
    protected $table = 'inscription_documents';

    public const DECISIONS = [
        'reutilise',
        'associe',
        'en_attente',
        'rejete',
        'expire',
        'non_conforme',
        'manquant',
    ];

    /** Décisions qui satisfont une pièce obligatoire pour l'année. */
    public const DECISIONS_VALIDES = ['reutilise', 'associe'];

    /** Décisions qui imposent une action de l'étudiant. */
    public const DECISIONS_A_FOURNIR = ['manquant', 'rejete', 'expire', 'non_conforme'];

    protected $fillable = [
        'inscription_academique_id',
        'student_document_id',
        'student_document_version_id',
        'document_type',
        'obligatoire',
        'decision',
        'motif',
        'snapshot',
        'decide_par',
        'decide_le',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'obligatoire' => 'boolean',
            'snapshot' => 'array',
            'decide_le' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<InscriptionAcademique, $this>
     */
    public function inscription(): BelongsTo
    {
        return $this->belongsTo(InscriptionAcademique::class, 'inscription_academique_id');
    }

    /**
     * @return BelongsTo<StudentDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(StudentDocument::class, 'student_document_id');
    }

    /**
     * @return BelongsTo<StudentDocumentVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(StudentDocumentVersion::class, 'student_document_version_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decide_par');
    }

    public function estValide(): bool
    {
        return in_array($this->decision, self::DECISIONS_VALIDES, true);
    }

    public function estAFournir(): bool
    {
        return $this->obligatoire && in_array($this->decision, self::DECISIONS_A_FOURNIR, true);
    }

    public function libelleDecision(): string
    {
        return match ($this->decision) {
            'reutilise' => 'Réutilisé automatiquement',
            'associe' => 'Validé pour cette inscription',
            'en_attente' => 'En attente de contrôle',
            'rejete' => 'Refusé — à remplacer',
            'expire' => 'Expiré — à renouveler',
            'non_conforme' => 'Non conforme — à remplacer',
            default => 'Manquant — à fournir',
        };
    }
}
