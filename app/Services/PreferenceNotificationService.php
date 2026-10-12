<?php

namespace App\Services;

use App\Models\NotificationPreference;
use App\Models\NotificationTemplate;

/**
 * Gabarits + préférences (Phase 15 §15.1) : rendu {{variable}}
 * échappé, opt-out par catégorie sauf alertes obligatoires.
 */
class PreferenceNotificationService
{
    /** Catégories jamais désactivables (alertes institutionnelles). */
    public const OBLIGATOIRES = ['resultats', 'documents_officiels'];

    public const CATEGORIES = ['resultats', 'documents_officiels', 'demandes', 'annonces', 'inscriptions'];

    /**
     * @param  array<string, mixed>  $variables
     * @return array{titre: string, message: string}|null null si gabarit absent/inactif.
     */
    public function rendre(string $code, array $variables = []): ?array
    {
        $gabarit = NotificationTemplate::where('code', $code)->where('actif', true)->first();

        if ($gabarit === null) {
            return null;
        }

        $remplacer = fn ($texte) => preg_replace_callback(
            '/\{([a-zA-Z0-9_]+)\}/',
            fn ($m) => e((string) ($variables[$m[1]] ?? '')),
            $texte
        );

        return [
            'titre' => $gabarit->titre,
            'message' => (string) $remplacer($gabarit->message),
        ];
    }

    public function notifiable(?int $etudiantId, ?int $userId, string $categorie): bool
    {
        if (in_array($categorie, self::OBLIGATOIRES, true)) {
            return true;
        }

        $preference = NotificationPreference::where('categorie', $categorie)
            ->when($etudiantId !== null, fn ($q) => $q->where('etudiant_id', $etudiantId))
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->first();

        return $preference === null || (bool) $preference->actif;
    }

    public function definir(?int $etudiantId, ?int $userId, string $categorie, bool $actif): void
    {
        if (! in_array($categorie, self::CATEGORIES, true)) {
            throw new \InvalidArgumentException("Catégorie « {$categorie} » inconnue.");
        }

        NotificationPreference::updateOrCreate(
            ['etudiant_id' => $etudiantId, 'user_id' => $userId, 'categorie' => $categorie],
            ['actif' => $actif],
        );
    }
}
