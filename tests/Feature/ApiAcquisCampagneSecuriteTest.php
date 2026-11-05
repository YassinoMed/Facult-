<?php

namespace Tests\Feature;

use App\Jobs\ExecuterCampagneRedoublementJob;
use App\Models\AnneeUniversitaire;
use App\Models\CampagneRedoublement;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\TypeDiplome;
use App\Modules\Schedule\Models\Group;
use App\Services\Acquis\CampagneRedoublementMasseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * LOOP 32 — Sécurité de l'API « campagne de redoublement de masse ».
 *
 * Couvre les constats A1 (`groupe_id` ignoré) et A2 (aucun RBAC sur
 * l'exécution de masse), ainsi que A5 (`formation_id` renommé en interne)
 * et A6 (année `0` retombant silencieusement sur « dernière année »).
 */
class ApiAcquisCampagneSecuriteTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private Programme $programmeA;

    private Niveau $niveauA;

    private AnneeUniversitaire $annee;

    private Group $groupeA1;

    private Group $groupeA2;

    private Group $groupeB1;

    private Etudiant $etudiantA1;

    private Etudiant $etudiantA2;

    private Etudiant $etudiantB1;

    private User $adminFaculteA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté A', 'code' => 'FACA', 'active' => true,
        ]);
        $this->faculteB = Faculte::create([
            'nom' => 'Faculté B', 'code' => 'FACB', 'active' => true,
        ]);

        $typeDiplome = TypeDiplome::create([
            'code' => 'L', 'nom' => 'Licence', 'cycle' => 'premier_cycle', 'actif' => true,
        ]);

        $this->programmeA = Programme::create([
            'faculte_id' => $this->faculteA->id,
            'type_diplome_id' => $typeDiplome->id,
            'code' => 'LIC-A',
            'nom' => 'Licence A',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
            'actif' => true,
        ]);

        $this->niveauA = Niveau::create([
            'programme_id' => $this->programmeA->id,
            'code' => 'L1', 'nom' => 'Licence 1', 'ordre' => 1, 'actif' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026',
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30',
            'active' => true,
        ]);

        $this->groupeA1 = Group::create([
            'code' => 'A1', 'nom' => 'Groupe A1', 'faculte_id' => $this->faculteA->id,
            'niveau' => 'L1', 'actif' => true,
        ]);
        $this->groupeA2 = Group::create([
            'code' => 'A2', 'nom' => 'Groupe A2', 'faculte_id' => $this->faculteA->id,
            'niveau' => 'L1', 'actif' => true,
        ]);
        $this->groupeB1 = Group::create([
            'code' => 'B1', 'nom' => 'Groupe B1', 'faculte_id' => $this->faculteB->id,
            'niveau' => 'L1', 'actif' => true,
        ]);

        $this->etudiantA1 = $this->creerEtudiant('07000001', $this->faculteA, $this->groupeA1);
        $this->etudiantA2 = $this->creerEtudiant('07000002', $this->faculteA, $this->groupeA2);
        $this->etudiantB1 = $this->creerEtudiant('07000003', $this->faculteB, $this->groupeB1);

        $this->adminFaculteA = $this->creerCompte('admin.faca@test.local', 'admin_faculte', $this->faculteA->id);
    }

    // ── A2 : RBAC dédié, non déductible du seul scope Sanctum ─────────────

    public function test_un_role_sans_permission_ne_peut_pas_lancer_une_campagne_meme_avec_le_scope(): void
    {
        $agent = $this->creerCompte('agent@test.local', 'agent_scolarite', $this->faculteA->id);

        Sanctum::actingAs($agent, ['archives:write']);

        $this->postJson('/api/v1/acquis/campagne/lancer', [
            'annee_universitaire_id' => $this->annee->id,
            'dry_run' => true,
        ])->assertForbidden();
    }

    public function test_un_role_lecture_seul_ne_peut_pas_lancer_une_campagne(): void
    {
        $consultation = $this->creerCompte('consultation@test.local', 'consultation', $this->faculteA->id);

        Sanctum::actingAs($consultation, ['archives:write']);

        $this->postJson('/api/v1/acquis/campagne/lancer', [
            'annee_universitaire_id' => $this->annee->id,
            'dry_run' => true,
        ])->assertForbidden();
    }

    public function test_un_admin_faculte_titulaire_de_la_permission_peut_lancer(): void
    {
        Sanctum::actingAs($this->adminFaculteA, ['archives:write']);

        $this->postJson('/api/v1/acquis/campagne/lancer', [
            'annee_universitaire_id' => $this->annee->id,
            'dry_run' => true,
        ])->assertOk()->assertJsonPath('mode', 'simulation_preparatoire');
    }

    // ── A1 : le groupe filtre réellement la cohorte ──────────────────────

    public function test_le_groupe_filtre_reellement_la_cohorte(): void
    {
        Sanctum::actingAs($this->adminFaculteA, ['archives:write']);

        $reponse = $this->postJson('/api/v1/acquis/campagne/lancer', [
            'annee_universitaire_id' => $this->annee->id,
            'groupe_id' => $this->groupeA1->id,
            'dry_run' => true,
        ])->assertOk();

        $this->assertSame(1, $reponse->json('donnees.total_etudiants'));
        $this->assertSame(
            [$this->etudiantA1->id],
            array_column($reponse->json('donnees.details_etudiants'), 'etudiant_id')
        );
    }

    public function test_sans_groupe_la_cohorte_couvre_tout_le_perimetre_faculte(): void
    {
        Sanctum::actingAs($this->adminFaculteA, ['archives:write']);

        $reponse = $this->postJson('/api/v1/acquis/campagne/lancer', [
            'annee_universitaire_id' => $this->annee->id,
            'dry_run' => true,
        ])->assertOk();

        $this->assertSame(2, $reponse->json('donnees.total_etudiants'));
    }

    public function test_un_groupe_hors_perimetre_renvoie_404_identique_a_un_groupe_inexistant(): void
    {
        Sanctum::actingAs($this->adminFaculteA, ['archives:write']);

        $horsPerimetre = $this->postJson('/api/v1/acquis/campagne/lancer', [
            'annee_universitaire_id' => $this->annee->id,
            'groupe_id' => $this->groupeB1->id,
            'dry_run' => true,
        ]);

        $inexistant = $this->postJson('/api/v1/acquis/campagne/lancer', [
            'annee_universitaire_id' => $this->annee->id,
            'groupe_id' => 999999,
            'dry_run' => true,
        ]);

        $horsPerimetre->assertNotFound();
        $inexistant->assertNotFound();
        $this->assertSame(
            $horsPerimetre->json('message'),
            $inexistant->json('message'),
            'Un groupe hors périmètre ne doit pas être distinguable d\'un groupe inexistant (anti-BOLA).'
        );
    }

    // ── A5 : vocabulaire canonique `programme_id`, alias historique ──────

    public function test_programme_id_et_formation_id_divergents_sont_refuses(): void
    {
        Sanctum::actingAs($this->adminFaculteA, ['archives:write']);

        $this->postJson('/api/v1/acquis/campagne/lancer', [
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programmeA->id,
            'formation_id' => $this->programmeA->id + 999,
            'dry_run' => true,
        ])->assertStatus(422);
    }

    public function test_l_alias_formation_id_reste_accepte(): void
    {
        Sanctum::actingAs($this->adminFaculteA, ['archives:write']);

        $this->postJson('/api/v1/acquis/campagne/lancer', [
            'annee_universitaire_id' => $this->annee->id,
            'formation_id' => $this->programmeA->id,
            'dry_run' => true,
        ])->assertOk()->assertJsonPath('donnees.total_etudiants', 2);
    }

    public function test_programme_id_canonique_filtre_la_cohorte(): void
    {
        Sanctum::actingAs($this->adminFaculteA, ['archives:write']);

        $this->postJson('/api/v1/acquis/campagne/lancer', [
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programmeA->id,
            'dry_run' => true,
        ])->assertOk()->assertJsonPath('donnees.total_etudiants', 2);
    }

    // ── A6 : l'année n'est jamais devinée ────────────────────────────────

    public function test_une_annee_a_zero_est_refusee_au_lieu_de_retomber_sur_la_derniere(): void
    {
        Sanctum::actingAs($this->adminFaculteA, ['archives:write']);

        $this->postJson('/api/v1/acquis/campagne/lancer', [
            'annee_universitaire_id' => 0,
            'dry_run' => true,
        ])->assertStatus(422);
    }

    // ── A3 : la campagne est persistée et suivable ───────────────────────

    public function test_une_simulation_persiste_une_campagne_sans_donnee_nominative(): void
    {
        Sanctum::actingAs($this->adminFaculteA, ['archives:write']);

        $reference = $this->postJson('/api/v1/acquis/campagne/lancer', [
            'annee_universitaire_id' => $this->annee->id,
            'groupe_id' => $this->groupeA1->id,
            'dry_run' => true,
        ])->assertOk()->json('campagne.reference');

        $campagne = CampagneRedoublement::where('reference', $reference)->firstOrFail();

        $this->assertSame(CampagneRedoublement::MODE_SIMULATION, $campagne->mode);
        $this->assertSame(CampagneRedoublement::STATUT_TERMINEE, $campagne->statut);
        $this->assertSame($this->groupeA1->id, $campagne->groupe_id);
        $this->assertSame(1, $campagne->dossiers_total);

        // Aucun nom, CIN ni détail nominatif dans le bilan persisté.
        $this->assertArrayNotHasKey('details_etudiants', $campagne->bilan);
        $this->assertStringNotContainsString('07000001', json_encode($campagne->bilan, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('Nom07000001', json_encode($campagne->bilan, JSON_UNESCAPED_UNICODE));
    }

    public function test_deux_appels_creent_deux_campagnes_distinctes_et_tracables(): void
    {
        Sanctum::actingAs($this->adminFaculteA, ['archives:write']);

        $premiere = $this->postJson('/api/v1/acquis/campagne/lancer', [
            'annee_universitaire_id' => $this->annee->id,
            'dry_run' => true,
        ])->json('campagne.reference');

        $seconde = $this->postJson('/api/v1/acquis/campagne/lancer', [
            'annee_universitaire_id' => $this->annee->id,
            'dry_run' => true,
        ])->json('campagne.reference');

        $this->assertNotSame($premiere, $seconde);
        $this->assertSame(2, CampagneRedoublement::count());
    }

    // ── A4 : exécution asynchrone + suivi ────────────────────────────────

    public function test_une_execution_repond_202_et_met_le_job_en_file(): void
    {
        Queue::fake();

        Sanctum::actingAs($this->adminFaculteA, ['archives:write']);

        $reponse = $this->postJson('/api/v1/acquis/campagne/lancer', [
            'annee_universitaire_id' => $this->annee->id,
            'dry_run' => false,
        ])->assertStatus(202);

        $reference = $reponse->json('campagne.reference');

        $campagne = CampagneRedoublement::where('reference', $reference)->firstOrFail();
        $this->assertSame(CampagneRedoublement::MODE_EXECUTION, $campagne->mode);
        $this->assertSame(CampagneRedoublement::STATUT_EN_FILE, $campagne->statut);
        $this->assertSame($this->faculteA->id, $campagne->faculte_id);

        Queue::assertPushed(ExecuterCampagneRedoublementJob::class, fn ($job) => $job->campagneId === $campagne->id);
    }

    public function test_le_job_execute_la_campagne_et_renseigne_le_bilan(): void
    {
        $campagne = CampagneRedoublement::create([
            'reference' => 'CAMP-TEST-0001',
            'statut' => CampagneRedoublement::STATUT_EN_FILE,
            'mode' => CampagneRedoublement::MODE_EXECUTION,
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculteA->id,
            'lancee_par' => $this->adminFaculteA->id,
        ]);

        (new ExecuterCampagneRedoublementJob($campagne->id))->handle(app(CampagneRedoublementMasseService::class));

        $campagne->refresh();

        $this->assertSame(CampagneRedoublement::STATUT_TERMINEE, $campagne->statut);
        $this->assertSame(2, $campagne->dossiers_total);
        $this->assertNotNull($campagne->demarree_a);
        $this->assertNotNull($campagne->terminee_a);
        $this->assertArrayHasKey('execution', $campagne->bilan);
        $this->assertArrayNotHasKey('details', $campagne->bilan['execution']);
    }

    public function test_le_job_ne_rejoue_pas_une_campagne_deja_terminee(): void
    {
        $campagne = CampagneRedoublement::create([
            'reference' => 'CAMP-TEST-0002',
            'statut' => CampagneRedoublement::STATUT_TERMINEE,
            'mode' => CampagneRedoublement::MODE_EXECUTION,
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculteA->id,
            'dossiers_total' => 7,
        ]);

        (new ExecuterCampagneRedoublementJob($campagne->id))->handle(app(CampagneRedoublementMasseService::class));

        $this->assertSame(7, $campagne->fresh()->dossiers_total);
    }

    public function test_le_suivi_renvoie_la_campagne_pour_son_perimetre(): void
    {
        $campagne = CampagneRedoublement::create([
            'reference' => 'CAMP-TEST-0003',
            'statut' => CampagneRedoublement::STATUT_TERMINEE,
            'mode' => CampagneRedoublement::MODE_SIMULATION,
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculteA->id,
            'dossiers_total' => 3,
        ]);

        Sanctum::actingAs($this->adminFaculteA, ['archives:read']);

        $this->getJson('/api/v1/acquis/campagnes/'.$campagne->reference)
            ->assertOk()
            ->assertJsonPath('donnees.reference', 'CAMP-TEST-0003')
            ->assertJsonPath('donnees.statut', CampagneRedoublement::STATUT_TERMINEE);
    }

    public function test_le_suivi_d_une_campagne_hors_perimetre_renvoie_404(): void
    {
        $campagne = CampagneRedoublement::create([
            'reference' => 'CAMP-TEST-0004',
            'statut' => CampagneRedoublement::STATUT_TERMINEE,
            'mode' => CampagneRedoublement::MODE_SIMULATION,
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculteB->id,
        ]);

        Sanctum::actingAs($this->adminFaculteA, ['archives:read']);

        $this->getJson('/api/v1/acquis/campagnes/'.$campagne->reference)->assertNotFound();
        $this->getJson('/api/v1/acquis/campagnes/CAMP-INCONNUE')->assertNotFound();
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function creerEtudiant(string $cin, Faculte $faculte, Group $groupe): Etudiant
    {
        $etudiant = Etudiant::create([
            'faculte_id' => $faculte->id,
            'programme_id' => $this->programmeA->id,
            'groupe_id' => $groupe->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'cin' => $cin,
            'nom' => 'Nom'.$cin,
            'prenom' => 'Prenom'.$cin,
            'email' => $cin.'@test.local',
            'num_inscription' => 'INS-'.$cin,
            'statut' => 'actif',
            'date_naissance' => '2004-01-01',
            'sexe' => 'M',
            'adresse' => 'Adresse',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2023',
            'password' => 'Secret@123',
        ]);

        InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programmeA->id,
            'niveau_id' => $this->niveauA->id,
            'niveau' => 'L1',
            'statut' => 'validee',
        ]);

        return $etudiant;
    }

    private function creerCompte(string $email, string $role, int $faculteId): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Compte '.$role,
            'email' => $email,
            'faculte_id' => $faculteId,
            'password' => Hash::make('Motdepasse@123'),
        ], $role);
    }
}
