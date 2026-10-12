<?php

namespace App\Modules\Referentiel\Services;

use App\Models\Matiere;
use App\Modules\Referentiel\Models\Programme;
use Illuminate\Support\Facades\DB;

/**
 * Graphe des prérequis (ENTERPRISE LOOP 11) : lecture seule — nœuds
 * ECUE d'un programme, arêtes `ecue_prerequis`, cycles détectés via le
 * moteur canonique (`PrerequisService::atteignable`, jamais réécrit),
 * prérequis hors programme signalés. Les références manquantes sont
 * impossibles par construction (FK restrict) — documenté, non testé
 * pour du vide. Aucune création ni suppression de lien ici.
 *
 * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>, aretes_en_cycle: int}
 */
final class GraphePrerequisService
{
    public function __construct(
        private readonly PrerequisService $prerequis,
    ) {}

    public function construire(Programme $programme): array
    {
        $programme->loadMissing('niveaux.semestres.unitesEnseignement');

        $ueIds = $programme->niveaux->flatMap(fn ($n) => $n->semestres->flatMap->unitesEnseignement)->pluck('id')->all();

        $ecues = Matiere::whereIn('ue_id', $ueIds === [] ? [0] : $ueIds)
            ->with(['ue.semestre.niveau', 'prerequis.ue', 'requisePar'])
            ->orderBy('code')
            ->get();

        $ids = $ecues->pluck('id')->all();
        $dansProgramme = array_flip($ids);

        $nodes = [];
        foreach ($ecues as $ecue) {
            $nodes[$ecue->id] = [
                'id' => $ecue->id,
                'code' => $ecue->code,
                'nom' => $ecue->nom,
                'ue' => $ecue->ue?->code,
                'semestre' => $ecue->ue?->semestre?->code,
                'niveau' => $ecue->ue?->semestre?->niveau?->code,
                'dependants' => $ecue->requisePar->count(),
                'en_cycle' => false,
                'hors_programme' => false,
            ];
        }

        $edges = [];
        $aretesEnCycle = 0;

        if ($ids !== []) {
            $lignes = DB::table('ecue_prerequis')->whereIn('matiere_id', $ids)->orderBy('matiere_id')->get();

            foreach ($lignes as $ligne) {
                $estCycle = $this->prerequis->atteignable((int) $ligne->prerequis_id, (int) $ligne->matiere_id);
                $prerequis = $ecues->firstWhere('id', (int) $ligne->prerequis_id);
                $horsProgramme = $prerequis === null || ! isset($dansProgramme[$prerequis->id])
                    || (int) $prerequis->ue?->programme_id !== (int) $programme->id;

                if ($estCycle) {
                    $aretesEnCycle++;
                    $nodes[(int) $ligne->matiere_id]['en_cycle'] = true;
                    if (isset($nodes[(int) $ligne->prerequis_id])) {
                        $nodes[(int) $ligne->prerequis_id]['en_cycle'] = true;
                    }
                }

                if ($horsProgramme && isset($nodes[(int) $ligne->matiere_id])) {
                    $nodes[(int) $ligne->matiere_id]['hors_programme'] = true;
                }

                $edges[] = [
                    'de' => $nodes[(int) $ligne->matiere_id]['code'] ?? '#'.((int) $ligne->matiere_id),
                    'vers' => $prerequis?->code ?? '#'.((int) $ligne->prerequis_id),
                    'cycle' => $estCycle,
                    'hors_programme' => $horsProgramme,
                ];
            }
        }

        return ['nodes' => array_values($nodes), 'edges' => $edges, 'aretes_en_cycle' => $aretesEnCycle];
    }
}
