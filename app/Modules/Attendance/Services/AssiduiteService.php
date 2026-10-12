<?php

namespace App\Modules\Attendance\Services;

use App\Models\Etudiant;
use App\Models\User;
use App\Modules\Attendance\Events\AbsenceJustifiee;
use App\Modules\Attendance\Models\Justification;
use App\Modules\Attendance\Models\Presence;
use App\Services\NotificationService;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Assiduité (§36) : statistiques, justifications (workflow
 * pending → approved/rejected), alertes de seuil via notifications.
 * L'absence n'est jamais supprimée : acceptée ⇒ statut `excuse`.
 */
class AssiduiteService
{
    public function __construct(private NotificationService $notifications) {}

    /**
     * @return array{seances: int, absences: int, justifiees: int, injustifiees: int, retards: int, taux: ?float}
     */
    public function statistiques(Etudiant $etudiant, ?string $debut = null, ?string $fin = null): array
    {
        $query = Presence::where('etudiant_id', $etudiant->id)
            ->when($debut !== null, fn ($q) => $q->whereDate('date', '>=', $debut))
            ->when($fin !== null, fn ($q) => $q->whereDate('date', '<=', $fin));

        $seances = (clone $query)->count();
        $absents = (clone $query)->where('statut', 'absent')->count();
        $retards = (clone $query)->where('statut', 'retard')->count();
        $excuses = (clone $query)->where('statut', 'excuse')->count();

        $justifiees = Justification::where('etudiant_id', $etudiant->id)
            ->where('statut', 'approved')
            ->count();

        return [
            'seances' => $seances,
            'absences' => $absents,
            'justifiees' => min($justifiees, $absents) + $excuses,
            'injustifiees' => max(0, $absents - $justifiees),
            'retards' => $retards,
            'taux' => $seances > 0 ? round(($seances - $absents) / $seances * 100, 1) : null,
        ];
    }

    public function deposerJustification(Presence $presence, Etudiant $etudiant, string $motif, ?string $pieceChemin = null, ?string $pieceNom = null): Justification
    {
        return DB::transaction(function () use ($presence, $etudiant, $motif, $pieceChemin, $pieceNom) {
            abort_unless((int) $presence->etudiant_id === (int) $etudiant->id, 404);
            abort_unless($presence->statut === 'absent', 422, 'Seule une absence peut être justifiée.');

            abort_unless(
                ! Justification::where('presence_id', $presence->id)->whereIn('statut', ['pending', 'approved'])->exists(),
                422,
                'Une justification est déjà en cours ou acceptée.'
            );

            return Justification::create([
                'presence_id' => $presence->id,
                'etudiant_id' => $etudiant->id,
                'motif' => $motif,
                'piece_chemin' => $pieceChemin,
                'piece_nom' => $pieceNom,
                'statut' => 'pending',
            ]);
        });
    }

    public function validerJustification(Justification $justification, int $validateurId, bool $accepter, ?string $commentaire = null): Justification
    {
        return DB::transaction(function () use ($justification, $validateurId, $accepter, $commentaire) {
            $justification->refresh();

            if ($justification->statut !== 'pending') {
                throw new DomainException('Justification déjà traitée.');
            }

            $justification->update([
                'statut' => $accepter ? 'approved' : 'rejected',
                'commentaire_validation' => $commentaire,
                'valide_par' => $validateurId,
                'validee_a' => now(),
            ]);

            if ($accepter) {
                $justification->presence()->update(['statut' => 'excuse']);
                AbsenceJustifiee::dispatch($justification->id);

                $this->notifications->notifierEtudiant(
                    $justification->etudiant,
                    'absence.justifiee',
                    'Justification acceptée',
                    'Votre justification a été acceptée.',
                    route('etudiant.absences.index'),
                );
            }

            return $justification->fresh();
        });
    }

    /**
     * Alerte seuil d'absences injustifiées (notifie la scolarité du périmètre).
     */
    public function alerterSiSeuilDepasse(Etudiant $etudiant, int $seuil): bool
    {
        $stats = $this->statistiques($etudiant);

        if ($stats['injustifiees'] < $seuil) {
            return false;
        }

        $destinataires = User::where('actif', true)
            ->whereIn('role', ['admin_scolarite', 'agent_scolarite'])
            ->when($etudiant->faculte_id !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('faculte_id')->orWhere('faculte_id', $etudiant->faculte_id)))
            ->pluck('id');

        $this->notifications->notifierPlusieursUsers(
            $destinataires,
            'absence.seuil',
            'Seuil d’absences dépassé',
            "{$etudiant->nom_complet} : {$stats['injustifiees']} absences injustifiées (seuil {$seuil}).",
            route('admin.etudiants.show', $etudiant),
            ['etudiant_id' => $etudiant->id],
            2,
        );

        return true;
    }
}
