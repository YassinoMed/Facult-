<?php

namespace App\Modules\StudentArchive\Enums;

/**
 * Types de dossier sensible (§8) : UNE table, type contrôlé — jamais de
 * table par type. `medical` = métadonnées administratives UNIQUEMENT
 * (certificat reçu, période, aménagement) ; JAMAIS diagnostic/traitement.
 */
enum TypeDossierSensible: string
{
    case Medical = 'medical';
    case Disciplinaire = 'disciplinaire';
    case SituationParticuliere = 'situation_particuliere';
    case AdministratifRestreint = 'administratif_restreint';

    public function permissionLecture(): string
    {
        return match ($this) {
            self::Medical => 'student_archive.medical.view',
            self::Disciplinaire => 'student_archive.disciplinary.view',
            default => 'student_archive.sensitive.view',
        };
    }

    public function permissionTelechargement(): string
    {
        return match ($this) {
            self::Medical => 'student_archive.medical.download',
            self::Disciplinaire => 'student_archive.disciplinary.download',
            default => 'student_archive.sensitive.view',
        };
    }

    public function libelle(): string
    {
        return match ($this) {
            self::Medical => 'Médical',
            self::Disciplinaire => 'Disciplinaire',
            self::SituationParticuliere => 'Situation particulière',
            self::AdministratifRestreint => 'Administratif restreint',
        };
    }
}
