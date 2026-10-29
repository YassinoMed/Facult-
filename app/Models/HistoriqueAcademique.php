<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Journal d'audit académique — immuable (created_at uniquement, jamais updated).
 * Ne jamais y enregistrer de secrets (mots de passe, tokens, cookies).
 */
class HistoriqueAcademique extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'historique_academique';

    protected $fillable = [
        'etudiant_id',
        'utilisateur_id',
        'action',
        'entite',
        'entite_id',
        'ancienne_valeur',
        'nouvelle_valeur',
        'adresse_ip',
        'user_agent',
        'source_systeme',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilisateur_id');
    }

    /**
     * Libellé humain de l'action tracée (logique hors des vues Blade).
     */
    public function getActionLibelleAttribute(): string
    {
        return match ($this->action) {
            self::ACTION_CONNEXION => 'Connexion au back-office',
            self::ACTION_CONSULTATION => 'Consultation du dossier',
            self::ACTION_CREATION_DOSSIER => 'Création du dossier',
            self::ACTION_MODIFICATION_DOSSIER => 'Modification du dossier',
            self::ACTION_SUPPRESSION_DOSSIER => 'Suppression du dossier',
            self::ACTION_CREATION_INSCRIPTION => 'Création d\'une inscription académique',
            self::ACTION_MODIFICATION_INSCRIPTION => 'Modification d\'une inscription',
            self::ACTION_SUPPRESSION_INSCRIPTION => 'Suppression d\'une inscription',
            self::ACTION_CREATION_NOTE => 'Saisie d\'une note',
            self::ACTION_MODIFICATION_NOTE => 'Correction d\'une note',
            self::ACTION_SUPPRESSION_NOTE => 'Suppression d\'une note',
            self::ACTION_VERROUILLAGE_NOTE => 'Verrouillage / déverrouillage d\'une note',
            self::ACTION_UPLOAD_DOCUMENT => 'Téléversement d\'un document',
            self::ACTION_TELECHARGEMENT_DOCUMENT => 'Téléchargement d\'un document',
            self::ACTION_SUPPRESSION_DOCUMENT => 'Suppression d\'un document',
            self::ACTION_ARCHIVE_VIEW => 'Consultation de l\'archive historique',
            self::ACTION_SENSITIVE_VIEW => 'Consultation d\'un dossier sensible',
            self::ACTION_SENSITIVE_DOWNLOAD => 'Téléchargement sensible',
            self::ACTION_SENSITIVE_CREATE => 'Création d\'un dossier sensible',
            self::ACTION_SENSITIVE_UPDATE => 'Modification d\'un dossier sensible',
            self::ACTION_SENSITIVE_DELETE => 'Suppression d\'un dossier sensible',
            self::ACTION_MEDICAL_VIEW => 'Consultation d\'un dossier médical',
            self::ACTION_MEDICAL_DOWNLOAD => 'Téléchargement médical',
            self::ACTION_DISCIPLINARY_VIEW => 'Consultation d\'un dossier disciplinaire',
            self::ACTION_DISCIPLINARY_DOWNLOAD => 'Téléchargement disciplinaire',
            self::ACTION_CORRECTION_PROPOSEE => 'Correction proposée',
            self::ACTION_CORRECTION_APPROUVEE => 'Correction approuvée',
            self::ACTION_CORRECTION_REJETEE => 'Correction rejetée',
            self::ACTION_COMMENTAIRE_INSTRUCTION => 'Note d’instruction administrative',
            self::ACTION_ENSEIGNANT_CREATION => 'Création d’un enseignant',
            self::ACTION_ENSEIGNANT_MODIFICATION => 'Modification d’un enseignant',
            self::ACTION_ENSEIGNANT_SUPPRESSION => 'Suppression d’un enseignant',
            self::ACTION_MENS_CREATION => 'Création d’une référence MENS',
            self::ACTION_MENS_MODIFICATION => 'Modification d’une référence MENS',
            self::ACTION_MENS_SUPPRESSION => 'Suppression d’une référence MENS',
            'export_dossier' => 'Export du dossier',
            'student_document.rejected' => 'Pièce du dossier rejetée par la scolarité',
            'student_document.downloaded' => 'Pièce du dossier téléchargée',
            'student_document.uploaded' => 'Pièce du dossier transmise',
            'student_document.validated' => 'Pièce du dossier validée',
            'affectation.auto_assignee' => 'Affectation automatique à un groupe',
            'periodes-inscription.exception_used' => 'Ouverture exceptionnelle consommée',
            'periodes-inscription.periode_created' => 'Période d’inscription créée',
            'periodes-inscription.exception_created' => 'Ouverture exceptionnelle créée',
            'periodes-inscription.exception_revoked' => 'Ouverture exceptionnelle révoquée',
            'periodes-inscription.periode_extended' => 'Période d’inscription prolongée',
            'inscription.dossier_cree' => 'Dossier d’inscription créé',
            'inscription.dossier_cree_mens' => 'Dossier créé depuis le référentiel MENS',
            'periode_exception_id' => 'Ouverture exceptionnelle appliquée',
            default => $this->action,
        };
    }

    /* ── Actions normalisées du journal ── */

    public const ACTION_CONNEXION = 'connexion_admin';

    public const ACTION_CONSULTATION = 'consultation_dossier';

    public const ACTION_CREATION_DOSSIER = 'creation_dossier';

    public const ACTION_MODIFICATION_DOSSIER = 'modification_dossier';

    public const ACTION_SUPPRESSION_DOSSIER = 'suppression_dossier';

    public const ACTION_CREATION_INSCRIPTION = 'creation_inscription';

    public const ACTION_MODIFICATION_INSCRIPTION = 'modification_inscription';

    public const ACTION_SUPPRESSION_INSCRIPTION = 'suppression_inscription';

    public const ACTION_CREATION_NOTE = 'creation_note';

    public const ACTION_MODIFICATION_NOTE = 'modification_note';

    public const ACTION_SUPPRESSION_NOTE = 'suppression_note';

    public const ACTION_VERROUILLAGE_NOTE = 'verrouillage_note';

    public const ACTION_UPLOAD_DOCUMENT = 'upload_document';

    public const ACTION_TELECHARGEMENT_DOCUMENT = 'telechargement_document';

    public const ACTION_SUPPRESSION_DOCUMENT = 'suppression_document';

    public const ACTION_ARCHIVE_VIEW = 'archive_view';

    public const ACTION_SENSITIVE_VIEW = 'sensitive_view';

    public const ACTION_SENSITIVE_DOWNLOAD = 'sensitive_download';

    public const ACTION_SENSITIVE_CREATE = 'sensitive_create';

    public const ACTION_SENSITIVE_UPDATE = 'sensitive_update';

    public const ACTION_SENSITIVE_DELETE = 'sensitive_delete';

    public const ACTION_MEDICAL_VIEW = 'medical_view';

    public const ACTION_MEDICAL_DOWNLOAD = 'medical_download';

    public const ACTION_DISCIPLINARY_VIEW = 'disciplinary_view';

    public const ACTION_DISCIPLINARY_DOWNLOAD = 'disciplinary_download';

    public const ACTION_VERIFICATION_DOSSIER = 'verification_dossier';

    public const ACTION_CORRECTION_DOSSIER = 'correction_dossier';

    public const ACTION_CORRECTION_PROPOSEE = 'correction_proposee';

    public const ACTION_CORRECTION_APPROUVEE = 'correction_approuvee';

    public const ACTION_CORRECTION_REJETEE = 'correction_rejetee';

    public const ACTION_COMMENTAIRE_INSTRUCTION = 'commentaire_instruction';

    public const ACTION_ENSEIGNANT_CREATION = 'enseignant.cree';

    public const ACTION_ENSEIGNANT_MODIFICATION = 'enseignant.modifie';

    public const ACTION_ENSEIGNANT_SUPPRESSION = 'enseignant.supprime';

    public const ACTION_MENS_CREATION = 'mens.cree';

    public const ACTION_MENS_MODIFICATION = 'mens.modifie';

    public const ACTION_MENS_SUPPRESSION = 'mens.supprime';
}
