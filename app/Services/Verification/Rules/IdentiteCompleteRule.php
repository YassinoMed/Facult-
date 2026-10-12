<?php

namespace App\Services\Verification\Rules;

use App\Models\Etudiant;
use App\Services\Verification\Anomalie;
use App\Services\Verification\VerificationRule;

/**
 * R1 — Vérifie la complétude des données d'identité obligatoires.
 *
 * Champs critiques : nom, prénom, (CIN ou email), date_naissance,
 * sexe, faculte_id.
 */
class IdentiteCompleteRule implements VerificationRule
{
    public function code(): string
    {
        return 'identite.complete';
    }

    public function libelle(): string
    {
        return 'Identité complète';
    }

    public function categorie(): string
    {
        return 'identite';
    }

    public function verifier(Etudiant $etudiant): array
    {
        $anomalies = [];

        // Nom + prénom : bloquant si absents.
        if (empty($etudiant->nom)) {
            $anomalies[] = new Anomalie(
                regle: $this->code(),
                categorie: $this->categorie(),
                gravite: 'bloquant',
                message: 'Nom de famille manquant.',
                justification: 'Le champ « nom » est vide ou null.',
                recommandation: 'Compléter le nom de famille dans le dossier.',
                champ: 'nom',
            );
        }

        if (empty($etudiant->prenom)) {
            $anomalies[] = new Anomalie(
                regle: $this->code(),
                categorie: $this->categorie(),
                gravite: 'bloquant',
                message: 'Prénom manquant.',
                justification: 'Le champ « prenom » est vide ou null.',
                recommandation: 'Compléter le prénom dans le dossier.',
                champ: 'prenom',
            );
        }

        // CIN ou email : au moins un identifiant.
        if (empty($etudiant->cin) && empty($etudiant->email)) {
            $anomalies[] = new Anomalie(
                regle: $this->code(),
                categorie: $this->categorie(),
                gravite: 'erreur',
                message: 'Aucun identifiant (CIN ni email) renseigné.',
                justification: 'Les champs « cin » et « email » sont tous deux vides.',
                recommandation: 'Renseigner au minimum le CIN ou l\'adresse email.',
                champ: 'cin',
            );
        }

        if ($etudiant->date_naissance === null) {
            $anomalies[] = new Anomalie(
                regle: $this->code(),
                categorie: $this->categorie(),
                gravite: 'erreur',
                message: 'Date de naissance manquante.',
                justification: 'Le champ « date_naissance » est null.',
                recommandation: 'Renseigner la date de naissance.',
                champ: 'date_naissance',
            );
        }

        if (empty($etudiant->sexe)) {
            $anomalies[] = new Anomalie(
                regle: $this->code(),
                categorie: $this->categorie(),
                gravite: 'avertissement',
                message: 'Sexe non renseigné.',
                justification: 'Le champ « sexe » est vide ou null.',
                recommandation: 'Compléter le champ sexe (M/F).',
                champ: 'sexe',
            );
        }

        if ($etudiant->faculte_id === null) {
            $anomalies[] = new Anomalie(
                regle: $this->code(),
                categorie: $this->categorie(),
                gravite: 'erreur',
                message: 'Faculté non rattachée.',
                justification: 'Le champ « faculte_id » est null.',
                recommandation: 'Rattacher l\'étudiant à une faculté.',
                champ: 'faculte_id',
            );
        }

        return $anomalies;
    }
}
