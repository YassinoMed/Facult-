<?php

namespace App\Modules\Evaluation\Services;

use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Models\SurveillanceConstraintConfig;

/**
 * Résout la configuration applicable à un périmètre (1 seule requête),
 * par priorité : session > faculté+année > année > système.
 * Le DTO retourné est immuable pendant toute l'exécution.
 */
class SurveillanceConstraintResolver
{
    public function resoudre(?int $faculteId, ?int $anneeId, ?int $sessionId): ContraintesSurveillance
    {
        // Une seule requête : toutes les lignes potentiellement
        // applicables, puis choix par priorité en mémoire (aucun N+1 :
        // le resolver est appelé une fois par exécution).
        $candidats = SurveillanceConstraintConfig::query()
            ->where(function ($q) use ($sessionId) {
                if ($sessionId !== null) {
                    $q->where('session_examen_id', $sessionId)->orWhereNull('session_examen_id');
                } else {
                    $q->whereNull('session_examen_id');
                }
            })
            ->where(function ($q) use ($faculteId) {
                if ($faculteId !== null) {
                    $q->where('faculte_id', $faculteId)->orWhereNull('faculte_id');
                } else {
                    $q->whereNull('faculte_id');
                }
            })
            ->where(function ($q) use ($anneeId) {
                if ($anneeId !== null) {
                    $q->where('annee_universitaire_id', $anneeId)->orWhereNull('annee_universitaire_id');
                } else {
                    $q->whereNull('annee_universitaire_id');
                }
            })
            ->orderByDesc('id')
            ->get();

        // Seules les 4 formes hiérarchiques sont éligibles (les lignes
        // hors contrat, créées hors validation, sont ignorées).
        $choisie = $candidats
            ->filter(fn ($c) => $this->priorite($c) > 0)
            ->sortByDesc(fn ($c) => [$this->priorite($c), $c->id])
            ->first();

        if ($choisie === null) {
            return new ContraintesSurveillance;
        }

        return ContraintesSurveillance::depuisParams(array_merge($choisie->toArray(), [
            'config_id' => $choisie->id,
            'config_version' => $choisie->version,
        ]));
    }

    public function resoudrePourSession(SessionExamen $session, ?int $faculteId): ContraintesSurveillance
    {
        return $this->resoudre($faculteId, $session->annee_universitaire_id, $session->id);
    }

    /**
     * Priorité hiérarchique (0 = forme hors contrat, ignorée) :
     * session (4) > faculté+année (3) > année (2) > système (1).
     */
    private function priorite(SurveillanceConstraintConfig $config): int
    {
        if ($config->session_examen_id !== null) {
            return $config->faculte_id === null && $config->annee_universitaire_id === null ? 4 : 0;
        }

        if ($config->faculte_id !== null && $config->annee_universitaire_id !== null) {
            return 3;
        }

        if ($config->faculte_id === null && $config->annee_universitaire_id !== null) {
            return 2;
        }

        if ($config->faculte_id === null && $config->annee_universitaire_id === null) {
            return 1;
        }

        return 0;
    }
}
