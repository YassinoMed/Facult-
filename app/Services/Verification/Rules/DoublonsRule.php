<?php

namespace App\Services\Verification\Rules;

use App\Models\Etudiant;
use App\Services\FicheEtudiantService;
use App\Services\Verification\Anomalie;
use App\Services\Verification\VerificationRule;

/**
 * R6 — Détection des doublons potentiels.
 *
 * Réutilise FicheEtudiantService::doublonsPotentiels()
 * (même CIN, même email/téléphone, même nom+prénom+date_naissance).
 * JAMAIS de fusion automatique — proposition de comparaison uniquement.
 */
class DoublonsRule implements VerificationRule
{
    public function __construct(
        private readonly FicheEtudiantService $ficheService,
    ) {}

    public function code(): string
    {
        return 'doublons.detection';
    }

    public function libelle(): string
    {
        return 'Doublons potentiels';
    }

    public function categorie(): string
    {
        return 'doublon';
    }

    public function verifier(Etudiant $etudiant): array
    {
        $anomalies = [];
        $doublons = $this->ficheService->doublonsPotentiels($etudiant);

        if ($doublons->isEmpty()) {
            return $anomalies;
        }

        // Vérifier si un doublon partage exactement le même CIN.
        foreach ($doublons as $doublon) {
            $cinNormEtudiant = mb_strtoupper(preg_replace('/\s+/', '', (string) $etudiant->cin) ?? '');
            $cinNormDoublon = mb_strtoupper(preg_replace('/\s+/', '', (string) $doublon->cin) ?? '');

            if ($cinNormEtudiant !== '' && $cinNormEtudiant === $cinNormDoublon) {
                $anomalies[] = new Anomalie(
                    regle: $this->code(),
                    categorie: $this->categorie(),
                    gravite: 'bloquant',
                    message: 'Doublon CIN détecté avec un autre étudiant.',
                    justification: "Un autre dossier (#{$doublon->id}) possède le même CIN.",
                    recommandation: 'Comparer les deux dossiers et fusionner ou corriger le CIN.',
                    donnees: ['doublon_id' => $doublon->id, 'critere' => 'cin'],
                );

                continue;
            }

            // Même nom+prénom+date_naissance.
            $memeIdentite = mb_strtoupper(trim((string) $doublon->nom)) === mb_strtoupper(trim((string) $etudiant->nom))
                && mb_strtoupper(trim((string) $doublon->prenom)) === mb_strtoupper(trim((string) $etudiant->prenom))
                && $doublon->date_naissance?->toDateString() === $etudiant->date_naissance?->toDateString();

            if ($memeIdentite) {
                $anomalies[] = new Anomalie(
                    regle: $this->code(),
                    categorie: $this->categorie(),
                    gravite: 'avertissement',
                    message: 'Doublon potentiel (même nom, prénom et date de naissance).',
                    justification: "Dossier #{$doublon->id} partage les mêmes informations d'identité.",
                    recommandation: 'Comparer les deux dossiers pour vérifier s\'il s\'agit du même étudiant.',
                    donnees: ['doublon_id' => $doublon->id, 'critere' => 'identite'],
                );

                continue;
            }

            // Même email ou téléphone.
            $anomalies[] = new Anomalie(
                regle: $this->code(),
                categorie: $this->categorie(),
                gravite: 'avertissement',
                message: 'Doublon potentiel (même email ou téléphone).',
                justification: "Dossier #{$doublon->id} partage un contact identique.",
                recommandation: 'Comparer les deux dossiers.',
                donnees: ['doublon_id' => $doublon->id, 'critere' => 'contact'],
            );
        }

        return $anomalies;
    }
}
