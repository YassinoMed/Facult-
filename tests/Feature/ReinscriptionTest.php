<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Services\ReinscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsApiClient;
use Tests\TestCase;

/**
 * Réinscription simplifiée (§26) : contexte prérempli, niveaux
 * proposés depuis les décisions officielles, avenants verrouillés.
 */
class ReinscriptionTest extends TestCase
{
    use ActAsApiClient, RefreshDatabase;

    private Faculte $faculte;

    private Faculte $autreFaculte;

    private User $scolarite;

    private User $adminFaculte;

    private AnneeUniversitaire $annee;

    private AnneeUniversitaire $anneeSuivante;

    private Programme $programme;

    private Niveau $l1;

    private Niveau $l2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->autreFaculte = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'T', 'active' => true]);
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.reins@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.reins@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->autreFaculte->id,
        ], 'admin_faculte');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-06-30', 'active' => false,
        ]);
        $this->anneeSuivante = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LMI', 'nom' => 'Licence MI',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $this->l1 = Niveau::create(['programme_id' => $this->programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $this->l2 = Niveau::create(['programme_id' => $this->programme->id, 'code' => 'L2', 'nom' => 'L2', 'ordre' => 2]);
    }

    private function etudiant(string $cin, array $attrs = []): Etudiant
    {
        $e = Etudiant::create(array_merge([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => strtolower($cin).'@reins.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id, 'niveau_id' => $this->l1->id,
            'niveau' => 'L1', 'annee_academique' => '2025-2026', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ], $attrs));
        $e->definirStatut('valide');

        return $e;
    }

    private function inscrire(Etudiant $e, AnneeUniversitaire $annee, Niveau $niveau, string $statut = 'en_cours'): InscriptionAcademique
    {
        return InscriptionAcademique::create([
            'etudiant_id' => $e->id, 'annee_universitaire_id' => $annee->id,
            'programme_id' => $this->programme->id, 'niveau_id' => $niveau->id,
            'niveau' => $niveau->code, 'statut' => $statut, 'type' => 'premiere', 'regime' => 'initiale',
        ]);
    }

    private function deliberer(Etudiant $e, InscriptionAcademique $inscription, string $decision): void
    {
        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $this->annee->id, 'programme_id' => $this->programme->id,
            'statut' => 'published', 'created_by' => $this->scolarite->id, 'published_at' => now(),
        ]);
        DecisionDeliberation::create([
            'deliberation_id' => $deliberation->id, 'etudiant_id' => $e->id,
            'inscription_academique_id' => $inscription->id,
            'moyenne' => 12.0, 'decision' => $decision, 'publie' => true,
        ]);
    }

    public function test_1_nouvel_etudiant_formulaire_complet(): void
    {
        $e = $this->etudiant('RINS00001');
        $contexte = app(ReinscriptionService::class)->contexte($e, $this->anneeSuivante);

        $this->assertSame('premiere', $contexte['situation']);
        $this->assertNull($contexte['derniere']);
        $this->assertNull($contexte['niveau_propose']);

        $this->actingAs($this->scolarite)
            ->get(route('admin.etudiants.reinscriptions.creer', $e))
            ->assertOk();
    }

    public function test_2_existant_contexte_prerempli(): void
    {
        $e = $this->etudiant('RINS00002');
        $inscription = $this->inscrire($e, $this->annee, $this->l1);
        $this->deliberer($e, $inscription, 'admis');

        $contexte = app(ReinscriptionService::class)->contexte($e, $this->anneeSuivante);

        $this->assertSame($inscription->id, $contexte['derniere']->id);
        $this->assertSame('passage', $contexte['situation']);
        $this->assertSame('L2', $contexte['niveau_propose']['code']);
        $this->assertSame('admis', $contexte['source_decision']);

        $contenu = (string) $this->actingAs($this->scolarite)
            ->get(route('admin.etudiants.reinscriptions.creer', [$e, 'annee_universitaire_id' => $this->anneeSuivante->id]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Niveau proposé automatiquement', $contenu);
        $this->assertMatchesRegularExpression('/Niveau proposé automatiquement[^<]*<strong>L2<\/strong>/', $contenu);
        $this->assertStringContainsString($e->num_inscription, $contenu);
    }

    public function test_3_4_5_passages_proposes(): void
    {
        foreach (['admis', 'admis_compensation', 'admis_conditionnel'] as $i => $decision) {
            $e = $this->etudiant('RINSP00'.$i);
            $inscription = $this->inscrire($e, $this->annee, $this->l1);
            $this->deliberer($e, $inscription, $decision);

            $contexte = app(ReinscriptionService::class)->contexte($e, $this->anneeSuivante);
            $this->assertSame('L2', $contexte['niveau_propose']['code'], "Décision {$decision}");
        }
    }

    public function test_6_redoublement_propose_meme_niveau(): void
    {
        $e = $this->etudiant('RINS00006');
        $inscription = $this->inscrire($e, $this->annee, $this->l1);
        $this->deliberer($e, $inscription, 'redouble');

        $contexte = app(ReinscriptionService::class)->contexte($e, $this->anneeSuivante);

        $this->assertSame('redoublement', $contexte['situation']);
        $this->assertSame('L1', $contexte['niveau_propose']['code']);
    }

    public function test_7_sans_decision_niveau_non_invente(): void
    {
        $e = $this->etudiant('RINS00007');
        $this->inscrire($e, $this->annee, $this->l1);

        $contexte = app(ReinscriptionService::class)->contexte($e, $this->anneeSuivante);

        $this->assertSame('bloque', $contexte['situation']);
        $this->assertNull($contexte['niveau_propose']);
        $this->assertNotEmpty($contexte['evaluation']['blocages']);
    }

    public function test_8_reorientation_conserve_identite(): void
    {
        $e = $this->etudiant('RINS00008');
        $inscription = $this->inscrire($e, $this->annee, $this->l1);
        $this->deliberer($e, $inscription, 'admis');

        $reponse = $this->actingAs($this->scolarite)->post(
            route('admin.etudiants.reinscriptions.store', $e),
            [
                'annee_universitaire_id' => $this->anneeSuivante->id,
                'programme_id' => $this->programme->id,
                'niveau_id' => $this->l2->id,
                'confirmer' => '1',
            ]
        );

        $reponse->assertRedirect();
        $nouvelle = InscriptionAcademique::where('etudiant_id', $e->id)
            ->where('annee_universitaire_id', $this->anneeSuivante->id)->firstOrFail();
        $this->assertSame('L2', $nouvelle->niveau);
        $this->assertSame('Benali', $e->fresh()->nom);
    }

    public function test_8b_reorientation_sans_decision_refusee(): void
    {
        $e = $this->etudiant('RINS00008B');
        $this->inscrire($e, $this->annee, $this->l1);

        $this->actingAs($this->scolarite)->post(
            route('admin.etudiants.reinscriptions.store', $e),
            [
                'annee_universitaire_id' => $this->anneeSuivante->id,
                'niveau_id' => $this->l2->id,
                'confirmer' => '1',
            ]
        )->assertSessionHasErrors('inscription');
    }

    public function test_9_10_documents_checklist(): void
    {
        config(['inscriptions.documents_requis' => ['piece_identite']]);

        $e = $this->etudiant('RINS00009');
        $contexte = app(ReinscriptionService::class)->contexte($e, $this->anneeSuivante);

        $this->assertSame(['piece_identite'], $contexte['documents_manquants']);
    }

    public function test_11_double_inscription_impossible(): void
    {
        $e = $this->etudiant('RINS00011');
        $inscription = $this->inscrire($e, $this->annee, $this->l1);
        $this->deliberer($e, $inscription, 'admis');

        $service = app(ReinscriptionService::class);
        $premiere = $service->confirmer($e, $this->anneeSuivante, ['confirmer' => true], $this->scolarite->id);
        $seconde = $service->confirmer($e, $this->anneeSuivante, ['confirmer' => true], $this->scolarite->id);

        $this->assertSame($premiere->id, $seconde->id);
        $this->assertSame(1, InscriptionAcademique::where('etudiant_id', $e->id)
            ->where('annee_universitaire_id', $this->anneeSuivante->id)->count());
    }

    public function test_12_13_idor_et_scope(): void
    {
        $e = $this->etudiant('RINS00012');

        $this->actingAs($this->adminFaculte)
            ->get(route('admin.etudiants.reinscriptions.creer', $e))
            ->assertForbidden();

        $this->actingAs($this->adminFaculte)
            ->post(route('admin.etudiants.reinscriptions.store', $e), [
                'annee_universitaire_id' => $this->anneeSuivante->id,
                'confirmer' => '1',
            ])->assertNotFound();
    }

    public function test_api_contexte_et_reenroll(): void
    {
        $e = $this->etudiant('RINSAPI01');
        $inscription = $this->inscrire($e, $this->annee, $this->l1);
        $this->deliberer($e, $inscription, 'admis');

        [, $lecture] = $this->clientApi(['archives:read']);
        $contexte = $this->getApi("/api/v1/students/{$e->id}/reenrollment-context?annee_universitaire_id={$this->anneeSuivante->id}", $lecture)
            ->assertOk()->json('data');

        $this->assertTrue($contexte['student_exists']);
        $this->assertSame('L2', $contexte['proposed_level']);
        $this->assertSame('ELIGIBLE', $contexte['progression_status']);

        [, $ecriture] = $this->clientApi(['registrations:write']);
        $this->resetAuthGuards();
        $creee = $this->postApi('/api/v1/registrations/reenroll/'.$e->id, [
            'annee_universitaire_id' => $this->anneeSuivante->id,
            'confirmer' => '1',
        ], $ecriture)->assertCreated()->json('data');

        $this->assertSame('L2', $creee['niveau']);

        // Rejeu idempotent : même inscription, pas de doublon.
        $this->postApi('/api/v1/registrations/reenroll/'.$e->id, [
            'annee_universitaire_id' => $this->anneeSuivante->id,
            'confirmer' => '1',
        ], $ecriture)->assertCreated();

        $this->assertSame(1, InscriptionAcademique::where('etudiant_id', $e->id)
            ->where('annee_universitaire_id', $this->anneeSuivante->id)->count());
    }

    public function test_api_sans_scope_write_refuse(): void
    {
        $e = $this->etudiant('RINSAPI02');

        [, $lecture] = $this->clientApi(['archives:read']);

        $this->postApi('/api/v1/registrations/reenroll/'.$e->id, [
            'annee_universitaire_id' => $this->anneeSuivante->id,
            'confirmer' => '1',
        ], $lecture)->assertForbidden();
    }
}
