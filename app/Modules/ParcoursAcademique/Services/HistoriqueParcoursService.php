<?php

namespace App\Modules\ParcoursAcademique\Services;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Models\SituationEtudiant;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\ParcoursAcademique\Models\ParcoursDispense;
use App\Modules\ParcoursAcademique\Models\ParcoursEquivalence;
use App\Modules\ParcoursAcademique\Models\ParcoursPasserelle;
use App\Modules\ParcoursAcademique\Models\ParcoursReorientation;
use App\Modules\ParcoursAcademique\Models\ParcoursSimulation;

/**
 * Historique académique complet (§13) : vision chronologique
 * reconstruite depuis les sources officielles + module.
 * Traçable, jamais détruite par les modifications futures
 * (lecture seule, aucune suppression).
 */
class HistoriqueParcoursService
{
    /**
     * @return array<int, array{date: ?string, type: string, libelle: string, detail: array<string, mixed>}>
     */
    public function chronologie(Etudiant $etudiant, ?string $type = null): array
    {
        $evenements = [];

        $inscriptions = InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->with(['anneeUniversitaire', 'programmeRef', 'niveauRef', 'semestres'])
            ->orderBy('annee_universitaire_id')->get();

        foreach ($inscriptions as $inscription) {
            $evenements[] = [
                'date' => $inscription->anneeUniversitaire?->date_debut,
                'type' => 'inscription',
                'libelle' => ($inscription->anneeUniversitaire?->libelle ?? '').' — '.($inscription->niveauRef?->code ?? $inscription->niveau ?? ''),
                'detail' => [
                    'programme' => $inscription->programmeRef?->nom,
                    'statut' => $inscription->statut,
                    'moyenne' => $inscription->moyenne_annuelle,
                    'credits' => $inscription->credits_obtenus.'/'.$inscription->credits_total,
                ],
            ];

            foreach ($inscription->semestres as $semestre) {
                $evenements[] = [
                    'date' => $inscription->anneeUniversitaire?->date_debut,
                    'type' => 'semestre',
                    'libelle' => 'Semestre '.$semestre->numero.' — '.($semestre->decision ?? 'sans décision'),
                    'detail' => ['moyenne' => $semestre->moyenne, 'credits' => $semestre->credits_obtenus.'/'.$semestre->credits_total],
                ];
            }
        }

        $decisions = DecisionDeliberation::where('etudiant_id', $etudiant->id)->orderBy('id')->get();
        foreach ($decisions as $decision) {
            $evenements[] = [
                'date' => $decision->created_at?->toDateString(),
                'type' => 'deliberation',
                'libelle' => 'Décision jury : '.$decision->decision.($decision->publie ? ' (publiée)' : ' (non publiée)'),
                'detail' => ['inscription_id' => $decision->inscription_academique_id],
            ];
        }

        $situations = SituationEtudiant::where('etudiant_id', $etudiant->id)->orderBy('id')->get();
        foreach ($situations as $situation) {
            $evenements[] = [
                'date' => $situation->created_at?->toDateString(),
                'type' => 'situation',
                'libelle' => 'Transition : '.($situation->statut ?? $situation->action ?? 'mise à jour'),
                'detail' => [],
            ];
        }

        foreach (ParcoursEquivalence::where('etudiant_id', $etudiant->id)->orderBy('id')->get() as $eq) {
            $evenements[] = [
                'date' => $eq->created_at?->toDateString(),
                'type' => 'equivalence',
                'libelle' => "Équivalence {$eq->statut} : {$eq->origine_libelle} → {$eq->cible_libelle}",
                'detail' => ['decision' => $eq->decision],
            ];
        }

        foreach (ParcoursDispense::where('etudiant_id', $etudiant->id)->orderBy('id')->get() as $dispense) {
            $evenements[] = [
                'date' => $dispense->created_at?->toDateString(),
                'type' => 'dispense',
                'libelle' => "Dispense {$dispense->statut} : {$dispense->cible_libelle}",
                'detail' => ['motif' => $dispense->motif],
            ];
        }

        foreach (ParcoursReorientation::where('etudiant_id', $etudiant->id)->orderBy('id')->get() as $reor) {
            $evenements[] = [
                'date' => $reor->created_at?->toDateString(),
                'type' => 'reorientation',
                'libelle' => "Réorientation {$reor->statut} : {$reor->ancien_libelle} → {$reor->nouveau_libelle}",
                'detail' => ['decision' => $reor->decision],
            ];
        }

        foreach (ParcoursPasserelle::where('etudiant_id', $etudiant->id)->orderBy('id')->get() as $passerelle) {
            $evenements[] = [
                'date' => $passerelle->created_at?->toDateString(),
                'type' => 'passerelle',
                'libelle' => "Passerelle {$passerelle->statut}",
                'detail' => ['admission' => $passerelle->decision_admission],
            ];
        }

        $simulations = ParcoursSimulation::where('etudiant_id', $etudiant->id)->orderBy('id')->get();
        foreach ($simulations as $simulation) {
            $evenements[] = [
                'date' => $simulation->created_at?->toDateString(),
                'type' => 'simulation',
                'libelle' => "Simulation {$simulation->scenario} : ".($simulation->resultat['resultat_final'] ?? '?'),
                'detail' => ['simulation_id' => $simulation->id],
            ];
        }

        usort($evenements, fn ($a, $b) => strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')));

        $evenements = array_values($evenements);

        // Filtrage par type + garantie sans PII (jamais CIN, e-mail,
        // téléphone, adresse dans les libellés ni les détails).
        if ($type !== null) {
            $evenements = array_values(array_filter($evenements, fn ($e) => $e['type'] === $type));
        }

        return $evenements;
    }
}
