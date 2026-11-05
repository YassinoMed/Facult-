<?php

namespace App\Services\Acquis;

use App\Models\Etudiant;
use App\Models\Matiere;
use App\Models\Note;
use App\Modules\ParcoursAcademique\Models\ParcoursDispense;
use App\Modules\ParcoursAcademique\Models\ParcoursEquivalence;
use DomainException;

/**
 * CONTRÔLE DES PRÉREQUIS ET DÉPENDANCES PÉDAGOGIQUES (LOOP 20).
 *
 * Vérifie rigoureusement que les conditions pédagogiques d'accès aux matières
 * sont satisfaites pour les étudiants redoublants.
 *
 * Couvre :
 * - Cas A : Prérequis acquis de plein droit (note >= 10 ou crédits propres).
 * - Cas B : Prérequis non acquis (blocage de l'inscription).
 * - Cas C : Prérequis compensé (arbitrage réglementaire).
 * - Cas D : Prérequis dispensé (visa officiel de dispense).
 * - Cas E : Prérequis modifié par réforme (mesures transitoires / équivalence).
 * - Protection anti-contournement HTTP server-side.
 */
class ControlePrerequisService
{
    public function __construct(
        private readonly ReglementationAcquisService $reglementationService,
    ) {}

    /**
     * Analyse l'éligibilité d'un étudiant à une matière au regard de ses prérequis.
     *
     * @return array{
     *     eligible: bool,
     *     cas: string,
     *     motif: string,
     *     prerequis_requis: array,
     *     prerequis_manquants: array
     * }
     */
    public function verifierEligibiliteMatiere(Etudiant $etudiant, Matiere $matiere): array
    {
        $matiere->loadMissing(['prerequis.ue', 'ue.programme.typeDiplome']);
        $prerequisList = $matiere->prerequis;

        if ($prerequisList->isEmpty()) {
            return [
                'eligible' => true,
                'cas' => 'AUCUN_PREREQUIS',
                'motif' => 'Cette matière ne comporte aucun prérequis.',
                'prerequis_requis' => [],
                'prerequis_manquants' => [],
            ];
        }

        $regime = $this->reglementationService->resoudreRegime($matiere->ue?->programme);
        $compensationValidePrerequis = (bool) ($regime['compensation_valide_prerequis'] ?? false);

        $notesHistoriques = Note::where('etudiant_id', $etudiant->id)
            ->whereNotNull('note')
            ->get();

        $dispenses = ParcoursDispense::where('etudiant_id', $etudiant->id)
            ->where('statut', 'validee')
            ->get();

        $equivalences = ParcoursEquivalence::where('etudiant_id', $etudiant->id)
            ->where('statut', 'validee')
            ->get();

        $prerequisRequis = [];
        $prerequisManquants = [];
        $tousSatisfaits = true;
        $casIdentifie = 'CAS_A_ACQUIS';

        foreach ($prerequisList as $pre) {
            $preId = (int) $pre->id;
            $preCode = $pre->code;
            $preNom = $pre->nom;

            // CAS D — Prérequis dispensé officiellement
            $dispense = $dispenses->first(fn ($d) => (int) $d->matiere_id === $preId || (int) $d->ue_id === (int) $pre->ue_id);
            if ($dispense !== null) {
                $prerequisRequis[] = [
                    'matiere_id' => $preId,
                    'code' => $preCode,
                    'nom' => $preNom,
                    'statut' => 'DISPENSE',
                    'motif' => "Dispense officielle accordée (#{$dispense->id})",
                ];
                $casIdentifie = 'CAS_D_DISPENSE';

                continue;
            }

            // CAS E — Prérequis modifié / couvert par une équivalence transitoire
            $equivalence = $equivalences->first(fn ($e) => (int) $e->cible_matiere_id === $preId || (int) $e->cible_ue_id === (int) $pre->ue_id);
            if ($equivalence !== null) {
                $prerequisRequis[] = [
                    'matiere_id' => $preId,
                    'code' => $preCode,
                    'nom' => $preNom,
                    'statut' => 'EQUIVALENCE',
                    'motif' => "Équivalence transitoire validée (#{$equivalence->id})",
                ];
                $casIdentifie = 'CAS_E_EQUIVALENCE';

                continue;
            }

            // Vérification de la note obtenue
            $notesPre = $notesHistoriques->where('matiere_id', $preId);
            $meilleureNote = $notesPre->isNotEmpty() ? (float) $notesPre->max('note') : null;

            // CAS A — Prérequis acquis de plein droit
            if ($meilleureNote !== null && $meilleureNote >= 10.0) {
                $prerequisRequis[] = [
                    'matiere_id' => $preId,
                    'code' => $preCode,
                    'nom' => $preNom,
                    'statut' => 'VALIDE',
                    'note' => $meilleureNote,
                    'motif' => "Note validante de {$meilleureNote}/20",
                ];

                continue;
            }

            // CAS C — Prérequis compensé
            $seuilElim = $regime['seuil_competence_defaut'] ?? $regime['seuil_note_eliminatoire'] ?? null;
            if ($compensationValidePrerequis && $meilleureNote !== null && ($seuilElim === null || $meilleureNote >= $seuilElim)) {
                $prerequisRequis[] = [
                    'matiere_id' => $preId,
                    'code' => $preCode,
                    'nom' => $preNom,
                    'statut' => 'COMPENSE',
                    'note' => $meilleureNote,
                    'motif' => "Prérequis réputé satisfait par compensation réglementaire (note: {$meilleureNote}/20)",
                ];
                $casIdentifie = 'CAS_C_COMPENSE';

                continue;
            }

            // CAS B — Prérequis non acquis
            $tousSatisfaits = false;
            $prerequisManquants[] = [
                'matiere_id' => $preId,
                'code' => $preCode,
                'nom' => $preNom,
                'statut' => 'NON_VALIDE',
                'note' => $meilleureNote,
                'motif' => $meilleureNote !== null
                    ? "Note insuffisante ({$meilleureNote}/20 < 10/20)"
                    : 'Matière jamais suivie ou non notée',
            ];
            $casIdentifie = 'CAS_B_NON_ACQUIS';
        }

        $motifFinal = $tousSatisfaits
            ? 'Tous les prérequis pédagogiques exigés sont officiellement validés.'
            : 'Inscription bloquée : un ou plusieurs prérequis obligatoires ne sont pas validés.';

        return [
            'eligible' => $tousSatisfaits,
            'cas' => $casIdentifie,
            'motif' => $motifFinal,
            'prerequis_requis' => $prerequisRequis,
            'prerequis_manquants' => $prerequisManquants,
        ];
    }

    /**
     * Garde-fou strict côté serveur empêchant toute inscription si les prérequis ne sont pas remplis.
     *
     * @throws DomainException
     */
    public function exigerPrerequisSatisfaits(Etudiant $etudiant, Matiere $matiere): void
    {
        $verification = $this->verifierEligibiliteMatiere($etudiant, $matiere);

        if (! $verification['eligible']) {
            $codesManquants = implode(', ', array_column($verification['prerequis_manquants'], 'code'));
            throw new DomainException(
                "Inscription interdite : les prérequis suivants pour la matière {$matiere->code} ne sont pas satisfaits [{$codesManquants}]."
            );
        }
    }
}
