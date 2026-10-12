<?php

namespace App\Modules\Finances\Listeners;

use App\Modules\Finances\Events\FactureEmise;
use App\Modules\Finances\Events\PaiementConfirme;
use App\Modules\Finances\Events\RemboursementTraite;
use Illuminate\Support\Facades\Log;

class AuditFinance
{
    public function facture(FactureEmise $event): void
    {
        Log::info('finance.facture', ['facture_id' => $event->factureId, 'acteur_id' => auth()->id()]);
    }

    public function paiement(PaiementConfirme $event): void
    {
        Log::info('finance.paiement', ['paiement_id' => $event->paiementId, 'acteur_id' => auth()->id()]);
    }

    public function remboursement(RemboursementTraite $event): void
    {
        Log::info('finance.remboursement.'.$event->cible, ['remboursement_id' => $event->remboursementId, 'acteur_id' => auth()->id()]);
    }
}
