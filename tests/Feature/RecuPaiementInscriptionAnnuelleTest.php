<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Finances\Models\DossierPaiement;
use App\Modules\Finances\Models\RecuPaiement;
use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Modules\StudentDossiers\Models\StudentDossier;
use App\Modules\StudentDossiers\Services\ReutilisationDocumentsService;
use App\Services\DocumentsRequisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Règle métier : Dans l'inscription de chaque année, l'étudiant doit déposer
 * le reçu de paiement des droits d'inscription (PNG, PDF, JPG, JPEG, WebP).
 *
 * Preuves :
 * - Présence de 'recu_paiement' dans le catalogue et les pièces obligatoires annuelles.
 * - Formats autorisés : png, pdf, jpg, jpeg, webp.
 * - Non-réutilisation annuelle : un reçu de l'année N n'est jamais réutilisé en N+1.
 * - Validation stricte du type MIME réel et rejet des extensions dangereuses.
 * - Dépôt fonctionnel depuis le parcours de paiement annuel de l'étudiant.
 */
class RecuPaiementInscriptionAnnuelleTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee2025;

    private AnneeUniversitaire $annee2026;

    private User $scolarite;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.finances.active' => true,
            'modules.student-dossiers.active' => true,
            'inscriptions-paiements.actif' => true,
        ]);

        Storage::fake('private');

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences de Tunis',
            'code' => 'FST',
            'description' => 'Campus Universitaire',
            'active' => true,
        ]);

        $this->annee2025 = AnneeUniversitaire::create([
            'libelle' => '2025-2026',
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30',
            'active' => false,
        ]);

        $this->annee2026 = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Agent Scolarité',
            'email' => 'agent.sco@universite.tn',
            'password' => 'Secret123456!',
        ], 'admin_scolarite');
    }

    public function test_catalogue_contient_le_recu_de_paiement_avec_formats_autorises(): void
    {
        $service = app(DocumentsRequisService::class);
        $catalogue = $service->catalogue();

        $this->assertArrayHasKey('recu_paiement', $catalogue);

        $recu = $catalogue['recu_paiement'];
        $this->assertSame('recu_paiement', $recu['code']);
        $this->assertSame('financial', $recu['category']);
        $this->assertSame(10, $recu['taille_max_mo']);

        // Vérification des formats attendus par la spécification (png, pdf, jpg, jpeg, webp)
        $this->assertContains('png', $recu['formats']);
        $this->assertContains('pdf', $recu['formats']);
        $this->assertContains('jpg', $recu['formats']);
        $this->assertContains('jpeg', $recu['formats']);
        $this->assertContains('webp', $recu['formats']);
    }

    public function test_recu_paiement_est_obligatoire_pour_chaque_type_d_inscription(): void
    {
        $service = app(DocumentsRequisService::class);

        $piecesPremiere = collect($service->checklistPourType('premiere'));
        $pieceRecuPremiere = $piecesPremiere->firstWhere('code', 'recu_paiement');
        $this->assertNotNull($pieceRecuPremiere, 'recu_paiement doit être présent en première inscription.');
        $this->assertTrue($pieceRecuPremiere['obligatoire'], 'recu_paiement doit être obligatoire en première inscription.');

        $piecesReinscription = collect($service->checklistPourType('reinscription'));
        $pieceRecuReinscription = $piecesReinscription->firstWhere('code', 'recu_paiement');
        $this->assertNotNull($pieceRecuReinscription, 'recu_paiement doit être présent en réinscription.');
        $this->assertTrue($pieceRecuReinscription['obligatoire'], 'recu_paiement doit être obligatoire en réinscription.');

        $piecesTransfert = collect($service->checklistPourType('transfert'));
        $pieceRecuTransfert = $piecesTransfert->firstWhere('code', 'recu_paiement');
        $this->assertNotNull($pieceRecuTransfert, 'recu_paiement doit être présent en transfert.');
        $this->assertTrue($pieceRecuTransfert['obligatoire'], 'recu_paiement doit être obligatoire en transfert.');
    }

    public function test_le_recu_de_paiement_n_est_jamais_reutilise_automatiquement_d_une_annee_a_l_autre(): void
    {
        $etudiant = $this->creerEtudiant('08123456');

        // Année 1 (2025-2026) : l'étudiant avait déposé un reçu de paiement qui a été validé
        $dossierGed = StudentDossier::create([
            'etudiant_id' => $etudiant->id,
            'reference' => 'DOS-'.$etudiant->num_inscription,
            'status' => 'active',
        ]);

        $chemin2025 = 'student-dossiers/2025-2026/'.$etudiant->id.'/financial/recu-2025.pdf';
        $contenuPdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
        Storage::disk('private')->put($chemin2025, $contenuPdf);

        $docRecu2025 = StudentDocument::create([
            'student_dossier_id' => $dossierGed->id,
            'category' => 'financial',
            'document_type' => 'recu_paiement',
            'title' => 'Reçu de paiement 2025-2026',
            'original_filename' => 'recu-2025.pdf',
            'storage_path' => $chemin2025,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => strlen($contenuPdf),
            'sha256' => hash('sha256', $contenuPdf),
            'status' => 'validated',
            'validated_at' => now()->subYear(),
        ]);
        $docRecu2025->versions()->create([
            'version' => 1,
            'original_filename' => 'recu-2025.pdf',
            'storage_path' => $chemin2025,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => strlen($contenuPdf),
            'sha256' => hash('sha256', $contenuPdf),
        ]);

        // Première inscription en 2025 clôturée
        $inscription2025 = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee2025->id,
            'niveau' => 'L1',
            'filiere' => 'Informatique',
            'statut' => 'valide',
            'paye' => true,
        ]);

        // Année 2 (2026-2027) : Nouvelle inscription
        $inscription2026 = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee2026->id,
            'niveau' => 'L2',
            'filiere' => 'Informatique',
            'statut' => 'en_cours',
        ]);

        $reutilisationService = app(ReutilisationDocumentsService::class);
        $bilan = $reutilisationService->synchroniser($inscription2026);

        // RÈGLE MÉTIER CRITIQUE : Le reçu de paiement ne doit PAS être dans les pièces réutilisées !
        $this->assertNotContains('recu_paiement', $bilan['reutilises'], 'Le reçu de paiement ne doit jamais être réutilisé d’une année précédente.');
        $this->assertContains('recu_paiement', $bilan['a_fournir'], 'Le reçu de paiement doit être marqué comme à fournir pour la nouvelle année.');
    }

    public function test_etudiant_peut_deposer_un_recu_au_format_png_ou_pdf(): void
    {
        $etudiant = $this->creerEtudiant('08999999');
        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee2026->id,
            'niveau' => 'L1',
            'statut' => 'en_cours',
        ]);

        // Création d'une image PNG valide (signature magique PNG \x89PNG\r\n\x1a\n)
        $pngContent = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15c4\x00\x00\x00\nIDATx\x9cc\x00\x01\x00\x00\x05\x00\x01\r\n-\xb4\x00\x00\x00\x00IEND\xaeB`\x82";
        $fichierPng = UploadedFile::fake()->createWithContent('recu-banque.png', $pngContent);

        $reponse = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->post(route('etudiant.paiements.recus.store', $inscription), [
                'recu' => $fichierPng,
                'montant' => '250.00',
                'reference_paiement' => 'VIR-2026-9999',
                'date_paiement' => now()->format('Y-m-d'),
                'engagement' => '1',
            ]);

        $reponse->assertRedirect();
        $reponse->assertSessionHas('success');

        $this->assertDatabaseHas('recus_paiement', [
            'inscription_academique_id' => $inscription->id,
            'extension' => 'png',
            'statut' => RecuPaiement::RECU_DEPOSE,
            'reference_paiement' => 'VIR-2026-9999',
        ]);

        $this->assertDatabaseHas('dossiers_paiement', [
            'inscription_academique_id' => $inscription->id,
            'statut' => DossierPaiement::RECU_DEPOSE,
        ]);
    }

    public function test_depot_rejete_si_format_invalide(): void
    {
        $etudiant = $this->creerEtudiant('08111222');
        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee2026->id,
            'niveau' => 'L1',
            'statut' => 'en_cours',
        ]);

        // Fichier exécutable interdit (.exe)
        $fichierInterdit = UploadedFile::fake()->create('script_malveillant.exe', 100);

        $reponse = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->post(route('etudiant.paiements.recus.store', $inscription), [
                'recu' => $fichierInterdit,
                'montant' => '100.00',
                'reference_paiement' => 'TEST-EXE',
                'date_paiement' => now()->format('Y-m-d'),
                'engagement' => '1',
            ]);

        $reponse->assertSessionHasErrors(['recu']);
        $this->assertSame(0, RecuPaiement::count());
    }

    private function creerEtudiant(string $cin): Etudiant
    {
        return Etudiant::create([
            'cin' => $cin,
            'nom' => 'Gharbi',
            'prenom' => 'Sami',
            'email' => "sami.{$cin}@universite.tn",
            'telephone' => '+216 22 111 222',
            'date_naissance' => '2004-04-12',
            'sexe' => 'M',
            'adresse' => 'Rue de la Liberté',
            'ville' => 'Tunis',
            'code_postal' => '1002',
            'annee_bac' => '2023',
            'mention_bac' => 'Bien',
            'annee_academique' => '2026-2027',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'statut' => 'valide',
            'password' => bcrypt('Secret123456!'),
        ]);
    }
}
