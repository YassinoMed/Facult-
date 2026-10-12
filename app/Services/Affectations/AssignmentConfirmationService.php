<?php

namespace App\Services\Affectations;

use App\Models\AnneeUniversitaire;
use App\Models\AssignmentItem;
use App\Models\AssignmentSimulation;
use App\Models\Etudiant;
use App\Modules\Schedule\Models\Group;
use App\Services\AffectationService;
use App\Services\AuditService;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Refonte affectation §24-30 (étapes 12-14) : prévisualisation,
 * modification manuelle validée, confirmation PAR GROUPE
 * transactionnelle (re-vérification complète + obsolescence +
 * idempotence + audit). Double confirmation = 0 écriture.
 */
final class AssignmentConfirmationService
{
    public function __construct(
        private readonly AffectationService $affectations,
        private readonly AssignmentSimulationService $simulations,
        private readonly AuditService $audit,
    ) {}

    /**
     * Modification manuelle d'une proposition (mêmes règles métier,
     * jamais de contournement §27).
     */
    public function modifier(AssignmentItem $item, int $groupeId, int $auteurId): AssignmentItem
    {
        $simulation = $item->simulation;

        if (in_array($simulation->statut, ['confirmee', 'annulee', 'expiree'], true)) {
            throw new DomainException('Simulation clôturée : modification impossible.');
        }

        if ($simulation->groupeConfirme((int) $item->groupe_propose_id)) {
            throw new DomainException('Groupe déjà confirmé : modification impossible.');
        }

        $annee = AnneeUniversitaire::findOrFail($simulation->annee_universitaire_id);
        $etudiant = Etudiant::findOrFail($item->etudiant_id);
        $groupe = Group::findOrFail($groupeId);

        abort_unless(request()->user()?->peutAccederFaculte((int) $groupe->faculte_id) ?? true, 404);

        $bloquantes = array_values(array_filter(
            $this->affectations->controler($etudiant, $groupe, $annee),
            fn (array $c) => $c['bloquant']
        ));

        if ($bloquantes !== []) {
            throw new DomainException('Modification refusée : '.implode(' ', array_column($bloquantes, 'message')));
        }

        $ancien = $item->groupe_propose_id;

        $item->update(['groupe_propose_id' => $groupeId, 'statut' => 'modifie']);

        $this->audit->tracer($etudiant, 'assignment.manually_changed', 'simulation', $simulation->id, [
            'groupe' => $ancien,
        ], [
            'groupe' => $groupeId, 'simulation' => $simulation->reference,
        ]);

        return $item->fresh();
    }

    /**
     * Confirmation d'un groupe : transaction + re-vérification +
     * obsolescence + idempotence. Traitée par TRANCHES ($limite, défaut
     * 200) pour rester sous les timeouts HTTP sans worker : chaque appel
     * confirme une tranche et signale le reste à confirmer.
     *
     * @return array{confirmees: int, ignorees: int, erreurs: array<int, string>, restantes: int, termine: bool}
     */
    public function confirmerGroupe(AssignmentSimulation $simulation, int $groupeId, int $auteurId, int $limite = 200): array
    {
        return DB::transaction(function () use ($simulation, $groupeId, $auteurId, $limite) {
            $verrouillee = AssignmentSimulation::whereKey($simulation->id)->lockForUpdate()->firstOrFail();

            if (in_array($verrouillee->statut, ['annulee', 'expiree'], true)) {
                throw new DomainException('Simulation clôturée.');
            }

            // Idempotence : groupe déjà confirmé = 0 écriture.
            if ($verrouillee->groupeConfirme($groupeId)) {
                return ['confirmees' => 0, 'ignorees' => 0, 'erreurs' => [], 'restantes' => 0, 'termine' => true];
            }

            // Obsolescence §28 : les données ont changé depuis.
            if ($this->simulations->hashEntrees($verrouillee->parametres) !== $verrouillee->hash_entrees) {
                $verrouillee->update(['statut' => 'expiree']);
                throw new DomainException('Les données ont changé depuis la simulation. Veuillez relancer la simulation.');
            }

            $annee = AnneeUniversitaire::findOrFail($verrouillee->annee_universitaire_id);
            $groupe = Group::whereKey($groupeId)->lockForUpdate()->firstOrFail();
            $force = (bool) ($verrouillee->parametres['force'] ?? false);

            $items = AssignmentItem::where('simulation_id', $verrouillee->id)
                ->where('groupe_propose_id', $groupeId)
                ->whereIn('statut', ['propose', 'modifie'])
                ->orderBy('id')
                ->limit($limite)
                ->lockForUpdate()
                ->get();

            $contexte = $this->affectations->contexteBulk(
                $items->map(fn ($i) => (int) $i->etudiant_id)->all(), [$groupeId], $annee
            );

            $confirmees = 0;
            $ignorees = 0;
            $erreurs = [];

            foreach ($items as $item) {
                $etudiant = Etudiant::find($item->etudiant_id);

                if ($etudiant === null) {
                    $ignorees++;
                    $erreurs[$item->etudiant_id] = 'Étudiant introuvable.';

                    continue;
                }

                try {
                    $affectation = $this->affectations->affecter(
                        $etudiant, $groupe, $annee, $auteurId,
                        "Simulation {$verrouillee->reference}", $force, null, $contexte
                    );
                    $affectation->update(['confirme_le' => now(), 'confirme_par' => $auteurId]);
                    $item->update(['statut' => 'confirme']);
                    $confirmees++;

                    $this->audit->tracer($etudiant, 'assignment.group_confirmed', 'simulation', $verrouillee->id, null, [
                        'groupe' => $groupe->code, 'simulation' => $verrouillee->reference,
                    ]);
                } catch (DomainException $e) {
                    // Échec définitif à la confirmation (re-vérifié) :
                    // tracé comme non affecté pour ne pas boucler sur
                    // « Continuer » avec les mêmes items en échec.
                    $ignorees++;
                    $erreurs[$etudiant->id] = $e->getMessage();
                    $item->update(['statut' => 'non_affecte', 'raison' => $e->getMessage()]);
                }
            }

            $restantes = AssignmentItem::where('simulation_id', $verrouillee->id)
                ->where('groupe_propose_id', $groupeId)
                ->whereIn('statut', ['propose', 'modifie'])
                ->count();
            $termine = $restantes === 0;

            if ($termine) {
                $confirmes = $verrouillee->groupes_confirmes ?? [];
                $confirmes[(string) $groupeId] = ['par' => $auteurId, 'le' => now()->toIso8601String()];
                $verrouillee->update(['groupes_confirmes' => $confirmes]);
            }

            $restants = AssignmentItem::where('simulation_id', $verrouillee->id)
                ->whereIn('statut', ['propose', 'modifie'])->exists();

            $verrouillee->update(['statut' => $restants ? 'partiellement_confirmee' : 'confirmee', 'version' => $verrouillee->version + 1]);

            // Re-calibrage : les écritures de CETTE tranche sont absorbées ;
            // un changement externe avant la prochaine tranche reste détecté.
            $verrouillee->update(['hash_entrees' => $this->simulations->hashEntrees($verrouillee->parametres)]);

            return ['confirmees' => $confirmees, 'ignorees' => $ignorees, 'erreurs' => $erreurs, 'restantes' => $restantes, 'termine' => $termine];
        });
    }

    public function annuler(AssignmentSimulation $simulation, int $auteurId): AssignmentSimulation
    {
        if ($simulation->statut === 'confirmee') {
            throw new DomainException('Simulation entièrement confirmée : annulation impossible.');
        }

        $simulation->update(['statut' => 'annulee']);
        AssignmentItem::where('simulation_id', $simulation->id)
            ->whereIn('statut', ['propose', 'modifie', 'non_affecte'])
            ->update(['statut' => 'annule']);

        return $simulation->fresh();
    }
}
