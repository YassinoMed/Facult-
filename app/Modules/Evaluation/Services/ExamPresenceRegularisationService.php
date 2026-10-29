<?php

namespace App\Modules\Evaluation\Services;

use App\Models\IntegrationLog;
use App\Models\User;
use App\Modules\Evaluation\Events\EvaluationChanged;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\RegularisationPresence;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Service de gestion des présences, retards, absences et régularisations (LOT E - §2.9).
 *
 * Règles métier absolues :
 * 1. Retard : heure d'arrivée horodatée et conservée, sans exclusion automatique.
 * 2. Absence provisoire : `presence_provisoire = true` et JAMAIS de conversion
 *    automatique en absence injustifiée.
 * 3. Justificatif tardif : demande PERSISTÉE (table dédiée), instruite par une
 *    décision motivée portée par un acteur habilité, DISTINCT du demandeur et
 *    du pointeur initial (séparation des responsabilités).
 * 4. Détection des écarts de présence bloquant la clôture définitive.
 */
class ExamPresenceRegularisationService
{
    /** Permission minimale pour instruire une régularisation de présence. */
    public const PERMISSION_INSTRUCTION = 'evaluations.update';

    /**
     * Enregistre un retard d'étudiant avec heure d'arrivée, sans exclusion automatique.
     */
    public function enregistrerRetard(Candidat $candidat, string $heureArrivee, ?string $motif = null, ?int $auteurId = null): Candidat
    {
        $heure = $this->normaliserHeure($heureArrivee);

        return DB::transaction(function () use ($candidat, $heure, $motif, $auteurId) {
            /** @var Candidat $verrouille */
            $verrouille = Candidat::whereKey($candidat->id)->lockForUpdate()->firstOrFail();

            if ($verrouille->epreuve?->estVerrouillee()) {
                throw new DomainException('Épreuve verrouillée : modification du pointage interdite.');
            }

            $justification = [
                'type' => 'retard',
                'heure_arrivee' => substr($heure, 0, 5),
                'motif' => $motif ?? "Arrivée en retard constatée à {$heure}",
                'enregistre_le' => Carbon::now()->toIso8601String(),
                'auteur_id' => $auteurId,
            ];

            $verrouille->update([
                'statut' => 'retard',
                'heure_arrivee' => $heure,
                // Un retard est une présence constatée : rien de provisoire.
                'presence_provisoire' => false,
                'justification' => json_encode($justification, JSON_UNESCAPED_UNICODE),
                'decide_par' => $auteurId,
            ]);

            EvaluationChanged::dispatch('candidat.retard_enregistre', $verrouille->epreuve_id);

            return $verrouille;
        });
    }

    /**
     * Enregistre un constat d'absence initiale sous forme d'absence PROVISOIRE.
     * Règle absolue : ne jamais convertir automatiquement en absence injustifiée.
     */
    public function enregistrerAbsenceProvisoire(Candidat $candidat, ?string $motif = null, ?int $auteurId = null): Candidat
    {
        return DB::transaction(function () use ($candidat, $motif, $auteurId) {
            /** @var Candidat $verrouille */
            $verrouille = Candidat::whereKey($candidat->id)->lockForUpdate()->firstOrFail();

            if ($verrouille->epreuve?->estVerrouillee()) {
                throw new DomainException('Épreuve verrouillée : modification du pointage interdite.');
            }

            // Une décision déjà rendue ne peut pas être rouverte par un simple
            // nouveau pointage : il faut une nouvelle demande justifiée.
            $decisionExistante = RegularisationPresence::query()
                ->where('candidat_id', $verrouille->id)
                ->whereIn('statut', [
                    RegularisationPresence::STATUT_APPROUVEE,
                    RegularisationPresence::STATUT_REJETEE,
                ])
                ->exists();

            if ($decisionExistante) {
                throw new DomainException(
                    'Une décision de régularisation a déjà été rendue pour ce candidat : un nouveau constat provisoire est interdit.'
                );
            }

            $justification = [
                'type' => 'absence_provisoire',
                'motif' => $motif ?? "Absence constatée lors de l'appel d'ouverture",
                'constate_le' => Carbon::now()->toIso8601String(),
                'auteur_id' => $auteurId,
                'statut_provisoire' => true,
            ];

            $verrouille->update([
                'statut' => 'absent_provisoire',
                'heure_arrivee' => null,
                'presence_provisoire' => true,
                'justification' => json_encode($justification, JSON_UNESCAPED_UNICODE),
                'decide_par' => $auteurId,
            ]);

            EvaluationChanged::dispatch('candidat.absence_provisoire', $verrouille->epreuve_id);

            return $verrouille;
        });
    }

    /**
     * Dépose une demande de régularisation PERSISTÉE pour justificatif tardif.
     *
     * La demande reste `en_attente` : elle ne modifie jamais le statut final
     * de présence, seule une instruction motivée le fera.
     */
    public function soumettreDemandeRegularisation(
        Candidat $candidat,
        string $motifJustificatif,
        ?string $referenceDocument = null,
        ?int $auteurId = null
    ): RegularisationPresence {
        $motif = trim($motifJustificatif);
        if ($motif === '') {
            throw new DomainException('Le motif ou descriptif du justificatif est obligatoire.');
        }

        if ($auteurId === null || User::whereKey($auteurId)->doesntExist()) {
            throw new DomainException('La demande de régularisation exige un demandeur identifié.');
        }

        return DB::transaction(function () use ($candidat, $motif, $referenceDocument, $auteurId) {
            /** @var Candidat $verrouille */
            $verrouille = Candidat::whereKey($candidat->id)->lockForUpdate()->firstOrFail();

            if ($verrouille->epreuve?->estVerrouillee()) {
                throw new DomainException('Épreuve verrouillée : dépôt de justificatif interdit.');
            }

            if (! in_array($verrouille->statut, [
                'absent_provisoire',
                'absent',
                'absent_confirme',
                'regularisation_en_cours',
                'retard',
            ], true)) {
                throw new DomainException(
                    "Demande de régularisation impossible depuis le statut « {$verrouille->statut} » : aucune absence ou retard à justifier."
                );
            }

            $dejaEnAttente = RegularisationPresence::query()
                ->where('candidat_id', $verrouille->id)
                ->where('statut', RegularisationPresence::STATUT_EN_ATTENTE)
                ->exists();

            if ($dejaEnAttente) {
                throw new DomainException('Une demande de régularisation est déjà en attente pour ce candidat.');
            }

            $demande = RegularisationPresence::create([
                'candidat_id' => $verrouille->id,
                'statut' => RegularisationPresence::STATUT_EN_ATTENTE,
                'statut_initial' => $verrouille->statut,
                'motif' => $motif,
                'preuve_reference' => $referenceDocument,
                'demande_par' => $auteurId,
                'demande_at' => now(),
            ]);

            $historique = $this->historique($verrouille->justification);
            $historique['demande_regularisation'] = [
                'regularisation_id' => $demande->id,
                'statut' => RegularisationPresence::STATUT_EN_ATTENTE,
                'motif' => $motif,
                'reference_document' => $referenceDocument,
                'soumis_le' => Carbon::now()->toIso8601String(),
                'auteur_id' => $auteurId,
            ];

            $verrouille->update([
                'statut' => 'regularisation_en_cours',
                // L'absence reste provisoire tant que la décision n'est pas rendue.
                'presence_provisoire' => true,
                'justification' => json_encode($historique, JSON_UNESCAPED_UNICODE),
            ]);

            EvaluationChanged::dispatch('candidat.regularisation_demandee', $verrouille->epreuve_id);

            return $demande;
        });
    }

    /**
     * Décision motivée d'instruction de la régularisation par un responsable habilité.
     *
     * Séparation des responsabilités : le décideur ne peut être ni le
     * demandeur, ni l'auteur du pointage initial.
     */
    public function instruireRegularisation(
        Candidat $candidat,
        string $decision,
        string $motifDecision,
        int $responsableId
    ): Candidat {
        if (! array_key_exists($decision, RegularisationPresence::DECISIONS)) {
            throw new DomainException("Décision de régularisation invalide : « {$decision} ». Seuls absent_justifie, absent_injustifie ou present sont autorisés.");
        }

        $motif = trim($motifDecision);
        if ($motif === '') {
            throw new DomainException('La motivation formelle de la décision est obligatoire.');
        }

        return DB::transaction(function () use ($candidat, $decision, $motif, $responsableId) {
            /** @var Candidat $verrouille */
            $verrouille = Candidat::whereKey($candidat->id)->lockForUpdate()->firstOrFail();

            if ($verrouille->epreuve?->estVerrouillee()) {
                throw new DomainException('Épreuve verrouillée : instruction de régularisation interdite.');
            }

            /** @var RegularisationPresence|null $demande */
            $demande = RegularisationPresence::query()
                ->where('candidat_id', $verrouille->id)
                ->where('statut', RegularisationPresence::STATUT_EN_ATTENTE)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($demande === null) {
                throw new DomainException('Aucune demande de régularisation en attente pour ce candidat.');
            }

            $this->exigerActeurHabilite($responsableId);

            if ((int) $demande->demande_par === $responsableId) {
                throw new DomainException(
                    'Séparation des responsabilités : le demandeur ne peut pas instruire sa propre demande de régularisation.'
                );
            }

            if ($verrouille->decide_par !== null && (int) $verrouille->decide_par === $responsableId) {
                throw new DomainException(
                    'Séparation des responsabilités : l’auteur du pointage ne peut pas décider de sa propre régularisation.'
                );
            }

            $historique = $this->historique($verrouille->justification);
            $historique['decision_finale'] = [
                'regularisation_id' => $demande->id,
                'decision' => $decision,
                'motivation' => $motif,
                'responsable_id' => $responsableId,
                'statue_le' => Carbon::now()->toIso8601String(),
            ];

            $verrouille->update([
                'statut' => $decision,
                'presence_provisoire' => false,
                'justification' => json_encode($historique, JSON_UNESCAPED_UNICODE),
                'decide_par' => $responsableId,
            ]);

            $demande->update([
                'statut' => RegularisationPresence::DECISIONS[$decision],
                'statut_final' => $decision,
                'decide_par' => $responsableId,
                'decide_at' => now(),
                'motif_decision' => $motif,
            ]);

            IntegrationLog::tracer(
                'evaluation',
                'internal',
                'exam.attendance.regularized',
                'completed',
                null,
                200,
                null,
                null,
                (string) Str::uuid(),
                'CAND-'.$verrouille->id
            );

            EvaluationChanged::dispatch('candidat.regularisation_statuee', $verrouille->epreuve_id);

            return $verrouille;
        });
    }

    /**
     * Vérifie et audite l'ensemble des présences d'une épreuve pour déceler les écarts bloquants.
     *
     * @return array{
     *     total_candidats: int,
     *     presents: int,
     *     retards: int,
     *     absents_provisoires: int,
     *     absents_justifies: int,
     *     absents_injustifies: int,
     *     regularisations_en_cours: int,
     *     regularisations_en_attente: int,
     *     non_pointes: int,
     *     cloture_bloquee: bool,
     *     motifs_blocage: array<int, string>
     * }
     */
    public function verifierEcartsPresences(Epreuve $epreuve): array
    {
        $candidats = $epreuve->candidats()->get();

        $demandesEnAttente = RegularisationPresence::query()
            ->whereIn('candidat_id', $candidats->pluck('id'))
            ->where('statut', RegularisationPresence::STATUT_EN_ATTENTE)
            ->count();

        $stats = [
            'total_candidats' => $candidats->count(),
            'presents' => $candidats->where('statut', 'present')->count(),
            'retards' => $candidats->where('statut', 'retard')->count(),
            'absents_provisoires' => $candidats->where('statut', 'absent_provisoire')->count(),
            'absents_justifies' => $candidats->where('statut', 'absent_justifie')->count(),
            'absents_injustifies' => $candidats->where('statut', 'absent_injustifie')->count(),
            // Un statut « en cours » sans ligne persistée ne suffit pas : on
            // retient le plus contraignant des deux compteurs.
            'regularisations_en_cours' => max(
                $candidats->where('statut', 'regularisation_en_cours')->count(),
                $demandesEnAttente
            ),
            'regularisations_en_attente' => $demandesEnAttente,
            'non_pointes' => $candidats->where('statut', Candidat::STATUT_NON_POINTE)->count(),
        ];

        $motifsBlocage = [];
        if ($stats['absents_provisoires'] > 0) {
            $motifsBlocage[] = "{$stats['absents_provisoires']} absence(s) provisoire(s) non confirmée(s) ou régularisée(s).";
        }
        if ($stats['regularisations_en_cours'] > 0) {
            $motifsBlocage[] = "{$stats['regularisations_en_cours']} demande(s) de régularisation en attente d'instruction.";
        }
        if ($stats['non_pointes'] > 0) {
            $motifsBlocage[] = "{$stats['non_pointes']} candidat(s) non pointé(s) sur la feuille d'émargement.";
        }

        return array_merge($stats, [
            'cloture_bloquee' => $motifsBlocage !== [],
            'motifs_blocage' => $motifsBlocage,
        ]);
    }

    /**
     * Normalise une heure saisie en `H:i:s` (stockage fiable, seconde près).
     */
    private function normaliserHeure(string $heure): string
    {
        $heure = trim($heure);

        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $heure, $m) !== 1) {
            throw new DomainException("Heure d'arrivée invalide : « {$heure} ». Format attendu HH:MM.");
        }

        return sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) ($m[3] ?? 0));
    }

    private function exigerActeurHabilite(int $acteurId): void
    {
        $acteur = User::find($acteurId);

        if ($acteur === null || ! $acteur->hasPermission(self::PERMISSION_INSTRUCTION)) {
            throw new DomainException(
                'Instruction de régularisation refusée : l’acteur doit être habilité ('.self::PERMISSION_INSTRUCTION.').'
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function historique(?string $justification): array
    {
        if (empty($justification)) {
            return [];
        }

        $decode = json_decode($justification, true);

        return is_array($decode) ? $decode : [];
    }
}
