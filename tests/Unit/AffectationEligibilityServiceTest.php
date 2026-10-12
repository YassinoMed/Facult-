<?php

namespace Tests\Unit;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\SituationEtudiant;
use App\Modules\Referentiel\Models\Programme;
use App\Services\Affectations\AffectationEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AffectationEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private AffectationEligibilityService $service;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(AffectationEligibilityService::class);

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences de Tunis',
            'code' => 'FST',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'LIC-INFO',
            'nom' => 'Licence en Informatique',
            'cycle' => 'licence',
            'actif' => true,
        ]);
    }

    private function creerEtudiant(array $attributs = []): Etudiant
    {
        static $compteur = 5000;
        $compteur++;

        return Etudiant::create(array_merge([
            'numero_etudiant' => "ETU{$compteur}",
            'num_inscription' => "INS-{$compteur}",
            'nom' => 'Ben Salah',
            'prenom' => 'Karim',
            'email' => "karim{$compteur}@etudiant.tn",
            'cin' => '12345678',
            'date_naissance' => '2004-05-10',
            'telephone' => '20123456',
            'sexe' => 'M',
            'nationalite' => 'Tunisienne',
            'lieu_naissance' => 'Tunis',
            'adresse' => 'Avenue Habib Bourguiba',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2023',
            'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'statut' => 'valide',
            'password' => 'Secret@123',
        ], $attributs));
    }

    private function creerInscription(Etudiant $etudiant, array $attributs = []): InscriptionAcademique
    {
        return InscriptionAcademique::create(array_merge([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1',
            'statut' => 'valide',
            'date_inscription' => now(),
        ], $attributs));
    }

    /**
     * TEST 1 : Étudiant parfaitement éligible sur les 5 piliers.
     */
    public function test_etudiant_parfaitement_eligible(): void
    {
        $etudiant = $this->creerEtudiant();
        $this->creerInscription($etudiant);

        $resultat = $this->service->evaluer($etudiant, $this->annee);

        $this->assertTrue($resultat['eligible']);
        $this->assertTrue($resultat['checks']['identity']);
        $this->assertTrue($resultat['checks']['contact']);
        $this->assertTrue($resultat['checks']['program']);
        $this->assertTrue($resultat['checks']['registration']);
        $this->assertTrue($resultat['checks']['documents']);
        $this->assertEmpty($resultat['blocking_reasons']);
        $this->assertEquals(AffectationEligibilityService::IDENTITY_VALID, $resultat['statuses']['identity']);
        $this->assertEquals(AffectationEligibilityService::CONTACT_VALID, $resultat['statuses']['contact']);
        $this->assertEquals(AffectationEligibilityService::PROGRAM_VALID, $resultat['statuses']['program']);
        $this->assertEquals(AffectationEligibilityService::REGISTRATION_VALID, $resultat['statuses']['registration']);
        $this->assertEquals(AffectationEligibilityService::DOCUMENTS_VALID, $resultat['statuses']['documents']);
    }

    /**
     * TEST 2 : Identité incomplète — nom ou prénom manquant.
     */
    public function test_identite_incomplete_nom_manquant(): void
    {
        $etudiant = $this->creerEtudiant();
        $etudiant->nom = null;
        $this->creerInscription($etudiant);

        $resultat = $this->service->evaluer($etudiant, $this->annee);

        $this->assertFalse($resultat['eligible']);
        $this->assertFalse($resultat['checks']['identity']);
        $this->assertEquals(AffectationEligibilityService::IDENTITY_INCOMPLETE, $resultat['statuses']['identity']);
        $this->assertContains('IDENTITY_NAME_MISSING', $resultat['blocking_reasons']);
    }

    /**
     * TEST 3 : Identité — CIN manquante sans identifiant officiel alternatif.
     */
    public function test_identite_cin_manquante(): void
    {
        $etudiant = $this->creerEtudiant(['cin' => null, 'identifiant_national' => null]);
        $this->creerInscription($etudiant);

        $resultat = $this->service->evaluer($etudiant, $this->annee);

        $this->assertFalse($resultat['eligible']);
        $this->assertFalse($resultat['checks']['identity']);
        $this->assertEquals(AffectationEligibilityService::IDENTITY_INCOMPLETE, $resultat['statuses']['identity']);
        $this->assertContains('IDENTITY_CIN_MISSING', $resultat['blocking_reasons']);
    }

    /**
     * TEST 4 : Identité — Format CIN non conforme (doit être 8 chiffres pour la Tunisie).
     */
    public function test_identite_cin_format_invalide(): void
    {
        $etudiant = $this->creerEtudiant(['cin' => 'ABC1234']);
        $this->creerInscription($etudiant);

        $resultat = $this->service->evaluer($etudiant, $this->annee);

        $this->assertFalse($resultat['eligible']);
        $this->assertFalse($resultat['checks']['identity']);
        $this->assertContains('IDENTITY_CIN_INVALID', $resultat['blocking_reasons']);
    }

    /**
     * TEST 5 : Contact — Aucun moyen de contact disponible (bloquant).
     */
    public function test_contact_aucun_moyen_bloquant(): void
    {
        $etudiant = $this->creerEtudiant(['telephone' => null]);
        $etudiant->email = null;
        $this->creerInscription($etudiant);

        $resultat = $this->service->evaluer($etudiant, $this->annee);

        $this->assertFalse($resultat['eligible']);
        $this->assertFalse($resultat['checks']['contact']);
        $this->assertEquals(AffectationEligibilityService::CONTACT_INVALID, $resultat['statuses']['contact']);
        $this->assertContains('CONTACT_MISSING', $resultat['blocking_reasons']);
    }

    /**
     * TEST 6 : Contact — Contact partiel (email présent mais téléphone manquant : non bloquant mais avertissement).
     */
    public function test_contact_partiel_genere_avertissement(): void
    {
        $etudiant = $this->creerEtudiant(['telephone' => null]);
        $this->creerInscription($etudiant);

        $resultat = $this->service->evaluer($etudiant, $this->annee);

        $this->assertTrue($resultat['eligible']);
        $this->assertTrue($resultat['checks']['contact']);
        $this->assertEquals(AffectationEligibilityService::CONTACT_INCOMPLETE, $resultat['statuses']['contact']);
        $this->assertContains('CONTACT_PHONE_MISSING', $resultat['warnings']);
    }

    /**
     * TEST 7 : Programme — Niveau académique non renseigné (bloquant strict).
     */
    public function test_programme_incomplet_sans_niveau(): void
    {
        $etudiant = $this->creerEtudiant();
        $etudiant->niveau = null;
        $inscription = $this->creerInscription($etudiant);
        $inscription->niveau = null;

        $resultat = $this->service->evaluer($etudiant, $this->annee);

        $this->assertFalse($resultat['eligible']);
        $this->assertFalse($resultat['checks']['program']);
        $this->assertEquals(AffectationEligibilityService::PROGRAM_INCOMPLETE, $resultat['statuses']['program']);
        $this->assertContains('PROGRAM_LEVEL_MISSING', $resultat['blocking_reasons']);
    }

    /**
     * TEST 8 : Programme — Incohérence programme / faculté de rattachement.
     */
    public function test_programme_incoherent_autre_faculte(): void
    {
        $autreFaculte = Faculte::create([
            'nom' => 'Faculté de Médecine de Tunis',
            'code' => 'FMT',
            'active' => true,
        ]);

        $etudiant = $this->creerEtudiant(['faculte_id' => $autreFaculte->id]);
        $this->creerInscription($etudiant, ['faculte_id' => $autreFaculte->id]);

        $resultat = $this->service->evaluer($etudiant, $this->annee);

        $this->assertFalse($resultat['eligible']);
        $this->assertFalse($resultat['checks']['program']);
        $this->assertEquals(AffectationEligibilityService::PROGRAM_INCONSISTENT, $resultat['statuses']['program']);
        $this->assertContains('PROGRAM_FACULTY_MISMATCH', $resultat['blocking_reasons']);
    }

    /**
     * TEST 9 : Inscription — Absence totale d'inscription pour l'année universitaire ciblée.
     */
    public function test_inscription_absente_pour_annee(): void
    {
        $etudiant = $this->creerEtudiant();
        // Pas d'inscription créée

        $resultat = $this->service->evaluer($etudiant, $this->annee);

        $this->assertFalse($resultat['eligible']);
        $this->assertFalse($resultat['checks']['registration']);
        $this->assertEquals(AffectationEligibilityService::REGISTRATION_MISSING, $resultat['statuses']['registration']);
        $this->assertContains('REGISTRATION_MISSING', $resultat['blocking_reasons']);
    }

    /**
     * TEST 10 : Inscription — Inscription inactive (ajourné ou abandon).
     */
    public function test_inscription_inactive_abandon_ou_ajourne(): void
    {
        $etudiant = $this->creerEtudiant();
        $this->creerInscription($etudiant, ['statut' => 'abandon']);

        $resultat = $this->service->evaluer($etudiant, $this->annee);

        $this->assertFalse($resultat['eligible']);
        $this->assertFalse($resultat['checks']['registration']);
        $this->assertEquals(AffectationEligibilityService::REGISTRATION_INACTIVE, $resultat['statuses']['registration']);
        $this->assertContains('REGISTRATION_INACTIVE', $resultat['blocking_reasons']);
    }

    /**
     * TEST 11 : Inscription — Inscription encore non validée (brouillon ou en cours).
     */
    public function test_inscription_en_cours_non_validee_bloque_affectation(): void
    {
        $etudiant = $this->creerEtudiant();
        $this->creerInscription($etudiant, ['statut' => 'en_cours']);

        $resultat = $this->service->evaluer($etudiant, $this->annee);

        $this->assertFalse($resultat['eligible']);
        $this->assertFalse($resultat['checks']['registration']);
        $this->assertEquals(AffectationEligibilityService::REGISTRATION_INVALID, $resultat['statuses']['registration']);
        $this->assertContains('REGISTRATION_NOT_VALIDATED', $resultat['blocking_reasons']);
    }

    /**
     * TEST 12 : Suspension disciplinaire active bloque immédiatement l'affectation.
     */
    public function test_etudiant_suspendu_disciplinaire_bloque(): void
    {
        $etudiant = $this->creerEtudiant();
        $this->creerInscription($etudiant);

        SituationEtudiant::create([
            'etudiant_id' => $etudiant->id,
            'type' => 'suspension',
            'date_effet' => now()->subDays(5),
            'date_fin' => now()->addDays(30),
            'motif' => 'Sanction disciplinaire',
        ]);

        $resultat = $this->service->evaluer($etudiant, $this->annee);

        $this->assertFalse($resultat['eligible']);
        $this->assertContains('STUDENT_SUSPENDED', $resultat['blocking_reasons']);
    }
}
