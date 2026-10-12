<?php

namespace App\Services\Affectations;

use App\Models\AffectationGroupe;
use App\Models\AffectationProposal;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use App\Services\AffectationService;
use App\Services\AuditService;
use App\Services\NotificationService;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * MOTEUR UNIQUE D'AFFECTATION UNIFIÉ (LOOP MATURE) :
 *
 * Source de vérité unique pour :
 * - L'affectation à l'inscription (événementiel / automatique ou proposition)
 * - L'affectation individuelle
 * - L'affectation semi-automatique (génération et validation de propositions)
 * - L'affectation de masse (/admin/affectations/masse)
 * - La réaffectation historisée (changement de cursus / transfert)
 */
class AffectationEngine
{
    public const STATUT_AUTO_ASSIGNABLE = 'AUTO_ASSIGNABLE';

    public const STATUT_SEMI_AUTO_PROPOSAL = 'SEMI_AUTO_PROPOSAL';

    public const STATUT_NON_AFFECTABLE = 'NON_AFFECTABLE';

    public function __construct(
        private readonly AffectationService $affectationService,
        private readonly AuditService $auditService,
        private readonly NotificationService $notificationService,
        private readonly AffectationEligibilityService $eligibilityService,
    ) {}

    /**
     * Évalue l'éligibilité et calcule le scoring /100 pour tous les groupes d'un étudiant.
     *
     * @param  array{preloaded_ctx?: array, places_simulees?: array<int, int>, effectifs_simules?: array<int, int>, ignorer_documents?: bool, exiger_contact_complet?: bool}  $options
     * @return array{
     *     eligible: bool,
     *     decision: string,
     *     certitude: string,
     *     meilleur_groupe: Group|null,
     *     meilleur_score: int,
     *     reasons: array<string>,
     *     scoring_breakdown: array<string, int>,
     *     alternatives: array<int, array{group_id: int, code: string, score: int, reasons: array<string>}>,
     *     anomalies: array<int, array{code: string, message: string}>,
     *     is_reaffectation: bool,
     *     current_group: string|null,
     *     eligibility_report?: array
     * }
     */
    public function evaluer(Etudiant $etudiant, AnneeUniversitaire $annee, array $options = []): array
    {
        $ctx = $options['preloaded_ctx'] ?? null;
        $placesSimulees = $options['places_simulees'] ?? [];
        $effectifsSimules = $options['effectifs_simules'] ?? [];

        // 1. Contrôle d'éligibilité administrative préalable (LOOP MATURE — Phases 2 & 3)
        $bilanEligibilite = $this->eligibilityService->verifier($etudiant, $annee, $options);

        if (! $bilanEligibilite['eligible']) {
            $anomalies = [];
            foreach ($bilanEligibilite['blocking_reasons'] as $code) {
                $anomalies[] = [
                    'code' => $code,
                    'message' => $bilanEligibilite['details'][$code] ?? "Critère administratif d'éligibilité non validé ({$code}).",
                ];
            }

            return array_merge($this->reponseIneligible($anomalies), [
                'eligibility_report' => $bilanEligibilite,
            ]);
        }

        $niveauEtudiant = $etudiant->niveau
            ?: ($ctx['inscriptions'][$etudiant->id] ?? null ? InscriptionAcademique::where('etudiant_id', $etudiant->id)->where('annee_universitaire_id', $annee->id)->value('niveau') : null);

        if (empty($niveauEtudiant)) {
            return array_merge($this->reponseIneligible([
                ['code' => 'NIVEAU_MANQUANT', 'message' => 'Niveau académique LMD non renseigné.'],
            ]), ['eligibility_report' => $bilanEligibilite]);
        }

        // 1.5. Vérification si déjà affecté (Phase 17 - Idempotence)
        $affectationActive = $etudiant->affectations()->where('annee_universitaire_id', $annee->id)->where('statut', 'active')->first();
        if ($affectationActive !== null && empty($options['autoriser_reaffectation'])) {
            if ($affectationActive->verrouille) {
                return array_merge($this->reponseIneligible([
                    ['code' => 'AFFECTATION_VERROUILLEE', 'message' => "Affectation active verrouillée sur {$affectationActive->groupe?->code}."],
                ]), ['statut' => self::STATUT_NON_AFFECTABLE, 'eligibility_report' => $bilanEligibilite]);
            }

            return array_merge($this->reponseIneligible([
                ['code' => 'ALREADY_ASSIGNED', 'message' => "L'étudiant est déjà affecté au groupe {$affectationActive->groupe?->code} pour cette année."],
            ]), ['statut' => self::STATUT_NON_AFFECTABLE, 'eligibility_report' => $bilanEligibilite]);
        }

        // 2. Recherche des groupes candidats dans la faculté de l'étudiant
        $groupes = Group::query()
            ->where('actif', true)
            ->where('faculte_id', $etudiant->faculte_id)
            ->get();

        if ($groupes->isEmpty()) {
            return $this->reponseIneligible([
                ['code' => 'AUCUN_GROUPE_FACULTE', 'message' => "Aucun groupe actif dans la faculté #{$etudiant->faculte_id}."],
            ]);
        }

        // Préchargement du contexte bulk si absent
        if ($ctx === null) {
            $ctx = $this->affectationService->contexteBulk([$etudiant->id], $groupes->pluck('id')->all(), $annee);
        }

        // 3. Filtrage par contraintes dures (Hard constraints - LOOP 3)
        $groupesAdmissibles = [];
        $anomalies = [];

        foreach ($groupes as $groupe) {
            // Règle 1 : Niveau strict (LOOP 3)
            if ($groupe->niveau !== null && $groupe->niveau !== $niveauEtudiant) {
                continue;
            }

            // Règle 2 : Programme / Parcours strict si spécifié sur le groupe
            if ($groupe->programme_id !== null && $etudiant->programme_id !== null && (int) $groupe->programme_id !== (int) $etudiant->programme_id) {
                continue;
            }

            // Règle 3 : Capacité (vérifie places réelles ou simulées)
            $effectif = $effectifsSimules[$groupe->id] ?? ($ctx['effectifs'][$groupe->id] ?? $groupe->effectifActuel());
            $placesRestantes = $placesSimulees[$groupe->id] ?? ($groupe->capacite !== null ? max(0, (int) $groupe->capacite - $effectif) : PHP_INT_MAX);

            if ($groupe->capacite !== null && $placesRestantes <= 0) {
                continue;
            }

            $groupesAdmissibles[] = [
                'groupe' => $groupe,
                'effectif' => $effectif,
                'places_restantes' => $placesRestantes,
            ];
        }

        if (empty($groupesAdmissibles)) {
            return $this->reponseIneligible([
                ['code' => 'AUCUN_GROUPE_COMPATIBLE', 'message' => "Aucun groupe compatible disponible avec place pour le niveau {$niveauEtudiant}."],
            ]);
        }

        // 4. Moteur de scoring /100 pour classer les groupes admissibles (LOOP 4).
        // Barème central noter() : mêmes données préchargées, zéro requête,
        // réutilisé tel quel par l'analyse de masse (explicabilité).
        $minEffectif = min(array_column($groupesAdmissibles, 'effectif'));
        $maxEffectif = max(array_column($groupesAdmissibles, 'effectif'));
        $scoresGroupes = [];

        foreach ($groupesAdmissibles as $item) {
            $note = $this->noter($etudiant, $item['groupe'], $item['effectif'], $minEffectif, $maxEffectif);

            $scoresGroupes[] = [
                'groupe' => $item['groupe'],
                'score' => $note['score'],
                'reasons' => $note['reasons'],
                'scoring_breakdown' => $note['scoring_breakdown'],
                'effectif' => $item['effectif'],
                'taux_remplissage' => $note['taux_remplissage'],
            ];
        }

        // Tri décroissant par score, puis effectif croissant, puis ID déterministe
        usort($scoresGroupes, function ($a, $b) {
            return [$b['score'], $a['effectif'], $a['groupe']->id] <=> [$a['score'], $b['effectif'], $b['groupe']->id];
        });

        $meilleur = $scoresGroupes[0];
        $second = $scoresGroupes[1] ?? null;

        // 5. Détermination de la Certitude (Certitude élevée vs Moyenne/Faible - LOOP 6)
        $certitude = AffectationProposal::CERTITUDE_MOYENNE;
        $decision = 'SEMI_AUTO_PROPOSAL';

        $ecartScore = $second !== null ? ($meilleur['score'] - $second['score']) : 100;
        $tauxRemplissage = $meilleur['taux_remplissage'];

        $certitude = $this->certitudePour($meilleur['score'], $ecartScore, $tauxRemplissage, empty($bilanEligibilite['warnings']));
        $decision = $certitude === AffectationProposal::CERTITUDE_ELEVEE ? self::STATUT_AUTO_ASSIGNABLE : self::STATUT_SEMI_AUTO_PROPOSAL;

        // Détection de réaffectation (LOOP 15)
        $currentGroupCode = $affectationActive?->groupe?->code;
        $isReaffectation = $affectationActive !== null && (int) $affectationActive->groupe_id !== (int) $meilleur['groupe']->id;

        // Alternatives pour l'administrateur
        $alternatives = [];
        foreach (array_slice($scoresGroupes, 1, 5) as $alt) {
            $alternatives[] = [
                'group_id' => $alt['groupe']->id,
                'code' => $alt['groupe']->code,
                'score' => $alt['score'],
                'reasons' => $alt['reasons'],
            ];
        }

        return [
            'eligible' => true,
            'statut' => $decision,
            'decision' => $decision,
            'certitude' => $certitude,
            'meilleur_groupe' => $meilleur['groupe'],
            'meilleur_score' => $meilleur['score'],
            'reasons' => $meilleur['reasons'],
            'scoring_breakdown' => $meilleur['scoring_breakdown'],
            'alternatives' => $alternatives,
            'anomalies' => [],
            'avertissements' => $bilanEligibilite['warnings'] ?? [],
            'is_reaffectation' => $isReaffectation,
            'current_group' => $currentGroupCode,
        ];
    }

    /**
     * Traite un étudiant selon le mode (Automatique si certain, Semi-auto sinon).
     *
     * @return array{type: string, message: string, proposal?: AffectationProposal, affectation?: AffectationGroupe, score?: int}
     */
    public function traiterEtudiant(
        Etudiant $etudiant,
        AnneeUniversitaire $annee,
        string $modeSouhaite = 'automatique',
        ?int $auteurId = null,
        array $options = []
    ): array {
        $evaluation = $this->evaluer($etudiant, $annee, $options);

        if (! $evaluation['eligible']) {
            return [
                'type' => 'bloque',
                'message' => $evaluation['anomalies'][0]['message'] ?? 'Affectation impossible.',
                'anomalies' => $evaluation['anomalies'],
            ];
        }

        $meilleurGroupe = $evaluation['meilleur_groupe'];

        // MODE AUTOMATIQUE : Seulement si demandé ET certitude élevée sans ambiguïté (LOOP 5)
        if ($modeSouhaite === 'automatique' && $evaluation['certitude'] === AffectationProposal::CERTITUDE_ELEVEE) {
            $affectation = $this->affectationService->affecter(
                $etudiant,
                $meilleurGroupe,
                $annee,
                $auteurId,
                'Affectation automatique à certitude élevée (Score: '.$evaluation['meilleur_score'].'/100)',
                false,
                null,
                null,
                true // Notification directe envoyée
            );

            $this->auditService->tracer(
                $etudiant,
                'assignment.auto_executed',
                'groupe',
                $meilleurGroupe->id,
                null,
                [
                    'score' => $evaluation['meilleur_score'],
                    'reasons' => $evaluation['reasons'],
                    'annee' => $annee->libelle,
                ]
            );

            return [
                'type' => 'affecte',
                'message' => "Étudiant affecté directement au groupe {$meilleurGroupe->code}.",
                'affectation' => $affectation,
                'score' => $evaluation['meilleur_score'],
            ];
        }

        // MODE SEMI-AUTOMATIQUE : Création d'une proposition persistée (LOOP 7 & 10)
        $proposal = AffectationProposal::updateOrCreate(
            [
                'student_id' => $etudiant->id,
                'annee_universitaire_id' => $annee->id,
                'statut' => AffectationProposal::STATUT_EN_ATTENTE,
            ],
            [
                'inscription_id' => $etudiant->inscriptionsAcademiques->where('annee_universitaire_id', $annee->id)->first()?->id,
                'proposed_group_id' => $meilleurGroupe->id,
                'mode' => AffectationProposal::MODE_SEMI_AUTOMATIQUE,
                'score' => $evaluation['meilleur_score'],
                'certitude' => $evaluation['certitude'],
                'reasons' => $evaluation['reasons'],
                'metadata' => [
                    'scoring_breakdown' => $evaluation['scoring_breakdown'],
                    'alternatives' => $evaluation['alternatives'],
                    'is_reaffectation' => $evaluation['is_reaffectation'],
                    'current_group' => $evaluation['current_group'],
                    'source' => $options['source'] ?? 'cockpit',
                ],
            ]
        );

        $this->auditService->tracer(
            $etudiant,
            'assignment.proposal_created',
            'affectation_proposal',
            $proposal->id,
            null,
            [
                'score' => $evaluation['meilleur_score'],
                'certitude' => $evaluation['certitude'],
                'proposed_group' => $meilleurGroupe->code,
            ]
        );

        return [
            'type' => 'proposition',
            'message' => "Proposition d'affectation soumise pour validation administrative ({$meilleurGroupe->code}, Score: {$evaluation['meilleur_score']}/100).",
            'proposal' => $proposal,
            'score' => $evaluation['meilleur_score'],
        ];
    }

    /**
     * Validation transactionnelle d'une proposition avec lock anti-concurrence (LOOP 13).
     */
    public function accepterProposition(AffectationProposal $proposal, int $auteurId, ?string $motif = null): AffectationGroupe
    {
        if (! $proposal->estEnAttente()) {
            throw new DomainException('Cette proposition n’est plus en attente.');
        }

        return DB::transaction(function () use ($proposal, $auteurId, $motif) {
            /** @var Group $groupe */
            $groupe = Group::where('id', $proposal->proposed_group_id)->lockForUpdate()->firstOrFail();
            $etudiant = $proposal->student;
            $annee = $proposal->anneeUniversitaire;

            $affectation = $this->affectationService->affecter(
                $etudiant,
                $groupe,
                $annee,
                $auteurId,
                $motif ?? "Validation de la proposition #{$proposal->id} (Score: {$proposal->score}/100)",
                false,
                null,
                null,
                true
            );

            $proposal->update([
                'statut' => AffectationProposal::STATUT_ACCEPTEE,
                'valide_par' => $auteurId,
                'valide_le' => now(),
            ]);

            $this->auditService->tracer(
                $etudiant,
                'assignment.proposal_accepted',
                'affectation_proposal',
                $proposal->id,
                null,
                [
                    'groupe' => $groupe->code,
                    'valide_par' => $auteurId,
                ]
            );

            return $affectation;
        });
    }

    /**
     * Modification du groupe cible avant validation.
     */
    /**
     * Modification du groupe cible avant validation.
     */
    public function modifierProposition(AffectationProposal $proposal, Group|int $nouveauGroupe, int $auteurId, string $motif): AffectationGroupe
    {
        if (! $proposal->estEnAttente()) {
            throw new DomainException('Cette proposition n’est plus en attente.');
        }

        $nouveauGroupeId = $nouveauGroupe instanceof Group ? $nouveauGroupe->id : (int) $nouveauGroupe;

        return DB::transaction(function () use ($proposal, $nouveauGroupeId, $auteurId, $motif) {
            /** @var Group $nouveauGroupe */
            $nouveauGroupe = Group::where('id', $nouveauGroupeId)->lockForUpdate()->firstOrFail();
            $etudiant = $proposal->student;
            $annee = $proposal->anneeUniversitaire;

            $affectation = $this->affectationService->affecter(
                $etudiant,
                $nouveauGroupe,
                $annee,
                $auteurId,
                "[MODIFIÉ ADMIN] {$motif}",
                false,
                null,
                null,
                true
            );

            $proposal->update([
                'proposed_group_id' => $nouveauGroupeId,
                'statut' => AffectationProposal::STATUT_MODIFIEE,
                'valide_par' => $auteurId,
                'valide_le' => now(),
            ]);

            $this->auditService->tracer(
                $etudiant,
                'assignment.proposal_modified',
                'affectation_proposal',
                $proposal->id,
                null,
                [
                    'ancien_groupe' => $proposal->proposed_group_id,
                    'nouveau_groupe' => $nouveauGroupe->code,
                    'motif' => $motif,
                ]
            );

            return $affectation;
        });
    }

    /**
     * Rejet d'une proposition par l'administrateur.
     */
    public function rejeterProposition(AffectationProposal $proposal, int $auteurId, string $motif): void
    {
        if (! $proposal->estEnAttente()) {
            throw new DomainException('Cette proposition n’est plus en attente.');
        }

        $proposal->update([
            'statut' => AffectationProposal::STATUT_REJETEE,
            'valide_par' => $auteurId,
            'valide_le' => now(),
            'motif_refus' => $motif,
        ]);

        $this->auditService->tracer(
            $proposal->student,
            'assignment.proposal_rejected',
            'affectation_proposal',
            $proposal->id,
            null,
            ['motif' => $motif, 'valide_par' => $auteurId]
        );
    }

    /**
     * Exécute directement une affectation automatique si le dossier est éligible et certain.
     *
     * @return array{succes: bool, type: string, message: string, affectation: ?AffectationGroupe, score: ?int, anomalies: array}
     */
    public function affecterAutomatique(Etudiant $etudiant, AnneeUniversitaire $annee, ?int $auteurId = null, array $options = []): array
    {
        $res = $this->traiterEtudiant($etudiant, $annee, 'automatique', $auteurId, $options);

        return [
            'succes' => ($res['type'] === 'affecte'),
            'type' => $res['type'],
            'message' => $res['message'],
            'affectation' => $res['affectation'] ?? null,
            'score' => $res['score'] ?? null,
            'anomalies' => $res['anomalies'] ?? [],
        ];
    }

    /**
     * Génère une proposition semi-automatique persistée pour arbitrage administrateur.
     */
    public function genererProposition(Etudiant $etudiant, AnneeUniversitaire $annee, ?int $auteurId = null, array $options = []): ?AffectationProposal
    {
        $res = $this->traiterEtudiant($etudiant, $annee, 'semi-automatique', $auteurId, $options);

        return $res['proposal'] ?? null;
    }

    /**
     * Affectation exceptionnelle sécurisée (Phase 19) :
     * Réservée à un utilisateur possédant la permission `affectations.override` ou `groupes.manage`.
     * Motif obligatoire, re-vérification sous verrou, traçabilité d'audit `assignment.exceptional_override`.
     */
    public function affecterExceptionnel(
        Etudiant $etudiant,
        Group $groupe,
        AnneeUniversitaire $annee,
        User $auteur,
        string $motif
    ): AffectationGroupe {
        if (! $auteur->hasPermission('groupes.manage') && ! $auteur->hasPermission('affectations.override')) {
            abort(403, 'Permission requise pour une affectation exceptionnelle (groupes.manage ou affectations.override).');
        }

        if (trim($motif) === '') {
            throw new DomainException('Un motif circonstancié est obligatoire pour une affectation exceptionnelle.');
        }

        $affectation = $this->affectationService->affecter(
            $etudiant,
            $groupe,
            $annee,
            $auteur->id,
            "[EXCEPTIONNEL] {$motif}",
            true, // force = true
            null,
            null,
            true
        );

        $this->auditService->tracer(
            $etudiant,
            'assignment.exceptional_override',
            'affectation_groupe',
            $affectation->id,
            null,
            [
                'groupe' => $groupe->code,
                'motif' => $motif,
                'auteur' => $auteur->email,
            ]
        );

        return $affectation;
    }

    /**
     * Évalue un lot d'étudiants en préchargeant les contextes pour garantir zéro N+1 (LOOP 14 & 19).
     *
     * @param  Collection<int, Etudiant>  $etudiants
     * @param  Collection<int, Group>  $groupes
     * @return array{
     *     total: int,
     *     eligible_count: int,
     *     assigned_count: int,
     *     certitude_elevee_count: int,
     *     certitude_moyenne_count: int,
     *     propositions: array<int, array>,
     *     anomalies: array<int, array>,
     *     groups_summary: array<int, array>
     * }
     */
    /**
     * @return array<string, int>
     */
    public function poidsScoring(): array
    {
        return (array) config('affectations.scoring', [
            'niveau' => 30,
            'parcours_exact' => 25,
            'parcours_tronc_commun' => 15,
            'filiere_concordante' => 20,
            'filiere_defaut' => 15,
            'domaine' => 10,
            'capacite_confortable' => 5,
            'equilibrage_max' => 10,
        ]);
    }

    /**
     * @return array<string, int>
     */
    public function seuilsDecision(): array
    {
        return (array) config('affectations.seuils', [
            'auto_score' => 85,
            'auto_ecart' => 8,
            'auto_remplissage_max' => 85,
            'faible_score' => 70,
            'faible_remplissage' => 90,
            'capacite_confortable_max' => 80,
        ]);
    }

    /**
     * Note UN groupe candidat pour UN étudiant à partir de données
     * PRÉCHARGÉES uniquement (zéro requête) : barème central
     * `config/affectations.php`. Utilisé par evaluer() ET par
     * l'analyse de masse (explicabilité, sans second moteur).
     *
     * @return array{score: int, reasons: array<int, string>, scoring_breakdown: array<string, int>, taux_remplissage: float}
     */
    public function noter(Etudiant $etudiant, Group $groupe, int $effectif, int $minEffectif, int $maxEffectif): array
    {
        return $this->noterAvecBase(
            $etudiant, $groupe, $effectif, $minEffectif, $maxEffectif,
            $this->noterStatique($etudiant, $groupe)
        );
    }

    /**
     * Part statique de la note (niveau/parcours/filière/domaine) :
     * ne dépend ni des effectifs ni des seuils de décision. Les
     * traitements par lot la mémoïsent par signature académique
     * (même résultat arithmétique, sans recalcul).
     *
     * @return array{points: int, reasons: array<int, string>, breakdown: array<string, int>}
     */
    public function noterStatique(Etudiant $etudiant, Group $groupe): array
    {
        $poids = $this->poidsScoring();

        $breakdown = [
            'niveau' => $poids['niveau'], // Niveau exact respecté
            'parcours' => 0,
            'filiere' => 0,
            'domaine' => $poids['domaine'], // Même faculté/domaine
        ];
        $reasons = ['Niveau académique strict conforme (+'.$breakdown['niveau'].' pts)'];

        // Parcours / Programme (exact vs tronc commun)
        if ($groupe->programme_id !== null && (int) $groupe->programme_id === (int) $etudiant->programme_id) {
            $breakdown['parcours'] = $poids['parcours_exact'];
            $reasons[] = 'Parcours / Programme LMD identique (+'.$poids['parcours_exact'].' pts)';
        } elseif ($groupe->programme_id === null || $etudiant->programme_id === null) {
            $breakdown['parcours'] = $poids['parcours_tronc_commun'];
            $reasons[] = 'Tronc commun / Parcours général compatible (+'.$poids['parcours_tronc_commun'].' pts)';
        }

        // Spécialité / Filière (concordante vs défaut)
        $filiereEtudiant = $etudiant->filiere ?? $etudiant->programme?->nom ?? $etudiant->programme?->code ?? null;
        if (! empty($groupe->filiere) && ! empty($filiereEtudiant) && (strcasecmp($groupe->filiere, $filiereEtudiant) === 0 || str_contains(strtolower($filiereEtudiant), strtolower($groupe->filiere)))) {
            $breakdown['filiere'] = $poids['filiere_concordante'];
            $reasons[] = 'Filière / Spécialité d’études concordante (+'.$poids['filiere_concordante'].' pts)';
        } else {
            $breakdown['filiere'] = $poids['filiere_defaut'];
        }

        return ['points' => array_sum($breakdown), 'reasons' => $reasons, 'breakdown' => $breakdown];
    }

    /**
     * Score seul, arithmétiquement identique à noter()/noterAvecBase
     * (mêmes points, mêmes seuils), sans construire ni raisons ni
     * breakdown : classement des candidats en lot. Les notes
     * complètes (raisons exactes) sont ensuite calculées pour le
     * seul groupe choisi et ses meilleures alternatives.
     */
    public function scoreLeger(int $pointsStatiques, Group $groupe, int $effectif, int $minEffectif, int $maxEffectif): int
    {
        $poids = $this->poidsScoring();
        $seuils = $this->seuilsDecision();

        $taux = ($groupe->capacite !== null && $groupe->capacite > 0)
            ? ($effectif / $groupe->capacite) * 100
            : 0;

        $score = $pointsStatiques;
        if ($taux < $seuils['capacite_confortable_max']) {
            $score += $poids['capacite_confortable'];
        }

        $ecart = $maxEffectif - $minEffectif;
        $score += $ecart <= 0
            ? $poids['equilibrage_max']
            : (int) round($poids['equilibrage_max'] * (($maxEffectif - $effectif) / $ecart));

        return min(100, $score);
    }

    /**
     * effectifs simulés) à une base statique. `noter()` = ce raccourci
     * avec base calculée ; les lots passent une base mémoïsée.
     *
     * @param  array{points: int, reasons: array<int, string>, breakdown: array<string, int>}  $base
     * @return array{score: int, reasons: array<int, string>, scoring_breakdown: array<string, int>, taux_remplissage: float}
     */
    public function noterAvecBase(Etudiant $etudiant, Group $groupe, int $effectif, int $minEffectif, int $maxEffectif, array $base): array
    {
        $poids = $this->poidsScoring();
        $seuils = $this->seuilsDecision();

        $breakdown = $base['breakdown'];
        $reasons = $base['reasons'];

        // Capacité disponible confortable
        $tauxRemplissage = ($groupe->capacite !== null && $groupe->capacite > 0)
            ? ($effectif / $groupe->capacite) * 100
            : 0;

        $breakdown['capacite'] = 0;
        if ($tauxRemplissage < $seuils['capacite_confortable_max']) {
            $breakdown['capacite'] = $poids['capacite_confortable'];
            $reasons[] = 'Capacité d’accueil disponible confortable (+'.$poids['capacite_confortable'].' pts)';
        }

        // Équilibrage de charge (proportionnel au moins rempli)
        $ecart = $maxEffectif - $minEffectif;
        if ($ecart <= 0) {
            $pointsEquilibrage = $poids['equilibrage_max'];
        } else {
            $pointsEquilibrage = (int) round($poids['equilibrage_max'] * (($maxEffectif - $effectif) / $ecart));
        }
        $breakdown['equilibrage'] = $pointsEquilibrage;
        if ($pointsEquilibrage >= (int) round($poids['equilibrage_max'] * 0.8)) {
            $reasons[] = 'Optimal pour l’équilibrage de charge (+'.$pointsEquilibrage.' pts)';
        }

        return [
            'score' => min(100, $base['points'] + $breakdown['capacite'] + $breakdown['equilibrage']),
            'reasons' => $reasons,
            'scoring_breakdown' => $breakdown,
            'taux_remplissage' => $tauxRemplissage,
        ];
    }

    /**
     * Règle centrale de certitude (mêmes seuils que evaluer()) :
     * élevée = score + écart + remplissage sains ET sans alerte ;
     * faible = score insuffisant ou groupe saturé ; sinon moyenne.
     */
    public function certitudePour(int $score, int $ecartSecond, float $tauxRemplissage, bool $sansAlerte): string
    {
        $seuils = $this->seuilsDecision();

        if ($sansAlerte && $score >= $seuils['auto_score'] && $ecartSecond >= $seuils['auto_ecart'] && $tauxRemplissage < $seuils['auto_remplissage_max']) {
            return AffectationProposal::CERTITUDE_ELEVEE;
        }

        if ($score < $seuils['faible_score'] || $tauxRemplissage >= $seuils['faible_remplissage']) {
            return AffectationProposal::CERTITUDE_FAIBLE;
        }

        return AffectationProposal::CERTITUDE_MOYENNE;
    }

    private function reponseIneligible(array $anomalies): array
    {
        return [
            'eligible' => false,
            'statut' => self::STATUT_NON_AFFECTABLE,
            'decision' => self::STATUT_NON_AFFECTABLE,
            'certitude' => AffectationProposal::CERTITUDE_FAIBLE,
            'meilleur_groupe' => null,
            'meilleur_score' => 0,
            'reasons' => [],
            'scoring_breakdown' => [],
            'alternatives' => [],
            'anomalies' => $anomalies,
            'is_reaffectation' => false,
            'current_group' => null,
        ];
    }
}
