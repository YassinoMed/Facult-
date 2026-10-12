<?php

namespace Tests\Feature\Validation;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Services\DataExchange\ValueNormalizer;
use App\Services\Io\CsvSerializer;
use App\Services\NormalisationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Tests\TestCase;

class GlobalInputValidationTest extends TestCase
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
            'nom' => 'Faculté Polydisciplinaire',
            'code' => 'FP',
            'active' => true,
        ]);

        $this->programme = Programme::create([
            'nom' => 'Licence Fondamentale',
            'code' => 'LF',
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->niveau = Niveau::create([
            'programme_id' => $this->programme->id,
            'code' => 'L1',
            'nom' => 'Licence 1',
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
     * §4 : DATES ET HEURES
     */
    public function test_dates_refus_date_impossible_31_fevrier(): void
    {
        $regles = [
            'date_naissance' => ['required', 'date_format:Y-m-d', 'before:today', 'after:1920-01-01'],
        ];

        $validator = Validator::make(['date_naissance' => '2024-02-31'], $regles);
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('date_naissance', $validator->errors()->toArray());
    }

    public function test_dates_refus_date_naissance_future(): void
    {
        $regles = [
            'date_naissance' => ['required', 'date_format:Y-m-d', 'before:today', 'after:1920-01-01'],
        ];

        $demain = date('Y-m-d', strtotime('+1 day'));
        $validator = Validator::make(['date_naissance' => $demain], $regles);
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('date_naissance', $validator->errors()->toArray());
    }

    public function test_dates_refus_date_naissance_trop_ancienne(): void
    {
        $regles = [
            'date_naissance' => ['required', 'date_format:Y-m-d', 'before:today', 'after:1920-01-01'],
        ];

        $validator = Validator::make(['date_naissance' => '1899-12-31'], $regles);
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('date_naissance', $validator->errors()->toArray());
    }

    public function test_dates_acceptation_date_naissance_valide(): void
    {
        $regles = [
            'date_naissance' => ['required', 'date_format:Y-m-d', 'before:today', 'after:1920-01-01'],
        ];

        $validator = Validator::make(['date_naissance' => '2004-06-15'], $regles);
        $this->assertTrue($validator->passes());
    }

    public function test_dates_periode_date_fin_apres_ou_egale_date_debut(): void
    {
        $regles = [
            'date_debut' => ['required', 'date_format:Y-m-d'],
            'date_fin' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_debut'],
        ];

        // Valide (fin après début)
        $valide = Validator::make([
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30',
        ], $regles);
        $this->assertTrue($valide->passes());

        // Invalide (fin avant début)
        $invalide = Validator::make([
            'date_debut' => '2025-09-01',
            'date_fin' => '2025-08-31',
        ], $regles);
        $this->assertFalse($invalide->passes());
        $this->assertArrayHasKey('date_fin', $invalide->errors()->toArray());
    }

    /**
     * §5 : EMAILS
     */
    public function test_emails_longueur_maximale_rfc5321(): void
    {
        $regles = ['email' => ['required', 'string', 'email', 'max:254']];

        $tropLong = str_repeat('a', 246).'@test.tn'; // 246 + 8 = 254 chars -> OK
        $tropLong2 = str_repeat('a', 247).'@test.tn'; // 247 + 8 = 255 chars -> Invalide

        $v1 = Validator::make(['email' => $tropLong], $regles);
        $this->assertTrue($v1->passes());

        $v2 = Validator::make(['email' => $tropLong2], $regles);
        $this->assertFalse($v2->passes());
        $this->assertArrayHasKey('email', $v2->errors()->toArray());
    }

    public function test_emails_normalisation_minuscule_et_espaces(): void
    {
        $service = new NormalisationService;
        $normalise = $service->email('  ETUDIANT.TEST@Universite.TN  ');
        $this->assertSame('etudiant.test@universite.tn', $normalise);
    }

    public function test_emails_unicite_avec_ignore_sur_mise_a_jour(): void
    {
        $user = User::create([
            'name' => 'Admin Test',
            'email' => 'admin@test.tn',
            'password' => bcrypt('Secret1234!'),
            'role' => 'super_admin',
        ]);

        $regles = [
            'email' => ['required', 'email', 'max:254', Rule::unique('users', 'email')->ignore($user->id)],
        ];

        // Même email pour le même user -> autorisé
        $v1 = Validator::make(['email' => 'admin@test.tn'], $regles);
        $this->assertTrue($v1->passes());

        // Autre user existant -> refusé
        $user2 = User::create([
            'name' => 'Admin Deux',
            'email' => 'autre@test.tn',
            'password' => bcrypt('Secret1234!'),
            'role' => 'super_admin',
        ]);

        $v2 = Validator::make(['email' => 'autre@test.tn'], $regles);
        $this->assertFalse($v2->passes());
    }

    /**
     * §6 : TÉLÉPHONES
     */
    public function test_telephones_acceptation_format_local_et_international(): void
    {
        $regle = ['telephone' => ['nullable', 'string', 'regex:/^\+?[0-9\s\-()]{7,30}$/']];

        $valides = [
            '+216 71 123 456',
            '71123456',
            '+33 6 12 34 56 78',
            '+1 (555) 123-4567',
            '0021698123456',
        ];

        foreach ($valides as $tel) {
            $v = Validator::make(['telephone' => $tel], $regle);
            $this->assertTrue($v->passes(), "Le téléphone {$tel} devrait être accepté.");
        }

        $invalides = [
            '123',            // Trop court (< 7)
            '06ABC45678',      // Lettres
            '<script>',        // Injection
            str_repeat('9', 35), // Trop long (> 30)
        ];

        foreach ($invalides as $tel) {
            $v = Validator::make(['telephone' => $tel], $regle);
            $this->assertFalse($v->passes(), "Le téléphone {$tel} devrait être rejeté.");
        }
    }

    /**
     * §7 : NOMS, PRÉNOMS ET TEXTES
     */
    public function test_noms_refus_espaces_seuls(): void
    {
        $regles = ['nom' => ['required', 'string', 'min:2', 'max:100']];

        $v = Validator::make(['nom' => '   '], $regles);
        // Après trim via FormRequest ou règle explicite
        $nomTrim = trim('   ');
        $vTrim = Validator::make(['nom' => $nomTrim], $regles);
        $this->assertFalse($vTrim->passes());
    }

    public function test_noms_preservation_accents_et_caracteres_internationaux(): void
    {
        $regles = ['nom' => ['required', 'string', 'min:2', 'max:100']];

        $noms = [
            "O'Connor",
            'Élodie-Chloé',
            'بن سالم',
            'Müller',
            'García Márquez',
        ];

        foreach ($noms as $nom) {
            $v = Validator::make(['nom' => $nom], $regles);
            $this->assertTrue($v->passes(), "Le nom {$nom} devrait être accepté.");
        }
    }

    /**
     * §9 : CHAMPS NUMÉRIQUES ET NOTES
     */
    public function test_notes_bornes_barème_0_a_20(): void
    {
        $regle = ['note' => ['required', 'numeric', 'between:0,20']];

        $this->assertTrue(Validator::make(['note' => 0], $regle)->passes());
        $this->assertTrue(Validator::make(['note' => 14.75], $regle)->passes());
        $this->assertTrue(Validator::make(['note' => 20], $regle)->passes());

        // Hors barème
        $this->assertFalse(Validator::make(['note' => -0.5], $regle)->passes());
        $this->assertFalse(Validator::make(['note' => 20.25], $regle)->passes());
        $this->assertFalse(Validator::make(['note' => 'NaN'], $regle)->passes());
        $this->assertFalse(Validator::make(['note' => 'non-note'], $regle)->passes());
    }

    /**
     * §13 : SÉCURITÉ EXPORT & FORMULA INJECTION
     */
    public function test_neutralisation_injection_formules_csv(): void
    {
        $serializer = new CsvSerializer;

        $vecteurs = [
            '=SUM(A1:A10)',
            '+cmd|/C calc!A0',
            '-2+3*[1]',
            '@evil_link',
            "\tTAB_INJECT",
            "\rRETURN_INJECT",
        ];

        foreach ($vecteurs as $vecteur) {
            $neutraliseCsv = $serializer->neutraliserFormule($vecteur);
            $this->assertStringStartsWith("'", $neutraliseCsv, "Le vecteur {$vecteur} doit être préfixé par une apostrophe dans CsvSerializer.");

            $neutraliseNorm = ValueNormalizer::celluleExport($vecteur);
            $this->assertStringStartsWith("'", $neutraliseNorm, "Le vecteur {$vecteur} doit être préfixé par une apostrophe dans ValueNormalizer.");
        }

        // Texte standard non modifié
        $texteNormal = 'Mohamed Ben Salah';
        $this->assertSame($texteNormal, $serializer->neutraliserFormule($texteNormal));
        $this->assertSame($texteNormal, ValueNormalizer::celluleExport($texteNormal));
    }
}
