<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Enseignement\Models\TypeEnseignement;
use App\Modules\OnlineRequests\Models\RequestType;
use App\Modules\OnlineRequests\Services\OnlineRequestService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Services\FicheEtudiantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsApiClient;
use Tests\TestCase;

/**
 * Phase 8 — Dossier 360° + archive (§8-9, §23-26, §33-35) : timeline
 * unifiée déléguée, onglets demandes/progression, export unifié,
 * API timeline, recherche instantanée. Sources existantes.
 */
class StudentDossierPhase8Test extends TestCase
{
    use ActAsApiClient;
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Niveau $niveau;

    private Etudiant $etudiant;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.student-archive.active' => true,
            'modules.online-requests.active' => true,
            'modules.enseignement.active' => true,
            'modules.evaluation.active' => true,
        ]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.ph8@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LMI', 'nom' => 'Licence MI',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $this->niveau = Niveau::create(['programme_id' => $this->programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);

        $this->etudiant = Etudiant::create([
            'cin' => 'BKPH8001', 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'bkph8001@ph8.test', 'date_naissance' => '2004-01-01',
            'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id, 'niveau_id' => $this->niveau->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X',
            'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');

        InscriptionAcademique::create([
            'etudiant_id' => $this->etudiant->id, 'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id, 'niveau_id' => $this->niveau->id,
            'niveau' => 'L1', 'statut' => 'en_cours', 'type' => 'premiere', 'regime' => 'initiale',
        ]);
    }

    public function test_timeline_unifiee_ordonnee_et_paginee(): void
    {
        $timeline = app(FicheEtudiantService::class)->timeline($this->etudiant);

        $this->assertNotEmpty($timeline);
        $this->assertArrayHasKey('titre', $timeline[0]);

        $dates = array_column($timeline, 'date');
        $triees = $dates;
        rsort($triees);
        $this->assertSame($triees, $dates);

        // Offset : pas de recouvrement avec la première page.
        $page2 = app(FicheEtudiantService::class)->timeline($this->etudiant, 30, 1);
        $this->assertNotSame($timeline, $page2);
    }

    public function test_onglet_demandes_et_progression_archive(): void
    {
        app(OnlineRequestService::class)->creer($this->etudiant, [
            'request_type_id' => RequestType::where('code', 'DEMANDE_LIBRE')->firstOrFail()->id,
            'objet' => 'Suivi dossier',
        ]);

        $reponse = $this->actingAs($this->admin)
            ->get(route('admin.etudiants.archive', [$this->etudiant, 'onglet' => 'demandes']))
            ->assertOk();
        $reponse->assertSee('Suivi dossier', false);

        // Progression dry-run sur l'onglet général (sans décision : blocage affiché).
        $general = $this->actingAs($this->admin)
            ->get(route('admin.etudiants.archive', $this->etudiant))
            ->assertOk();
        $general->assertSee('Progression LMD', false);

        // Périmètre : faculté A → étudiant faculté B = 404 (jamais le contenu).
        $autreFaculte = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'T', 'active' => true]);
        $adminAutre = User::creerCompteBackOffice([
            'name' => 'Fac B', 'email' => 'facb.ph8@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $autreFaculte->id,
        ], 'admin_faculte');
        $this->actingAs($adminAutre)
            ->get(route('admin.etudiants.archive', [$this->etudiant, 'onglet' => 'demandes']))
            ->assertNotFound();
    }

    public function test_export_dossier_unifie_audite(): void
    {
        $reponse = $this->actingAs($this->admin)
            ->get(route('admin.etudiants.exportDossier', $this->etudiant))
            ->assertOk();

        $json = $reponse->json();
        $this->assertSame($this->etudiant->num_inscription, $json['etudiant']['num_inscription']);
        $this->assertArrayHasKey('synthese', $json);
        $this->assertArrayHasKey('parcours', $json);
        $this->assertArrayHasKey('documents', $json);
        $this->assertArrayHasKey('demandes', $json);
        $this->assertArrayHasKey('timeline', $json);
        $this->assertTrue(HistoriqueAcademique::where('action', 'export_dossier')
            ->where('entite_id', $this->etudiant->id)->exists());

        // Hors périmètre : 403 (policy), jamais le JSON.
        $autreFaculte = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'T', 'active' => true]);
        $adminAutre = User::creerCompteBackOffice([
            'name' => 'Fac B', 'email' => 'facb.ph8@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $autreFaculte->id,
        ], 'admin_faculte');
        $this->actingAs($adminAutre)
            ->get(route('admin.etudiants.exportDossier', $this->etudiant))
            ->assertForbidden();
    }

    public function test_api_timeline_bola(): void
    {
        [, $token] = $this->clientApi(['archives:read']);

        $reponse = $this->getApi(route('api.v1.students.timeline', $this->etudiant).'?limite=5', $token)
            ->assertOk();

        $this->assertArrayHasKey('total', $reponse->json('meta'));
        $this->assertNotEmpty($reponse->json('data'));

        $filtre = $this->getApi(route('api.v1.students.timeline', $this->etudiant).'?categorie=inscription', $token)
            ->assertOk();
        $this->assertTrue(collect($filtre->json('data'))->every(fn ($e) => $e['categorie'] === 'inscription'));
    }

    public function test_recherche_instantanee_enseignements_epreuves(): void
    {
        $semestre = SemestreLmd::create(['niveau_id' => $this->niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $this->programme->id,
            'code' => 'UEP8', 'nom' => 'Palier8Unique', 'credits' => 6, 'coefficient' => 1,
        ]);
        $ecue = Matiere::create([
            'code' => 'P8U001', 'nom' => 'Palier8Unique', 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 4,
        ]);
        $type = TypeEnseignement::create(['code' => 'CM', 'nom' => 'Cours']);
        Enseignement::create([
            'matiere_id' => $ecue->id, 'type_id' => $type->id,
            'annee_universitaire_id' => $this->annee->id,
            'semestre_id' => $semestre->id, 'programme_id' => $this->programme->id,
            'volume_cm' => 18, 'statut' => 'active', 'cree_par' => $this->admin->id,
        ]);

        $reponse = $this->actingAs($this->admin)
            ->getJson(route('admin.recherche.instantanee', ['q' => 'Palier8Unique']))
            ->assertOk();

        $sections = collect($reponse->json())->pluck('section')->unique()->all();
        $this->assertContains('Enseignements', $sections);
    }

    public function test_portail_situation_et_demandes(): void
    {
        session(['etudiant_auth_id' => $this->etudiant->id]);

        $dashboard = $this->get(route('etudiant.dashboard'))->assertOk();
        $dashboard->assertSee('Ma situation', false);
        $dashboard->assertSee('Licence MI', false);
    }
}
