<?php

namespace App\Modules\Announcements\Listeners;

use App\Modules\Announcements\Events\AnnoncePubliee;
use Illuminate\Support\Facades\Log;

class AuditAnnonce
{
    public function handle(AnnoncePubliee $event): void
    {
        Log::info('annonce.publiee', ['annonce_id' => $event->annonceId, 'acteur_id' => auth()->id()]);
    }
}
