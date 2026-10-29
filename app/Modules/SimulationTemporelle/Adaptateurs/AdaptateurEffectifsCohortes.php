<?php

namespace App\Modules\SimulationTemporelle\Adaptateurs;

use App\Models\AffectationGroupe;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Schedule\Models\Group;

/**
 * Adaptateur pilote (ST-02) : effectifs VALIDÉS agrégés par niveau pour
 * une faculté (via programme ou étudiant) et une année universitaire — lecture
 * stricte, aucun nom/CIN : la simulation ne voit que des compteurs.
 */
final class AdaptateurEffectifsCohortes implements ContratAdaptateur
{
    public function etatInitial(?int $faculteId, int $anneeSourceId, ?int $niveauId = null, ?array $groupesIds = null): array
    {
        $annee = AnneeUniversitaire::query()->find($anneeSourceId);

        if ($annee === null) {
            throw new \DomainException("Année universitaire #{$anneeSourceId} introuvable.");
        }

        $codeSql = 'COALESCE(niveaux.code, inscriptions_academiques.niveau)';
        $ordreSql = 'COALESCE(niveaux.ordre, CASE inscriptions_academiques.niveau WHEN \'L1\' THEN 1 WHEN \'L2\' THEN 2 WHEN \'L3\' THEN 3 WHEN \'M1\' THEN 4 WHEN \'M2\' THEN 5 ELSE 99 END)';

        $niveauObj = $niveauId !== null ? Niveau::find($niveauId) : null;

        $effectifs = InscriptionAcademique::query()
            ->where('annee_universitaire_id', $anneeSourceId)
            ->where('inscriptions_academiques.statut', 'valide')
            ->when($niveauId !== null, fn ($q) => $q->where(function ($sub) use ($niveauId, $niveauObj) {
                $sub->where('inscriptions_academiques.niveau_id', $niveauId);
                if ($niveauObj !== null) {
                    $sub->orWhere('inscriptions_academiques.niveau', $niveauObj->code);
                }
            }))
            ->when($groupesIds !== null, fn ($q) => $q->where(function ($sub) use ($groupesIds, $anneeSourceId) {
                $groupesCodes = Group::whereIn('id', $groupesIds)->pluck('code')->all();
                $sub->whereIn(
                    'inscriptions_academiques.etudiant_id',
                    Etudiant::whereIn('groupe_id', $groupesIds)->select('id')
                )->orWhereIn(
                    'inscriptions_academiques.etudiant_id',
                    AffectationGroupe::whereIn('groupe_id', $groupesIds)
                        ->where('annee_universitaire_id', $anneeSourceId)
                        ->where('statut', 'active')
                        ->select('etudiant_id')
                );
                if ($groupesCodes !== []) {
                    $sub->orWhereIn('inscriptions_academiques.groupe', $groupesCodes);
                }
            }))
            ->when($faculteId !== null, fn ($q) => $q->where(function ($sub) use ($faculteId) {
                $sub->whereHas('programme', fn ($p) => $p->where('faculte_id', $faculteId))
                    ->orWhereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculteId));
            }))
            ->leftJoin('niveaux', 'niveaux.id', '=', 'inscriptions_academiques.niveau_id')
            ->selectRaw($codeSql.' as code, '.$ordreSql.' as ordre, COUNT(*) as total')
            ->groupByRaw($codeSql.', '.$ordreSql)
            ->orderByRaw($ordreSql.' asc')
            ->get();

        $cohortes = [];
        foreach ($effectifs as $ligne) {
            if ($ligne->code) {
                $cohortes[$ligne->code] = (int) $ligne->total;
            }
        }

        if ($cohortes === []) {
            throw new \DomainException('Aucune inscription validée sur ce périmètre/année'.($groupesIds !== null ? ' pour les groupes sélectionnés' : '').' : état initial impossible (donnée absente, jamais zéro silencieux).');
        }

        return [
            'annee_source' => $annee->libelle,
            'cohortes' => $cohortes,
            'total_initial' => array_sum($cohortes),
        ];
    }
}
