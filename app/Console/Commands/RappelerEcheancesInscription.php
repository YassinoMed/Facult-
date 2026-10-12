<?php

namespace App\Console\Commands;

use App\Models\Etudiant;
use App\Models\RegistrationPeriod;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Commande de notification de rappel d'échéance d'inscription (LOOP 12) :
 * - Détecte les périodes actives dont la clôture approche (J-3 par défaut) ;
 * - Identifie les étudiants dont le dossier est toujours en attente ;
 * - Génère des notifications in-app et file d'attente externes idempotentes ;
 * - Contenu institutionnel sans aucune donnée personnelle sensible (zéro PII).
 */
class RappelerEcheancesInscription extends Command
{
    protected $signature = 'inscriptions:rappeler-echeances
                            {--jours=3 : Seuil de jours avant clôture pour déclencher l\'alerte}';

    protected $description = 'Notifie les étudiants dont le dossier est en attente à l\'approche de la clôture des inscriptions.';

    public function handle(NotificationService $notifications): int
    {
        $seuilJours = max(1, (int) $this->option('jours'));
        $maintenant = now();
        $dateLimite = now()->addDays($seuilJours);

        $periodes = RegistrationPeriod::where('statut', 'active')
            ->whereNotNull('closed_at')
            ->where('closed_at', '>=', $maintenant)
            ->where('closed_at', '<=', $dateLimite)
            ->get();

        if ($periodes->isEmpty()) {
            $this->info("Aucune période d'inscription active n'arrive à échéance dans les {$seuilJours} prochains jours.");

            return self::SUCCESS;
        }

        $totalNotifies = 0;
        $totalIgnores = 0;

        foreach ($periodes as $periode) {
            $joursRestants = max(1, (int) ceil($maintenant->diffInHours($periode->closed_at, false) / 24));
            $dateClotureFormat = $periode->closed_at->format('d/m/Y à H:i');

            $query = Etudiant::where('statut', 'en_attente');
            if ($periode->faculte_id !== null) {
                $query->where('faculte_id', $periode->faculte_id);
            }
            if ($periode->niveau !== null) {
                $query->where('niveau', $periode->niveau);
            }

            $etudiants = $query->get();

            foreach ($etudiants as $etudiant) {
                $cleDedup = "echeance:{$etudiant->id}:{$periode->id}:{$joursRestants}j";

                $notif = $notifications->notifierEtudiant(
                    $etudiant,
                    'dossier.echeance_proche',
                    "Rappel : Clôture des inscriptions dans {$joursRestants} jour(s)",
                    "La période d'inscription « {$periode->nom} » se clôture le {$dateClotureFormat}. Pensez à finaliser et compléter votre dossier avant cette date.",
                    route('etudiant.suivi'),
                    [
                        'periode_id' => $periode->id,
                        'jours_restants' => $joursRestants,
                        'closed_at' => $periode->closed_at->toISOString(),
                    ],
                    1,
                    $cleDedup
                );

                // Détecter si la notification était une nouvelle ou une réutilisée (idempotence)
                if (($notif->data['cle_dedup'] ?? null) === $cleDedup && $notif->wasRecentlyCreated) {
                    $totalNotifies++;
                } else {
                    $totalIgnores++;
                }
            }
        }

        $this->info("Rappels d'échéances traités : {$totalNotifies} notification(s) envoyée(s), {$totalIgnores} ignorée(s) car déjà transmises.");

        return self::SUCCESS;
    }
}
