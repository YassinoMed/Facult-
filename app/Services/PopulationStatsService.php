<?php

namespace App\Services;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Teacher;

/**
 * Statistiques population (§40) + anomalies qualité (§41), scopées
 * faculté, calculées côté serveur.
 */
class PopulationStatsService
{
    /**
     * @return array<string, int|float>
     */
    public function statistiques(?int $faculteId, ?int $anneeId): array
    {
        $etudiants = Etudiant::query()
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId));

        $inscriptions = InscriptionAcademique::query()
            ->when($anneeId !== null, fn ($q) => $q->where('annee_universitaire_id', $anneeId))
            ->when($faculteId !== null, fn ($q) => $q->whereHas('etudiant',
                fn ($e) => $e->where('faculte_id', $faculteId)));

        $groupesQuery = Group::query()
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->withCount(['affectations as affectations_actives' => fn ($q) => $q->where('statut', 'active')]);

        $groupes = $groupesQuery->get();
        $capaciteTotale = (int) $groupes->sum('capacite');
        $effectifGroupes = (int) $groupes->sum('affectations_actives');
        $groupesActifs = $groupes->where('actif', true)->count();
        $groupesComplets = $groupes->filter(fn (Group $g) => $g->capacite !== null && $g->affectations_actives >= (int) $g->capacite)->count();
        $groupesVides = $groupes->filter(fn (Group $g) => (int) $g->affectations_actives === 0)->count();
        $tauxMoyen = $capaciteTotale > 0 ? round($effectifGroupes / $capaciteTotale * 100, 1) : 0.0;

        return [
            'etudiants_total' => (clone $etudiants)->count(),
            'etudiants_valides' => (clone $etudiants)->where('statut', 'valide')->count(),
            'etudiants_attente' => (clone $etudiants)->where('statut', 'en_attente')->count(),
            'etudiants_sans_groupe' => (clone $etudiants)->whereNull('groupe_id')->count(),
            'inscriptions' => (clone $inscriptions)->count(),
            'inscriptions_validees' => (clone $inscriptions)->where('statut', 'valide')->count(),
            'inscriptions_aujourdhui' => (clone $inscriptions)->whereDate('inscriptions_academiques.created_at', now()->toDateString())->count(),
            'inscriptions_encours' => (clone $inscriptions)->where('statut', 'en_cours')->count(),
            'inscriptions_ajournees' => (clone $inscriptions)->where('statut', 'ajourne')->count(),
            'reinscriptions' => (clone $inscriptions)->where('type', 'reinscription')->count(),
            'enseignants' => Teacher::where('actif', true)->count(),
            'groupes' => $groupes->count(),
            'groupes_actifs' => $groupesActifs,
            'groupes_complets' => $groupesComplets,
            'groupes_vides' => $groupesVides,
            'capacite_totale' => $capaciteTotale,
            'places_disponibles' => max(0, $capaciteTotale - $effectifGroupes),
            'taux_remplissage_moyen' => $tauxMoyen,
        ];
    }

    /**
     * Ventilations population (Phase 2) : par programme, niveau, sexe +
     * créés du jour. Scopées faculté, requêtes agrégées (pas de N+1).
     *
     * @return array{par_programme: array<string, int>, par_niveau: array<string, int>, par_sexe: array<string, int>, crees_aujourdhui: int, inscriptions_par_programme: array<string, int>}
     */
    public function ventilations(?int $faculteId): array
    {
        $base = Etudiant::query()
            ->when($faculteId !== null, fn ($q) => $q->where('etudiants.faculte_id', $faculteId));

        $parProgramme = (clone $base)
            ->join('programmes', 'programmes.id', '=', 'etudiants.programme_id')
            ->groupBy('programmes.code')->selectRaw('programmes.code as code, COUNT(*) as total')
            ->pluck('total', 'code')->map(fn ($v) => (int) $v)->all();

        $parNiveau = (clone $base)->whereNotNull('niveau')
            ->groupBy('niveau')->selectRaw('niveau as code, COUNT(*) as total')
            ->pluck('total', 'code')->map(fn ($v) => (int) $v)->all();

        $parSexe = (clone $base)->whereIn('sexe', ['M', 'F'])
            ->groupBy('sexe')->selectRaw('sexe as code, COUNT(*) as total')
            ->pluck('total', 'code')->map(fn ($v) => (int) $v)->all();

        $inscriptionsParProgramme = InscriptionAcademique::query()
            ->when($faculteId !== null, fn ($q) => $q->whereHas('etudiant',
                fn ($e) => $e->where('faculte_id', $faculteId)))
            ->join('programmes', 'programmes.id', '=', 'inscriptions_academiques.programme_id')
            ->groupBy('programmes.code')->selectRaw('programmes.code as code, COUNT(*) as total')
            ->pluck('total', 'code')->map(fn ($v) => (int) $v)->all();

        return [
            'par_programme' => $parProgramme,
            'par_niveau' => $parNiveau,
            'par_sexe' => $parSexe,
            'crees_aujourdhui' => (clone $base)->whereDate('etudiants.created_at', now()->toDateString())->count(),
            'inscriptions_par_programme' => $inscriptionsParProgramme,
        ];
    }

    /**
     * @return array<int, array{gravite: string, entite: string, lien: string, probleme: string, action: string}>
     */
    public function anomalies(?int $faculteId, int $limite = 50): array
    {
        $anomalies = [];

        $sansInscription = Etudiant::where('statut', 'valide')
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->whereDoesntHave('inscriptionsAcademiques')->limit(10)->get();

        foreach ($sansInscription as $etudiant) {
            $anomalies[] = [
                'gravite' => 'avertissement',
                'entite' => 'Étudiant '.$etudiant->num_inscription,
                'lien' => route('admin.etudiants.show', $etudiant),
                'probleme' => 'Étudiant validé sans inscription académique.',
                'action' => 'Créer une inscription (dossier).',
            ];
        }

        $sansGroupe = Etudiant::where('statut', 'valide')
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->whereNull('groupe_id')->limit(10)->get();

        foreach ($sansGroupe as $etudiant) {
            $anomalies[] = [
                'gravite' => 'avertissement',
                'entite' => 'Étudiant '.$etudiant->num_inscription,
                'lien' => route('admin.etudiants.show', $etudiant),
                'probleme' => 'Sans groupe affecté.',
                'action' => 'Affecter à un groupe.',
            ];
        }

        $groupes = Group::withCount(['affectations as affectes' => fn ($q) => $q->where('statut', 'active')])
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->get();

        foreach ($groupes as $groupe) {
            if ($groupe->capacite !== null && $groupe->affectes > $groupe->capacite) {
                $anomalies[] = [
                    'gravite' => 'erreur',
                    'entite' => 'Groupe '.$groupe->code,
                    'lien' => route('admin.groupes.index'),
                    'probleme' => "Sureffectif : {$groupe->affectes}/{$groupe->capacite}.",
                    'action' => 'Transférer des étudiants.',
                ];
            }

            if ($groupe->programme_id === null) {
                $anomalies[] = [
                    'gravite' => 'info',
                    'entite' => 'Groupe '.$groupe->code,
                    'lien' => route('admin.groupes.index'),
                    'probleme' => 'Sans programme rattaché.',
                    'action' => 'Rattacher au programme LMD.',
                ];
            }
        }

        $enseignants = Teacher::whereNull('departement_id')->limit(10)->get();

        foreach ($enseignants as $enseignant) {
            $anomalies[] = [
                'gravite' => 'info',
                'entite' => 'Enseignant '.$enseignant->nomComplet(),
                'lien' => route('admin.enseignants.index'),
                'probleme' => 'Sans département.',
                'action' => 'Rattacher à un département.',
            ];
        }

        return array_slice($anomalies, 0, $limite);
    }
}
