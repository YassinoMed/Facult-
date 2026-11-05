<?php

namespace App\Services\Acquis;

use App\Models\AnneeUniversitaire;
use App\Models\CampagneRedoublement;
use App\Models\Etudiant;
use App\Models\HistoriqueAcademique;
use App\Models\User;
use App\Modules\ParcoursAcademique\Models\ParcoursPlan;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TRAITEMENT DE MASSE DES REDOUBLANTS ET DES COHORTES (LOOP 27 & LOOP 36).
 *
 * Orchestration résiliente par tranches (chunks), sans transaction globale longue.
 * Classification automatique : Prêts, Avec réserves, Bloqués prérequis, 4 yeux requis.
 * Durcissement :
 * - Transactions atomiques unitaires par dossier.
 * - Idempotence et reprise fluide après interruption (skip des dossiers déjà validés).
 * - Verrouillage atomique prévenant les exécutions concurrentes sur un même étudiant.
 * - Zéro donnée personnelle (PII/CIN) dans les logs techniques de supervision.
 */
class CampagneRedoublementMasseService
{
    public function __construct(
        private readonly MoteurDecisionAcademiqueService $moteurDecision,
        private readonly PlanRedoublementService $planService,
        private readonly ReinscriptionPedagogiqueRedoublantService $reinscriptionService,
    ) {}

    /**
     * Exécute une simulation globale préparatoire pour une cohorte (Étape B & C).
     *
     * @param array{
     *     programme_id?: int|null,
     *     annee_id?: int|null,
     *     faculte_id?: int|null,
     *     groupe_id?: int|null,
     *     etudiant_ids?: array<int>|null
     * } $criteres
     * @return array<string, mixed>
     */
    public function preparerCampagne(array $criteres, ?string $campagneId = null): array
    {
        $campagneId ??= 'CAMP-'.date('Ymd-His').'-'.bin2hex(random_bytes(3));
        $query = Etudiant::query()->with([
            'inscriptionsAcademiques.programmeRef',
            'inscriptionsAcademiques.anneeUniversitaire',
            'decisionsDeliberation',
            'parcoursPlans',
            'parcoursEquivalences',
        ]);

        if (! empty($criteres['etudiant_ids'])) {
            $query->whereIn('id', $criteres['etudiant_ids']);
        }

        if (! empty($criteres['programme_id'])) {
            $query->whereHas('inscriptionsAcademiques', fn ($q) => $q->where('programme_id', (int) $criteres['programme_id']));
        }

        if (! empty($criteres['faculte_id'])) {
            $query->where('faculte_id', (int) $criteres['faculte_id']);
        }

        // A1 : le groupe est un critère réel de cohorte (jamais ignoré).
        // Un groupe appartient à une seule faculté : le filtre faculté ci-dessus
        // reste donc cohérent avec cette restriction.
        if (! empty($criteres['groupe_id'])) {
            $query->where('groupe_id', (int) $criteres['groupe_id']);
        }

        if (! empty($criteres['annee_id'])) {
            $query->whereHas('inscriptionsAcademiques', fn ($q) => $q->where('annee_universitaire_id', (int) $criteres['annee_id']));
        }

        $etudiants = $query->get();

        $dossiersPrets = 0;
        $dossiersAvecReserves = 0;
        $dossiersBloques = 0;
        $dossiers4Yeux = 0;
        $details = [];

        foreach ($etudiants as $etudiant) {
            $analyse = $this->moteurDecision->determinerDecision($etudiant);
            $planExistant = $etudiant->parcoursPlans->sortByDesc('id')->first();
            $equivEnAttente = $etudiant->parcoursEquivalences->where('statut', 'en_attente')->count() > 0;

            // Classification rigoureuse
            if ($planExistant && $planExistant->statut === 'bloque') {
                $classification = 'BLOQUE_PREREQUIS';
                $dossiersBloques++;
            } elseif ($planExistant && $planExistant->statut === 'en_validation' || $equivEnAttente) {
                $classification = 'VALIDATION_4_YEUX_REQUISE';
                $dossiers4Yeux++;
            } elseif (! empty($analyse['anomalies'])) {
                $classification = 'AVEC_RESERVES';
                $dossiersAvecReserves++;
            } else {
                $classification = 'PRET';
                $dossiersPrets++;
            }

            $details[] = [
                'etudiant_id' => $etudiant->id,
                'nom_complet' => "{$etudiant->nom} {$etudiant->prenom}",
                'cin' => $etudiant->cin,
                'classification' => $classification,
                'credits_acquis' => $analyse['credits']['capitalises'] ?? 0,
                'elements_a_reprendre_count' => count($analyse['elements_a_reprendre'] ?? []),
                'deliberation_statut' => $analyse['decision_definitive'] ? 'PUBLIEE' : 'EN_ATTENTE',
            ];
        }

        return [
            'campagne_id' => $campagneId,
            'annee_id' => $criteres['annee_id'] ?? null,
            'total_etudiants' => $etudiants->count(),
            'total_cohorte' => $etudiants->count(),
            'dossiers_prets' => $dossiersPrets,
            'dossiers_avec_reserves' => $dossiersAvecReserves,
            'dossiers_bloques_prerequis' => $dossiersBloques,
            'dossiers_4_yeux_requis' => $dossiers4Yeux,
            'qualification' => [
                'prets' => $dossiersPrets,
                'avec_reserves' => $dossiersAvecReserves,
                'bloques' => $dossiersBloques,
                'quatre_yeux' => $dossiers4Yeux,
            ],
            'chiffres_clefs' => [
                'total' => $etudiants->count(),
                'valides' => $dossiersPrets,
            ],
            'details_etudiants' => $details,
        ];
    }

    /**
     * Persiste une campagne avant préparation ou exécution (constat A3).
     *
     * Seuls les critères de cohorte sont enregistrés : aucune donnée
     * nominative (nom, CIN) ne quitte les tables métier.
     *
     * @param  array{programme_id?: int|null, annee_id?: int|null, faculte_id?: int|null, groupe_id?: int|null}  $criteres
     */
    public function creerCampagne(
        array $criteres,
        string $mode,
        ?int $acteurId = null,
        bool $ignorerAlertesMineures = false,
    ): CampagneRedoublement {
        $attributs = [
            'statut' => $mode === CampagneRedoublement::MODE_EXECUTION
                ? CampagneRedoublement::STATUT_EN_FILE
                : CampagneRedoublement::STATUT_PREPAREE,
            'mode' => $mode,
            'annee_universitaire_id' => $criteres['annee_id'] ?? null,
            'programme_id' => $criteres['programme_id'] ?? null,
            'groupe_id' => $criteres['groupe_id'] ?? null,
            'faculte_id' => $criteres['faculte_id'] ?? null,
            'ignorer_alertes_mineures' => $ignorerAlertesMineures,
            'lancee_par' => $acteurId,
        ];

        // Référence stable et lisible : CAMP-AAAAMMJJ-HHMMSS-xxxxxx.
        for ($tentative = 0; $tentative < 3; $tentative++) {
            try {
                return CampagneRedoublement::create($attributs + [
                    'reference' => 'CAMP-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)),
                ]);
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        return CampagneRedoublement::create($attributs + [
            'reference' => 'CAMP-'.date('Ymd-His').'-'.bin2hex(random_bytes(6)),
        ]);
    }

    /**
     * Exécute une campagne globale préparée avec reprise après interruption.
     *
     * @param  array<string, mixed>  $preparation
     * @return array<string, mixed>
     */
    public function executerCampagne(array $preparation, int $acteurId, bool $ignorerAlertes = false): array
    {
        $etudiantIds = collect($preparation['details_etudiants'] ?? [])->pluck('etudiant_id')->all();
        $nouvelleAnneeId = (int) ($preparation['annee_id'] ?? AnneeUniversitaire::latest('id')->value('id') ?? 1);

        $bilan = $this->executerCampagneParLots($etudiantIds, $nouvelleAnneeId, $acteurId);

        return [
            'campagne_id' => $preparation['campagne_id'] ?? 'CAMP-'.uniqid(),
            'etudiants_traites' => $bilan['traites'],
            'etudiants_succes' => $bilan['succes'],
            'etudiants_deja_traites' => $bilan['deja_traites'] ?? 0,
            'etudiants_erreurs' => $bilan['echecs'],
            'duree_ms' => $bilan['duree_ms'] ?? 0.0,
            'debit_etudiants_s' => $bilan['debit_etudiants_s'] ?? 0.0,
            'memoire_pic_mo' => $bilan['memoire_pic_mo'] ?? 0.0,
            'moteur_bd' => $bilan['moteur_bd'] ?? 'sqlite',
            'taille_lot' => $bilan['taille_lot'] ?? 50,
            'statut_final' => $bilan['statut_final'] ?? 'SUCCES_TOTAL',
            'version_code' => $bilan['version_code'] ?? 'UniRegistre v2.4-LOOP38',
            'details' => $bilan['details_erreurs'],
        ];
    }

    /**
     * Exécute la campagne par tranches (chunks) avec verrous atomiques et transactions isolées.
     *
     * @param  array<int>  $etudiantIds
     * @return array{
     *     traites: int,
     *     succes: int,
     *     deja_traites: int,
     *     echecs: int,
     *     details_erreurs: array<int, string>
     * }
     */
    public function executerCampagneParLots(
        array $etudiantIds,
        int $nouvelleAnneeId,
        int $acteurId,
        int $chunkSize = 50
    ): array {
        $t0 = microtime(true);
        $traites = 0;
        $succes = 0;
        $dejaTraites = 0;
        $echecs = 0;
        $detailsErreurs = [];

        // Découpage en tranches indépendantes (chunking)
        $morceaux = array_chunk($etudiantIds, $chunkSize);

        foreach ($morceaux as $lotIds) {
            $etudiants = Etudiant::whereIn('id', $lotIds)->get();

            foreach ($etudiants as $etudiant) {
                $traites++;

                // 1. Verrouillage atomique par étudiant pour empêcher toute concurrence simultanée
                $verrou = Cache::lock("verrou_redoublement_etudiant_{$etudiant->id}", 15);

                if (! $verrou->get()) {
                    $echecs++;
                    $detailsErreurs[] = "Étudiant ID {$etudiant->id} : Dossier verrouillé par un autre processus en cours.";

                    continue;
                }

                try {
                    // 2. Reprise après interruption : vérifier si le plan est déjà validé
                    $planExistantValide = ParcoursPlan::where('etudiant_id', $etudiant->id)
                        ->where('statut', 'valide')
                        ->first();

                    if ($planExistantValide !== null) {
                        $dejaTraites++;
                        $succes++;

                        continue;
                    }

                    // 3. Transaction unitaire isolée par étudiant
                    DB::beginTransaction();

                    $plan = $this->planService->confirmerPlan($etudiant, $nouvelleAnneeId, $acteurId);

                    // Consignation d'audit (sans PII)
                    $userValide = $acteurId > 0 && User::where('id', $acteurId)->exists();
                    HistoriqueAcademique::create([
                        'etudiant_id' => $etudiant->id,
                        'utilisateur_id' => $userValide ? $acteurId : null,
                        'action' => 'campagne_redoublement_masse_etudiant',
                        'entite' => 'parcours_plans',
                        'entite_id' => $plan->id,
                        'ancienne_valeur' => null,
                        'nouvelle_valeur' => json_encode([
                            'annee_cible_id' => $nouvelleAnneeId,
                            'statut_plan' => $plan->statut,
                            'credits_capitalises' => $plan->contenu['credits_acquis_conserves'] ?? 0,
                            'credits_restants' => $plan->contenu['credits_restants'] ?? 0,
                        ], JSON_UNESCAPED_UNICODE),
                        'source_systeme' => 'campagne_redoublement_masse_loop27',
                    ]);

                    DB::commit();
                    $succes++;
                } catch (\Throwable $e) {
                    DB::rollBack();
                    $echecs++;
                    // Journalisation strictement sans PII (uniquement l'ID interne et la cause technique)
                    $detailsErreurs[] = "Étudiant ID {$etudiant->id} : {$e->getMessage()}";
                    Log::warning('Échec de traitement individuel lors d\'une campagne de redoublement.', [
                        'etudiant_id' => $etudiant->id,
                        'erreur_type' => get_class($e),
                        'erreur_message' => $e->getMessage(),
                    ]);
                } finally {
                    $verrou->release();
                }
            }
        }

        $dureeMs = round((microtime(true) - $t0) * 1000, 2);
        $memoirePicMo = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
        $debitEtudiantsS = ($dureeMs > 0) ? round(($traites / ($dureeMs / 1000)), 2) : 0.0;
        $moteurBd = DB::connection()->getDriverName();
        $statutFinal = ($echecs === 0) ? 'SUCCES_TOTAL' : (($succes > 0) ? 'PARTIEL_AVEC_ERREURS' : 'ECHEC_TOTAL');

        return [
            'traites' => $traites,
            'succes' => $succes,
            'deja_traites' => $dejaTraites,
            'echecs' => $echecs,
            'details_erreurs' => $detailsErreurs,
            'duree_ms' => $dureeMs,
            'debit_etudiants_s' => $debitEtudiantsS,
            'memoire_pic_mo' => $memoirePicMo,
            'moteur_bd' => $moteurBd,
            'taille_lot' => $chunkSize,
            'statut_final' => $statutFinal,
            'version_code' => 'UniRegistre v2.4-LOOP38',
        ];
    }
}
