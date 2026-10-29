<?php

namespace App\Modules\AiAssistant\Support;

use App\Modules\AiAssistant\Data\ContexteExecution;
use Closure;

/**
 * Outil exposé aux agents IA (Lot 1). L'exécution reste TOUJOURS pilotée
 * par l'orchestrateur Laravel : le contenu du modèle n'est jamais une
 * source d'autorisation ni de paramètre non contrôlé.
 */
final readonly class OutilIa
{
    /**
     * @param  Closure(array $parametres, ContexteExecution $contexte): array  $executeur
     */
    public function __construct(
        public string $nom,
        public string $description,
        public string $permission,
        public string $mode,
        public Closure $executeur,
        public ?Closure $parametresValides = null,
    ) {
        if (! in_array($this->mode, ['commun', 'developpeur', 'metier'], true)) {
            throw new \InvalidArgumentException("Mode d'outil inconnu : {$this->mode}.");
        }
    }
}
