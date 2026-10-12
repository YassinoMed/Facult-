<?php

namespace App\Services\Verification\Rules;

use App\Models\Etudiant;
use App\Modules\Schedule\Models\Group;
use App\Services\Verification\Anomalie;
use App\Services\Verification\VerificationRule;

/**
 * R8 — Cohérence de l'affectation groupe.
 *
 * Vérifie la cohérence entre le groupe affecté et le programme
 * de l'étudiant, et l'état du groupe.
 */
class AffectationGroupeRule implements VerificationRule
{
    public function code(): string
    {
        return 'affectation.groupe';
    }

    public function libelle(): string
    {
        return 'Affectation groupe';
    }

    public function categorie(): string
    {
        return 'affectation';
    }

    public function verifier(Etudiant $etudiant): array
    {
        $anomalies = [];

        if ($etudiant->groupe_id === null) {
            // Couvert par IdentiteCompleteRule ou PopulationStatsService,
            // mais on le signale ici si l'étudiant est validé.
            if ($etudiant->statut === 'valide') {
                $anomalies[] = new Anomalie(
                    regle: $this->code(),
                    categorie: $this->categorie(),
                    gravite: 'avertissement',
                    message: 'Étudiant validé sans groupe affecté.',
                    justification: 'groupe_id = null pour un étudiant au statut « valide ».',
                    recommandation: 'Affecter l\'étudiant à un groupe.',
                    champ: 'groupe_id',
                );
            }

            return $anomalies;
        }

        $groupe = Group::find($etudiant->groupe_id);

        if ($groupe === null) {
            $anomalies[] = new Anomalie(
                regle: $this->code(),
                categorie: $this->categorie(),
                gravite: 'erreur',
                message: 'Groupe référencé inexistant.',
                justification: "groupe_id = {$etudiant->groupe_id} mais le groupe n'existe pas en base.",
                recommandation: 'Réaffecter l\'étudiant à un groupe valide.',
                champ: 'groupe_id',
            );

            return $anomalies;
        }

        // Programme du groupe ≠ programme de l'étudiant.
        if ($groupe->programme_id !== null && $etudiant->programme_id !== null
            && $groupe->programme_id !== $etudiant->programme_id) {
            $anomalies[] = new Anomalie(
                regle: $this->code(),
                categorie: $this->categorie(),
                gravite: 'erreur',
                message: 'Le programme du groupe ne correspond pas au programme de l\'étudiant.',
                justification: "Groupe programme = {$groupe->programme_id}, étudiant programme = {$etudiant->programme_id}.",
                recommandation: 'Transférer l\'étudiant dans un groupe du bon programme.',
                donnees: ['groupe_programme' => $groupe->programme_id, 'etudiant_programme' => $etudiant->programme_id],
                champ: 'groupe_id',
            );
        }

        // Groupe inactif.
        if (isset($groupe->actif) && ! $groupe->actif) {
            $anomalies[] = new Anomalie(
                regle: $this->code(),
                categorie: $this->categorie(),
                gravite: 'avertissement',
                message: 'Étudiant affecté à un groupe inactif.',
                justification: "Groupe « {$groupe->code} » est marqué inactif.",
                recommandation: 'Réaffecter l\'étudiant à un groupe actif.',
                donnees: ['groupe_code' => $groupe->code],
            );
        }

        return $anomalies;
    }
}
