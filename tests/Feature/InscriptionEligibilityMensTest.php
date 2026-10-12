<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\MensReference;
use App\Services\InscriptionEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LOOP A (convergent) — Contrôle MENS explicite de la façade : divergences
 * nom/prénom et absence de rapprochement remontés comme avertissements
 * structurés. Complète InscriptionEligibiliteFacadeTest (8 tests).
 */
class InscriptionEligibilityMensTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        config(['inscriptions.documents_requis_par_type' => [
            'premiere' => [], 'reinscription' => [], 'transfert' => [],
        ]]);
    }

    private function creerDossier(string $cin, string $nom = 'Benali', string $prenom = 'Sara'): InscriptionAcademique
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => $nom, 'prenom' => $prenom,
            'email' => strtolower(preg_replace('/\s+/', '', $cin)).'@mens.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');

        return $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1',
            'statut' => 'en_cours', 'type' => 'premiere',
        ]);
    }

    private function creerMens(string $cin, string $nom = 'Benali', string $prenom = 'Sara'): void
    {
        MensReference::create([
            'cin_normalise' => MensReference::normaliserCin($cin), 'cin_original' => $cin,
            'date_naissance' => '2004-01-01', 'nom' => $nom, 'prenom' => $prenom,
            'annee' => '2026', 'source_hash' => hash('sha256', $cin),
        ]);
    }

    private function controle(array $synthese, string $code): array
    {
        foreach ($synthese['controles'] as $controle) {
            if ($controle['code'] === $code) {
                return $controle;
            }
        }

        $this->fail("Contrôle {$code} absent de la synthèse.");
    }

    public function test_mens_non_rapproche_avertit(): void
    {
        $inscription = $this->creerDossier('11000002');

        $synthese = app(InscriptionEligibilityService::class)->evaluer($inscription);

        $this->assertSame('avertissement', $this->controle($synthese, 'IDENTITE_MENS')['statut']);
        $codes = array_column($synthese['avertissements'], 'code');
        $this->assertContains('MENS_NON_RAPPROCHE', $codes);
        $this->assertStringContainsString(
            'rapprochement mens', mb_strtolower(implode(' | ', $synthese['actions_proposees']))
        );
    }

    public function test_rapproche_sans_divergence_passe(): void
    {
        $inscription = $this->creerDossier('11000003');
        $this->creerMens('11000003');

        $compte = 0;
        DB::listen(function () use (&$compte): void {
            $compte++;
        });
        $synthese = app(InscriptionEligibilityService::class)->evaluer($inscription);
        DB::flushQueryLog();
        fwrite(STDERR, "\n[ELIGIBILITE] requêtes par évaluation : {$compte} (SQLite test).\n");

        $this->assertSame('pass', $this->controle($synthese, 'IDENTITE_MENS')['statut']);
        $this->assertNotContains('MENS_NON_RAPPROCHE', array_column($synthese['avertissements'], 'code'));
        $this->assertLessThan(60, $compte);
    }
}
