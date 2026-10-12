<?php

namespace App\Modules\Evaluation\Services;

use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Schedule\Models\Room;
use Illuminate\Support\Collection;

/**
 * Détection automatique des conflits d'épreuves (§6).
 * Pure : aucune écriture, réutilisable avant chaque validation.
 *
 * @return array<int, array{type: string, critique: bool, message: string}>
 */
class ConflitEpreuveService
{
    /**
     * @param  array{date: string, heure_debut: string, heure_fin: string, salle_id?: ?int, salle_ids?: array<int>, groupe_id?: ?int, capacite_necessaire?: int, surveillants?: array<int, array{teacher_id?: ?int, user_id?: ?int}>}  $donnees
     */
    public function detecter(array $donnees, ?int $ignorerEpreuveId = null): array
    {
        $conflits = [];

        if (($donnees['heure_debut'] ?? '') >= ($donnees['heure_fin'] ?? '')) {
            $conflits[] = ['type' => 'horaire', 'critique' => true, 'message' => 'Horaire incohérent : le début doit précéder la fin.'];

            return $conflits;
        }

        $candidates = Epreuve::whereDate('date', $donnees['date'])
            ->where('statut', '!=', 'cancelled')
            ->when($ignorerEpreuveId !== null, fn ($q) => $q->where('id', '!=', $ignorerEpreuveId))
            ->with(['salle', 'groupe', 'matiere', 'surveillants', 'candidats' => fn ($q) => $q->select('id', 'epreuve_id', 'salle_id')])
            ->get()
            ->filter(fn (Epreuve $e) => $this->chevaucheHeures(
                $donnees['heure_debut'], $donnees['heure_fin'],
                (string) $e->heure_debut, (string) $e->heure_fin
            ));

        // Occupation des salles : salle principale ET secondaires (candidats
        // ventilés). Chaque salle demandée est vérifiée séparément.
        $sallesAVerifier = array_values(array_unique(array_filter(
            array_map(
                fn ($id) => (int) $id,
                [...($donnees['salle_ids'] ?? []), $donnees['salle_id'] ?? null]
            ),
            fn (int $id) => $id > 0
        )));

        foreach ($sallesAVerifier as $salleId) {
            $conflit = $candidates->first(fn (Epreuve $e) => (int) $e->salle_id === $salleId
                || $e->candidats->contains(fn ($c) => (int) $c->salle_id === $salleId));
            if ($conflit !== null) {
                $codeSalle = Room::find($salleId)?->code ?? ('#'.$salleId);
                $conflits[] = [
                    'type' => 'salle',
                    'critique' => true,
                    'message' => "Salle {$codeSalle} déjà réservée par « {$conflit->matiere->nom} » ({$this->plage($conflit)}).",
                ];
            }
        }

        if (! empty($donnees['groupe_id'])) {
            $conflit = $candidates->firstWhere('groupe_id', (int) $donnees['groupe_id']);
            if ($conflit !== null) {
                $conflits[] = [
                    'type' => 'groupe',
                    'critique' => true,
                    'message' => "Groupe déjà convoqué sur « {$conflit->matiere->nom} » ({$this->plage($conflit)}).",
                ];
            }
        }

        foreach ($donnees['surveillants'] ?? [] as $surveillant) {
            $conflit = $candidates->first(function (Epreuve $e) use ($surveillant) {
                return $e->surveillants->contains(function (Surveillance $s) use ($surveillant) {
                    if ($s->statut === 'annule') {
                        return false;
                    }

                    return (! empty($surveillant['teacher_id']) && (int) $s->teacher_id === (int) $surveillant['teacher_id'])
                        || (! empty($surveillant['user_id']) && (int) $s->user_id === (int) $surveillant['user_id']);
                });
            });

            if ($conflit !== null) {
                $conflits[] = [
                    'type' => 'surveillant',
                    'critique' => true,
                    'message' => "Surveillant déjà affecté sur « {$conflit->code} — {$conflit->matiere?->nom} » ({$conflit->date->format('d/m/Y')}, {$this->plage($conflit)}).",
                ];
                break;
            }
        }

        // Capacité : candidats du groupe vs capacité salle (avertissement si salle inconnue).
        if (! empty($donnees['salle_id']) && ($donnees['capacite_necessaire'] ?? 0) > 0) {
            $salle = $candidates->firstWhere('salle_id', (int) $donnees['salle_id'])?->salle
                ?? Room::find($donnees['salle_id']);

            if ($salle !== null && $salle->capacite !== null && (int) $donnees['capacite_necessaire'] > (int) $salle->capacite) {
                $conflits[] = [
                    'type' => 'capacite',
                    'critique' => true,
                    'message' => "Capacité insuffisante : {$donnees['capacite_necessaire']} candidats pour {$salle->capacite} places ({$salle->code}).",
                ];
            }
        }

        // Conflit étudiant : deux épreuves incompatibles au même moment pour
        // les candidats déjà convoqués du même groupe.
        if (! empty($donnees['groupe_id']) && $candidates->isNotEmpty()) {
            $conflits[] = [
                'type' => 'etudiant',
                'critique' => false,
                'message' => 'Vérifier les convocations individuelles : un étudiant ne doit pas avoir deux épreuves au même moment.',
            ];
        }

        return $conflits;
    }

    /** @param  Collection<int, array{type: string, critique: bool, message: string}>  $conflits */
    public function bloquants(Collection|array $conflits): array
    {
        $liste = $conflits instanceof Collection ? $conflits->all() : $conflits;

        return array_values(array_filter($liste, fn (array $c) => $c['critique'] === true));
    }

    private function chevaucheHeures(string $debutA, string $finA, string $debutB, string $finB): bool
    {
        return $debutA < $finB && $debutB < $finA;
    }

    private function plage(Epreuve $epreuve): string
    {
        return substr((string) $epreuve->heure_debut, 0, 5).' → '.substr((string) $epreuve->heure_fin, 0, 5);
    }
}
