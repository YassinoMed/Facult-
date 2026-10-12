<?php

namespace App\Services;

use App\Jobs\AcheminerNotificationExterneJob;
use App\Models\AppNotification;
use App\Models\Etudiant;
use App\Models\User;
use App\Modules\Schedule\Models\Teacher;

/**
 * Centre de notifications (§4-6) : création typée pour users,
 * étudiants et enseignants, compteurs non-lues, marquage. Les modules
 * appellent ce service (jamais de table manipulée en direct hors tests).
 */
class NotificationService
{
    /**
     * @param  array<string, mixed>  $data
     * @param  string|null  $cleDedup  Clé d'idempotence (LOOP 12) : si une
     *                                 notification de même type + même clé
     *                                 existe déjà pour cet étudiant, elle
     *                                 est RETOURNÉE sans réinsérer (un
     *                                 événement rejoué ne duplique jamais).
     *                                 Null = comportement historique.
     */
    public function notifierEtudiant(
        Etudiant $etudiant,
        string $type,
        string $titre,
        string $message,
        ?string $lien = null,
        array $data = [],
        int $priorite = 0,
        ?string $cleDedup = null,
    ): AppNotification {
        if ($cleDedup !== null) {
            /** @var AppNotification|null $existante */
            $existante = AppNotification::query()
                ->where('etudiant_id', $etudiant->id)
                ->where('type', $type)
                ->where('data->cle_dedup', $cleDedup)
                ->first();

            if ($existante !== null) {
                return $existante;
            }

            $data['cle_dedup'] = $cleDedup;
        }

        if (! isset($data['statut_envoi'])) {
            $data['statut_envoi'] = 'en_attente';
        }

        $notification = AppNotification::create([
            'etudiant_id' => $etudiant->id,
            'type' => $type,
            'titre' => mb_substr($titre, 0, 150),
            'message' => mb_substr($message, 0, 500),
            'lien' => $lien !== null ? mb_substr($lien, 0, 255) : null,
            'data' => $data,
            'priorite' => max(0, min(3, $priorite)),
        ]);

        $this->acheminerExterne($notification);

        return $notification;
    }

    /**
     * Acheminement asynchrone sécurisé vers le canal externe (LOOP 12).
     * Fail-soft garanti : ne lève aucune exception vers l'appelant.
     */
    public function acheminerExterne(AppNotification $notification): void
    {
        try {
            AcheminerNotificationExterneJob::dispatch($notification->id);
        } catch (\Throwable) {
            // Fail-soft : la notification en base reste intègre
        }
    }

    /**
     * Reprise d'un envoi externe en échec (LOOP 12).
     */
    public function retenterEnvoiExterne(AppNotification $notification): void
    {
        $data = $notification->data ?? [];
        $data['statut_envoi'] = 'en_attente';
        $data['erreur'] = null;
        $notification->update(['data' => $data]);

        $this->acheminerExterne($notification);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function notifierUser(
        User $user,
        string $type,
        string $titre,
        string $message,
        ?string $lien = null,
        array $data = [],
        int $priorite = 0,
    ): AppNotification {
        return AppNotification::create([
            'user_id' => $user->id,
            'type' => $type,
            'titre' => mb_substr($titre, 0, 150),
            'message' => mb_substr($message, 0, 500),
            'lien' => $lien !== null ? mb_substr($lien, 0, 255) : null,
            'data' => $data,
            'priorite' => max(0, min(3, $priorite)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function notifierEnseignant(
        Teacher $enseignant,
        string $type,
        string $titre,
        string $message,
        ?string $lien = null,
        array $data = [],
        int $priorite = 0,
    ): AppNotification {
        return AppNotification::create([
            'teacher_id' => $enseignant->id,
            'type' => $type,
            'titre' => mb_substr($titre, 0, 150),
            'message' => mb_substr($message, 0, 500),
            'lien' => $lien !== null ? mb_substr($lien, 0, 255) : null,
            'data' => $data,
            'priorite' => max(0, min(3, $priorite)),
        ]);
    }

    /**
     * Diffusion de masse (annonces, alertes) : insertion en lots,
     * jamais de boucle N+1 de create().
     *
     * @param  iterable<int>  $etudiantIds
     * @param  array<string, mixed>  $data
     */
    public function notifierPlusieursEtudiants(
        iterable $etudiantIds,
        string $type,
        string $titre,
        string $message,
        ?string $lien = null,
        array $data = [],
        int $priorite = 0,
    ): int {
        return $this->notifierPlusieurs('etudiant_id', $etudiantIds, $type, $titre, $message, $lien, $data, $priorite);
    }

    /**
     * @param  iterable<int>  $userIds
     * @param  array<string, mixed>  $data
     */
    public function notifierPlusieursUsers(
        iterable $userIds,
        string $type,
        string $titre,
        string $message,
        ?string $lien = null,
        array $data = [],
        int $priorite = 0,
    ): int {
        return $this->notifierPlusieurs('user_id', $userIds, $type, $titre, $message, $lien, $data, $priorite);
    }

    /**
     * @param  iterable<int>  $enseignantIds
     * @param  array<string, mixed>  $data
     */
    public function notifierPlusieursEnseignants(
        iterable $enseignantIds,
        string $type,
        string $titre,
        string $message,
        ?string $lien = null,
        array $data = [],
        int $priorite = 0,
    ): int {
        return $this->notifierPlusieurs('teacher_id', $enseignantIds, $type, $titre, $message, $lien, $data, $priorite);
    }

    /**
     * Purge des notifications lues anciennes (données personnelles).
     */
    public function purgerLues(int $jours = 180): int
    {
        return AppNotification::whereNotNull('read_at')
            ->where('read_at', '<', now()->subDays($jours))
            ->delete();
    }

    public function nonLuesEtudiant(int $etudiantId): int
    {
        return AppNotification::where('etudiant_id', $etudiantId)->whereNull('read_at')->count();
    }

    public function nonLuesUser(int $userId): int
    {
        return AppNotification::where('user_id', $userId)->whereNull('read_at')->count();
    }

    public function nonLuesEnseignant(int $enseignantId): int
    {
        return AppNotification::where('teacher_id', $enseignantId)->whereNull('read_at')->count();
    }

    public function toutMarquerLuEtudiant(int $etudiantId): int
    {
        return AppNotification::where('etudiant_id', $etudiantId)->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function toutMarquerLuUser(int $userId): int
    {
        return AppNotification::where('user_id', $userId)->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function toutMarquerLuEnseignant(int $enseignantId): int
    {
        return AppNotification::where('teacher_id', $enseignantId)->whereNull('read_at')->update(['read_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function notifierPlusieurs(
        string $colonne,
        iterable $ids,
        string $type,
        string $titre,
        string $message,
        ?string $lien,
        array $data,
        int $priorite,
    ): int {
        $titre = mb_substr($titre, 0, 150);
        $message = mb_substr($message, 0, 500);
        $lien = $lien !== null ? mb_substr($lien, 0, 255) : null;
        $priorite = max(0, min(3, $priorite));
        $maintenant = now();

        $lot = [];
        $total = 0;

        foreach ($ids as $id) {
            $lot[] = [
                $colonne => (int) $id,
                'type' => $type,
                'titre' => $titre,
                'message' => $message,
                'lien' => $lien,
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'priorite' => $priorite,
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ];

            if (count($lot) >= 500) {
                AppNotification::insert($lot);
                $total += count($lot);
                $lot = [];
            }
        }

        if ($lot !== []) {
            AppNotification::insert($lot);
            $total += count($lot);
        }

        return $total;
    }
}
