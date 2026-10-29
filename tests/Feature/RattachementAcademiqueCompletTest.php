<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\TypeDiplome;
use App\Modules\Schedule\Models\Group;
use App\Services\RattachementAcademiqueCompletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests d'harmonisation et de rattachement académique complet des étudiants :
 * parcours LMD (programme), groupe et niveau référentiel.
 */
class RattachementAcademiqueCompletTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte1;

    private Faculte $faculte2;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.referentiel.active' => true]);

        AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-08-31',
            'active' => true,
        ]);

        TypeDiplome::create(['code' => 'LIC', 'nom' => 'Licence']);
        TypeDiplome::create(['code' => 'ING', 'nom' => 'Ingénieur']);
        TypeDiplome::create(['code' => 'MAS', 'nom' => 'Master']);
        TypeDiplome::create(['code' => 'DOC', 'nom' => 'Doctorat']);

        $this->faculte1 = Faculte::create([
            'nom' => 'Faculté des Sciences et Techniques',
            'code' => 'FST',
            'active' => true,
        ]);

        $this->faculte2 = Faculte::create([
            'nom' => 'Faculté de Droit, Économie et Gestion',
            'code' => 'FDEG',
            'active' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Test',
            'email' => 'admin.test@univ.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    private function creerEtudiantTest(int $faculteId, string $cin, string $niveau = 'L1', ?int $groupeId = null): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'num_inscription' => 'ETU-'.$cin,
            'nom' => 'Nom'.$cin,
            'prenom' => 'Prenom'.$cin,
            'email' => strtolower($cin).'@univ.test',
            'date_naissance' => '2004-05-15',
            'sexe' => 'M',
            'faculte_id' => $faculteId,
            'groupe_id' => $groupeId,
            'niveau' => $niveau,
            'annee_academique' => '2026-2027',
            'adresse' => 'Campus Universitaire',
            'ville' => 'Tunis',
            'code_postal' => '1002',
            'annee_bac' => 2024,
            'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');

        return $etudiant;
    }

    /** Test de l'audit de l'état actuel de la base */
    public function test_audit_etat_initial_base_de_donnees(): void
    {
        $this->creerEtudiantTest($this->faculte1->id, 'AB100001', 'L1', null);

        $service = app(RattachementAcademiqueCompletService::class);
        $audit = $service->auditerEtatActuel();

        $this->assertSame(1, $audit['total_etudiants']);
        $this->assertSame(1, $audit['sans_groupe']);
        $this->assertSame(1, $audit['sans_programme']);
        $this->assertSame(1, $audit['sans_niveau']);
    }

    /** Test de l'initialisation des programmes et niveaux pour chaque faculté */
    public function test_initialisation_programmes_et_niveaux(): void
    {
        $service = app(RattachementAcademiqueCompletService::class);
        $referentiel = $service->initialiserReferentielParcours(null, true);

        $this->assertArrayHasKey(1, $referentiel);
        $this->assertArrayHasKey(2, $referentiel);

        $progFdeg = $referentiel[2]['programme'];
        $this->assertInstanceOf(Programme::class, $progFdeg);
        $this->assertSame('PAR-FDEG-DEG', $progFdeg->code);
        $this->assertSame(2, $progFdeg->faculte_id);

        $this->assertArrayHasKey('L1', $referentiel[2]['niveaux']);
        $this->assertArrayHasKey('L2', $referentiel[2]['niveaux']);
        $this->assertArrayHasKey('L3', $referentiel[2]['niveaux']);
        $this->assertSame('L1', $referentiel[2]['niveaux']['L1']->code);
    }

    /** Test de l'affectation d'un étudiant sans groupe et rattachement complet */
    public function test_affectation_et_rattachement_complet_etudiant(): void
    {
        $service = app(RattachementAcademiqueCompletService::class);

        // Groupe existant dans la faculté
        $groupe = Group::create([
            'code' => 'G-L1-FDEG-01',
            'nom' => 'L1 Droit Groupe 01',
            'faculte_id' => $this->faculte2->id,
            'niveau' => 'L1',
            'actif' => true,
        ]);

        // Étudiant sans groupe, sans programme, sans niveau_id
        $etudiant = $this->creerEtudiantTest($this->faculte2->id, 'CD200002', 'L1', null);

        // Inscription académique orpheline
        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => 1,
            'niveau' => 'L1',
            'statut' => 'valide',
        ]);

        // Exécution de l'harmonisation complète
        $resultat = $service->executerHarmonisation(null, true);

        $etudiant->refresh();
        $inscription->refresh();
        $groupe->refresh();

        // Vérifications de l'étudiant
        $this->assertNotNull($etudiant->groupe_id);
        $this->assertSame($groupe->id, $etudiant->groupe_id);
        $this->assertNotNull($etudiant->programme_id);
        $this->assertNotNull($etudiant->niveau_id);

        $niveau = Niveau::find($etudiant->niveau_id);
        $this->assertSame('L1', $niveau->code);
        $this->assertSame($etudiant->programme_id, $niveau->programme_id);

        // Vérifications de l'inscription académique
        $this->assertSame($etudiant->programme_id, $inscription->programme_id);
        $this->assertSame($etudiant->niveau_id, $inscription->niveau_id);
        $this->assertSame($groupe->code, $inscription->groupe);

        // Vérifications du groupe
        $this->assertSame($etudiant->programme_id, $groupe->programme_id);
        $this->assertSame($etudiant->niveau_id, $groupe->niveau_id);
    }

    /** Test de la commande Artisan CLI */
    public function test_commande_artisan_harmoniser_parcours(): void
    {
        $this->artisan('referentiel:harmoniser-etudiants-parcours')
            ->expectsOutputToContain('SIMULATION / AUDIT SEUL (DRY-RUN)')
            ->assertExitCode(0);

        $this->artisan('referentiel:harmoniser-etudiants-parcours', ['--force' => true])
            ->expectsOutputToContain('ÉCRITURE RÉELLE (--force)')
            ->expectsOutputToContain('Harmonisation réussie')
            ->assertExitCode(0);
    }
}
