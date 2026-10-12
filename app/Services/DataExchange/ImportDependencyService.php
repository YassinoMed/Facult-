<?php

namespace App\Services\DataExchange;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Referentiel\Models\Domaine;
use App\Modules\Referentiel\Models\Mention;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\Specialite;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Teacher;
use App\Services\Io\IoException;

/**
 * Service de contrôle du graphe des dépendances d'importation LMD/SALIMA (§2/§41-42).
 *
 * Ordre réel (issu des FK et résolutions du code, LOT 2) :
 *   ANNÉE → FACULTÉ/ÉTABLISSEMENT (+ TYPES-DIPLÔMES indépendants) →
 *   DÉPARTEMENT → DOMAINE → MENTION → SPÉCIALITÉ → PROGRAMMES (faculté ;
 *   spécialité/diplôme optionnels) → NIVEAU → SEMESTRE → UE (programme +
 *   semestre) → ECUE (UE recommandée ; standalone legacy sans FK) →
 *   ENSEIGNANTS / SALLES / GROUPES / ÉTUDIANTS → INSCRIPTIONS →
 *   ENSEIGNEMENTS → AFFECTATIONS → SESSIONS → EXAMENS → ÉPREUVES →
 *   NOTES (matière = ECUE, même table) → DÉLIBÉRATIONS → DÉCISIONS-JURY.
 * Référentiel externe indépendant : REFERENTIEL-MENS (aucune
 * dépendance). Dérivés calculés importables : PROGRESSIONS
 * (étudiants+niveaux+années), DOCUMENTS-ACADEMIQUES (étudiants).
 *
 * Aucune étape ne doit être validée si ses dépendances obligatoires sont absentes.
 */
class ImportDependencyService
{
    /**
     * Graphe des dépendances déclaratives par entité.
     *
     * @var array<string, array<int, string>>
     */
    private const DEPENDANCES = [
        'annees-universitaires' => [],
        'facultes' => [],
        'type-diplomes' => [],
        'departements' => ['facultes'],
        'domaines' => ['facultes'],
        'mentions' => ['domaines'],
        'specialites' => ['mentions'],
        // Programme : faculté requise ; spécialité/diplôme optionnels
        // (relations NULLABLES — pas de dépendance bloquante).
        'programmes' => ['facultes'],
        'niveaux' => ['programmes'],
        'semestres' => ['niveaux'],
        // UE : programme + semestre requis (FK).
        'ues' => ['programmes', 'semestres'],
        'ecues' => ['ues'],
        // Matières standalone : legacy sans FK (même table que ECUE).
        'matieres' => [],
        'enseignants' => ['facultes'],
        'salles' => ['facultes'],
        'groupes' => ['facultes'],
        'etudiants' => ['facultes'],
        'inscriptions-academiques' => ['etudiants', 'annees-universitaires'],
        'inscriptions-pedagogiques' => ['inscriptions-academiques', 'ecues'],
        'enseignements' => ['ecues', 'annees-universitaires'],
        'affectations-enseignants' => ['enseignements', 'enseignants'],
        'affectations-groupes' => ['etudiants', 'groupes'],
        'sessions-examen' => ['annees-universitaires'],
        'examens' => ['sessions-examen'],
        'epreuves' => ['examens'],
        // Notes : `matieres` = ECUE (même table, choix architectural
        // documenté — jamais de FK `ecue_id` dupliquée).
        'notes' => ['etudiants', 'matieres'],
        // Notes d'épreuve anonymes : contexte épreuve posé par la route
        // (pas de prérequis référentiel : épreuve + candidats vérifiés
        // par ligne dans la définition).
        'notes-epreuve-anonymes' => [],
        'deliberations' => ['sessions-examen', 'programmes'],
        'decisions-jury' => ['deliberations', 'etudiants'],
        // Référentiel externe indépendant (source MENS importée).
        'referentiel-mens' => [],
        // Plans d'études : programmes + UE + ECUE + année requis.
        'maquettes-plan' => ['programmes', 'ues', 'ecues', 'annees-universitaires'],
        // Dérivés calculés mais importables (pipeline complet).
        'progressions' => ['etudiants', 'niveaux', 'annees-universitaires'],
        'documents-academiques' => ['etudiants'],
    ];

    /**
     * Retourne les dépendances directes d'une entité.
     *
     * @return array<int, string>
     */
    public function dependancesDe(string $entite): array
    {
        return self::DEPENDANCES[$entite] ?? [];
    }

    /**
     * Vérifie si les données prérequises existent dans la base de données.
     * Prend en compte le périmètre de faculté de l'auteur si restreint.
     *
     * @return array<string, string> Liste des dépendances manquantes avec explications claires
     */
    public function verifierPrerequis(string $entite, ?int $auteurId = null): array
    {
        $dependances = $this->dependancesDe($entite);
        if ($dependances === []) {
            return [];
        }

        $auteur = $auteurId !== null ? User::find($auteurId) : null;
        $faculteRestreinteId = $auteur?->faculteRestreinteId();

        $manquantes = [];

        foreach ($dependances as $dep) {
            $satisfaite = match ($dep) {
                'facultes' => $faculteRestreinteId !== null
                    ? Faculte::whereKey($faculteRestreinteId)->exists()
                    : Faculte::exists(),
                'annees-universitaires' => AnneeUniversitaire::exists(),
                'departements' => $faculteRestreinteId !== null
                    ? Departement::where('faculte_id', $faculteRestreinteId)->exists()
                    : Departement::exists(),
                'domaines' => $faculteRestreinteId !== null
                    ? Domaine::where('faculte_id', $faculteRestreinteId)->exists()
                    : Domaine::exists(),
                'mentions' => $faculteRestreinteId !== null
                    ? Mention::whereHas('domaine', fn ($q) => $q->where('faculte_id', $faculteRestreinteId))->exists()
                    : Mention::exists(),
                'specialites' => Specialite::exists(),
                'programmes' => $faculteRestreinteId !== null
                    ? Programme::where('faculte_id', $faculteRestreinteId)->exists()
                    : Programme::exists(),
                'niveaux' => $faculteRestreinteId !== null
                    ? Niveau::whereHas('programme', fn ($q) => $q->where('faculte_id', $faculteRestreinteId))->exists()
                    : Niveau::exists(),
                'semestres' => SemestreLmd::exists(),
                'ues' => UniteEnseignement::exists(),
                // ECUE rattachées (standalone legacy exclu : une ECUE
                // importée doit avoir son rattachement académique).
                'ecues' => Matiere::whereNotNull('ue_id')->exists(),
                'matieres' => Matiere::exists(),
                'enseignants' => $faculteRestreinteId !== null
                    ? Teacher::where('faculte_id', $faculteRestreinteId)->exists()
                    : Teacher::exists(),
                'salles' => $faculteRestreinteId !== null
                    ? Room::where('faculte_id', $faculteRestreinteId)->exists()
                    : Room::exists(),
                'groupes' => $faculteRestreinteId !== null
                    ? Group::where('faculte_id', $faculteRestreinteId)->exists()
                    : Group::exists(),
                'etudiants' => $faculteRestreinteId !== null
                    ? Etudiant::where('faculte_id', $faculteRestreinteId)->exists()
                    : Etudiant::exists(),
                'inscriptions-academiques' => InscriptionAcademique::exists(),
                'enseignements' => Enseignement::exists(),
                'sessions-examen' => SessionExamen::exists(),
                'examens' => Examen::exists(),
                'deliberations' => Deliberation::exists(),
                default => true,
            };

            if (! $satisfaite) {
                $manquantes[$dep] = $this->messageExplicatif($dep, $entite);
            }
        }

        return $manquantes;
    }

    /**
     * Bloque l'import en lançant une exception si des dépendances sont absentes.
     *
     * @throws IoException
     */
    public function bloqueSiDependancesManquantes(string $entite, ?int $auteurId = null): void
    {
        $manquantes = $this->verifierPrerequis($entite, $auteurId);

        if ($manquantes !== []) {
            $details = implode(' ; ', $manquantes);
            throw IoException::malforme(
                "Dépendance préalable manquante pour l'import « {$entite} » : {$details}"
            );
        }
    }

    /**
     * Retourne l'état de préparation de toutes les entités du système.
     *
     * @return array<string, array{prete: bool, dependances: array<int, string>, manquantes: array<string, string>}>
     */
    public function etatToutesEntites(?int $auteurId = null): array
    {
        $etats = [];

        foreach (array_keys(self::DEPENDANCES) as $entite) {
            $manquantes = $this->verifierPrerequis($entite, $auteurId);
            $etats[$entite] = [
                'prete' => $manquantes === [],
                'dependances' => $this->dependancesDe($entite),
                'manquantes' => $manquantes,
            ];
        }

        return $etats;
    }

    private function messageExplicatif(string $dependance, string $entite): string
    {
        return match ($dependance) {
            'facultes' => "Aucune faculté ou établissement n'est enregistré. Vous devez importer ou créer la faculté cible avant « {$entite} ».",
            'annees-universitaires' => "Aucune année universitaire n'est enregistrée. Importez d'abord l'année universitaire.",
            'departements' => "Aucun département n'existe pour cette faculté. Importez d'abord les départements.",
            'domaines' => "Aucun domaine de formation n'est défini. Importez d'abord les domaines.",
            'mentions' => "Aucune mention n'est définie. Importez d'abord les mentions.",
            'programmes' => "Aucun parcours/programme n'est enregistré. Importez d'abord les parcours.",
            'niveaux' => "Aucun niveau (L1, L2...) n'est configuré. Importez d'abord les niveaux du parcours.",
            'semestres' => "Aucun semestre LMD n'est configuré. Importez d'abord les semestres.",
            'ues' => "Aucune Unité d'Enseignement (UE) n'existe. Importez d'abord les UE.",
            'ecues' => "Aucune ECUE/matière n'est enregistrée. Importez d'abord les ECUE.",
            'matieres' => "Aucune matière n'est enregistrée. Importez d'abord les matières.",
            'enseignants' => "Aucun enseignant n'est enregistré. Importez d'abord les enseignants.",
            'groupes' => "Aucun groupe n'est enregistré. Importez d'abord les groupes.",
            'etudiants' => "Aucun étudiant n'est enregistré. Importez d'abord les étudiants.",
            'inscriptions-academiques' => "Aucune inscription académique n'existe. Importez d'abord les inscriptions.",
            'enseignements' => "Aucun enseignement n'est configuré. Importez d'abord les enseignements.",
            'sessions-examen' => "Aucune session d'examen n'existe. Importez d'abord les sessions d'examen.",
            'examens' => "Aucun examen n'est planifié. Importez d'abord les examens.",
            'deliberations' => "Aucune délibération n'existe. Importez d'abord les délibérations.",
            'decisions-jury' => "Aucune décision de jury n'est enregistrée. Importez d'abord les décisions (via les délibérations).",
            'progressions' => "Aucune progression ne peut être calculée sans inscriptions. Importez d'abord les inscriptions.",
            'documents-academiques' => "Aucun document de référence étudiant. Importez d'abord les étudiants.",
            'referentiel-mens' => 'Référentiel MENS vide : importez le fichier officiel (aucun prérequis local).',
            default => "Données préalables « {$dependance} » absentes.",
        };
    }
}
