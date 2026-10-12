<?php

namespace App\Services;

use App\Contracts\CanalNotificationExterneInterface;
use App\Models\AppNotification;
use InvalidArgumentException;
use Throwable;

/**
 * Driver simulé pour le canal externe (LOOP 12) :
 * - Évite tout envoi réseau réel en environnement local / test ;
 * - Bloque formellement toute tentative de diffusion contenant des PII sensibles ;
 * - Permet l'injection d'erreurs en test pour tester la résilience et le retry.
 */
class CanalNotificationExterneSimule implements CanalNotificationExterneInterface
{
    /** @var array<int, array<string, mixed>> */
    private static array $notificationsEnvoyees = [];

    private static ?Throwable $exceptionSimulee = null;

    /**
     * Envoie la notification simulée.
     *
     * @throws Throwable
     */
    public function envoyer(AppNotification $notification): bool
    {
        // 1. Contrôle de sécurité strict : contenu minimal et non sensible (zéro PII)
        $texteComplet = ($notification->titre ?? '').' '.($notification->message ?? '');
        $dataJson = json_encode($notification->data ?? [], JSON_UNESCAPED_UNICODE);

        if (
            preg_match('/\b(cin|passeport|password|mdp|token)\b/i', $texteComplet)
            || preg_match('/"(cin|passeport|password|token)"\s*:/i', (string) $dataJson)
        ) {
            throw new InvalidArgumentException(
                'Donnée sensible détectée dans la notification externe : diffusion interdite pour des motifs de confidentialité.'
            );
        }

        // 2. Simulation de panne pour les tests de résilience
        if (self::$exceptionSimulee !== null) {
            $e = self::$exceptionSimulee;
            throw $e;
        }

        // 3. Enregistrement en mémoire pour inspection
        self::$notificationsEnvoyees[] = [
            'id' => $notification->id,
            'etudiant_id' => $notification->etudiant_id,
            'type' => $notification->type,
            'titre' => $notification->titre,
            'message' => $notification->message,
            'data' => $notification->data,
            'timestamp' => now()->toISOString(),
        ];

        return true;
    }

    /**
     * Permet aux tests d'injecter une exception pour tester la reprise.
     */
    public static function simulerPanne(?Throwable $e): void
    {
        self::$exceptionSimulee = $e;
    }

    /**
     * Récupère les notifications externes transmises en mémoire.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function envoyees(): array
    {
        return self::$notificationsEnvoyees;
    }

    /**
     * Réinitialise le simulateur pour les tests.
     */
    public static function reinitialiser(): void
    {
        self::$notificationsEnvoyees = [];
        self::$exceptionSimulee = null;
    }
}
