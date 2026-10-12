<?php

namespace App\Services\DataExchange;

use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Definitions\AffectationEnseignementDefinition;
use App\Services\DataExchange\Definitions\AffectationGroupeDefinition;
use App\Services\DataExchange\Definitions\AffectationSalleDefinition;
use App\Services\DataExchange\Definitions\AnneeUniversitaireDefinition;
use App\Services\DataExchange\Definitions\AnnonceDefinition;
use App\Services\DataExchange\Definitions\AnomalieQualiteDefinition;
use App\Services\DataExchange\Definitions\BatimentDefinition;
use App\Services\DataExchange\Definitions\DecisionJuryDefinition;
use App\Services\DataExchange\Definitions\DeliberationDefinition;
use App\Services\DataExchange\Definitions\DemandeDefinition;
use App\Services\DataExchange\Definitions\DepartementDefinition;
use App\Services\DataExchange\Definitions\DocumentAcademiqueDefinition;
use App\Services\DataExchange\Definitions\DomaineDefinition;
use App\Services\DataExchange\Definitions\EcueDefinition;
use App\Services\DataExchange\Definitions\EmploiDuTempsDefinition;
use App\Services\DataExchange\Definitions\EnseignementDefinition;
use App\Services\DataExchange\Definitions\EpreuveDefinition;
use App\Services\DataExchange\Definitions\EtudiantDefinition;
use App\Services\DataExchange\Definitions\EvaluationDefinition;
use App\Services\DataExchange\Definitions\ExamenDefinition;
use App\Services\DataExchange\Definitions\FactureDefinition;
use App\Services\DataExchange\Definitions\FaculteDefinition;
use App\Services\DataExchange\Definitions\GroupDefinition;
use App\Services\DataExchange\Definitions\InscriptionAcademiqueDefinition;
use App\Services\DataExchange\Definitions\InscriptionPedagogiqueDefinition;
use App\Services\DataExchange\Definitions\MaquetteImportDefinition;
use App\Services\DataExchange\Definitions\MatiereDefinition;
use App\Services\DataExchange\Definitions\MensImportDefinition;
use App\Services\DataExchange\Definitions\MentionDefinition;
use App\Services\DataExchange\Definitions\NiveauDefinition;
use App\Services\DataExchange\Definitions\NoteEpreuveAnonymeDefinition;
use App\Services\DataExchange\Definitions\NoteEpreuveDefinition;
use App\Services\DataExchange\Definitions\NoteImportDefinition;
use App\Services\DataExchange\Definitions\PresenceDefinition;
use App\Services\DataExchange\Definitions\ProgrammeDefinition;
use App\Services\DataExchange\Definitions\ProgressionDefinition;
use App\Services\DataExchange\Definitions\RoomDefinition;
use App\Services\DataExchange\Definitions\SemestreDefinition;
use App\Services\DataExchange\Definitions\SessionExamenDefinition;
use App\Services\DataExchange\Definitions\SpecialiteDefinition;
use App\Services\DataExchange\Definitions\TeacherDefinition;
use App\Services\DataExchange\Definitions\TypeDiplomeDefinition;
use App\Services\DataExchange\Definitions\UeDefinition;
use InvalidArgumentException;

/**
 * Registre des entités échangeables (§4-5) : ajouter une entité =
 * une définition ici, rien d'autre à câbler.
 */
final class DefinitionRegistry
{
    /**
     * @var array<string, class-string<ImportDefinitionInterface>>
     */
    private const IMPORTS = [
        'annees-universitaires' => AnneeUniversitaireDefinition::class,
        'facultes' => FaculteDefinition::class,
        'etablissements' => FaculteDefinition::class,
        'departements' => DepartementDefinition::class,
        'domaines' => DomaineDefinition::class,
        'mentions' => MentionDefinition::class,
        'specialites' => SpecialiteDefinition::class,
        'type-diplomes' => TypeDiplomeDefinition::class,
        'diplomes' => TypeDiplomeDefinition::class,
        'programmes' => ProgrammeDefinition::class,
        'parcours' => ProgrammeDefinition::class,
        'niveaux' => NiveauDefinition::class,
        'semestres' => SemestreDefinition::class,
        'ues' => UeDefinition::class,
        'ecues' => EcueDefinition::class,
        'matieres' => MatiereDefinition::class,
        'enseignants' => TeacherDefinition::class,
        'batiments' => BatimentDefinition::class,
        'salles' => RoomDefinition::class,
        'groupes' => GroupDefinition::class,
        'etudiants' => EtudiantDefinition::class,
        'inscriptions' => InscriptionAcademiqueDefinition::class,
        'inscriptions-academiques' => InscriptionAcademiqueDefinition::class,
        'inscriptions-pedagogiques' => InscriptionPedagogiqueDefinition::class,
        'enseignements' => EnseignementDefinition::class,
        'affectations-enseignants' => AffectationEnseignementDefinition::class,
        'affectations-groupes' => AffectationGroupeDefinition::class,
        'affectations-salles' => AffectationSalleDefinition::class,
        'emplois-du-temps' => EmploiDuTempsDefinition::class,
        'evaluations' => EvaluationDefinition::class,
        'sessions-examen' => SessionExamenDefinition::class,
        'examens' => ExamenDefinition::class,
        'epreuves' => EpreuveDefinition::class,
        'notes' => NoteImportDefinition::class,
        'notes-epreuve-anonymes' => NoteEpreuveAnonymeDefinition::class,
        'deliberations' => DeliberationDefinition::class,
        'decisions-jury' => DecisionJuryDefinition::class,
        'progressions' => ProgressionDefinition::class,
        'documents-academiques' => DocumentAcademiqueDefinition::class,
        'annonces' => AnnonceDefinition::class,
        'referentiel-mens' => MensImportDefinition::class,
        // Plans d'études (LOOP B6) : import seul (jamais d'export de
        // plans — relecture via les fiches maquettes versionnées).
        'maquettes-plan' => MaquetteImportDefinition::class,
    ];

    /**
     * @var array<string, class-string<ExportDefinitionInterface>>
     */
    private const EXPORTS = [
        'annees-universitaires' => AnneeUniversitaireDefinition::class,
        'facultes' => FaculteDefinition::class,
        'etablissements' => FaculteDefinition::class,
        'departements' => DepartementDefinition::class,
        'domaines' => DomaineDefinition::class,
        'mentions' => MentionDefinition::class,
        'specialites' => SpecialiteDefinition::class,
        'type-diplomes' => TypeDiplomeDefinition::class,
        'diplomes' => TypeDiplomeDefinition::class,
        'programmes' => ProgrammeDefinition::class,
        'parcours' => ProgrammeDefinition::class,
        'niveaux' => NiveauDefinition::class,
        'semestres' => SemestreDefinition::class,
        'ues' => UeDefinition::class,
        'ecues' => EcueDefinition::class,
        'matieres' => MatiereDefinition::class,
        'enseignants' => TeacherDefinition::class,
        'batiments' => BatimentDefinition::class,
        'salles' => RoomDefinition::class,
        'groupes' => GroupDefinition::class,
        'etudiants' => EtudiantDefinition::class,
        'inscriptions' => InscriptionAcademiqueDefinition::class,
        'inscriptions-academiques' => InscriptionAcademiqueDefinition::class,
        'inscriptions-pedagogiques' => InscriptionPedagogiqueDefinition::class,
        'enseignements' => EnseignementDefinition::class,
        'affectations-enseignants' => AffectationEnseignementDefinition::class,
        'affectations-groupes' => AffectationGroupeDefinition::class,
        'affectations-salles' => AffectationSalleDefinition::class,
        'emplois-du-temps' => EmploiDuTempsDefinition::class,
        'evaluations' => EvaluationDefinition::class,
        'sessions-examen' => SessionExamenDefinition::class,
        'examens' => ExamenDefinition::class,
        'epreuves' => EpreuveDefinition::class,
        'notes_epreuves' => NoteEpreuveDefinition::class,
        'notes' => NoteImportDefinition::class,
        'deliberations' => DeliberationDefinition::class,
        'decisions-jury' => DecisionJuryDefinition::class,
        'progressions' => ProgressionDefinition::class,
        'documents-academiques' => DocumentAcademiqueDefinition::class,
        'annonces' => AnnonceDefinition::class,
        'presences' => PresenceDefinition::class,
        'anomalies_qualite' => AnomalieQualiteDefinition::class,
        'factures' => FactureDefinition::class,
        'demandes' => DemandeDefinition::class,
    ];

    /**
     * @return array<int, string>
     */
    public static function entites(): array
    {
        return array_keys(self::IMPORTS);
    }

    /**
     * Entités visualisables par l'œil (page Voir) : toute entité
     * disposant d'une définition d'export (requête scopée + colonnes
     * blanchies). Inclut les entités export-seul (factures, demandes…).
     *
     * @return array<int, string>
     */
    public static function exportables(): array
    {
        return array_keys(self::EXPORTS);
    }

    /**
     * Entités consultables via la page Voir : union imports + exports
     * (une entité import-seul sans export, ex. referentiel-mens,
     * n'est pas visualisable et retourne 404 via le contrôleur).
     *
     * @return array<int, string>
     */
    public static function consultables(): array
    {
        return array_values(array_unique(array_merge(array_keys(self::IMPORTS), array_keys(self::EXPORTS))));
    }

    public function import(string $entite): ImportDefinitionInterface
    {
        $classe = self::IMPORTS[$entite] ?? null;

        if ($classe === null) {
            throw new InvalidArgumentException("Entité « {$entite} » non importable.");
        }

        return app($classe);
    }

    public function export(string $entite): ExportDefinitionInterface
    {
        $classe = self::EXPORTS[$entite] ?? null;

        if ($classe === null) {
            throw new InvalidArgumentException("Entité « {$entite} » non exportable.");
        }

        return app($classe);
    }
}
