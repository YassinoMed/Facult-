<?php

namespace App\Support;

/**
 * Scopes API (§48) — principe du moindre privilège.
 * Un token ne possède QUE les scopes qui lui sont explicitement accordés.
 */
final class ApiScopes
{
    public const SCOPES = [
        'students:read' => 'Consulter les étudiants',
        'students:write' => 'Créer/modifier des étudiants via import',
        'archives:read' => 'Consulter les dossiers académiques',
        'grades:read' => 'Consulter les notes',
        'grades:write' => 'Importer des notes',
        'documents:read' => 'Consulter les métadonnées de documents',
        'documents:write' => 'Importer des métadonnées de documents',
        'imports:create' => 'Lancer des imports (validation + confirmation requises)',
        'data:exchange' => 'Import/export unifié des référentiels (validation + confirmation requises)',
        'schedules:import' => 'Importer des emplois du temps (validation + confirmation requises)',
        'exports:create' => 'Générer des exports (profils restreints)',
        'referentiel:read' => 'Consulter le référentiel académique',
        'registrations:read' => 'Consulter les inscriptions (contexte de réinscription)',
        'registrations:write' => 'Créer des réinscriptions (confirmation requise)',
        'assignments:read' => 'Consulter les simulations d’affectation',
        'assignments:write' => 'Simuler et confirmer des affectations',
        'configuration:manage' => 'Gérer la configuration académique (matières, résultats)',
        'versioning:read' => 'Consulter les métadonnées et l’état du versionnage système',
        'webhooks:receive' => 'Recevoir des webhooks signés',
    ];

    public static function valide(string $scope): bool
    {
        return array_key_exists($scope, self::SCOPES);
    }

    /**
     * Scopes implicites requis par type d'opération d'import.
     */
    public static function pourImport(string $type): array
    {
        return match ($type) {
            'students' => ['imports:create', 'students:write'],
            'grades' => ['imports:create', 'grades:write'],
            'academic_registrations' => ['imports:create', 'archives:read'],
            'subjects' => ['imports:create', 'configuration:manage'],
            'results' => ['imports:create', 'configuration:manage'],
            'documents' => ['imports:create', 'documents:write'],
            default => ['imports:create'],
        };
    }
}
