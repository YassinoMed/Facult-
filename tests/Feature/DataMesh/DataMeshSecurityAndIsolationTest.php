<?php

namespace Tests\Feature\DataMesh;

use App\DataMesh\Catalogue;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Referentiel\Models\Programme;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests de sécurité, RBAC, isolation facultaire et minimisation PII (§8, §11).
 */
class DataMeshSecurityAndIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private User $superAdmin;

    private User $adminFaculteA;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteA = Faculte::create(['nom' => 'Faculté des Sciences', 'code' => 'FST', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'Faculté des Lettres', 'code' => 'FLSH', 'active' => true]);

        $this->superAdmin = User::creerCompteBackOffice([
            'name' => 'Super Admin',
            'email' => 'super@universite.test',
            'password' => 'Motdepasse@123',
        ], 'super_admin');

        $this->adminFaculteA = User::creerCompteBackOffice([
            'name' => 'Admin FST',
            'email' => 'fst@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);
    }

    public function test_consommateur_non_authentifie_est_rejete(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Produit de données : consommateur non authentifié.');

        $produit = Catalogue::produit('catalogue_academique');
        $produit->donnees([]);
    }

    public function test_utilisateur_sans_role_back_office_est_rejete(): void
    {
        $simpleUser = new User(['name' => 'Sans Role']);
        $simpleUser->role = null;

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Produit de données : rôle back-office requis.');

        $produit = Catalogue::produit('catalogue_academique');
        $produit->donnees(['utilisateur' => $simpleUser]);
    }

    public function test_isolation_facultaire_stricte_pour_admin_faculte(): void
    {
        Programme::create(['nom' => 'Licence Info', 'code' => 'L-INFO', 'faculte_id' => $this->faculteA->id, 'actif' => true]);
        Programme::create(['nom' => 'Licence Histoire', 'code' => 'L-HIST', 'faculte_id' => $this->faculteB->id, 'actif' => true]);

        $produit = Catalogue::produit('catalogue_academique');

        // L'admin FST ne reçoit que les programmes de sa propre faculté
        $donnees = $produit->donnees(['utilisateur' => $this->adminFaculteA]);

        $programmes = $donnees['programmes'];
        $this->assertCount(1, $programmes);
        $this->assertSame('L-INFO', $programmes[0]['code']);
        $this->assertSame('FST', $programmes[0]['faculte_code']);
    }

    public function test_tentative_acces_faculte_hors_perimetre_leve_exception(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Produit de données : faculté hors périmètre.');

        // L'admin FST tente de requêter explicitement la faculté B
        $userRestreint = User::creerCompteBackOffice([
            'name' => 'Admin Fac A',
            'email' => 'admin.fsta@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $produit = Catalogue::produit('catalogue_academique');
        $produit->donnees([
            'utilisateur' => $userRestreint,
            'faculte_id' => $this->faculteB->id,
        ]);
    }

    public function test_super_admin_peut_consulter_toutes_les_facultes(): void
    {
        Programme::create(['nom' => 'Licence Info', 'code' => 'L-INFO', 'faculte_id' => $this->faculteA->id, 'actif' => true]);
        Programme::create(['nom' => 'Licence Histoire', 'code' => 'L-HIST', 'faculte_id' => $this->faculteB->id, 'actif' => true]);

        $produit = Catalogue::produit('catalogue_academique');
        $donnees = $produit->donnees(['utilisateur' => $this->superAdmin]);

        $this->assertCount(2, $donnees['programmes']);
    }

    public function test_minimisation_des_pii_sur_inscriptions_validees(): void
    {
        $etudiant = $this->creerEtudiant('08765432', 'Ben Salah', 'Amira', 'amira.bensalah@etudiant.univ.tn', $this->faculteA->id);

        InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1',
            'statut' => 'valide',
        ]);

        $produit = Catalogue::produit('inscriptions_validees');
        $resultat = $produit->donnees(['utilisateur' => $this->superAdmin]);

        $this->assertSame(1, $resultat['total']);
        $inscription = $resultat['inscriptions'][0];

        // Vérification de la minimisation PII
        $this->assertSame('Amira Ben Salah', $inscription['etudiant']['nom_complet']);
        $this->assertSame('08***432', $inscription['etudiant']['cin_masquee']);
        $this->assertStringContainsString('ami******@etudiant.univ.tn', $inscription['etudiant']['email_masque']);

        // Vérification formelle que les coordonnées complètes et la CIN brute ne sont pas exposées
        $this->assertArrayNotHasKey('telephone', $inscription['etudiant']);
        $this->assertArrayNotHasKey('cin', $inscription['etudiant']);
        $this->assertArrayNotHasKey('adresse', $inscription['etudiant']);
    }

    public function test_confidentialite_des_resultats_d_examens_non_publies(): void
    {
        $etudiant = $this->creerEtudiant('11223344', 'Mansour', 'Karim', 'karim@etudiant.univ.tn', $this->faculteA->id);

        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L2',
            'statut' => 'valide',
        ]);

        $deliberation = Deliberation::create([
            'code' => 'DELIB-2026-01',
            'annee_universitaire_id' => $this->annee->id,
            'statut' => 'published',
            'published_at' => now(),
        ]);

        // Décision NON publiée
        DecisionDeliberation::create([
            'deliberation_id' => $deliberation->id,
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'moyenne' => 14.50,
            'credits_obtenus' => 60,
            'credits_total' => 60,
            'decision' => 'admis',
            'publie' => false,
        ]);

        $produit = Catalogue::produit('resultats_examens');
        $donnees = $produit->donnees(['utilisateur' => $this->superAdmin]);

        // Le résultat non publié ne doit JAMAIS apparaître
        $this->assertSame(0, $donnees['total']);
        $this->assertEmpty($donnees['resultats']);

        // Publication de la décision
        DecisionDeliberation::where('etudiant_id', $etudiant->id)->update(['publie' => true]);

        $donneesPubliees = $produit->donnees(['utilisateur' => $this->superAdmin]);
        $this->assertSame(1, $donneesPubliees['total']);
        $this->assertSame(14.5, $donneesPubliees['resultats'][0]['moyenne']);
        $this->assertSame('admis', $donneesPubliees['resultats'][0]['decision']);
    }

    private function creerEtudiant(string $cin, string $nom, string $prenom, string $email, int $faculteId): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => $nom,
            'prenom' => $prenom,
            'email' => $email,
            'date_naissance' => '2004-01-01',
            'sexe' => 'F',
            'faculte_id' => $faculteId,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Rue Universitaire',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2023',
            'password' => 'Pass@123',
        ]);
        $etudiant->definirStatut('valide');

        return $etudiant;
    }

    public function test_tous_les_produits_refusent_sans_consommateur(): void
    {
        $this->assertNotEmpty(Catalogue::codes());

        foreach (Catalogue::codes() as $code) {
            try {
                Catalogue::produit($code)->donnees([]);
                $this->fail("Le produit [{$code}] aurait dû refuser sans consommateur.");
            } catch (AuthorizationException $e) {
                $this->assertStringContainsString('non authentifié', $e->getMessage(), $code);
            }
        }
    }

    public function test_produits_restreints_suivent_la_matrice_des_permissions(): void
    {
        // La matrice RBAC accorde enseignements.view / resultats.view au rôle
        // consultation : l'accès passe par les gates, pas seulement la liste
        // des rôles (règle effective documentée ici, pas supposée).
        $consultation = User::creerCompteBackOffice([
            'name' => 'Consult', 'email' => 'consult.mesh@universite.test', 'password' => 'Motdepasse@123',
        ], 'consultation');

        foreach (['affectations_enseignement', 'resultats_examens'] as $code) {
            $donnees = Catalogue::produit($code)->donnees(['utilisateur' => $consultation]);
            $this->assertArrayHasKey('total', $donnees, $code);
        }

        // Sans rôle : refusé sur ces deux produits comme sur les autres.
        $sansRole = new User(['name' => 'Sans Role']);
        $sansRole->role = null;
        foreach (['affectations_enseignement', 'resultats_examens'] as $code) {
            try {
                Catalogue::produit($code)->donnees(['utilisateur' => $sansRole]);
                $this->fail("Le produit [{$code}] aurait dû refuser sans rôle.");
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_produits_restreints_isolation_facultaire(): void
    {
        foreach (['affectations_enseignement', 'resultats_examens'] as $code) {
            try {
                Catalogue::produit($code)->donnees([
                    'utilisateur' => $this->adminFaculteA, 'faculte_id' => $this->faculteB->id,
                ]);
                $this->fail("Le produit [{$code}] aurait dû refuser la faculté hors périmètre.");
            } catch (AuthorizationException $e) {
                $this->assertStringContainsString('hors périmètre', $e->getMessage(), $code);
            }
        }
    }

    public function test_resultats_publies_visibles_sans_fuite_non_publies(): void
    {
        $etudiant = $this->creerEtudiant('99887766', 'Haddad', 'Lina', 'lina@etudiant.univ.tn', $this->faculteA->id);
        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1',
            'statut' => 'valide',
        ]);
        $deliberation = Deliberation::create([
            'code' => 'DELIB-SEC-01',
            'annee_universitaire_id' => $this->annee->id,
            'statut' => 'published',
            'published_at' => now(),
        ]);
        DecisionDeliberation::create([
            'deliberation_id' => $deliberation->id,
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'moyenne' => 15.25,
            'credits_obtenus' => 60,
            'credits_total' => 60,
            'decision' => 'admis',
            'publie' => true,
        ]);

        $donnees = Catalogue::produit('resultats_examens')->donnees(['utilisateur' => $this->superAdmin]);

        $this->assertSame(1, $donnees['total']);
        $this->assertSame(15.25, $donnees['resultats'][0]['moyenne']);
        $this->assertTrue($donnees['resultats'][0]['publie']);
        // Aucune donnée brute d'identité : seule la référence officielle circule.
        $this->assertArrayNotHasKey('nom', $donnees['resultats'][0]);
        $this->assertArrayNotHasKey('cin', $donnees['resultats'][0]);
    }
}
