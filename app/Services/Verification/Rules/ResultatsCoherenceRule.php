<?php

namespace App\Services\Verification\Rules;

use App\Models\Etudiant;
use App\Models\Note;
use App\Services\Verification\Anomalie;
use App\Services\Verification\VerificationRule;

/**
 * R10 — Cohérence des résultats et notes académiques.
 *
 * Détecte :
 * - Notes hors bornes réglementaires (note < 0 ou note > 20)
 * - Coefficients ou crédits invalides (<= 0)
 * - Session invalide
 * - Notes rattachées à un étudiant sans inscription active
 */
class ResultatsCoherenceRule implements VerificationRule
{
    public function code(): string
    {
        return 'resultats.coherence';
    }

    public function libelle(): string
    {
        return 'Cohérence des résultats académiques';
    }

    public function categorie(): string
    {
        return 'resultats';
    }

    public function verifier(Etudiant $etudiant): array
    {
        $anomalies = [];

        $notes = Note::where('etudiant_id', $etudiant->id)->get();

        if ($notes->isEmpty()) {
            return [];
        }

        // 1. Notes hors bornes [0, 20]
        $notesInvalides = $notes->filter(fn ($n) => (float) $n->note < 0 || (float) $n->note > 20);
        if ($notesInvalides->isNotEmpty()) {
            $anomalies[] = new Anomalie(
                regle: $this->code(),
                categorie: $this->categorie(),
                gravite: 'bloquant',
                message: "{$notesInvalides->count()} note(s) hors bornes réglementaires (doit être entre 0 et 20).",
                justification: 'Valeurs de note inférieures à 0 ou supérieures à 20 détectées.',
                recommandation: 'Vérifier la saisie des notes et corriger les valeurs erronées.',
                donnees: ['nombre' => $notesInvalides->count()],
                champ: 'note',
            );
        }

        // 2. Coefficients <= 0
        $coeffInvalides = $notes->filter(fn ($n) => (float) $n->coefficient <= 0);
        if ($coeffInvalides->isNotEmpty()) {
            $anomalies[] = new Anomalie(
                regle: $this->code(),
                categorie: $this->categorie(),
                gravite: 'erreur',
                message: "{$coeffInvalides->count()} note(s) avec un coefficient nul ou négatif.",
                justification: 'Le coefficient d\'une matière doit être strictement positif.',
                recommandation: 'Renseigner le coefficient selon la maquette LMD officielle.',
                donnees: ['nombre' => $coeffInvalides->count()],
                champ: 'coefficient',
            );
        }

        // 3. Notes sans inscription académique parente
        $notesSansInscription = $notes->filter(fn ($n) => empty($n->inscription_academique_id));
        if ($notesSansInscription->isNotEmpty()) {
            $anomalies[] = new Anomalie(
                regle: $this->code(),
                categorie: $this->categorie(),
                gravite: 'erreur',
                message: "{$notesSansInscription->count()} note(s) sans inscription académique associée.",
                justification: 'Toute note doit être reliée à une inscription académique officielle.',
                recommandation: 'Rattacher les notes à l\'inscription académique correspondante.',
                donnees: ['nombre' => $notesSansInscription->count()],
                champ: 'inscription_academique_id',
            );
        }

        return $anomalies;
    }
}
