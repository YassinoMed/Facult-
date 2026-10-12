<?php

namespace App\Modules\Enseignement\Services;

use App\Models\Matiere;
use App\Modules\Enseignement\Events\EnseignementChanged;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Enseignement\Models\TypeEnseignement;
use Illuminate\Support\Facades\DB;

/**
 * Cycle de vie des enseignements (§7, §44-45, §48) : création
 * transactionnelle, transitions contrôlées, verrouillage.
 */
class EnseignementService
{
    /**
     * @param  array<string, mixed>  $donnees
     *
     * @throws \DomainException
     */
    public function creer(Matiere $matiere, TypeEnseignement $type, array $donnees, ?int $auteurId): Enseignement
    {
        if ($matiere->ue_id === null) {
            throw new \DomainException("ECUE {$matiere->code} non rattachée à une UE.");
        }

        foreach (['volume_cm', 'volume_td', 'volume_tp', 'volume_projet', 'volume_autre'] as $champ) {
            if (($donnees[$champ] ?? 0) < 0) {
                throw new \DomainException('Volumes négatifs interdits.');
            }
        }

        return DB::transaction(function () use ($matiere, $type, $donnees, $auteurId) {
            $enseignement = Enseignement::create([
                'matiere_id' => $matiere->id,
                'type_id' => $type->id,
                'annee_universitaire_id' => $donnees['annee_universitaire_id'],
                'semestre_id' => $donnees['semestre_id'] ?? $matiere->ue->semestre_id,
                'programme_id' => $donnees['programme_id'] ?? $matiere->ue->programme_id,
                'volume_cm' => $donnees['volume_cm'] ?? 0,
                'volume_td' => $donnees['volume_td'] ?? 0,
                'volume_tp' => $donnees['volume_tp'] ?? 0,
                'volume_projet' => $donnees['volume_projet'] ?? 0,
                'volume_autre' => $donnees['volume_autre'] ?? 0,
                'statut' => 'draft',
                'description' => $donnees['description'] ?? null,
                'cree_par' => $auteurId,
            ]);

            EnseignementChanged::dispatch('creation', $enseignement->id, $auteurId);

            return $enseignement->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $donnees
     *
     * @throws \DomainException
     */
    public function modifier(Enseignement $enseignement, array $donnees, ?int $auteurId, ?string $motif = null): Enseignement
    {
        if ($enseignement->estVerrouille() && $motif === null) {
            throw new \DomainException('Enseignement verrouillé : motif obligatoire.');
        }

        return DB::transaction(function () use ($enseignement, $donnees, $auteurId, $motif) {
            $enseignement->update($donnees);

            EnseignementChanged::dispatch('modification', $enseignement->id, $auteurId, ['motif' => $motif]);

            return $enseignement->fresh();
        });
    }

    /**
     * @throws \DomainException
     */
    public function transiter(Enseignement $enseignement, string $cible, ?int $auteurId): Enseignement
    {
        if (! in_array($cible, Enseignement::STATUTS, true)) {
            throw new \DomainException("Statut « {$cible} » inconnu.");
        }

        if (! $enseignement->peutTransiterVers($cible)) {
            throw new \DomainException("Transition {$enseignement->statut} → {$cible} interdite.");
        }

        return DB::transaction(function () use ($enseignement, $cible, $auteurId) {
            $enseignement->update(['statut' => $cible]);

            EnseignementChanged::dispatch('transition.'.$cible, $enseignement->id, $auteurId);

            return $enseignement->fresh();
        });
    }
}
