<?php

namespace App\Services;

use App\Models\AnomalieQualite;
use App\Models\Etudiant;
use App\Modules\Enseignement\Services\QualitePedagogiqueService;
use App\Modules\Referentiel\Services\ReferentielStatsService;
use App\Services\Verification\VerificationEngine;

/**
 * Agrégateur qualité (§19.1) : consomme les détecteurs existants
 * (population, référentiel, pédagogie) sans les dupliquer, et
 * normalise vers le format persisté `anomalies_qualite`.
 */
class AgregateurQualite
{
    public const SOURCES = ['population', 'referentiel', 'pedagogie', 'verification_dossier'];

    /** Gravités métier → sévérités normalisées. */
    public const GRAVITES = [
        'info' => 'info',
        'avertissement' => 'avertissement',
        'erreur' => 'erreur',
        'critique' => 'critique',
    ];

    public function __construct(
        private PopulationStatsService $population,
        private ReferentielStatsService $referentiel,
        private QualitePedagogiqueService $pedagogie,
        private VerificationEngine $verification,
    ) {}

    /**
     * @return array<int, array{cle_unique: string, source: string, gravite: string, message: string, lien: string, action_recommandee: string, entite_libelle: string}>
     */
    public function detecter(?int $faculteId = null, int $limite = 200): array
    {
        $brutes = [];

        foreach ($this->population->anomalies($faculteId, $limite) as $anomalie) {
            $brutes[] = ['source' => 'population'] + $anomalie;
        }

        foreach ($this->referentiel->anomalies($faculteId, $limite) as $anomalie) {
            $brutes[] = ['source' => 'referentiel'] + $anomalie;
        }

        foreach ($this->pedagogie->anomalies($faculteId, $limite) as $anomalie) {
            $brutes[] = ['source' => 'pedagogie'] + $anomalie;
        }

        // Source 4 : vérification intelligente du dossier étudiant.
        $etudiants = Etudiant::where('statut', 'valide')
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->limit(min(20, $limite))->get();

        foreach ($etudiants as $etudiant) {
            $resultat = $this->verification->verifier($etudiant);

            foreach ($this->verification->versFormatQualite($resultat, $etudiant->num_inscription ?? "#{$etudiant->id}") as $anomalie) {
                $brutes[] = ['source' => 'verification_dossier'] + $anomalie;
            }
        }

        $normalisees = [];

        foreach (array_slice($brutes, 0, $limite) as $anomalie) {
            $gravite = self::GRAVITES[$anomalie['gravite']] ?? 'avertissement';

            $normalisees[] = [
                'cle_unique' => sha1($anomalie['source'].'|'.$gravite.'|'.$anomalie['probleme'].'|'.$anomalie['entite']),
                'source' => $anomalie['source'],
                'gravite' => $gravite,
                'message' => $anomalie['probleme'],
                'lien' => $anomalie['lien'],
                'action_recommandee' => $anomalie['action'],
                'entite_libelle' => $anomalie['entite'],
            ];
        }

        return $normalisees;
    }

    /**
     * @return array{creees: int, reactualisees: int, resolues: int}
     */
    public function synchroniser(?int $faculteId = null, int $limite = 500): array
    {
        $detectees = $this->detecter($faculteId, $limite);
        $cles = array_column($detectees, 'cle_unique');

        $creees = 0;
        $reactualisees = 0;

        foreach ($detectees as $anomalie) {
            $existante = AnomalieQualite::where('cle_unique', $anomalie['cle_unique'])->first();

            if ($existante === null) {
                AnomalieQualite::create($anomalie + [
                    'statut' => 'ouverte',
                    'faculte_id' => $faculteId,
                    'derniere_detection_a' => now(),
                ]);
                $creees++;
            } else {
                $existante->update([
                    'message' => $anomalie['message'],
                    'faculte_id' => $faculteId,
                    'derniere_detection_a' => now(),
                ]);
                $reactualisees++;
            }
        }

        // Résolution automatique : une anomalie ouverte non redétectée
        // sur un balayage complet (sans filtre faculté) est résolue.
        $resolues = 0;

        if ($faculteId === null) {
            $resolues = AnomalieQualite::whereIn('statut', ['ouverte', 'acquittee', 'en_cours'])
                ->whereNotIn('cle_unique', $cles)
                ->update(['statut' => 'resolue', 'resolue_a' => now(), 'resolution' => 'Non redétectée au balayage.']);
        }

        return ['creees' => $creees, 'reactualisees' => $reactualisees, 'resolues' => $resolues];
    }
}
