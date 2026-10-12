<?php

namespace App\Services\Documents;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Models\SituationEtudiant;
use App\Modules\Attendance\Models\Presence;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Finances\Models\Facture;
use App\Modules\Finances\Models\Paiement;
use App\Modules\StudentDossiers\Services\Pdf\OfficialDocumentService;
use App\Services\InscriptionService;
use DomainException;

/**
 * Éligibilité documentaire (LOT 2, PHASE 8) : moteur central de
 * vérification des conditions métier AVANT toute génération officielle.
 *
 * Chaque type du catalogue `documents-officiels.types` déclare ses
 * règles (`decision_requise`, `inscription_active_requise`,
 * `dossier_valide_requis`, `fin_cycle_requise`, `presence_requise`,
 * `paiement_confirme_requis`, `abandon_requis`, `transfert_requis`,
 * `inscription_valide_requise`). `verifier()` retourne la checklist
 * (satisfaits + blocages codés) réutilisée par l'UI « Générer » et par
 * `OfficialDocumentService::generer()` (qui refuse sur le 1er blocage).
 *
 * Aucune écriture, aucune génération : lecture seule.
 */
final class DocumentEligibiliteService
{
    public function __construct(
        private readonly InscriptionService $inscriptions,
    ) {}

    /**
     * @param  array{paiement_id?: int}  $options
     * @return array{eligible: bool, satisfaits: array<int, string>, blocages: array<int, array{code: string, message: string}>, decision: ?DecisionDeliberation, paiement: ?Paiement, transfert: ?SituationEtudiant}
     */
    public function verifier(Etudiant $etudiant, string $type, ?InscriptionAcademique $inscription = null, array $options = []): array
    {
        $definition = OfficialDocumentService::catalogue()[$type] ?? null;

        if ($definition === null) {
            throw new DomainException("Type de document « {$type} » hors catalogue.");
        }

        $satisfaits = [];
        $blocages = [];
        $decision = null;
        $paiement = null;
        $transfert = null;

        if (($definition['inscription_active_requise'] ?? false) || ($definition['inscription_valide_requise'] ?? false)) {
            if ($inscription === null) {
                $blocages[] = ['code' => 'INSCRIPTION_MANQUANTE', 'message' => 'Document refusé : inscription introuvable.'];
            } elseif (($definition['inscription_valide_requise'] ?? false) && $inscription->statut !== 'valide') {
                $blocages[] = ['code' => 'INSCRIPTION_NON_VALIDEE', 'message' => "Certificat refusé : inscription « {$inscription->statut} » non validée (validation administrative requise)."];
            } elseif (! in_array($inscription->statut, ['en_cours', 'valide'], true)) {
                $blocages[] = ['code' => 'INSCRIPTION_INACTIVE', 'message' => "Attestation refusée : inscription « {$inscription->statut} » non active."];
            } else {
                $satisfaits[] = "Inscription {$inscription->statut} ({$inscription->anneeUniversitaire?->libelle})";
            }
        }

        if ($definition['dossier_valide_requis'] ?? false) {
            if ($etudiant->statut !== 'valide') {
                $blocages[] = ['code' => 'DOSSIER_NON_VALIDE', 'message' => "Document refusé : dossier étudiant « {$etudiant->statut} » non validé."];
            } else {
                $satisfaits[] = 'Dossier étudiant validé';
            }
        }

        // Décision résolue systématiquement pour l'affichage (les relevés
        // l'impriment quand elle existe) ; exigée seulement si le type
        // déclare `decision_requise`.
        $decision = $inscription === null ? null : DecisionDeliberation::where('inscription_academique_id', $inscription->id)
            ->where('publie', true)->latest('id')->first();

        if (($definition['decision_requise'] ?? null) !== null) {
            if ($decision === null || ! in_array($decision->decision, $definition['decision_requise'], true)) {
                $blocages[] = ['code' => 'DECISION_MANQUANTE', 'message' => 'Attestation refusée : aucune décision publiée requise pour cette inscription.'];
            } else {
                $satisfaits[] = "Décision publiée : {$decision->decision}".($decision->moyenne !== null ? ' ('.number_format((float) $decision->moyenne, 2, ',', ' ').'/20)' : '');
            }
        }

        if ($definition['fin_cycle_requise'] ?? false) {
            // Sémantique exacte de ciblePassage : niveauApres renvoie le
            // MÊME niveau en fin de cycle (jamais réinventée ici).
            $niveauSuivant = $inscription === null ? null : $this->inscriptions->niveauApres($inscription->niveau_id);

            if ($inscription === null || ($niveauSuivant !== null && (int) $niveauSuivant->id !== (int) $inscription->niveau_id)) {
                $blocages[] = ['code' => 'FIN_CYCLE_NON_ATTEINTE', 'message' => 'Diplôme refusé : l’inscription n’est pas en fin de cycle (niveau suivant existant).'];
            } else {
                $satisfaits[] = 'Fin de cycle confirmée';
            }
        }

        if ($definition['presence_requise'] ?? false) {
            $seances = Presence::where('etudiant_id', $etudiant->id)->count();

            if ($seances === 0) {
                $blocages[] = ['code' => 'PRESENCE_ABSENTE', 'message' => 'Attestation refusée : aucune présence enregistrée pour cet étudiant.'];
            } else {
                $satisfaits[] = "Présences enregistrées ({$seances} séance(s))";
            }
        }

        if ($definition['paiement_confirme_requis'] ?? false) {
            $paiementId = $options['paiement_id'] ?? null;

            if ($paiementId !== null) {
                $paiement = Paiement::whereKey($paiementId)->with('facture')->first();

                if ($paiement === null || (int) $paiement->facture?->etudiant_id !== (int) $etudiant->id) {
                    $paiement = null;
                    $blocages[] = ['code' => 'PAIEMENT_INTROUVABLE', 'message' => 'Quittance refusée : paiement introuvable pour cet étudiant.'];
                }
            } else {
                $paiement = Paiement::where('statut', 'confirmed')
                    ->whereHas('facture', fn ($q) => $q->where('etudiant_id', $etudiant->id))
                    ->with('facture')->latest('id')->first();

                if ($paiement === null) {
                    $blocages[] = ['code' => 'PAIEMENT_MANQUANT', 'message' => 'Quittance refusée : aucun paiement confirmé pour cet étudiant.'];
                }
            }

            if ($paiement !== null && $paiement->statut !== 'confirmed') {
                $blocages[] = ['code' => 'PAIEMENT_NON_CONFIRME', 'message' => "Quittance refusée : paiement « {$paiement->reference} » non confirmé (statut : {$paiement->statut})."];
                $paiement = null;
            }

            if ($paiement !== null) {
                $satisfaits[] = 'Paiement confirmé : '.Facture::formater((int) $paiement->montant_centimes)." ({$paiement->methode})";
            }
        }

        if ($definition['abandon_requis'] ?? false) {
            if ($inscription === null || $inscription->statut !== 'abandon') {
                $blocages[] = ['code' => 'ABANDON_NON_CONSTATE', 'message' => 'Attestation refusée : inscription non en abandon (statut : '.($inscription?->statut ?? 'introuvable').').'];
            } else {
                $satisfaits[] = "Abandon constaté ({$inscription->anneeUniversitaire?->libelle})";
            }
        }

        if ($definition['transfert_requis'] ?? false) {
            $transfert = SituationEtudiant::where('etudiant_id', $etudiant->id)
                ->where('type', 'transfert')->latest('id')->first();

            if ($transfert === null) {
                $blocages[] = ['code' => 'TRANSFERT_NON_CONSTATE', 'message' => 'Attestation refusée : aucun transfert enregistré pour cet étudiant.'];
            } else {
                $satisfaits[] = "Transfert enregistré ({$transfert->ancienne_valeur} → {$transfert->nouvelle_valeur})";
            }
        }

        return [
            'eligible' => $blocages === [],
            'satisfaits' => $satisfaits,
            'blocages' => $blocages,
            'decision' => $decision,
            'paiement' => $paiement,
            'transfert' => $transfert,
        ];
    }

    /**
     * Statistiques de présence pour les gabarits (même formule que
     * `AttendanceService::tauxPresence` : présents + retards / séances,
     * arrondi au dixième, null si aucune séance).
     *
     * @return array{seances: int, taux: ?float}
     */
    public function statistiquesPresence(Etudiant $etudiant): array
    {
        $seances = Presence::where('etudiant_id', $etudiant->id)->count();

        if ($seances === 0) {
            return ['seances' => 0, 'taux' => null];
        }

        $presents = Presence::where('etudiant_id', $etudiant->id)
            ->whereIn('statut', ['present', 'retard'])->count();

        return ['seances' => $seances, 'taux' => round($presents / $seances * 100, 1)];
    }
}
