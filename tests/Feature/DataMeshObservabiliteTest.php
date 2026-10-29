<?php

namespace Tests\Feature;

use App\DataMesh\Catalogue;
use App\DataMesh\Observabilite\ServiceObservabilite;
use App\DataMesh\Outbox\OutboxMessage;
use App\DataMesh\Qualite\ControlesDonnees;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Observability Center : autorisations, périmètre, non-fuite PII,
 * cohérence catalogue/graphe, états inconnus, N+1, versions.
 * Base sqlite isolée, lecture seule (aucune écriture métier).
 */
class DataMeshObservabiliteTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private User $superAdmin;

    private User $adminFaculteA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteA = Faculte::create(['nom' => 'FST', 'code' => 'FSTA', 'description' => 'T', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'FLSH', 'code' => 'FLSH', 'description' => 'T', 'active' => true]);
        $this->superAdmin = User::creerCompteBackOffice([
            'name' => 'Super Admin', 'email' => 'super.obs@universite.test', 'password' => 'Motdepasse@123',
        ], 'super_admin');
        $this->adminFaculteA = User::creerCompteBackOffice([
            'name' => 'Admin FST', 'email' => 'fst.obs@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');
        AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
    }

    public function test_invite_redirige_vers_connexion(): void
    {
        $this->get(route('admin.datamesh.index'))->assertRedirect();
        $this->getJson(route('admin.datamesh.graphe'))->assertUnauthorized();
    }

    public function test_super_admin_accede_page_et_endpoints(): void
    {
        $this->actingAs($this->superAdmin);

        $this->get(route('admin.datamesh.index'))->assertOk()->assertSee('Data Mesh');

        foreach (['graphe', 'apercu', 'flux', 'qualite', 'outbox'] as $endpoint) {
            $this->getJson(route('admin.datamesh.'.$endpoint))->assertOk();
        }
    }

    public function test_admin_faculte_dans_son_perimetre(): void
    {
        $this->actingAs($this->adminFaculteA);

        $this->get(route('admin.datamesh.index', ['faculte_id' => $this->faculteA->id]))->assertOk();
        $this->getJson(route('admin.datamesh.graphe', ['faculte_id' => $this->faculteA->id]))
            ->assertOk()
            ->assertJsonPath('perimetre', 'faculte='.$this->faculteA->id);
    }

    public function test_admin_faculte_refuse_hors_perimetre(): void
    {
        $this->actingAs($this->adminFaculteA);

        $this->get(route('admin.datamesh.index', ['faculte_id' => $this->faculteB->id]))->assertForbidden();

        foreach (['graphe', 'apercu', 'flux', 'qualite', 'outbox'] as $endpoint) {
            $this->getJson(route('admin.datamesh.'.$endpoint, ['faculte_id' => $this->faculteB->id]))
                ->assertForbidden()
                ->assertJsonPath('code', 'hors_perimetre');
        }
    }

    public function test_graphe_coherent_avec_catalogue_reel(): void
    {
        $reponse = $this->actingAs($this->superAdmin)->getJson(route('admin.datamesh.graphe'))->assertOk();

        $produits = collect($reponse->json('noeuds'))->where('type', 'produit');
        $this->assertSame(count(Catalogue::codes()), $produits->count());

        foreach (Catalogue::codes() as $code) {
            $noeud = $produits->firstWhere('code', $code);
            $this->assertNotNull($noeud, "Nœud manquant : {$code}");
            $this->assertSame(Catalogue::produit($code)->version(), $noeud['version']);
            $this->assertSame(Catalogue::produit($code)->domaine(), $noeud['domaine']);
        }

        foreach ($reponse->json('relations') as $relation) {
            $this->assertContains($relation['confirmation'], ['confirmee', 'declaree', 'inconnue']);
        }
    }

    public function test_etats_inconnus_correctement_representes_base_vide(): void
    {
        $apercu = $this->actingAs($this->superAdmin)->getJson(route('admin.datamesh.apercu'))->assertOk()->json();

        $this->assertTrue($apercu['donnees_manquantes_signalees']);
        $this->assertFalse($apercu['flux']['disponible']);
        $this->assertNull($apercu['flux']['date_reference']);

        $graphe = $this->getJson(route('admin.datamesh.graphe'))->assertOk()->json();
        $parCode = [];
        foreach (collect($graphe['noeuds'])->where('type', 'produit') as $noeud) {
            $parCode[$noeud['code']] = $noeud['etat'];
        }
        // Les 2 facultés fixtures donnent une fraîcheur réelle au référentiel…
        $this->assertSame('operationnel', $parCode['catalogue_academique']);
        // …tandis que les produits sans aucune donnée restent non vérifiés.
        $this->assertSame('non_verifie', $parCode['inscriptions_validees']);
        $this->assertSame('non_verifie', $parCode['affectations_groupes']);
        $this->assertContains('inconnu', collect($graphe['noeuds'])->pluck('etat')->all());
    }

    public function test_aucune_fuite_pii_dans_les_endpoints(): void
    {
        $this->actingAs($this->superAdmin);
        $payloads = [];

        foreach (['graphe', 'apercu', 'flux', 'qualite', 'outbox'] as $endpoint) {
            $payloads[] = $this->getJson(route('admin.datamesh.'.$endpoint))->assertOk()->getContent();
        }
        $payloads[] = $this->getJson(route('admin.datamesh.noeud', ['id' => 'produit:resultats_examens']))->assertOk()->getContent();

        foreach ($payloads as $contenu) {
            $this->assertDoesNotMatchRegularExpression('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $contenu);
            $this->assertStringNotContainsStringIgnoringCase('charge_utile', $contenu);
            $this->assertStringNotContainsStringIgnoringCase('"cin"', $contenu);
        }
    }

    public function test_noeud_inconnu_et_invalide(): void
    {
        $this->actingAs($this->superAdmin);

        $this->getJson(route('admin.datamesh.noeud', ['id' => 'produit:inexistant_xyz']))->assertNotFound();
    }

    public function test_pas_de_n_plus_un_sur_graphe_et_apercu(): void
    {
        $this->actingAs($this->superAdmin);

        foreach ([route('admin.datamesh.graphe'), route('admin.datamesh.apercu')] as $url) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($url)->assertOk();
            $this->assertLessThan(60, count(DB::getQueryLog()), "Trop de requêtes sur {$url}");
            DB::disableQueryLog();
        }
    }

    public function test_outbox_lecture_seule_sans_charge_utile(): void
    {
        $donnees = $this->actingAs($this->superAdmin)->getJson(route('admin.datamesh.outbox'))->assertOk()->json();

        $this->assertSame('au_moins_une_fois', $donnees['livraison']);
        $this->assertArrayHasKey('retention', $donnees);

        foreach ($donnees['messages'] as $message) {
            $this->assertArrayNotHasKey('charge_utile', $message);
            $this->assertArrayHasKey('anciennete_heures', $message);
        }
    }

    public function test_flux_lecture_seule_cinq_etapes_sans_execution(): void
    {
        $donnees = $this->actingAs($this->superAdmin)->getJson(route('admin.datamesh.flux'))->assertOk()->json();

        $this->assertTrue($donnees['lecture_seule']);
        $this->assertCount(5, $donnees['etapes']);
        $this->assertSame(
            ['Catalogue académique', 'Inscriptions validées', 'Affectations de groupes', 'Analytique facultaire', 'Audit et bilan global'],
            array_column($donnees['etapes'], 'libelle')
        );
        $this->assertSame(0, HistoriqueAcademique::where('action', 'data_mesh.flux_vertical_execute')->count());
    }

    public function test_etats_produits_exclusifs_somme_egale_enregistres(): void
    {
        $apercu = $this->actingAs($this->superAdmin)->getJson(route('admin.datamesh.apercu'))->assertOk()->json();

        $produits = $apercu['produits'];
        $this->assertTrue($produits['etats_exclusifs']);
        $this->assertSame(
            $produits['enregistres'],
            $produits['operationnels'] + $produits['en_erreur'] + $produits['degrades'] + $produits['non_verifies'] + $produits['inconnus']
        );

        foreach ($apercu['detail_par_produit'] as $fiche) {
            $this->assertContains($fiche['etat'], ['operationnel', 'erreur', 'degrade', 'non_verifie', 'inconnu']);
            $this->assertNotEmpty($fiche['origine_etat']);
            $this->assertArrayHasKey('diagnostic', $fiche);
            $this->assertArrayHasKey('dernier_controle', $fiche);
            $this->assertArrayHasKey('date_mesure', $fiche);
        }
    }

    public function test_qualite_violations_unites_et_non_dedup_transverse(): void
    {
        $donnees = $this->actingAs($this->superAdmin)->getJson(route('admin.datamesh.qualite'))->assertOk()->json();

        $this->assertFalse($donnees['deduplication_transverse']);
        $this->assertSame($donnees['total_violations'], array_sum(array_column($donnees['resultats'], 'violations')));

        foreach ($donnees['resultats'] as $resultat) {
            $this->assertArrayHasKey('unite_comptage', $resultat);
            $this->assertArrayHasKey('enregistrements_distincts', $resultat);
            $this->assertTrue($resultat['deduplique_intra_regle']);
            $this->assertSame($resultat['violations'], $resultat['enregistrements_distincts']);
        }
    }

    /**
     * LOT 03 — l'éditeur est désormais PLANIFIÉ (routes/console.php :
     * `datamesh:outbox-publier --retenter` toutes les 5 minutes) : l'état
     * mesuré au runtime bascule de NON_CONFIGURE (0 événement datamesh)
     * à « planifié mais exécution non confirmée » (INCONNU) tant que le
     * heartbeat `supervision:scheduler-dernier` est absent. L'intention
     * historique est préservée et renforcée : état MESURÉ (jamais codé
     * en dur), dernier succès individuel toujours INCONNU prouvé.
     */
    public function test_outbox_automatisation_mesuree_zero_non_sain(): void
    {
        $donnees = $this->actingAs($this->superAdmin)->getJson(route('admin.datamesh.outbox'))->assertOk()->json();

        $this->assertSame('INCONNU', $donnees['automatisation']['etat']);
        $this->assertSame(1, $donnees['automatisation']['evenements_planifies']);
        $this->assertNotNull($donnees['automatisation']['prochain_declenchement']);
        $this->assertNull($donnees['automatisation']['derniere_execution_publisher']);
        $this->assertNull($donnees['automatisation']['dernier_succes_confirme']);
    }

    public function test_apercu_outbox_zero_non_sain_et_fuseau(): void
    {
        $apercu = $this->actingAs($this->superAdmin)->getJson(route('admin.datamesh.apercu'))->assertOk()->json();

        $this->assertFalse($apercu['outbox']['sain_interprete']);
        $this->assertNotEmpty($apercu['outbox']['note_zero']);
        $this->assertSame('UTC', $apercu['fuseau']);
        $this->assertArrayHasKey('politique_fraicheur', $apercu);
        $this->assertArrayHasKey('calcule_le', $apercu['produits']);
    }

    public function test_role_acteur_resolu_par_repli_journal(): void
    {
        $etudiant = Etudiant::create([
            'cin' => 'EE123456', 'nom' => 'Test', 'prenom' => 'Acteur', 'email' => 'acteur.obs@universite.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculteA->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        HistoriqueAcademique::create([
            'etudiant_id' => $etudiant->id, 'utilisateur_id' => null,
            'action' => 'data_mesh.flux_vertical_execute', 'entite' => 'flux_vertical',
            'nouvelle_valeur' => json_encode(['flux_id' => 'FLUX_TEST', 'utilisateur_id' => $this->superAdmin->id]),
            'source_systeme' => 'systeme', 'created_at' => now(),
        ]);

        $donnees = $this->actingAs($this->superAdmin)->getJson(route('admin.datamesh.flux'))->assertOk()->json();

        $this->assertSame('super_admin', $donnees['derniere_execution']['acteur_role']);
        $this->assertFalse($donnees['derniere_execution']['acteur_compte_supprime']);
        $this->assertStringNotContainsStringIgnoringCase($this->superAdmin->email, json_encode($donnees));
    }

    public function test_role_acteur_compte_supprime_signale(): void
    {
        $etudiant = Etudiant::create([
            'cin' => 'EE654321', 'nom' => 'Test', 'prenom' => 'Supprime', 'email' => 'supprime.obs@universite.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculteA->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        HistoriqueAcademique::create([
            'etudiant_id' => $etudiant->id, 'utilisateur_id' => null,
            'action' => 'data_mesh.flux_vertical_execute', 'entite' => 'flux_vertical',
            'nouvelle_valeur' => json_encode(['flux_id' => 'FLUX_ORPHELIN', 'utilisateur_id' => 999999]),
            'source_systeme' => 'systeme', 'created_at' => now(),
        ]);

        $donnees = $this->actingAs($this->superAdmin)->getJson(route('admin.datamesh.flux'))->assertOk()->json();

        $this->assertNull($donnees['derniere_execution']['acteur_role']);
        $this->assertTrue($donnees['derniere_execution']['acteur_compte_supprime']);
    }

    public function test_graphe_noeuds_etats_origine_et_relations_confirmees(): void
    {
        $graphe = $this->actingAs($this->superAdmin)->getJson(route('admin.datamesh.graphe'))->assertOk()->json();

        foreach ($graphe['noeuds'] as $noeud) {
            $this->assertNotEmpty($noeud['etat'], 'Nœud sans état : '.$noeud['id']);
            if ($noeud['type'] === 'produit') {
                $this->assertNotEmpty($noeud['origine_etat'] ?? null, 'Produit sans origine : '.$noeud['id']);
                $this->assertArrayHasKey('diagnostic', $noeud);
                $this->assertArrayHasKey('dernier_controle', $noeud);
            }
        }

        foreach ($graphe['relations'] as $relation) {
            $this->assertContains($relation['confirmation'], ['confirmee', 'declaree', 'inconnue']);
            $this->assertNotEmpty($relation['source']);
        }
    }

    public function test_lecture_seule_aucun_effet_de_bord(): void
    {
        $this->actingAs($this->superAdmin);
        $tracesAvant = HistoriqueAcademique::count();
        $outboxAvant = OutboxMessage::count();

        $this->get(route('admin.datamesh.index'))->assertOk();
        foreach (['graphe', 'apercu', 'flux', 'qualite', 'outbox'] as $endpoint) {
            $this->getJson(route('admin.datamesh.'.$endpoint))->assertOk();
        }
        $this->getJson(route('admin.datamesh.noeud', ['id' => 'produit:catalogue_academique']))->assertOk();

        $this->assertSame($tracesAvant, HistoriqueAcademique::count());
        $this->assertSame($outboxAvant, OutboxMessage::count());
    }

    public function test_performance_endpoints_bornes(): void
    {
        $this->actingAs($this->superAdmin);

        foreach (['graphe', 'apercu', 'flux', 'qualite', 'outbox'] as $endpoint) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $debut = microtime(true);
            $this->getJson(route('admin.datamesh.'.$endpoint))->assertOk();
            $dureeMs = (microtime(true) - $debut) * 1000;
            $requetes = count(DB::getQueryLog());
            DB::disableQueryLog();

            $this->assertLessThan(60, $requetes, "N+1 suspect sur {$endpoint} : {$requetes} requêtes");
            $this->assertLessThan(5000, $dureeMs, "Trop lent sur {$endpoint} : {$dureeMs} ms");
        }
    }

    public function test_regle8_precalculee_equivalente(): void
    {
        $moteur = app(ControlesDonnees::class);
        $annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-06-30', 'active' => false,
        ]);

        $reference = $moteur->controler($this->superAdmin, null, $annee->id);
        $service = app(ServiceObservabilite::class);
        $fiches = $service->produits($this->superAdmin, null);
        $fraicheurs = [];
        foreach ($fiches as $fiche) {
            $fraicheurs[$fiche['code']] = $fiche['fraicheur'];
        }
        $mutualise = $moteur->controler($this->superAdmin, null, $annee->id, $fraicheurs);

        $this->assertSame($reference['resultats'], $mutualise['resultats']);
    }

    public function test_degrade_avertissements_sans_bloquante(): void
    {
        $service = app(ServiceObservabilite::class);
        $fiche = ['code' => 'affectations_groupes', 'fraicheur_connue' => false];
        $resume = [
            'disponible' => true, 'bloquants_par_produit' => [], 'regles_par_produit' => [],
            'avertissements_par_produit' => ['affectations_groupes' => 3],
            'date_controle' => now()->format(DATE_ATOM),
        ];

        $resolution = $service->resoudreEtatProduit($fiche, $resume);

        $this->assertSame('degrade', $resolution['etat']);
        $this->assertSame('qualite', $resolution['origine_etat']);
        $this->assertStringContainsString('3', $resolution['diagnostic']);

        // L'erreur reste prioritaire sur le dégradé.
        $resume['bloquants_par_produit'] = ['affectations_groupes' => 1];
        $resume['regles_par_produit'] = ['affectations_groupes' => ['doublons_affectations']];
        $this->assertSame('erreur', $service->resoudreEtatProduit($fiche, $resume)['etat']);
    }

    public function test_cache_chaud_isole_par_perimetre(): void
    {
        // L'admin de faculté réchauffe le cache sur son périmètre…
        $this->actingAs($this->adminFaculteA)->getJson(route('admin.datamesh.graphe'))->assertOk();

        // …le super-admin transversal ne doit jamais recevoir ce périmètre.
        $graphe = $this->actingAs($this->superAdmin)->getJson(route('admin.datamesh.graphe'))->assertOk()->json();
        $this->assertSame('toutes', $graphe['perimetre']);

        $grapheB = $this->actingAs($this->superAdmin)
            ->getJson(route('admin.datamesh.graphe', ['faculte_id' => $this->faculteB->id]))
            ->assertOk()->json();
        $this->assertSame('faculte='.$this->faculteB->id, $grapheB['perimetre']);
    }
}
