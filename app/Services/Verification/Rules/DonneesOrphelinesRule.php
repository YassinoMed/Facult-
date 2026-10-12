<?php

namespace App\Services\Verification\Rules;

use App\Models\Etudiant;
use App\Models\InscriptionPedagogique;
use App\Models\Note;
use App\Services\Verification\Anomalie;
use App\Services\Verification\VerificationRule;

/**
 * R9 — Données orphelines.
 *
 * Détecte les enregistrements rattachés à l'étudiant mais
 * dont les relations parentes sont brisées ou incohérentes.
 */
class DonneesOrphelinesRule implements VerificationRule
{
    public function code(): string
    {
        return 'donnees.orphelines';
    }

    public function libelle(): string
    {
        return 'Données orphelines';
    }

    public function categorie(): string
    {
        return 'orphelin';
    }

    public function verifier(Etudiant $etudiant): array
    {
        $anomalies = [];

        // Inscriptions pédagogiques sans inscription académique parente valide.
        $inscriptionIds = $etudiant->inscriptionsAcademiques()->pluck('id');

        if ($inscriptionIds->isNotEmpty()) {
            $orphelines = InscriptionPedagogique::whereIn('inscription_id', $inscriptionIds)
                ->whereDoesntHave('inscription')
                ->count();

            if ($orphelines > 0) {
                $anomalies[] = new Anomalie(
                    regle: $this->code(),
                    categorie: $this->categorie(),
                    gravite: 'erreur',
                    message: "{$orphelines} inscription(s) pédagogique(s) orpheline(s).",
                    justification: 'Inscription(s) pédagogique(s) dont l\'inscription académique parente n\'existe plus.',
                    recommandation: 'Supprimer les inscriptions pédagogiques orphelines ou recréer l\'inscription académique.',
                    donnees: ['nombre' => $orphelines],
                );
            }
        }

        // Notes sans matière existante.
        $notesOrphelines = Note::where(function ($q) use ($etudiant) {
            // Notes via l'étudiant directement (si relation directe).
            $q->where('etudiant_id', $etudiant->id);
        })
            ->whereDoesntHave('matiere')
            ->count();

        if ($notesOrphelines > 0) {
            $anomalies[] = new Anomalie(
                regle: $this->code(),
                categorie: $this->categorie(),
                gravite: 'erreur',
                message: "{$notesOrphelines} note(s) rattachée(s) à une matière inexistante.",
                justification: 'Note(s) dont la matière référencée n\'existe plus en base.',
                recommandation: 'Vérifier les notes et corriger les rattachements matière.',
                donnees: ['nombre' => $notesOrphelines],
            );
        }

        // Documents sans année universitaire.
        $docsOrphelins = $etudiant->documentsAcademiques()
            ->whereNotNull('annee_universitaire_id')
            ->whereDoesntHave('anneeUniversitaire')
            ->count();

        if ($docsOrphelins > 0) {
            $anomalies[] = new Anomalie(
                regle: $this->code(),
                categorie: $this->categorie(),
                gravite: 'avertissement',
                message: "{$docsOrphelins} document(s) référençant une année universitaire inexistante.",
                justification: 'Document(s) dont l\'année universitaire référencée n\'existe plus.',
                recommandation: 'Corriger les rattachements ou supprimer les documents obsolètes.',
                donnees: ['nombre' => $docsOrphelins],
            );
        }

        return $anomalies;
    }
}
