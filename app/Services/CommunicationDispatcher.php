<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\Etudiant;
use App\Models\NotificationPreference;
use Illuminate\Support\Facades\Log;

/**
 * Centre de communication (Phase 15 §15.4) : point de passage unique
 * des notifications automatiques métier — gabarit versionné +
 * préférences + déduplication + audit. Textes identiques aux envois
 * directs historiques (contrat préservé, zéro régression).
 */
class CommunicationDispatcher
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly PreferenceNotificationService $preferences,
    ) {}

    /**
     * @param  array<string, mixed>  $variables
     */
    public function dispatcher(
        string $evenement,
        Etudiant $etudiant,
        array $variables = [],
        ?string $lien = null,
        int $priorite = 0,
    ): ?AppNotification {
        // Clés d'événements à points : lecture du tableau parent
        // (la notation pointée de config() découperait la clé).
        $regles = config('communication.evenements', []);
        $regle = is_array($regles) ? ($regles[$evenement] ?? null) : null;

        if (! is_array($regle) || ($regle['actif'] ?? false) !== true) {
            return null;
        }

        $rendu = app(PreferenceNotificationService::class)->rendre(
            (string) ($regle['gabarit'] ?? $evenement), $variables
        );

        if ($rendu === null) {
            Log::warning('communication.gabarit-manquant', ['evenement' => $evenement]);

            return null;
        }

        $categorie = (string) ($regle['categorie'] ?? 'annonces');

        if (! $this->preferences->notifiable($etudiant->id, null, $categorie)) {
            return null;
        }

        if ($this->dejaNotifie($etudiant->id, $evenement, $lien)) {
            return null;
        }

        return $this->notifications->notifierEtudiant(
            $etudiant, $evenement, $rendu['titre'], $rendu['message'], $lien, [], $priorite,
        );
    }

    /**
     * @param  iterable<int>  $etudiantIds
     * @param  array<string, mixed>  $variables
     */
    public function dispatcherPlusieurs(
        string $evenement,
        iterable $etudiantIds,
        array $variables = [],
        ?string $lien = null,
        int $priorite = 0,
    ): int {
        $regles = config('communication.evenements', []);
        $regle = is_array($regles) ? ($regles[$evenement] ?? null) : null;

        if (! is_array($regle) || ($regle['actif'] ?? false) !== true) {
            return 0;
        }

        $rendu = app(PreferenceNotificationService::class)->rendre(
            (string) ($regle['gabarit'] ?? $evenement), $variables
        );

        if ($rendu === null) {
            Log::warning('communication.gabarit-manquant', ['evenement' => $evenement]);

            return 0;
        }

        $categorie = (string) ($regle['categorie'] ?? 'annonces');
        $ids = collect($etudiantIds)->map(fn ($id) => (int) $id)->unique()->values();

        $exclus = NotificationPreference::where('categorie', $categorie)
            ->where('actif', false)
            ->whereIn('etudiant_id', $ids->all())
            ->pluck('etudiant_id')->all();

        if (! in_array($categorie, PreferenceNotificationService::OBLIGATOIRES, true)) {
            $ids = $ids->reject(fn ($id) => in_array($id, $exclus, true))->values();
        }

        if ($ids->isEmpty()) {
            return 0;
        }

        return $this->notifications->notifierPlusieursEtudiants(
            $ids->all(), $evenement, $rendu['titre'], $rendu['message'], $lien, [], $priorite,
        );
    }

    private function dejaNotifie(int $etudiantId, string $evenement, ?string $lien): bool
    {
        $fenetre = (int) config('communication.dedup_minutes', 60);

        if ($fenetre <= 0) {
            return false;
        }

        return AppNotification::where('etudiant_id', $etudiantId)
            ->where('type', $evenement)
            ->whereNull('read_at')
            ->when($lien !== null, fn ($q) => $q->where('lien', $lien))
            ->where('created_at', '>=', now()->subMinutes($fenetre))
            ->exists();
    }
}
