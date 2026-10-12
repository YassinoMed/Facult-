<?php

namespace App\Modules\Announcements\Services;

use App\Models\AppNotification;
use App\Models\Etudiant;
use App\Models\User;
use App\Modules\Announcements\Events\CampagneEnvoyee;
use App\Modules\Announcements\Models\Campagne;
use App\Services\NotificationService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Campagnes (§15.14) : estimation d'audience, envoi batch idempotent
 * (cle_unique — jamais de doublon), statistiques d'envoi.
 */
class CampagneService
{
    public function __construct(private NotificationService $notifications) {}

    public function estimer(Campagne $campagne): int
    {
        $estimation = 0;

        if (in_array($campagne->audience, ['etudiants', 'tous'], true)) {
            $estimation += $this->etudiantsCibles($campagne)->count();
        }

        if (in_array($campagne->audience, ['personnel', 'tous'], true)) {
            $estimation += $this->usersCibles($campagne)->count();
        }

        $campagne->update(['destinataires_estimes' => $estimation]);

        return $estimation;
    }

    public function envoyer(Campagne $campagne): Campagne
    {
        return DB::transaction(function () use ($campagne) {
            $campagne->refresh();

            if (! in_array($campagne->statut, ['brouillon', 'planifiee'], true)) {
                throw new DomainException("Envoi interdit depuis « {$campagne->statut} ».");
            }

            $envoyes = 0;

            if (in_array($campagne->audience, ['etudiants', 'tous'], true)) {
                // Déduplication : un destinataire = une notification par campagne.
                $deja = AppNotification::where('type', 'campagne.'.$campagne->cle_unique)->pluck('etudiant_id')->all();
                $ids = $this->etudiantsCibles($campagne)->whereNotIn('id', $deja)->pluck('id');

                $envoyes += $this->notifications->notifierPlusieursEtudiants(
                    $ids,
                    'campagne.'.$campagne->cle_unique,
                    $campagne->titre,
                    mb_substr($campagne->message, 0, 500),
                    null,
                    ['campagne_id' => $campagne->id],
                    1,
                );
            }

            if (in_array($campagne->audience, ['personnel', 'tous'], true)) {
                $deja = AppNotification::where('type', 'campagne.'.$campagne->cle_unique)->pluck('user_id')->all();
                $ids = $this->usersCibles($campagne)->whereNotIn('id', $deja)->pluck('id');

                $envoyes += $this->notifications->notifierPlusieursUsers(
                    $ids,
                    'campagne.'.$campagne->cle_unique,
                    $campagne->titre,
                    mb_substr($campagne->message, 0, 500),
                    null,
                    ['campagne_id' => $campagne->id],
                    1,
                );
            }

            $campagne->update(['statut' => 'envoyee', 'envoyee_a' => now(), 'envoyes' => $envoyes]);
            CampagneEnvoyee::dispatch($campagne->id);

            return $campagne->fresh();
        });
    }

    public function actualiserLectures(Campagne $campagne): Campagne
    {
        $lus = AppNotification::where('type', 'campagne.'.$campagne->cle_unique)
            ->whereNotNull('read_at')->count();

        $campagne->update(['lus' => $lus]);

        return $campagne->fresh();
    }

    private function etudiantsCibles(Campagne $campagne)
    {
        return Etudiant::query()
            ->when($campagne->faculte_id !== null, fn ($q) => $q->where('faculte_id', $campagne->faculte_id))
            ->when($campagne->programme_id !== null, fn ($q) => $q->where('programme_id', $campagne->programme_id))
            ->when($campagne->groupe_id !== null, fn ($q) => $q->where('groupe_id', $campagne->groupe_id));
    }

    private function usersCibles(Campagne $campagne)
    {
        return User::where('actif', true)
            ->when($campagne->faculte_id !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('faculte_id')->orWhere('faculte_id', $campagne->faculte_id)));
    }

    public function nouvelleCle(): string
    {
        return 'camp-'.Str::uuid()->toString();
    }
}
