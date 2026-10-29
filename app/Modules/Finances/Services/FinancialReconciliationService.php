<?php

namespace App\Modules\Finances\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Rapprochement financier EN LECTURE SEULE.
 *
 * Aucune écriture, aucun paiement confirmé automatiquement, aucune
 * correction de masse. Le service produit des constats vérifiables :
 * volumes, états contradictoires, liens orphelins, doublons et
 * réutilisations. Les montants sont ceux DÉCLARÉS/instantanés, jamais une
 * preuve comptable.
 */
class FinancialReconciliationService
{
    /**
     * @return array<string, mixed>
     */
    public function rapport(): array
    {
        $dossiersParStatut = DB::table('dossiers_paiement')
            ->select('statut', DB::raw('COUNT(*) as total'))
            ->groupBy('statut')
            ->pluck('total', 'statut')
            ->map(fn ($total): int => (int) $total)
            ->all();

        $recusParStatut = DB::table('recus_paiement')
            ->select('statut', DB::raw('COUNT(*) as total'))
            ->groupBy('statut')
            ->pluck('total', 'statut')
            ->map(fn ($total): int => (int) $total)
            ->all();

        $anomalies = [
            'recu_valide_incoherent' => $this->recuValideIncoherent(),
            'recu_valide_sans_dossier_valide' => $this->recuValideSansDossierValide(),
            'dossier_valide_sans_recu_valide' => $this->dossierValideSansRecuValide(),
            'dossier_en_attente_sans_recu_en_attente' => $this->dossierEnAttenteSansRecu(),
            'recu_dossier_incoherent' => $this->recuDossierIncoherent(),
            'dossier_orphelin' => $this->dossierOrphelin(),
            'recu_orphelin' => $this->recuOrphelin(),
            'fichier_reutilise' => $this->fichierReutilise(),
            'metadonnees_paiement_dupliquees' => $this->metadonneesDupliquees(),
            'indicateur_paye_contradictoire' => $this->indicateurPayeContradictoire(),
        ];

        $nombreAnomalies = array_sum(array_map(
            fn (array $anomalie): int => (int) $anomalie['total'],
            $anomalies,
        ));

        return [
            'lecture_seule' => true,
            'mutation_effectuee' => false,
            'genere_le' => now()->toIso8601String(),
            'volumes' => [
                'dossiers' => (int) DB::table('dossiers_paiement')->count(),
                'recus' => (int) DB::table('recus_paiement')->count(),
                'recus_valides' => (int) DB::table('recus_paiement')->where('est_valide', true)->count(),
                'montant_declare_valide_centimes' => (int) DB::table('recus_paiement')
                    ->where('est_valide', true)
                    ->sum('montant_declare_centimes'),
            ],
            'dossiers_par_statut' => $dossiersParStatut,
            'recus_par_statut' => $recusParStatut,
            'anomalies' => $anomalies,
            'nombre_anomalies' => $nombreAnomalies,
            'avertissement' => 'Les montants sont déclaratifs/instantanés. Un reçu déposé n’est jamais un paiement confirmé ; ce rapport ne corrige aucune donnée.',
        ];
    }

    /**
     * @return array{total: int, exemples: array<int, int>}
     */
    private function recuValideIncoherent(): array
    {
        return $this->anomalie(
            DB::table('recus_paiement')
                ->where(function ($q): void {
                    $q->where(fn ($w) => $w->where('statut', 'VALIDE')->where(fn ($x) => $x->whereNull('est_valide')->orWhere('est_valide', false)))
                        ->orWhere(fn ($w) => $w->where('statut', '!=', 'VALIDE')->where('est_valide', true));
                }),
        );
    }

    private function recuValideSansDossierValide(): array
    {
        return $this->anomalie(
            DB::table('recus_paiement as r')
                ->join('dossiers_paiement as d', 'd.id', '=', 'r.dossier_paiement_id')
                ->where('r.est_valide', true)
                ->where('d.statut', '!=', 'VALIDE')
                ->select('r.id'),
        );
    }

    private function dossierValideSansRecuValide(): array
    {
        return $this->anomalie(
            DB::table('dossiers_paiement as d')
                ->where('d.statut', 'VALIDE')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('recus_paiement as r')
                    ->whereColumn('r.dossier_paiement_id', 'd.id')
                    ->where('r.est_valide', true))
                ->select('d.id'),
        );
    }

    private function dossierEnAttenteSansRecu(): array
    {
        return $this->anomalie(
            DB::table('dossiers_paiement as d')
                ->whereIn('d.statut', ['RECU_DEPOSE', 'EN_VERIFICATION'])
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('recus_paiement as r')
                    ->whereColumn('r.dossier_paiement_id', 'd.id')
                    ->whereIn('r.statut', ['RECU_DEPOSE', 'EN_VERIFICATION']))
                ->select('d.id'),
        );
    }

    private function recuDossierIncoherent(): array
    {
        return $this->anomalie(
            DB::table('recus_paiement as r')
                ->join('dossiers_paiement as d', 'd.id', '=', 'r.dossier_paiement_id')
                ->whereColumn('r.inscription_academique_id', '!=', 'd.inscription_academique_id')
                ->select('r.id'),
        );
    }

    private function dossierOrphelin(): array
    {
        return $this->anomalie(
            DB::table('dossiers_paiement as d')
                ->leftJoin('inscriptions_academiques as i', 'i.id', '=', 'd.inscription_academique_id')
                ->whereNull('i.id')
                ->select('d.id'),
        );
    }

    private function recuOrphelin(): array
    {
        return $this->anomalie(
            DB::table('recus_paiement as r')
                ->leftJoin('dossiers_paiement as d', 'd.id', '=', 'r.dossier_paiement_id')
                ->whereNull('d.id')
                ->select('r.id'),
        );
    }

    private function fichierReutilise(): array
    {
        $groupes = DB::table('recus_paiement')
            ->select('sha256', DB::raw('COUNT(*) as total'))
            ->groupBy('sha256')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        return [
            'total' => (int) $groupes->sum('total'),
            'exemples' => $groupes->pluck('sha256')->take(10)->values()->all(),
        ];
    }

    private function metadonneesDupliquees(): array
    {
        $groupes = DB::table('recus_paiement')
            ->select(
                'reference_paiement',
                'date_paiement',
                'montant_declare_centimes',
                DB::raw('COUNT(*) as total'),
            )
            ->groupBy('reference_paiement', 'date_paiement', 'montant_declare_centimes')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        return [
            'total' => (int) $groupes->sum('total'),
            'exemples' => $groupes
                ->map(fn ($groupe): string => $groupe->reference_paiement.'|'.$groupe->date_paiement.'|'.$groupe->montant_declare_centimes)
                ->take(10)
                ->values()
                ->all(),
        ];
    }

    private function indicateurPayeContradictoire(): array
    {
        return $this->anomalie(
            DB::table('inscriptions_academiques as i')
                ->leftJoin('dossiers_paiement as d', 'd.inscription_academique_id', '=', 'i.id')
                ->where(function ($q): void {
                    $q->where(fn ($w) => $w->where('i.paye', true)->where(fn ($x) => $x->whereNull('d.id')->orWhere('d.statut', '!=', 'VALIDE')))
                        ->orWhere(fn ($w) => $w->where('i.paye', false)->where('d.statut', 'VALIDE'));
                })
                ->select('i.id'),
        );
    }

    /**
     * @param  Builder  $requete
     * @return array{total: int, exemples: array<int, int>}
     */
    private function anomalie($requete): array
    {
        $total = (int) (clone $requete)->count();

        return [
            'total' => $total,
            'exemples' => $total === 0
                ? []
                : $requete->limit(10)->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        ];
    }
}
