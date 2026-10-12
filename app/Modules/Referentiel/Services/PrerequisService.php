<?php

namespace App\Modules\Referentiel\Services;

use App\Models\Matiere;
use Illuminate\Support\Facades\DB;

/**
 * Prérequis d'ECUE (§17) : graphe orienté sans cycle (BFS), transactionnel.
 */
class PrerequisService
{
    /**
     * Ajoute un prérequis (refuse l'auto-référence et les cycles).
     *
     * @throws \DomainException
     */
    public function ajouter(Matiere $matiere, Matiere $prerequis): void
    {
        if ($matiere->id === $prerequis->id) {
            throw new \DomainException('Une ECUE ne peut pas être son propre prérequis.');
        }

        // Cycle : le prérequis ne doit pas déjà dépendre (transitivement)
        // de la matière (sinon A→B→A).
        if ($this->atteignable($prerequis->id, $matiere->id)) {
            throw new \DomainException('Prérequis circulaire interdit.');
        }

        DB::transaction(function () use ($matiere, $prerequis) {
            $matiere->prerequis()->syncWithoutDetaching([$prerequis->id]);
        });
    }

    public function retirer(Matiere $matiere, Matiere $prerequis): void
    {
        $matiere->prerequis()->detach($prerequis->id);
    }

    /**
     * Existe-t-il un chemin $depuis → … → $cible dans le graphe ?
     */
    public function atteignable(int $depuis, int $cible): bool
    {
        $visites = [];
        $file = [$depuis];

        while ($file !== []) {
            $courant = array_shift($file);

            if ($courant === $cible) {
                return true;
            }

            if (isset($visites[$courant])) {
                continue;
            }
            $visites[$courant] = true;

            $suivants = DB::table('ecue_prerequis')
                ->where('matiere_id', $courant)->pluck('prerequis_id')->all();

            foreach ($suivants as $suivant) {
                if (! isset($visites[$suivant])) {
                    $file[] = $suivant;
                }
            }
        }

        return false;
    }
}
