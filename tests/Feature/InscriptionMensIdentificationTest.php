<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\MensReference;
use App\Services\IdentificationIntelligenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP 06 — Tests d'identification intelligente et préremplissage MENS (§6).
 *
 * Vérifie :
 * 1. Correspondance certaine (MENS exact sans conflit)
 * 2. Correspondance probable (divergence mineure)
 * 3. Ambiguïté (doublons locaux ou fiches MENS conflictuelles -> AMBIGUOUS, jamais de fusion)
 * 4. Aucune correspondance (couple inconnu -> UNKNOWN)
 * 5. Indisponibilité MENS (panne réseau / service -> fail-soft MENS_UNAVAILABLE)
 * 6. Minimisation des données & anti-énumération
 * 7. Limitation de débit (throttle strict)
 */
class InscriptionMensIdentificationTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences',
            'code' => 'FS',
            'description' => 'Test',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);
    }

    public function test_correspondance_certaine_mens_prefill_donnees_minimales_sans_contact(): void
    {
        MensReference::create([
            'cin_original' => '08123456',
            'cin_normalise' => '08123456',
            'date_naissance' => '2004-05-12',
            'nom' => 'Alami',
            'prenom' => 'Youssef',
            'nom_ar' => 'العلمي',
            'prenom_ar' => 'يوسف',
            'sexe' => 'M',
            'nationalite' => 'Marocaine',
            'annee' => '2026-2027',
            'source_hash' => str_repeat('a', 64),
        ]);

        $reponse = $this->postJson(route('inscription.identifier'), [
            'cin' => '08123456',
            'date_naissance' => '2004-05-12',
        ]);

        $reponse->assertOk()
            ->assertJson([
                'status' => 'MENS_FOUND',
                'certitude' => 'certaine',
                'source' => 'MENS',
                'prefill' => [
                    'cin' => '08123456',
                    'nom' => 'Alami',
                    'prenom' => 'Youssef',
                    'nom_ar' => 'العلمي',
                    'prenom_ar' => 'يوسف',
                    'date_naissance' => '2004-05-12',
                    'sexe' => 'M',
                    'nationalite' => 'Marocaine',
                ],
            ]);

        // Vérification stricte de minimisation des données : aucun champ contact ou technique exposé
        $prefill = $reponse->json('prefill');
        $this->assertArrayNotHasKey('email', $prefill);
        $this->assertArrayNotHasKey('telephone', $prefill);
        $this->assertArrayNotHasKey('adresse', $prefill);
        $this->assertArrayNotHasKey('matricule_national', $prefill);
        $this->assertArrayNotHasKey('source_hash', $prefill);
    }

    private function creerEtudiant(string $cin, array $attributs = []): Etudiant
    {
        $etudiant = Etudiant::create(array_merge([
            'cin' => $cin,
            'nom' => 'NomTest',
            'prenom' => 'PrenomTest',
            'email' => strtolower($cin).'@universite.test',
            'date_naissance' => '2004-01-01',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Rue 123',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2024',
            'password' => 'Secret@123',
        ], $attributs));
        $etudiant->definirStatut('valide');

        return $etudiant;
    }

    public function test_correspondance_probable_divergence_mineure_orthographe(): void
    {
        MensReference::create([
            'cin_original' => '08234567',
            'cin_normalise' => '08234567',
            'date_naissance' => '2004-08-20',
            'nom' => 'Benjelloun',
            'prenom' => 'Amine',
            'annee' => '2026-2027',
            'source_hash' => str_repeat('b', 64),
        ]);

        // Dossier local avec une légère divergence d'orthographe (Benjelloune au lieu de Benjelloun)
        $this->creerEtudiant('08234567', [
            'nom' => 'Benjelloune',
            'prenom' => 'Amine',
            'date_naissance' => '2004-08-20',
        ]);

        $service = app(IdentificationIntelligenteService::class);
        $resultat = $service->identifier('08234567', '2004-08-20');

        $this->assertSame('MENS_ET_LOCAL', $resultat['statut']);
        $this->assertSame('probable', $resultat['certitude']);
        $this->assertNotEmpty($resultat['divergences']);
        $this->assertSame('A_VERIFIER', $resultat['divergences'][0]['gravite']);
    }

    public function test_homonymes_legitimes_distingues_par_cin_ou_date_sans_confusion(): void
    {
        // Deux homonymes légitimes (même nom, même prénom, même date de naissance) avec CIN distincts
        MensReference::create([
            'cin_original' => '08333111',
            'cin_normalise' => '08333111',
            'date_naissance' => '2004-06-15',
            'nom' => 'Benali',
            'prenom' => 'Yassine',
            'sexe' => 'M',
            'nationalite' => 'Marocaine',
            'annee' => '2026-2027',
            'source_hash' => str_repeat('e', 64),
        ]);

        MensReference::create([
            'cin_original' => '08333222',
            'cin_normalise' => '08333222',
            'date_naissance' => '2004-06-15',
            'nom' => 'Benali',
            'prenom' => 'Yassine',
            'sexe' => 'M',
            'nationalite' => 'Marocaine',
            'annee' => '2026-2027',
            'source_hash' => str_repeat('f', 64),
        ]);

        // La recherche sur le premier CIN ne retourne que le premier, sans collision ni ambiguïté
        $reponse1 = $this->postJson(route('inscription.identifier'), [
            'cin' => '08333111',
            'date_naissance' => '2004-06-15',
        ]);

        $reponse1->assertOk()
            ->assertJson([
                'status' => 'MENS_FOUND',
                'certitude' => 'certaine',
                'prefill' => [
                    'cin' => '08333111',
                    'nom' => 'Benali',
                    'prenom' => 'Yassine',
                ],
            ]);

        // Une mauvaise date de naissance pour le premier CIN ne retourne aucun des deux (fail-closed)
        $reponseMauvaiseDate = $this->postJson(route('inscription.identifier'), [
            'cin' => '08333111',
            'date_naissance' => '2000-01-01',
        ]);

        $reponseMauvaiseDate->assertOk()->assertJson(['status' => 'UNKNOWN']);
    }

    public function test_ambiguite_bloquante_fiches_mens_contradictoires_aucune_fusion_ni_prefill(): void
    {
        // Deux enregistrements MENS pour le même CIN/date avec des noms différents
        MensReference::create([
            'cin_original' => '08456789',
            'cin_normalise' => '08456789',
            'date_naissance' => '2004-02-14',
            'nom' => 'El Idrissi',
            'prenom' => 'Salma',
            'annee' => '2025-2026',
            'source_hash' => str_repeat('c', 64),
        ]);

        MensReference::create([
            'cin_original' => '08456789',
            'cin_normalise' => '08456789',
            'date_naissance' => '2004-02-14',
            'nom' => 'Berrada',
            'prenom' => 'Salma',
            'annee' => '2026-2027',
            'source_hash' => str_repeat('d', 64),
        ]);

        $service = app(IdentificationIntelligenteService::class);
        $resultat = $service->identifier('08456789', '2004-02-14');
        $this->assertSame('AMBIGU', $resultat['statut']);
        $this->assertSame('ambigue', $resultat['certitude']);

        $reponse = $this->postJson(route('inscription.identifier'), [
            'cin' => '08456789',
            'date_naissance' => '2004-02-14',
        ]);

        $reponse->assertOk()
            ->assertJson([
                'status' => 'AMBIGUOUS',
            ]);

        // Aucune donnée préremplie ni création/fusion silencieuse d'étudiant
        $this->assertNull($reponse->json('prefill'));
        $this->assertNull($reponse->json('masked_email'));
        $this->assertSame(0, Etudiant::count());
    }

    public function test_aucune_correspondance_retourne_unknown(): void
    {
        $reponse = $this->postJson(route('inscription.identifier'), [
            'cin' => '08000099',
            'date_naissance' => '2001-01-01',
        ]);

        $reponse->assertOk()
            ->assertJson([
                'status' => 'UNKNOWN',
            ]);

        $this->assertNull($reponse->json('prefill'));
        $this->assertNull($reponse->json('masked_email'));
    }

    public function test_mens_indisponible_fail_soft_autorise_saisie_manuelle(): void
    {
        // Activer la simulation de panne de connexion au référentiel MENS
        config(['inscriptions.mens_simuler_panne' => true]);

        $reponse = $this->postJson(route('inscription.identifier'), [
            'cin' => '08999999',
            'date_naissance' => '2004-01-01',
        ]);

        $reponse->assertOk()
            ->assertJson([
                'status' => 'MENS_UNAVAILABLE',
            ]);

        $this->assertStringContainsString('temporairement indisponible', (string) $reponse->json('message'));
        $this->assertStringContainsString('manuellement', (string) $reponse->json('message'));
    }

    public function test_anti_enumeration_etudiant_existant_masque_email_sans_pii(): void
    {
        $this->creerEtudiant('08567890', [
            'nom' => 'Daoudi',
            'prenom' => 'Karim',
            'email' => 'karim.daoudi@universite.test',
            'date_naissance' => '2004-07-07',
        ]);

        $reponse = $this->postJson(route('inscription.identifier'), [
            'cin' => '08567890',
            'date_naissance' => '2004-07-07',
        ]);

        $reponse->assertOk()
            ->assertJson([
                'status' => 'EXISTING_STUDENT',
            ]);

        $masked = (string) $reponse->json('masked_email');
        $this->assertNotEmpty($masked);
        $this->assertStringContainsString('*', $masked);
        $this->assertStringNotContainsString('karim.daoudi@universite.test', $masked);

        // Zéro PII supplémentaire
        $this->assertNull($reponse->json('nom'));
        $this->assertNull($reponse->json('prenom'));
        $this->assertNull($reponse->json('prefill'));
    }

    public function test_rate_limiting_throttle_protection(): void
    {
        // Route configurée à throttle:5,1 (5 requêtes par minute max)
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(route('inscription.identifier'), [
                'cin' => sprintf('088800%02d', $i),
                'date_naissance' => '2004-01-01',
            ]);
        }

        // La 6ème requête doit être bloquée avec 429 Too Many Requests
        $bloquee = $this->postJson(route('inscription.identifier'), [
            'cin' => '08880099',
            'date_naissance' => '2004-01-01',
        ]);

        $bloquee->assertStatus(429);
    }
}
