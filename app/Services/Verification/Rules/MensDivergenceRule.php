<?php

namespace App\Services\Verification\Rules;

use App\Models\Etudiant;
use App\Services\IdentificationIntelligenteService;
use App\Services\Verification\Anomalie;
use App\Services\Verification\VerificationRule;

/**
 * R12 — Divergences MENS ↔ dossier local (mission MENS, LOT 1 B5) :
 * identité divergente = à vérifier ; conflit d'identité (deux dossiers
 * locaux au même CIN) = bloquant. Lecture seule : la RÉSOLUTION passe
 * par le workflow 4-eyes (corrections proposer → approuver → appliquer),
 * jamais par un écrasement automatique des données locales.
 */
class MensDivergenceRule implements VerificationRule
{
    public function __construct(
        private readonly IdentificationIntelligenteService $identification,
    ) {}

    public function code(): string
    {
        return 'mens.divergences';
    }

    public function libelle(): string
    {
        return 'Divergences avec le référentiel national (MENS)';
    }

    public function categorie(): string
    {
        return 'identite';
    }

    public function verifier(Etudiant $etudiant): array
    {
        $divergences = $this->identification->divergencesDossier($etudiant);

        if ($divergences === []) {
            return [];
        }

        $anomalies = [];

        foreach ($divergences as $divergence) {
            $bloquant = $divergence['gravite'] === 'BLOQUANT';

            $anomalies[] = new Anomalie(
                regle: $this->code(),
                categorie: $this->categorie(),
                gravite: $bloquant ? 'bloquant' : 'avertissement',
                message: $bloquant
                    ? 'Conflit d’identité : plusieurs dossiers locaux partagent ce CIN.'
                    : "Divergence MENS sur « {$divergence['champ']} » : valeur locale différente du référentiel national.",
                justification: 'Référentiel MENS importé officiel — comparaison sur CIN normalisé.',
                recommandation: 'Arbitrage administratif obligatoire via la correction à quatre yeux (jamais d’écrasement automatique).',
                donnees: ['champ' => $divergence['champ'], 'gravite' => $divergence['gravite']],
                champ: $divergence['champ'] === 'CIN' ? 'cin' : match ($divergence['champ']) {
                    'prénom' => 'prenom', default => $divergence['champ']
                },
            );
        }

        return $anomalies;
    }
}
