<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Enseignement\Models\TypeEnseignement;
use App\Modules\Enseignement\Services\ServiceEnseignantService;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Teacher;
use App\Services\DataExchange\DefinitionRegistry;
use App\Services\InscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4 — Enseignement (§10-12, §16, §18) : import/export
 * inscriptions pédagogiques, bulk ECUE, services scopés faculté,
 * recherche enseignements — sans duplication (services existants).
 */
class EnseignementPhase4Test extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $adminFaculte;

    private Faculte $faculte;

    private Faculte $autreFaculte;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Niveau $niveau;

    private UniteEnseignement $ue;

    private Matiere $ecue;

    private Matiere $ecueB;

    private Etudiant $etudiant;

    private InscriptionAcademique $inscription;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.enseignement.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->autreFaculte = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.ph4@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.ph4@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculte->id,
        ], 'admin_faculte');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LMI', 'nom' => 'Licence MI',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $this->niveau = Niveau::create(['programme_id' => $this->programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $semestre = SemestreLmd::create(['niveau_id' => $this->niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1]);
        $this->ue = UniteEnseignement::create(['semestre_id' => $semestre->id, 'programme_id' => $this->programme->id,
            'code' => 'UE01', 'nom' => 'Bases', 'credits' => 6, 'coefficient' => 2,
        ]);
        $this->ecue = Matiere::create([
            'code' => 'INF101', 'nom' => 'Informatique', 'ue_id' => $this->ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 4,
        ]);
        $this->ecueB = Matiere::create([
            'code' => 'INF102', 'nom' => 'Algorithmique', 'ue_id' => $this->ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 4,
        ]);

        Maquette::create([
            'programme_id' => $this->programme->id, 'annee_universitaire_id' => $this->annee->id,
            'version' => 1, 'statut' => 'active', 'auteur_id' => $this->admin->id,
        ])->unitesEnseignement()->attach($this->ue->id, ['semestre_id' => $semestre->id]);

        $this->etudiant = Etudiant::create([
            'cin' => 'BKPH4001', 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'bkph4001@ph4.test', 'date_naissance' => '2004-01-01',
            'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id, 'niveau_id' => $this->niveau->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X',
            'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');

        $this->inscription = InscriptionAcademique::create([
            'etudiant_id' => $this->etudiant->id, 'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id, 'niveau_id' => $this->niveau->id,
            'niveau' => 'L1', 'statut' => 'en_cours', 'type' => 'premiere', 'regime' => 'initiale',
        ]);
    }

    public function test_bulk_ecue_rapporte_crees_existants_erreurs(): void
    {
        $service = app(InscriptionService::class);

        $rapport = $service->inscrireEcues($this->inscription, [$this->ecue->id, $this->ecueB->id], $this->admin->id);

        $this->assertSame(2, $rapport['crees']);
        $this->assertSame([], $rapport['erreurs']);

        // Rejeu : idempotent, aucune ligne supplémentaire.
        $rejeu = $service->inscrireEcues($this->inscription, [$this->ecue->id, $this->ecueB->id, 999999], $this->admin->id);

        $this->assertSame(0, $rejeu['crees']);
        $this->assertSame(2, $rejeu['existants']);
        $this->assertArrayHasKey(999999, $rejeu['erreurs']);
        $this->assertSame(2, $this->inscription->pedagogiques()->count());
    }

    public function test_route_lot_exige_permission_et_rapporte(): void
    {
        $this->actingAs($this->admin)->post(route('admin.inscriptions.ecues.lot', $this->inscription), [
            'matiere_ids' => [$this->ecue->id],
        ])->assertRedirect();

        $this->assertTrue($this->inscription->pedagogiques()->where('matiere_id', $this->ecue->id)->exists());
    }

    public function test_definition_inscriptions_pedagogiques_import_export(): void
    {
        $registre = app(DefinitionRegistry::class);

        $this->assertContains('inscriptions-pedagogiques', DefinitionRegistry::entites());

        $definition = $registre->import('inscriptions-pedagogiques');

        $this->assertSame(
            'inscrit : INF101',
            $definition->persister(['matricule' => $this->etudiant->num_inscription, 'code_matiere' => 'inf101', 'annee' => '2026-2027'], 'creer', $this->admin->id)
        );
        $this->assertSame(
            'déjà inscrit : INF101',
            $definition->persister(['matricule' => $this->etudiant->num_inscription, 'code_matiere' => 'inf101', 'annee' => '2026-2027'], 'creer', $this->admin->id)
        );

        try {
            $definition->persister(['matricule' => 'MAT-INCONNU', 'code_matiere' => 'INF101', 'annee' => '2026-2027'], 'creer', $this->admin->id);
            $this->fail('Ligne inconnue aurait dû être refusée.');
        } catch (\DomainException) {
            $this->assertTrue(true);
        }
    }

    public function test_tableau_services_scope_faculte(): void
    {
        $departement = Departement::create([
            'faculte_id' => $this->faculte->id, 'code' => 'INFO', 'nom' => 'Informatique', 'actif' => true,
        ]);
        $dedans = Teacher::create([
            'nom' => 'Dedans', 'prenom' => 'A', 'email' => 'dedans@ph4.test',
            'departement_id' => $departement->id, 'actif' => true, 'statut' => 'actif',
        ]);
        $dehors = Teacher::create([
            'nom' => 'Dehors', 'prenom' => 'B', 'email' => 'dehors@ph4.test', 'actif' => true, 'statut' => 'actif',
        ]);

        $tableau = app(ServiceEnseignantService::class)->tableauServices(null, $this->faculte->id);
        $ids = $tableau->map(fn ($ligne) => $ligne['teacher']->id)->all();

        $this->assertContains($dedans->id, $ids);
        $this->assertNotContains($dehors->id, $ids);

        // Sans filtre : tout le monde.
        $global = app(ServiceEnseignantService::class)->tableauServices(null, null);
        $this->assertContains($dehors->id, $global->map(fn ($ligne) => $ligne['teacher']->id)->all());
    }

    public function test_recherche_enseignements_scopee(): void
    {
        $type = TypeEnseignement::create(['code' => 'CM', 'nom' => 'Cours magistral']);
        Enseignement::create([
            'matiere_id' => $this->ecue->id, 'type_id' => $type->id,
            'annee_universitaire_id' => $this->annee->id,
            'semestre_id' => $this->ecue->ue->semestre_id, 'programme_id' => $this->programme->id,
            'volume_cm' => 18, 'statut' => 'active', 'cree_par' => $this->admin->id,
        ]);

        $reponse = $this->actingAs($this->adminFaculte)
            ->get(route('admin.recherche', ['q' => 'INF101']))
            ->assertOk();

        $sections = $reponse->viewData('sections');
        $this->assertArrayHasKey('Enseignements', $sections);
        $this->assertNotEmpty($sections['Enseignements']['resultats']);
    }

    public function test_recherche_enseignements_hors_perimetre_absente(): void
    {
        $autreProgramme = Programme::create([
            'faculte_id' => $this->autreFaculte->id, 'code' => 'LMP', 'nom' => 'Licence MP',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $autreNiveau = Niveau::create(['programme_id' => $autreProgramme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $autreSemestre = SemestreLmd::create(['niveau_id' => $autreNiveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1]);
        $autreUe = UniteEnseignement::create([
            'semestre_id' => $autreSemestre->id, 'programme_id' => $autreProgramme->id,
            'code' => 'UE99', 'nom' => 'ZZZIntrouvable', 'credits' => 6, 'coefficient' => 1,
        ]);
        $autreEcue = Matiere::create([
            'code' => 'ZZZ999', 'nom' => 'ZZZIntrouvable', 'ue_id' => $autreUe->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 4,
        ]);
        $type = TypeEnseignement::create(['code' => 'CM', 'nom' => 'Cours magistral']);
        Enseignement::create([
            'matiere_id' => $autreEcue->id, 'type_id' => $type->id,
            'annee_universitaire_id' => $this->annee->id,
            'semestre_id' => $autreSemestre->id, 'programme_id' => $autreProgramme->id,
            'volume_cm' => 10, 'statut' => 'active', 'cree_par' => $this->admin->id,
        ]);

        $reponse = $this->actingAs($this->adminFaculte)
            ->get(route('admin.recherche', ['q' => 'ZZZIntrouvable']))
            ->assertOk();

        $sections = $reponse->viewData('sections');
        $this->assertTrue(! isset($sections['Enseignements']) || $sections['Enseignements']['resultats']->isEmpty());
    }
}
