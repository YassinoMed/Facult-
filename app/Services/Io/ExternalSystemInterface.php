<?php

namespace App\Services\Io;

/**
 * Contrat d'adaptateur pour systèmes externes (§65).
 * Ajouter un nouveau système externe = implémenter cette interface,
 * sans toucher au cœur de l'application.
 */
interface ExternalSystemInterface
{
    /**
     * Nom court du système externe (utilisé comme clé d'audit).
     */
    public function name(): string;

    /**
     * @param  array<int, array<string, mixed>>  $lignes
     * @return array<string, mixed> rapport d'exécution
     */
    public function importStudents(array $lignes, string $mode): array;

    /**
     * @param  array<int, array<string, mixed>>  $lignes
     */
    public function importGrades(array $lignes, string $mode): array;

    /**
     * @return array{entetes: array<int, string>, lignes: array<int, array<string, mixed>>}
     */
    public function exportStudents(string $profil, array $filtres): array;
}
