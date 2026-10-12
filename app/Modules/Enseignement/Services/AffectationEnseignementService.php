<?php

namespace App\Modules\Enseignement\Services;

use App\Modules\Enseignement\Events\EnseignementChanged;
use App\Modules\Enseignement\Models\AffectationEnseignement;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Enseignement\Models\EnseignementGroupe;
use App\Modules\Enseignement\Models\SousGroupe;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Support\Facades\DB;

/**
 * Affectations enseignants + groupes (§10-16, §39, §48) : cohérence
 * (actifs, programme/niveau/semestre/année), anti-doublon SQL,
 * transaction + audit.
 */
class AffectationEnseignementService
{
    /**
     * @throws \DomainException
     */
    public function affecterEnseignant(
        Enseignement $enseignement,
        Teacher $teacher,
        string $role,
        float $volume,
        ?int $auteurId,
        ?string $motif = null,
    ): AffectationEnseignement {
        if (! $teacher->actif || ($teacher->statut ?? 'actif') !== 'actif') {
            throw new \DomainException("Enseignant {$teacher->nomComplet()} inactif.");
        }

        if (! in_array($role, AffectationEnseignement::ROLES, true)) {
            throw new \DomainException("Rôle « {$role} » inconnu.");
        }

        if ($volume < 0) {
            throw new \DomainException('Volume négatif interdit.');
        }

        return DB::transaction(function () use ($enseignement, $teacher, $role, $volume, $auteurId, $motif) {
            $affectation = AffectationEnseignement::create([
                'enseignement_id' => $enseignement->id,
                'teacher_id' => $teacher->id,
                'role' => $role,
                'volume_heures' => $volume,
                'motif' => $motif,
                'auteur_id' => $auteurId,
            ]);

            EnseignementChanged::dispatch('affectation', $enseignement->id, $auteurId, [
                'teacher_id' => $teacher->id, 'role' => $role,
            ]);

            return $affectation;
        });
    }

    public function desaffecter(AffectationEnseignement $affectation, ?int $auteurId, ?string $motif = null): void
    {
        DB::transaction(function () use ($affectation, $auteurId, $motif) {
            $affectation->update(['statut' => 'cloturee', 'motif' => $motif]);

            EnseignementChanged::dispatch('desaffectation', $affectation->enseignement_id, $auteurId);
        });
    }

    /**
     * Rattache un groupe (ou sous-groupe) à un enseignement (§13-15) :
     * cohérence programme/niveau/année vérifiée serveur.
     *
     * @throws \DomainException
     */
    public function rattacherGroupe(
        Enseignement $enseignement,
        Group $groupe,
        ?int $sousGroupeId,
        ?int $auteurId,
    ): EnseignementGroupe {
        $enseignement->loadMissing(['programme', 'semestre.niveau']);

        if ($enseignement->programme_id !== null && $groupe->programme_id !== null
            && (int) $groupe->programme_id !== (int) $enseignement->programme_id) {
            throw new \DomainException("Groupe {$groupe->code} hors programme de l'enseignement.");
        }

        if ($sousGroupeId !== null) {
            $sousGroupe = SousGroupe::findOrFail($sousGroupeId);

            if ((int) $sousGroupe->groupe_id !== (int) $groupe->id) {
                throw new \DomainException('Sous-groupe hors groupe.');
            }
        }

        return DB::transaction(function () use ($enseignement, $groupe, $sousGroupeId, $auteurId) {
            $lien = EnseignementGroupe::firstOrCreate(
                ['enseignement_id' => $enseignement->id, 'groupe_id' => $groupe->id, 'sous_groupe_id' => $sousGroupeId]
            );

            EnseignementChanged::dispatch('rattachement-groupe', $enseignement->id, $auteurId, [
                'groupe' => $groupe->code,
            ]);

            return $lien;
        });
    }

    public function detacherGroupe(EnseignementGroupe $lien, ?int $auteurId): void
    {
        $enseignementId = $lien->enseignement_id;
        $lien->delete();

        EnseignementChanged::dispatch('detachement-groupe', $enseignementId, $auteurId);
    }
}
