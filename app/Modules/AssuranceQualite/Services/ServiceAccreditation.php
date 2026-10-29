<?php

namespace App\Modules\AssuranceQualite\Services;

use App\Models\AnomalieQualite;
use App\Models\HistoriqueAcademique;
use App\Modules\AssuranceQualite\Models\ConstatAccreditation;
use App\Modules\AssuranceQualite\Models\CritereAccreditation;
use App\Modules\AssuranceQualite\Models\RapportAccreditation;
use App\Modules\AssuranceQualite\Models\VersionReferentielAccreditation;
use App\Modules\StudentDossiers\Models\StudentDocument;
use Illuminate\Support\Collection;

/**
 * Lot 16 — Service d'assurance qualité et accréditation.
 *
 * RÈGLES DU CAHIER (interdits) :
 * - INVENTER des critères d'accréditation : INTERDIT — le catalogue est
 *   saisi par l'administration, jamais seedé ;
 * - PRÉSENTER UN RAPPORT INCOMPLET COMME CONFORME : INTERDIT — la
 *   soumission EXIGE un constat EXPLICITE pour chaque critère du
 *   périmètre (y compris `non_evalue`, compté comme tel, jamais comme
 *   conforme) ; les totaux sont FIGÉS à la soumission.
 *
 * Frontières : socle `AnomalieQualite`/`AgregateurQualite` et coffre
 * `StudentDossiers` CONSULTÉS en lecture uniquement (preuves typées).
 */
final class ServiceAccreditation
{
    /** Types de preuve supportés (artefacts RÉELS confirmés du dépôt). */
    private const MODELES_PREUVE = [
        'document' => [StudentDocument::class, 'verification du coffre'],
        'anomalie' => [AnomalieQualite::class, 'socle qualité'],
        'journal' => [HistoriqueAcademique::class, 'journaux'],
    ];

    /**
     * Critères du périmètre d'un rapport :
     * - rapport global (faculte_id NULL) : TOUS les critères de la version ;
     * - rapport de faculté : critères globaux + critères de CETTE faculté.
     *
     * @return Collection<int, CritereAccreditation>
     */
    public function criteresDuPerimetre(VersionReferentielAccreditation $version, ?int $faculteId): Collection
    {
        return CritereAccreditation::query()
            ->where('version_referentiel_id', $version->id)
            ->when($faculteId !== null, function ($q) use ($faculteId) {
                $q->where(function ($w) use ($faculteId) {
                    $w->whereNull('faculte_id')->orWhere('faculte_id', $faculteId);
                });
            })
            ->orderBy('code')
            ->get();
    }

    /**
     * Décompte des constats du périmètre + liste des critères SANS constat.
     *
     * @return array{total: int, conformes: int, partiels: int, non_conformes: int, non_evalues: int, manquants: array<int, string>}
     */
    public function decompte(VersionReferentielAccreditation $version, ?int $faculteId): array
    {
        $perimetre = ConstatAccreditation::perimetrePour($faculteId);
        $constats = ConstatAccreditation::query()
            ->where('perimetre', $perimetre)
            ->whereIn('critere_id', $this->criteresDuPerimetre($version, $faculteId)->pluck('id'))
            ->get()
            ->keyBy('critere_id');

        $resume = ['total' => 0, 'conformes' => 0, 'partiels' => 0, 'non_conformes' => 0, 'non_evalues' => 0, 'manquants' => []];

        foreach ($this->criteresDuPerimetre($version, $faculteId) as $critere) {
            $resume['total']++;

            $constat = $constats->get($critere->id);
            if ($constat === null) {
                $resume['manquants'][] = $critere->code;

                continue;
            }

            match ($constat->evaluation) {
                'conforme' => $resume['conformes']++,
                'partiellement_conforme' => $resume['partiels']++,
                'non_conforme' => $resume['non_conformes']++,
                'non_evalue' => $resume['non_evalues']++,
                default => $resume['manquants'][] = $critere->code,
            };
        }

        return $resume;
    }

    /**
     * Soumission d'un rapport : complétude EXIGÉE (aucun critère sans constat),
     * totaux FIGÉS (un `non_evalue` explicite reste compté non évalué — jamais
     * conforme). Lève InvalidArgumentException si des constats manquent.
     */
    public function soumettre(RapportAccreditation $rapport): RapportAccreditation
    {
        $decompte = $this->decompte($rapport->version, $rapport->faculte_id);

        if ($decompte['manquants'] !== []) {
            throw new \InvalidArgumentException(
                'Constats manquants pour '.count($decompte['manquants']).' critère(s) : '
                .implode(', ', array_slice($decompte['manquants'], 0, 10))
                .'. Un rapport incomplet ne peut pas être soumis (jamais présenté comme conforme).'
            );
        }

        $rapport->forceFill([
            'statut' => 'soumis',
            'soumis_le' => now(),
            'total_criteres' => $decompte['total'],
            'total_conformes' => $decompte['conformes'],
            'total_partiels' => $decompte['partiels'],
            'total_non_conformes' => $decompte['non_conformes'],
            'total_non_evalues' => $decompte['non_evalues'],
        ])->save();

        return $rapport;
    }

    /**
     * Vérifie l'existence RÉELLE d'une cible de preuve et retourne son
     * libellé daté (copie) — retourne null si l'artefact n'existe pas.
     */
    public function resoudreCiblePreuve(string $type, int $cibleId): ?string
    {
        $definition = self::MODELES_PREUVE[$type] ?? null;
        if ($definition === null) {
            return null;
        }

        $artefact = $definition[0]::query()->find($cibleId);
        if ($artefact === null) {
            return null;
        }

        return mb_substr($this->libelleArtefact($type, $artefact), 0, 240);
    }

    /**
     * @return string libellé lisible et daté de l'artefact cité en preuve
     */
    private function libelleArtefact(string $type, object $artefact): string
    {
        return match ($type) {
            'document' => 'Document #'.$artefact->id
                .(isset($artefact->original_filename) ? ' — '.basename((string) $artefact->original_filename) : ''),
            'anomalie' => 'Anomalie #'.$artefact->id.' — '.mb_substr((string) $artefact->message, 0, 120),
            'journal' => 'Journal #'.$artefact->id.' — '.mb_substr((string) $artefact->action, 0, 120),
            default => 'Preuve #'.$artefact->id,
        };
    }

    /**
     * Types de preuve supportés (exposés pour validation contrôleur).
     *
     * @return array<int, string>
     */
    public function typesPreuve(): array
    {
        return array_keys(self::MODELES_PREUVE);
    }
}
