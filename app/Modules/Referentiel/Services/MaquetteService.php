<?php

namespace App\Modules\Referentiel\Services;

use App\Models\Matiere;
use App\Modules\Referentiel\Events\MaquetteTransitionnee;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\MaquetteUe;
use App\Modules\Referentiel\Models\MaquetteUeEcue;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Cycle de vie des maquettes (§18-19, §30) : création versionnée,
 * duplication, transitions contrôlées, verrouillage. Tout est
 * transactionnel — jamais d'état partiellement créé.
 */
class MaquetteService
{
    /**
     * Nouvelle version (incrément automatique, snapshot des UE copié).
     *
     * @throws \DomainException
     */
    public function nouvelleVersion(Maquette $source, int $anneeId, ?int $auteurId, ?string $commentaire): Maquette
    {
        $source->loadMissing('unitesEnseignement');

        return DB::transaction(function () use ($source, $anneeId, $auteurId, $commentaire) {
            $suivante = Maquette::where('programme_id', $source->programme_id)
                ->where('annee_universitaire_id', $anneeId)
                ->max('version');

            $maquette = Maquette::create([
                'programme_id' => $source->programme_id,
                'annee_universitaire_id' => $anneeId,
                'version' => ($suivante ?? 0) + 1,
                'statut' => 'draft',
                'auteur_id' => $auteurId,
                'version_precedente_id' => $source->id,
                'commentaire' => $commentaire,
            ]);

            foreach ($source->unitesEnseignement as $ue) {
                $maquette->unitesEnseignement()->attach($ue->id, [
                    'semestre_id' => $ue->pivot->semestre_id,
                ]);
            }

            // Vague 3 (§19 option B) : le snapshot reprend AUSSI les
            // contextes ECUE annuels (coef/crédits/volumes/règles Master)
            // — l'originale n'est jamais modifiée.
            $lignesSource = MaquetteUe::where('maquette_id', $source->id)->get()->keyBy('ue_id');
            $lignesCible = MaquetteUe::where('maquette_id', $maquette->id)->get()->keyBy('ue_id');

            foreach (MaquetteUeEcue::whereIn('maquette_ue_id', $lignesSource->pluck('id'))->get() as $contexte) {
                $ueId = $lignesSource[$contexte->maquette_ue_id]?->ue_id;
                $ligneCible = $lignesCible->get($ueId);

                if ($ligneCible !== null) {
                    MaquetteUeEcue::create([
                        'maquette_ue_id' => $ligneCible->id,
                        'matiere_id' => $contexte->matiere_id,
                        'ordre' => $contexte->ordre,
                        'coefficient' => $contexte->coefficient,
                        'credits' => $contexte->credits,
                        'volume_cm' => $contexte->volume_cm,
                        'volume_td' => $contexte->volume_td,
                        'volume_tp' => $contexte->volume_tp,
                        'type_evaluation' => $contexte->type_evaluation,
                        'regime' => $contexte->regime,
                        'obligatoire' => $contexte->obligatoire,
                        'groupe_choix' => $contexte->groupe_choix,
                        'conditions' => $contexte->conditions,
                    ]);
                }
            }

            return $maquette->fresh();
        });
    }

    /**
     * Rattache des UE (brouillon uniquement).
     *
     * @param  array<int, array{ue_id: int, semestre_id: int}>  $entrees
     *
     * @throws \DomainException
     */
    public function definirContenu(Maquette $maquette, array $entrees, ?int $auteurId): void
    {
        $this->exigerModifiable($maquette);

        DB::transaction(function () use ($maquette, $entrees) {
            $maquette->unitesEnseignement()->detach();

            foreach ($entrees as $entree) {
                $maquette->unitesEnseignement()->attach($entree['ue_id'], [
                    'semestre_id' => $entree['semestre_id'],
                ]);
            }
        });

        MaquetteTransitionnee::dispatch($maquette->fresh(), 'contenu', $auteurId);
    }

    /**
     * Contextes ECUE du plan (Vague 3 §6B/§17) : valeurs annuelles
     * (coef/crédits/volumes) + règles Master (obligatoire, groupe de
     * choix K_PARMI_N, conditions) — la matière canonique n'est JAMAIS
     * mutée. Brouillon uniquement ; remplacement complet du contenu
     * ECUE (comme definirContenu), idempotent par (maquette_ue, matiere).
     *
     * @param  array<int, array<string, mixed>>  $entrees
     *
     * @throws \DomainException
     */
    public function definirEcues(Maquette $maquette, array $entrees, ?int $auteurId): void
    {
        $this->exigerModifiable($maquette);

        $lignes = MaquetteUe::where('maquette_id', $maquette->id)->get()->keyBy('id');
        $matieres = Matiere::whereIn('id', collect($entrees)->pluck('matiere_id')->filter()->all())
            ->get()->keyBy('id');

        foreach ($entrees as $entree) {
            $ligne = $lignes->get((int) ($entree['maquette_ue_id'] ?? 0));

            if ($ligne === null) {
                throw new \DomainException('Ligne UE inconnue dans cette maquette.');
            }

            $matiere = $matieres->get((int) ($entree['matiere_id'] ?? 0));

            if ($matiere === null) {
                throw new \DomainException('ECUE inconnue.');
            }

            if ($matiere->ue_id === null || (int) $matiere->ue_id !== (int) $ligne->ue_id) {
                throw new \DomainException("ECUE {$matiere->code} hors de l'UE {$ligne->ue?->code}.");
            }

            if (isset($entree['regime'])
                && ! in_array($entree['regime'], ['obligatoire', 'optionnelle', 'conditionnelle'], true)) {
                throw new \DomainException('Régime inconnu (obligatoire, optionnelle, conditionnelle).');
            }

            if (isset($entree['conditions']) && ! is_array($entree['conditions'])) {
                throw new \DomainException('Les conditions doivent être un objet JSON.');
            }
        }

        DB::transaction(function () use ($entrees, $lignes, $matieres) {
            MaquetteUeEcue::whereIn('maquette_ue_id', $lignes->pluck('id')->all())->delete();

            foreach ($entrees as $entree) {
                $matiere = $matieres->get((int) $entree['matiere_id']);

                MaquetteUeEcue::create([
                    'maquette_ue_id' => (int) $entree['maquette_ue_id'],
                    'matiere_id' => (int) $entree['matiere_id'],
                    'ordre' => (int) ($entree['ordre'] ?? 0),
                    'coefficient' => $entree['coefficient'] ?? $matiere->coefficient ?? 1,
                    'credits' => (int) ($entree['credits'] ?? $matiere->credits ?? 0),
                    'volume_cm' => (int) ($entree['volume_cm'] ?? $matiere->volume_cm ?? 0),
                    'volume_td' => (int) ($entree['volume_td'] ?? $matiere->volume_td ?? 0),
                    'volume_tp' => (int) ($entree['volume_tp'] ?? $matiere->volume_tp ?? 0),
                    'type_evaluation' => $entree['type_evaluation'] ?? $matiere->type_evaluation,
                    'regime' => $entree['regime'] ?? ($entree['obligatoire'] ?? true ? 'obligatoire' : 'optionnelle'),
                    'obligatoire' => (bool) ($entree['obligatoire'] ?? true),
                    'groupe_choix' => $entree['groupe_choix'] ?? null,
                    'conditions' => $entree['conditions'] ?? null,
                ]);
            }
        });

        MaquetteTransitionnee::dispatch($maquette->fresh(), 'ecues', $auteurId);
    }

    /**
     * Transition de statut contrôlée (§19).
     *
     * @throws \DomainException
     */
    public function transiter(Maquette $maquette, string $cible, ?int $auteurId): Maquette
    {
        if (! in_array($cible, Maquette::STATUTS, true)) {
            throw new \DomainException("Statut « {$cible} » inconnu.");
        }

        if (! $maquette->peutTransiterVers($cible)) {
            throw new \DomainException("Transition {$maquette->statut} → {$cible} interdite.");
        }

        // Une seule maquette active par programme (bascule atomique,
        // lignes verrouillées : deux activations concurrentes ne peuvent
        // pas laisser deux actives — la seconde archive la première).
        // LOOP 2 : un interblocage (1213) ou une attente de verrou (1205)
        // reste possible à l'instant précis de la course — converti en
        // refus explicite rejouable (jamais de 500 brute au perdant).
        try {
            return DB::transaction(function () use ($maquette, $cible, $auteurId) {
                $maquette = Maquette::whereKey($maquette->id)->lockForUpdate()->firstOrFail();

                if ($cible === 'active') {
                    Maquette::where('programme_id', $maquette->programme_id)
                        ->lockForUpdate()
                        ->get();
                    Maquette::where('programme_id', $maquette->programme_id)
                        ->where('id', '!=', $maquette->id)
                        ->where('statut', 'active')
                        ->update(['statut' => 'archived']);
                }

                $donnees = ['statut' => $cible];

                if (in_array($cible, ['approved', 'active'], true)) {
                    $donnees['validee_par'] = $auteurId;
                    $donnees['date_validation'] = now();
                }

                $maquette->update($donnees);

                MaquetteTransitionnee::dispatch($maquette->fresh(), $cible, $auteurId);

                return $maquette->fresh();
            });
        } catch (QueryException $e) {
            if (in_array($e->errorInfo[1] ?? null, [1205, 1213], true)) {
                throw new \DomainException('Activation concurrente détectée : réessayez.');
            }

            throw $e;
        }
    }

    /**
     * @throws \DomainException
     */
    public function exigerModifiable(Maquette $maquette): void
    {
        if ($maquette->estVerrouillee()) {
            throw new \DomainException('Maquette verrouillée : créez une nouvelle version.');
        }
    }
}
