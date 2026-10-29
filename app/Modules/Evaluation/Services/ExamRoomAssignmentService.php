<?php

namespace App\Modules\Evaluation\Services;

use App\Models\IntegrationLog;
use App\Modules\Evaluation\Events\EvaluationChanged;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Schedule\Models\Room;
use App\Services\AuditService;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Service unique d'affectation et de répartition des salles d'examen (§8, §10).
 *
 * Gère :
 * - Affectation unitaire transactionnelle et sécurisée
 * - Simulation pure avant validation (proposition non persistée)
 * - Répartition multi-salles pour les grands effectifs avec numérotation des places
 * - Respect des capacités, des conflits d'horaires et de l'isolation
 */
class ExamRoomAssignmentService
{
    public function __construct(
        private ConflitEpreuveService $conflits,
        private AuditService $audit,
    ) {}

    /**
     * Affecte une salle principale à une épreuve de manière transactionnelle.
     */
    public function affecterSalle(Epreuve $epreuve, int $salleId, int $auteurId): Epreuve
    {
        return DB::transaction(function () use ($epreuve, $salleId) {
            // Verrou de la ressource partagée d'abord (salle), puis épreuve :
            // ordre déterministe salle → épreuve → candidats pour sérialiser
            // deux épreuves concurrentes visant la même salle (DM-05).
            /** @var Room $salle */
            $salle = Room::whereKey($salleId)->lockForUpdate()->firstOrFail();

            // DM-01 : salle inactive ou à capacité inconnue inadmissible.
            if (! $salle->actif) {
                throw new DomainException("Salle {$salle->code} inactive : affectation interdite.");
            }

            if ($salle->capacite === null || (int) $salle->capacite < 1) {
                throw new DomainException("Capacité de la salle {$salle->code} inconnue : affectation interdite.");
            }

            /** @var Epreuve $verrouillee */
            $verrouillee = Epreuve::whereKey($epreuve->id)->lockForUpdate()->firstOrFail();

            if ($verrouillee->estVerrouillee()) {
                throw new DomainException('Épreuve verrouillée : affectation de salle interdite.');
            }

            $totalCandidats = $verrouillee->candidats()->count();
            $capaciteRequise = $verrouillee->capacite_necessaire > 0 ? (int) $verrouillee->capacite_necessaire : $totalCandidats;

            $listeConflits = $this->conflits->detecter([
                'date' => $verrouillee->date->format('Y-m-d'),
                'heure_debut' => substr((string) $verrouillee->heure_debut, 0, 5),
                'heure_fin' => substr((string) $verrouillee->heure_fin, 0, 5),
                'salle_id' => $salleId,
                'capacite_necessaire' => $capaciteRequise,
            ], $verrouillee->id);

            $bloquants = $this->conflits->bloquants($listeConflits);
            if ($bloquants !== []) {
                throw new DomainException($bloquants[0]['message']);
            }

            $ancienneSalleId = $verrouillee->salle_id;
            $verrouillee->update(['salle_id' => $salleId]);

            // Met à jour les candidats n'ayant pas de salle spécifique
            $verrouillee->candidats()
                ->where(function ($q) use ($ancienneSalleId) {
                    $q->whereNull('salle_id')
                        ->orWhere('salle_id', $ancienneSalleId);
                })
                ->update(['salle_id' => $salleId]);

            IntegrationLog::tracer(
                'evaluation',
                'internal',
                'exam.room.assigned',
                'completed',
                null,
                200,
                null,
                null,
                (string) Str::uuid(),
                'EPR-'.$verrouillee->id
            );

            EvaluationChanged::dispatch('epreuve.salle_affectee', $verrouillee->id);

            return $verrouillee->fresh(['salle']);
        });
    }

    /**
     * Simulation pure (lecture seule) de la répartition multi-salles (§10).
     *
     * @param  array<int, int>  $salleIds
     * @return array{valide: bool, total_candidats: int, capacite_totale: int, repartition: array<int, array{candidat_id: int, etudiant_nom: string, salle_id: int, salle_code: string, place: string}>, conflits: array<int, array{type: string, critique: bool, message: string}>}
     */
    public function simulerRepartition(Epreuve $epreuve, array $salleIds, string $modePrefixe = 'Place '): array
    {
        if ($epreuve->estVerrouillee()) {
            throw new DomainException('Épreuve verrouillée : simulation interdite.');
        }

        $epreuve->loadMissing(['candidats.etudiant']);
        $candidats = $epreuve->candidats->sortBy(fn ($c) => ($c->etudiant->nom ?? '').' '.($c->etudiant->prenom ?? ''))->values();

        if ($candidats->isEmpty()) {
            throw new DomainException('Aucun candidat convoqué pour cette épreuve.');
        }

        /** @var Collection<int, Room> $salles */
        $toutesLesSalles = Room::whereIn('id', $salleIds)->orderBy('id')->get();

        $conflits = [];

        // Salles inadmissibles listées explicitement (DM-01) : inactive ou
        // capacité inconnue => conflit critique ; elles ne participent jamais
        // au calcul de capacité admissible.
        $toutesLesSalles->each(function (Room $salle) use (&$conflits) {
            if (! $salle->actif) {
                $conflits[] = [
                    'type' => 'salle_inactive',
                    'critique' => true,
                    'message' => "Salle {$salle->code} inactive : affectation interdite.",
                ];
            } elseif ($salle->capacite === null || (int) $salle->capacite < 1) {
                $conflits[] = [
                    'type' => 'capacite_inconnue',
                    'critique' => true,
                    'message' => "Capacité de la salle {$salle->code} inconnue : affectation interdite.",
                ];
            }
        });

        $salles = $toutesLesSalles->filter(fn (Room $salle) => $salle->actif
            && $salle->capacite !== null
            && (int) $salle->capacite >= 1)->values();

        if ($salles->isEmpty()) {
            throw new DomainException('Aucune salle admissible sélectionnée (salle active avec capacité connue).');
        }

        $capaciteTotale = (int) $salles->sum('capacite');
        $totalCandidats = $candidats->count();

        // Vérification que les candidats appartiennent bien à la matière et aux groupes concernés
        $correspondance = app(CorrespondanceExamenService::class)->analyserEpreuve($epreuve);
        if (($correspondance['ecarts']['candidats_non_eligibles'] ?? []) !== []) {
            $conflits[] = [
                'type' => 'correspondance_candidats',
                'critique' => true,
                'message' => $correspondance['bloquants'][0] ?? CorrespondanceExamenService::ALERTE_PUBLICATION_BLOQUEE,
            ];
        }

        if ($capaciteTotale < $totalCandidats) {
            $conflits[] = [
                'type' => 'capacite',
                'critique' => true,
                'message' => "Capacité totale insuffisante : {$capaciteTotale} places pour {$totalCandidats} candidats.",
            ];
        }

        // Vérification des conflits pour chaque salle
        foreach ($salles as $salle) {
            $detectes = $this->conflits->detecter([
                'date' => $epreuve->date->format('Y-m-d'),
                'heure_debut' => substr((string) $epreuve->heure_debut, 0, 5),
                'heure_fin' => substr((string) $epreuve->heure_fin, 0, 5),
                'salle_id' => $salle->id,
            ], $epreuve->id);

            foreach ($this->conflits->bloquants($detectes) as $bloquant) {
                $conflits[] = $bloquant;
            }
        }

        $repartition = [];
        $indexCandidat = 0;

        foreach ($salles as $salle) {
            $capacite = (int) $salle->capacite;
            $placeDansSalle = 1;

            while ($indexCandidat < $totalCandidats && $placeDansSalle <= $capacite) {
                $candidat = $candidats[$indexCandidat];
                $placeStr = $salle->code.'-'.str_pad((string) $placeDansSalle, 2, '0', STR_PAD_LEFT);

                $repartition[] = [
                    'candidat_id' => $candidat->id,
                    'etudiant_nom' => $candidat->etudiant->nom_complet ?? 'Candidat #'.$candidat->id,
                    'salle_id' => $salle->id,
                    'salle_code' => $salle->code,
                    'place' => $placeStr,
                ];

                $placeDansSalle++;
                $indexCandidat++;
            }

            if ($indexCandidat >= $totalCandidats) {
                break;
            }
        }

        $critiques = array_filter($conflits, fn ($c) => ($c['critique'] ?? false) === true);

        return [
            'valide' => empty($critiques) && $indexCandidat >= $totalCandidats,
            'total_candidats' => $totalCandidats,
            'capacite_totale' => $capaciteTotale,
            'repartition' => $repartition,
            'conflits' => $conflits,
        ];
    }

    /**
     * Applique une proposition de répartition validée de manière transactionnelle.
     *
     * @param  array<int, array{candidat_id: int, salle_id: int, place: string}>  $repartition
     */
    public function appliquerRepartition(Epreuve $epreuve, array $repartition, int $auteurId): int
    {
        return DB::transaction(function () use ($epreuve, $repartition) {
            /** @var Epreuve $verrouillee */
            $verrouillee = Epreuve::whereKey($epreuve->id)->lockForUpdate()->firstOrFail();

            if ($verrouillee->estVerrouillee()) {
                throw new DomainException('Épreuve verrouillée : répartition interdite.');
            }

            // Garde multi-salles (DM-08) : TOUT est recalculé et revalidé
            // dans le service au moment de l'application — admissibilité
            // (DM-01), occupation/ouverture (§6), intégrité du payload puis
            // capacité par salle (DM-06). Fail-closed : toute exception
            // annule la transaction entière, aucune écriture partielle.
            $sallesRepartition = collect($repartition)
                ->pluck('salle_id')->map(fn ($id) => (int) $id)
                ->filter(fn (int $id) => $id > 0)->unique()->values()->all();

            if ($sallesRepartition === []) {
                throw new DomainException('Répartition vide : application interdite.');
            }

            // Verrou des ressources partagées dans un ordre déterministe
            // avant la détection de conflits (salle → épreuve → candidats,
            // DM-05), puis revalidation complète côté serveur.
            /** @var Collection<int, Room> $salles */
            $salles = Room::whereIn('id', $sallesRepartition)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($sallesRepartition as $salleRepartitionId) {
                $salleRepartition = $salles->get($salleRepartitionId);

                if ($salleRepartition === null) {
                    throw new DomainException("Salle #{$salleRepartitionId} introuvable.");
                }

                if (! $salleRepartition->actif) {
                    throw new DomainException("Salle {$salleRepartition->code} inactive : répartition interdite.");
                }

                if ($salleRepartition->capacite === null || (int) $salleRepartition->capacite < 1) {
                    throw new DomainException("Capacité de la salle {$salleRepartition->code} inconnue : répartition interdite.");
                }
            }

            $detectes = $this->conflits->detecter([
                'date' => $verrouillee->date->format('Y-m-d'),
                'heure_debut' => substr((string) $verrouillee->heure_debut, 0, 5),
                'heure_fin' => substr((string) $verrouillee->heure_fin, 0, 5),
                'salle_ids' => $sallesRepartition,
            ], $verrouillee->id);

            $bloquants = $this->conflits->bloquants($detectes);
            if ($bloquants !== []) {
                throw new DomainException($bloquants[0]['message']);
            }

            // Intégrité du payload (DM-06/DM-08) : candidats de l'épreuve
            // uniquement, couverture complète (aucun candidat perdu), pas de
            // doublon de candidat ni de place au sein d'une même salle.
            $idsCandidats = array_map(fn (array $item) => (int) $item['candidat_id'], $repartition);

            if (count($idsCandidats) !== count(array_unique($idsCandidats))) {
                throw new DomainException('Répartition invalide : un candidat figure plusieurs fois.');
            }

            /** @var array<int, int> $idsEpreuve */
            $idsEpreuve = Candidat::where('epreuve_id', $verrouillee->id)
                ->pluck('id')->map(fn ($id) => (int) $id)->all();

            $horsEpreuve = array_diff($idsCandidats, $idsEpreuve);
            if ($horsEpreuve !== []) {
                throw new DomainException('Répartition invalide : candidat(s) de cette épreuve uniquement (id '.implode(', ', array_slice($horsEpreuve, 0, 5)).').');
            }

            $manquants = array_diff($idsEpreuve, $idsCandidats);
            if ($manquants !== []) {
                throw new DomainException('Répartition incomplète : '.count($manquants).' candidat(s) sans place dans le payload.');
            }

            // Vérification que tous les candidats affectés aux salles sont éligibles pour cette épreuve
            $analyseCorrespondance = app(CorrespondanceExamenService::class)->analyserEpreuve($verrouillee);
            if (($analyseCorrespondance['ecarts']['candidats_non_eligibles'] ?? []) !== []) {
                throw new DomainException($analyseCorrespondance['bloquants'][0] ?? CorrespondanceExamenService::ALERTE_PUBLICATION_BLOQUEE);
            }

            $lignesParSalle = collect($repartition)
                ->groupBy(fn (array $item) => (int) $item['salle_id']);

            foreach ($lignesParSalle as $salleRepartitionId => $lignes) {
                /** @var Room $salleRepartition */
                $salleRepartition = $salles->get($salleRepartitionId);
                $capaciteSalle = (int) $salleRepartition->capacite;

                if ($lignes->count() > $capaciteSalle) {
                    throw new DomainException("Capacité dépassée dans {$salleRepartition->code} : {$lignes->count()} candidats pour {$capaciteSalle} places.");
                }

                // EX-04 : un numéro de place explicite doit rester compatible
                // avec la capacité réelle de la salle (1..capacité). Le
                // générateur produit « CODE-NN » ; seul le suffixe numérique
                // de fin est contrôlé afin de ne pas restreindre les formats
                // purement alphabétiques (aucun fallback inventé).
                foreach ($lignes as $ligne) {
                    if (preg_match('/(\d+)\s*$/u', (string) $ligne['place'], $correspondance) === 1) {
                        $numeroPlace = (int) $correspondance[1];

                        if ($numeroPlace < 1 || $numeroPlace > $capaciteSalle) {
                            throw new DomainException("Place « {$ligne['place']} » hors capacité de {$salleRepartition->code} : numéro {$numeroPlace} pour {$capaciteSalle} place(s).");
                        }
                    }
                }
            }

            $placesVues = [];
            foreach ($repartition as $item) {
                $clePlace = ((int) $item['salle_id']).'|'.(string) $item['place'];

                if (isset($placesVues[$clePlace])) {
                    /** @var Room $salleRepartition */
                    $salleRepartition = $salles->get((int) $item['salle_id']);
                    throw new DomainException("Place « {$item['place']} » attribuée deux fois dans {$salleRepartition->code}.");
                }

                $placesVues[$clePlace] = true;
            }

            $modifies = 0;
            $premiereSalleId = null;

            foreach ($repartition as $item) {
                $candidatId = (int) $item['candidat_id'];
                $salleId = (int) $item['salle_id'];
                $place = (string) $item['place'];

                /** @var Candidat $candidat */
                $candidat = Candidat::where('id', $candidatId)
                    ->where('epreuve_id', $verrouillee->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $candidat->update([
                    'salle_id' => $salleId,
                    'place' => $place,
                ]);
                $modifies++;

                if ($premiereSalleId === null) {
                    $premiereSalleId = $salleId;
                }
            }

            if ($verrouillee->salle_id === null && $premiereSalleId !== null) {
                $verrouillee->update(['salle_id' => $premiereSalleId]);
            }

            IntegrationLog::tracer(
                'evaluation',
                'internal',
                'exam.room.repartition_applied',
                'completed',
                null,
                200,
                null,
                null,
                (string) Str::uuid(),
                'EPR-'.$verrouillee->id
            );

            EvaluationChanged::dispatch('epreuve.repartition_appliquee', $verrouillee->id);

            return $modifies;
        });
    }
}
