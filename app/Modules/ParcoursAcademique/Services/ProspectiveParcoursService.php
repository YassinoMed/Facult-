<?php

namespace App\Modules\ParcoursAcademique\Services;

use App\Models\AnneeUniversitaire;
use App\Models\InscriptionAcademique;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Services\AcademicProgressionService;

/**
 * Planification prospective (§18) : évolution future d'une
 * population (passages, redoublements, dettes) et besoins
 * (groupes/places). Lecture seule, dry-run via
 * AcademicProgressionService, jamais d'écriture.
 */
class ProspectiveParcoursService
{
    public function __construct(
        private readonly AcademicProgressionService $progressions,
    ) {}

    /**
     * @return array{programme: string, annee_source: ?string, annee_cible: ?string, niveaux: array<int, array{niveau: string, inscrits: int, progresser: int, redoubler: int, fin_cycle: int, bloque: int, projetes: int, places_groupes: int, places_manquantes: int}>, totaux: array<string, int>}
     */
    public function projeter(Programme $programme, AnneeUniversitaire $source): array
    {
        $cible = $source->suivante() ?? AnneeUniversitaire::active();
        $niveaux = Niveau::where('programme_id', $programme->id)->orderBy('ordre')->get();

        $lignes = [];
        $totaux = ['inscrits' => 0, 'projetes' => 0, 'places_manquantes' => 0];

        foreach ($niveaux as $niveau) {
            $inscriptions = InscriptionAcademique::where('programme_id', $programme->id)
                ->where('niveau_id', $niveau->id)
                ->where('annee_universitaire_id', $source->id)
                ->with('etudiant')
                ->get();

            $compteurs = ['progresser' => 0, 'redoubler' => 0, 'fin_cycle' => 0, 'bloque' => 0];
            foreach ($inscriptions as $inscription) {
                if ($inscription->etudiant === null) {
                    $compteurs['bloque']++;

                    continue;
                }
                $evaluation = $this->progressions->evaluer($inscription->etudiant, $inscription);
                $action = $evaluation['action'];
                $compteurs[$action] = ($compteurs[$action] ?? 0) + 1;
            }

            // Projetés sur ce niveau l'an prochain = redoublements ici +
            // passages du niveau précédent (ordre - 1). Calculé après boucle.
            $lignes[] = [
                'niveau' => $niveau->code,
                'niveau_id' => $niveau->id,
                'ordre' => $niveau->ordre,
                'inscrits' => $inscriptions->count(),
                'progresser' => $compteurs['progresser'] ?? 0,
                'redoubler' => $compteurs['redoubler'] ?? 0,
                'fin_cycle' => $compteurs['fin_cycle'] ?? 0,
                'bloque' => $compteurs['bloque'] ?? 0,
                'projetes' => 0,
                'places_groupes' => 0,
                'places_manquantes' => 0,
            ];

            $totaux['inscrits'] += $inscriptions->count();
        }

        foreach ($lignes as $i => &$ligne) {
            $precedent = $i > 0 ? $lignes[$i - 1] : null;
            $ligne['projetes'] = $ligne['redoubler'] + ($precedent['progresser'] ?? 0);

            $capacite = (int) Group::where('programme_id', $programme->id)
                ->where('actif', true)
                ->sum('capacite');
            $parNiveau = Group::where('programme_id', $programme->id)
                ->where('niveau_id', $ligne['niveau_id'])
                ->where('actif', true)
                ->sum('capacite');
            $places = $parNiveau > 0 ? (int) $parNiveau : $capacite;

            $ligne['places_groupes'] = $places;
            $ligne['places_manquantes'] = max(0, $ligne['projetes'] - $places);
            $totaux['projetes'] += $ligne['projetes'];
            $totaux['places_manquantes'] += $ligne['places_manquantes'];
        }
        unset($ligne);

        return [
            'programme' => $programme->nom,
            'annee_source' => $source->libelle,
            'annee_cible' => $cible?->libelle,
            'niveaux' => $lignes,
            'totaux' => $totaux,
        ];
    }
}
