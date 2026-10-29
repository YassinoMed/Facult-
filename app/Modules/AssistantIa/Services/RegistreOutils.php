<?php

namespace App\Modules\AssistantIa\Services;

use App\Models\User;
use App\Modules\AssistantIa\Contracts\OutilIaInterface;
use App\Modules\AssistantIa\DTO\ResultatOutil;
use App\Modules\AssistantIa\Exceptions\OutilNonAutoriseException;
use Illuminate\Support\Facades\Log;

class RegistreOutils
{
    /**
     * @var array<string, OutilIaInterface>
     */
    protected array $outils = [];

    /**
     * Enregistre un outil auprès du registre.
     */
    public function enregistrer(OutilIaInterface $outil): self
    {
        $this->outils[$outil->nom()] = $outil;

        return $this;
    }

    /**
     * Récupère un outil par son nom technique.
     */
    public function obtenir(string $nom): ?OutilIaInterface
    {
        return $this->outils[$nom] ?? null;
    }

    /**
     * Vérifie si un outil existe dans le registre.
     */
    public function existe(string $nom): bool
    {
        return isset($this->outils[$nom]);
    }

    /**
     * Retourne tous les outils enregistrés.
     *
     * @return array<string, OutilIaInterface>
     */
    public function tous(): array
    {
        return $this->outils;
    }

    /**
     * Filtre les outils autorisés pour un utilisateur donné et un mode donné.
     *
     * @return array<string, OutilIaInterface>
     */
    public function pourUtilisateurEtMode(User $utilisateur, string $mode): array
    {
        $autorises = [];

        foreach ($this->outils as $nom => $outil) {
            // Vérification du mode ('commun' ou mode exact)
            if ($outil->mode() !== 'commun' && $outil->mode() !== $mode) {
                continue;
            }

            // Vérification de permission
            $perm = $outil->permissionRequise();
            if ($perm !== '' && ! $utilisateur->hasPermission($perm) && ! $utilisateur->estSuperAdmin()) {
                continue;
            }

            $autorises[$nom] = $outil;
        }

        return $autorises;
    }

    /**
     * Formate les spécifications des outils pour le function calling du modèle (OpenAI / vLLM / Claude).
     * Les outils d'écriture et actions sensibles sont rigoureusement exclus si le fournisseur est distant.
     *
     * @return array<int, array<string, mixed>>
     */
    public function obtenirDefinitionsPourIa(User $utilisateur, string $mode, ?string $fournisseur = null): array
    {
        $outils = $this->pourUtilisateurEtMode($utilisateur, $mode);
        $definitions = [];

        foreach ($outils as $outil) {
            // Si le fournisseur est distant, exclusion formelle des outils d'écriture ou sensibles
            if ($fournisseur === 'distant') {
                if ($outil->estSensible() || str_contains($outil->nom(), 'preparer_action')) {
                    continue;
                }
            }

            $definitions[] = [
                'type' => 'function',
                'function' => [
                    'name' => $outil->nom(),
                    'description' => $outil->description(),
                    'parameters' => $outil->schemaParametres(),
                ],
            ];
        }

        return $definitions;
    }

    /**
     * Exécute un outil après validation stricte des autorisations côté serveur.
     *
     * @param  array<string, mixed>  $parametres
     */
    public function executerOutil(string $nom, array $parametres, User $utilisateur, ?string $mode = null, ?string $fournisseur = null): ResultatOutil
    {
        $outil = $this->obtenir($nom);

        if (! $outil) {
            throw new OutilNonAutoriseException($nom, "L'outil demandé n'existe pas dans le registre.");
        }

        // Interdiction d'exécuter un outil d'écriture si la demande provient d'un fournisseur distant
        if ($fournisseur === 'distant' && ($outil->estSensible() || str_contains($outil->nom(), 'preparer_action'))) {
            throw new OutilNonAutoriseException($nom, "L'action '{$nom}' est une opération sensible ou d'écriture. Son exécution par un fournisseur d'IA externe est strictement interdite.");
        }

        if ($mode !== null && $outil->mode() !== 'commun' && $outil->mode() !== $mode) {
            throw new OutilNonAutoriseException($nom, "L'outil n'est pas accessible en mode '{$mode}'.");
        }

        $perm = $outil->permissionRequise();
        if ($perm !== '' && ! $utilisateur->hasPermission($perm) && ! $utilisateur->estSuperAdmin()) {
            throw new OutilNonAutoriseException($nom, "L'utilisateur ne dispose pas de la permission requise '{$perm}'.");
        }

        try {
            return $outil->executer($parametres, $utilisateur);
        } catch (\Throwable $e) {
            Log::warning("Erreur lors de l'exécution de l'outil IA [{$nom}] : ".$e->getMessage(), [
                'utilisateur_id' => $utilisateur->id,
                'outil' => $nom,
            ]);

            return ResultatOutil::echec("Une erreur est survenue lors de l'exécution de l'outil : {$e->getMessage()}", [
                'exception' => get_class($e),
            ]);
        }
    }
}
