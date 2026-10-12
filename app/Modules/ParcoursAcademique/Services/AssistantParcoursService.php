<?php

namespace App\Modules\ParcoursAcademique\Services;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;

/**
 * Assistant académique (§19) : explique, résume, compare à partir
 * des services métier — jamais source de vérité, jamais de
 * décision officielle. Chaque réponse cite ses sources
 * (décisions publiées, règles versionnées, calculs).
 */
class AssistantParcoursService
{
    public function __construct(
        private readonly PlanParcoursService $plans,
        private readonly CreditParcoursService $credits,
        private readonly DetteAcademiqueService $dettes,
        private readonly RisqueAcademiqueService $risques,
    ) {}

    /**
     * @return array{resume: string, points: array<int, string>, sources: array<int, string>, avertissement: string}
     */
    public function expliquer(Etudiant $etudiant, ?InscriptionAcademique $source = null): array
    {
        $analyse = $this->plans->analyser($etudiant, $source);
        $credits = $this->credits->creditsEtudiant($etudiant);
        $risque = $this->risques->evaluer($etudiant, $source);

        $points = [
            'Niveau actuel : '.($analyse['niveau_actuel'] ?? 'inconnu').' ('.($analyse['programme'] ?? 'programme inconnu').').',
            'Crédits : '.$credits['obtenus'].'/'.$credits['requis'].' (reste '.$credits['restants'].'), moyenne '.($credits['moyenne_generale'] ?? 'non calculable').'.',
            'Dettes : '.count($analyse['dettes']).' matière(s) à repasser, origine historique conservée.',
            'Risque '.$risque['niveau'].' (score '.$risque['score'].') : '.($risque['motifs'] !== [] ? implode(' ', $risque['motifs']) : 'aucun signal.'),
        ];

        $proposition = $analyse['propositions'][0] ?? null;
        if ($proposition !== null) {
            $points[] = 'Prochaine étape envisagée : '.$proposition['scenario']
                .($proposition['niveau_cible'] ?? null ? ' vers '.$proposition['niveau_cible'] : '')
                .' (simulation, à confirmer en délibération).';
        }

        return [
            'resume' => 'Situation de '.($etudiant->nom.' '.$etudiant->prenom).' au '.now()->format('d/m/Y').'.',
            'points' => $points,
            'sources' => array_values(array_unique(array_merge(
                ['Décisions de jury publiées (evaluation_decisions)'],
                $analyse['regles']
            ))),
            'avertissement' => "L'assistant explique les données officielles ; il ne décide rien et ne remplace ni le jury ni la scolarité.",
        ];
    }
}
