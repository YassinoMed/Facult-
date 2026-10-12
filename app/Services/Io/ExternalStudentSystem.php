<?php

namespace App\Services\Io;

/**
 * Adaptateur concret du système universitaire externe (§65).
 * Délègue aux mêmes importers/exporters que l'API : les intégrations
 * externes ne contournent JAMAIS les validations métier (§35).
 */
class ExternalStudentSystem implements ExternalSystemInterface
{
    public function name(): string
    {
        return 'external-university';
    }

    public function importStudents(array $lignes, string $mode): array
    {
        return app(StudentsImporter::class)->importer($lignes, $mode);
    }

    public function importGrades(array $lignes, string $mode): array
    {
        return app(GradesImporter::class)->importer($lignes, $mode);
    }

    public function exportStudents(string $profil, array $filtres): array
    {
        return app(StudentsExporter::class)->exporter($profil, $filtres);
    }
}
