<?php

namespace App\Services\Interface;

use App\Models\ElementRecent;

/**
 * Éléments récents (§7.7) : persistance minimale (user × type ×
 * référence), plafond 20 par utilisateur. Respect des permissions :
 * l'enregistrement n'a lieu que sur des pages déjà autorisées.
 */
class RecentsService
{
    public function enregistrer(int $userId, string $type, string $reference, string $titre, string $lien): void
    {
        ElementRecent::updateOrCreate(
            ['user_id' => $userId, 'type' => $type, 'reference' => $reference],
            ['titre' => mb_substr($titre, 0, 150), 'lien' => mb_substr($lien, 0, 255), 'updated_at' => now()],
        );

        $ids = ElementRecent::where('user_id', $userId)
            ->orderByDesc('updated_at')
            ->pluck('id')
            ->slice(20)
            ->values();

        if ($ids->isNotEmpty()) {
            ElementRecent::whereIn('id', $ids)->delete();
        }
    }
}
