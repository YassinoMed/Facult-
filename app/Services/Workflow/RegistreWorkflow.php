<?php

namespace App\Services\Workflow;

use App\Services\Workflow\Contrats\WorkflowDefinitionInterface;
use App\Services\Workflow\Definitions\WorkflowCorrectionQuatreYeuxDefinition;
use App\Services\Workflow\Definitions\WorkflowDemandeEtudianteDefinition;
use App\Services\Workflow\Definitions\WorkflowInscriptionDefinitionV1;
use App\Services\Workflow\Definitions\WorkflowInscriptionDefinitionV2;
use DomainException;

/**
 * Registre central des définitions de workflows universitaires.
 * Gère le multi-versioning par domaine et résout la version adéquate.
 */
class RegistreWorkflow
{
    /**
     * @var array<string, array<int, WorkflowDefinitionInterface>>
     */
    private array $definitions = [];

    public function __construct()
    {
        // Enregistrement des définitions socles par défaut
        $this->enregistrer(new WorkflowInscriptionDefinitionV1);
        $this->enregistrer(new WorkflowInscriptionDefinitionV2);
        $this->enregistrer(new WorkflowCorrectionQuatreYeuxDefinition);
        $this->enregistrer(new WorkflowDemandeEtudianteDefinition);
    }

    /**
     * Enregistre une définition de workflow pour son domaine et sa version.
     */
    public function enregistrer(WorkflowDefinitionInterface $definition): void
    {
        $domaine = $definition->domaine();
        $version = $definition->version();

        if (! isset($this->definitions[$domaine])) {
            $this->definitions[$domaine] = [];
        }

        $this->definitions[$domaine][$version] = $definition;
        ksort($this->definitions[$domaine]);
    }

    /**
     * Récupère la définition de workflow applicable.
     * Si aucune version n'est spécifiée, renvoie la dernière version enregistrée.
     *
     * @throws DomainException
     */
    public function obtenir(string $domaine, ?int $version = null): WorkflowDefinitionInterface
    {
        if (! isset($this->definitions[$domaine]) || empty($this->definitions[$domaine])) {
            throw new DomainException("Aucun workflow enregistré pour le domaine « {$domaine} ».");
        }

        if ($version !== null) {
            if (! isset($this->definitions[$domaine][$version])) {
                throw new DomainException("Version {$version} introuvable pour le workflow « {$domaine} ».");
            }

            return $this->definitions[$domaine][$version];
        }

        // Renvoie la version la plus récente
        $versionsDisponibles = array_keys($this->definitions[$domaine]);
        $derniereVersion = max($versionsDisponibles);

        return $this->definitions[$domaine][$derniereVersion];
    }

    /**
     * Vérifie si un domaine de workflow existe.
     */
    public function existe(string $domaine, ?int $version = null): bool
    {
        if (! isset($this->definitions[$domaine])) {
            return false;
        }

        if ($version !== null) {
            return isset($this->definitions[$domaine][$version]);
        }

        return true;
    }

    /**
     * Retourne toutes les versions disponibles pour un domaine.
     *
     * @return array<int, int>
     */
    public function versions(string $domaine): array
    {
        return array_keys($this->definitions[$domaine] ?? []);
    }
}
