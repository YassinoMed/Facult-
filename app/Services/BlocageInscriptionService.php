<?php

namespace App\Services;

use App\Models\InscriptionAcademique;
use App\Services\Verification\Anomalie;
use App\Services\Verification\VerificationEngine;

/**
 * Blocages d'inscription (mission centre de résolution) : unifie le
 * verdict du moteur intelligent + les pièces obligatoires en une fiche
 * explicable (cause, donnée, source, action) — jamais de boîte noire.
 * Lecture seule : ne modifie rien, ne valide rien.
 */
final class BlocageInscriptionService
{
    public function __construct(
        private readonly VerificationEngine $moteur,
        private readonly DocumentsRequisService $pieces,
    ) {}

    /**
     * @return array{verdict: string, score: int, bloquant: bool, items: array<int, array{code: string, gravite: string, titre: string, constat: string, recommandation: string, source: string}>}
     */
    public function blocages(InscriptionAcademique $inscription): array
    {
        $inscription->loadMissing('etudiant');
        $etudiant = $inscription->etudiant;

        $resultat = $this->moteur->verifier($etudiant);

        $items = array_map(
            fn (Anomalie $a) => [
                'code' => mb_strtoupper(str_replace('.', '_', $a->regle)),
                'gravite' => $a->gravite,
                'titre' => $a->message,
                'constat' => $a->justification,
                'recommandation' => $a->recommandation,
                'source' => "moteur:{$a->regle}",
            ],
            $resultat->anomalies
        );

        foreach ($this->bilanPieces($etudiant, $inscription) as $item) {
            $items[] = $item;
        }

        $bloquant = $resultat->verdict === 'BLOQUANT'
            || collect($items)->contains(fn (array $i) => $i['gravite'] === 'bloquant');

        return [
            'verdict' => $bloquant ? 'BLOQUANT' : $resultat->verdict,
            'score' => $resultat->score,
            'bloquant' => $bloquant,
            'items' => array_values($items),
        ];
    }

    /** @return array<int, array{code: string, gravite: string, titre: string, constat: string, recommandation: string, source: string}> */
    private function bilanPieces(mixed $etudiant, InscriptionAcademique $inscription): array
    {
        $bilan = $this->pieces->verifier($etudiant, $inscription->type ?? 'premiere');
        $items = [];

        foreach ($bilan['manquants'] as $code) {
            $items[] = $this->item('MISSING_REQUIRED_DOCUMENT', 'bloquant', "Pièce obligatoire manquante : {$code}.", "GED : aucun document de type « {$code} ».", "Déposer le document « {$code} ».", 'documents');
        }

        foreach ($bilan['rejetes'] as $code) {
            $items[] = $this->item('DOCUMENT_REJECTED', 'bloquant', "Pièce rejetée : {$code}.", 'GED : statut rejeté (voir motif).', "Remplacer le document « {$code} ».", 'documents');
        }

        foreach ($bilan['expires'] as $code) {
            $items[] = $this->item('DOCUMENT_EXPIRED', 'bloquant', "Pièce expirée : {$code}.", 'GED : validité dépassée.', "Déposer une version en cours de validité de « {$code} ».", 'documents');
        }

        foreach ($bilan['en_attente'] as $code) {
            $items[] = $this->item('DOCUMENT_PENDING_REVIEW', 'erreur', "Pièce en attente de contrôle : {$code}.", 'GED : statut en attente.', "Faire contrôler le document « {$code} » avant validation.", 'documents');
        }

        return $items;
    }

    /** @return array{code: string, gravite: string, titre: string, constat: string, recommandation: string, source: string} */
    private function item(string $code, string $gravite, string $titre, string $constat, string $recommandation, string $source): array
    {
        return compact('code', 'gravite', 'titre', 'constat', 'recommandation', 'source');
    }
}
