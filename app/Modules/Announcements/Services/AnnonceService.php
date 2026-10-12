<?php

namespace App\Modules\Announcements\Services;

use App\Models\Etudiant;
use App\Models\User;
use App\Modules\Announcements\Events\AnnoncePubliee;
use App\Modules\Announcements\Models\Annonce;
use App\Services\NotificationService;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Publication des annonces (§39) : transition serveur + diffusion
 * ciblée en lots via NotificationService (pas de N+1).
 */
class AnnonceService
{
    public function __construct(private NotificationService $notifications) {}

    public function publier(Annonce $annonce): Annonce
    {
        return DB::transaction(function () use ($annonce) {
            if (! in_array($annonce->statut, ['draft', 'scheduled'], true)) {
                throw new DomainException('Seule une annonce en brouillon ou planifiée peut être publiée.');
            }

            $annonce->update(['statut' => 'published', 'publiee_a' => $annonce->publiee_a ?? now()]);

            $ids = $this->destinatairesEtudiants($annonce);

            if ($ids !== []) {
                $this->notifications->notifierPlusieursEtudiants(
                    $ids,
                    'annonce.publiee',
                    $annonce->titre,
                    mb_substr($annonce->contenu, 0, 200),
                    route('etudiant.annonces.show', $annonce),
                    ['annonce_id' => $annonce->id],
                    $annonce->type === 'urgent' ? 3 : (int) $annonce->priorite,
                );
            }

            // Audiences staff : les enseignants n'ayant pas de compte,
            // le personnel back-office du périmètre est notifié.
            if (in_array($annonce->audience, ['enseignants', 'personnel'], true)) {
                $userIds = User::where('actif', true)
                    ->when($annonce->faculte_id !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('faculte_id')->orWhere('faculte_id', $annonce->faculte_id)))
                    ->pluck('id');

                if ($userIds->isNotEmpty()) {
                    $this->notifications->notifierPlusieursUsers(
                        $userIds,
                        'annonce.publiee',
                        $annonce->titre,
                        mb_substr($annonce->contenu, 0, 200),
                        route('admin.annonces.index'),
                        ['annonce_id' => $annonce->id],
                        $annonce->type === 'urgent' ? 3 : (int) $annonce->priorite,
                    );
                }
            }

            AnnoncePubliee::dispatch($annonce->id);

            return $annonce->fresh();
        });
    }

    public function expirer(Annonce $annonce): Annonce
    {
        $annonce->update(['statut' => 'expired']);

        return $annonce->fresh();
    }

    /** @return array<int> */
    private function destinatairesEtudiants(Annonce $annonce): array
    {
        if (! in_array($annonce->audience, ['tous', 'etudiants', 'programme', 'groupe'], true)) {
            return [];
        }

        return Etudiant::query()
            ->when($annonce->faculte_id !== null, fn ($q) => $q->where('faculte_id', $annonce->faculte_id))
            ->when($annonce->programme_id !== null, fn ($q) => $q->where('programme_id', $annonce->programme_id))
            ->when($annonce->groupe_id !== null, fn ($q) => $q->where('groupe_id', $annonce->groupe_id))
            ->pluck('id')
            ->all();
    }
}
