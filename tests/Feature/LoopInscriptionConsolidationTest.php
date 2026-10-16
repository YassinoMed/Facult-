<?php

namespace Tests\Feature;

use App\Events\StudentRegistered;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Services\Verification\Anomalie;
use App\Services\Verification\VerificationEngine;
use App\Services\Verification\VerificationResult;
use App\Services\Verification\VerificationRule;
use App\Services\Verification\VerificationStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LOOP UNIQUE consolidation inscription — tests de non-régression des
 * défauts corrigés (registre C1-C4 du rapport de loop).
 *
 * - C1 : une règle en exception ne doit jamais laisser un dossier CONFORME.
 * - C2 : le traitement en masse doit tracer l'acteur comme en individuel.
 * - C3 : StudentRegistered est afterCommit (écritures listeners protégées).
 * - C4 : chaque run persiste version du moteur + règles exécutées.
 */
class LoopInscriptionConsolidationTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences de Tunis',
            'code' => 'FST',
            'active' => true,
        ]);
    }

    private function creerEtudiant(array $attributs = []): Etudiant
    {
        static $compteur = 0;

        $compteur++;

        return Etudiant::create(array_merge([
            'nom' => 'Nom'.$compteur,
            'prenom' => 'Prenom'.$compteur,
            'email' => "consolidation{$compteur}@test.tn",
            'cin' => sprintf('90000%03d', $compteur),
            'sexe' => 'M',
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '10 Rue de la République',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2025',
            'faculte_id' => $this->faculte->id,
        ], $attributs));
    }

    private function regleExplosive(): VerificationRule
    {
        return new class implements VerificationRule
        {
            public function code(): string
            {
                return 'test.explosive';
            }

            public function libelle(): string
            {
                return 'Règle explosive (test)';
            }

            public function categorie(): string
            {
                return 'identite';
            }

            /** @return array<int, Anomalie> */
            public function verifier(Etudiant $etudiant): array
            {
                throw new \RuntimeException('panne simulée du contrôle obligatoire');
            }
        };
    }

    public function test_exception_regle_produit_avertissement_pas_conforme(): void
    {
        $etudiant = $this->creerEtudiant();

        $moteur = new VerificationEngine([$this->regleExplosive()]);
        $resultat = $moteur->verifier($etudiant);

        $this->assertCount(1, $resultat->anomalies);
        $this->assertSame('avertissement', $resultat->anomalies[0]->gravite);
        // Jamais CONFORME par défaut : un contrôle obligatoire n'a pas été exécuté.
        $this->assertSame(VerificationResult::A_VERIFIER, $resultat->verdict);
        $this->assertFalse($resultat->estConforme());
        $this->assertSame(95, $resultat->score);
    }

    public function test_run_persiste_version_et_regles(): void
    {
        $etudiant = $this->creerEtudiant();

        $moteur = app(VerificationEngine::class);
        $resultat = $moteur->verifier($etudiant);

        $run = app(VerificationStore::class)->enregistrer($etudiant, $resultat, 'web', null);

        $this->assertSame(VerificationEngine::VERSION, $run->moteur_version);
        $this->assertSame(VerificationEngine::VERSION, $resultat->versionMoteur);
        $this->assertNotEmpty($run->regles_executees);
        $this->assertContains('doublons.detection', $run->regles_executees);
        $this->assertSame($resultat->reglesExecutees, $run->regles_executees);
        $this->assertDatabaseHas('dossier_verifications', [
            'id' => $run->id,
            'moteur_version' => VerificationEngine::VERSION,
        ]);
    }

    public function test_bulk_statut_tracer_acteur(): void
    {
        $admin = User::creerCompteBackOffice([
            'name' => 'Scolarité centrale',
            'email' => 'scolarite.bulk@universite.tn',
            'password' => 'Secret123!',
        ], 'admin_scolarite');

        $etudiants = [$this->creerEtudiant(), $this->creerEtudiant()];

        $reponse = $this->actingAs($admin)->post(route('admin.etudiants.bulkStatut'), [
            'ids' => [$etudiants[0]->id, $etudiants[1]->id],
            'statut' => 'valide',
            'confirmation' => true,
        ]);

        $reponse->assertRedirect();
        $this->assertSame('valide', $etudiants[0]->fresh()->statut);
        $this->assertSame('valide', $etudiants[1]->fresh()->statut);

        // Chaque transition en masse porte l'acteur, comme en individuel.
        foreach ($etudiants as $etudiant) {
            $transitions = DB::table('situations_etudiant')
                ->where('etudiant_id', $etudiant->id)
                ->where('type', 'statut')
                ->get();

            $this->assertNotEmpty($transitions);
            foreach ($transitions as $transition) {
                $this->assertSame($admin->id, (int) $transition->auteur_id);
            }
        }
    }

    public function test_student_registered_apres_commit(): void
    {
        $evenement = new StudentRegistered(new Etudiant);

        // Contrat : les listeners écrivent en base (GED, anomalies, notification)
        // — aucun rollback ne doit laisser ces écritures orphelines.
        $this->assertTrue($evenement->afterCommit);
    }
}
