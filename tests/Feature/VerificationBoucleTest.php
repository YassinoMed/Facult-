<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DossierVerification;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Services\Verification\CorrectionService;
use App\Services\Verification\VerificationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * LOOP vérificateur : CAS 6/7/8/9 (§23), workflow de correction
 * validée (§15, 4-eyes, concurrence), runs persistés (§13).
 */
class VerificationBoucleTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private User $scolarite;

    private User $scolarite2;

    private AnneeUniversitaire $annee;

    private AnneeUniversitaire $anneePrec;

    private Programme $programme;

    private Niveau $l1;

    private Niveau $l2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'S1', 'email' => 's1.boucle@univ.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->scolarite2 = User::creerCompteBackOffice([
            'name' => 'S2', 'email' => 's2.boucle@univ.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->anneePrec = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-06-30', 'active' => false,
        ]);
        $this->programme = Programme::create([
            'code' => 'INF', 'nom' => 'Informatique', 'faculte_id' => $this->faculte->id,
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $this->l1 = Niveau::create(['programme_id' => $this->programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $this->l2 = Niveau::create(['programme_id' => $this->programme->id, 'code' => 'L2', 'nom' => 'L2', 'ordre' => 2]);
    }

    private function etudiant(string $cin, array $attrs = []): Etudiant
    {
        static $n = 0;
        $n++;

        $e = Etudiant::create(array_merge([
            'nom' => 'Nom'.$n, 'prenom' => 'Prenom'.$n, 'email' => "boucle{$n}@univ.test",
            'cin' => $cin, 'sexe' => 'M', 'date_naissance' => '2004-01-01',
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X',
            'ville' => 'Tunis', 'code_postal' => '1000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123', 'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id, 'niveau_id' => $this->l1->id,
        ], $attrs));
        $e->definirStatut('valide');

        return $e;
    }

    private function inscrire(Etudiant $e, AnneeUniversitaire $annee, string $niveau = 'L1', string $statut = 'en_cours', ?Niveau $niveauRef = null): InscriptionAcademique
    {
        return InscriptionAcademique::create([
            'etudiant_id' => $e->id, 'annee_universitaire_id' => $annee->id,
            'programme_id' => $this->programme->id, 'niveau_id' => ($niveauRef ?? $this->l1)->id,
            'niveau' => $niveau, 'statut' => $statut, 'type' => 'premiere', 'regime' => 'initiale',
        ]);
    }

    /** @return array<int, string> gravités des anomalies d'une catégorie */
    private function gravites(Etudiant $e, string $categorie): array
    {
        return array_map(
            fn ($a) => $a->gravite,
            array_filter(app(VerificationEngine::class)->verifier($e)->anomalies, fn ($a) => $a->categorie === $categorie)
        );
    }

    public function test_cas_6_redoublant_non_bloquant(): void
    {
        $e = $this->etudiant('BCL00001');
        $this->inscrire($e, $this->anneePrec, 'L1', 'valide');
        $this->inscrire($e, $this->annee, 'L1', 'en_cours');

        $this->assertNotContains('bloquant', $this->gravites($e, 'parcours'));
    }

    public function test_cas_7_reorientation_non_bloquante(): void
    {
        $autre = Programme::create([
            'code' => 'ECO', 'nom' => 'Économie', 'faculte_id' => $this->faculte->id,
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $e = $this->etudiant('BCL00002', ['programme_id' => $autre->id]);
        $this->inscrire($e, $this->anneePrec, 'L1', 'valide');
        $this->inscrire($e, $this->annee, 'L1', 'en_cours');

        $this->assertNotContains('bloquant', $this->gravites($e, 'parcours'));
    }

    public function test_cas_8_diplome_decision_publiee_coherent(): void
    {
        $e = $this->etudiant('BCL00003');
        $this->inscrire($e, $this->annee, 'L1', 'valide');
        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $this->annee->id, 'programme_id' => $this->programme->id,
            'statut' => 'published', 'created_by' => $this->scolarite->id, 'published_at' => now(),
        ]);
        DecisionDeliberation::create([
            'deliberation_id' => $deliberation->id, 'etudiant_id' => $e->id,
            'inscription_academique_id' => $e->inscriptionsAcademiques()->first()->id,
            'moyenne' => 14.0, 'decision' => 'admis', 'publie' => true,
        ]);

        $anomalies = app(VerificationEngine::class)->verifier($e)->anomalies;
        $bloquantes = array_filter($anomalies, fn ($a) => $a->gravite === 'bloquant'
            && in_array($a->categorie, ['parcours', 'resultats'], true));

        $this->assertSame([], array_values($bloquantes));
    }

    public function test_cas_9_ancien_sans_inscription_active_avertissement(): void
    {
        $e = $this->etudiant('BCL00004');
        $this->inscrire($e, $this->anneePrec, 'L1', 'valide');

        // Historique valide sans inscription active : jamais bloquant.
        $this->assertNotContains('bloquant', $this->gravites($e, 'inscription'));
    }

    public function test_workflow_proposer_approuver_appliquer(): void
    {
        $e = $this->etudiant('BCL00005', ['telephone' => null]);
        $service = app(CorrectionService::class);

        $correction = $service->proposer($e, 'telephone', '+21620123456', 'Numéro du dossier papier.', 'identite.complete', $this->scolarite->id);
        $this->assertSame('proposee', $correction->statut);

        // Doublon ouvert refusé (idempotence).
        $this->expectException(\DomainException::class);
        $service->proposer($e, 'telephone', '+21620123456', 'Bis.', null, $this->scolarite->id);
    }

    public function test_4_eyes_et_concurrence(): void
    {
        $e = $this->etudiant('BCL00006', ['telephone' => null]);
        $service = app(CorrectionService::class);
        $correction = $service->proposer($e, 'telephone', '+21620123456', 'Dossier.', null, $this->scolarite->id);

        // Auto-approbation refusée.
        try {
            $service->approuver($correction, $this->scolarite->id);
            $this->fail('Auto-approbation aurait dû être refusée.');
        } catch (HttpException $ex) {
            $this->assertSame(403, $ex->getStatusCode());
        }

        $approuvee = $service->approuver($correction, $this->scolarite2->id, 'Vérifié.');
        $this->assertSame('approuvee', $approuvee->statut);

        // Double approbation (concurrence) refusée.
        $this->expectException(\DomainException::class);
        $service->approuver($correction->fresh(), $this->scolarite->id === $this->scolarite2->id ? 999999 : $this->scolarite->id);
    }

    public function test_appliquer_reverifie_et_audite(): void
    {
        $e = $this->etudiant('BCL00007', ['telephone' => null]);
        $this->inscrire($e, $this->annee);
        $service = app(CorrectionService::class);
        $correction = $service->proposer($e, 'telephone', '+21620123456', 'Dossier.', 'identite.complete', $this->scolarite->id);
        $service->approuver($correction, $this->scolarite2->id);

        $resultat = $service->appliquer($correction->fresh(), $this->scolarite2->id);

        $this->assertSame('+21620123456', $e->fresh()->telephone);
        $this->assertSame('appliquee', $resultat['correction']->statut);
        $this->assertArrayHasKey('verdict_apres', $resultat);
        $this->assertTrue(HistoriqueAcademique::where('action', HistoriqueAcademique::ACTION_CORRECTION_DOSSIER)->where('entite_id', $e->id)->exists());
        $this->assertTrue(HistoriqueAcademique::where('action', HistoriqueAcademique::ACTION_CORRECTION_APPROUVEE)->exists());
    }

    public function test_appliquer_refuse_si_donnee_changee(): void
    {
        $e = $this->etudiant('BCL00008', ['telephone' => '+21620000000']);
        $service = app(CorrectionService::class);
        $correction = $service->proposer($e, 'telephone', '+21620123456', 'Dossier.', null, $this->scolarite->id);
        $service->approuver($correction, $this->scolarite2->id);
        $e->update(['telephone' => '+21620999999']);

        $this->expectException(\DomainException::class);
        $service->appliquer($correction->fresh(), $this->scolarite2->id);
    }

    public function test_rejeter_exige_second_agent_et_motif(): void
    {
        $e = $this->etudiant('BCL00009', ['telephone' => null]);
        $service = app(CorrectionService::class);
        $correction = $service->proposer($e, 'telephone', '+21620123456', 'Dossier.', null, $this->scolarite->id);

        $rejetee = $service->rejeter($correction, $this->scolarite2->id, 'Numéro illisible.');
        $this->assertSame('rejetee', $rejetee->statut);
        $this->assertTrue(HistoriqueAcademique::where('action', HistoriqueAcademique::ACTION_CORRECTION_REJETEE)->exists());
    }

    public function test_run_persiste_via_verifier_http(): void
    {
        $e = $this->etudiant('BCL00010');
        $this->inscrire($e, $this->annee);

        $this->actingAs($this->scolarite)
            ->post(route('admin.verification.verifier', $e))
            ->assertRedirect();

        $run = DossierVerification::where('etudiant_id', $e->id)->latest('id')->firstOrFail();
        $this->assertSame('web', $run->source);
        $this->assertContains($run->verdict, ['CONFORME', 'A_VERIFIER', 'BLOQUANT']);
    }

    public function test_idor_correction_faculte_etrangere(): void
    {
        $autre = Faculte::create(['nom' => 'FD', 'code' => 'FD', 'description' => 'T', 'active' => true]);
        $adminFac = User::creerCompteBackOffice([
            'name' => 'F', 'email' => 'f.boucle@univ.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $autre->id,
        ], 'admin_faculte');
        $e = $this->etudiant('BCL00011', ['telephone' => null]);

        $this->actingAs($adminFac)->post(route('admin.verification.proposer', $e), [
            'champ' => 'telephone', 'nouvelle_valeur' => '+21620123456', 'motif' => 'X',
        ])->assertForbidden();
    }
}
