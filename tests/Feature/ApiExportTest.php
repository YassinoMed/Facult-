<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActAsApiClient;
use Tests\TestCase;

/**
 * API v1 — Exports (§41-43, §62-63) : profils restreints, filtres,
 * CSV/formula injection neutralisée, IDOR sur le téléchargement.
 */
class ApiExportTest extends TestCase
{
    use ActAsApiClient, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $faculte = Faculte::create(['nom' => 'Faculté Sciences', 'code' => 'FST', 'description' => 'T', 'active' => true]);

        $etudiantCree = Etudiant::create(['cin' => 'EXP0001',
            'nom' => '=SUM(A1:A9)', // Cellule piégée : formula injection
            'prenom' => 'Exp', 'email' => 'exp@example.com',
            'telephone' => '+CMD injection', // piège sur une colonne exportée (profil administratif)
            'sexe' => 'M', 'faculte_id' => $faculte->id, 'niveau' => 'L1', 'annee_academique' => '2025-2026',
            'adresse' => 'R', 'ville' => 'Dakar', 'code_postal' => '10000', 'annee_bac' => '2024', ]);
        $etudiantCree->forceFill(['num_inscription' => 'INS-EXP-1'])->save();
    }

    public function test_export_csv_profil_administratif_et_neutralisation_des_formules(): void
    {
        [, $token] = $this->clientApi(['exports:create']);

        $reponse = $this->post('/api/v1/exports', [
            'type' => 'students', 'format' => 'csv', 'profil' => 'administratif',
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $this->assertSame('completed', $reponse->json('report.status'));
        $this->assertSame(1, $reponse->json('report.total'));

        $telechargement = $this->get($reponse->json('report.download_url'), ['Authorization' => 'Bearer '.$token])
            ->assertOk();

        $contenu = $telechargement->streamedContent();

        // CSV injection neutralisée : les valeurs piégées sont préfixées
        $this->assertStringContainsString("'=SUM(A1:A9)", $contenu);
        $this->assertStringContainsString("'+CMD injection", $contenu);
        $this->assertStringNotContainsStringIgnoringCase('password', $contenu);

        // Journal d'échange
        $this->assertDatabaseHas('integration_logs', ['operation' => 'export.students']);
    }

    public function test_export_profil_statistique_ne_contient_aucune_donnee_individuelle(): void
    {
        // Le profil statistique agrège les INSCRIPTIONS : ajoutons-en une
        $etudiant = Etudiant::first();
        $annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-07-15',
        ]);
        InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $annee->id,
            'niveau' => 'L1', 'statut' => 'valide',
            'moyenne_annuelle' => 14.5, 'credits_obtenus' => 30, 'credits_total' => 30, 'decision' => 'Admis(e)',
        ]);

        [, $token] = $this->clientApi(['exports:create']);

        $reponse = $this->post('/api/v1/exports', [
            'type' => 'students', 'format' => 'json', 'profil' => 'statistique',
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $contenu = $this->get($reponse->json('report.download_url'), ['Authorization' => 'Bearer '.$token])
            ->assertOk()->streamedContent();

        // Agrégats uniquement — jamais CIN, email, ni nom individuel (§43)
        $this->assertStringNotContainsString('EXP0001', $contenu);
        $this->assertStringNotContainsString('exp@example.com', $contenu);
        $this->assertStringContainsString('effectif', $contenu);
        $this->assertStringContainsString('taux_reussite', $contenu);
        $this->assertStringContainsString('100', $contenu); // taux de réussite : 1/1 = 100%
    }

    public function test_export_profil_academique_exclut_les_donnees_de_contact(): void
    {
        [, $token] = $this->clientApi(['exports:create']);

        $reponse = $this->post('/api/v1/exports', [
            'type' => 'students', 'format' => 'json', 'profil' => 'academique',
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $contenu = $this->get($reponse->json('report.download_url'), ['Authorization' => 'Bearer '.$token])
            ->assertOk()->streamedContent();

        $this->assertStringNotContainsString('exp@example.com', $contenu); // pas d'email en profil académique
        $this->assertStringNotContainsStringIgnoringCase('telephone', $contenu);
    }

    public function test_export_xlsx_genere_un_vrai_fichier_office(): void
    {
        [, $token] = $this->clientApi(['exports:create']);

        $reponse = $this->post('/api/v1/exports', [
            'type' => 'students', 'format' => 'xlsx', 'profil' => 'administratif',
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $contenu = $this->get($reponse->json('report.download_url'), ['Authorization' => 'Bearer '.$token])
            ->assertOk()->streamedContent();

        // Signature ZIP d'un XLSX valide
        $this->assertStringStartsWith('PK', substr($contenu, 0, 2));
    }

    public function test_export_xml_bien_forme(): void
    {
        [, $token] = $this->clientApi(['exports:create']);

        $reponse = $this->post('/api/v1/exports', [
            'type' => 'students', 'format' => 'xml', 'profil' => 'administratif',
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $contenu = $this->get($reponse->json('report.download_url'), ['Authorization' => 'Bearer '.$token])
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('<?xml', $contenu);
        $this->assertStringContainsString('<exports>', $contenu);

        // Valeurs échappées : le nom piégé ne devient pas une entité
        $this->assertStringNotContainsString('<!DOCTYPE', $contenu);
        $this->assertStringNotContainsString('<!ENTITY', $contenu);
    }

    public function test_filtres_appliques_a_l_export(): void
    {
        [, $token] = $this->clientApi(['exports:create']);

        $reponse = $this->post('/api/v1/exports', [
            'type' => 'students', 'format' => 'json', 'profil' => 'administratif',
            'filters' => ['faculte' => 'INEXISTANTE'],
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $contenu = $this->get($reponse->json('report.download_url'), ['Authorization' => 'Bearer '.$token])
            ->assertOk()->streamedContent();

        $this->assertSame('[]', trim($contenu)); // aucun étudiant : filtre respecté
    }

    public function test_telechargement_reserve_au_proprietaire(): void
    {
        [, $tokenA] = $this->clientApi(['exports:create']);
        [, $tokenB] = $this->clientApi(['exports:create']);

        $reponse = $this->post('/api/v1/exports', [
            'type' => 'students', 'format' => 'csv', 'profil' => 'administratif',
        ], ['Authorization' => 'Bearer '.$tokenA])->assertStatus(201);

        // Le client B ne peut pas télécharger l'export de A (anti-IDOR §42)
        $this->resetAuthGuards(); // requête fraîche : le client B s'authentifie avec SON token
        $this->get($reponse->json('report.download_url'), ['Authorization' => 'Bearer '.$tokenB])
            ->assertStatus(404);

        // …ni consulter son rapport
        $rapportId = $reponse->json('report.id');
        $this->get("/api/v1/exports/{$rapportId}", ['Authorization' => 'Bearer '.$tokenB])
            ->assertStatus(404);
    }

    public function test_format_inconnu_refuse(): void
    {
        [, $token] = $this->clientApi(['exports:create']);

        $this->post('/api/v1/exports', [
            'type' => 'students', 'format' => 'pdf', 'profil' => 'administratif',
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(422);
    }

    public function test_filtre_arbitraire_rejete_par_validation(): void
    {
        [, $token] = $this->clientApi(['exports:create']);

        // Filtre hors liste blanche : la validation échoue (§42/§59)
        $this->post('/api/v1/exports', [
            'type' => 'students', 'format' => 'csv', 'profil' => 'administratif',
            'filters' => ['sql' => 'DROP TABLE etudiants'],
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(422);
    }
}
