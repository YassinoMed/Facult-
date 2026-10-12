<?php

namespace App\Services\Verification\Rules;

use App\Models\Etudiant;
use App\Services\Verification\Anomalie;
use App\Services\Verification\VerificationRule;

/**
 * R7 — Anomalies chronologiques.
 *
 * Détecte les dates impossibles ou incohérentes dans le dossier :
 * date de naissance après inscription, chevauchement de situations, etc.
 */
class ChronologieRule implements VerificationRule
{
    public function code(): string
    {
        return 'chronologie.coherence';
    }

    public function libelle(): string
    {
        return 'Cohérence chronologique';
    }

    public function categorie(): string
    {
        return 'chronologie';
    }

    public function verifier(Etudiant $etudiant): array
    {
        $anomalies = [];

        // Date de naissance vs date de création du dossier.
        if ($etudiant->date_naissance !== null && $etudiant->created_at !== null) {
            if ($etudiant->date_naissance->isAfter($etudiant->created_at)) {
                $anomalies[] = new Anomalie(
                    regle: $this->code(),
                    categorie: $this->categorie(),
                    gravite: 'bloquant',
                    message: 'Date de naissance postérieure à la date de création du dossier.',
                    justification: "Naissance : {$etudiant->date_naissance->toDateString()}, création dossier : {$etudiant->created_at->toDateString()}.",
                    recommandation: 'Corriger la date de naissance.',
                    champ: 'date_naissance',
                );
            }
        }

        // Inscriptions avec dates incohérentes.
        $inscriptions = $etudiant->inscriptionsAcademiques()
            ->with('anneeUniversitaire')
            ->get();

        foreach ($inscriptions as $inscription) {
            if ($inscription->created_at !== null && $etudiant->date_naissance !== null) {
                $ageAInscription = $etudiant->date_naissance->diffInYears($inscription->created_at);

                if ($ageAInscription < 15) {
                    $anomalies[] = new Anomalie(
                        regle: $this->code(),
                        categorie: $this->categorie(),
                        gravite: 'erreur',
                        message: "Âge improbable à la date d'inscription ({$ageAInscription} ans).",
                        justification: "Moins de 15 ans au moment de l'inscription ({$inscription->anneeUniversitaire?->libelle}).",
                        recommandation: 'Vérifier la date de naissance ou la date d\'inscription.',
                        donnees: ['age_inscription' => $ageAInscription],
                    );
                }
            }
        }

        // Situations qui se chevauchent (même type, périodes superposées).
        $situations = $etudiant->situations()->orderBy('date_effet')->get();
        $parType = $situations->groupBy('type');

        foreach ($parType as $type => $situationsType) {
            $precedente = null;

            foreach ($situationsType as $situation) {
                if ($precedente !== null
                    && $precedente->date_fin !== null
                    && $situation->date_effet !== null
                    && $situation->date_effet->isBefore($precedente->date_fin)) {
                    $anomalies[] = new Anomalie(
                        regle: $this->code(),
                        categorie: $this->categorie(),
                        gravite: 'avertissement',
                        message: "Chevauchement de situations de type « {$type} ».",
                        justification: "Situation #{$precedente->id} (fin : {$precedente->date_fin->toDateString()}) chevauche la situation #{$situation->id} (début : {$situation->date_effet->toDateString()}).",
                        recommandation: 'Vérifier les dates des situations et corriger le chevauchement.',
                    );
                }
                $precedente = $situation;
            }
        }

        return $anomalies;
    }
}
