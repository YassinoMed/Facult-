<?php

namespace Tests\Feature\DataMesh;

use App\DataMesh\Flux\FluxVerticalDataMesh;
use App\Models\AffectationGroupe;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test du premier cas d'usage obligatoire — Flux vertical Data Mesh (§10, §11).
 */
class DataMeshVerticalFlowTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private User $admin;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Niveau $niveau;

    private Group $groupe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences de Tunis',
            'code' => 'FST',
            'active' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Directeur Scolarité',
            'email' => 'directeur.sco@fst.univ.tn',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculte->id,
        ], 'admin_scolarite');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programme = Programme::create([
            'nom' => 'Licence Sciences Informatiques',
            'code' => 'L-INFO',
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->niveau = Niveau::create([
            'nom' => 'Licence 1',
            'code' => 'L1',
            'programme_id' => $this->programme->id,
            'actif' => true,
        ]);

        $this->groupe = Group::create([
            'code' => 'L1-INFO-G1',
            'nom' => 'Groupe 1 Informatique',
            'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id,
            'niveau_id' => $this->niveau->id,
            'niveau' => 'L1',
            'capacite' => 30,
            'actif' => true,
        ]);
    }

    public function test_flux_vertical_minimal_de_bout_en_bout(): void
    {
        // Préparation d'un étudiant inscrit et validé
        $etudiant = Etudiant::create([
            'cin' => '09876543',
            'nom' => 'Gharbi',
            'prenom' => 'Youssef',
            'email' => 'youssef.gharbi@fst.univ.tn',
            'date_naissance' => '2005-03-15',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id,
            'niveau_id' => $this->niveau->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Campus El Manar',
            'ville' => 'Tunis',
            'code_postal' => '2092',
            'annee_bac' => '2024',
            'password' => 'Pass@123',
        ]);
        $etudiant->definirStatut('valide');

        InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'niveau_id' => $this->niveau->id,
            'niveau' => 'L1',
            'statut' => 'valide',
        ]);

        $serviceFlux = app(FluxVerticalDataMesh::class);
        $resultat = $serviceFlux->executer($this->admin, $this->faculte->id, $this->annee->id);

        // 1. Statut général
        $this->assertSame('SUCCES', $resultat['statut']);

        // 2. Étape 1 : Référentiel
        $this->assertSame('catalogue_academique', $resultat['etape_1_referentiel']['produit']);
        $this->assertSame('1.0', $resultat['etape_1_referentiel']['version']);
        $this->assertGreaterThanOrEqual(1, $resultat['etape_1_referentiel']['nb_programmes']);
        $this->assertGreaterThanOrEqual(1, $resultat['etape_1_referentiel']['nb_niveaux']);

        // 3. Étape 2 : Scolarité
        $this->assertSame('inscriptions_validees', $resultat['etape_2_scolarite']['produit']);
        $this->assertSame('1.1', $resultat['etape_2_scolarite']['version']);
        $this->assertSame(1, $resultat['etape_2_scolarite']['total_inscriptions_validees']);
        $this->assertTrue($resultat['etape_2_scolarite']['pii_masquee']);

        // 4. Étape 3 : Affectation
        $this->assertSame('affectations_groupes', $resultat['etape_3_affectation']['produit']);
        $this->assertSame('1.0', $resultat['etape_3_affectation']['version']);
        $this->assertSame('eligible_affectation_verifiee', $resultat['etape_3_affectation']['decision_metier']);

        // 5. Étape 4 : Pilotage
        $this->assertSame('analytique_faculte', $resultat['etape_4_pilotage']['produit']);
        $this->assertSame('1.1', $resultat['etape_4_pilotage']['version']);
        $this->assertNotNull($resultat['etape_4_pilotage']['version_agregats_md5']);
        $this->assertNotEmpty($resultat['etape_4_pilotage']['indicateurs_disponibles']);

        // 6. Étape 5 : Traçabilité et audit
        $this->assertTrue($resultat['etape_5_tracabilite']['audite']);
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $etudiant->id,
            'action' => 'data_mesh.flux_vertical_execute',
            'entite' => 'flux_vertical',
        ]);
    }

    public function test_flux_expose_etats_par_etape_et_diagnostic_zero_affectation(): void
    {
        $etudiant = Etudiant::create([
            'cin' => '11224455', 'nom' => 'Ben Ammar', 'prenom' => 'Sana',
            'email' => 'sana.benammar@fst.univ.tn', 'date_naissance' => '2005-06-01',
            'sexe' => 'F', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'Campus', 'ville' => 'Tunis',
            'code_postal' => '2092', 'annee_bac' => '2024', 'password' => 'Pass@123',
        ]);
        $etudiant->definirStatut('valide');
        InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1', 'statut' => 'valide',
        ]);

        $resultat = app(FluxVerticalDataMesh::class)->executer($this->admin, $this->faculte->id, $this->annee->id);

        $this->assertSame('SUCCES', $resultat['statut']);
        foreach (['etape_1_referentiel', 'etape_2_scolarite', 'etape_4_pilotage'] as $etape) {
            $this->assertContains($resultat[$etape]['etat'], ['terminee', 'terminee_sans_resultat'], $etape);
        }

        // Zéro affectation active : le succès ne doit pas le masquer.
        $this->assertSame(0, $resultat['etape_3_affectation']['total_affectations_actives']);
        $this->assertSame('terminee_sans_resultat', $resultat['etape_3_affectation']['etat']);
        $this->assertNotNull($resultat['etape_3_affectation']['diagnostic']);
        $this->assertStringContainsString('rattrapage', $resultat['etape_3_affectation']['diagnostic']);
    }

    public function test_flux_sans_diagnostic_si_affectations_presentes(): void
    {
        $etudiant = Etudiant::create([
            'cin' => '66778899', 'nom' => 'Trabelsi', 'prenom' => 'Omar',
            'email' => 'omar.trabelsi@fst.univ.tn', 'date_naissance' => '2004-11-20',
            'sexe' => 'M', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'Campus', 'ville' => 'Tunis',
            'code_postal' => '2092', 'annee_bac' => '2024', 'password' => 'Pass@123',
        ]);
        $etudiant->definirStatut('valide');
        InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1', 'statut' => 'valide',
        ]);
        AffectationGroupe::create([
            'etudiant_id' => $etudiant->id, 'groupe_id' => $this->groupe->id,
            'annee_universitaire_id' => $this->annee->id, 'date_debut' => '2026-09-01',
            'statut' => 'active',
        ]);

        $resultat = app(FluxVerticalDataMesh::class)->executer($this->admin, $this->faculte->id, $this->annee->id);

        $this->assertSame('terminee', $resultat['etape_3_affectation']['etat']);
        $this->assertSame(1, $resultat['etape_3_affectation']['total_affectations_actives']);
        $this->assertNull($resultat['etape_3_affectation']['diagnostic']);
    }
}
