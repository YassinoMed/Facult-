<?php

namespace Tests\Feature;

use App\Services\DataExchange\CorrecteurImportService;
use App\Services\DataExchange\DataImportService;
use App\Services\DataExchange\Definitions\EtudiantDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * PHASE 3 — Moteur de correction automatique : règles déterministes
 * tracées, champs sensibles jamais touchés, ambigu abstenu pour revue,
 * idempotence.
 */
class CorrecteurImportTest extends TestCase
{
    use RefreshDatabase;

    private function ligne(array $valeurs, int $numero = 2): array
    {
        return array_merge(['_ligne' => $numero, 'ligne' => $numero], $valeurs);
    }

    private function corriger(array $ligne): array
    {
        return app(CorrecteurImportService::class)->corriger([$ligne], app(EtudiantDefinition::class));
    }

    public function test_espaces_superflus_corriges_et_traces(): void
    {
        ['lignes' => $lignes, 'corrections' => $corrections] = $this->corriger(
            $this->ligne(['adresse' => '  Rue   des  Jasmins '])
        );

        $this->assertSame('Rue des Jasmins', $lignes[0]['adresse']);
        $this->assertCount(1, $corrections);
        $this->assertSame([
            'ligne' => 2, 'champ' => 'adresse',
            'originale' => '  Rue   des  Jasmins ', 'proposee' => 'Rue des Jasmins',
            'regle' => 'ESPACES@1.0',
        ], $corrections[0]);
    }

    public function test_champs_sensibles_jamais_touches(): void
    {
        $entree = $this->ligne([
            'cin' => '12  345  678',
            'nom' => '  Ben   Ali ',
            'prenom' => 'Mohamed',
            'email' => '  test@exemple.com ',
            'note' => '12,5',
        ]);

        ['lignes' => $lignes, 'corrections' => $corrections, 'a_revoir' => $aRevoir] = $this->corriger($entree);

        $this->assertSame('12  345  678', $lignes[0]['cin']);
        $this->assertSame('  Ben   Ali ', $lignes[0]['nom']);
        $this->assertSame('Mohamed', $lignes[0]['prenom']);
        $this->assertSame('  test@exemple.com ', $lignes[0]['email']);
        $this->assertSame('12,5', $lignes[0]['note']);
        $this->assertSame([], $corrections);
        $this->assertSame([], $aRevoir);
    }

    public function test_telephone_normalise_avec_regle_explicite(): void
    {
        ['lignes' => $lignes, 'corrections' => $corrections] = $this->corriger(
            $this->ligne(['telephone' => '21234567'])
        );

        $this->assertSame('+21621234567', $lignes[0]['telephone']);
        $this->assertSame('TELEPHONE@1.0', $corrections[0]['regle']);

        ['lignes' => $lignes] = $this->corriger($this->ligne(['telephone' => '0021698765432']));
        $this->assertSame('+21698765432', $lignes[0]['telephone']);

        ['lignes' => $lignes, 'corrections' => $corrections] = $this->corriger(
            $this->ligne(['telephone' => '٢١٢٣٤٥٦٧'])
        );
        $this->assertSame('+21621234567', $lignes[0]['telephone']);
        $this->assertSame(['CHIFFRES@1.0', 'TELEPHONE@1.0'], array_column($corrections, 'regle'));
    }

    public function test_date_non_ambigue_uniformisee_ambigue_abstenue(): void
    {
        // ISO : inchangée, aucune trace.
        ['lignes' => $lignes, 'corrections' => $corrections] = $this->corriger(
            $this->ligne(['date_naissance' => '2026-10-01'])
        );
        $this->assertSame('2026-10-01', $lignes[0]['date_naissance']);
        $this->assertSame([], $corrections);

        // Jour > 12 : non ambiguë → corrigée.
        ['lignes' => $lignes, 'corrections' => $corrections] = $this->corriger(
            $this->ligne(['date_naissance' => '15/10/2026'])
        );
        $this->assertSame('2026-10-15', $lignes[0]['date_naissance']);
        $this->assertSame('DATE@1.0', $corrections[0]['regle']);

        // Ambiguë (jj/mm vs mm/jj) : abstention + revue, valeur intacte.
        ['lignes' => $lignes, 'corrections' => $corrections, 'a_revoir' => $aRevoir] = $this->corriger(
            $this->ligne(['date_naissance' => '05/06/2026'])
        );
        $this->assertSame('05/06/2026', $lignes[0]['date_naissance']);
        $this->assertSame([], $corrections);
        $this->assertCount(1, $aRevoir);
        $this->assertSame('DATE@1.0', $aRevoir[0]['regle']);

        // Illisible : abstention + revue.
        ['lignes' => $lignes, 'a_revoir' => $aRevoir] = $this->corriger(
            $this->ligne(['date_naissance' => 'demain'])
        );
        $this->assertSame('demain', $lignes[0]['date_naissance']);
        $this->assertCount(1, $aRevoir);
    }

    public function test_application_repetee_idempotente(): void
    {
        $service = app(CorrecteurImportService::class);
        $definition = app(EtudiantDefinition::class);

        $premiere = $service->corriger([
            $this->ligne(['adresse' => '  Rue   des  Jasmins ', 'telephone' => '21234567', 'date_naissance' => '15/10/2026']),
        ], $definition);

        $seconde = $service->corriger($premiere['lignes'], $definition);

        $this->assertSame($premiere['lignes'], $seconde['lignes']);
        $this->assertSame([], $seconde['corrections']);
    }

    public function test_valeurs_vides_ignorees(): void
    {
        ['lignes' => $lignes, 'corrections' => $corrections] = $this->corriger(
            $this->ligne(['adresse' => null, 'telephone' => '   '])
        );

        $this->assertNull($lignes[0]['adresse']);
        $this->assertSame([], $corrections);
    }

    public function test_analyseur_expose_corrections_et_revue(): void
    {
        $chemin = tempnam(sys_get_temp_dir(), 'correcteur').'.csv';
        file_put_contents($chemin, "nom,prenom,adresse,telephone,date_naissance\nBen Ali,Mohamed,  Rue   des  Jasmins ,21234567,05/06/2026\n");
        $fichier = new UploadedFile($chemin, 'correcteur.csv', 'text/csv', null, true);

        ['resultat' => $resultat] = app(DataImportService::class)->analyser($fichier, 'etudiants');

        $champs = array_column($resultat->corrections, 'champ');
        $this->assertContains('adresse', $champs);
        $this->assertContains('telephone', $champs);
        $this->assertNotContains('nom', $champs);
        $this->assertNotContains('prenom', $champs);

        $this->assertCount(1, $resultat->aRevoir);
        $this->assertSame('date_naissance', $resultat->aRevoir[0]['champ']);
    }
}
