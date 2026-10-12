<?php

namespace App\Services\Affectations;

use App\Models\AffectationGroupe;
use App\Models\AnneeUniversitaire;
use App\Models\AssignmentItem;
use App\Models\AssignmentSimulation;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Modules\Schedule\Models\Group;
use App\Services\AffectationService;
use App\Services\AuditService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Refonte affectation §5/§10-19 (étapes 7-10) : simulation SANS
 * écriture métier (seule la simulation technique est persistée).
 * Ordre : éligibles → admissibles → plus contraints d'abord →
 * moins rempli (tie-break documenté) → rééquilibrage MOVE puis SWAP →
 * métriques explicites (jamais de score magique). Déterministe : aucun
 * random, départages par (effectif, places, id).
 */
final class AssignmentSimulationService
{
    private const ITERATIONS_MAX = 5000;

    public function __construct(
        private readonly AssignmentEligibilityResolver $eligibilite,
        private readonly AssignmentConstraintEngine $contraintes,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array{annee_id: int, groupe_ids: array<int>, etudiant_ids: array<int>, force?: bool}  $parametres
     */
    public function simuler(array $parametres, int $auteurId, ?int $faculteRestreinteId): AssignmentSimulation
    {
        $annee = AnneeUniversitaire::findOrFail($parametres['annee_id']);
        $force = (bool) ($parametres['force'] ?? false);

        // Seed de simulation (§8) : même seed + mêmes données = même
        // répartition ; nouveau seed = nouvelle répartition équivalente.
        // Jamais de shuffle() sans seed : l'aléatoire reste contraint
        // (départage uniquement, après contraintes + équilibrage).
        $seed = (string) ($parametres['seed'] ?? Str::upper(Str::random(8)));
        $parametres['seed'] = $seed;
        $strategie = (string) ($parametres['strategie'] ?? 'equilibre');
        $parametres['strategie'] = $strategie;
        $parametres['etudiant_ids'] = array_values(array_unique(array_map('intval', $parametres['etudiant_ids'] ?? [])));

        $groupes = Group::whereIn('id', $parametres['groupe_ids'])->orderBy('id')->get();

        abort_unless($groupes->isNotEmpty(), 422, 'Aucun groupe candidat.');

        foreach ($groupes as $groupe) {
            abort_if($faculteRestreinteId !== null && (int) $groupe->faculte_id !== $faculteRestreinteId, 404);
        }

        $nbCandidats = count($parametres['etudiant_ids']);
        $eligibles = $this->eligibilite->eligibles($parametres['etudiant_ids'], $annee);

        // Bulk : préchargements uniques, zéro N+1 (§39).
        $idsEligibles = $eligibles->map(fn (Etudiant $e) => $e->id)->all();
        $inscriptions = InscriptionAcademique::where('annee_universitaire_id', $annee->id)
            ->whereIn('etudiant_id', $idsEligibles !== [] ? $idsEligibles : [0])
            ->get(['id', 'etudiant_id', 'type', 'regime', 'programme_id', 'niveau_id', 'niveau'])
            ->keyBy('etudiant_id');
        $affectationService = app(AffectationService::class);
        $ctx = $affectationService->contexteBulk($idsEligibles, $groupes->map(fn (Group $g) => $g->id)->all(), $annee);
        $slots = $this->contraintes->prechargerCreneaux($eligibles, $groupes, $annee);
        $preload = ['ctx' => $ctx, 'slots' => $slots];

        $places = [];

        foreach ($groupes as $groupe) {
            $places[$groupe->id] = $groupe->capacite !== null
                ? max(0, (int) $groupe->capacite - ($ctx['effectifs'][$groupe->id] ?? 0))
                : PHP_INT_MAX;
        }

        // Passe 1 : options par étudiant (places initiales), tri
        // plus-contraints d'abord (§10) : (options, id).
        $options = [];

        foreach ($this->bucketiser($eligibles, $slots, $inscriptions) as $membres) {
            $representant = $membres[0];
            $verdicts = $this->contraintes->admissiblesLeger($representant, $groupes, $annee, $places, $faculteRestreinteId, $force, $preload);
            $admissibles = array_keys(array_filter($verdicts));
            sort($admissibles);

            foreach ($membres as $membre) {
                // Mémoire bornée : seuls les ids admissibles sont
                // retenus ; les diagnostics complets sont recalculés à
                // la demande (non-affectés) à la persistance.
                $options[$membre->id] = $admissibles;
            }
        }

        // Tri contraints-d'abord (§10) ; départage par seed ou ordre alphabétique.
        // Départages pré-calculés (le hash n'est pas recalculé par comparaison).
        $ordres = [];

        foreach ($eligibles as $etudiant) {
            $ordres[$etudiant->id] = [$this->departage($seed, $etudiant->id), count($options[$etudiant->id])];
        }

        if ($strategie === 'alphabetique') {
            $ordonnes = $eligibles->sortBy([
                fn (Etudiant $a, Etudiant $b) => [
                    $ordres[$a->id][1],
                    mb_strtoupper((string) $a->nom),
                    mb_strtoupper((string) $a->prenom),
                    $a->id,
                ] <=> [
                    $ordres[$b->id][1],
                    mb_strtoupper((string) $b->nom),
                    mb_strtoupper((string) $b->prenom),
                    $b->id,
                ],
            ])->values();
        } else {
            $ordonnes = $eligibles->sortBy([
                fn (Etudiant $a, Etudiant $b) => [$ordres[$a->id][1], $ordres[$a->id][0]] <=> [$ordres[$b->id][1], $ordres[$b->id][0]],
            ])->values();
        }

        // Passe 2 : affectation selon la stratégie choisie (tie-break §19).
        $propositions = [];
        $effectifsSimules = array_fill_keys($groupes->map(fn (Group $g) => $g->id)->all(), 0);
        $capacitesGroupes = $groupes->mapWithKeys(fn (Group $g) => [$g->id => $g->capacite])->all();
        $effectifsGenres = array_fill_keys($groupes->map(fn (Group $g) => $g->id)->all(), ['M' => 0, 'F' => 0, 'autre' => 0]);
        $effectifsRedoublants = array_fill_keys($groupes->map(fn (Group $g) => $g->id)->all(), 0);

        foreach ($ordonnes as $etudiant) {
            // Passe 1 réutilisée : seules les places ont évolué.
            $candidatsGroupe = array_values(array_filter(
                $options[$etudiant->id],
                fn (int $gid) => $force || ($places[$gid] ?? 0) > 0 || ($capacitesGroupes[$gid] ?? null) === null
            ));
            sort($candidatsGroupe);

            if ($candidatsGroupe === []) {
                $propositions[$etudiant->id] = ['groupe_id' => null];

                continue;
            }

            $sexeBrut = strtoupper(trim((string) $etudiant->sexe));
            $sexe = in_array($sexeBrut, ['F', 'FEMININ', 'FEMME'], true) ? 'F' : (in_array($sexeBrut, ['M', 'MASCULIN', 'HOMME'], true) ? 'M' : 'autre');
            $estRedoublant = ($inscriptions[$etudiant->id]->type ?? null) === 'reinscription';

            if ($strategie === 'mixite_genre') {
                usort($candidatsGroupe, function (int $x, int $y) use ($effectifsGenres, $effectifsSimules, $places, $sexe) {
                    $genreX = $effectifsGenres[$x][$sexe] ?? 0;
                    $genreY = $effectifsGenres[$y][$sexe] ?? 0;

                    return [$genreX, $effectifsSimules[$x], -min($places[$x], 1 << 30), $x]
                        <=> [$genreY, $effectifsSimules[$y], -min($places[$y], 1 << 30), $y];
                });
            } elseif ($strategie === 'redoublants_homogenes') {
                usort($candidatsGroupe, function (int $x, int $y) use ($effectifsRedoublants, $effectifsSimules, $places, $estRedoublant) {
                    $redX = $estRedoublant ? ($effectifsRedoublants[$x] ?? 0) : 0;
                    $redY = $estRedoublant ? ($effectifsRedoublants[$y] ?? 0) : 0;

                    return [$redX, $effectifsSimules[$x], -min($places[$x], 1 << 30), $x]
                        <=> [$redY, $effectifsSimules[$y], -min($places[$y], 1 << 30), $y];
                });
            } else {
                usort($candidatsGroupe, fn (int $x, int $y) => [$effectifsSimules[$x], -min($places[$x], 1 << 30), $x] <=> [$effectifsSimules[$y], -min($places[$y], 1 << 30), $y]);
            }

            $choisi = $candidatsGroupe[0];

            $justification = match ($strategie) {
                'mixite_genre' => "Mixité de genre : groupe avec effectif {$sexe} équilibré ({$effectifsGenres[$choisi][$sexe]} existants) parmi les admissibles.",
                'redoublants_homogenes' => $estRedoublant ? "Équilibre pédagogique : répartition équilibrée des réinscriptions/redoublants ({$effectifsRedoublants[$choisi]} existants)." : 'Groupe le moins rempli parmi les admissibles.',
                'alphabetique' => 'Répartition alphabétique : ordre lexicographique (nom/prénom).',
                default => 'Groupe le moins rempli parmi les admissibles (tie-break : effectif, places, id).',
            };

            $propositions[$etudiant->id] = [
                'groupe_id' => $choisi,
                'alternatives' => array_slice($candidatsGroupe, 1),
                'justification' => $justification,
            ];

            if ($places[$choisi] !== PHP_INT_MAX) {
                $places[$choisi]--;
            }

            $effectifsSimules[$choisi]++;
            $effectifsGenres[$choisi][$sexe] = ($effectifsGenres[$choisi][$sexe] ?? 0) + 1;
            if ($estRedoublant) {
                $effectifsRedoublants[$choisi]++;
            }
        }

        // IDs inéligibles (sans inscription, suspendus…) : items
        // `non_affecte` motivés pour qu'aucun candidat ne disparaisse
        // sans explication.
        $idsEligibles = array_flip($idsEligibles);
        $manquants = Etudiant::whereIn('id', array_values(array_diff(
            array_map('intval', $parametres['etudiant_ids']),
            array_keys($idsEligibles)
        )))->get();

        foreach ($manquants as $manquant) {
            $motif = $this->eligibilite->motifIneligibilite($manquant, $annee) ?? 'Candidat non retenu.';
            $propositions[$manquant->id] = ['groupe_id' => null, 'motif_ineligibilite' => $motif];
        }

        $nbIneligibles = count($manquants);

        // Passe 3 : rééquilibrage MOVE puis SWAP (§14-15, jamais au
        // prix d'une contrainte §16), terminaison garantie (§54).
        $mouvements = $this->reequilibrer($propositions, $ordonnes, $groupes, $annee, $places, $effectifsSimules, $faculteRestreinteId, $force, $preload);

        // Métriques sur l'occupation TOTALE (existant + simulation),
        // pas sur les seuls nouveaux (écart sinon trompeur).
        $occupationTotale = [];

        foreach ($groupes as $groupe) {
            $occupationTotale[$groupe->id] = ($ctx['effectifs'][$groupe->id] ?? 0) + ($effectifsSimules[$groupe->id] ?? 0);
        }

        $metriques = $this->metriques($occupationTotale, $groupes);

        // Capacité insuffisante (§12) : non-affectés qui AURAIENT un
        // groupe sans la contrainte capacitaire → reste à placer.
        $parId = $eligibles->keyBy('id');
        $resteCapacite = 0;

        foreach ($propositions as $etudiantId => $proposition) {
            if ($proposition['groupe_id'] !== null || isset($proposition['motif_ineligibilite'])) {
                continue;
            }

            $etudiant = $parId->get($etudiantId);

            if ($etudiant === null) {
                continue;
            }

            $sansCapacite = $this->contraintes->admissiblesLeger(
                $etudiant, $groupes, $annee, $places, $faculteRestreinteId, $force, $preload, true
            );

            if (in_array(true, $sansCapacite, true)) {
                $resteCapacite++;
            }
        }

        $plan = $this->planCapacite($groupes, $resteCapacite);
        $totalPlaces = array_sum(array_map(fn ($p) => $p === PHP_INT_MAX ? 0 : $p, $places));
        $composition = $this->compositionParGroupe($propositions, $eligibles, $inscriptions, $groupes);

        $metriques += [
            'strategie' => $strategie,
            'genres_par_groupe' => $composition['genres'],
            'redoublants_par_groupe' => $composition['redoublants'],
            'taux_affectation_pct' => $eligibles->count() > 0 ? round($this->compterAffectes($propositions) / $eligibles->count() * 100, 1) : 0.0,
            'groupes_utilises' => count(array_filter($occupationTotale, fn (int $occ) => $occ > 0)),
            'groupes_satures' => count(array_filter(
                $groupes->all(),
                fn (Group $g) => $g->capacite !== null && ($occupationTotale[$g->id] ?? 0) >= (int) $g->capacite
            )),
            'capacite_totale' => (int) $groupes->sum(fn (Group $g) => $g->capacite ?? 0),
            'capacite_disponible' => $totalPlaces,
        ] + $plan;

        return DB::transaction(function () use ($parametres, $seed, $annee, $auteurId, $nbCandidats, $nbIneligibles, $eligibles, $propositions, $mouvements, $metriques, $groupes, $places, $faculteRestreinteId, $force, $preload, $inscriptions) {
            $affectes = count(array_filter($propositions, fn (array $p) => $p['groupe_id'] !== null));

            $simulation = AssignmentSimulation::create([
                'reference' => 'ASS-'.now()->format('Ymd-His').'-'.Str::upper(Str::random(4)),
                'statut' => 'simulee',
                'annee_universitaire_id' => $annee->id,
                'parametres' => $parametres,
                'hash_entrees' => $this->hashEntrees($parametres),
                'seed' => $seed,
                'candidats' => $nbCandidats,
                'eligibles' => $eligibles->count(),
                'affectes' => $affectes,
                'non_affectes' => $eligibles->count() + $nbIneligibles - $affectes,
                'conflits' => 0,
                'metriques' => $metriques + ['mouvements' => $mouvements],
                'auteur_id' => $auteurId,
            ]);

            $lignes = [];
            $maintenant = now();
            $parId = $eligibles->keyBy('id');
            $totalConflits = 0;
            $nomsGroupes = [];

            foreach ($groupes as $groupe) {
                $nomsGroupes[$groupe->id] = $groupe->code;
            }

            // Matrices partagées par signature (programme/niveau/
            // faculté, sans créneaux) : 1 calcul pour N étudiants.
            // Sélectionné forcé compatible a posteriori par ligne.
            $avecCreneaux = [];

            foreach ($preload['slots']['etudiants'] ?? [] as $eid => $creneaux) {
                if ($creneaux !== []) {
                    $avecCreneaux[$eid] = true;
                }
            }

            $matrices = [];

            foreach ($propositions as $etudiantId => $proposition) {
                $etudiant = $parId->get($etudiantId);

                // Matrice candidats (§6) : TOUS les groupes évalués avec
                // motif. Mémoire bornée : encodée ligne par ligne.
                $candidats = [];
                $conflits = [];
                $raison = 'Aucune affectation automatique possible.';

                if (isset($proposition['motif_ineligibilite'])) {
                    $raison = $proposition['motif_ineligibilite'];
                    $conflits = [['type' => 'ELIGIBILITE', 'gravite' => 'erreur', 'raison' => $proposition['motif_ineligibilite']]];
                } elseif ($etudiant !== null && ! isset($avecCreneaux[$etudiantId])) {
                    $progId = $etudiant->programme_id ?? $inscriptions[$etudiantId]->programme_id ?? null;
                    $niv = $etudiant->niveau ?? $inscriptions[$etudiantId]->niveau ?? null;
                    $cle = "{$progId}|{$niv}|{$etudiant->faculte_id}";

                    if (! array_key_exists($cle, $matrices)) {
                        $matrices[$cle] = $this->contraintes->matriceCandidats(
                            $etudiant, $groupes, $nomsGroupes, $annee, $places,
                            $faculteRestreinteId, $force, $preload
                        );
                    }

                    $candidats = $matrices[$cle];

                    foreach ($candidats as &$entree) {
                        if ($entree['groupe_id'] === $proposition['groupe_id']) {
                            $entree['compatible'] = true;
                            $entree['type'] = null;
                            $entree['raison'] = null;
                        }
                    }

                    unset($entree);
                } elseif ($etudiant !== null) {
                    $candidats = $this->contraintes->matriceCandidats(
                        $etudiant, $groupes, $nomsGroupes, $annee, $places,
                        $faculteRestreinteId, $force, $preload, $proposition['groupe_id']
                    );
                }

                if ($candidats !== [] && ! isset($proposition['motif_ineligibilite'])) {
                    $conflits = array_values(array_filter(array_map(
                        fn (array $c) => ! $c['compatible']
                            ? ['type' => $c['type'], 'gravite' => 'erreur', 'raison' => "G{$c['code']} → {$c['raison']}"]
                            : null,
                        $candidats
                    )));
                    $totalConflits += count($conflits);
                }

                $flexibilite = count(array_filter($candidats, fn (array $c) => $c['compatible']));

                $lignes[] = [
                    'simulation_id' => $simulation->id,
                    'etudiant_id' => $etudiantId,
                    'groupe_propose_id' => $proposition['groupe_id'],
                    'alternatives' => json_encode($proposition['alternatives'] ?? []),
                    'candidats' => json_encode($candidats),
                    'flexibilite' => $flexibilite,
                    'contraintes_validees' => json_encode($proposition['groupe_id'] !== null ? $this->checklist($etudiant, $nomsGroupes[$proposition['groupe_id']] ?? null) : []),
                    'conflits' => json_encode($conflits),
                    'raison' => $proposition['groupe_id'] !== null
                        ? ($proposition['justification'] ?? 'Groupe le moins rempli parmi les admissibles (tie-break : effectif, places, id).')
                        : $raison,
                    'statut' => $proposition['groupe_id'] !== null ? 'propose' : 'non_affecte',
                    'created_at' => $maintenant,
                    'updated_at' => $maintenant,
                ];

                // Chunk réduit (matrice candidats ~30 Ko/ligne : 100
                // lignes ≈ 3 Mo/insert, mémoire bornée).
                if (count($lignes) >= 100) {
                    AssignmentItem::insert($lignes);
                    $lignes = [];
                    gc_collect_cycles();
                }
            }

            if ($lignes !== []) {
                AssignmentItem::insert($lignes);
            }

            // Compteur de conflits = entrées expliquant les non-affectés.
            $simulation->update(['conflits' => $totalConflits]);

            $premier = $eligibles->first();

            if ($premier !== null) {
                $this->audit->tracer($premier, 'assignment.simulation_completed', 'simulation', $simulation->id, null, [
                    'reference' => $simulation->reference, 'affectes' => $affectes,
                ]);
            }

            return $simulation;
        });
    }

    /**
     * Départage déterministe par seed (§8) : stable pour un seed donné.
     */
    public function departage(string $seed, int $etudiantId): int
    {
        return (int) hexdec(substr(hash('sha256', $seed.':'.$etudiantId), 0, 8));
    }

    /**
     * Régénération : mêmes paramètres, nouveau seed, lignée tracée.
     */
    public function regenerer(AssignmentSimulation $simulation, int $auteurId, ?int $faculteRestreinteId): AssignmentSimulation
    {
        $parametres = $simulation->parametres;
        $parametres['seed'] = Str::upper(Str::random(8));

        $nouvelle = $this->simuler($parametres, $auteurId, $faculteRestreinteId);
        $nouvelle->update(['simulation_origine_id' => $simulation->id]);

        return $nouvelle->fresh();
    }

    public function hashEntrees(array $parametres): string
    {
        $maxAffectation = (string) AffectationGroupe::max('updated_at');
        $groupeIds = $parametres['groupe_ids'] ?? [];
        $capacites = Group::whereIn('id', $groupeIds)->orderBy('id')->pluck('capacite', 'id')->all();
        $maxGroupe = (string) Group::whereIn('id', $groupeIds !== [] ? $groupeIds : [0])->max('updated_at');

        return hash('sha256', json_encode([$parametres, $maxAffectation, $capacites, $maxGroupe]));
    }

    /**
     * @param  array<int, array{groupe_id: ?int, diagnostics?: array}>  $propositions
     * @return array<int, string> justifications des mouvements
     */
    private function reequilibrer(array &$propositions, Collection $ordonnes, Collection $groupes, AnneeUniversitaire $annee, array &$places, array &$effectifs, ?int $faculteRestreinteId, bool $force, array $preload = []): array
    {
        $mouvements = [];
        $iterations = 0;

        while ($iterations++ < self::ITERATIONS_MAX) {
            $max = max($effectifs);
            $min = min($effectifs);

            if ($max - $min <= 1) {
                break;
            }

            $gMax = array_search($max, $effectifs, true);
            $gMin = array_search($min, $effectifs, true);

            // MOVE : un étudiant de Gmax admissible vers Gmin.
            $deplace = false;

            foreach ($ordonnes as $etudiant) {
                if (($propositions[$etudiant->id]['groupe_id'] ?? null) !== $gMax) {
                    continue;
                }

                $ok = $this->contraintes->admissiblesLeger($etudiant, $groupes->where('id', $gMin)->values(), $annee, $places, $faculteRestreinteId, $force, $preload);

                if (($ok[$gMin] ?? false)) {
                    $propositions[$etudiant->id]['groupe_id'] = $gMin;
                    $propositions[$etudiant->id]['justification'] = "Rééquilibrage : G{$gMax} surchargé → G{$gMin} sous-utilisé, aucun conflit détecté.";
                    $effectifs[$gMax]--;
                    $effectifs[$gMin]++;

                    if ($places[$gMin] !== PHP_INT_MAX) {
                        $places[$gMin]--;
                    }

                    if ($places[$gMax] !== PHP_INT_MAX) {
                        $places[$gMax]++;
                    }

                    $mouvements[$etudiant->id] = "G{$gMax} → G{$gMin} (rééquilibrage)";
                    $deplace = true;
                    break;
                }
            }

            if ($deplace) {
                continue;
            }

            // SWAP : échange A(Gmax)↔B(Gmin) mutuellement valide.
            if ($this->swap($propositions, $ordonnes, $groupes, $annee, $gMax, $gMin, $places, $faculteRestreinteId, $force, $mouvements, $preload)) {
                continue;
            }

            break;
        }

        return $mouvements;
    }

    private function swap(array &$propositions, Collection $ordonnes, Collection $groupes, AnneeUniversitaire $annee, int $gMax, int $gMin, array $places, ?int $faculteRestreinteId, bool $force, array &$mouvements, array $preload = []): bool
    {
        $dansMax = $ordonnes->filter(fn (Etudiant $e) => ($propositions[$e->id]['groupe_id'] ?? null) === $gMax)->values();
        $dansMin = $ordonnes->filter(fn (Etudiant $e) => ($propositions[$e->id]['groupe_id'] ?? null) === $gMin)->values();
        $paires = 0;

        foreach ($dansMax as $a) {
            foreach ($dansMin as $b) {
                if (++$paires > 2000) {
                    return false;
                }

                $okA = $this->contraintes->admissiblesLeger($a, $groupes->where('id', $gMin)->values(), $annee, $places, $faculteRestreinteId, $force, $preload);
                $okB = $this->contraintes->admissiblesLeger($b, $groupes->where('id', $gMax)->values(), $annee, $places, $faculteRestreinteId, $force, $preload);

                if (($okA[$gMin] ?? false) && ($okB[$gMax] ?? false)) {
                    $propositions[$a->id]['groupe_id'] = $gMin;
                    $propositions[$b->id]['groupe_id'] = $gMax;
                    $propositions[$a->id]['justification'] = "Swap : échange avec {$b->num_inscription} (G{$gMax}↔G{$gMin}), équilibrage amélioré.";
                    $propositions[$b->id]['justification'] = "Swap : échange avec {$a->num_inscription} (G{$gMin}↔G{$gMax}), équilibrage amélioré.";
                    $mouvements[$a->id] = "swap G{$gMax}→G{$gMin}";
                    $mouvements[$b->id] = "swap G{$gMin}→G{$gMax}";

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Regroupe les éligibles par signature (programme/niveau/faculté) :
     * à créneaux vides, le verdict léger est identique pour tout le
     * groupe → calculé une fois. Les étudiants avec créneaux restent
     * individuels (conflits horaires propres).
     *
     * @param  Collection<int, Etudiant>  $eligibles
     * @param  array{groupes: array, etudiants: array}  $slots
     * @param  Collection<int, InscriptionAcademique>|array<int, InscriptionAcademique>  $inscriptions
     * @return array<int, array<int, Etudiant>>
     */
    private function bucketiser(Collection $eligibles, array $slots, $inscriptions = []): array
    {
        $buckets = [];

        foreach ($eligibles as $etudiant) {
            $creneaux = $slots['etudiants'][$etudiant->id] ?? [];
            $progId = $etudiant->programme_id ?? $inscriptions[$etudiant->id]->programme_id ?? null;
            $niv = $etudiant->niveau ?? $inscriptions[$etudiant->id]->niveau ?? null;

            $cle = $creneaux === []
                ? "s:{$progId}|{$niv}|{$etudiant->faculte_id}"
                : "u:{$etudiant->id}";

            $buckets[$cle][] = $etudiant;
        }

        return array_values($buckets);
    }

    /**
     * Plan capacitaire (§12-14) : reste à placer → groupes
     * supplémentaires (ceil, capacité médiane réellement applicable)
     * + code suggéré (préfixe commun + numéro suivant). La création
     * reste un acte administratif (jamais auto-créée).
     *
     * @param  Collection<int, Group>  $groupes
     * @return array{manque: int, groupes_supplementaires: int, capacite_recommandee: int, code_groupe_suggere: ?string}
     */
    public function planCapacite(Collection $groupes, int $resteACaser): array
    {
        if ($resteACaser <= 0) {
            return ['manque' => 0, 'groupes_supplementaires' => 0, 'capacite_recommandee' => 0, 'code_groupe_suggere' => null];
        }

        $capacites = $groupes->map(fn (Group $g) => $g->capacite)->filter(fn ($c) => $c !== null)->sort()->values();

        if ($capacites->isEmpty()) {
            return ['manque' => $resteACaser, 'groupes_supplementaires' => 0, 'capacite_recommandee' => 0, 'code_groupe_suggere' => null];
        }

        $standard = (int) $capacites->median();

        if ($standard <= 0) {
            return ['manque' => $resteACaser, 'groupes_supplementaires' => 0, 'capacite_recommandee' => 0, 'code_groupe_suggere' => null];
        }

        return [
            'manque' => $resteACaser,
            'groupes_supplementaires' => (int) ceil($resteACaser / $standard),
            'capacite_recommandee' => $standard,
            'code_groupe_suggere' => $this->codeGroupeSuivant($groupes),
        ];
    }

    /**
     * @param  Collection<int, Group>  $groupes
     */
    private function codeGroupeSuivant(Collection $groupes): ?string
    {
        $numerotes = [];

        foreach ($groupes as $groupe) {
            if (preg_match('/^(.*?)(\d+)$/', $groupe->code, $m) === 1) {
                $numerotes[$m[1]][] = (int) $m[2];
            }
        }

        if ($numerotes === []) {
            return null;
        }

        uasort($numerotes, fn (array $a, array $b) => count($b) <=> count($a));
        $prefixe = (string) array_key_first($numerotes);

        return $prefixe.str_pad((string) (max($numerotes[$prefixe]) + 1), 2, '0', STR_PAD_LEFT);
    }

    /** @param array<int, array{groupe_id: ?int}> $propositions */
    private function compterAffectes(array $propositions): int
    {
        return count(array_filter($propositions, fn (array $p) => $p['groupe_id'] !== null));
    }

    /** @return array{minimum: int, maximum: int, moyenne: float, ecart_max: int, capacite_utilisee_pct: float, capacite_restante: int} */
    private function metriques(array $effectifs, Collection $groupes): array
    {
        $valeurs = array_values($effectifs);
        $capaciteTotale = $groupes->sum(fn (Group $g) => $g->capacite ?? 0);
        $capaciteFinie = $groupes->contains(fn (Group $g) => $g->capacite !== null);

        return [
            'minimum' => min($valeurs),
            'maximum' => max($valeurs),
            'moyenne' => round(array_sum($valeurs) / max(1, count($valeurs)), 2),
            'ecart_max' => max($valeurs) - min($valeurs),
            'capacite_utilisee_pct' => $capaciteFinie && $capaciteTotale > 0
                ? round(array_sum($valeurs) / $capaciteTotale * 100, 1) : 0.0,
            'capacite_restante' => $capaciteFinie
                ? (int) $groupes->sum(fn (Group $g) => $g->capacite !== null ? max(0, (int) $g->capacite - ($effectifs[$g->id] ?? 0)) : 0) : -1,
        ];
    }

    /** @return array<int, string> */
    private function checklist(?Etudiant $etudiant, ?string $codeGroupe): array
    {
        if ($etudiant === null || $codeGroupe === null) {
            return [];
        }

        return [
            'Formation compatible', 'Parcours compatible', 'Niveau compatible',
            'Horaire compatible', 'Capacité disponible', 'Aucun conflit',
        ];
    }

    /** @return array<int, array{type: string, gravite: string, raison: string}> */
    private function conflitsResumes(array $diagnostics, Collection $groupes, ?array $codes = null): array
    {
        $resumes = [];
        $codes ??= $groupes->mapWithKeys(fn (Group $g) => [$g->id => $g->code])->all();

        foreach ($diagnostics as $groupeId => $diagnostic) {
            $code = $codes[$groupeId] ?? (string) $groupeId;

            foreach ($diagnostic['conflits'] ?? [] as $conflit) {
                $resumes[] = ['type' => $conflit['type'], 'gravite' => 'erreur', 'raison' => "G{$code} → {$conflit['raison']}"];
            }

            foreach (array_filter($diagnostic['contraintes'] ?? [], fn (array $c) => $c['bloquant']) as $c) {
                $resumes[] = ['type' => $c['code'], 'gravite' => 'erreur', 'raison' => "G{$code} → {$c['message']}"];
            }
        }

        return $resumes;
    }

    /**
     * @param  array<int, array{groupe_id: ?int}>  $propositions
     * @param  Collection<int, Etudiant>  $eligibles
     * @param  Collection<int, InscriptionAcademique>|array<int, InscriptionAcademique>  $inscriptions
     * @param  Collection<int, Group>  $groupes
     * @return array{genres: array<int, array{femmes: int, hommes: int, pct_femmes: float}>, redoublants: array<int, array{redoublants: int, nouveaux: int, pct_redoublants: float}>}
     */
    private function compositionParGroupe(array $propositions, Collection $eligibles, $inscriptions, Collection $groupes): array
    {
        $parId = $eligibles->keyBy('id');
        $genres = [];
        $redoublants = [];

        foreach ($groupes as $groupe) {
            $femmes = 0;
            $hommes = 0;
            $reds = 0;
            $nouvs = 0;

            foreach ($propositions as $eid => $prop) {
                if (($prop['groupe_id'] ?? null) === $groupe->id) {
                    $e = $parId->get($eid);
                    if ($e !== null) {
                        $sexe = strtoupper(trim((string) $e->sexe));
                        if (in_array($sexe, ['F', 'FEMININ', 'FEMME'], true)) {
                            $femmes++;
                        } else {
                            $hommes++;
                        }

                        $type = $inscriptions[$eid]->type ?? 'premiere';
                        if ($type === 'reinscription') {
                            $reds++;
                        } else {
                            $nouvs++;
                        }
                    }
                }
            }

            $total = $femmes + $hommes;
            $genres[$groupe->id] = [
                'femmes' => $femmes,
                'hommes' => $hommes,
                'pct_femmes' => $total > 0 ? round($femmes / $total * 100, 1) : 0.0,
            ];

            $redoublants[$groupe->id] = [
                'redoublants' => $reds,
                'nouveaux' => $nouvs,
                'pct_redoublants' => $total > 0 ? round($reds / $total * 100, 1) : 0.0,
            ];
        }

        return ['genres' => $genres, 'redoublants' => $redoublants];
    }
}
