<?php

namespace Tests\Feature\DataMesh;

use App\DataMesh\Catalogue;
use App\DataMesh\Contrats\ProduitDonnees;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Tests de conformité des contrats et du catalogue Data Mesh (§11).
 */
class DataMeshContractsTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalogue_contient_tous_les_produits_attendus(): void
    {
        $codes = Catalogue::codes();

        $attendus = [
            'catalogue_academique',
            'inscriptions_validees',
            'affectations_groupes',
            'affectations_enseignement',
            'resultats_examens',
            'analytique_faculte',
        ];

        foreach ($attendus as $attendu) {
            $this->assertContains($attendu, $codes, "Le produit [{$attendu}] doit être enregistré dans le catalogue.");
        }
    }

    public function test_chaque_produit_implemente_l_interface_produit_donnees(): void
    {
        // Versions contractuelles gelées (lot 02 : inscriptions_validees v1.1
        // pagination MINEUR — toute évolution exige mise à jour conjointe
        // de CONTRACTS.md et du catalogue, jamais silencieuse).
        $versionsAttendues = [
            'catalogue_academique' => '1.0',
            'inscriptions_validees' => '1.1',
            'affectations_groupes' => '1.0',
            'affectations_enseignement' => '1.0',
            'resultats_examens' => '1.0',
            'analytique_faculte' => '1.1',
        ];

        foreach (Catalogue::codes() as $code) {
            $produit = Catalogue::produit($code);

            $this->assertInstanceOf(ProduitDonnees::class, $produit);
            $this->assertSame($code, $produit->code());
            $this->assertNotEmpty($produit->domaine());
            $this->assertSame(
                $versionsAttendues[$code] ?? null,
                $produit->version(),
                "Version inattendue pour [{$code}] : mettre à jour le contrat et ce gel."
            );

            $description = $produit->decrire();
            $this->assertArrayHasKey('nom', $description);
            $this->assertArrayHasKey('description', $description);
            $this->assertArrayHasKey('finalite', $description);
            $this->assertArrayHasKey('proprietaire_metier', $description);
            $this->assertArrayHasKey('source_verite', $description);
            $this->assertArrayHasKey('classification', $description);
            $this->assertArrayHasKey('fraicheur_attendue', $description);

            $schema = $produit->schema();
            $this->assertIsArray($schema);
            $this->assertNotEmpty($schema);

            foreach ($schema as $colonne) {
                $this->assertArrayHasKey('champ', $colonne);
                $this->assertArrayHasKey('type', $colonne);
                $this->assertArrayHasKey('signification', $colonne);
                $this->assertArrayHasKey('sensible', $colonne);
                $this->assertIsBool($colonne['sensible']);
            }
        }
    }

    public function test_produit_inconnu_leve_une_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Produit de données inconnu : produit_inexistant.');

        Catalogue::produit('produit_inexistant');
    }

    public function test_liste_du_catalogue_est_complete_et_documentee(): void
    {
        $liste = Catalogue::liste();

        $this->assertCount(6, $liste);

        foreach ($liste as $item) {
            $this->assertArrayHasKey('code', $item);
            $this->assertArrayHasKey('nom', $item);
            $this->assertArrayHasKey('version', $item);
            $this->assertArrayHasKey('domaine', $item);
            $this->assertArrayHasKey('classification', $item);
            $this->assertArrayHasKey('nb_champs_schema', $item);
            $this->assertGreaterThan(0, $item['nb_champs_schema']);
        }
    }

    public function test_filtrage_par_domaine_proprietaire(): void
    {
        $produitsScolarite = Catalogue::parDomaine('scolarite');
        $this->assertCount(1, $produitsScolarite);
        $this->assertSame('inscriptions_validees', $produitsScolarite[0]->code());

        $produitsReferentiel = Catalogue::parDomaine('referentiel');
        $this->assertCount(1, $produitsReferentiel);
        $this->assertSame('catalogue_academique', $produitsReferentiel[0]->code());
    }

    public function test_instantane_des_schemas_empeche_les_changements_silencieux(): void
    {
        // Tout ajout/retrait/renommage de champ doit s'accompagner d'une
        // montée de version documentée (CONTRACTS.md) et de la mise à jour
        // de cet instantané — jamais en silence.
        $attendus = [
            'catalogue_academique' => ['facultes[].code', 'facultes[].nom', 'programmes[].code', 'programmes[].nom', 'programmes[].faculte_code', 'niveaux[].code', 'niveaux[].programme_code'],
            'inscriptions_validees' => ['total', 'page', 'par_page', 'pages', 'reference', 'annee', 'niveau', 'programme_code', 'statut', 'etudiant.nom_complet', 'etudiant.cin_masquee', 'etudiant.email_masque', 'faculte_code'],
            'affectations_groupes' => ['total', 'affectations[].reference_etudiant', 'affectations[].groupe_code', 'affectations[].verrouille', 'occupation[].groupe_code', 'occupation[].effectif', 'occupation[].capacite', 'occupation[].depasse'],
            'affectations_enseignement' => ['total', 'enseignements[].code', 'enseignements[].matiere_code', 'enseignements[].matiere_nom', 'enseignements[].enseignant_nom', 'enseignements[].groupe_code', 'enseignements[].semestre', 'enseignements[].volume_horaire', 'enseignements[].statut'],
            'resultats_examens' => ['total', 'resultats[].reference_etudiant', 'resultats[].moyenne', 'resultats[].credits_obtenus', 'resultats[].decision', 'resultats[].publie'],
            'analytique_faculte' => ['version_agregats', 'indicateurs', 'snapshot_kpi'],
        ];

        $this->assertSame(array_keys($attendus), Catalogue::codes());

        foreach ($attendus as $code => $champs) {
            $reels = array_column(Catalogue::produit($code)->schema(), 'champ');
            $this->assertSame($champs, $reels, "Schéma modifié pour [{$code}] : monter la version et documenter.");
        }
    }
}
