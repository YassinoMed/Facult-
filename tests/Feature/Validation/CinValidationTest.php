<?php

namespace Tests\Feature\Validation;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Rules\CinTunisienne;
use App\Services\DataExchange\ValueNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class CinValidationTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private Programme $programme;

    private Niveau $niveau;

    private AnneeUniversitaire $anneeUniv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences',
            'code' => 'FS',
            'active' => true,
        ]);

        $this->programme = Programme::create([
            'nom' => 'Licence Informatique',
            'code' => 'L-INFO',
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->niveau = Niveau::create([
            'programme_id' => $this->programme->id,
            'code' => 'L1',
            'nom' => 'Première Année',
            'ordre' => 1,
        ]);

        $this->anneeUniv = AnneeUniversitaire::create([
            'libelle' => '2025-2026',
            'annee' => '2025-2026',
            'active' => true,
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30',
        ]);
    }

    /**
     * Test de la règle CinTunisienne unitaire.
     */
    public function test_regle_cin_accepte_format_8_chiffres_standard(): void
    {
        $validator = Validator::make(['cin' => '12345678'], ['cin' => [new CinTunisienne]]);
        $this->assertTrue($validator->passes());
    }

    public function test_regle_cin_accepte_format_8_chiffres_avec_zero_initial(): void
    {
        $validator = Validator::make(['cin' => '01234567'], ['cin' => [new CinTunisienne]]);
        $this->assertTrue($validator->passes());
    }

    public function test_regle_cin_refuse_7_chiffres(): void
    {
        $validator = Validator::make(['cin' => '1234567'], ['cin' => [new CinTunisienne]]);
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('cin', $validator->errors()->toArray());
    }

    public function test_regle_cin_refuse_9_chiffres(): void
    {
        $validator = Validator::make(['cin' => '123456789'], ['cin' => [new CinTunisienne]]);
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('cin', $validator->errors()->toArray());
    }

    public function test_regle_cin_refuse_lettres(): void
    {
        $validator = Validator::make(['cin' => '12A45678'], ['cin' => [new CinTunisienne]]);
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('cin', $validator->errors()->toArray());
    }

    public function test_regle_cin_refuse_espace_au_milieu(): void
    {
        $validator = Validator::make(['cin' => '12 45678'], ['cin' => [new CinTunisienne]]);
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('cin', $validator->errors()->toArray());
    }

    public function test_regle_cin_refuse_espaces_autour(): void
    {
        $validator = Validator::make(['cin' => ' 12345678 '], ['cin' => [new CinTunisienne]]);
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('cin', $validator->errors()->toArray());
    }

    public function test_regle_cin_refuse_chaine_vide_ou_null(): void
    {
        $v1 = Validator::make(['cin' => ''], ['cin' => ['required', new CinTunisienne]]);
        $this->assertFalse($v1->passes());

        $v2 = Validator::make(['cin' => null], ['cin' => ['required', new CinTunisienne]]);
        $this->assertFalse($v2->passes());
    }

    /**
     * Test de l'endpoint POST /inscription/verifier-cin
     */
    public function test_endpoint_verifier_cin_valide(): void
    {
        $response = $this->postJson(route('inscription.verifierCin'), [
            'cin' => '12345678',
            'date_naissance' => '2004-05-15',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['exists']);
    }

    public function test_endpoint_verifier_cin_valide_avec_zero_initial(): void
    {
        $response = $this->postJson(route('inscription.verifierCin'), [
            'cin' => '01234567',
            'date_naissance' => '2004-05-15',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['exists']);
    }

    public function test_endpoint_verifier_cin_refuse_7_chiffres(): void
    {
        $response = $this->postJson(route('inscription.verifierCin'), [
            'cin' => '1234567',
            'date_naissance' => '2004-05-15',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['cin']);
    }

    public function test_endpoint_verifier_cin_refuse_9_chiffres(): void
    {
        $response = $this->postJson(route('inscription.verifierCin'), [
            'cin' => '123456789',
            'date_naissance' => '2004-05-15',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['cin']);
    }

    public function test_endpoint_verifier_cin_refuse_caracteres_alphanumeriques(): void
    {
        $response = $this->postJson(route('inscription.verifierCin'), [
            'cin' => '12A45678',
            'date_naissance' => '2004-05-15',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['cin']);
    }

    public function test_endpoint_verifier_cin_refuse_espaces(): void
    {
        $response = $this->postJson(route('inscription.verifierCin'), [
            'cin' => '12 45678',
            'date_naissance' => '2004-05-15',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['cin']);
    }

    public function test_endpoint_verifier_cin_refuse_espaces_bordures(): void
    {
        $response = $this->postJson(route('inscription.verifierCin'), [
            'cin' => ' 12345678 ',
            'date_naissance' => '2004-05-15',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['cin']);
    }

    /**
     * Test de la préservation du zéro initial et du type chaîne dans le modèle.
     */
    public function test_etudiant_modele_preserve_cin_comme_chaine_avec_zero_initial(): void
    {
        $etudiant = Etudiant::create([
            'cin' => '08765432',
            'matricule' => 'ETU20250001',
            'nom' => 'Ben Salah',
            'prenom' => 'Mohamed',
            'email' => 'mohamed.bensalah@test.tn',
            'date_naissance' => '2004-01-15',
            'sexe' => 'M',
            'statut' => 'actif',
            'faculte_id' => $this->faculte->id,
            'niveau_id' => $this->niveau->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Avenue Habib Bourguiba',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2023',
            'password' => 'secret123',
            'annee_universitaire_id' => $this->anneeUniv->id,
        ]);

        $this->assertIsString($etudiant->cin);
        $this->assertEquals('08765432', $etudiant->cin);

        $fromDb = Etudiant::find($etudiant->id);
        $this->assertSame('08765432', $fromDb->cin);
    }

    /**
     * Test du normalisateur d'importation pour la CIN.
     */
    public function test_normalisateur_import_preserve_zeros_initiaux(): void
    {
        $valideStandard = ValueNormalizer::cin('12345678');
        $this->assertSame('12345678', $valideStandard);

        $valideAvecZero = ValueNormalizer::cin('01234567');
        $this->assertSame('01234567', $valideAvecZero);

        $valideFormate = ValueNormalizer::cin('08 12 34 56');
        $this->assertSame('08123456', $valideFormate);

        // Invalide (7 chiffres)
        $invalideCourt = ValueNormalizer::cin('1234567');
        $this->assertNull($invalideCourt);

        // Invalide (lettre)
        $invalideLettre = ValueNormalizer::cin('12A45678');
        $this->assertNull($invalideLettre);
    }
}
