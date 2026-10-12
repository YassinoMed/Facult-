<?php

namespace App\Listeners;

use App\Events\StudentRegistered;
use App\Models\AnomalieQualite;
use App\Services\AuditService;
use App\Services\Verification\VerificationEngine;
use Illuminate\Support\Facades\Log;

/**
 * LOOP 07 — Détection automatique des doublons potentiels et incohérences
 * dès la soumission d'une inscription publique (§7).
 *
 * Exécute le VerificationEngine (notamment DoublonsRule, IdentiteCoherenceRule,
 * MensDivergenceRule) et enregistre les anomalies de niveau avertissement / critique
 * dans `anomalies_qualite` pour examen par un agent habilité.
 *
 * N'effectue JAMAIS de rejet bloquant ni de fusion automatique : les homonymes
 * légitimes et cas ambigus sont orientés vers une vérification humaine.
 */
class DetecterIncoherencesInscription
{
    public function __construct(
        private readonly VerificationEngine $verification,
        private readonly AuditService $audit,
    ) {}

    public function handle(StudentRegistered $event): void
    {
        try {
            $resultat = $this->verification->verifier($event->etudiant);

            if (empty($resultat->anomalies)) {
                return;
            }

            foreach ($resultat->anomalies as $anomalie) {
                $cle = 'inscr_'.$event->etudiant->id.'_'.$anomalie->regle.'_'.md5($anomalie->message);

                AnomalieQualite::updateOrCreate(
                    ['cle_unique' => $cle],
                    [
                        'source' => 'verification_dossier',
                        'gravite' => $anomalie->gravite === 'bloquant' ? 'critique' : $anomalie->gravite,
                        'entite_type' => 'etudiant',
                        'entite_id' => $event->etudiant->id,
                        'entite_libelle' => 'Étudiant '.$event->etudiant->num_inscription,
                        'message' => $anomalie->message,
                        'lien' => route('admin.etudiants.show', $event->etudiant->id),
                        'action_recommandee' => $anomalie->recommandation,
                        'statut' => 'ouverte',
                        'faculte_id' => $event->etudiant->faculte_id,
                        'derniere_detection_a' => now(),
                    ]
                );
            }

            $this->audit->tracer(
                $event->etudiant,
                'inscription.incoherences_detectees',
                'etudiant',
                $event->etudiant->id,
                null,
                ['nombre_anomalies' => count($resultat->anomalies)],
                'systeme',
            );

            Log::info('inscription.incoherences_detectees', [
                'etudiant_id' => $event->etudiant->id,
                'nombre_anomalies' => count($resultat->anomalies),
            ]);
        } catch (\Throwable $e) {
            report($e);
            Log::warning('inscription.verification_incoherences_echec', [
                'etudiant_id' => $event->etudiant->id,
            ]);
        }
    }
}
