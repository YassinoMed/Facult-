<?php

namespace App\Contracts;

use App\Models\AppNotification;

/**
 * Contrat pour l'acheminement des notifications sur des canaux externes (LOOP 12) :
 * - Courriel, SMS, push mobile ou passerelle institutionnelle ;
 * - Contenu minimal et non sensible garanti ;
 * - Découplé de la transaction métier pour garantir le fail-soft.
 */
interface CanalNotificationExterneInterface
{
    /**
     * Tente l'envoi de la notification vers le canal externe.
     *
     * @throws \Throwable en cas de panne réseau, passerelle inaccessible ou refus
     */
    public function envoyer(AppNotification $notification): bool;
}
