<?php

namespace App\Modules\Evaluation\Services;

use App\Models\IntegrationLog;
use App\Modules\Evaluation\Events\EvaluationChanged;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\EvaluationIncident;
use App\Modules\Evaluation\Models\NoteEpreuve;
use App\Modules\Evaluation\Models\NoteEpreuveHistorique;
use App\Modules\Evaluation\Models\SessionExamen;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Service de Clôture, Corrections post-clôture et Réouverture formelle (LOT G - §2.13, §2.14, §2.17).
 *
 * Règles métier absolues :
 * 1. Les éléments bloquants (incidents critiques non résolus, écarts de présence, non pointés) INTERDISENT la clôture.
 * 2. Correction post-clôture : demande motivée, preuves, validation 4 yeux, création d'une NOUVELLE trace d'audit
 *    sans JAMAIS écraser la valeur antérieure ni altérer l'historique.
 * 3. Réouverture de session : demande motivée, approbation par personne distincte, nouvelle version, audit complet.
 */
class ExamClotureCorrectionService
{
    public function __construct(
        private ExamPresenceRegularisationService $presenceService
    ) {}

    /**
     * Vérifie l'ensemble des prérequis et des éléments bloquants avant la clôture officielle d'une épreuve.
     *
     * @return array{
     *     peut_cloturer: bool,
     *     epreuve_id: int,
     *     incidents_critiques_non_resolus: int,
     *     ecarts_presences: array<string, mixed>,
     *     bloquants: array<int, string>
     * }
     */
    public function auditerPreRequisCloture(Epreuve $epreuve): array
    {
        $epreuve->refresh();
        $bloquants = [];

        // 1. Contrôle des présences et émargements
        $auditPresences = $this->presenceService->verifierEcartsPresences($epreuve);
        if ($auditPresences['cloture_bloquee']) {
            foreach ($auditPresences['motifs_blocage'] as $motif) {
                $bloquants[] = "Émargement : {$motif}";
            }
        }

        // 2. Contrôle des incidents critiques ou majeurs en suspens
        $incidentsNonClos = EvaluationIncident::where('epreuve_id', $epreuve->id)
            ->whereIn('statut', ['signale', 'en_cours'])
            ->get();

        $incidentsCritiquesCount = 0;
        foreach ($incidentsNonClos as $inc) {
            $desc = json_decode((string) $inc->description, true);
            $gravite = $inc->gravite
                ?: (is_array($desc) ? ($desc['gravite'] ?? 'mineure') : 'mineure');
            if (in_array($gravite, ['critique', 'majeure'], true)) {
                $incidentsCritiquesCount++;
                $bloquants[] = "Incident {$gravite} non résolu (#{$inc->id}) : « {$inc->type} » en attente de clôture.";
            }
        }

        return [
            'peut_cloturer' => $bloquants === [],
            'epreuve_id' => $epreuve->id,
            'incidents_critiques_non_resolus' => $incidentsCritiquesCount,
            'ecarts_presences' => $auditPresences,
            'bloquants' => $bloquants,
        ];
    }

    /**
     * Clôture et verrouille formellement une épreuve d'examen après vérification des bloquants.
     */
    public function cloturerEpreuve(Epreuve $epreuve, int $responsableId): Epreuve
    {
        return DB::transaction(function () use ($epreuve) {
            $epreuve->refresh();

            if ($epreuve->estVerrouillee()) {
                throw new DomainException("L'épreuve est déjà clôturée et verrouillée.");
            }

            $audit = $this->auditerPreRequisCloture($epreuve);
            if (! $audit['peut_cloturer']) {
                $details = implode(' ; ', $audit['bloquants']);
                throw new DomainException("Clôture impossible : éléments bloquants non résolus ({$details}).");
            }

            $epreuve->statut = 'locked';
            $epreuve->save();

            IntegrationLog::tracer(
                'evaluation',
                'internal',
                'exam.epreuve.closed_and_locked',
                'completed',
                null,
                200,
                null,
                null,
                (string) Str::uuid(),
                'EPR-'.$epreuve->id
            );

            EvaluationChanged::dispatch('epreuve.cloturee', $epreuve->id);

            return $epreuve;
        });
    }

    /**
     * Procédure de correction post-clôture officielle (Règle 2.14).
     *
     * Demande motivée avec preuves, validation par une personne distincte (4 yeux)
     * et traçabilité immuable dans l'historique sans jamais écraser l'ancienne valeur.
     *
     * @param array{
     *     note_epreuve_id: int,
     *     nouvelle_valeur: float,
     *     motif_formel: string,
     *     demandeur_id: int,
     *     valideur_distinct_id: int,
     *     references_preuves?: array<int, string>
     * } $data
     */
    public function corrigerNotePostCloture(array $data): NoteEpreuve
    {
        $motif = trim($data['motif_formel']);
        if ($motif === '') {
            throw new DomainException('Le motif formel de correction post-clôture est obligatoire.');
        }

        // Règle 4-yeux : Le valideur doit être distinct du demandeur
        if ($data['demandeur_id'] === $data['valideur_distinct_id']) {
            throw new DomainException('Séparation des responsabilités : la validation de correction post-clôture exige une personne distincte du demandeur.');
        }

        $note = NoteEpreuve::findOrFail($data['note_epreuve_id']);

        return DB::transaction(function () use ($note, $data, $motif) {
            $note->refresh();
            $ancienneValeur = $note->valeur;
            $nouvelleValeur = (float) $data['nouvelle_valeur'];

            // 1. Création de la trace d'audit immuable dans l'historique
            NoteEpreuveHistorique::create([
                'evaluation_note_id' => $note->id,
                'ancienne_valeur' => $ancienneValeur,
                'nouvelle_valeur' => $nouvelleValeur,
                'motif' => "Correction post-clôture validée [Demandeur: #{$data['demandeur_id']}, Valideur: #{$data['valideur_distinct_id']}] : {$motif}",
                'auteur_id' => $data['valideur_distinct_id'],
                'created_at' => Carbon::now(),
            ]);

            // 2. Application de la nouvelle valeur ciblée
            $note->update([
                'valeur' => $nouvelleValeur,
                'statut' => 'validated', // Maintient le statut validé
            ]);

            IntegrationLog::tracer(
                'evaluation',
                'internal',
                'exam.note.post_closure_corrected',
                'completed',
                null,
                200,
                null,
                null,
                (string) Str::uuid(),
                'NOTE-'.$note->id
            );

            EvaluationChanged::dispatch('note.corrigee_post_cloture', $note->id);

            return $note;
        });
    }

    /**
     * Réouverture formelle d'une session clôturée (Règle 2.17).
     *
     * Exige : demande motivée, justification/preuves, approbation par personne distincte,
     * incrémentation de version et audit complet.
     */
    public function rouvrirSessionCloturee(
        SessionExamen $session,
        string $motifReouverture,
        int $demandeurId,
        int $approbateurDistinctId,
        ?array $justifications = null
    ): SessionExamen {
        $motif = trim($motifReouverture);
        if ($motif === '') {
            throw new DomainException('Le motif de réouverture de session est obligatoire.');
        }

        if ($demandeurId === $approbateurDistinctId) {
            throw new DomainException("Violation de séparation des responsabilités : l'approbateur de réouverture doit être distinct du demandeur.");
        }

        return DB::transaction(function () use ($session, $motif, $demandeurId, $approbateurDistinctId, $justifications) {
            $session->refresh();

            if (! in_array($session->statut, ['closed', 'locked'], true)) {
                throw new DomainException("Seule une session clôturée ou verrouillée peut faire l'objet d'une réouverture.");
            }

            $regles = $session->regles ?? [];
            $historiqueReouvertures = $regles['reouvertures'] ?? [];

            $historiqueReouvertures[] = [
                'reouverture_id' => (string) Str::uuid(),
                'statut_precedent' => $session->statut,
                'motif' => $motif,
                'demandeur_id' => $demandeurId,
                'approbateur_id' => $approbateurDistinctId,
                'justifications' => $justifications ?? [],
                'reouvert_at' => Carbon::now()->toIso8601String(),
            ];

            $regles['reouvertures'] = $historiqueReouvertures;
            $regles['version'] = ((int) ($regles['version'] ?? 1)) + 1;

            $session->statut = 'open';
            $session->verrouillee_at = null;
            $session->regles = $regles;
            $session->save();

            IntegrationLog::tracer(
                'evaluation',
                'internal',
                'exam.session.reopened',
                'completed',
                null,
                200,
                null,
                null,
                (string) Str::uuid(),
                'SESS-'.$session->id
            );

            EvaluationChanged::dispatch('session.reouverte', $session->id);

            return $session;
        });
    }
}
