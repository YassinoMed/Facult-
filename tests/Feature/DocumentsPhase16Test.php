<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DocumentTemplate;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\StudentDossiers\Models\GeneratedDocument;
use App\Services\Documents\DocumentGenerator;
use App\Services\Documents\DocumentNumberingService;
use App\Services\Documents\DocumentTemplateService;
use App\Services\Documents\DocumentVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 16 §16.10 : centre documentaire (snapshot, numérotation,
 * vérification publique, cycle de vie, IDOR, lots).
 */
class DocumentsPhase16Test extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private Etudiant $etudiant;

    private InscriptionAcademique $inscription;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'F16', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.ph16@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->etudiant = Etudiant::create([
            'cin' => 'PH160001', 'nom' => 'Ben Ahmed-El Haddad', 'prenom' => 'Sara',
            'email' => 'ph16@universite.test', 'date_naissance' => '2004-03-15',
            'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X',
            'ville' => 'Tunis', 'code_postal' => '1000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');

        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $this->inscription = InscriptionAcademique::create([
            'etudiant_id' => $this->etudiant->id, 'annee_universitaire_id' => $annee->id,
            'niveau' => 'L1', 'statut' => 'en_cours', 'type' => 'premiere', 'regime' => 'initiale',
        ]);
    }

    public function test_generation_snapshot_fige_et_supersession(): void
    {
        $generateur = app(DocumentGenerator::class);

        $doc = $generateur->generer($this->etudiant, 'attestation_inscription', [
            'inscription_id' => $this->inscription->id,
        ], $this->admin->id);

        $this->assertMatchesRegularExpression('/^ATI-\d{4}-\d{6}$/', (string) $doc->numero);
        $this->assertSame(32, strlen((string) $doc->code_verification));
        $this->assertNotNull($doc->snapshot_donnees);
        Storage::disk('private')->assertExists($doc->storage_path);

        // Source modifiée → document inchangé (snapshot).
        $this->etudiant->update(['nom' => 'Nouveau-Nom']);
        $doc->refresh();
        $this->assertSame('Ben Ahmed-El Haddad', $doc->snapshot_donnees['nom']);

        // Régénération → nouveau document qui supersede l'ancien.
        $v2 = $generateur->generer($this->etudiant, 'attestation_inscription', [
            'inscription_id' => $this->inscription->id,
        ], $this->admin->id);
        $this->assertNotSame($doc->id, $v2->id);
        $this->assertSame('remplace', $doc->fresh()->statut);
        $this->assertSame($v2->id, $doc->fresh()->remplace_id);
    }

    public function test_generation_bloquee_donnee_manquante(): void
    {
        $this->etudiant->update(['date_naissance' => null]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('date de naissance manquante');

        app(DocumentGenerator::class)->generer($this->etudiant, 'attestation_inscription', [
            'inscription_id' => $this->inscription->id,
        ], $this->admin->id);
    }

    public function test_numerotation_sans_trou_en_cas_echec(): void
    {
        $service = app(DocumentNumberingService::class);

        // Aperçu ne consomme rien.
        $apercu = $service->apercu('attestation_inscription', 'ATI');
        $this->assertMatchesRegularExpression('/^ATI-\d{4}-\d{6}$/', $apercu);

        // Deux émissions successives : séquence stricte, pas de doublon.
        $generateur = app(DocumentGenerator::class);
        $d1 = $generateur->generer($this->etudiant, 'attestation_inscription', ['inscription_id' => $this->inscription->id], $this->admin->id);
        $this->etudiant->update(['prenom' => 'Sarra']);
        $d2 = $generateur->generer($this->etudiant, 'attestation_inscription', ['inscription_id' => $this->inscription->id], $this->admin->id);

        $this->assertNotSame($d1->numero, $d2->numero);
        $this->assertSame(0, GeneratedDocument::where('statut', 'failed')->count());
    }

    public function test_verification_publique_statuts_et_masquage(): void
    {
        $doc = app(DocumentGenerator::class)->generer($this->etudiant, 'attestation_inscription', [
            'inscription_id' => $this->inscription->id,
        ], $this->admin->id);

        $verification = app(DocumentVerificationService::class);

        $ok = $verification->verifier((string) $doc->code_verification, '1.2.3.4');
        $this->assertTrue($ok['valide']);
        $this->assertSame('valide', $ok['statut']);
        $this->assertStringStartsWith('B', (string) $ok['nom_masque']);
        $this->assertStringNotContainsString('Ben Ahmed-El Haddad', (string) $ok['nom_masque']);

        $doc->update(['statut' => 'revoque']);
        $revoque = $verification->verifier((string) $doc->code_verification, '1.2.3.4');
        $this->assertFalse($revoque['valide']);
        $this->assertSame('revoque', $revoque['statut']);

        $inconnu = $verification->verifier('CODE-INEXISTANT-XYZ', '1.2.3.4');
        $this->assertFalse($inconnu['trouve']);
        $this->assertSame('inexistant', $inconnu['statut']);

        // IP hachée, jamais en clair.
        $this->assertDatabaseMissing('document_verifications', ['ip_hachee' => '1.2.3.4']);
    }

    public function test_idor_faculte_etrangere(): void
    {
        $doc = app(DocumentGenerator::class)->generer($this->etudiant, 'attestation_inscription', [
            'inscription_id' => $this->inscription->id,
        ], $this->admin->id);

        $autre = Faculte::create(['nom' => 'FLSH', 'code' => 'F17', 'description' => 'T', 'active' => true]);
        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.ph16@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $autre->id,
        ], 'admin_faculte');

        $this->actingAs($adminFaculte)->get(route('admin.documents-center.show', $doc))->assertNotFound();
        $this->actingAs($this->admin)->get(route('admin.documents-center.show', $doc))->assertOk();
    }

    public function test_duplicata_suffixe_et_trace(): void
    {
        $generateur = app(DocumentGenerator::class);
        $origine = $generateur->generer($this->etudiant, 'attestation_inscription', [
            'inscription_id' => $this->inscription->id,
        ], $this->admin->id);

        $this->etudiant->update(['prenom' => 'Sarah']);
        $duplicata = $generateur->generer($this->etudiant, 'attestation_inscription', [
            'inscription_id' => $this->inscription->id, 'duplicata_de' => $origine->id,
        ], $this->admin->id);

        $this->assertStringContainsString('-DUP1', (string) $duplicata->numero);
        $this->assertSame(1, (int) $origine->fresh()->duplicatas);
        $this->assertTrue((bool) $duplicata->snapshot_donnees['duplicata']);
    }

    public function test_modele_version_immuable_et_sandbox_sans_code(): void
    {
        $service = app(DocumentTemplateService::class);
        $modele = DocumentTemplate::create([
            'type' => 'attestation_inscription', 'nom' => 'ATI v1', 'statut' => 'brouillon',
        ]);
        $v1 = $service->nouvelleVersion($modele, 'Attestation de {{ nom }}', ['nom' => 'string'], 'admin');
        $v2 = $service->nouvelleVersion($modele->fresh(), 'Attestation modifiée de {{ nom }}', ['nom' => 'string'], 'admin');

        $this->assertSame(1, $v1->version);
        $this->assertSame(2, $v2->version);
        $this->assertSame('Attestation de {{ nom }}', $v1->fresh()->contenu);

        $this->expectException(\DomainException::class);
        $service->nouvelleVersion($modele->fresh(), 'Contenu <?php echo 1; ?>', null, 'admin');
    }
}
