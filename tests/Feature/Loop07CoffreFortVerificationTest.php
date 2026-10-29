<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\Semestre;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\StudentDossiers\Models\GeneratedDocument;
use App\Modules\StudentDossiers\Services\Pdf\OfficialDocumentService;
use App\Services\Documents\DefaultDocumentGenerator;
use App\Services\Documents\DocumentSignatureService;
use App\Services\Documents\DocumentVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * LOOP-07 — vérification indépendante du coffre-fort documentaire et du QR.
 *
 * Ce fichier est distinct de `CoffreFortAttestationsQrTest` et du rapport
 * LOOP-07 préexistant. Il prouve les deux manques explicitement ouverts :
 * R-07/1 (interrupteur `DOCUMENTS_QR_ACTIF` non branché) et R-07/6
 * (absence de signature cryptographique), ainsi que le câblage QR des
 * 15 gabarits du catalogue.
 */
class Loop07CoffreFortVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Etudiant $etudiant;

    private InscriptionAcademique $inscription;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.student-dossiers.active' => true]);
        Storage::fake('private');
        Storage::fake('local');

        $faculte = Faculte::create([
            'nom' => 'Faculté Vérification LOOP-07',
            'code' => 'FAC-VL07',
            'description' => 'Vérification LOOP-07',
            'active' => true,
        ]);

        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027 Vérification LOOP-07',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $programme = Programme::create([
            'faculte_id' => $faculte->id,
            'code' => 'LIC-VL07',
            'nom' => 'Licence Vérification LOOP-07',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
            'actif' => true,
        ]);

        $niveau = Niveau::create([
            'programme_id' => $programme->id,
            'code' => 'L1-VL07',
            'nom' => 'Licence 1 Vérification LOOP-07',
            'ordre' => 1,
            'actif' => true,
        ]);

        $semestreRef = SemestreLmd::create([
            'niveau_id' => $niveau->id,
            'code' => 'S1-VL07',
            'nom' => 'Semestre 1 Vérification LOOP-07',
            'numero' => 1,
            'ordre' => 1,
            'actif' => true,
        ]);

        $ue = UniteEnseignement::create([
            'semestre_id' => $semestreRef->id,
            'programme_id' => $programme->id,
            'code' => 'UE-VL07',
            'nom' => 'UE Vérification LOOP-07',
            'credits' => 6,
            'coefficient' => 2,
            'actif' => true,
        ]);

        $matiere = Matiere::create([
            'code' => 'ALG-VL07',
            'nom' => 'Algorithmique Vérification LOOP-07',
            'ue_id' => $ue->id,
            'niveau' => 'L1',
            'semestre' => 1,
            'credits' => 6,
            'coefficient' => 2,
            'active' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Agent Vérification LOOP-07',
            'email' => 'loop07.scolarite@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $faculte->id,
        ], 'admin_scolarite');

        $this->etudiant = Etudiant::create([
            'cin' => '11223344',
            'nom' => 'Ben Salah',
            'prenom' => 'Nour',
            'email' => 'nour.bs@etudiant.test',
            'date_naissance' => '2004-03-12',
            'sexe' => 'F',
            'faculte_id' => $faculte->id,
            'programme_id' => $programme->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'num_inscription' => 'INS-VL07-0001',
            'adresse' => 'Rue de la République',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');

        $this->inscription = InscriptionAcademique::create([
            'etudiant_id' => $this->etudiant->id,
            'annee_universitaire_id' => $annee->id,
            'programme_id' => $programme->id,
            'niveau' => 'L1',
            'statut' => 'en_cours',
            'type' => 'premiere',
            'regime' => 'initiale',
            'moyenne_annuelle' => 14.5,
            'credits_obtenus' => 30,
            'credits_total' => 30,
        ]);

        $semestre = Semestre::create([
            'inscription_academique_id' => $this->inscription->id,
            'numero' => 1,
            'moyenne' => 14.5,
            'credits_obtenus' => 30,
            'credits_total' => 30,
        ]);

        Note::create([
            'etudiant_id' => $this->etudiant->id,
            'inscription_academique_id' => $this->inscription->id,
            'semestre_id' => $semestre->id,
            'matiere_id' => $matiere->id,
            'note' => 14.5,
            'coefficient' => 2,
            'credits' => 6,
            'session' => 'normale',
        ]);
    }

    public function test_qr_actif_par_defaut_et_present_dans_tous_les_gabarits_du_catalogue(): void
    {
        config(['documents-officiels.qr.actif' => true]);

        $document = $this->genererReleve();

        $this->assertArrayHasKey('qrCodeSvg', $document->snapshot_donnees);
        $this->assertStringContainsString('<svg', $document->snapshot_donnees['qrCodeSvg']);

        // Les 15 types pointent vers 14 gabarits : chacun doit exposer le
        // slot QR après câblage (R-07/2).
        foreach (OfficialDocumentService::catalogue() as $type => $definition) {
            $chemin = resource_path('views/'.str_replace('.', '/', $definition['gabarit']).'.blade.php');

            $this->assertFileExists($chemin, "Gabarit absent pour {$type}");
            $this->assertStringContainsString(
                'qrCodeSvg',
                (string) file_get_contents($chemin),
                "Gabarit {$type} sans slot QR"
            );
        }
    }

    public function test_interrupteur_qr_desactive_retire_image_mais_conserve_url_et_code(): void
    {
        config(['documents-officiels.qr.actif' => false]);

        $document = $this->genererReleve();
        $snapshot = $document->snapshot_donnees;

        $this->assertArrayNotHasKey('qrCodeSvg', $snapshot);
        $this->assertNotEmpty($snapshot['urlVerification']);
        $this->assertSame($document->code_verification, $snapshot['codeVerification']);

        $html = view('modules.student-dossiers.pdf.releve', $snapshot)->render();

        $this->assertStringNotContainsString('<svg', $html);
        $this->assertStringContainsString($snapshot['urlVerification'], $html);
        $this->assertStringContainsString($document->code_verification, $html);
    }

    public function test_interrupteur_qr_desactive_aussi_dans_le_generateur_ged(): void
    {
        config(['documents-officiels.qr.actif' => false]);

        $document = app(DefaultDocumentGenerator::class)->generer(
            $this->etudiant,
            'attestation_inscription',
            ['inscription_id' => $this->inscription->id],
            $this->admin->id
        );

        $this->assertArrayNotHasKey('qrCodeSvg', $document->snapshot_donnees);
        $this->assertNotEmpty($document->snapshot_donnees['urlVerification'] ?? null);
    }

    public function test_generation_officielle_produit_une_signature_hmac_verifiee(): void
    {
        $document = $this->genererReleve();

        $signature = $document->snapshot_donnees['signature_document'] ?? null;

        $this->assertIsArray($signature);
        $this->assertSame(DocumentSignatureService::ALGORITHME, $signature['algorithme']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $signature['valeur']);

        // La signature est reproductible à partir des seules données du
        // registre (aucun état volatil).
        $attendue = app(DocumentSignatureService::class)->signer(
            (string) $document->type,
            (string) $document->reference,
            (string) $document->contenu_hash,
            (string) $document->code_verification
        );
        $this->assertSame($attendue, $signature['valeur']);

        $resultat = app(DocumentVerificationService::class)->verifier($document->code_verification, '10.0.0.7');
        $this->assertTrue($resultat['valide']);
        $this->assertTrue($resultat['signature_valide']);
    }

    public function test_generateur_ged_signe_aussi_ses_documents(): void
    {
        $document = app(DefaultDocumentGenerator::class)->generer(
            $this->etudiant,
            'attestation_inscription',
            ['inscription_id' => $this->inscription->id],
            $this->admin->id
        );

        $this->assertArrayHasKey('signature_document', $document->snapshot_donnees);
        $this->assertTrue(app(DocumentVerificationService::class)
            ->verifier((string) $document->code_verification, '10.0.0.8')['signature_valide']);
    }

    public function test_signature_invalide_si_le_contenu_hash_est_altere(): void
    {
        $document = $this->genererReleve();

        $document->update(['contenu_hash' => hash('sha256', 'contenu-altere')]);

        $resultat = app(DocumentVerificationService::class)->verifier($document->code_verification, '10.0.0.9');

        $this->assertFalse($resultat['signature_valide']);
        $this->assertFalse($resultat['valide']);
        $this->assertSame('valide', $resultat['statut']);
    }

    public function test_signature_invalide_si_la_valeur_du_snapshot_est_alteree(): void
    {
        $document = $this->genererReleve();
        $snapshot = $document->snapshot_donnees;
        $snapshot['signature_document']['valeur'] = str_repeat('0', 64);

        $document->update(['snapshot_donnees' => $snapshot]);

        $resultat = app(DocumentVerificationService::class)->verifier($document->code_verification, '10.0.0.10');

        $this->assertFalse($resultat['signature_valide']);
        $this->assertFalse($resultat['valide']);
    }

    public function test_signature_invalide_si_la_reference_est_alteree(): void
    {
        $document = $this->genererReleve();

        $document->update(['reference' => $document->reference.'-ALTEREE']);

        $resultat = app(DocumentVerificationService::class)->verifier($document->code_verification, '10.0.0.11');

        $this->assertFalse($resultat['signature_valide']);
        $this->assertFalse($resultat['valide']);
    }

    public function test_document_anterieur_sans_signature_reste_valide_et_signale_non_disponible(): void
    {
        $document = $this->genererReleve();
        $snapshot = $document->snapshot_donnees;
        unset($snapshot['signature_document']);

        $document->update(['snapshot_donnees' => $snapshot]);

        $resultat = app(DocumentVerificationService::class)->verifier($document->code_verification, '10.0.0.12');

        $this->assertNull($resultat['signature_valide']);
        $this->assertTrue($resultat['valide']);

        $this->get(route('documents.verify', $document->code_verification))
            ->assertOk()
            ->assertSee('Non disponible (document antérieur)');
    }

    public function test_cle_dediee_est_utilisee_et_un_changement_de_cle_invalide_la_signature(): void
    {
        config(['documents-officiels.signature.cle' => 'cle-dediee-verification-loop-07']);

        $document = $this->genererReleve();

        $this->assertTrue(app(DocumentVerificationService::class)
            ->verifier($document->code_verification, '10.0.0.13')['signature_valide']);

        config(['documents-officiels.signature.cle' => 'autre-cle-volontairement-differente']);

        $resultat = app(DocumentVerificationService::class)->verifier($document->code_verification, '10.0.0.14');

        $this->assertFalse($resultat['signature_valide']);
        $this->assertFalse($resultat['valide']);
    }

    public function test_page_publique_affiche_signature_verifiee_puis_invalide(): void
    {
        $document = $this->genererReleve();

        $this->get(route('documents.verify', $document->code_verification))
            ->assertOk()
            ->assertSee('Document valide.')
            ->assertSee("Signature d'intégrité", false)
            ->assertSee('Vérifiée');

        $document->update(['contenu_hash' => hash('sha256', 'alteration-publique')]);

        $this->get(route('documents.verify', $document->code_verification))
            ->assertOk()
            ->assertSee('Signature invalide')
            ->assertDontSee('Document valide.');
    }

    private function genererReleve(): GeneratedDocument
    {
        return app(OfficialDocumentService::class)->generer(
            $this->etudiant,
            'releve_notes',
            $this->inscription->id,
            $this->admin->id
        );
    }
}
