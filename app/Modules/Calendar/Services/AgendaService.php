<?php

namespace App\Modules\Calendar\Services;

use App\Models\Etudiant;
use App\Modules\Calendar\Models\EvenementCalendrier;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Schedule\Models\Teacher;

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

        // LOT 2 : un événement chevauche la période s'il commence au plus
        // tard à `fin` et se termine au plus tôt à `debut`. L'ancien filtre
        // (`date_fin >= fin` + `date_debut >= debut`) masquait les
        // événements multi-jours commençant avant la période et ceux qui se
        // terminaient dedans.
        $evenements = EvenementCalendrier::where('statut', 'publie')
            ->whereDate('date_debut', '<=', $fin)
            ->where(function ($q) use ($debut) {
                $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', $debut);
            })
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

    /**
     * Agenda enseignant (lecture seule) : événements publiés des
     * audiences `tous`/`enseignants` de sa faculté. Les brouillons, les
     * événements annulés et ceux d'une autre faculté sont exclus.
     *
     * @return array<int, array{titre: string, type: string, date: string, source: string, lien: ?string}>
     */
    public function agendaPourEnseignant(Teacher $enseignant, string $debut, string $fin, ?int $limite = null): array
    {
        $query = EvenementCalendrier::visiblesPourEnseignant($enseignant)
            ->whereDate('date_debut', '<=', $fin)
            ->where(fn ($q) => $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', $debut))
            ->orderBy('date_debut');

        if ($limite !== null) {
            $query->limit($limite);
        }

        $evenements = $query->get();

        return $evenements->map(fn (EvenementCalendrier $evenement) => [
            'titre' => $evenement->titre,
            'type' => $evenement->type,
            'date' => $evenement->date_debut->format('Y-m-d'),
            'source' => 'calendrier',
            'lien' => null,
        ])->all();
    }
}
