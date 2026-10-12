<?php

namespace App\Modules\Referentiel\Services;

use App\Models\Matiere;
use App\Models\User;
use App\Modules\Referentiel\Events\OrdreModifie;
use App\Modules\Referentiel\Models\UniteEnseignement;
use Illuminate\Support\Facades\DB;

/**
 * Réordonnancement UE/ECUE (§18-19) : positions absolues par lot,
 * périmètre faculté vérifié, transaction unique, event d'audit.
 */
class ReordonnancementService
{
    /**
     * @param  array<int, int>  $ordres  [id => position]
     */
    public function reordonner(string $type, array $ordres, ?User $auteur): int
    {
        abort_unless(in_array($type, ['ue', 'matiere'], true), 422);

        $ids = array_map('intval', array_keys($ordres));

        abort_if($ids === [] || count($ids) > 500, 422);

        return DB::transaction(function () use ($type, $ordres, $ids, $auteur) {
            $modeles = $type === 'ue'
                ? UniteEnseignement::whereIn('id', $ids)->with('programme')->get()
                : Matiere::whereIn('id', $ids)->with('ue.programme')->get();

            abort_if($modeles->count() !== count($ids), 404);

            foreach ($modeles as $modele) {
                $faculteId = $type === 'ue'
                    ? (int) $modele->programme?->faculte_id
                    : (int) $modele->ue?->programme?->faculte_id;

                abort_unless($auteur === null || $auteur->peutAccederFaculte($faculteId), 404);
            }

            foreach ($ordres as $id => $position) {
                $modele = $modeles->firstWhere('id', (int) $id);
                $modele->update(['ordre' => max(0, min(65000, (int) $position))]);
            }

            OrdreModifie::dispatch($type, count($ids), (int) $auteur?->id);

            return count($ids);
        });
    }
}
