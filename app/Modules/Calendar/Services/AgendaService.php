<?php

namespace App\Modules\Calendar\Services;

use App\Models\Etudiant;
use App\Modules\Calendar\Models\EvenementCalendrier;
use App\Modules\Evaluation\Models\SessionExamen;

/**
 * Agenda unifié (§41) : événements + sessions d'examen en lecture
 * seule. Les cours restent la propriété de Schedule, les épreuves
 * celle d'Evaluation — aucune duplication, agrégation uniquement.
 *
 * @return array<int, array{titre: string, type: string, date: string, source: string, lien: ?string}>
 */
class AgendaService
{
    public function agendaPourEtudiant(Etudiant $etudiant, string $debut, string $fin): array
    {
        $elements = [];

        $evenements = EvenementCalendrier::where('statut', 'publie')
            ->whereDate('date_debut', '<=', $fin)
            ->where(function ($q) use ($fin) {
                $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', $fin);
            })
            ->whereDate('date_debut', '>=', $debut)
            ->where(fn ($q) => $q
                ->where('audience', 'tous')
                ->orWhere(function ($s) use ($etudiant) {
                    $s->where('audience', 'etudiants')
                        ->when($etudiant->faculte_id !== null, fn ($w) => $w->where(fn ($x) => $x->whereNull('faculte_id')->orWhere('faculte_id', $etudiant->faculte_id)));
                })
                ->orWhere(function ($s) use ($etudiant) {
                    $s->where('audience', 'groupe')->where('groupe_id', $etudiant->groupe_id);
                })
                ->orWhere(function ($s) use ($etudiant) {
                    $s->where('audience', 'programme')->where('programme_id', $etudiant->programme_id);
                }))
            ->orderBy('date_debut')
            ->get();

        foreach ($evenements as $evenement) {
            $elements[] = [
                'titre' => $evenement->titre,
                'type' => $evenement->type,
                'date' => $evenement->date_debut->format('Y-m-d'),
                'source' => 'calendrier',
                'lien' => null,
            ];
        }

        // Sessions d'examen (module Evaluation, si actif) : lecture seule.
        if (config('modules.evaluation.active') && class_exists(SessionExamen::class)) {
            $sessions = SessionExamen::whereIn('statut', ['open', 'ongoing'])
                ->whereDate('date_debut', '<=', $fin)
                ->whereDate('date_fin', '>=', $debut)
                ->orderBy('date_debut')
                ->get();

            foreach ($sessions as $session) {
                $elements[] = [
                    'titre' => $session->nom,
                    'type' => 'examen',
                    'date' => $session->date_debut->format('Y-m-d'),
                    'source' => 'session_examen',
                    'lien' => null,
                ];
            }
        }

        usort($elements, fn (array $a, array $b) => $a['date'] <=> $b['date']);

        return $elements;
    }
}
