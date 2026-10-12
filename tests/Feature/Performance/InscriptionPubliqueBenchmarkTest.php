<?php

namespace Tests\Feature\Performance;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\MensReference;
use App\Models\RegistrationPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Benchmark du parcours public d'inscription (LOOP 20 — dette résorbée :
 * k6 était détourné par la porte fail-closed MENS, aucune mesure PHPUnit
 * du POST n'existait). Mesure le coût réel d'une soumission complète :
 * identification MENS → gardes (période, CIN normalisée, e-mail) →
 * création transactionnelle → événements (GED, notifications, qualité,
 * affectation). Requêtes SQL + temps par soumission, bornes gardes-fou
 * anti-régression, artefact JSON `storage/app/benchmark/` (convention
 * AffectationBenchmarkTest). Volume piloté : `BENCH_VOLUMES_INSCRIPTION`
 * (défaut 10 — le POST est unitaire par nature, pas un lot).
 */
class InscriptionPubliqueBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);

        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        RegistrationPeriod::create([
            'annee_universitaire_id' => $annee->id,
            'type_periode' => 'normale',
            'opened_at' => now()->subDay(),
            'closed_at' => now()->addDay(),
            'statut' => 'active',
        ]);

        $volume = (int) (getenv('BENCH_VOLUMES_INSCRIPTION') ?: 10);

        for ($i = 1; $i <= $volume; $i++) {
            $cin = sprintf('%08d', 90000000 + $i);
            MensReference::create([
                'cin_normalise' => $cin,
                'cin_original' => $cin,
                'date_naissance' => '2004-03-15',
                'nom' => 'Nomperf', 'prenom' => 'Prenomperf'.$i,
                'sexe' => 'M', 'nationalite' => 'Tunisienne',
                'annee' => '2026-2027', 'niveau' => 'L1',
                'source_hash' => str_repeat((string) $i, 64),
            ]);
        }
    }

    public function test_soumission_publique_cout_reel_borne(): void
    {
        $volume = (int) (getenv('BENCH_VOLUMES_INSCRIPTION') ?: 10);

        $requetesParSoumission = [];
        $dureesMs = [];

        for ($i = 1; $i <= $volume; $i++) {
            $cin = sprintf('%08d', 90000000 + $i);

            DB::enableQueryLog();
            $debut = microtime(true);

            $reponse = $this->post('/inscription', [
                'cin' => $cin,
                'date_naissance' => '2004-03-15',
                'nom' => 'Nomperf',
                'prenom' => 'Prenomperf'.$i,
                'email' => 'perf'.$i.'@etudiant.test',
                'sexe' => 'M',
                'faculte_id' => $this->faculte->id,
                'niveau' => 'L1',
                'annee_academique' => '2026-2027',
                'adresse' => '12 rue du bench',
                'ville' => 'Sfax',
                'code_postal' => '3000',
                'annee_bac' => '2024',
                'password' => 'Etudiant@123',
                'password_confirmation' => 'Etudiant@123',
                'confirmation' => '1',
            ]);

            $dureesMs[] = (microtime(true) - $debut) * 1000;
            $requetesParSoumission[] = count(DB::getQueryLog());
            DB::flushQueryLog();
            DB::disableQueryLog();

            $reponse->assertRedirect(route('inscription.success'));
        }

        $this->assertSame($volume, Etudiant::count());

        $stats = [
            'volume' => $volume,
            'requetes_max' => max($requetesParSoumission),
            'requetes_moyenne' => round(array_sum($requetesParSoumission) / $volume, 1),
            'duree_ms_max' => (int) round(max($dureesMs)),
            'duree_ms_moyenne' => (int) round(array_sum($dureesMs) / $volume),
        ];

        // Artefact (convention AffectationBenchmarkTest) — sans PII :
        // CIN/noms volontairement absents, agrégats uniquement.
        $chemin = storage_path('app/benchmark');
        @mkdir($chemin, 0775, true);
        file_put_contents($chemin.'/inscription-publique.json', json_encode(
            array_merge($stats, ['mesure_le' => now()->toIso8601String()]),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
        ));

        // Gardes-fou anti-régression, calibrés sur la mesure réelle
        // (2026-10-09 : ~56 requêtes/soumission, ~46 ms — parcours
        // complet : identification MENS, gardes, création, 5 listeners
        // GED/notification/audit/qualité/affectation ; une soumission
        // REJETÉE par les gardes ne coûte que ~13 requêtes).
        $this->assertLessThan(100, $stats['requetes_max'], 'Requêtes/soumission : '.json_encode($stats));
        $this->assertLessThan(2000.0, max($dureesMs), 'Durée/soumission ms : '.json_encode($stats));

        fwrite(STDERR, sprintf(
            "\n[INSCRIPTION-PUBLIQUE] %d soumissions | %s requêtes max (%s moy) | %d ms max (%d moy)\n",
            $volume,
            $stats['requetes_max'],
            $stats['requetes_moyenne'],
            $stats['duree_ms_max'],
            $stats['duree_ms_moyenne'],
        ));
    }
}
