<?php

namespace App\Services\Acquis;

use App\Models\AppNotification;
use App\Models\Etudiant;
use App\Models\User;
use App\Modules\ParcoursAcademique\Models\ParcoursPlan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Service de notifications idempotentes pour la gestion des acquis et du redoublement.
 * Garantit l'absence de spams et de doublons via un mécanisme d'idempotence strict.
 *
 * RÈGLE D'OR : L'échec éventuel d'un transport ou d'un canal de notification ne doit
 * JAMAIS faire échouer ni annuler une  décision académique officiellement enregistrée.
 */
class NotificationRedoublementService
{
    /**
     * Durée de verrouillage de la clé d'idempotence (en secondes) - 30 jours par défaut.
     */
    protected int $dureeIdempotenceSecondes = 2592000;

    /**
     * Notifie l'étudiant de l'établissement de sa proposition de parcours de redoublement.
     */
    public function notifierPlanEtabli(Etudiant $etudiant, ParcoursPlan $plan): ?AppNotification
    {
        $cleIdempotence = sprintf(
            'notif_redoublement_plan_etabli_%d_%d_%s',
            $etudiant->id,
            $plan->id,
            $plan->statut
        );

        if (! $this->reserverCle($cleIdempotence)) {
            Log::info('Notification plan établi ignorée car déjà émise (idempotence)', [
                'etudiant_id' => $etudiant->id,
                'plan_id' => $plan->id,
            ]);

            return null;
        }

        try {
            $contenu = is_array($plan->contenu) ? $plan->contenu : [];
            $creditsRestants = (int) ($contenu['credits_restants'] ?? 60);
            $creditsConserves = (int) ($contenu['credits_acquis_conserves'] ?? 0);

            return AppNotification::create([
                'etudiant_id' => $etudiant->id,
                'user_id' => null,
                'type' => 'acquis.plan_etabli',
                'titre' => 'Parcours académique de redoublement disponible',
                'message' => sprintf(
                    'Votre proposition de parcours pour l\'année universitaire en cours a été établie (%d crédits à valider, %d conservés). Vous pouvez la consulter dans votre espace.',
                    $creditsRestants,
                    $creditsConserves
                ),
                'data' => [
                    'plan_id' => $plan->id,
                    'credits_restants' => $creditsRestants,
                    'credits_conserves' => $creditsConserves,
                    'statut' => $plan->statut,
                ],
                'lien' => route('etudiant.acquis.index'),
                'priorite' => 'normale',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Échec d\'acheminement de la notification d\'établissement de plan (non bloquant).', [
                'etudiant_id' => $etudiant->id,
                'plan_id' => $plan->id,
                'erreur_classe' => get_class($e),
            ]);

            return null;
        }
    }

    /**
     * Notifie l'étudiant et la scolarité de la validation officielle d'un plan de redoublement.
     */
    public function notifierPlanValide(Etudiant $etudiant, ParcoursPlan $plan, ?User $validateur = null): ?AppNotification
    {
        $cleIdempotence = sprintf(
            'notif_redoublement_plan_valide_%d_%d',
            $etudiant->id,
            $plan->id
        );

        if (! $this->reserverCle($cleIdempotence)) {
            Log::info('Notification plan validé ignorée car déjà émise (idempotence)', [
                'etudiant_id' => $etudiant->id,
                'plan_id' => $plan->id,
            ]);

            return null;
        }

        try {
            return AppNotification::create([
                'etudiant_id' => $etudiant->id,
                'user_id' => null,
                'type' => 'acquis.plan_valide',
                'titre' => 'Validation officielle de votre contrat pédagogique',
                'message' => 'Votre plan de réinscription ciblée a été validé par la scolarité. Vos unités d\'enseignement sont désormais figées pour la prochaine session.',
                'data' => [
                    'plan_id' => $plan->id,
                    'validateur_id' => $validateur?->id,
                    'valide_le' => now()->toIso8601String(),
                ],
                'lien' => route('etudiant.acquis.index'),
                'priorite' => 'haute',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Échec d\'acheminement de la notification de validation de plan (non bloquant).', [
                'etudiant_id' => $etudiant->id,
                'plan_id' => $plan->id,
                'erreur_classe' => get_class($e),
            ]);

            return null;
        }
    }

    /**
     * Notifie un agent ou responsable de scolarité à la fin d'une campagne de masse.
     */
    public function notifierCampagneTerminee(User $destinataire, array $bilanCampagne): ?AppNotification
    {
        $campagneId = $bilanCampagne['campagne_id'] ?? uniqid('campagne_');
        $cleIdempotence = sprintf('notif_campagne_terminee_%d_%s', $destinataire->id, $campagneId);

        if (! $this->reserverCle($cleIdempotence)) {
            return null;
        }

        try {
            $nbTraites = $bilanCampagne['etudiants_traites'] ?? 0;
            $nbSucces = $bilanCampagne['etudiants_succes'] ?? 0;
            $nbErreurs = $bilanCampagne['etudiants_erreurs'] ?? 0;

            return AppNotification::create([
                'etudiant_id' => null,
                'user_id' => $destinataire->id,
                'type' => 'acquis.campagne_terminee',
                'titre' => 'Campagne de redoublement de masse terminée',
                'message' => sprintf(
                    'Traitement de cohorte achevé : %d dossiers traités (%d validés/préparés, %d alertes/erreurs).',
                    $nbTraites,
                    $nbSucces,
                    $nbErreurs
                ),
                'data' => [
                    'campagne_id' => $campagneId,
                    'statistiques' => $bilanCampagne,
                ],
                'lien' => route('admin.redoublement.index'),
                'priorite' => $nbErreurs > 0 ? 'haute' : 'normale',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Échec d\'acheminement de la notification de fin de campagne (non bloquant).', [
                'destinataire_id' => $destinataire->id,
                'campagne_id' => $campagneId,
                'erreur_classe' => get_class($e),
            ]);

            return null;
        }
    }

    /**
     * Tente de réserver une clé d'idempotence.
     */
    protected function reserverCle(string $cle): bool
    {
        return Cache::add($cle, true, $this->dureeIdempotenceSecondes);
    }
}
