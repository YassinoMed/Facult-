<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\SearchDocument;
use App\Models\User;
use App\Services\Recherche\DbSearchEngine;
use App\Services\Recherche\SearchIndexer;
use App\Services\Recherche\SearchNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 18 §18.8 : recherche universelle (normalisation, sécurité,
 * identifiants, indexation, dérive).
 */
class RechercheUniversellePhase18Test extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private User $adminA;

    private Etudiant $etudiantA;

    private Etudiant $etudiantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteA = Faculte::create(['nom' => 'FST', 'code' => 'R18A', 'description' => 'T', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'FLSH', 'code' => 'R18B', 'description' => 'T', 'active' => true]);

        $this->adminA = User::creerCompteBackOffice([
            'name' => 'Fac A', 'email' => 'faca.r18@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $this->etudiantA = Etudiant::create([
            'cin' => 'R1800001', 'nom' => 'Ben Ahmed', 'prenom' => 'Mohamed',
            'email' => 'a.r18@universite.test', 'date_naissance' => '2003-05-01',
            'sexe' => 'M', 'faculte_id' => $this->faculteA->id,
            'niveau' => 'L2', 'annee_academique' => '2026-2027', 'adresse' => 'X',
            'ville' => 'Tunis', 'code_postal' => '1000', 'annee_bac' => '2023',
            'password' => 'Etudiant@123',
        ]);

        $this->etudiantB = Etudiant::create([
            'cin' => 'R1800002', 'nom' => 'Trabelsi', 'prenom' => 'Yassine',
            'email' => 'b.r18@universite.test', 'date_naissance' => '2003-06-01',
            'sexe' => 'M', 'faculte_id' => $this->faculteB->id,
            'niveau' => 'L2', 'annee_academique' => '2026-2027', 'adresse' => 'X',
            'ville' => 'Sfax', 'code_postal' => '3000', 'annee_bac' => '2023',
            'password' => 'Etudiant@123',
        ]);

        app(SearchIndexer::class)->indexerEtudiant($this->etudiantA);
        app(SearchIndexer::class)->indexerEtudiant($this->etudiantB);
    }

    public function test_normalisation_accents_arabe_chiffres(): void
    {
        $this->assertSame('ben ahmed', SearchNormalizer::normaliser('Ben Ahmed'));
        $this->assertSame('ben ahmed', SearchNormalizer::normaliser('Bén Ahmed'));
        // Alef variantes + tashkeel ignorés : أحمد → احمه? (taa marbouta → ه).
        $this->assertSame('احمد', SearchNormalizer::normaliser('أَحْمَد'));
        $this->assertSame('2026', SearchNormalizer::normaliser('٢٠٢٦'));

        $variantes = SearchNormalizer::variantes('محمد');
        $this->assertContains('mohamed', $variantes);
        $this->assertContains('mohamad', $variantes);
    }

    public function test_moteur_filtre_perimetre_dans_requete(): void
    {
        $moteur = app(DbSearchEngine::class);

        $resultats = $moteur->chercher($this->adminA, 'Ben Ahmed');

        $ids = array_column($resultats, 'entite_id');
        $this->assertContains($this->etudiantA->id, $ids);
        $this->assertNotContains($this->etudiantB->id, $ids);
    }

    public function test_moteur_sans_permission_ne_voit_rien(): void
    {
        // Élément exigeant `document.revoke` : le profil consultation ne
        // l'a pas (§matrice) → le résultat n'existe pas pour lui (pas
        // de « 1 résultat masqué »), mais existe pour la scolarité.
        SearchDocument::create([
            'type_entite' => 'document', 'entite_id' => 999999,
            'titre' => 'Attestation confidentielle R18',
            'corps_normalise' => 'attestation confidentielle r18',
            'identifiants' => 'ATT-2026-999999',
            'faculte_id' => $this->faculteA->id,
            'permission_requise' => 'document.revoke',
        ]);

        $sansDroit = User::creerCompteBackOffice([
            'name' => 'Consult', 'email' => 'consult.r18@universite.test', 'password' => 'Motdepasse@123',
        ], 'consultation');

        $moteur = app(DbSearchEngine::class);
        $resultats = $moteur->chercher($sansDroit, 'confidentielle R18');

        foreach ($resultats as $resultat) {
            $this->assertNotSame(999999, $resultat['entite_id']);
        }

        $scolarite = User::creerCompteBackOffice([
            'name' => 'Sco', 'email' => 'sco.r18@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $visibles = $moteur->chercher($scolarite, 'confidentielle R18');
        $this->assertContains(999999, array_column($visibles, 'entite_id'));
    }

    public function test_requete_courte_rejetee_sauf_identifiant(): void
    {
        $moteur = app(DbSearchEngine::class);

        $this->assertSame([], $moteur->chercher($this->adminA, 'a'));

        // Identifiant exact court : pas de seuil.
        $resultats = $moteur->chercher($this->adminA, $this->etudiantA->num_inscription);
        $this->assertNotEmpty($resultats);
    }

    public function test_operateurs_echappes_sans_injection(): void
    {
        $moteur = app(DbSearchEngine::class);

        // Ne doit jamais lever ni tout retourner.
        $resultats = $moteur->chercher($this->adminA, '*(Ben) "Ahmed"');
        $this->assertIsArray($resultats);
    }

    public function test_indexation_retrait_anonymisation_derive(): void
    {
        $indexeur = app(SearchIndexer::class);

        $derive = $indexeur->mesurerDerive();
        $this->assertSame(0, $derive['orphelins']);
        $this->assertSame(0, $derive['manquants']);

        $indexeur->retirer('etudiant', $this->etudiantB->id);
        $derive = $indexeur->mesurerDerive();
        $this->assertSame(1, $derive['manquants']);

        $indexeur->purgerAnonymise($this->etudiantA);
        $this->assertDatabaseHas('search_documents', [
            'type_entite' => 'etudiant', 'entite_id' => $this->etudiantA->id, 'titre' => 'Anonymisé',
        ]);
    }

    public function test_commande_reindex_et_derive(): void
    {
        $this->artisan('search:reindex', ['entite' => 'etudiants'])->assertSuccessful();
        $this->artisan('search:reindex', ['--derive' => true])->assertSuccessful();
    }
}
