<?php

namespace Tests\Feature\DataMesh;

use App\DataMesh\Catalogue;
use App\Models\AffectationGroupe;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Enseignement\Models\TypeEnseignement;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests fonctionnels des 6 produits de données Data Mesh (§4, §5, §11).
 */
class DataMeshProductsTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private User $admin;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'Faculté Polytechnique', 'code' => 'POLY', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Poly',
            'email' => 'admin.poly@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculte->id,
        ], 'admin_scolarite');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);
    }

    public function test_catalogue_academique_retourne_structure_complete(): void
    {
        $prog = Programme::create(['nom' => 'Ingénierie Logicielle', 'code' => 'ING-LOG', 'faculte_id' => $this->faculte->id, 'actif' => true]);
        Niveau::create(['nom' => '1ère Année Ingénieur', 'code' => 'ING1', 'programme_id' => $prog->id, 'actif' => true]);

        $produit = Catalogue::produit('catalogue_academique');
        $donnees = $produit->donnees(['utilisateur' => $this->admin]);

        $this->assertNotEmpty($donnees['facultes']);
        $this->assertSame('POLY', $donnees['facultes'][0]['code']);

        $this->assertNotEmpty($donnees['programmes']);
        $this->assertSame('ING-LOG', $donnees['programmes'][0]['code']);
        $this->assertSame('POLY', $donnees['programmes'][0]['faculte_code']);

        $this->assertNotEmpty($donnees['niveaux']);
        $this->assertSame('ING1', $donnees['niveaux'][0]['code']);
        $this->assertSame('ING-LOG', $donnees['niveaux'][0]['programme_code']);

        $this->assertNotNull($produit->fraicheur());
    }

    public function test_inscriptions_validees_avec_filtres_multiples(): void
    {
        $etudiant1 = $this->creerEtudiant('CIN001', 'L1');
        $etudiant2 = $this->creerEtudiant('CIN002', 'L2');

        InscriptionAcademique::create([
            'etudiant_id' => $etudiant1->id,
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1',
            'statut' => 'valide',
        ]);

        InscriptionAcademique::create([
            'etudiant_id' => $etudiant2->id,
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L2',
            'statut' => 'valide',
        ]);

        $produit = Catalogue::produit('inscriptions_validees');

        // Total sans filtre de niveau
        $tous = $produit->donnees(['utilisateur' => $this->admin]);
        $this->assertSame(2, $tous['total']);

        // Filtre niveau L1
        $l1 = $produit->donnees(['utilisateur' => $this->admin, 'niveau' => 'L1']);
        $this->assertSame(1, $l1['total']);
        $this->assertSame('L1', $l1['inscriptions'][0]['niveau']);

        // Filtre niveau L3 (inexistant)
        $l3 = $produit->donnees(['utilisateur' => $this->admin, 'niveau' => 'L3']);
        $this->assertSame(0, $l3['total']);
    }

    public function test_affectations_groupes_calcule_occupation_et_saturation(): void
    {
        $groupeLimite = Group::create([
            'code' => 'ING1-TP1',
            'nom' => 'TP1 Logiciel',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'capacite' => 2,
            'actif' => true,
        ]);

        $groupeIllimite = Group::create([
            'code' => 'ING1-AMPHI',
            'nom' => 'Amphi Général',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'capacite' => null, // Capacité non renseignée = illimitée selon convention
            'actif' => true,
        ]);

        $e1 = $this->creerEtudiant('CIN010', 'L1');
        $e2 = $this->creerEtudiant('CIN020', 'L1');
        $e3 = $this->creerEtudiant('CIN030', 'L1');

        // Affectations sur le groupe limité
        AffectationGroupe::create([
            'etudiant_id' => $e1->id,
            'groupe_id' => $groupeLimite->id,
            'annee_universitaire_id' => $this->annee->id,
            'date_debut' => '2026-09-01',
            'statut' => 'active',
        ]);
        AffectationGroupe::create([
            'etudiant_id' => $e2->id,
            'groupe_id' => $groupeLimite->id,
            'annee_universitaire_id' => $this->annee->id,
            'date_debut' => '2026-09-01',
            'statut' => 'active',
        ]);
        AffectationGroupe::create([
            'etudiant_id' => $e3->id,
            'groupe_id' => $groupeLimite->id,
            'annee_universitaire_id' => $this->annee->id,
            'date_debut' => '2026-09-01',
            'statut' => 'active',
        ]);

        $produit = Catalogue::produit('affectations_groupes');
        $donnees = $produit->donnees(['utilisateur' => $this->admin]);

        $this->assertSame(3, $donnees['total']);
        $occupation = collect($donnees['occupation'])->keyBy('groupe_code');

        $this->assertTrue($occupation->has('ING1-TP1'));
        $this->assertSame(3, $occupation['ING1-TP1']['effectif']);
        $this->assertSame(2, $occupation['ING1-TP1']['capacite']);
        $this->assertTrue($occupation['ING1-TP1']['depasse'], 'Le groupe limité à 2 avec 3 étudiants doit être marqué dépassé.');

        // Capacité null = illimitée
        $this->assertFalse($groupeIllimite->estComplet());
    }

    public function test_affectations_enseignement_expose_charges_pedagogiques(): void
    {
        $matiere = Matiere::create([
            'code' => 'ALG-01',
            'nom' => 'Algorithmique Avancée',
            'niveau' => 'L1',
            'coefficient' => 3,
            'credits' => 4,
            'semestre' => 'S1',
        ]);

        $type = TypeEnseignement::create([
            'code' => 'CM',
            'nom' => 'Cours Magistral',
            'actif' => true,
        ]);

        $enseignant = Teacher::create([
            'nom' => 'Driss',
            'prenom' => 'Mohamed',
            'email' => 'driss@univ.test',
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $enseignement = Enseignement::create([
            'code' => 'ENS-ALG-2026',
            'matiere_id' => $matiere->id,
            'type_id' => $type->id,
            'annee_universitaire_id' => $this->annee->id,
            'volume_cm' => 20,
            'volume_td' => 20,
            'statut' => 'validated',
        ]);

        $enseignement->enseignants()->attach($enseignant->id, [
            'role' => 'principal',
            'volume_heures' => 40,
            'statut' => 'active',
        ]);

        $produit = Catalogue::produit('affectations_enseignement');
        $donnees = $produit->donnees(['utilisateur' => $this->admin]);

        $this->assertGreaterThanOrEqual(1, $donnees['total']);
        $item = collect($donnees['enseignements'])->firstWhere('code', 'ENS-ALG-2026');

        $this->assertNotNull($item);
        $this->assertSame('ALG-01', $item['matiere_code']);
        $this->assertSame('Algorithmique Avancée', $item['matiere_nom']);
        $this->assertSame('Mohamed Driss', $item['enseignant_nom']);
        $this->assertSame(40, $item['volume_horaire']);
        $this->assertSame('validated', $item['statut']);
    }

    public function test_analytique_faculte_expose_agregats_versionnes(): void
    {
        $etudiant = $this->creerEtudiant('CIN999', 'L1');
        InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1',
            'statut' => 'valide',
        ]);

        $produit = Catalogue::produit('analytique_faculte');
        $donnees = $produit->donnees([
            'utilisateur' => $this->admin,
            'faculte_id' => $this->faculte->id,
            'annee_id' => $this->annee->id,
        ]);

        $this->assertArrayHasKey('version_agregats', $donnees);
        $this->assertSame(32, strlen($donnees['version_agregats']), 'La version des agrégats doit être un hash MD5 de 32 caractères.');

        $this->assertArrayHasKey('indicateurs', $donnees);
        $this->assertIsArray($donnees['indicateurs']);
    }

    private function creerEtudiant(string $cin, string $niveau): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => 'Nom'.$cin,
            'prenom' => 'Prenom'.$cin,
            'email' => strtolower($cin).'@univ.test',
            'date_naissance' => '2004-01-01',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => $niveau,
            'annee_academique' => '2026-2027',
            'adresse' => 'Campus',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2023',
            'password' => 'Pass@123',
        ]);
        $etudiant->definirStatut('valide');

        return $etudiant;
    }
}
