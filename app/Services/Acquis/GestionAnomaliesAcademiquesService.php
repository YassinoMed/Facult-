<?php

namespace App\Services\Acquis;

use App\Models\Etudiant;
use App\Models\HistoriqueAcademique;
use App\Models\Note;
use App\Models\User;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * GESTIONNAIRE CENTRAL DES ANOMALIES ACADÉMIQUES ET CORRECTIONS AUDITÉES (LOOP 24).
 *
 * Détecte, catégorise, explique et orchestre les corrections autorisées.
 * Interdit formellement toute correction silencieuse :
 * - Motif obligatoire.
 * - Ancienne valeur et nouvelle valeur tracées.
 * - Source officielle exigée.
 * - Re-contrôle automatique des acquis dépendants.
 */
class GestionAnomaliesAcademiquesService
{
    public const GRAVITE_MINEURE = 'MINEURE';

    public const GRAVITE_MOYENNE = 'MOYENNE';

    public const GRAVITE_CRITIQUE = 'CRITIQUE';

    public const GRAVITE_BLOQUANTE = 'BLOQUANTE';

    public function __construct(
        private readonly MoteurDecisionAcademiqueService $moteurDecision,
    ) {}

    /**
     * Analyse et inventorie exhaustivement les anomalies sur un dossier étudiant.
     *
     * @return array<int, array{
     *     code: string,
     *     gravite: string,
     *     constat: string,
     *     explication: string,
     *     action_recommandee: string,
     *     resoluble_automatiquement: bool
     * }>
     */
    public function detecterAnomalies(Etudiant $etudiant): array
    {
        $anomalies = [];

        // 1. Détection de délibération non publiée ou absente
        $deliberationPubliee = DecisionDeliberation::where('etudiant_id', $etudiant->id)
            ->where('publie', true)
            ->exists();

        if (! $deliberationPubliee) {
            $anomalies[] = [
                'code' => 'DELIBERATION_NON_PUBLIEE',
                'gravite' => self::GRAVITE_BLOQUANTE,
                'constat' => 'Aucune délibération officielle publiée n\'a été trouvée pour cet étudiant.',
                'explication' => 'Le calcul ne peut accorder de crédits définitifs sur la base de résultats provisoires non délibérés.',
                'action_recommandee' => 'Attendre ou provoquer la publication officielle du procès-verbal de jury.',
                'resoluble_automatiquement' => false,
            ];
        }

        // 2. Détection de notes hors barème (> 20 ou < 0)
        $notesInvalides = Note::where('etudiant_id', $etudiant->id)
            ->where(fn ($q) => $q->where('note', '<', 0)->orWhere('note', '>', 20))
            ->get();

        foreach ($notesInvalides as $noteInv) {
            $anomalies[] = [
                'code' => 'NOTE_HORS_BAREME',
                'gravite' => self::GRAVITE_BLOQUANTE,
                'constat' => "Note invalide détectée : {$noteInv->note}/20 sur la matière #{$noteInv->matiere_id}.",
                'explication' => 'Une note doit être impérativement comprise entre 0.00 et 20.00.',
                'action_recommandee' => 'Rectifier la note saisie avec visa du responsable de matière.',
                'resoluble_automatiquement' => false,
            ];
        }

        // 3. Détection des notes éliminatoires (< seuil réglementaire)
        $notes = Note::where('etudiant_id', $etudiant->id)->whereNotNull('note')->get();
        foreach ($notes as $n) {
            if ((float) $n->note < 6.0) {
                $anomalies[] = [
                    'code' => 'SEUIL_ELIMINATOIRE_ATTEINT',
                    'gravite' => self::GRAVITE_CRITIQUE,
                    'constat' => "Note éliminatoire de {$n->note}/20 sur la matière #{$n->matiere_id}.",
                    'explication' => 'Conformément à l\'Art. 13 de l\'Arrêté du 7 juillet 2023, cette note bloque toute compensation de l\'UE.',
                    'action_recommandee' => 'Repasser impérativement l\'ECUE lors de la prochaine session.',
                    'resoluble_automatiquement' => false,
                ];
            }
        }

        // 4. Détection des discordances de crédits
        $decision = $this->moteurDecision->determinerDecision($etudiant);
        foreach ($decision['anomalies'] as $ano) {
            $anomalies[] = [
                'code' => 'ANOMALIE_MOTEUR_DECISION',
                'gravite' => self::GRAVITE_MOYENNE,
                'constat' => $ano,
                'explication' => 'Discordance identifiée par le moteur central de décision.',
                'action_recommandee' => 'Contrôler la maquette et le rattachement des éléments constitutifs.',
                'resoluble_automatiquement' => false,
            ];
        }

        return $anomalies;
    }

    /**
     * Applique une correction formelle et auditée sur une note ou un résultat.
     * Déclenche immédiatement un recontrôle des acquis dépendants.
     *
     * @return array{
     *     succes: bool,
     *     ancienne_valeur: float,
     *     nouvelle_valeur: float,
     *     impact_acquis: array
     * }
     */
    public function corrigerNoteAvecAudit(
        Note $note,
        float $nouvelleNote,
        int $acteurId,
        string $motifOfficiel,
        string $referencePvRectificatif
    ): array {
        if ($nouvelleNote < 0.0 || $nouvelleNote > 20.0) {
            throw new DomainException("Note rectifiée invalide : {$nouvelleNote}/20.");
        }

        if (trim($motifOfficiel) === '' || trim($referencePvRectificatif) === '') {
            throw new DomainException(
                'Rejet de la correction : le motif et la référence du PV rectificatif sont obligatoires pour toute modification d\'évaluation.'
            );
        }

        $ancienneNote = (float) $note->note;
        $etudiant = $note->etudiant;

        return DB::transaction(function () use ($note, $nouvelleNote, $ancienneNote, $etudiant, $acteurId, $motifOfficiel, $referencePvRectificatif) {
            $userValide = User::where('id', $acteurId)->exists();
            $acteurFinal = $userValide ? $acteurId : null;

            // Application de la correction sur la note
            $note->update([
                'note' => $nouvelleNote,
                'observation' => "Rectification officielle : {$motifOfficiel} (Réf : {$referencePvRectificatif})",
            ]);

            // Audit dans HistoriqueAcademique
            HistoriqueAcademique::create([
                'etudiant_id' => $etudiant->id,
                'utilisateur_id' => $acteurFinal,
                'action' => 'rectification_officielle_note',
                'entite' => 'notes',
                'entite_id' => $note->id,
                'ancienne_valeur' => (string) $ancienneNote,
                'nouvelle_valeur' => json_encode([
                    'nouvelle_note' => $nouvelleNote,
                    'motif' => $motifOfficiel,
                    'pv_reference' => $referencePvRectificatif,
                    'horodatage' => now()->toIso8601String(),
                ], JSON_UNESCAPED_UNICODE),
                'source_systeme' => 'audit_anomalies',
            ]);

            // Re-contrôle automatique des acquis dépendants post-correction
            $reanalyse = $this->moteurDecision->determinerDecision($etudiant);

            return [
                'succes' => true,
                'ancienne_valeur' => $ancienneNote,
                'nouvelle_valeur' => $nouvelleNote,
                'impact_acquis' => [
                    'nouveau_total_credits' => $reanalyse['credits']['capitalises'],
                    'elements_conserves_count' => count($reanalyse['elements_conserves']),
                    'elements_a_reprendre_count' => count($reanalyse['elements_a_reprendre']),
                ],
            ];
        });
    }
}
