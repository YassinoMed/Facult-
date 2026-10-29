<?php

namespace Tests\Feature;

use App\DataMesh\Catalogue;
use App\DataMesh\Contrats\ProduitDonnees;
use App\DataMesh\Qualite\ControlesDonnees;
use App\Models\AffectationGroupe;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Data Mesh : catalogue, contrats, produits, qualité.
 * Base sqlite isolée, aucune écriture métier (lecture + fixtures).
 */
class DataMeshTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private Faculte $autreFaculte;

    private AnneeUniversitaire $annee;

    private User $scolarite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->autreFaculte = Faculte::create(['nom' => 'FLSH', 'code' => 'FLSH', 'description' => 'T', 'active' => true]);
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.mesh@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
    }

    private function creerEtudiant(string $cin, ?Faculte $faculte = null): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara', 'email' => strtolower($cin).'@mesh.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => ($faculte ?? $this->faculte)->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');
        $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1', 'statut' => 'valide',
        ]);

        return $etudiant;
    }

    private function creerGroupe(string $code, ?int $capacite = 30, string $niveau = 'L1', ?Faculte $faculte = null): Group
    {
        return Group::create([
            'code' => $code, 'nom' => $code, 'faculte_id' => ($faculte ?? $this->faculte)->id,
            'niveau' => $niveau, 'capacite' => $capacite, 'actif' => true,
        ]);
    }

    public function test_catalogue_expose_quatre_produits_versionnes(): void
    {
        $codes = Catalogue::codes();

        $this->assertContains('catalogue_academique', $codes);
        $this->assertContains('inscriptions_validees', $codes);
        $this->assertContains('affectations_groupes', $codes);
        $this->assertContains('analytique_faculte', $codes);

        foreach (Catalogue::liste() as $ligne) {
            $this->assertMatchesRegularExpression('/^\d+\.\d+$/', $ligne['version']);
            $this->assertNotEmpty($ligne['domaine']);
            $this->assertInstanceOf(ProduitDonnees::class, Catalogue::produit($ligne['code']));
        }

        $this->expectException(\InvalidArgumentException::class);
        Catalogue::produit('inexistant');
    }

    public function test_contrats_conformes_decrire_et_schema(): void
    {
        $cles = ['nom', 'description', 'finalite', 'proprietaire_metier', 'source_verite', 'classification', 'fraicheur_attendue', 'consommation', 'compatibilite', 'erreurs'];

        foreach (Catalogue::codes() as $code) {
            $produit = Catalogue::produit($code);
            $this->assertSame($code, $produit->code());
            foreach ($cles as $cle) {
                $this->assertArrayHasKey($cle, $produit->decrire(), "{$code}.decrire:{$cle}");
            }
            $this->assertNotEmpty($produit->schema());
            foreach ($produit->schema() as $champ) {
                $this->assertArrayHasKey('champ', $champ);
                $this->assertArrayHasKey('type', $champ);
                $this->assertArrayHasKey('signification', $champ);
                $this->assertArrayHasKey('sensible', $champ);
            }
        }
    }

    public function test_acces_refuse_sans_consommateur_ni_role(): void
    {
        $this->expectException(AuthorizationException::class);
        Catalogue::produit('inscriptions_validees')->donnees([]);
    }

    public function test_acces_refuse_sans_role_back_office(): void
    {
        $sansRole = new User(['name' => 'Utilisateur Sans Role']);
        $sansRole->role = null;

        try {
            Catalogue::produit('catalogue_academique')->donnees(['utilisateur' => $sansRole]);
            $this->fail('Refus attendu pour un utilisateur sans rôle.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
    }

    public function test_isolation_facultaire_admin_restreint(): void
    {
        $this->creerEtudiant('MESH00001', $this->faculte);
        $this->creerEtudiant('MESH00002', $this->autreFaculte);

        $restreint = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.mesh@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculte->id,
        ], 'admin_faculte');

        // Demande explicite hors périmètre -> rejetée par AuthorizationException
        try {
            Catalogue::produit('inscriptions_validees')->donnees([
                'utilisateur' => $restreint, 'faculte_id' => $this->autreFaculte->id,
            ]);
            $this->fail('Refus attendu pour une faculté hors périmètre.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('hors périmètre', $e->getMessage());
        }

        // Sans filtre : ramené automatiquement à sa faculté (1 seule ligne).
        $donnees = Catalogue::produit('inscriptions_validees')->donnees(['utilisateur' => $restreint]);
        $this->assertSame(1, $donnees['total']);
    }

    public function test_inscriptions_validees_minimise_pii_et_idempotent(): void
    {
        $etudiant = $this->creerEtudiant('MESH00003');

        $un = Catalogue::produit('inscriptions_validees')->donnees(['utilisateur' => $this->scolarite]);
        $deux = Catalogue::produit('inscriptions_validees')->donnees(['utilisateur' => $this->scolarite]);

        $this->assertSame($un, $deux);
        $this->assertSame(1, $un['total']);
        $ligne = $un['inscriptions'][0];
        $this->assertSame('valide', $ligne['statut']);
        $this->assertStringNotContainsString($etudiant->cin, json_encode($un));
        $this->assertStringNotContainsString($etudiant->email, json_encode($un));
        $this->assertNotEmpty($ligne['etudiant']['cin_masquee']);
    }

    public function test_affectations_groupes_et_occupation_sans_n_plus_un(): void
    {
        $groupe = $this->creerGroupe('MESH-G01', 30);
        foreach (['MESH00004', 'MESH00005', 'MESH00006'] as $cin) {
            $etudiant = $this->creerEtudiant($cin);
            AffectationGroupe::create([
                'etudiant_id' => $etudiant->id, 'groupe_id' => $groupe->id,
                'annee_universitaire_id' => $this->annee->id, 'statut' => 'active',
                'date_debut' => now()->toDateString(),
            ]);
        }

        DB::enableQueryLog();
        $donnees = Catalogue::produit('affectations_groupes')->donnees(['utilisateur' => $this->scolarite]);
        $requetes = count(DB::getQueryLog());

        $this->assertSame(3, $donnees['total']);
        $this->assertSame(3, $donnees['occupation'][0]['effectif']);
        $this->assertFalse($donnees['occupation'][0]['depasse']);
        $this->assertLessThanOrEqual(15, $requetes, "N+1 suspect : {$requetes} requêtes");
    }

    public function test_controles_detectent_anomalies_reelles(): void
    {
        // Capacité inconnue.
        $this->creerGroupe('MESH-NULL', null);
        // Surcharge : capacité 1, deux affectés.
        $plein = $this->creerGroupe('MESH-PLEIN', 1);
        foreach (['MESH00007', 'MESH00008'] as $cin) {
            $etudiant = $this->creerEtudiant($cin);
            AffectationGroupe::create([
                'etudiant_id' => $etudiant->id, 'groupe_id' => $plein->id,
                'annee_universitaire_id' => $this->annee->id, 'statut' => 'active',
                'date_debut' => now()->toDateString(),
            ]);
        }
        // Doublon : deux lignes actives même étudiant/année.
        $double = $this->creerEtudiant('MESH00009');
        $autre = $this->creerGroupe('MESH-AUTRE', 30);
        foreach ([$plein->id, $autre->id] as $gid) {
            AffectationGroupe::create([
                'etudiant_id' => $double->id, 'groupe_id' => $gid,
                'annee_universitaire_id' => $this->annee->id, 'statut' => 'active',
                'date_debut' => now()->toDateString(),
            ]);
        }
        // Incompatible : groupe d'une autre faculté.
        $loin = $this->creerGroupe('MESH-LOIN', 30, 'L1', $this->autreFaculte);
        $perdu = $this->creerEtudiant('MESH00010');
        AffectationGroupe::create([
            'etudiant_id' => $perdu->id, 'groupe_id' => $loin->id,
            'annee_universitaire_id' => $this->annee->id, 'statut' => 'active',
            'date_debut' => now()->toDateString(),
        ]);

        $rapport = app(ControlesDonnees::class)->controler($this->scolarite, null, $this->annee->id);
        $parRegle = collect($rapport['resultats'])->keyBy('regle');

        $this->assertGreaterThanOrEqual(1, $parRegle['capacites_inconnues']['anomalies']);
        $this->assertContains('MESH-NULL', $parRegle['capacites_inconnues']['exemples']);
        $this->assertGreaterThanOrEqual(1, $parRegle['groupes_surcharges']['anomalies']);
        $this->assertGreaterThanOrEqual(1, $parRegle['doublons_affectations']['anomalies']);
        $this->assertGreaterThanOrEqual(1, $parRegle['affectations_incompatibles']['anomalies']);
        $this->assertSame('bloquant', $parRegle['doublons_affectations']['gravite']);
        $this->assertNotEmpty($rapport['date_controle']);
        foreach ($rapport['resultats'] as $ligne) {
            $this->assertArrayHasKey('perimetre', $ligne);
        }
    }

    public function test_analytique_faculte_versionnee_et_sans_ligne_individuelle(): void
    {
        $this->creerEtudiant('MESH00011');

        $un = Catalogue::produit('analytique_faculte')->donnees(['utilisateur' => $this->scolarite]);
        $deux = Catalogue::produit('analytique_faculte')->donnees(['utilisateur' => $this->scolarite]);

        $this->assertSame($un['version_agregats'], $deux['version_agregats']);
        $this->assertNotEmpty($un['indicateurs']);
        $this->assertStringNotContainsString('MESH00011', json_encode($un));
    }

    public function test_pagination_bornee_et_deterministe(): void
    {
        foreach (['MESHP001', 'MESHP002', 'MESHP003', 'MESHP004', 'MESHP005'] as $cin) {
            $this->creerEtudiant($cin);
        }

        $produit = Catalogue::produit('inscriptions_validees');
        $this->assertSame('1.1', $produit->version());

        $p1 = $produit->donnees(['utilisateur' => $this->scolarite, 'page' => 1, 'par_page' => 2]);
        $this->assertSame(5, $p1['total']);
        $this->assertSame(1, $p1['page']);
        $this->assertSame(2, $p1['par_page']);
        $this->assertSame(3, $p1['pages']);
        $this->assertCount(2, $p1['inscriptions']);

        $p3 = $produit->donnees(['utilisateur' => $this->scolarite, 'page' => 3, 'par_page' => 2]);
        $this->assertCount(1, $p3['inscriptions']);

        // Ordre déterministe : les 3 pages couvrent les 5 références sans doublon.
        $refs = [];
        foreach ([1, 2, 3] as $page) {
            $d = $produit->donnees(['utilisateur' => $this->scolarite, 'page' => $page, 'par_page' => 2]);
            foreach ($d['inscriptions'] as $ligne) {
                $refs[] = $ligne['reference'];
            }
        }
        $this->assertCount(5, array_unique($refs));

        // Bornes : page 0 → 1, par_page excessif → 1000, tri invalide → repli id.
        $bornes = $produit->donnees(['utilisateur' => $this->scolarite, 'page' => 0, 'par_page' => 9999, 'tri' => 'injection', 'sens' => 'sideways']);
        $this->assertSame(1, $bornes['page']);
        $this->assertSame(1000, $bornes['par_page']);
        $this->assertCount(5, $bornes['inscriptions']);
    }

    public function test_pagination_filtres_et_compatibilite_v10(): void
    {
        $this->creerEtudiant('MESHF001');

        $filtre = Catalogue::produit('inscriptions_validees')->donnees([
            'utilisateur' => $this->scolarite, 'niveau' => 'L1',
        ]);
        $this->assertSame(1, $filtre['total']);

        $vide = Catalogue::produit('inscriptions_validees')->donnees([
            'utilisateur' => $this->scolarite, 'niveau' => 'D3',
        ]);
        $this->assertSame(0, $vide['total']);
        $this->assertSame(0, $vide['pages']);

        // Compatibilité v1.0 : clés historiques présentes et stables.
        $this->assertArrayHasKey('total', $filtre);
        $this->assertArrayHasKey('inscriptions', $filtre);
        $this->assertArrayHasKey('reference', $filtre['inscriptions'][0]);
        $this->assertArrayHasKey('etudiant', $filtre['inscriptions'][0]);
    }
}
