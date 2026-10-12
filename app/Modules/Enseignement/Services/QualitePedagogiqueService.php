<?php

namespace App\Modules\Enseignement\Services;

use App\Models\Matiere;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\UniteEnseignement;
use Illuminate\Support\Collection;

/**
 * Contrôle de cohérence pédagogique (§38) : anomalies calculées serveur
 * (gravité + entité + action corrective + lien).
 */
class QualitePedagogiqueService
{
    /**
     * @return array<int, array{gravite: string, entite: string, lien: string, probleme: string, action: string}>
     */
    public function anomalies(?int $faculteId, int $limite = 100): array
    {
        $anomalies = [];

        $ues = UniteEnseignement::with(['ecues', 'programme'])
            ->when($faculteId !== null, fn ($q) => $q->whereHas('programme',
                fn ($p) => $p->where('faculte_id', $faculteId)))
            ->get();

        foreach ($ues as $ue) {
            $scope = ['ues', $ue->id];

            if ($ue->ecues->isEmpty()) {
                $anomalies[] = $this->f('avertissement', 'UE '.$ue->code, $scope,
                    'UE sans ECUE.', 'Rattacher des matières.');
            }
        }

        $enseignements = Enseignement::with(['matiere', 'affectations', 'groupes'])
            ->when($faculteId !== null, fn ($q) => $q->whereHas('matiere.ue.programme',
                fn ($p) => $p->where('faculte_id', $faculteId)))
            ->get();

        foreach ($enseignements as $ens) {
            $scope = ['ens', $ens->id];

            if ($ens->affectations->where('statut', 'active')->isEmpty()) {
                $anomalies[] = $this->f('avertissement',
                    'Enseignement '.$ens->matiere->code.' / '.($ens->type?->code ?? '?'), $scope,
                    'Enseignement sans enseignant.', 'Affecter un enseignant.');
            }

            if ($ens->groupes->isEmpty()) {
                $anomalies[] = $this->f('avertissement',
                    'Enseignement '.$ens->matiere->code.' / '.($ens->type?->code ?? '?'), $scope,
                    'Enseignement sans groupe.', 'Rattacher un groupe.');
            }

            if ($ens->volumeTotal() <= 0) {
                $anomalies[] = $this->f('erreur',
                    'Enseignement '.$ens->matiere->code.' / '.($ens->type?->code ?? '?'), $scope,
                    'Volume nul.', 'Saisir les volumes.');
            }
        }

        $ecues = Matiere::with('composantes')
            ->whereNotNull('ue_id')
            ->when($faculteId !== null, fn ($q) => $q->whereHas('ue.programme',
                fn ($p) => $p->where('faculte_id', $faculteId)))
            ->get();

        foreach ($ecues as $ecue) {
            if ($ecue->type_evaluation === null || trim((string) $ecue->type_evaluation) === '') {
                $anomalies[] = $this->f('info', 'ECUE '.$ecue->code, ['ecue', $ecue->id],
                    'Sans modalité d’évaluation.', 'Renseigner le type d’évaluation.');
            }
        }

        return array_slice($anomalies, 0, $limite);
    }

    /**
     * @param  array{0: string, 1: int}  $cible
     * @return array{gravite: string, entite: string, lien: string, probleme: string, action: string}
     */
    private function f(string $gravite, string $entite, array $cible, string $probleme, string $action): array
    {
        $lien = match ($cible[0]) {
            'ues' => route('admin.referentiel.entites.show', ['ues', $cible[1]]),
            'ens' => route('admin.enseignements.show', $cible[1]),
            default => route('admin.referentiel.ecues.show', $cible[1]),
        };

        return compact('gravite', 'entite', 'lien', 'probleme', 'action');
    }

    /**
     * @return Collection<int, Programme>
     */
    public function programmes(?int $faculteId): Collection
    {
        return Programme::orderBy('code')
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->get();
    }
}
