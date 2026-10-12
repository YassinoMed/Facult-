<?php

namespace App\Services;

use App\Events\DossierDecisionPrise;
use App\Events\PopulationChanged;
use App\Models\Etudiant;
use App\Models\SituationEtudiant;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Transitions de statut étudiant (§7-8) : matrice serveur, motif
 * enregistré, historique append-only. Point de passage unique
 * (remplace les appels directs à definirStatut dans le back-office).
 */
class StudentStatusService
{
    /**
     * Transitions autorisées (source => cibles).
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSITIONS = [
        'en_attente' => ['valide', 'rejete'],
        'valide' => ['en_attente', 'rejete'],
        'rejete' => ['en_attente', 'valide'],
    ];

    /**
     * @throws \DomainException
     */
    public function changer(Etudiant $etudiant, string $nouveau, ?int $auteurId, ?string $motif = null): void
    {
        // Idempotent : même statut = no-op (bulk sur lots mixtes).
        if ($etudiant->statut === $nouveau) {
            return;
        }

        if (! in_array($nouveau, self::TRANSITIONS[$etudiant->statut] ?? [], true)) {
            throw new \DomainException("Transition {$etudiant->statut} → {$nouveau} interdite.");
        }

        $ancien = $etudiant->statut;

        // Verrou ligne : deux validations concurrentes du même dossier ne
        // créent qu'une seule transition (la seconde devient no-op, sans
        // redispatch). Retourne l'ancien statut réel, ou null si no-op.
        $ancienReel = DB::transaction(function () use ($etudiant, $nouveau, $auteurId, $motif): ?string {
            $frais = Etudiant::whereKey($etudiant->id)->lockForUpdate()->firstOrFail();

            if ($frais->statut === $nouveau) {
                return null;
            }

            if (! in_array($nouveau, self::TRANSITIONS[$frais->statut] ?? [], true)) {
                throw new \DomainException("Transition {$frais->statut} → {$nouveau} interdite.");
            }

            $statutReel = $frais->statut;
            $frais->definirStatut($nouveau);

            SituationEtudiant::create([
                'etudiant_id' => $frais->id,
                'type' => 'statut',
                'ancienne_valeur' => $statutReel,
                'nouvelle_valeur' => $nouveau,
                'motif' => $motif,
                'auteur_id' => $auteurId,
                'date_effet' => now()->toDateString(),
            ]);

            return $statutReel;
        });

        if ($ancienReel === null) {
            return;
        }

        $ancien = $ancienReel;

        PopulationChanged::dispatch('etudiants', 'statut', $etudiant->id, $auteurId, [
            'ancien' => $ancien, 'nouveau' => $nouveau,
        ]);

        if (in_array($nouveau, ['valide', 'rejete'], true)) {
            DossierDecisionPrise::dispatch($etudiant->fresh(), $nouveau, $motif, $auteurId);
        }

        // Notification in-app (LOOP 12) : l'étudiant découvre la décision
        // sans consulter son dossier. Fail-soft : la transition (déjà
        // commitée) ne doit jamais échouer sur une panne de notification.
        // Clé de dédup = id de la transition SituationEtudiant : un event
        // rejoué ne duplique jamais, deux transitions distinctes notifient.
        $situationId = (int) SituationEtudiant::where('etudiant_id', $etudiant->id)
            ->where('type', 'statut')
            ->orderByDesc('id')
            ->value('id');

        try {
            app(NotificationService::class)->notifierEtudiant(
                $etudiant->fresh(),
                'dossier.statut',
                'Statut de votre dossier mis à jour',
                "Le statut de votre dossier est désormais « {$this->libelleStatut($nouveau)} »."
                    .($motif !== null && trim($motif) !== '' ? " Motif : {$motif}" : ''),
                route('etudiant.profil.show'),
                ['ancien' => $ancien, 'nouveau' => $nouveau],
                $nouveau === 'rejete' ? 2 : 1,
                'dossier.statut:'.$situationId,
            );
        } catch (Throwable) {
            // Fail-soft : la source de vérité reste SituationEtudiant.
        }
    }

    /** @return array<string, string> */
    private function libelleStatut(string $statut): string
    {
        return match ($statut) {
            'valide' => 'Validé',
            'rejete' => 'Rejeté',
            'en_attente' => 'En attente',
            default => ucfirst($statut),
        };
    }

    /**
     * Suspension datée (§27) : bloque les opérations incompatibles
     * (affectations, inscriptions — vérifié par les services).
     */
    public function suspendre(Etudiant $etudiant, string $debut, ?string $fin, ?int $auteurId, ?string $motif = null): void
    {
        DB::transaction(function () use ($etudiant, $debut, $fin, $auteurId, $motif) {
            SituationEtudiant::create([
                'etudiant_id' => $etudiant->id,
                'type' => 'suspension',
                'motif' => $motif,
                'auteur_id' => $auteurId,
                'date_effet' => $debut,
                'date_fin' => $fin,
            ]);
        });

        PopulationChanged::dispatch('etudiants', 'suspension', $etudiant->id, $auteurId);
    }
}
