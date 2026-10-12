<?php

namespace App\Services\Affectations;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Services\AffectationService;
use Illuminate\Support\Collection;

/**
 * Refonte affectation §7-9 (étapes 4/6) : groupes admissibles par
 * étudiant. Ordre absolu : VALIDITÉ > CONFLITS > CAPACITÉ > MÉTIER.
 * Réutilise `AffectationService::controler` (10 codes, jamais dupliqués)
 * + créneaux Schedule (chevauchement strict) pour SCHEDULE. EXAM :
 * non applicable (groupes non rattachés aux sessions d'examen —
 * les épreuves à venir restent informatives via `impacts()`).
 */
final class AssignmentConstraintEngine
{
    public function __construct(private readonly AffectationService $affectations) {}

    /**
     * @param  Collection<int, Group>  $groupes
     * @param  array{ctx?: array, slots?: array{groupes: array, etudiants: array}|null}  $preload
     * @return array<int, array{admissible: bool, contraintes: array, conflits: array<int, array{type: string, gravite: string, raison: string}>}>
     */
    public function admissibles(Etudiant $etudiant, Collection $groupes, AnneeUniversitaire $annee, array $placesSimulees, ?int $faculteRestreinteId, bool $force = false, array $preload = []): array
    {
        $ctx = $preload['ctx'] ?? null;
        $slotsPrecharges = $preload['slots'] ?? null;

        $creneauxEtudiant = $slotsPrecharges !== null
            ? ($slotsPrecharges['etudiants'][$etudiant->id] ?? [])
            : $this->creneauxGroupesActifs($etudiant, $annee);
        $slotsCandidats = $slotsPrecharges !== null
            ? $slotsPrecharges['groupes']
            : $this->slotsParGroupe($groupes);
        $resultat = [];

        foreach ($groupes as $groupe) {
            $contraintes = $this->affectations->controler($etudiant, $groupe, $annee, $force, null, $ctx);
            $conflits = [];

            // Scope faculté : groupe et étudiant dans le périmètre.
            $motifScope = $this->motifScope($etudiant, $groupe, $faculteRestreinteId);

            if ($motifScope !== null) {
                $conflits[] = $motifScope;
            }

            // Capacité sur places SIMULÉES (jamais l'effectif figé,
            // jamais de requête ici : message sans comptage).
            $motifCapacite = $this->motifCapacite($groupe, $placesSimulees[$groupe->id] ?? 0, $force);

            if ($motifCapacite !== null) {
                $conflits[] = $motifCapacite;
            }

            // Horaire : créneaux du candidat vs autres groupes actifs.
            $motifHoraire = $this->motifHoraire($slotsCandidats[$groupe->id] ?? [], $creneauxEtudiant);

            if ($motifHoraire !== null) {
                $conflits[] = $motifHoraire;
            }

            // GROUP_FULL du contrôleur = effectif figé : ignoré ici
            // (capacité gérée via places simulées ci-dessus).
            $bloquantes = array_values(array_filter(
                $contraintes,
                fn (array $c) => $c['bloquant'] && $c['code'] !== 'GROUP_FULL'
            ));

            $resultat[$groupe->id] = [
                'admissible' => $bloquantes === [] && $conflits === [],
                'contraintes' => $contraintes,
                'conflits' => $conflits,
            ];
        }

        return $resultat;
    }

    /**
     * Précharge les créneaux en 2 requêtes (groupes candidats +
     * groupes actifs des étudiants), quelle que soit la taille.
     *
     * @param  Collection<int, Etudiant>  $etudiants
     * @param  Collection<int, Group>  $groupes
     * @return array{groupes: array<int, array>, etudiants: array<int, array>}
     */
    public function prechargerCreneaux(Collection $etudiants, Collection $groupes, AnneeUniversitaire $annee): array
    {
        $groupesParId = $this->creneauxDesGroupes($groupes->map(fn (Group $g) => $g->id)->all(), true);

        $autresGroupes = $etudiants->flatMap(fn (Etudiant $e) => $e->affectations
            ->where('annee_universitaire_id', $annee->id)
            ->where('statut', 'active')
            ->map(fn ($a) => (int) $a->groupe_id))->unique()->values()->all();

        $parGroupesAutres = $autresGroupes === [] ? [] : $this->creneauxDesGroupes($autresGroupes, true);

        $parEtudiant = [];

        foreach ($etudiants as $etudiant) {
            $creneaux = [];

            foreach ($etudiant->affectations->where('annee_universitaire_id', $annee->id)->where('statut', 'active') as $affectation) {
                foreach ($parGroupesAutres[(int) $affectation->groupe_id] ?? [] as $creneau) {
                    $creneaux[] = $creneau;
                }
            }

            $parEtudiant[$etudiant->id] = $creneaux;
        }

        return ['groupes' => $groupesParId, 'etudiants' => $parEtudiant];
    }

    /**
     * Pré-filtre par signature (programme/niveau/actif) : mêmes
     * conditions que `controler()` pour ces 3 codes (toujours bloquants,
     * sauf niveau avec force). Les groupes exclus reçoivent un
     * diagnostic synthétique au même format ; seuls les compatibles
     * passent le diagnostic complet. Verdict strictement identique,
     * paires évaluées divisées par 10 à 50.
     *
     * @return array{compatibles: Collection<int, Group>, diagnostiques: array<int, array>}
     */
    public function prefiltreSignature(Etudiant $etudiant, Collection $groupes, bool $force = false): array
    {
        $compatibles = collect();
        $diagnostiques = [];

        foreach ($groupes as $groupe) {
            if (! $groupe->actif) {
                $diagnostiques[$groupe->id] = $this->rejet('GROUP_INACTIVE', "Groupe {$groupe->code} inactif.");

                continue;
            }

            if ($groupe->programme_id !== null && $etudiant->programme_id !== null
                && (int) $groupe->programme_id !== (int) $etudiant->programme_id) {
                $diagnostiques[$groupe->id] = $this->rejet(
                    'PROGRAM_MISMATCH',
                    "Programme incompatible : groupe rattaché au programme {$groupe->programme_id}, étudiant au programme {$etudiant->programme_id}."
                );

                continue;
            }

            if (! $force && $groupe->niveau !== null && $etudiant->niveau !== null && $groupe->niveau !== $etudiant->niveau) {
                $diagnostiques[$groupe->id] = $this->rejet(
                    'LEVEL_MISMATCH',
                    "Niveau incompatible : étudiant {$etudiant->niveau}, groupe {$groupe->niveau} (exception autorisée uniquement)."
                );

                continue;
            }

            $compatibles->push($groupe);
        }

        return ['compatibles' => $compatibles, 'diagnostiques' => $diagnostiques];
    }

    /** @return array{admissible: bool, contraintes: array, conflits: array} */
    private function rejet(string $code, string $message): array
    {
        return [
            'admissible' => false,
            'contraintes' => [['code' => $code, 'gravite' => 'erreur', 'message' => $message, 'bloquant' => true]],
            'conflits' => [],
        ];
    }

    /**
     * Variante légère de la passe 1 : verdict booléen par groupe, sans
     * construction de messages (10× moins cher). Mêmes conditions
     * exactes que `admissibles()` ; les diagnostics complets sont
     * recalculés à la demande pour les seuls non-affectés.
     *
     * @return array<int, bool>
     */
    public function admissiblesLeger(Etudiant $etudiant, Collection $groupes, AnneeUniversitaire $annee, array $placesSimulees, ?int $faculteRestreinteId, bool $force = false, array $preload = [], bool $ignorerCapacite = false): array
    {
        $ctx = $preload['ctx'] ?? null;
        $slotsPrecharges = $preload['slots'] ?? null;
        $creneauxEtudiant = $slotsPrecharges !== null
            ? ($slotsPrecharges['etudiants'][$etudiant->id] ?? [])
            : $this->creneauxGroupesActifs($etudiant, $annee);
        $slotsCandidats = $slotsPrecharges !== null
            ? $slotsPrecharges['groupes']
            : $this->slotsParGroupe($groupes);

        $inscriptionStatut = $ctx !== null
            ? ($ctx['inscriptions'][$etudiant->id] ?? null)
            : InscriptionAcademique::where('etudiant_id', $etudiant->id)->where('annee_universitaire_id', $annee->id)->value('statut');

        $suspendu = $ctx !== null
            ? isset($ctx['suspendus'][$etudiant->id])
            : $etudiant->estSuspendu();

        $verdicts = [];

        foreach ($groupes as $groupe) {
            if ($suspendu || $inscriptionStatut === null || ! in_array($inscriptionStatut, ['en_cours', 'valide'], true)) {
                $verdicts[$groupe->id] = false;

                continue;
            }

            if (! $groupe->actif) {
                $verdicts[$groupe->id] = false;

                continue;
            }

            if ($groupe->programme_id !== null && $etudiant->programme_id !== null
                && (int) $groupe->programme_id !== (int) $etudiant->programme_id) {
                $verdicts[$groupe->id] = false;

                continue;
            }

            if (! $force && $groupe->niveau !== null && $etudiant->niveau !== null && $groupe->niveau !== $etudiant->niveau) {
                $verdicts[$groupe->id] = false;

                continue;
            }

            if ($faculteRestreinteId !== null
                && ((int) $groupe->faculte_id !== $faculteRestreinteId || (int) $etudiant->faculte_id !== $faculteRestreinteId)) {
                $verdicts[$groupe->id] = false;

                continue;
            }

            if (! $force && ! $ignorerCapacite && ($placesSimulees[$groupe->id] ?? 0) <= 0 && $groupe->capacite !== null) {
                $verdicts[$groupe->id] = false;

                continue;
            }

            $conflit = false;

            foreach ($slotsCandidats[$groupe->id] ?? [] as $slot) {
                foreach ($creneauxEtudiant as $autre) {
                    if ($autre['jour'] === $slot['jour'] && $this->chevauche($autre['debut'], $autre['fin'], $slot['debut'], $slot['fin'])) {
                        $conflit = true;
                        break;
                    }
                }

                if ($conflit) {
                    break;
                }
            }

            $verdicts[$groupe->id] = ! $conflit;
        }

        return $verdicts;
    }

    /**
     * Pistes d'action (affichage uniquement, jamais de contournement
     * des règles) : codes des groupes compatibles par signature,
     * pour guider après un blocage (ex. réinscrire puis affecter).
     *
     * @return array<int, string>
     */
    public function groupesCompatibles(Etudiant $etudiant, Collection $groupes, int $limite = 3): array
    {
        $codes = [];

        foreach ($this->prefiltreSignature($etudiant, $groupes)['compatibles'] as $groupe) {
            $codes[] = $groupe->code;

            if (count($codes) >= $limite) {
                break;
            }
        }

        return $codes;
    }

    /** @return array{type: string, gravite: string, raison: string}|null */
    private function motifScope(Etudiant $etudiant, Group $groupe, ?int $faculteRestreinteId): ?array
    {
        if ($faculteRestreinteId !== null
            && ((int) $groupe->faculte_id !== $faculteRestreinteId || (int) $etudiant->faculte_id !== $faculteRestreinteId)) {
            return $this->conflit('FACULTY_SCOPE', 'Hors périmètre faculté.');
        }

        return null;
    }

    /** @return array{type: string, gravite: string, raison: string}|null */
    private function motifCapacite(Group $groupe, int $places, bool $force): ?array
    {
        if (! $force && $places <= 0 && $groupe->capacite !== null) {
            return $this->conflit('CAPACITY', "Groupe complet : capacité {$groupe->capacite} atteinte (aucune place simulée restante).");
        }

        return null;
    }

    /**
     * @param  array<int, array{jour: string, debut: string, fin: string, groupe: string, matiere: string}>  $slotsGroupe
     * @param  array<int, array{jour: string, debut: string, fin: string, groupe: string}>  $creneauxEtudiant
     * @return array{type: string, gravite: string, raison: string}|null
     */
    private function motifHoraire(array $slotsGroupe, array $creneauxEtudiant): ?array
    {
        foreach ($slotsGroupe as $slot) {
            foreach ($creneauxEtudiant as $autre) {
                if ($autre['jour'] === $slot['jour'] && $this->chevauche($autre['debut'], $autre['fin'], $slot['debut'], $slot['fin'])) {
                    return $this->conflit(
                        'SCHEDULE',
                        "Conflit horaire : {$slot['matiere']} ({$slot['jour']} {$slot['debut']}–{$slot['fin']}) chevauche le groupe {$autre['groupe']}."
                    );
                }
            }
        }

        return null;
    }

    /**
     * Matrice candidats bulk (§6/§20) : TOUS les groupes évalués avec
     * motif unique + places restantes. Un seul pré-filtre par étudiant,
     * zéro requête avec préchargements (mémoire transitoire par ligne).
     *
     * @return array<int, array{groupe_id: int, code: string, compatible: bool, type: ?string, raison: ?string, places_restantes: ?int}>
     */
    public function matriceCandidats(Etudiant $etudiant, Collection $groupes, array $nomsGroupes, AnneeUniversitaire $annee, array $placesSimulees, ?int $faculteRestreinteId, bool $force = false, array $preload = [], ?int $selectionneId = null): array
    {
        $ctx = $preload['ctx'] ?? null;
        $slotsPrecharges = $preload['slots'] ?? null;
        $pre = $this->prefiltreSignature($etudiant, $groupes, $force);
        $rejetsSignature = $pre['diagnostiques'];

        // Invariants par étudiant (un seul test pour tous les groupes).
        $raisonEtudiant = null;

        if ($ctx !== null) {
            if (isset($ctx['suspendus'][$etudiant->id])) {
                $raisonEtudiant = ['type' => 'ETUDIANT_SUSPENDU', 'raison' => 'Étudiant suspendu : affectation impossible.'];
            } else {
                $statut = $ctx['inscriptions'][$etudiant->id] ?? null;

                if ($statut === null) {
                    $raisonEtudiant = ['type' => 'REGISTRATION_NOT_FOUND', 'raison' => "Affectation impossible : aucune inscription pour l'année {$annee->libelle}."];
                } elseif (! in_array($statut, ['en_cours', 'valide'], true)) {
                    $raisonEtudiant = ['type' => 'REGISTRATION_INVALID', 'raison' => "Affectation impossible : inscription {$statut} (année {$annee->libelle})."];
                }
            }
        }

        $creneauxEtudiant = $slotsPrecharges !== null
            ? ($slotsPrecharges['etudiants'][$etudiant->id] ?? [])
            : $this->creneauxGroupesActifs($etudiant, $annee);
        $slotsGroupes = $slotsPrecharges !== null
            ? $slotsPrecharges['groupes']
            : $this->slotsParGroupe($groupes);

        $matrice = [];

        foreach ($groupes as $groupe) {
            $motif = null;

            if ($groupe->id !== $selectionneId) {
                if (isset($rejetsSignature[$groupe->id])) {
                    $contrainte = $rejetsSignature[$groupe->id]['contraintes'][0];
                    $motif = ['type' => $contrainte['code'], 'raison' => $contrainte['message']];
                } elseif ($raisonEtudiant !== null) {
                    $motif = $raisonEtudiant;
                } elseif ($faculteRestreinteId !== null
                    && ((int) $groupe->faculte_id !== $faculteRestreinteId || (int) $etudiant->faculte_id !== $faculteRestreinteId)) {
                    $motif = ['type' => 'FACULTY_SCOPE', 'raison' => 'Hors périmètre faculté.'];
                } elseif (! $force && ($placesSimulees[$groupe->id] ?? 0) <= 0 && $groupe->capacite !== null) {
                    $motif = ['type' => 'CAPACITY', 'raison' => "Groupe complet : capacité {$groupe->capacite} atteinte (aucune place simulée restante)."];
                } else {
                    foreach ($slotsGroupes[$groupe->id] ?? [] as $slot) {
                        foreach ($creneauxEtudiant as $autre) {
                            if ($autre['jour'] === $slot['jour'] && $this->chevauche($autre['debut'], $autre['fin'], $slot['debut'], $slot['fin'])) {
                                $motif = ['type' => 'SCHEDULE', 'raison' => "Conflit horaire : {$slot['matiere']} ({$slot['jour']} {$slot['debut']}–{$slot['fin']}) chevauche le groupe {$autre['groupe']}."];
                                break;
                            }
                        }

                        if ($motif !== null) {
                            break;
                        }
                    }
                }
            }

            $matrice[] = [
                'groupe_id' => $groupe->id,
                'code' => $nomsGroupes[$groupe->id] ?? (string) $groupe->id,
                'compatible' => $motif === null,
                'type' => $motif['type'] ?? null,
                'raison' => $motif['raison'] ?? null,
                'places_restantes' => $groupe->capacite === null ? null : max(0, (int) ($placesSimulees[$groupe->id] ?? 0)),
            ];
        }

        return $matrice;
    }

    /** @return array{code: string, gravite: string, message: string, bloquant: bool} */
    public function expliquer(Etudiant $etudiant, Group $groupe, array $diagnostic): string
    {
        $lignes = ["Étudiant : {$etudiant->num_inscription}", "Groupe : {$groupe->code}"];

        foreach ($diagnostic['contraintes'] ?? [] as $c) {
            $lignes[] = "• [{$c['code']}] {$c['message']}";
        }

        foreach ($diagnostic['conflits'] ?? [] as $c) {
            $lignes[] = "• [{$c['type']}] {$c['raison']}";
        }

        return implode("\n", $lignes);
    }

    /**
     * @return array<int, array{jour: string, debut: string, fin: string, groupe: string}>
     */
    private function creneauxGroupesActifs(Etudiant $etudiant, AnneeUniversitaire $annee): array
    {
        $groupeIds = $etudiant->affectations
            ->where('annee_universitaire_id', $annee->id)
            ->where('statut', 'active')
            ->map(fn ($a) => (int) $a->groupe_id)
            ->all();

        if ($groupeIds === []) {
            return [];
        }

        $parGroupe = $this->creneauxDesGroupes($groupeIds);

        return array_merge(...array_values($parGroupe));
    }

    /**
     * @param  Collection<int, Group>|array<int>  $groupes
     * @return array<int, array<int, array{jour: string, debut: string, fin: string, groupe: string, matiere: string}>>
     */
    private function slotsParGroupe(Collection|array $groupes): array
    {
        $ids = $groupes instanceof Collection ? $groupes->map(fn (Group $g) => $g->id)->all() : array_values($groupes);

        if ($ids === []) {
            return [];
        }

        return $this->creneauxDesGroupes($ids, true);
    }

    /**
     * @param  array<int>  $groupeIds
     * @return array<int, array>
     */
    private function creneauxDesGroupes(array $groupeIds, bool $avecMatiere = false): array
    {
        $scheduleIds = Schedule::whereIn('groupe_id', $groupeIds)->where('actif', true)->pluck('id', 'groupe_id');

        if ($scheduleIds->isEmpty()) {
            return [];
        }

        $slots = ScheduleSlot::whereIn('schedule_id', $scheduleIds->values())
            ->where('status', '!=', 'cancelled')
            ->with(['matiere', 'schedule'])
            ->get();

        $parGroupe = [];

        foreach ($slots as $slot) {
            $groupeId = (int) $scheduleIds->flip()->get($slot->schedule_id);
            $parGroupe[$groupeId][] = [
                'jour' => $slot->day_of_week,
                'debut' => substr((string) $slot->start_time, 0, 5),
                'fin' => substr((string) $slot->end_time, 0, 5),
                'groupe' => (string) $groupeId,
                'matiere' => (string) ($slot->matiere?->nom ?? 'cours'),
            ];
        }

        return $parGroupe;
    }

    private function chevauche(string $debutA, string $finA, string $debutB, string $finB): bool
    {
        return $debutA < $finB && $debutB < $finA;
    }

    /** @return array{type: string, gravite: string, raison: string} */
    private function conflit(string $type, string $raison): array
    {
        return ['type' => $type, 'gravite' => 'erreur', 'raison' => $raison];
    }
}
