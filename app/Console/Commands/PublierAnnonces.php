<?php

namespace App\Console\Commands;

use App\Modules\Announcements\Models\Annonce;
use App\Modules\Announcements\Services\AnnonceService;
use Illuminate\Console\Command;

/**
 * Publication planifiée (§15.6) : publie les annonces `scheduled`
 * échues, expire celles dépassées. Idempotent par statut.
 */
class PublierAnnonces extends Command
{
    protected $signature = 'annonces:publier';

    protected $description = 'Publie les annonces planifiées échues et expire celles dépassées.';

    public function handle(AnnonceService $annonces): int
    {
        $publiees = 0;
        $expirees = 0;

        foreach (Annonce::where('statut', 'scheduled')->where('publiee_a', '<=', now())->cursor() as $annonce) {
            $annonces->publier($annonce);
            $publiees++;
        }

        $expirees = Annonce::where('statut', 'published')->where('expire_a', '<=', now())->update(['statut' => 'expired']);

        $this->info("Publiées : {$publiees}, expirées : {$expirees}.");

        return self::SUCCESS;
    }
}
