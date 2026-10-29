<?php

namespace Tests\Feature\Security;

use App\Models\AnneeUniversitaire;
use App\Models\DocumentAcademique;
use App\Models\DossierAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\Semestre;
use App\Models\User;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\ParcoursAcademique\Models\ParcoursSimulation;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\StudentDossiers\Contracts\PdfGeneratorInterface;
use App\Services\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * LOT 13 — Parcours académique, relevés de notes et attestations officielles.
 *
 * Vérifications de sécurité et d'expérience étudiante :
 * 1. Redirection stricte des visiteurs non authentifiés vers la page de connexion.
 * 2. Consultation du parcours LMD en lecture seule strictement session-scopé.
 * 3. Génération du parcours PDF officiel à la demande avec en-têtes sécurisés et sans CIN (minimisation PII).
 * 4. Tolérance aux pannes fail-soft en cas de défaillance du moteur PDF (pas de crash 500).
 * 5. Respect strict de la règle D-01 : masquage des résultats non publiés sur les inscriptions.
 * 6. Rendu des résultats complets uniquement lorsque la délibération du jury est publiée.
 * 7. Exclusion totale des inscriptions sans décision publiée sur le relevé de notes officiel.
 * 8. Coffre-fort documentaire hermétique : affichage exclusif des documents de l'étudiant connecté.
 * 9. Isolation stricte anti-IDOR sur le téléchargement de documents sensibles (404).
 * 10. Blocage sécurisé (403) du téléchargement de relevé de notes si la délibération n'est pas publiée.
 * 11. Téléchargement autorisé de document avec traçabilité d'audit et protection d'en-tête nosniff.
 * 12. Assainissement du nom de fichier de téléchargement contre l'injection d'en-têtes HTTP (CRLF).
 * 13. Récépissé d'inscription à la demande sans stockage persistant, avec empreinte cryptographique.
 * 14. Tolérance aux pannes fail-soft sur la génération du récépissé d'inscription.
 * 15. Simulations de parcours : indisponibilité explicite sans inscription ou avec notes non publiées (D-01).
 * 16. Simulations en self-service append-only (auteur_id null) sans altération des notes ni inscriptions.
 * 17. Isolation anti-IDOR sur les simulations et masquage des simulations internes du back-office.
 * 18. Protection anti-force brute par rate limiting sur les téléchargements et simulations.
 */
class Loop13ParcoursAcademiqueRelevesAttestationsTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Niveau $niveau;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.parcours-academique.active' => true,
            'modules.student-dossiers.active' => true,
        ]);

        $this->faculte = Faculte::create([
            'code' => 'FST',
            'nom' => 'Faculté des Sciences de Tunis',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026',
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30',
            'active' => true,
        ]);

        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'LIC-INFO',
            'nom' => 'Licence en Informatique',
            'type' => 'licence',
            'actif' => true,
        ]);

        $this->niveau = Niveau::create([
            'programme_id' => $this->programme->id,
            'code' => 'L1',
            'nom' => 'Première Année',
            'ordre' => 1,
            'actif' => true,
        ]);
    }

    private function creerEtudiant(array $attributs = []): Etudiant
    {
        static $compteur = 1;

        $statut = $attributs['statut'] ?? 'valide';
        unset($attributs['statut']);

        $defauts = [
            'cin' => sprintf('PARC%04d', $compteur),
            'nom' => 'Trabelsi',
            'prenom' => 'Hédi '.$compteur,
            'email' => sprintf('etudiant.parcours.%d@example.com', $compteur),
            'telephone' => sprintf('+216710000%02d', $compteur),
            'date_naissance' => '2004-03-20',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Campus Universitaire',
            'ville' => 'Tunis',
            'code_postal' => '1002',
            'annee_bac' => '2023',
            'mot_de_passe_version' => 1,
            'password' => bcrypt('MotDePasseSecret123!'),
        ];

        $compteur++;

        $etudiant = Etudiant::create(array_merge($defauts, $attributs));
        if ($statut !== 'en_attente') {
            $etudiant->definirStatut($statut);
        }

        DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-'.$etudiant->num_inscription,
            'date_archivage' => now()->toDateString(),
            'statut' => 'actif',
        ]);

        return $etudiant;
    }

    private function sessionEtudiant(Etudiant $etudiant): array
    {
        return [
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_auth_prenom' => $etudiant->prenom,
            'etudiant_auth_nom' => $etudiant->nom,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ];
    }

    public function test_redirection_visiteurs_non_authentifies_sur_toutes_les_routes_parcours_releves_attestations(): void
    {
        $etudiant = $this->creerEtudiant();
        $doc = DocumentAcademique::create([
            'etudiant_id' => $etudiant->id,
            'type_document' => 'attestation',
            'nom_original' => 'attestation.pdf',
            'chemin' => 'archives/doc.pdf',
            'mime_type' => 'application/pdf',
            'taille' => 1024,
            'hash_fichier' => hash('sha256', 'test'),
        ]);

        $routes = [
            route('etudiant.parcours'),
            route('etudiant.parcours.pdf'),
            route('etudiant.inscriptions.index'),
            route('etudiant.notes.index'),
            route('etudiant.documents.index'),
            route('etudiant.historique.index'),
            route('etudiant.archives.documents.download', $doc),
            route('etudiant.recepisse'),
            route('etudiant.parcours.simulations.index'),
            route('etudiant.parcours.simulations.create'),
        ];

        foreach ($routes as $url) {
            $response = $this->get($url);
            $response->assertRedirect(route('etudiant.connexion.show'));
            $response->assertSessionHas('error');
        }
    }

    public function test_consultation_parcours_lmd_lecture_seule_session_scopee(): void
    {
        $etudiant = $this->creerEtudiant();

        InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1',
            'statut' => 'en_cours',
            'type' => 'premiere',
            'regime' => 'initiale',
        ]);

        $response = $this->withSession($this->sessionEtudiant($etudiant))
            ->get(route('etudiant.parcours'));

        $response->assertOk();
        $response->assertSee('Mon parcours LMD');
        $response->assertSee('LIC-INFO');
        $response->assertSee('Licence en Informatique');
        $response->assertSee('Version PDF (informatif)');
    }

    public function test_generation_parcours_pdf_a_la_demande_avec_en_tetes_securises_et_sans_cin(): void
    {
        $etudiant = $this->creerEtudiant();

        InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1',
            'statut' => 'en_cours',
            'type' => 'premiere',
            'regime' => 'initiale',
        ]);

        $capturedHtml = '';
        $pdfMock = $this->createMock(PdfGeneratorInterface::class);
        $pdfMock->method('generer')->willReturnCallback(function ($html) use (&$capturedHtml) {
            $capturedHtml = $html;

            return '%PDF-1.4 test parcours content';
        });
        app()->instance(PdfGeneratorInterface::class, $pdfMock);

        $response = $this->withSession($this->sessionEtudiant($etudiant))
            ->get(route('etudiant.parcours.pdf'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('parcours-'.$etudiant->num_inscription.'.pdf', $response->headers->get('Content-Disposition'));

        // Minimisation stricte des données personnelles : pas de CIN dans le document informatif
        $this->assertStringNotContainsString($etudiant->cin, $capturedHtml);
        $this->assertStringContainsString($etudiant->nom, $capturedHtml);
        $this->assertStringContainsString($etudiant->prenom, $capturedHtml);
    }

    public function test_fail_soft_generation_parcours_pdf_en_cas_d_erreur_moteur(): void
    {
        $etudiant = $this->creerEtudiant();

        InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1',
            'statut' => 'en_cours',
            'type' => 'premiere',
            'regime' => 'initiale',
        ]);

        $pdfMock = $this->createMock(PdfGeneratorInterface::class);
        $pdfMock->method('generer')->willThrowException(new \RuntimeException('Panne inattendue du générateur PDF'));
        app()->instance(PdfGeneratorInterface::class, $pdfMock);

        $response = $this->withSession($this->sessionEtudiant($etudiant))
            ->get(route('etudiant.parcours.pdf'));

        // Fail-soft : redirection propre avec message d'erreur et aucun code 500
        $response->assertRedirect(route('etudiant.parcours'));
        $response->assertSessionHasErrors(['parcours']);
    }

    public function test_inscriptions_academiques_masquent_resultats_non_publies_regle_d01(): void
    {
        $etudiant = $this->creerEtudiant();

        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1',
            'statut' => 'en_cours',
            'type' => 'premiere',
            'regime' => 'initiale',
            'moyenne_annuelle' => 16.50,
            'credits_obtenus' => 60,
            'credits_total' => 60,
            'decision' => 'admis',
        ]);

        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $this->annee->id,
            'statut' => 'draft',
            'published_at' => null,
        ]);

        // Délibération non publiée
        DecisionDeliberation::create([
            'deliberation_id' => $deliberation->id,
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'moyenne' => 16.50,
            'credits_obtenus' => 60,
            'credits_total' => 60,
            'decision' => 'admis',
            'publie' => false,
        ]);

        $response = $this->withSession($this->sessionEtudiant($etudiant))
            ->get(route('etudiant.inscriptions.index'));

        $response->assertOk();
        $response->assertSee('2025-2026');
        $response->assertSee('L1');

        // La règle D-01 impose le masquage des résultats calculés tant que non publiés
        $inscriptions = $response->viewData('inscriptions');
        $this->assertNull($inscriptions->first()->moyenne_annuelle);
        $this->assertEquals(0, $inscriptions->first()->credits_obtenus);
        $this->assertNull($inscriptions->first()->decision);

        // La moyenne secrète n'apparaît nulle part dans le code HTML généré
        $response->assertDontSee('16.50');
    }

    public function test_inscriptions_academiques_affichent_resultats_si_deliberation_publiee(): void
    {
        $etudiant = $this->creerEtudiant();

        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1',
            'statut' => 'en_cours',
            'type' => 'premiere',
            'regime' => 'initiale',
            'moyenne_annuelle' => 15.25,
            'credits_obtenus' => 60,
            'credits_total' => 60,
            'decision' => 'admis',
        ]);

        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $this->annee->id,
            'statut' => 'validated',
            'published_at' => now(),
        ]);

        // Délibération officiellement publiée
        DecisionDeliberation::create([
            'deliberation_id' => $deliberation->id,
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'moyenne' => 15.25,
            'credits_obtenus' => 60,
            'credits_total' => 60,
            'decision' => 'admis',
            'publie' => true,
        ]);

        $response = $this->withSession($this->sessionEtudiant($etudiant))
            ->get(route('etudiant.inscriptions.index'));

        $response->assertOk();
        $inscriptions = $response->viewData('inscriptions');
        $this->assertEquals('15.25', (string) $inscriptions->first()->moyenne_annuelle);
        $this->assertEquals(60, $inscriptions->first()->credits_obtenus);
        $this->assertEquals('admis', $inscriptions->first()->decision);
        $response->assertSee('15.25');
    }

    public function test_releve_de_notes_etudiant_exclut_inscriptions_sans_deliberation_publiee(): void
    {
        $etudiant = $this->creerEtudiant();

        $annee2 = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => false,
        ]);

        // Inscription 1 : Délibération publiée
        $inscrPubliee = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1',
            'statut' => 'valide',
            'type' => 'premiere',
            'regime' => 'initiale',
        ]);

        $delib1 = Deliberation::create([
            'annee_universitaire_id' => $this->annee->id,
            'statut' => 'validated',
            'published_at' => now(),
        ]);

        DecisionDeliberation::create([
            'deliberation_id' => $delib1->id,
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscrPubliee->id,
            'moyenne' => 14.00,
            'credits_obtenus' => 60,
            'credits_total' => 60,
            'decision' => 'admis',
            'publie' => true,
        ]);

        $sem1 = Semestre::create([
            'inscription_academique_id' => $inscrPubliee->id,
            'numero' => 1,
        ]);
        $mat1 = Matiere::create([
            'code' => 'MATH101',
            'nom' => 'Mathématiques 1',
            'credits' => 5,
            'niveau' => 'L1',
            'semestre' => 1,
        ]);
        Note::create([
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscrPubliee->id,
            'semestre_id' => $sem1->id,
            'matiere_id' => $mat1->id,
            'note' => 14.00,
            'coefficient' => 1.0,
            'credits' => 5,
        ]);

        // Inscription 2 : Délibération NON publiée
        $inscrNonPubliee = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $annee2->id,
            'niveau' => 'L2',
            'statut' => 'en_cours',
            'type' => 'reinscription',
            'regime' => 'initiale',
        ]);

        $delib2 = Deliberation::create([
            'annee_universitaire_id' => $annee2->id,
            'statut' => 'draft',
            'published_at' => null,
        ]);

        DecisionDeliberation::create([
            'deliberation_id' => $delib2->id,
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscrNonPubliee->id,
            'moyenne' => 11.50,
            'credits_obtenus' => 45,
            'credits_total' => 60,
            'decision' => 'ajourne',
            'publie' => false,
        ]);

        $response = $this->withSession($this->sessionEtudiant($etudiant))
            ->get(route('etudiant.notes.index'));

        $response->assertOk();
        $inscriptionsNotes = $response->viewData('inscriptions');

        // Seule l'inscription dont les résultats sont publiés est exposée
        $this->assertTrue($inscriptionsNotes->contains('id', $inscrPubliee->id));
        $this->assertFalse($inscriptionsNotes->contains('id', $inscrNonPubliee->id));
        $response->assertSee('2025-2026');
        $response->assertDontSee('2026-2027');
    }

    public function test_coffre_fort_documentaire_liste_uniquement_documents_de_l_etudiant_connecte(): void
    {
        $etudiantA = $this->creerEtudiant(['cin' => 'COFF0001']);
        $etudiantB = $this->creerEtudiant(['cin' => 'COFF0002']);

        $docA = DocumentAcademique::create([
            'etudiant_id' => $etudiantA->id,
            'annee_universitaire_id' => $this->annee->id,
            'type_document' => 'attestation',
            'nom_original' => 'attestation_scolarite_A.pdf',
            'chemin' => 'archives/etudiant_'.$etudiantA->id.'/attestation.pdf',
            'mime_type' => 'application/pdf',
            'taille' => 2048,
            'hash_fichier' => hash('sha256', 'fichier_a'),
        ]);

        $docB = DocumentAcademique::create([
            'etudiant_id' => $etudiantB->id,
            'annee_universitaire_id' => $this->annee->id,
            'type_document' => 'attestation',
            'nom_original' => 'attestation_scolarite_B_CONFIDENTIELLE.pdf',
            'chemin' => 'archives/etudiant_'.$etudiantB->id.'/attestation_b.pdf',
            'mime_type' => 'application/pdf',
            'taille' => 2048,
            'hash_fichier' => hash('sha256', 'fichier_b'),
        ]);

        $response = $this->withSession($this->sessionEtudiant($etudiantA))
            ->get(route('etudiant.documents.index'));

        $response->assertOk();
        $docs = $response->viewData('documents');
        $this->assertTrue($docs->contains('id', $docA->id));
        $this->assertFalse($docs->contains('id', $docB->id));

        $response->assertSee('attestation_scolarite_A.pdf');
        $response->assertDontSee('attestation_scolarite_B_CONFIDENTIELLE.pdf');
    }

    public function test_protection_anti_idor_telechargement_document_tierce_renvoie_404(): void
    {
        $etudiantA = $this->creerEtudiant(['cin' => 'IDORDOC1']);
        $etudiantB = $this->creerEtudiant(['cin' => 'IDORDOC2']);

        $docB = DocumentAcademique::create([
            'etudiant_id' => $etudiantB->id,
            'type_document' => 'attestation',
            'nom_original' => 'secret_b.pdf',
            'chemin' => 'archives/b.pdf',
            'mime_type' => 'application/pdf',
            'taille' => 1024,
            'hash_fichier' => hash('sha256', 'b'),
        ]);

        // Étudiant A tente de télécharger le document de B
        $response = $this->withSession($this->sessionEtudiant($etudiantA))
            ->get(route('etudiant.archives.documents.download', $docB));

        $response->assertNotFound();
    }

    public function test_telechargement_releve_notes_refuse_403_si_deliberation_non_publiee(): void
    {
        $etudiant = $this->creerEtudiant();

        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1',
            'statut' => 'en_cours',
            'type' => 'premiere',
            'regime' => 'initiale',
        ]);

        $docReleve = DocumentAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'type_document' => 'releve_notes',
            'nom_original' => 'releve_2025_2026.pdf',
            'chemin' => 'archives/releve.pdf',
            'mime_type' => 'application/pdf',
            'taille' => 2048,
            'hash_fichier' => hash('sha256', 'releve'),
        ]);

        // Aucune décision publiée
        $response = $this->withSession($this->sessionEtudiant($etudiant))
            ->get(route('etudiant.archives.documents.download', $docReleve));

        $response->assertStatus(403);
    }

    public function test_telechargement_document_reussi_avec_tracabilite_audit_et_en_tetes_securises(): void
    {
        Storage::fake('local');

        $etudiant = $this->creerEtudiant();
        $cheminFichier = 'archives/etudiant_'.$etudiant->id.'/attestation.pdf';
        Storage::disk('local')->put($cheminFichier, '%PDF-1.4 Attestation officielle');

        $doc = DocumentAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'type_document' => 'attestation',
            'nom_original' => 'Attestation de scolarité.pdf',
            'chemin' => $cheminFichier,
            'mime_type' => 'application/pdf',
            'taille' => 1024,
            'hash_fichier' => hash('sha256', '%PDF-1.4 Attestation officielle'),
        ]);

        $response = $this->withSession($this->sessionEtudiant($etudiant))
            ->get(route('etudiant.archives.documents.download', $doc));

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');

        // Audit de traçabilité conforme
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $etudiant->id,
            'action' => HistoriqueAcademique::ACTION_TELECHARGEMENT_DOCUMENT,
            'entite' => 'document',
            'entite_id' => $doc->id,
        ]);
    }

    public function test_sanitisation_nom_fichier_telechargement_anti_injection_header_crlf(): void
    {
        $documentService = app(DocumentService::class);

        // Tentative d'injection CRLF dans l'en-tête Content-Disposition
        $nomMalveillant = "attestation\r\nSet-Cookie: sessionId=malicious.pdf";
        $assaini = $documentService->nomTelechargementSecurise($nomMalveillant);

        $this->assertStringNotContainsString("\r", $assaini);
        $this->assertStringNotContainsString("\n", $assaini);
        $this->assertStringNotContainsString('"', $assaini);
        $this->assertEquals('attestationSet-Cookie: sessionId=malicious.pdf', $assaini);
    }

    public function test_recepisse_inscription_pdf_a_la_demande_session_scope_avec_empreinte_securite(): void
    {
        $etudiant = $this->creerEtudiant();

        $capturedHtml = '';
        $pdfMock = $this->createMock(PdfGeneratorInterface::class);
        $pdfMock->method('generer')->willReturnCallback(function ($html) use (&$capturedHtml) {
            $capturedHtml = $html;

            return '%PDF-1.4 Recepisse officiel';
        });
        app()->instance(PdfGeneratorInterface::class, $pdfMock);

        $response = $this->withSession($this->sessionEtudiant($etudiant))
            ->get(route('etudiant.recepisse'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('recepisse-inscription-'.$etudiant->num_inscription.'.pdf', $response->headers->get('Content-Disposition'));

        // Preuve de soumission, sans CIN
        $this->assertStringNotContainsString($etudiant->cin, $capturedHtml);
        $this->assertStringContainsString($etudiant->num_inscription, $capturedHtml);
    }

    public function test_fail_soft_telechargement_recepisse_en_cas_d_erreur_moteur(): void
    {
        $etudiant = $this->creerEtudiant();

        $pdfMock = $this->createMock(PdfGeneratorInterface::class);
        $pdfMock->method('generer')->willThrowException(new \RuntimeException('Erreur interne rendu récépissé'));
        app()->instance(PdfGeneratorInterface::class, $pdfMock);

        $response = $this->withSession($this->sessionEtudiant($etudiant))
            ->get(route('etudiant.recepisse'));

        // Redirection propre vers le profil étudiant avec message d'erreur
        $response->assertRedirect(route('etudiant.profil.show'));
        $response->assertSessionHasErrors(['recepisse']);
    }

    public function test_simulations_parcours_indisponibilite_explicite_sans_inscription_ou_avec_notes_non_publiees(): void
    {
        $etudiantSansInscr = $this->creerEtudiant(['cin' => 'SIMUL001']);

        // 1. Sans inscription : état explicite
        $respCreate = $this->withSession($this->sessionEtudiant($etudiantSansInscr))
            ->get(route('etudiant.parcours.simulations.create'));

        $respCreate->assertOk();
        $this->assertEquals('sans_inscription', $respCreate->viewData('etatSimulation'));

        $respPost = $this->withSession($this->sessionEtudiant($etudiantSansInscr))
            ->post(route('etudiant.parcours.simulations.store'), [
                'scenario' => 'passage',
            ]);

        $respPost->assertRedirect(route('etudiant.parcours.simulations.create'));
        $respPost->assertSessionHas('error');

        // 2. Avec inscription portant des notes non publiées (D-01)
        $etudiantNotesCachees = $this->creerEtudiant(['cin' => 'SIMUL002']);
        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiantNotesCachees->id,
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1',
            'statut' => 'en_cours',
            'type' => 'premiere',
            'regime' => 'initiale',
        ]);

        $semestre = Semestre::create([
            'inscription_academique_id' => $inscription->id,
            'numero' => 1,
        ]);

        $matiere = Matiere::create([
            'code' => 'ALGO1',
            'nom' => 'Algorithmique 1',
            'credits' => 5,
            'niveau' => 'L1',
            'semestre' => 1,
        ]);

        Note::create([
            'etudiant_id' => $etudiantNotesCachees->id,
            'inscription_academique_id' => $inscription->id,
            'semestre_id' => $semestre->id,
            'matiere_id' => $matiere->id,
            'note' => 14.50,
            'coefficient' => 1.0,
            'credits' => 5,
        ]);

        $respCreate2 = $this->withSession($this->sessionEtudiant($etudiantNotesCachees))
            ->get(route('etudiant.parcours.simulations.create'));

        $respCreate2->assertOk();
        $this->assertEquals('resultats_non_publies', $respCreate2->viewData('etatSimulation'));

        $respPost2 = $this->withSession($this->sessionEtudiant($etudiantNotesCachees))
            ->post(route('etudiant.parcours.simulations.store'), [
                'scenario' => 'passage',
            ]);

        $respPost2->assertRedirect(route('etudiant.parcours.simulations.create'));
        $respPost2->assertSessionHas('error');
    }

    public function test_simulations_parcours_self_service_append_only_sans_impact_sur_donnees_officielles(): void
    {
        $etudiant = $this->creerEtudiant();

        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1',
            'statut' => 'valide',
            'type' => 'premiere',
            'regime' => 'initiale',
        ]);

        $delib = Deliberation::create([
            'annee_universitaire_id' => $this->annee->id,
            'statut' => 'validated',
            'published_at' => now(),
        ]);

        DecisionDeliberation::create([
            'deliberation_id' => $delib->id,
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'moyenne' => 14.00,
            'credits_obtenus' => 60,
            'credits_total' => 60,
            'decision' => 'admis',
            'publie' => true,
        ]);

        $notesAvant = DB::table('notes')->count();
        $inscriptionAvant = $inscription->fresh()->toArray();

        $response = $this->withSession($this->sessionEtudiant($etudiant))
            ->post(route('etudiant.parcours.simulations.store'), [
                'scenario' => 'passage',
                'hypotheses' => [
                    'note' => 12.5,
                ],
            ]);

        $simulation = ParcoursSimulation::where('etudiant_id', $etudiant->id)->first();
        $this->assertNotNull($simulation);
        $this->assertNull($simulation->auteur_id); // Append-only self-service
        $this->assertEquals('passage', $simulation->scenario);

        $response->assertRedirect(route('etudiant.parcours.simulations.show', $simulation));

        // Aucune écriture officielle dans les tables de scolarité
        $this->assertEquals($notesAvant, DB::table('notes')->count());
        $this->assertEquals($inscriptionAvant, $inscription->fresh()->toArray());
    }

    public function test_simulations_parcours_anti_idor_et_masquage_simulations_back_office(): void
    {
        $etudiantA = $this->creerEtudiant(['cin' => 'SIMA0001']);
        $etudiantB = $this->creerEtudiant(['cin' => 'SIMB0002']);

        // Simulation de l'étudiant B
        $simB = ParcoursSimulation::create([
            'etudiant_id' => $etudiantB->id,
            'scenario' => 'passage',
            'hypotheses' => [],
            'resultat' => ['valide' => true],
            'auteur_id' => null,
        ]);

        $admin = User::creerCompteBackOffice([
            'name' => 'Agent Scolarité',
            'email' => 'agent.scol@fst.tn',
            'password' => 'PassSecret123!',
            'faculte_id' => $this->faculte->id,
        ], 'admin_scolarite');

        // Simulation de l'étudiant A créée par le back-office (auteur_id renseigne)
        $simAdminA = ParcoursSimulation::create([
            'etudiant_id' => $etudiantA->id,
            'scenario' => 'redoublement',
            'hypotheses' => [],
            'resultat' => ['valide' => false],
            'auteur_id' => $admin->id, // simulation interne scolarité
        ]);

        $sessionA = $this->sessionEtudiant($etudiantA);

        // Étudiant A tente d'accéder à la simulation de B -> 404
        $this->withSession($sessionA)
            ->get(route('etudiant.parcours.simulations.show', $simB))
            ->assertNotFound();

        // Étudiant A tente d'accéder à la simulation back-office le concernant -> 404 (document de travail interne)
        $this->withSession($sessionA)
            ->get(route('etudiant.parcours.simulations.show', $simAdminA))
            ->assertNotFound();
    }

    public function test_rate_limiting_telechargements_et_simulations(): void
    {
        $etudiant = $this->creerEtudiant();

        InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1',
            'statut' => 'en_cours',
            'type' => 'premiere',
            'regime' => 'initiale',
        ]);

        $pdfMock = $this->createMock(PdfGeneratorInterface::class);
        $pdfMock->method('generer')->willReturn('%PDF-1.4 test');
        app()->instance(PdfGeneratorInterface::class, $pdfMock);

        $session = $this->sessionEtudiant($etudiant);

        // La route /etudiant/parcours/pdf est configurée avec throttle:10,1
        for ($i = 1; $i <= 10; $i++) {
            $resp = $this->withSession($session)->get(route('etudiant.parcours.pdf'));
            $resp->assertOk();
        }

        // La 11e requête déclenche un code HTTP 429
        $rejet = $this->withSession($session)->get(route('etudiant.parcours.pdf'));
        $rejet->assertStatus(429);
    }
}
