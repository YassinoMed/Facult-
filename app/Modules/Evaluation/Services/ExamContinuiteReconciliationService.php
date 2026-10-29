<?php

namespace App\Modules\Evaluation\Services;

use App\Models\IntegrationLog;
use App\Models\User;
use App\Modules\Evaluation\Events\EvaluationChanged;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\EmargementHorsLigne;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\EvaluationReconciliationEcart;
use DomainException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Service de continuité après panne, réconciliation tri-sources et mode dégradé (LOT H - §2.15, §2.16).
 *
 * Règles métier absolues :
 * 1. Rapprochement des 3 sources : papier autorisé, données numériques, journaux horodatés.
 * 2. Un écart inexpliqué BLOQUE la validation définitive des présences concernées.
 * 3. Régularisation 4 yeux (vérification par responsable + validation par personne distincte).
 * 4. Reprise idempotente accompagnée d'un rapport exhaustif (acceptées, rejetées, à réviser).
 * 5. Le retour à la normale n'efface JAMAIS les opérations réalisées en mode dégradé.
 */
class ExamContinuiteReconciliationService
{
    /**
     * Rapprochement tri-sources pour une épreuve.
     *
     * Les trois sources sont distinguées et jamais fusionnées :
     *  - papier (saisie de l'émargement),
     *  - numérique (statut du candidat en base),
     *  - journal horodaté (trace d'intégration).
     *
     * Une DIVERGENCE (statuts incompatibles) bloque la validation. Une
     * RÉSERVE (source muette : pas de signature, pas de trace journal) ne
     * bloque pas mais interdit de parler de réconciliation « complète ».
     *
     * @param array{
     *     epreuve_id: int,
     *     pointages_papier: array<int, array{candidat_id: int, statut_papier: string, signature_presente: bool}>
     * } $donneesRapprochement
     * @return array{
     *     epreuve_id: int,
     *     total_candidats: int,
     *     concordances: int,
     *     divergences_detectees: int,
     *     validation_bloquee: bool,
     *     ecarts: array<int, array{
     *         candidat_id: int,
     *         statut_papier: string,
     *         statut_numerique: string,
     *         journal_horodate: ?string,
     *         nature_divergence: string
     *     }>,
     *     reserves: array<int, array{candidat_id: int, type: string, detail: string}>,
     *     reserves_detectees: int,
     *     reconciliation_complete: bool,
     *     sources_comparees: array<int, string>
     * }
     */
    public function rapprocherTriSources(array $donneesRapprochement): array
    {
        $epreuve = Epreuve::findOrFail($donneesRapprochement['epreuve_id']);
        $candidats = Candidat::where('epreuve_id', $epreuve->id)->get()->keyBy('id');

        // Journal strictement borné aux candidats DE CETTE épreuve. Une
        // recherche large (`orWhere operation like %attendance%`) attribuait
        // à un candidat la trace d'un autre : « CAND-1 » est contenu dans
        // « CAND-11 ». La convention d'écriture est exactement
        // « CAND-{id} » (voir regulariserEcartTriSources et
        // ExamPresenceRegularisationService).
        $references = $candidats->keys()
            ->map(fn (int $id): string => 'CAND-'.$id)
            ->values()
            ->all();

        $logs = $references === []
            ? collect()
            : IntegrationLog::query()
                ->whereIn('external_reference', $references)
                ->latest('id')
                ->get()
                ->keyBy('external_reference');

        $pointagesPapier = collect($donneesRapprochement['pointages_papier'] ?? [])->keyBy('candidat_id');

        $concordances = 0;
        $ecarts = [];
        $reserves = [];

        foreach ($candidats as $candidatId => $candidat) {
            $papier = $pointagesPapier->get($candidatId);
            $statutPapier = $papier ? (string) $papier['statut_papier'] : 'non_consigne_papier';
            $statutNum = (string) $candidat->statut;

            $dernierLog = $logs->get('CAND-'.$candidatId);
            $logDate = $dernierLog?->created_at?->toIso8601String();

            if ($statutPapier === $statutNum) {
                $concordances++;
            } else {
                $nature = "Divergence constatée : Feuille papier [{$statutPapier}] vs Base numérique [{$statutNum}]";
                $ecarts[] = [
                    'candidat_id' => $candidatId,
                    'statut_papier' => $statutPapier,
                    'statut_numerique' => $statutNum,
                    'journal_horodate' => $logDate,
                    'nature_divergence' => $nature,
                ];
            }

            // Réserves : sources présentes mais incomplètes. Elles ne
            // bloquent pas la validation, elles empêchent de conclure à une
            // réconciliation complète.
            if ($statutPapier === 'present' && $papier !== null
                && ($papier['signature_presente'] ?? true) === false) {
                $reserves[] = [
                    'candidat_id' => $candidatId,
                    'type' => 'signature_absente',
                    'detail' => 'Présence papier sans signature : la source papier ne peut pas être tenue pour probante.',
                ];
            }

            if ($dernierLog === null) {
                $reserves[] = [
                    'candidat_id' => $candidatId,
                    'type' => 'trace_journal_absente',
                    'detail' => 'Aucune trace horodatée pour ce candidat : la troisième source est muette.',
                ];
            }
        }

        $validationBloquee = $ecarts !== [];

        return [
            'epreuve_id' => $epreuve->id,
            'total_candidats' => $candidats->count(),
            'concordances' => $concordances,
            'divergences_detectees' => count($ecarts),
            'validation_bloquee' => $validationBloquee,
            'ecarts' => $ecarts,
            'reserves' => $reserves,
            'reserves_detectees' => count($reserves),
            'reconciliation_complete' => ! $validationBloquee && $reserves === [],
            'sources_comparees' => ['papier', 'numerique', 'journal'],
        ];
    }

    /**
     * Régularisation à 4 yeux d'un écart tri-sources.
     */
    public function regulariserEcartTriSources(
        Candidat $candidat,
        string $statutRetenu,
        string $motifExplicatif,
        int $instructeurId,
        int $valideurDistinctId,
        ?string $referencePreuve = null
    ): Candidat {
        if ($instructeurId === $valideurDistinctId) {
            throw new DomainException('Séparation des responsabilités : le valideur de régularisation doit être distinct du vérificateur.');
        }

        if (! in_array($statutRetenu, Candidat::STATUTS, true)) {
            throw new DomainException("Statut de régularisation invalide : « {$statutRetenu} ».");
        }

        return DB::transaction(function () use ($candidat, $statutRetenu, $motifExplicatif, $instructeurId, $valideurDistinctId, $referencePreuve) {
            $candidat->refresh();
            $ancienStatut = $candidat->statut;

            $audit = [
                'type' => 'regularisation_tri_sources_post_panne',
                'ancien_statut' => $ancienStatut,
                'statut_retenu' => $statutRetenu,
                'motif' => trim($motifExplicatif),
                'reference_preuve' => $referencePreuve,
                'instructeur_id' => $instructeurId,
                'valideur_id' => $valideurDistinctId,
                'horodatage' => Carbon::now()->toIso8601String(),
            ];

            $candidat->statut = $statutRetenu;
            $candidat->justification = json_encode($audit, JSON_UNESCAPED_UNICODE);
            $candidat->decide_par = $valideurDistinctId;
            $candidat->save();

            IntegrationLog::tracer(
                'evaluation',
                'internal',
                'exam.attendance.tri_sources_reconciled',
                'completed',
                null,
                200,
                null,
                null,
                (string) Str::uuid(),
                'CAND-'.$candidat->id
            );

            EvaluationChanged::dispatch('candidat.reconcilie', $candidat->epreuve_id);

            return $candidat;
        });
    }

    /** Permission exigée pour instruire et valider un écart. */
    public const PERMISSION_RECONCILIATION = 'evaluations.update';

    /**
     * Persiste les divergences d'un rapprochement tri-sources.
     *
     * Chaque écart devient un DOSSIER lisible, avec l'instantané des sources
     * au moment de la détection. Rejouer la même analyse ne crée pas de
     * doublon : l'empreinte couvre les trois sources constatées. Un écart
     * déjà validé n'est jamais rouvert : un changement réel de situation
     * produit une empreinte différente, donc un nouveau dossier.
     *
     * @param  array<string, mixed>  $resultatRapprochement
     * @return array{dossiers: EloquentCollection<int, EvaluationReconciliationEcart>, crees: int, existants: int}
     */
    public function persisterEcarts(array $resultatRapprochement, ?int $detecteParId = null): array
    {
        $epreuveId = (int) ($resultatRapprochement['epreuve_id'] ?? 0);

        if ($epreuveId <= 0 || ! Epreuve::whereKey($epreuveId)->exists()) {
            throw new DomainException('Persistance des écarts impossible : l’épreuve est inconnue.');
        }

        $ecarts = $resultatRapprochement['ecarts'] ?? [];

        return DB::transaction(function () use ($epreuveId, $ecarts, $detecteParId) {
            $dossiers = new EloquentCollection;
            $crees = 0;
            $existants = 0;

            foreach ($ecarts as $ecart) {
                $candidatId = (int) ($ecart['candidat_id'] ?? 0);
                $statutPapier = (string) ($ecart['statut_papier'] ?? '');
                $statutNumerique = (string) ($ecart['statut_numerique'] ?? '');
                $journal = $ecart['journal_horodate'] ?? null;

                if ($candidatId <= 0 || $statutPapier === '' || $statutNumerique === '') {
                    throw new DomainException('Écart incomplet : candidat et statuts des sources sont obligatoires.');
                }

                $dossier = EvaluationReconciliationEcart::firstOrCreate(
                    [
                        'epreuve_id' => $epreuveId,
                        'candidat_id' => $candidatId,
                        'empreinte_source' => $this->empreinteEcart($epreuveId, $candidatId, $statutPapier, $statutNumerique, $journal),
                    ],
                    [
                        'statut' => EvaluationReconciliationEcart::STATUT_OUVERT,
                        'statut_papier' => $statutPapier,
                        'statut_numerique' => $statutNumerique,
                        'journal_horodate' => $journal,
                        'nature_divergence' => (string) ($ecart['nature_divergence'] ?? 'Divergence papier/numérique constatée.'),
                        'detecte_par' => $detecteParId,
                        'detecte_at' => Carbon::now(),
                    ]
                );

                if ($dossier->wasRecentlyCreated) {
                    $crees++;

                    IntegrationLog::tracer(
                        'evaluation',
                        'internal',
                        'exam.attendance.reconciliation_ecart_ouvert',
                        'completed',
                        null,
                        200,
                        null,
                        null,
                        (string) Str::uuid(),
                        'CAND-'.$candidatId
                    );
                } else {
                    $existants++;
                }

                $dossiers->push($dossier);
            }

            return ['dossiers' => $dossiers, 'crees' => $crees, 'existants' => $existants];
        });
    }

    /**
     * Première paire de yeux : le vérificateur instruit l'écart.
     *
     * La source numérique doit être IDENTIQUE à l'instantané de détection :
     * si elle a bougé, la décision serait prise sur des faits périmés et
     * l'instruction est refusée jusqu'à une nouvelle analyse.
     */
    public function instruireEcart(
        EvaluationReconciliationEcart $ecart,
        int $instructeurId,
        string $statutRetenu,
        string $motifInstruction,
        ?string $referencePreuve = null
    ): EvaluationReconciliationEcart {
        $motif = trim($motifInstruction);

        if ($motif === '') {
            throw new DomainException('Le motif d’instruction de l’écart est obligatoire.');
        }

        if (! in_array($statutRetenu, Candidat::STATUTS, true)) {
            throw new DomainException("Statut retenu invalide : « {$statutRetenu} ».");
        }

        $this->exigerHabilitationReconciliation($instructeurId, 'instruire');

        return DB::transaction(function () use ($ecart, $instructeurId, $statutRetenu, $motif, $referencePreuve) {
            /** @var EvaluationReconciliationEcart $verrou */
            $verrou = EvaluationReconciliationEcart::whereKey($ecart->id)->lockForUpdate()->firstOrFail();

            if (! $verrou->estOuvert()) {
                throw new DomainException('Seul un écart ouvert peut être instruit : le dossier a déjà dépassé cette étape.');
            }

            $this->exigerSourceInchangee($verrou);

            $verrou->forceFill([
                'statut' => EvaluationReconciliationEcart::STATUT_INSTUIT,
                'instructeur_id' => $instructeurId,
                'instruit_at' => Carbon::now(),
                'statut_retenu' => $statutRetenu,
                'motif_instruction' => $motif,
                'preuve_reference' => $referencePreuve,
            ])->save();

            IntegrationLog::tracer(
                'evaluation',
                'internal',
                'exam.attendance.reconciliation_ecart_instruit',
                'completed',
                null,
                200,
                null,
                null,
                (string) Str::uuid(),
                'CAND-'.$verrou->candidat_id
            );

            EvaluationChanged::dispatch('exam.reconciliation.ecart_instruit', $verrou->epreuve_id);

            return $verrou;
        });
    }

    /**
     * Seconde paire de yeux : une personne DISTINCTE valide l'instruction.
     *
     * La validation applique le statut retenu au candidat via la
     * régularisation quatre yeux existante, puis scelle le dossier. Le
     * contrôle de fraîcheur est rejoué : entre l'instruction et la
     * validation, la source numérique ne doit pas avoir changé.
     */
    public function validerEcart(
        EvaluationReconciliationEcart $ecart,
        int $valideurId,
        ?string $commentaire = null
    ): EvaluationReconciliationEcart {
        $this->exigerHabilitationReconciliation($valideurId, 'valider');

        return DB::transaction(function () use ($ecart, $valideurId, $commentaire) {
            /** @var EvaluationReconciliationEcart $verrou */
            $verrou = EvaluationReconciliationEcart::whereKey($ecart->id)->lockForUpdate()->firstOrFail();

            if (! $verrou->estInstruit()) {
                throw new DomainException('Seul un écart instruit peut être validé : instruction préalable obligatoire.');
            }

            if ($verrou->instructeur_id === null) {
                throw new DomainException('Écart incohérent : aucune instruction enregistrée.');
            }

            if ((int) $verrou->instructeur_id === $valideurId) {
                throw new DomainException('Séparation des responsabilités : le valideur de l’écart doit être distinct de l’instructeur.');
            }

            if ($verrou->statut_retenu === null) {
                throw new DomainException('Écart incohérent : aucun statut retenu à appliquer.');
            }

            // Verrou de ligne sur le candidat : la fraîcheur vérifiée reste
            // vraie jusqu'au commit, aucune écriture concurrente ne peut
            // s'intercaler entre le contrôle et l'application du statut.
            $candidat = $this->exigerSourceInchangee($verrou);

            $this->regulariserEcartTriSources(
                $candidat,
                $verrou->statut_retenu,
                (string) $verrou->motif_instruction,
                (int) $verrou->instructeur_id,
                $valideurId,
                $verrou->preuve_reference
            );

            $verrou->forceFill([
                'statut' => EvaluationReconciliationEcart::STATUT_VALIDE,
                'valideur_id' => $valideurId,
                'valide_at' => Carbon::now(),
                'commentaire_validation' => $commentaire,
            ])->save();

            IntegrationLog::tracer(
                'evaluation',
                'internal',
                'exam.attendance.reconciliation_ecart_valide',
                'completed',
                null,
                200,
                null,
                null,
                (string) Str::uuid(),
                'CAND-'.$verrou->candidat_id
            );

            return $verrou;
        });
    }

    /** Empreinte stable de la situation constatée : sert d'anti-doublon. */
    private function empreinteEcart(
        int $epreuveId,
        int $candidatId,
        string $statutPapier,
        string $statutNumerique,
        mixed $journalHorodate
    ): string {
        $journal = $journalHorodate instanceof \DateTimeInterface
            ? $journalHorodate->format('c')
            : (string) ($journalHorodate ?? 'sans-trace');

        return hash('sha256', implode('|', [
            'v1', $epreuveId, $candidatId, $statutPapier, $statutNumerique, $journal,
        ]));
    }

    /**
     * La source numérique n'a pas changé depuis la détection.
     * Retourne le candidat VERROUILLÉ pour la suite de la transaction.
     */
    private function exigerSourceInchangee(EvaluationReconciliationEcart $ecart): Candidat
    {
        $candidat = Candidat::whereKey($ecart->candidat_id)
            ->where('epreuve_id', $ecart->epreuve_id)
            ->lockForUpdate()
            ->first();

        if ($candidat === null) {
            throw new DomainException('Écart orphelin : le candidat n’appartient plus à l’épreuve.');
        }

        if ((string) $candidat->statut !== (string) $ecart->statut_numerique) {
            throw new DomainException(sprintf(
                'La source numérique a changé depuis la détection (« %s » détecté, « %s » actuel) : relancez l’analyse tri-sources avant de poursuivre.',
                $ecart->statut_numerique,
                $candidat->statut
            ));
        }

        return $candidat;
    }

    private function exigerHabilitationReconciliation(int $acteurId, string $action): void
    {
        $acteur = User::find($acteurId);

        if ($acteur === null || ! $acteur->hasPermission(self::PERMISSION_RECONCILIATION)) {
            $libelle = $action === 'instruire' ? 'd’instruire' : 'de valider';

            throw new DomainException(
                "Impossible {$libelle} l’écart : l’acteur doit être habilité (".self::PERMISSION_RECONCILIATION.').'
            );
        }
    }

    /**
     * Reprise idempotente et contrôlée après panne des opérations hors-ligne avec rapport exhaustif.
     *
     * @param array<int, array{
     *     cle_idempotence: string,
     *     epreuve_id: int,
     *     candidat_id: int,
     *     statut: string
     * }> $lotsEnAttente
     * @return array{
     *     total_soumis: int,
     *     acceptes: int,
     *     rejetes: int,
     *     deja_traites: int,
     *     a_reviser: int,
     *     rapport_details: array<int, array{cle: string, candidat_id: int, decision: string, motif: string}>
     * }
     */
    public function executerRepriseApresPanne(array $lotsEnAttente, int $auteurRepriseId): array
    {
        $acceptes = 0;
        $rejetes = 0;
        $dejaTraites = 0;
        $aReviser = 0;
        $rapport = [];

        foreach ($lotsEnAttente as $entree) {
            $cle = (string) $entree['cle_idempotence'];
            $epreuveId = (int) $entree['epreuve_id'];
            $candidatId = (int) $entree['candidat_id'];
            $statut = (string) $entree['statut'];

            // 1. Contrôle d'idempotence stricte
            if (EmargementHorsLigne::where('cle_idempotence', $cle)->exists()) {
                $dejaTraites++;
                $rapport[] = [
                    'cle' => $cle,
                    'candidat_id' => $candidatId,
                    'decision' => 'ignore_doublon',
                    'motif' => 'Opération déjà synchronisée et traitée précédemment.',
                ];

                continue;
            }

            // 2. Vérification de l'intégrité de l'épreuve et du candidat
            $candidat = Candidat::where('id', $candidatId)->where('epreuve_id', $epreuveId)->first();

            if ($candidat === null) {
                $rejetes++;
                $rapport[] = [
                    'cle' => $cle,
                    'candidat_id' => $candidatId,
                    'decision' => 'rejete',
                    'motif' => "Le candidat n'appartient pas à l'épreuve spécifiée.",
                ];

                continue;
            }

            if ($candidat->epreuve?->estVerrouillee()) {
                $aReviser++;
                $rapport[] = [
                    'cle' => $cle,
                    'candidat_id' => $candidatId,
                    'decision' => 'a_reviser',
                    'motif' => "L'épreuve est déjà clôturée et verrouillée. Exige une procédure formelle de correction post-clôture.",
                ];

                continue;
            }

            if (! in_array($statut, Candidat::STATUTS, true)) {
                $rejetes++;
                $rapport[] = [
                    'cle' => $cle,
                    'candidat_id' => $candidatId,
                    'decision' => 'rejete',
                    'motif' => "Statut de pointage non autorisé : {$statut}.",
                ];

                continue;
            }

            // 3. Application sécurisée
            DB::transaction(function () use ($cle, $epreuveId, $candidat, $statut, $auteurRepriseId) {
                EmargementHorsLigne::create([
                    'epreuve_id' => $epreuveId,
                    'candidat_id' => $candidat->id,
                    'cle_idempotence' => $cle,
                    'statut' => $statut,
                    'auteur_id' => $auteurRepriseId,
                    'recu_a' => Carbon::now(),
                ]);

                $candidat->update([
                    'statut' => $statut,
                    'decide_par' => $auteurRepriseId,
                ]);
            });

            $acceptes++;
            $rapport[] = [
                'cle' => $cle,
                'candidat_id' => $candidatId,
                'decision' => 'accepte',
                'motif' => "Pointage réconcilié et appliqué en base avec succès ({$statut}).",
            ];
        }

        return [
            'total_soumis' => count($lotsEnAttente),
            'acceptes' => $acceptes,
            'rejetes' => $rejetes,
            'deja_traites' => $dejaTraites,
            'a_reviser' => $aReviser,
            'rapport_details' => $rapport,
        ];
    }
}
