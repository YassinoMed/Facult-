<?php

namespace App\Modules\Evaluation\Services;

use App\Models\Batiment;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Services\ExamScheduleEngineService;
use Illuminate\Database\Eloquent\Collection;

/**
 * Orchestrateur Enterprise pour la planification des examens et l'allocation des salles (Menus 4 & 5).
 *
 * Responsabilités :
 * - Audit unifié des salles et des capacités utiles d'examen (ratio anti-fraude 50%, Décision D-04 & R-17/R-18).
 * - Consolidation des métriques pour le tableau de bord des salles.
 * - Analyse comparative approfondie des 3 scénarios de planification (équilibré, compact, amphis).
 * - Enrichissement des placements d'épreuves avec ratios d'espacement et indicateurs de confort.
 */
class ExamPlanificationSallesOrchestratorService
{
    public function __construct(
        private ExamScheduleEngineService $scheduleEngine,
    ) {}

    /**
     * Audit complet du parc des salles et de leurs capacités opposables.
     *
     * @return array{
     *     kpis: array{
     *         total_salles: int,
     *         salles_actives: int,
     *         salles_inactives: int,
     *         salles_validees: int,
     *         salles_bloquantes: int,
     *         capacite_nominale_totale: int,
     *         capacite_utile_examen_50: int,
     *         taux_conformite: float
     *     },
     *     batiments: Collection<int, Batiment>,
     *     salles_bloquantes_liste: array<int, array{id: int, code: string, nom: string, batiment: string|null, motif: string}>
     * }
     */
    public function auditerSallesEtCapacites(?int $faculteId = null, ?int $batimentId = null): array
    {
        $query = Room::query()
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->when($batimentId !== null, fn ($q) => $q->where('batiment_id', $batimentId))
            ->with('batimentRelation');

        $salles = $query->get();

        $totalSalles = $salles->count();
        $sallesActives = $salles->where('actif', true)->count();
        $sallesInactives = $salles->where('actif', false)->count();

        $sallesValidees = 0;
        $sallesBloquantes = 0;
        $capaciteNominaleTotale = 0;
        $capaciteUtileTotale = 0;
        $sallesBloquantesListe = [];

        foreach ($salles as $salle) {
            $capa = $salle->capacite;

            if ($capa === null || (int) $capa <= 0) {
                $sallesBloquantes++;
                $sallesBloquantesListe[] = [
                    'id' => $salle->id,
                    'code' => $salle->code,
                    'nom' => $salle->nom,
                    'batiment' => $salle->batimentRelation?->nom ?? $salle->batiment,
                    'motif' => 'Capacité nominale indéterminée ou nulle (0 place)',
                ];
            } else {
                $sallesValidees++;
                $capaNominale = (int) $capa;
                $capaciteNominaleTotale += $capaNominale;
                $capaciteUtileTotale += $this->scheduleEngine->capaciteUtileExamen($salle);
            }
        }

        $tauxConformite = $totalSalles > 0
            ? round(($sallesValidees / $totalSalles) * 100, 1)
            : 100.0;

        $batiments = Batiment::query()
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->orderBy('nom')
            ->get();

        return [
            'kpis' => [
                'total_salles' => $totalSalles,
                'salles_actives' => $sallesActives,
                'salles_inactives' => $sallesInactives,
                'salles_validees' => $sallesValidees,
                'salles_bloquantes' => $sallesBloquantes,
                'capacite_nominale_totale' => $capaciteNominaleTotale,
                'capacite_utile_examen_50' => $capaciteUtileTotale,
                'taux_conformite' => $tauxConformite,
            ],
            'batiments' => $batiments,
            'salles_bloquantes_liste' => $sallesBloquantesListe,
        ];
    }

    /**
     * Analyse et enrichit les scénarios de planification calculés par le moteur CSP.
     *
     * @param  array{
     *     scenarios?: array<string, array<string, mixed>>,
     *     comparaison?: array<string, mixed>,
     *     recommandation?: string|null
     * }  $resultatMoteur
     * @return array{
     *     scenarios: array<string, array<string, mixed>>,
     *     recommandation: string|null,
     *     resume_comparatif: array<int, array<string, mixed>>,
     *     epreuves_examen: Collection<int, Epreuve>
     * }
     */
    public function analyserEtEnrichirScenarios(array $resultatMoteur, Examen $examen): array
    {
        $scenarios = $resultatMoteur['scenarios'] ?? [];
        $rawRecommandation = $resultatMoteur['recommandation'] ?? null;
        $recommandationId = is_array($rawRecommandation) ? ($rawRecommandation['scenario_id'] ?? null) : $rawRecommandation;
        $recommandationNom = is_array($rawRecommandation) ? ($rawRecommandation['nom'] ?? $recommandationId) : $rawRecommandation;

        $epreuves = $examen->epreuves()->with(['matiere', 'groupe', 'salle'])->get();
        $epreuvesById = $epreuves->keyBy('id');

        $toutesSallesIds = collect($scenarios)->pluck('placements')->flatten(1)->pluck('salle_id')->filter()->unique();
        $salles = Room::whereIn('id', $toutesSallesIds)->get()->keyBy('id');

        $resumeComparatif = [];

        foreach ($scenarios as $cle => &$scenario) {
            $estValide = (bool) ($scenario['est_valide'] ?? false);
            $score = (int) ($scenario['score_qualite'] ?? 0);
            $conflitsDurs = (int) ($scenario['nb_conflits_durs'] ?? 0);
            $placements = $scenario['placements'] ?? [];

            // Enrichissement des placements
            foreach ($placements as &$p) {
                $epreuveId = $p['epreuve_id'] ?? null;
                $salleId = $p['salle_id'] ?? null;

                $epreuve = $epreuveId ? $epreuvesById->get($epreuveId) : null;
                $salle = $salleId ? $salles->get($salleId) : null;

                $effectifRequis = (int) ($p['effectif'] ?? $epreuve?->capacite_necessaire ?? 0);
                $capaNominale = $salle ? (int) ($salle->capacite ?? 0) : 0;
                $capaUtile50 = $salle ? $this->scheduleEngine->capaciteUtileExamen($salle) : 0;

                $tauxRemplissage = $capaUtile50 > 0
                    ? round(($effectifRequis / $capaUtile50) * 100, 1)
                    : 0;

                $p['matiere_code'] = $epreuve?->matiere?->code ?? '—';
                $p['matiere_nom'] = $epreuve?->matiere?->nom ?? 'Épreuve #'.$epreuveId;
                $p['salle_code'] = $salle?->code ?? 'Non affectée';
                $p['salle_nom'] = $salle?->nom ?? '';
                $p['capacite_nominale'] = $capaNominale;
                $p['capacite_utile_50'] = $capaUtile50;
                $p['effectif_requis'] = $effectifRequis;
                $p['taux_remplissage_utile'] = $tauxRemplissage;
                $p['confort_statut'] = $tauxRemplissage > 100
                    ? 'surcharge'
                    : ($tauxRemplissage >= 85 ? 'dense' : 'optimal');
            }
            unset($p);

            $scenario['placements'] = $placements;

            $resumeComparatif[] = [
                'cle' => $cle,
                'nom' => $scenario['nom'] ?? ucfirst($cle),
                'description' => $scenario['description'] ?? '',
                'est_valide' => $estValide,
                'est_recommande' => $cle === $recommandationId,
                'score_qualite' => $score,
                'nb_conflits_durs' => $conflitsDurs,
                'epreuves_placees' => count($placements),
                'jours_mobilises' => $scenario['metriques']['jours_mobilises'] ?? count(array_unique(array_column($placements, 'date'))),
                'taux_remplissage_moyen' => $scenario['metriques']['taux_remplissage_moyen'] ?? 0,
            ];
        }
        unset($scenario);

        return [
            'scenarios' => $scenarios,
            'recommandation' => $recommandationNom,
            'recommandation_id' => $recommandationId,
            'resume_comparatif' => $resumeComparatif,
            'epreuves_examen' => $epreuves,
        ];
    }

    /**
     * Diagnostic de conformité pour une salle unitaire.
     *
     * @return array{
     *     capacite_nominale: int,
     *     capacite_utile_50: int,
     *     est_valide: bool,
     *     statut_code: string,
     *     statut_libelle: string,
     *     opposable: bool
     * }
     */
    public function diagnostiquerSalle(Room $salle): array
    {
        $nominale = (int) ($salle->capacite ?? 0);
        $utile = $this->scheduleEngine->capaciteUtileExamen($salle);

        if (! $salle->actif) {
            return [
                'capacite_nominale' => $nominale,
                'capacite_utile_50' => 0,
                'est_valide' => false,
                'statut_code' => 'fermee',
                'statut_libelle' => 'Salle fermée / inactive',
                'opposable' => false,
            ];
        }

        if ($nominale <= 0) {
            return [
                'capacite_nominale' => 0,
                'capacite_utile_50' => 0,
                'est_valide' => false,
                'statut_code' => 'inconnue',
                'statut_libelle' => 'Capacité inconnue (Bloquante)',
                'opposable' => false,
            ];
        }

        return [
            'capacite_nominale' => $nominale,
            'capacite_utile_50' => $utile,
            'est_valide' => true,
            'statut_code' => 'validee',
            'statut_libelle' => 'Validée (Opposable 50%)',
            'opposable' => true,
        ];
    }
}
