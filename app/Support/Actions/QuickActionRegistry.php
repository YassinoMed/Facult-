<?php

namespace App\Support\Actions;

use App\Models\User;

/**
 * Registre central d'actions rapides (Phase 7 §7.3) : chaque action
 * déclare ses contextes + une visibilité (permission, périmètre,
 * route existante). Les actions dont la route n'existe pas encore
 * (phases ultérieures) restent enregistrées mais masquées.
 *
 * @phpstan-type ActionDefin array{key: string, label: string, icon: string, contextes: array<int, string>, visible: callable, url: callable}
 */
class QuickActionRegistry
{
    /** @var array<int, ActionDefin> */
    private array $actions = [];

    /**
     * @param  array{key: string, label: string, icon?: string, contextes: array<int, string>, visible: callable, url: callable}  $action
     */
    public function enregistrer(array $action): void
    {
        $this->actions[] = $action + ['icon' => '⚡'];
    }

    /**
     * @return array<int, array{key: string, label: string, icon: string, url: string}>
     */
    public function pour(string $contexte, mixed $sujet, User $utilisateur): array
    {
        $visibles = [];

        foreach ($this->actions as $action) {
            if (! in_array($contexte, $action['contextes'], true)) {
                continue;
            }

            if (! (bool) call_user_func($action['visible'], $utilisateur, $sujet)) {
                continue;
            }

            $url = (string) call_user_func($action['url'], $utilisateur, $sujet);

            if ($url === '') {
                continue;
            }

            $visibles[] = [
                'key' => $action['key'],
                'label' => $action['label'],
                'icon' => $action['icon'],
                'url' => $url,
            ];
        }

        return $visibles;
    }
}
