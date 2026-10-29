<?php

namespace Tests\Unit;

use App\Modules\SimulationTemporelle\Support\ClasseurScenarios;
use App\Modules\SimulationTemporelle\Support\EvaluateurScenarios;
use App\Modules\SimulationTemporelle\Support\GenerateurVariantes;
use App\Modules\SimulationTemporelle\Support\HorlogeSimulee;
use App\Modules\SimulationTemporelle\Support\MoteurEvenements;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * ST-01 : moteur pur — horloge simulée (jamais now()), ordre total des
 * événements simultanés, générateur reproductible, évaluateur
 * déclaratif, classeur déterministe.
 */
class MoteurTemporelTest extends TestCase
{
    public function test_horloge_avance_par_pas_sans_horloge_systeme(): void
    {
        $horloge = HorlogeSimulee::annuelle(new DateTimeImmutable('2026-09-01'));

        $this->assertSame('2026-09-01', $horloge->instant()->format('Y-m-d'));
        $this->assertSame(0, $horloge->nbPas());

        $horloge = $horloge->avancer()->avancer();
        $this->assertSame(2, $horloge->nbPas());
        $this->assertSame('2028-09-01', $horloge->instant()->format('Y-m-d'));
    }

    public function test_evenements_simultanes_ordre_total_deterministe(): void
    {
        $t = new DateTimeImmutable('2027-09-01');
        $trace = [];

        foreach ([['b', 1], ['a', 2], ['c', 3], ['z', 4]] as [$type, $moment]) {
            $moteur = new MoteurEvenements;
            // Planification dans un ordre volontairement différent du tri.
            $moteur->planifier($t, 'second', []);
            $moteur->planifier($t, 'premier', []);
            $moteur->planifier($t, $type, []);
            $moteur->traiter(function ($e) use (&$trace, $moment): void {
                $trace[$moment][] = $e->type;
            });
        }

        // Ordre total : à temps ÉGAL, l'ordre de planification (séquence)
        // départage — 'second' a été planifié en premier → séquence 0.
        $this->assertSame(['second', 'premier', 'b'], $trace[1]);
        $this->assertSame(['second', 'premier', 'a'], $trace[2]);
        $this->assertNotContains('premier', array_slice($trace[1], 0, 1));
    }

    public function test_evenements_traversent_les_temps_dans_l_ordre(): void
    {
        $moteur = new MoteurEvenements;
        $ordre = [];
        $moteur->planifier(new DateTimeImmutable('2028-09-01'), 'tard', []);
        $moteur->planifier(new DateTimeImmutable('2026-09-01'), 'tot', []);
        $moteur->planifier(new DateTimeImmutable('2027-09-01'), 'moyen', []);
        $moteur->traiter(function ($e) use (&$ordre): void {
            $ordre[] = $e->type;
        });

        $this->assertSame(['tot', 'moyen', 'tard'], $ordre);
    }

    public function test_generateur_reproductible_et_borne(): void
    {
        $bornes = ['min' => -0.5, 'max' => 2.0];

        $a = GenerateurVariantes::deltasAdmissions(0.1, $bornes, 10, 'graine-X');
        $b = GenerateurVariantes::deltasAdmissions(0.1, $bornes, 10, 'graine-X');
        $c = GenerateurVariantes::deltasAdmissions(0.1, $bornes, 10, 'graine-Y');

        $this->assertSame($a, $b); // mêmes graine+bornes → mêmes variantes
        $this->assertNotSame($a, $c); // graine différente → variantes différentes
        $this->assertSame(0.1, $a[0]); // référence = delta SAISI
        foreach ([$a, $c] as $jeu) {
            foreach ($jeu as $delta) {
                $this->assertGreaterThanOrEqual(-0.5, $delta);
                $this->assertLessThanOrEqual(2.0, $delta);
            }
        }
    }

    public function test_evaluateur_contrainte_dure_et_scores_ponderes(): void
    {
        $evaluateur = new EvaluateurScenarios;
        $mesures = ['total_final' => 1200, 'variations_annuelles' => [0.05, 0.02]];

        $conforme = $evaluateur->evaluer($mesures,
            [['type' => 'plafond_total', 'valeur' => 1500]],
            [
                ['type' => 'cible_total', 'cible' => 1000, 'poids' => 1.0],
                ['type' => 'stabilite', 'poids' => 1.0],
            ],
        );
        $this->assertTrue($conforme['conforme']);
        $this->assertSame(0.875, $conforme['score']); // cible 0.8 + stabilité 0.95, poids égaux
        $this->assertNotEmpty($conforme['contributions']);

        $violee = $evaluateur->evaluer($mesures,
            [['type' => 'plafond_total', 'valeur' => 1000]], [],
        );
        $this->assertFalse($violee['conforme']);
        $this->assertStringContainsString('plafond', $violee['violations'][0]);
    }

    public function test_classeur_trie_par_score_et_isole_non_conformes(): void
    {
        $classeur = new ClasseurScenarios;
        $resultat = $classeur->classer([
            ['index' => 1, 'delta' => 0.2, 'evaluation' => ['conforme' => true, 'score' => 0.9, 'contributions' => []]],
            ['index' => 2, 'delta' => 0.3, 'evaluation' => ['conforme' => false, 'score' => 0.99, 'contributions' => []]],
            ['index' => 3, 'delta' => 0.1, 'evaluation' => ['conforme' => true, 'score' => 0.5, 'contributions' => []]],
        ]);

        $this->assertSame([1, 3], array_column($resultat['classement'], 'index'));
        $this->assertSame([2], array_column($resultat['non_conformes'], 'index'));
    }

    public function test_moteur_plafond_anti_cout(): void
    {
        $moteur = new MoteurEvenements;
        $t = new DateTimeImmutable('2026-09-01');

        $this->expectException(\DomainException::class);
        for ($i = 0; $i < MoteurEvenements::PLAFOND_EVENEMENTS + 1; $i++) {
            $moteur->planifier($t, 'x', []);
        }
    }
}
