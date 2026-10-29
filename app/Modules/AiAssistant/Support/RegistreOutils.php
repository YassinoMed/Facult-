<?php

namespace App\Modules\AiAssistant\Support;

use App\Modules\AiAssistant\Data\ContexteExecution;
use App\Modules\AiAssistant\Exceptions\OutilRefuseException;
use Closure;

/**
 * Registre central des outils autorisés pour les agents IA (Lot 1).
 *
 * Garanties serveur, jamais déléguées au modèle :
 * - un outil non enregistré ne peut PAS être invoqué ;
 * - un outil exigeant une permission absente est refusé ;
 * - un outil d'un autre mode (développeur/métier) est refusé ;
 * - des paramètres invalides sont refusés avant exécution.
 */
final class RegistreOutils
{
    /** @var array<string, OutilIa> */
    private array $outils = [];

    /** Nombre max d'invocations d'outils par réponse (anti-boucle, Lot 11). */
    public const INVOCATIONS_MAX = 8;

    public function enregistre(
        string $nom,
        string $description,
        string $permission,
        string $mode,
        Closure $executeur,
        ?Closure $parametresValides = null,
    ): void {
        if ($this->possede($nom)) {
            throw new \LogicException("Outil IA déjà enregistré : {$nom}.");
        }

        $this->outils[$nom] = new OutilIa($nom, $description, $permission, $mode, $executeur, $parametresValides);
    }

    public function possede(string $nom): bool
    {
        return array_key_exists($nom, $this->outils);
    }

    public function outil(string $nom): OutilIa
    {
        if (! $this->possede($nom)) {
            throw new OutilRefuseException($nom, 'outil inconnu du registre');
        }

        return $this->outils[$nom];
    }

    /**
     * @return list<OutilIa>
     */
    public function pourMode(string $mode): array
    {
        return array_values(array_filter(
            $this->outils,
            fn (OutilIa $outil) => $outil->mode === 'commun' || $outil->mode === $mode
        ));
    }

    /**
     * @return list<string>
     */
    public function nomsPourMode(string $mode): array
    {
        return array_map(fn (OutilIa $outil) => $outil->nom, $this->pourMode($mode));
    }

    /**
     * Invocation contrôlée : identité/permission/mode vérifiés ICI,
     * côté serveur, à partir du contexte reconstruit par Laravel.
     *
     * @param  array<string, mixed>  $parametres
     * @return array<string, mixed>
     */
    public function invoquer(string $nom, array $parametres, ContexteExecution $contexte): array
    {
        $outil = $this->outil($nom);
        $motifRefus = $this->motifRefus($outil, $contexte, $parametres);

        if ($motifRefus !== null) {
            throw new OutilRefuseException($nom, $motifRefus);
        }

        return ($outil->executeur)($parametres, $contexte);
    }

    /**
     * @param  array<string, mixed>  $parametres
     */
    private function motifRefus(OutilIa $outil, ContexteExecution $contexte, array $parametres): ?string
    {
        if (! PermissionsAi::permise($outil->permission, $contexte->role)) {
            return 'permission absente';
        }

        if ($outil->mode !== 'commun' && $outil->mode !== $contexte->mode) {
            return "outil réservé au mode {$outil->mode}";
        }

        if ($outil->parametresValides !== null && ! ($outil->parametresValides)($parametres)) {
            return 'paramètres invalides';
        }

        return null;
    }
}
