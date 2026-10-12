<?php

namespace App\Modules\Referentiel\Services;

use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\UniteEnseignement;
use Illuminate\Database\Eloquent\Builder;

/**
 * Compteurs du tableau de bord et anomalies qualité (§36-37),
 * calculés côté serveur, scopés faculté si restreinte.
 */
class ReferentielStatsService
{
    /**
     * @return array<string, int>
     */
    public function compteurs(?int $faculteId): array
    {
        $programmes = $this->programmes($faculteId);

        // LOOP 15 : ids chargés UNE fois (le builder était réexécuté à
        // chaque pluck — mêmes chiffres, 5 requêtes en moins).
        $ids = (clone $programmes)->pluck('id');

        // LOOP 3 : états regroupés en UNE requête (mêmes chiffres).
        $parStatut = Maquette::whereIn('programme_id', $ids)
            ->selectRaw('statut, COUNT(*) as total')
            ->groupBy('statut')
            ->pluck('total', 'statut');

        return [
            'etablissements' => $faculteId !== null ? 1 : Faculte::count(),
            'programmes' => $ids->count(),
            'niveaux' => Niveau::whereIn('programme_id', $ids)->count(),
            'ues' => UniteEnseignement::whereIn('programme_id', $ids)->count(),
            'ecues' => Matiere::whereIn('ue_id', UniteEnseignement::whereIn(
                'programme_id', $ids)->pluck('id'))->count(),
            'standalone' => Matiere::pourFaculte($faculteId)->whereNull('ue_id')->count(),
            'maquettes_actives' => (int) ($parStatut['active'] ?? 0),
            'maquettes_brouillon' => (int) ($parStatut['draft'] ?? 0) + (int) ($parStatut['review'] ?? 0),
            'maquettes_approuvees' => (int) ($parStatut['approved'] ?? 0),
            'maquettes_archivees' => (int) ($parStatut['archived'] ?? 0),
        ];
    }

    /**
     * Anomalies de cohérence : gravité + entité + action corrective.
     * Un programme actif sans maquette active est « en construction »
     * (avertissement) tant qu'il n'a aucun consommateur, et un BLOCAGE
     * OPÉRATIONNEL (erreur) dès qu'il porte des inscriptions académiques
     * ou des examens — les flux plan-actif (`inscrireEcue`,
     * `genererEpreuves`) refusent déjà explicitement sans maquette
     * active : cette distinction n'invente aucune règle.
     *
     * @return array<int, array{gravite: string, entite: string, lien: string, probleme: string, action: string}>
     */
    public function anomalies(?int $faculteId, int $limite = 50): array
    {
        $anomalies = [];
        // withCount en une requête (pas de N+1 : 2 comptes par programme sinon).
        $programmes = $this->programmes($faculteId)
            ->withCount(['niveaux', 'maquettes' => fn ($q) => $q->where('statut', 'active')])
            ->get();
        $idsProgrammes = $programmes->pluck('id');

        // Consommateurs actifs : 2 requêtes groupées (jamais par programme).
        $exploites = InscriptionAcademique::whereIn('programme_id', $idsProgrammes)
            ->selectRaw('programme_id')->groupBy('programme_id')->pluck('programme_id')
            ->merge(Examen::whereIn('programme_id', $idsProgrammes)
                ->selectRaw('programme_id')->groupBy('programme_id')->pluck('programme_id'))
            ->flip();

        foreach ($programmes as $programme) {
            if ($programme->specialite_id === null) {
                $anomalies[] = $this->anomalie(
                    'info', 'Programme '.$programme->code,
                    route('admin.referentiel.entites.show', ['programmes', $programme]),
                    'Sans spécialité rattachée.',
                    'Rattacher une spécialité ou laisser vide volontairement.'
                );
            }

            if ((int) $programme->niveaux_count === 0) {
                $anomalies[] = $this->anomalie(
                    'avertissement', 'Programme '.$programme->code,
                    route('admin.referentiel.entites.show', ['programmes', $programme]),
                    'Aucun niveau défini.',
                    'Créer les niveaux (L1, L2, L3…).'
                );
            }

            if ((int) $programme->maquettes_count === 0) {
                if ($exploites->has($programme->id)) {
                    $anomalies[] = $this->anomalie(
                        'erreur', 'Programme '.$programme->code,
                        route('admin.referentiel.prevalidation.show', $programme),
                        'Aucune maquette active — flux opérationnels bloqués (inscriptions pédagogiques, génération d\'épreuves).',
                        'Ouvrir la prévalidation, compléter le plan, activer la maquette.'
                    );
                } else {
                    $anomalies[] = $this->anomalie(
                        'avertissement', 'Programme '.$programme->code,
                        route('admin.referentiel.maquettes.index', ['programme_id' => $programme->id]),
                        'Aucune maquette active.',
                        'Créer puis activer une maquette.'
                    );
                }
            }
        }

        $ues = UniteEnseignement::whereIn('programme_id', $idsProgrammes)
            ->withCount('ecues')->with('semestre')->get();

        foreach ($ues as $ue) {
            if ($ue->ecues_count === 0) {
                $anomalies[] = $this->anomalie(
                    'avertissement', 'UE '.$ue->code,
                    route('admin.referentiel.entites.show', ['ues', $ue]),
                    'UE sans ECUE.',
                    'Rattacher des matières (ECUE) à cette UE.'
                );
            }
        }

        $ecues = Matiere::whereNotNull('ue_id')
            ->whereIn('ue_id', $ues->pluck('id'))->get();

        foreach ($ecues as $ecue) {
            if ((int) $ecue->credits <= 0) {
                $anomalies[] = $this->anomalie(
                    'erreur', 'ECUE '.$ecue->code, '#',
                    'Zéro crédit.',
                    'Corriger les crédits de la matière.'
                );
            }

            if ($ecue->volumeTotal() <= 0) {
                $anomalies[] = $this->anomalie(
                    'avertissement', 'ECUE '.$ecue->code, '#',
                    'Sans volume horaire.',
                    'Saisir CM/TD/TP dans la fiche matière.'
                );
            }
        }

        // Somme des crédits ECUE par UE : UNE requête groupée
        // (les `sum()` par UE créaient un N+1, mêmes chiffres).
        $sommesCredit = Matiere::whereIn('ue_id', $ues->pluck('id'))
            ->selectRaw('ue_id, SUM(credits) as total')->groupBy('ue_id')
            ->pluck('total', 'ue_id');

        foreach ($ues as $ue) {
            $somme = (int) ($sommesCredit[$ue->id] ?? 0);

            if ($ue->ecues_count > 0 && $somme !== (int) $ue->credits) {
                $anomalies[] = $this->anomalie(
                    'avertissement', 'UE '.$ue->code,
                    route('admin.referentiel.entites.show', ['ues', $ue]),
                    "Crédits UE ({$ue->credits}) ≠ somme ECUE ({$somme}).",
                    'Aligner les crédits.'
                );
            }
        }

        return array_slice($anomalies, 0, $limite);
    }

    /**
     * Comparaison multi-facultés (ENTERPRISE LOOP 09) : UNE requête
     * groupée par table (6 requêtes fixes), calcul serveur, réservé
     * aux rôles globaux (un restreint ne reçoit jamais cette liste —
     * le contrôleur ne l'appelle que si faculteId est null).
     *
     * @return array<int, array<string, mixed>>
     */
    public function parFaculte(): array
    {
        $programmes = Programme::selectRaw('faculte_id, COUNT(*) as total')->groupBy('faculte_id')->pluck('total', 'faculte_id');
        $niveaux = Niveau::selectRaw('programmes.faculte_id, COUNT(*) as total')
            ->join('programmes', 'programmes.id', '=', 'niveaux.programme_id')
            ->groupBy('programmes.faculte_id')->pluck('total', 'faculte_id');
        $ues = UniteEnseignement::selectRaw('programmes.faculte_id, COUNT(*) as total')
            ->join('programmes', 'programmes.id', '=', 'unites_enseignement.programme_id')
            ->groupBy('programmes.faculte_id')->pluck('total', 'faculte_id');
        $ecues = Matiere::selectRaw('programmes.faculte_id, COUNT(*) as total')
            ->join('unites_enseignement', 'unites_enseignement.id', '=', 'matieres.ue_id')
            ->join('programmes', 'programmes.id', '=', 'unites_enseignement.programme_id')
            ->groupBy('programmes.faculte_id')->pluck('total', 'faculte_id');
        $actives = Maquette::selectRaw('programmes.faculte_id, COUNT(*) as total')
            ->join('programmes', 'programmes.id', '=', 'maquettes.programme_id')
            ->where('maquettes.statut', 'active')
            ->groupBy('programmes.faculte_id')->pluck('total', 'faculte_id');

        return Faculte::orderBy('nom')->get()->map(fn (Faculte $f) => [
            'id' => $f->id,
            'code' => $f->code,
            'nom' => $f->nom,
            'programmes' => (int) ($programmes[$f->id] ?? 0),
            'niveaux' => (int) ($niveaux[$f->id] ?? 0),
            'ues' => (int) ($ues[$f->id] ?? 0),
            'ecues' => (int) ($ecues[$f->id] ?? 0),
            'maquettes_actives' => (int) ($actives[$f->id] ?? 0),
        ])->all();
    }

    /**
     * @return Builder
     */
    private function programmes(?int $faculteId)
    {
        $query = Programme::query();

        if ($faculteId !== null) {
            $query->where('faculte_id', $faculteId);
        }

        return $query;
    }

    /**
     * @return array{gravite: string, entite: string, lien: string, probleme: string, action: string}
     */
    private function anomalie(string $gravite, string $entite, string $lien, string $probleme, string $action): array
    {
        return compact('gravite', 'entite', 'lien', 'probleme', 'action');
    }
}
