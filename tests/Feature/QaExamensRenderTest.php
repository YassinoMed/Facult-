<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\User;
use App\Modules\Evaluation\Models\SessionExamen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Export statique des écrans d'examens pour la QA visuelle hors PHPUnit.
 * Fichier temporaire : supprimé après la vérification.
 */
class QaExamensRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_exporte_les_ecrans_examens(): void
    {
        $sortie = getenv('QA_OUT');

        if (! is_string($sortie) || $sortie === '') {
            $this->markTestSkipped('QA_OUT absent');
        }

        config(['modules.evaluation.active' => true]);

        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        SessionExamen::create([
            'annee_universitaire_id' => $annee->id,
            'code' => 'SESS-QA-01',
            'nom' => 'Session QA',
            'type' => 'principale',
            'date_debut' => now(),
            'date_fin' => now()->addDays(14),
            'statut' => 'open',
        ]);

        $admin = User::creerCompteBackOffice([
            'name' => 'Admin QA',
            'email' => 'admin.qa'.uniqid().'@universite.test',
            'password' => 'Motdepasse@123',
            'actif' => true,
        ], 'super_admin');

        foreach ([
            'tableau-de-bord' => 'admin.evaluation.examens.tableau-de-bord',
            'simulation' => 'admin.evaluation.examens.simulation',
            'surveillance' => 'admin.evaluation.examens.surveillance',
        ] as $nom => $route) {
            $reponse = $this->actingAs($admin)->get(route($route));
            $reponse->assertOk();

            file_put_contents($sortie.'/'.$nom.'.html', $reponse->getContent());
        }

        $this->assertTrue(true);
    }
}
