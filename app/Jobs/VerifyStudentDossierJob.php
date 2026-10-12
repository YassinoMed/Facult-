<?php

namespace App\Jobs;

use App\Models\AnomalieQualite;
use App\Models\Etudiant;
use App\Services\AuditService;
use App\Services\Verification\VerificationEngine;
use App\Services\Verification\VerificationStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job de vérification asynchrone du dossier étudiant (§18).
 *
 * Traite un lot d'étudiants de façon isolée et idempotente.
 * Dispose de retry, timeout borné et gestion d'erreurs fail-soft.
 */
class VerifyStudentDossierJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    public array $backoff = [10, 60, 180];

    /**
     * @param  array<int, int>  $etudiantIds  Liste des IDs d'étudiants à vérifier
     * @param  bool  $synchroniserQualite  Si true, persiste les anomalies dans la table anomalies_qualite
     * @param  ?int  $utilisateurId  ID de l'utilisateur qui a déclenché le job
     */
    public function __construct(
        public readonly array $etudiantIds,
        public readonly bool $synchroniserQualite = false,
        public readonly ?int $utilisateurId = null,
    ) {
        $this->onQueue('verification');
    }

    public function handle(VerificationEngine $engine, AuditService $audit, VerificationStore $runs): void
    {
        $etudiants = Etudiant::whereIn('id', $this->etudiantIds)
            ->with([
                'inscriptionsAcademiques.anneeUniversitaire',
                'situations',
                'documentsAcademiques',
            ])
            ->get();

        foreach ($etudiants as $etudiant) {
            try {
                $resultat = $engine->verifier($etudiant);
                $runs->enregistrer($etudiant, $resultat, 'job', $this->utilisateurId);

                // Optionnel : persistance dans anomalies_qualite
                if ($this->synchroniserQualite && ! empty($resultat->anomalies)) {
                    foreach ($resultat->anomalies as $anomalie) {
                        $cle = 'verif_'.$etudiant->id.'_'.$anomalie->regle.'_'.md5($anomalie->message);

                        AnomalieQualite::updateOrCreate(
                            ['cle_unique' => $cle],
                            [
                                'source' => 'verification_dossier',
                                'gravite' => $anomalie->gravite === 'bloquant' ? 'critique' : $anomalie->gravite,
                                'entite_type' => 'etudiant',
                                'entite_id' => $etudiant->id,
                                'entite_libelle' => 'Étudiant '.$etudiant->num_inscription,
                                'message' => $anomalie->message,
                                'lien' => route('admin.etudiants.show', $etudiant->id),
                                'action_recommandee' => $anomalie->recommandation,
                                'statut' => 'ouverte',
                                'faculte_id' => $etudiant->faculte_id,
                                'derniere_detection_a' => now(),
                            ]
                        );
                    }
                }

                // Audit sans PII
                $audit->tracer(
                    $etudiant,
                    'verification_dossier',
                    'etudiant',
                    $etudiant->id,
                    null,
                    [
                        'verdict' => $resultat->verdict,
                        'score' => $resultat->score,
                        'anomalies_count' => count($resultat->anomalies),
                        'duree_ms' => $resultat->dureeMs,
                    ],
                    'systeme'
                );
            } catch (\Throwable $e) {
                Log::warning('Échec vérification étudiant en job', [
                    'etudiant_id' => $etudiant->id,
                    'erreur' => $e->getMessage(),
                ]);
            }
        }
    }
}
