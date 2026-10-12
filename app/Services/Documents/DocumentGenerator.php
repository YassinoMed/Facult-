<?php

namespace App\Services\Documents;

use App\Models\Etudiant;
use App\Modules\StudentDossiers\Models\GeneratedDocument;

/**
 * Contrat de génération documentaire (Phase 16 §16.3) : un point
 * d'entrée unique vers les moteurs existants (dossier, officiels,
 * inscription). Pipeline : données → validation schéma → rendu →
 * registre versionné. Jamais de PDF hors registre pour un document
 * officiel individuel.
 */
interface DocumentGenerator
{
    /**
     * @return array<string> types supportés (clés catalogue).
     */
    public function types(): array;

    public function supporte(string $type): bool;

    /**
     * @param  array<string, mixed>  $contexte  (ex. inscription_id)
     *
     * @throws \DomainException si prérequis métier non rempli.
     */
    public function generer(Etudiant $etudiant, string $type, array $contexte, ?int $auteurId): GeneratedDocument;
}
