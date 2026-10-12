<?php

namespace App\Services;

use App\Events\PopulationChanged;
use App\Models\AffectationGroupe;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Models\Note;
use App\Models\SituationEtudiant;
use App\Models\User;
use App\Modules\Enseignement\Models\SousGroupe;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Schedule;
use App\Services\Affectations\AssignmentSimulationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Moteur d'affectation étudiant → groupe (§17-18, §39 + chantier
 * affectation) : contraintes structurées (codes §29), idempotence,
 * verrouillage capacité anti-concurrence, impacts, bulk + simulation,
 * retrait historisé. `etudiants.groupe_id` reste le pointeur courant.
 *
 * Chaîne imposée : prévisualiser → expliquer → confirmer →
 * transaction → auditer → notifier.
 */
class AffectationService
{
    public function __construct(private NotificationService $notifications) {}

    /**
     * Contrôle toutes les contraintes SANS écrire.
     *
     * @param  array{inscriptions?: array<int, string>, suspendus?: array<int, true>, existantes?: array<int, int>, effectifs?: array<int, int>|null}  $contexte
     *                                                                                                                                                            Contexte préchargé (bulk) : mêmes règles, zéro requête.
     * @return array<int, array{code: string, gravite: string, message: string, bloquant: bool}>
     */
    public function controler(
        Etudiant $etudiant,
        Group $groupe,
        AnneeUniversitaire $annee,
        bool $force = false,
        ?int $sousGroupeId = null,
        ?array $contexte = null,
    ): array {
        $contraintes = [];

        $suspendu = $contexte !== null && array_key_exists('suspendus', $contexte)
            ? isset($contexte['suspendus'][$etudiant->id])
            : $etudiant->estSuspendu();

        if ($suspendu) {
            $contraintes[] = $this->erreur('ETUDIANT_SUSPENDU', 'Étudiant suspendu : affectation impossible.');
        }

        if ($contexte !== null && array_key_exists('inscriptions', $contexte)) {
            $statutInscription = $contexte['inscriptions'][$etudiant->id] ?? null;
            $inscription = $statutInscription !== null ? (object) ['statut' => $statutInscription] : null;
        } else {
            $inscription = InscriptionAcademique::where('etudiant_id', $etudiant->id)
                ->where('annee_universitaire_id', $annee->id)
                ->first();
        }

        if ($inscription === null) {
            $contraintes[] = $this->erreur(
                'REGISTRATION_NOT_FOUND',
                "Affectation impossible : aucune inscription pour l'année {$annee->libelle}."
            );
        } elseif (! in_array($inscription->statut, ['en_cours', 'valide'], true)) {
            $contraintes[] = $this->erreur(
                'REGISTRATION_INVALID',
                "Affectation impossible : inscription {$inscription->statut} (année {$annee->libelle})."
            );
        }

        if (! $groupe->actif) {
            $contraintes[] = $this->erreur('GROUP_INACTIVE', "Groupe {$groupe->code} inactif.");
        }

        if ($groupe->programme_id !== null && $etudiant->programme_id !== null
            && (int) $groupe->programme_id !== (int) $etudiant->programme_id) {
            $contraintes[] = $this->erreur(
                'PROGRAM_MISMATCH',
                "Programme incompatible : groupe rattaché au programme {$groupe->programme_id}, étudiant au programme {$etudiant->programme_id}."
            );
        }

        if ($groupe->faculte_id !== null && $etudiant->faculte_id !== null
            && (int) $groupe->faculte_id !== (int) $etudiant->faculte_id) {
            $contraintes[] = [
                'code' => 'FACULTY_MISMATCH',
                'gravite' => 'erreur',
                'message' => "Faculté incompatible : groupe rattaché à la faculté {$groupe->faculte_id}, étudiant à la faculté {$etudiant->faculte_id} (exception autorisée uniquement).",
                'bloquant' => ! $force,
            ];
        }

        if ($groupe->niveau !== null && $etudiant->niveau !== null && $groupe->niveau !== $etudiant->niveau) {
            $contraintes[] = [
                'code' => 'LEVEL_MISMATCH',
                'gravite' => 'erreur',
                'message' => "Niveau incompatible : étudiant {$etudiant->niveau}, groupe {$groupe->niveau} (exception autorisée uniquement).",
                'bloquant' => ! $force,
            ];
        }

        // Capacité : effectif préchargé en bulk, comptage sinon.
        if ($contexte !== null && array_key_exists('effectifs', $contexte)) {
            $effectif = $contexte['effectifs'][$groupe->id] ?? 0;
            $complet = $groupe->capacite !== null && $effectif >= (int) $groupe->capacite;
        } else {
            $effectif = $groupe->effectifActuel();
            $complet = $groupe->estComplet();
        }

        if ($complet) {
            $contraintes[] = [
                'code' => 'GROUP_FULL',
                'gravite' => 'erreur',
                'message' => "Groupe complet : {$effectif}/{$groupe->capacite} (dépassement = exception autorisée).",
                'bloquant' => ! $force,
            ];
        }

        if ($contexte !== null && array_key_exists('existantes', $contexte)) {
            $donnees = $contexte['existantes'][$etudiant->id] ?? null;
            $existante = $donnees !== null ? (object) ['groupe_id' => $donnees['groupe_id'], 'groupe' => (object) ['code' => $donnees['code']]] : null;
        } else {
            $existante = AffectationGroupe::where('etudiant_id', $etudiant->id)
                ->where('annee_universitaire_id', $annee->id)
                ->where('statut', 'active')
                ->first();
        }

        if ($existante !== null && (int) $existante->groupe_id === (int) $groupe->id) {
            $contraintes[] = [
                'code' => 'GROUP_ASSIGNMENT_EXISTS',
                'gravite' => 'info',
                'message' => "Déjà affecté au groupe {$groupe->code} pour {$annee->libelle} : aucune opération.",
                'bloquant' => false,
            ];
        } elseif ($existante !== null) {
            $contraintes[] = [
                'code' => 'MULTIPLE_PRIMARY_GROUPS',
                'gravite' => 'info',
                'message' => "Transfert : l'affectation active ({$existante->groupe?->code}) sera clôturée, historique conservé.",
                'bloquant' => false,
            ];
        }

        if ($sousGroupeId !== null) {
            $sousGroupe = SousGroupe::find($sousGroupeId);

            if ($sousGroupe === null || (int) $sousGroupe->groupe_id !== (int) $groupe->id) {
                $contraintes[] = $this->erreur('INVALID_SUBGROUP', 'Sous-groupe invalide pour ce groupe.');
            }
        }

        return $contraintes;
    }

    /**
     * @param  array{inscriptions?: array<int, string>, suspendus?: array<int, true>, existantes?: array<int, array{groupe_id: int, code: string}>, effectifs?: array<int, int>|null}|null  $contexte
     *                                                                                                                                                                                                 Préchargé bulk (mêmes règles, zéro requête). $notifier=false en
     *                                                                                                                                                                                                 bulk quand l'appelant notifie déjà en lot (évite les doublons).
     *
     * @throws \DomainException (messages explicites : pourquoi refusé + action possible)
     */
    public function affecter(
        Etudiant $etudiant,
        Group $groupe,
        AnneeUniversitaire $annee,
        ?int $auteurId,
        ?string $motif = null,
        bool $force = false,
        ?int $sousGroupeId = null,
        ?array $contexte = null,
        bool $notifier = true,
    ): AffectationGroupe {
        if ($force && $auteurId !== null) {
            $auteur = User::find($auteurId);
            abort_unless($auteur !== null && $auteur->hasPermission('groupes.manage'), 403,
                'Forcer une affectation exige groupes.manage.');
        }

        $contraintes = $this->controler($etudiant, $groupe, $annee, $force, $sousGroupeId, $contexte);
        $bloquantes = array_values(array_filter($contraintes, fn (array $c) => $c['bloquant']));

        if ($bloquantes !== []) {
            throw new \DomainException($this->expliquer($groupe, $bloquantes));
        }

        return DB::transaction(function () use ($etudiant, $groupe, $annee, $auteurId, $motif, $force, $sousGroupeId, $notifier) {
            // Verrou anti-concurrence (LOOP 14 « exécutions concurrentes
            // sans double affectation ») : l'ÉTUDIANT est la ressource
            // sérialisante. Un SELECT FOR UPDATE sur la ligne d'affectation
            // ABSENTE ne sérialise rien (gap lock InnoDB = double insert
            // possible en prod multi-worker) ; le verrou de ligne étudiant,
            // lui, force le second worker à attendre le commit du premier,
            // qui voit alors l'affectation créée (idempotence/transfert).
            // Ordre des verrous : étudiant PUIS groupe (partout identique,
            // sinon deadlock croisé).
            $etudiantVerrouille = Etudiant::whereKey($etudiant->id)->lockForUpdate()->firstOrFail();

            // La capacité est revérifiée au moment de l'écriture
            // (29/30 + 2 admins ≠ 31/30 sans exception).
            $groupeVerrouille = Group::whereKey($groupe->id)->lockForUpdate()->firstOrFail();

            if ($groupeVerrouille->estComplet() && ! $force) {
                throw new \DomainException("Groupe {$groupeVerrouille->code} devenu complet pendant la validation ({$groupeVerrouille->effectifActuel()}/{$groupeVerrouille->capacite}). Choisissez un autre groupe ou demandez une exception.");
            }

            $ancienne = AffectationGroupe::where('etudiant_id', $etudiantVerrouille->id)
                ->where('annee_universitaire_id', $annee->id)
                ->where('statut', 'active')
                ->lockForUpdate()
                ->first();

            // Refonte §26 : une affectation verrouillée ne peut être
            // déplacée que par exception explicite (force + groupes.manage).
            if ($ancienne !== null && $ancienne->verrouille && ! $force) {
                throw new \DomainException("Affectation verrouillée ({$ancienne->groupe?->code}) : modification administrative explicite requise (exception groupes.manage).");
            }

            // Idempotence : même groupe + même année = aucune opération.
            if ($ancienne !== null && (int) $ancienne->groupe_id === (int) $groupe->id) {
                return $ancienne;
            }

            if ($ancienne !== null) {
                $ancienne->update(['statut' => 'cloturee', 'date_fin' => now()->toDateString()]);
            }

            $affectation = AffectationGroupe::create([
                'etudiant_id' => $etudiant->id,
                'groupe_id' => $groupe->id,
                'sous_groupe_id' => $sousGroupeId,
                'annee_universitaire_id' => $annee->id,
                'date_debut' => now()->toDateString(),
                'statut' => 'active',
                'motif' => ($force ? '[FORCÉ] ' : '').$motif,
                'auteur_id' => $auteurId,
            ]);

            $etudiant->update(['groupe_id' => $groupe->id]);

            SituationEtudiant::create([
                'etudiant_id' => $etudiant->id,
                'type' => 'groupe',
                'ancienne_valeur' => $ancienne?->groupe?->code,
                'nouvelle_valeur' => $groupe->code,
                'motif' => $motif,
                'auteur_id' => $auteurId,
                'date_effet' => now()->toDateString(),
            ]);

            PopulationChanged::dispatch('affectations', $ancienne ? 'transfert' : 'creation',
                $affectation->id, $auteurId, ['etudiant_id' => $etudiant->id, 'groupe' => $groupe->code]);

            if ($notifier) {
                $this->notifications->notifierEtudiant(
                    $etudiant,
                    'affectation.groupe',
                    'Affectation de groupe',
                    $ancienne !== null
                        ? "Votre groupe est modifié : {$ancienne->groupe?->code} → {$groupe->code} (effet immédiat)."
                        : "Vous êtes affecté au groupe {$groupe->code}.",
                    Route::has('etudiant.emploi-du-temps.semaine')
                        ? route('etudiant.emploi-du-temps.semaine')
                        : route('etudiant.dashboard'),
                );
            }

            return $affectation;
        });
    }

    /**
     * Simulation massive SANS écrire : proposées / bloquées / warnings.
     *
     * @param  array<int>  $etudiantIds
     * @return array{proposees: array<int>, bloquees: array<int, array<string>>, warnings: array<int, array<string>>}
     */
    public function simuler(array $etudiantIds, Group $groupe, AnneeUniversitaire $annee, bool $force = false): array
    {
        $resultat = ['proposees' => [], 'bloquees' => [], 'warnings' => []];

        $idsDemandes = array_slice($etudiantIds, 0, 5000);
        $contexte = $this->contexteBulk($idsDemandes, [$groupe->id], $annee);

        foreach (Etudiant::whereIn('id', $idsDemandes)->cursor() as $etudiant) {
            $contraintes = $this->controler($etudiant, $groupe, $annee, $force, null, $contexte);
            $bloquantes = array_values(array_filter($contraintes, fn (array $c) => $c['bloquant']));
            $alertes = array_values(array_filter($contraintes, fn (array $c) => ! $c['bloquant'] && $c['code'] !== 'GROUP_ASSIGNMENT_EXISTS' && $c['code'] !== 'MULTIPLE_PRIMARY_GROUPS'));

            if ($bloquantes !== []) {
                $resultat['bloquees'][$etudiant->id] = array_column($bloquantes, 'message');
            } else {
                $resultat['proposees'][] = $etudiant->id;
            }

            if ($alertes !== []) {
                $resultat['warnings'][$etudiant->id] = array_column($alertes, 'message');
            }
        }

        return $resultat;
    }

    /**
     * Application massive : simulation + transaction + notification unique.
     *
     * @param  array<int>  $etudiantIds
     * @return array{appliquees: int, ignorees: int, erreurs: array<int, string>}
     */
    public function affecterLot(
        array $etudiantIds,
        Group $groupe,
        AnneeUniversitaire $annee,
        int $auteurId,
        string $motif,
        bool $force = false,
    ): array {
        $simulation = $this->simuler($etudiantIds, $groupe, $annee, $force);
        $appliquees = 0;
        $ignorees = 0;
        $erreurs = [];

        // Contexte préchargé (zéro re-lecture par item) + notification
        // unique en lot après (pas de doublon par étudiant).
        $contexte = $this->contexteBulk($simulation['proposees'], [$groupe->id], $annee);

        DB::transaction(function () use ($simulation, $groupe, $annee, $auteurId, $motif, $force, $contexte, &$appliquees, &$ignorees, &$erreurs) {
            foreach ($simulation['proposees'] as $etudiantId) {
                try {
                    $this->affecter(
                        Etudiant::findOrFail($etudiantId), $groupe, $annee, $auteurId, $motif, $force, null, $contexte, false
                    );
                    $appliquees++;
                } catch (\DomainException $e) {
                    // Course perdue entre simulation et écriture (capacité…).
                    $ignorees++;
                    $erreurs[$etudiantId] = $e->getMessage();
                }
            }
        });

        // Notifications en lot unique (insert par 500, jamais N+1) :
        // seuls les IDs sont chargés, le contenu est identique pour tous.
        $affectesIds = Etudiant::whereIn('id', $simulation['proposees'])
            ->limit(5000)
            ->pluck('id')
            ->reject(fn (int $id) => isset($erreurs[$id]))
            ->values();

        if ($affectesIds->isNotEmpty()) {
            $this->notifications->notifierPlusieursEtudiants(
                $affectesIds,
                'affectation.groupe',
                'Affectation de groupe',
                "Vous êtes affecté au groupe {$groupe->code}.",
                route('etudiant.emploi-du-temps.semaine'),
            );
        }

        return ['appliquees' => $appliquees, 'ignorees' => $ignorees + count($simulation['bloquees']), 'erreurs' => $erreurs];
    }

    /**
     * Retrait historisé (jamais de suppression) : clôture + pointeur
     * réinitialisé si courant + audit. Notes/examens conservés.
     */
    public function retirer(AffectationGroupe $affectation, int $auteurId, string $motif): AffectationGroupe
    {
        return DB::transaction(function () use ($affectation, $auteurId, $motif) {
            // Verrou étudiant (LOOP 14) : même ressource sérialisante que
            // `affecter` — un retrait et une affectation concurrents ne
            // peuvent pas se croiser sur le même étudiant (ordre
            // étudiant → affectation, cohérent avec affecter).
            $etudiantVerrouille = Etudiant::whereKey($affectation->etudiant_id)
                ->lockForUpdate()
                ->firstOrFail();

            $affectation->refresh();

            if ($affectation->statut !== 'active') {
                throw new \DomainException('Affectation déjà clôturée.');
            }

            // Refonte §26 : retrait d'une affectation verrouillée =
            // action explicite tracée (motif obligatoire conservé).
            if ($affectation->verrouille) {
                $affectation->update(['verrouille' => false]);
            }

            $affectation->update(['statut' => 'cloturee', 'date_fin' => now()->toDateString()]);

            $etudiant = $etudiantVerrouille;

            if ((int) $etudiant->groupe_id === (int) $affectation->groupe_id) {
                $etudiant->update(['groupe_id' => null]);
            }

            SituationEtudiant::create([
                'etudiant_id' => $etudiant->id,
                'type' => 'groupe',
                'ancienne_valeur' => $affectation->groupe?->code,
                'nouvelle_valeur' => null,
                'motif' => 'Retrait : '.$motif,
                'auteur_id' => $auteurId,
                'date_effet' => now()->toDateString(),
            ]);

            PopulationChanged::dispatch('affectations', 'retrait', $affectation->id, $auteurId, ['etudiant_id' => $etudiant->id]);

            return $affectation->fresh();
        });
    }

    /**
     * Impacts d'un changement avant confirmation : planning du groupe
     * cible, épreuves à venir, données conservées (notes).
     *
     * @return array{planning_creneaux: int, epreuves_a_venir: int, notes_conservees: int}
     */
    public function impacts(Etudiant $etudiant, Group $nouveau): array
    {
        $planning = Schedule::where('groupe_id', $nouveau->id)
            ->where('actif', true)->first();

        $creneaux = $planning !== null
            ? $planning->slots()->where('status', '!=', 'cancelled')->count()
            : 0;

        $epreuves = 0;
        if (class_exists(Candidat::class)) {
            $epreuves = Candidat::where('etudiant_id', $etudiant->id)
                ->whereHas('epreuve', fn ($q) => $q->whereDate('date', '>=', now()->format('Y-m-d')))
                ->count();
        }

        $notes = Note::where('etudiant_id', $etudiant->id)->count();

        return ['planning_creneaux' => $creneaux, 'epreuves_a_venir' => $epreuves, 'notes_conservees' => $notes];
    }

    /**
     * Répartition automatique ÉQUILIBRÉE — SIMULATION SANS ÉCRITURE (§14).
     * Jamais d'écriture ici : le contrôleur doit confirmer explicitement
     * via affecterLot() par groupe. Équilibrage par places disponibles.
     *
     * @param  array<int>  $etudiantIds
     * @param  array<int>  $groupeIds
     * @return array{repartition: array<int, array<int>>, non_affectes: array<int, array<string>>, capacites: array<int, array{places: int, effectif: int, capacite: int|null}>}
     */
    public function repartitionAutomatique(array $etudiantIds, array $groupeIds, AnneeUniversitaire $annee, bool $force = false): array
    {
        $groupes = Group::whereIn('id', $groupeIds)->get()->keyBy('id');
        $repartition = [];
        $nonAffectes = [];
        $capacites = [];

        foreach ($groupes as $id => $groupe) {
            $repartition[$id] = [];
            $effectif = $groupe->effectifActuel();
            $capacites[$id] = [
                'places' => $groupe->capacite !== null ? max(0, (int) $groupe->capacite - $effectif) : PHP_INT_MAX,
                'effectif' => $effectif,
                'capacite' => $groupe->capacite,
            ];
        }

        // Bulk : 4 requêtes de préchargement, zéro N+1 (§39).
        $idsDemandes = array_slice($etudiantIds, 0, 5000);
        $contexte = $this->contexteBulk($idsDemandes, array_keys($groupes->all()), $annee);
        // Compteurs simulés : un groupe illimité (places infinies) ne
        // doit pas absorber toute la répartition (équilibrage réel).
        $compteurs = array_fill_keys(array_keys($groupes->all()), 0);

        foreach (Etudiant::whereIn('id', $idsDemandes)->cursor() as $etudiant) {
            // Candidats triés : moins remplis d'abord (simulé),
            // puis places restantes, puis id (déterministe).
            // NOTE : une seule closure retournant un tableau (comparaison
            // lexicographique) — sortBy([closures]) appellerait chaque
            // closure comme comparateur ($a, $b), ce qui est faux ici.
            $candidats = collect($groupes)->sortBy(
                fn (Group $g) => [$compteurs[$g->id], -min($capacites[$g->id]['places'], 1 << 30), $g->id]
            )->values();
            $affecte = false;

            foreach ($candidats as $groupe) {
                if (! $force && $capacites[$groupe->id]['places'] <= 0) {
                    continue;
                }

                // GROUP_FULL géré via places simulées (pas le contrôleur,
                // figé sur l'effectif réel) ; autres bloquantes conservées.
                $bloquantes = array_values(array_filter(
                    $this->controler($etudiant, $groupe, $annee, $force, null, $contexte),
                    fn (array $c) => $c['bloquant'] && $c['code'] !== 'GROUP_FULL'
                ));

                if ($bloquantes === []) {
                    $repartition[$groupe->id][] = $etudiant->id;
                    $capacites[$groupe->id]['places'] = max(0, $capacites[$groupe->id]['places'] - 1);
                    $capacites[$groupe->id]['effectif']++;
                    $compteurs[$groupe->id]++;
                    $contexte['effectifs'][$groupe->id] = ($contexte['effectifs'][$groupe->id] ?? 0) + 1;
                    $affecte = true;
                    break;
                }
            }

            if (! $affecte) {
                // Premier groupe pour le motif (diagnostic), sans écrire.
                $premier = $groupes->first();
                $motifs = $premier !== null
                    ? array_column(array_filter(
                        $this->controler($etudiant, $premier, $annee, $force, null, $contexte),
                        fn (array $c) => $c['bloquant']
                    ), 'message')
                    : ['Aucun groupe candidat.'];
                $nonAffectes[$etudiant->id] = $motifs === [] ? ['Capacité épuisée sur tous les groupes.'] : $motifs;
            }
        }

        return ['repartition' => $repartition, 'non_affectes' => $nonAffectes, 'capacites' => $capacites];
    }

    /**
     * Précharge le contexte bulk (4 requêtes, quelle que soit la taille) :
     * inscriptions, suspensions, affectations actives, effectifs.
     *
     * @param  array<int>  $etudiantIds
     * @param  array<int>  $groupeIds
     * @return array{inscriptions: array<int, string>, suspendus: array<int, true>, existantes: array<int, array{groupe_id: int, code: string}>, effectifs: array<int, int>}
     */
    public function contexteBulk(array $etudiantIds, array $groupeIds, AnneeUniversitaire $annee): array
    {
        if ($etudiantIds === [] || $groupeIds === []) {
            return ['inscriptions' => [], 'suspendus' => [], 'existantes' => [], 'effectifs' => []];
        }

        $inscriptions = InscriptionAcademique::where('annee_universitaire_id', $annee->id)
            ->whereIn('etudiant_id', $etudiantIds)
            ->pluck('statut', 'etudiant_id')->all();

        $suspendus = SituationEtudiant::whereIn('etudiant_id', $etudiantIds)
            ->where('type', 'suspension')
            ->whereDate('date_effet', '<=', now()->toDateString())
            ->where(fn ($q) => $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', now()->toDateString()))
            ->pluck('etudiant_id')->all();

        $existantes = AffectationGroupe::where('affectations_groupe.annee_universitaire_id', $annee->id)
            ->whereIn('affectations_groupe.etudiant_id', $etudiantIds)
            ->where('affectations_groupe.statut', 'active')
            ->join('groups', 'groups.id', '=', 'affectations_groupe.groupe_id')
            ->select('affectations_groupe.etudiant_id', 'affectations_groupe.groupe_id', 'groups.code')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->etudiant_id => ['groupe_id' => (int) $row->groupe_id, 'code' => (string) $row->code]])
            ->all();

        $effectifs = AffectationGroupe::where('annee_universitaire_id', $annee->id)
            ->whereIn('groupe_id', $groupeIds)
            ->where('statut', 'active')
            ->selectRaw('groupe_id, COUNT(*) n')
            ->groupBy('groupe_id')
            ->pluck('n', 'groupe_id')->all();

        return [
            'inscriptions' => $inscriptions,
            'suspendus' => array_fill_keys($suspendus, true),
            'existantes' => $existantes,
            'effectifs' => $effectifs,
        ];
    }

    /**
     * Pré-check de simulation (§22, lecture seule) : effectifs par
     * filtre avant de lancer l'algorithme. Si zéro potentiellement
     * éligible, le contrôleur ne lance pas la simulation.
     *
     * @param  array<int>  $etudiantIds
     * @param  array<int>  $groupeIds
     * @return array{selectionnes: int, inscrits: int, sans_inscription: int, prog_compatibles: int, niveau_compatibles: int, deja_affectes: int, verrouilles: int, suspendus: int, eligibles: int, groupes_compatibles: int, capacite_totale: int, capacite_disponible: int, besoin: int, manque: int, groupes_supplementaires: int, capacite_recommandee: int, code_groupe_suggere: ?string}
     */
    public function precheck(array $etudiantIds, array $groupeIds, AnneeUniversitaire $annee): array
    {
        $ids = array_values(array_unique(array_map('intval', $etudiantIds)));
        $groupes = Group::whereIn('id', $groupeIds)->get();
        $etudiants = Etudiant::whereIn('id', $ids)->get()->keyBy('id');
        $ctx = $this->contexteBulk($ids, $groupes->map(fn (Group $g) => $g->id)->all(), $annee);

        $verrouilles = $ids === [] ? 0 : AffectationGroupe::whereIn('etudiant_id', $ids)
            ->where('annee_universitaire_id', $annee->id)
            ->where('statut', 'active')
            ->where('verrouille', true)
            ->count();

        $stats = [
            'selectionnes' => count($ids), 'inscrits' => 0, 'sans_inscription' => 0,
            'prog_compatibles' => 0, 'niveau_compatibles' => 0,
            'deja_affectes' => count($ctx['existantes']), 'verrouilles' => $verrouilles,
            'suspendus' => count($ctx['suspendus']), 'eligibles' => 0,
            'groupes_compatibles' => 0, 'capacite_totale' => 0, 'capacite_disponible' => 0,
            'besoin' => 0, 'manque' => 0, 'groupes_supplementaires' => 0,
            'capacite_recommandee' => 0, 'code_groupe_suggere' => null,
        ];

        foreach ($etudiants as $etudiant) {
            $inscrit = in_array($ctx['inscriptions'][$etudiant->id] ?? null, ['en_cours', 'valide'], true);
            $inscrit ? $stats['inscrits']++ : $stats['sans_inscription']++;

            $progOk = false;
            $niveauOk = false;

            foreach ($groupes as $groupe) {
                if (! ($groupe->programme_id !== null && $etudiant->programme_id !== null
                    && (int) $groupe->programme_id !== (int) $etudiant->programme_id)) {
                    $progOk = true;
                }

                if (! ($groupe->niveau !== null && $etudiant->niveau !== null && $groupe->niveau !== $etudiant->niveau)) {
                    $niveauOk = true;
                }
            }

            if ($progOk) {
                $stats['prog_compatibles']++;
            }

            if ($niveauOk) {
                $stats['niveau_compatibles']++;
            }

            if ($inscrit && $progOk && $niveauOk
                && ! isset($ctx['existantes'][$etudiant->id])
                && ! isset($ctx['suspendus'][$etudiant->id])) {
                $stats['eligibles']++;
            }
        }

        // Capacité vs besoin (§18) : mêmes formules que le planificateur.
        $places = [];

        foreach ($groupes as $groupe) {
            $places[$groupe->id] = $groupe->capacite !== null
                ? max(0, (int) $groupe->capacite - ($ctx['effectifs'][$groupe->id] ?? 0))
                : PHP_INT_MAX;
        }

        $plan = app(AssignmentSimulationService::class)
            ->planCapacite($groupes, max(0, $stats['eligibles'] - array_sum(array_map(
                fn ($p) => $p === PHP_INT_MAX ? 0 : $p, $places
            ))));

        $stats['groupes_compatibles'] = $groupes->where('actif', true)->count();
        $stats['capacite_totale'] = (int) $groupes->sum(fn (Group $g) => $g->capacite ?? 0);
        $stats['capacite_disponible'] = array_sum(array_map(
            fn ($p) => $p === PHP_INT_MAX ? 0 : $p, $places
        ));
        $stats['besoin'] = $stats['eligibles'];
        $stats['manque'] = $plan['manque'];
        $stats['groupes_supplementaires'] = $plan['groupes_supplementaires'];
        $stats['capacite_recommandee'] = $plan['capacite_recommandee'];
        $stats['code_groupe_suggere'] = $plan['code_groupe_suggere'];

        return $stats;
    }

    /** @param array<int, array{code: string, gravite: string, message: string, bloquant: bool}> $bloquantes */
    private function expliquer(Group $groupe, array $bloquantes): string
    {
        $motifs = array_map(fn (array $c) => "[{$c['code']}] {$c['message']}", $bloquantes);

        return 'Affectation refusée — groupe '.$groupe->code.' : '.implode(' ', $motifs)
            .' Action possible : choisir un autre groupe ou demander une exception à un utilisateur disposant de la permission groupes.manage.';
    }

    /** @return array{code: string, gravite: string, message: string, bloquant: bool} */
    private function erreur(string $code, string $message): array
    {
        return ['code' => $code, 'gravite' => 'erreur', 'message' => $message, 'bloquant' => true];
    }
}
