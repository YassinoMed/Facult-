<?php

namespace App\Modules\SimulateurScenarios\Services;

use App\Models\InscriptionAcademique;
use App\Modules\Enseignement\Models\AffectationEnseignement;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Room;
use App\Modules\SimulateurScenarios\Support\CriteresScenario;
use InvalidArgumentException;

/**
 * Lot 14 — Moteur du simulateur de scénarios INSTITUTIONNELS.
 *
 * LECTURE SEULE STRICTE : ce service compte et projette, il n'écrit JAMAIS
 * une donnée officielle (inscriptions, salles, charges). Périmètres :
 * - distinct de `AssignmentSimulation` (affectation de groupes, technique) ;
 * - distinct de `SimulationParcoursService` (parcours d'UN étudiant) ;
 * - distinct de `TimetableScenario` (optimisation d'un emploi du temps).
 *
 * Déterminisme : mêmes entrées + même VERSION_REGLES ⇒ mêmes sorties et le
 * même hash. Aucune donnée manquante n'est remplacée par zéro : les axes
 * incapables de calcul déclarent `indisponible` avec une raison.
 *
 * Résultat = appui à la décision, jamais une décision institutionnelle
 * officielle (avertissement renvoyé au consommateur).
 */
final class ServiceScenarios
{
    public const VERSION_REGLES = '1.0';

    /**
     * Plafond de volume par axe : au-delà, le résultat reste EXACT mais il
     * est explicitement signalé (`plafond_atteint`). On ne tronque jamais un
     * effectif institutionnel : une borne silencieuse produirait un chiffre
     * faux. Le plafond sert donc d'alerte de volumétrie, pas de `LIMIT` SQL.
     */
    public const PLAFOND_LIGNES_PAR_AXE = 5000;

    /**
     * Calcule un scénario sur le périmètre effectif fourni.
     *
     * @return array<string, mixed> résultat déterministe, sans PII
     */
    public function calculer(CriteresScenario $criteres): array
    {
        $effectifs = $this->axeEffectifs($criteres);
        $capacite = $this->axeCapacite($criteres);

        return [
            'version_regles' => self::VERSION_REGLES,
            'hash_entrees' => $this->hashEntrees($criteres),
            'perimetre' => $this->decrirePerimetre($criteres),
            'axes' => [
                'effectifs' => $effectifs,
                'capacite' => $capacite,
                'saturation' => $this->axeSaturation($effectifs, $capacite),
                'charges' => $this->axeCharges($criteres),
                'programmes' => $this->axeProgrammes($criteres),
            ],
            'avertissement' => 'Appui à la décision uniquement : aucune décision '
                .'institutionnelle officielle. Les coûts sont indisponibles '
                .'(aucune source budgétaire confirmée).',
        ];
    }

    /**
     * Compare deux scénarios : même périmètre imposé, unités identiques,
     * écarts explicites (critères 5 et 8 du cahier).
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [A, B]
     */
    public function comparer(CriteresScenario $criteresA, CriteresScenario $criteresB): array
    {
        if ($criteresA->faculteId !== $criteresB->faculteId
            || $criteresA->anneeId !== $criteresB->anneeId) {
            throw new InvalidArgumentException(
                'Comparaison refusée : les deux scénarios doivent partager le '
                .'même périmètre (faculté et année) pour rester comparables.',
            );
        }

        $resultatA = $this->calculer($criteresA);
        $resultatB = $this->calculer($criteresB);

        return [$resultatA, $resultatB];
    }

    /**
     * Axes de comparaison chiffrés A vs B (différence b - a par axe).
     *
     * @param  array<string, mixed>  $resultatA
     * @param  array<string, mixed>  $resultatB
     * @return array<int, array<string, mixed>>
     */
    public function ecarts(array $resultatA, array $resultatB): array
    {
        $axesA = $resultatA['axes'];
        $axesB = $resultatB['axes'];

        $ecarts = [
            $this->ecartNumeric('effectifs', 'etudiants', $axesA, $axesB, 'projete'),
            $this->ecartNumeric('capacite', 'places', $axesA, $axesB, 'projete'),
            $this->ecartNumeric('charges', 'heures', $axesA, $axesB, 'projete'),
        ];

        $saturationA = $axesA['saturation']['valeur'] ?? null;
        $saturationB = $axesB['saturation']['valeur'] ?? null;
        $ecarts[] = [
            'axe' => 'saturation',
            'unite' => 'ratio',
            'a' => $saturationA,
            'b' => $saturationB,
            'ecart' => ($saturationA !== null && $saturationB !== null)
                ? round($saturationB - $saturationA, 2)
                : 'indisponible',
        ];

        return $ecarts;
    }

    /**
     * Écart chiffré A vs B pour un champ d'axe précis (« indisponible » si
     * l'une des valeurs ne peut pas être calculée — jamais un zéro fuyard).
     *
     * @param  array<string, mixed>  $axesA
     * @param  array<string, mixed>  $axesB
     * @return array<string, mixed>
     */
    private function ecartNumeric(string $axe, string $unite, array $axesA, array $axesB, string $champ): array
    {
        $a = $axesA[$axe][$champ] ?? null;
        $b = $axesB[$axe][$champ] ?? null;

        return [
            'axe' => $axe,
            'unite' => $unite,
            'a' => $a,
            'b' => $b,
            'ecart' => ($a !== null && $b !== null) ? round($b - $a, 2) : 'indisponible',
        ];
    }

    /* ── Axes de calcul ─────────────────────────────────────────── */

    /**
     * @return array<string, mixed>
     */
    private function axeEffectifs(CriteresScenario $criteres): array
    {
        $source = InscriptionAcademique::query()
            ->where('statut', 'valide');

        if ($criteres->anneeId !== null) {
            $source->where('annee_universitaire_id', $criteres->anneeId);
        }

        if ($criteres->faculteId !== null) {
            $source->whereHas('programme', fn ($q) => $q->where('faculte_id', $criteres->faculteId));
        }

        $base = (int) (clone $source)->count();
        $projete = (int) round($base * (1 + $criteres->deltaEffectifs));

        // Inscriptions validées hors de toute chaîne programme → faculté :
        // comptées à part (jamais silencieusement fusionnées au périmètre).
        $horsPerimetre = $this->effectifsHorsChainFacultaire($criteres);

        return [
            'unite' => 'etudiants',
            'statut_compte' => 'valide',
            'base' => $base,
            'base_definie_comme' => 'inscriptions validées rattachées à un programme de la faculté demandée ; les inscriptions sans programme (ou dont le programme n\'a pas de faculté) sont comptées séparément dans `hors_chaine_programme`',
            'delta' => $criteres->deltaEffectifs,
            'projete' => $projete,
            'hors_chaine_programme' => $horsPerimetre,
            'hors_chaine_programme_definie_comme' => 'inscriptions validées de la faculté sans programme rattaché, ou avec un programme sans faculté',
            'plafond_lignes' => self::PLAFOND_LIGNES_PAR_AXE,
            'plafond_atteint' => $base > self::PLAFOND_LIGNES_PAR_AXE,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function axeCapacite(CriteresScenario $criteres): array
    {
        $salles = Room::query()
            ->where('actif', true);

        if ($criteres->faculteId !== null) {
            $salles->where('faculte_id', $criteres->faculteId);
        }

        $agregats = (clone $salles)->selectRaw('COUNT(*) as total, COALESCE(SUM(capacite), 0) as places')->first();
        $nombreSalles = (int) ($agregats->total ?? 0);
        $capaciteBase = (int) ($agregats->places ?? 0);
        $capaciteMoyenne = $nombreSalles > 0 ? (int) floor($capaciteBase / $nombreSalles) : 0;

        // Capacité projetée jamais négative : un retrait au-delà du parc
        // existant est borné à zéro et la saturation passe indisponible.
        $capaciteProjetee = max(0, $capaciteBase + ($criteres->deltaSalles * $capaciteMoyenne));

        return [
            'unite' => 'places',
            'salles_actives' => $nombreSalles,
            'capacite_moyenne' => $capaciteMoyenne,
            'base' => $capaciteBase,
            'delta_salles' => $criteres->deltaSalles,
            'projete' => $capaciteProjetee,
            'plafond_lignes' => self::PLAFOND_LIGNES_PAR_AXE,
            'plafond_atteint' => $nombreSalles > self::PLAFOND_LIGNES_PAR_AXE,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function axeCharges(CriteresScenario $criteres): array
    {
        $affectations = AffectationEnseignement::query()
            ->where('statut', 'active');

        if ($criteres->faculteId !== null) {
            $affectations->whereHas('enseignement', fn ($e) => $e
                ->whereHas('programme', fn ($p) => $p->where('faculte_id', $criteres->faculteId)));
        }

        $agregats = (clone $affectations)->selectRaw('COUNT(*) as total, COALESCE(SUM(volume_heures), 0) as heures')->first();
        $nombreAffectations = (int) ($agregats->total ?? 0);
        $base = (float) ($agregats->heures ?? 0);
        $projete = round($base * (1 + $criteres->deltaCharges), 1);

        return [
            'unite' => 'heures',
            'statut_compte' => 'active',
            'affectations_actives' => $nombreAffectations,
            'base' => $base,
            'delta' => $criteres->deltaCharges,
            'projete' => $projete,
            'regroupement' => 'total ' // Pas encore de regroupement facultaire :
                .($criteres->faculteId !== null ? 'de la faculte demandee (chaine program>faculte_id)' : 'global'),
            'plafond_lignes' => self::PLAFOND_LIGNES_PAR_AXE,
            'plafond_atteint' => $nombreAffectations > self::PLAFOND_LIGNES_PAR_AXE,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function axeProgrammes(CriteresScenario $criteres): array
    {
        $programmes = Programme::query();

        if ($criteres->faculteId !== null) {
            $programmes->where('faculte_id', $criteres->faculteId);
        }

        $recense = (int) (clone $programmes)->count();

        return [
            'unite' => 'programmes',
            'recense' => $recense,
            'note' => 'Contexte seulement : aucune hypothèse d\'ouverture '
                .'n\'est simulée en MVP (la source de compétences du Lot 20 n\'existe pas encore).',
            'plafond_lignes' => self::PLAFOND_LIGNES_PAR_AXE,
            'plafond_atteint' => $recense > self::PLAFOND_LIGNES_PAR_AXE,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function axeSaturation(array $effectifs, array $capacite): array
    {
        $effectifsProjetes = (int) $effectifs['projete'];
        $capaciteProjetee = (int) $capacite['projete'];
        $aucuneSalle = (int) $capacite['salles_actives'] === 0 && (int) $capacite['delta_salles'] <= 0;

        if ($capaciteProjetee <= 0) {
            return [
                'unite' => 'ratio',
                'etat' => 'indisponible',
                'valeur' => null,
                'raison' => $aucuneSalle
                    ? 'Aucune salle active dans le périmètre : saturation non calculable (donnée absente, jamais un zéro silencieux).'
                    : 'Capacité projetée nulle après hypothèse : saturation non calculable.',
            ];
        }

        return [
            'unite' => 'ratio',
            'etat' => 'calcule',
            'valeur' => round($effectifsProjetes / $capaciteProjetee, 2),
            'raison' => null,
        ];
    }

    /* ── Aides déterministes ────────────────────────────────────── */

    private function effectifsHorsChainFacultaire(CriteresScenario $criteres): int
    {
        $horsChain = InscriptionAcademique::query()
            ->where('statut', 'valide')
            ->where(fn ($q) => $q
                ->whereNull('programme_id')
                ->orWhereHas('programme', fn ($p) => $p->whereNull('faculte_id')));

        if ($criteres->anneeId !== null) {
            $horsChain->where('annee_universitaire_id', $criteres->anneeId);
        }

        // Un agrégat « hors chaîne programme » reste dans le périmètre
        // facultaire : sinon un admin_faculte recevrait un compteur
        // d'inscriptions orphelines d'autres facultés.
        if ($criteres->faculteId !== null) {
            $horsChain->whereHas('etudiant', fn ($q) => $q->where('faculte_id', $criteres->faculteId));
        }

        return (int) $horsChain->count();
    }

    /**
     * Hash sha256 des entrées NORMALISÉES (nombre formaté à 4 décimales :
     * pas de variation de représentation flottante entre exécutions).
     */
    private function hashEntrees(CriteresScenario $criteres): string
    {
        $payload = [
            'version' => self::VERSION_REGLES,
            'faculte_id' => $criteres->faculteId,
            'annee_id' => $criteres->anneeId,
            'delta_effectifs' => number_format($criteres->deltaEffectifs, 4, '.', ''),
            'delta_salles' => (string) $criteres->deltaSalles,
            'delta_charges' => number_format($criteres->deltaCharges, 4, '.', ''),
        ];

        return hash('sha256', (string) json_encode($payload));
    }

    /**
     * @return array<string, mixed>
     */
    private function decrirePerimetre(CriteresScenario $criteres): array
    {
        return [
            'faculte_id' => $criteres->faculteId,
            'etendue' => $criteres->faculteId === null ? 'toutes-les-facultes' : 'faculte-'.$criteres->faculteId,
            'annee_id' => $criteres->anneeId,
        ];
    }
}
