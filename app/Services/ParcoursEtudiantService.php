<?php

namespace App\Services;

use App\Models\Etudiant;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\MaquetteUe;

/**
 * Parcours académique en lecture seule (LOOP 13) : programme et niveau
 * RÉELLEMENT associés (inscription la plus récente, repli dossier),
 * semestres/UE/ECUE publiables, version de maquette applicable —
 * AUCUNE écriture, AUCUNE attribution devinée.
 *
 * Version applicable démontrée (même règle que `inscrireEcue` et
 * `genererEpreuves`) : maquette ACTIVE du programme POUR l'année de
 * l'inscription. La maquette active COURANTE ne s'applique jamais
 * implicitement aux inscrits d'autres années (état explicite
 * SANS_MAQUETTE_APPLICABLE + chaîne canonique hors plan).
 */
final class ParcoursEtudiantService
{
    /**
     * @return array<string, mixed>
     */
    public function consulter(Etudiant $etudiant): array
    {
        $etudiant->loadMissing([
            'programmeRef', 'niveauRef',
            'inscriptionsAcademiques.anneeUniversitaire',
            'inscriptionsAcademiques.programme',
            'inscriptionsAcademiques.niveauRef',
        ]);

        $inscription = $etudiant->inscriptionsAcademiques
            ->sortByDesc(fn ($i) => $i->anneeUniversitaire?->date_debut ?? '')
            ->first();

        if ($inscription === null) {
            return $this->base($etudiant, null, 'SANS_INSCRIPTION', 'Aucune inscription académique.');
        }

        $programme = $inscription->programme ?? $etudiant->programmeRef;

        if ($programme === null) {
            return $this->base($etudiant, $inscription, 'SANS_PROGRAMME',
                'Programme non renseigné (ni inscription ni dossier) — aucune attribution devinée.');
        }

        $maquette = Maquette::where('programme_id', $programme->id)
            ->where('annee_universitaire_id', $inscription->annee_universitaire_id)
            ->where('statut', 'active')
            ->first();

        if ($maquette === null) {
            return $this->base($etudiant, $inscription, 'SANS_MAQUETTE_APPLICABLE',
                'Aucune maquette active pour ce programme et cette année.',
                $programme, null, false);
        }

        $lignes = MaquetteUe::where('maquette_id', $maquette->id)
            ->with(['ue.ecues', 'semestre'])
            ->orderBy('id')
            ->get();

        $semestres = [];
        foreach ($lignes as $ligne) {
            $code = $ligne->semestre?->code ?? $ligne->ue?->semestre?->code ?? '?';
            $semestres[$code]['code'] = $code;
            foreach ($ligne->ecues as $occurrence) {
                $matiere = $occurrence->matiere;
                $semestres[$code]['ecues'][] = [
                    'code' => $matiere?->code,
                    'nom' => $matiere?->nom,
                    'credits' => (int) $occurrence->credits,
                    'volumes' => ((int) $occurrence->volume_cm).'/'.((int) $occurrence->volume_td).'/'.((int) $occurrence->volume_tp),
                    'ue' => $ligne->ue?->code,
                    'regime' => $occurrence->regime,
                ];
            }
        }

        return $this->base($etudiant, $inscription, 'OK', '', $programme, [
            'version' => $maquette->version,
            'statut' => $maquette->statut,
            'annee' => $maquette->anneeUniversitaire?->libelle,
        ], true, array_values($semestres));
    }

    /**
     * @return array<string, mixed>
     */
    private function base(
        Etudiant $etudiant,
        ?object $inscription,
        string $etat,
        string $message,
        ?object $programme = null,
        ?array $maquette = null,
        bool $sourcePlan = false,
        ?array $semestres = null,
    ): array {
        if ($semestres === null && $programme !== null) {
            $semestres = $this->chaineCanonique($programme);
        }

        $pedagogiques = [];
        if ($inscription !== null) {
            $pedagogiques = $inscription->pedagogiques()
                ->with(['matiere', 'semestre'])
                ->orderBy('id')
                ->get()
                ->map(fn ($p) => [
                    'matiere' => $p->matiere?->code,
                    'nom' => $p->matiere?->nom,
                    'semestre' => $p->semestre?->code,
                    'statut' => $p->statut,
                ])->all();
        }

        return [
            'etat' => $etat,
            'message' => $message,
            'annee' => $inscription?->anneeUniversitaire?->libelle,
            'statut_inscription' => $inscription?->statut,
            'programme' => $programme?->code,
            'programme_nom' => $programme?->nom,
            'niveau' => $inscription?->niveauRef?->code ?? $etudiant->niveauRef?->code ?? $inscription?->niveau,
            'maquette' => $maquette,
            'source_plan' => $sourcePlan,
            'semestres' => $semestres ?? [],
            'inscriptions_pedagogiques' => $pedagogiques,
        ];
    }

    /**
     * Chaîne canonique hors plan (repli documenté) : niveaux →
     * semestres → UE → ECUE du programme.
     *
     * @return array<int, array<string, mixed>>
     */
    private function chaineCanonique(object $programme): array
    {
        $programme->loadMissing(['niveaux.semestres.unitesEnseignement.ecues']);

        $semestres = [];
        foreach ($programme->niveaux as $niveau) {
            foreach ($niveau->semestres as $semestre) {
                $entree = ['code' => $niveau->code.' / '.$semestre->code, 'ecues' => []];
                foreach ($semestre->unitesEnseignement as $ue) {
                    foreach ($ue->ecues as $ecue) {
                        $entree['ecues'][] = [
                            'code' => $ecue->code,
                            'nom' => $ecue->nom,
                            'credits' => (int) $ecue->credits,
                            'volumes' => ((int) $ecue->volume_cm).'/'.((int) $ecue->volume_td).'/'.((int) $ecue->volume_tp),
                            'ue' => $ue->code,
                            'regime' => null,
                        ];
                    }
                }
                $semestres[] = $entree;
            }
        }

        return $semestres;
    }
}
