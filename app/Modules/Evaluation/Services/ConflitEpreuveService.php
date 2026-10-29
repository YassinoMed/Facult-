<?php

namespace App\Modules\Evaluation\Services;

use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\ScheduleAvailability;
use App\Modules\Schedule\Models\ScheduleSlot;
use Carbon\Carbon;
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
            $salle = Room::find($salleId);

            // Admissibilité absolue de la salle (DM-01/DM-04) : inexistante,
            // inactive, capacité inconnue, ou hors horaires d'ouverture sur
            // une partie du créneau => conflits bloquants.
            if ($salle === null) {
                $conflits[] = [
                    'type' => 'salle_inexistante',
                    'critique' => true,
                    'message' => "Salle #{$salleId} introuvable.",
                ];
            } else {
                if (! $salle->actif) {
                    $conflits[] = [
                        'type' => 'salle_inactive',
                        'critique' => true,
                        'message' => "Salle {$salle->code} inactive : affectation interdite.",
                    ];
                }

                if ($salle->capacite === null || (int) $salle->capacite < 1) {
                    $conflits[] = [
                        'type' => 'capacite_inconnue',
                        'critique' => true,
                        'message' => "Capacité de la salle {$salle->code} inconnue : affectation interdite.",
                    ];
                }

                if (($salle->heure_ouverture !== null || $salle->heure_fermeture !== null) && ! $salle->estOuvertePlage(
                    substr((string) $donnees['heure_debut'], 0, 5),
                    substr((string) $donnees['heure_fin'], 0, 5)
                )) {
                    $conflits[] = [
                        'type' => 'ouverture',
                        'critique' => true,
                        'message' => "Salle {$salle->code} fermée sur une partie du créneau demandé ({$this->plageSalle($salle)}).",
                    ];
                }

                // EX-06 & EX-07 : Indisponibilités et planning des cours sur la salle
                if (! empty($donnees['date'])) {
                    $jourSemaine = strtolower(Carbon::parse($donnees['date'])->format('l'));
                    $hDeb = substr((string) $donnees['heure_debut'], 0, 5);
                    $hFin = substr((string) $donnees['heure_fin'], 0, 5);

                    // EX-06 : Indisponibilités déclarées
                    $indispo = ScheduleAvailability::query()
                        ->indisponibles()
                        ->where('room_id', $salleId)
                        ->where('day_of_week', $jourSemaine)
                        ->get()
                        ->first(fn ($p) => $p->chevauche($hDeb, $hFin)
                            && $p->couvrePeriode($donnees['date'], $donnees['date']));

                    if ($indispo !== null) {
                        $motif = filled($indispo->reason) ? " — motif : {$indispo->reason}" : '';
                        $conflits[] = [
                            'type' => 'salle_indisponible',
                            'critique' => true,
                            'message' => "Salle {$salle->code} déclarée indisponible sur cette plage{$motif}.",
                        ];
                    }

                    // EX-07 : Conflits avec le planning des cours (ScheduleSlot)
                    $slotConflit = ScheduleSlot::where('room_id', $salleId)
                        ->where('day_of_week', $jourSemaine)
                        ->where('status', '!=', 'cancelled')
                        ->with(['schedule.groupe', 'matiere'])
                        ->get()
                        ->first(function ($slot) use ($donnees, $hDeb, $hFin) {
                            if (! $slot->chevauche($hDeb, $hFin)) {
                                return false;
                            }
                            if ($slot->schedule && $slot->schedule->starts_at && $slot->schedule->ends_at) {
                                $dateEp = $donnees['date'];
                                if ($dateEp < $slot->schedule->starts_at->format('Y-m-d') || $dateEp > $slot->schedule->ends_at->format('Y-m-d')) {
                                    return false;
                                }
                            }

                            return true;
                        });

                    if ($slotConflit !== null) {
                        $coursNom = $slotConflit->matiere?->nom ?? $slotConflit->type;
                        $grpCode = $slotConflit->schedule?->groupe?->code ? " ({$slotConflit->schedule->groupe->code})" : '';
                        $conflits[] = [
                            'type' => 'salle_planning',
                            'critique' => true,
                            'message' => "Salle {$salle->code} déjà occupée par un cours planifié « {$coursNom} »{$grpCode}.",
                        ];
                    }
                }
            }

            $conflit = $candidates->first(fn (Epreuve $e) => (int) $e->salle_id === $salleId
                || $e->candidats->contains(fn ($c) => (int) $c->salle_id === $salleId));
            if ($conflit !== null) {
                $codeSalle = $salle?->code ?? ('#'.$salleId);
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

        // Conflit candidats (§6, DM-03) : un même étudiant déjà convoqué sur
        // une autre épreuve qui se chevauche. Contrôle réel dès qu'une
        // épreuve existante est analysée (ignorerEpreuveId) ; à la création,
        // la population n'existe pas encore et l'alerte « etudiant » non
        // critique ci-dessous s'applique. Le périmètre est global : aucune
        // restriction par faculté ne masque une réservation croisée.
        if ($ignorerEpreuveId !== null && $candidates->isNotEmpty()) {
            $idsEtudiants = Candidat::where('epreuve_id', $ignorerEpreuveId)
                ->distinct()->pluck('etudiant_id');

            if ($idsEtudiants->isNotEmpty()) {
                $partages = (int) Candidat::whereIn('epreuve_id', $candidates->pluck('id'))
                    ->whereIn('etudiant_id', $idsEtudiants)
                    ->distinct()->count('etudiant_id');

                if ($partages > 0) {
                    $conflits[] = [
                        'type' => 'candidat',
                        'critique' => true,
                        'message' => "{$partages} étudiant(s) déjà convoqué(s) sur une autre épreuve au même moment.",
                    ];
                }
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

    private function plageSalle(Room $salle): string
    {
        if ($salle->heure_ouverture === null && $salle->heure_fermeture === null) {
            return 'aucune plage horaire définie';
        }

        return substr((string) $salle->heure_ouverture, 0, 5).' → '.substr((string) $salle->heure_fermeture, 0, 5);
    }
}
