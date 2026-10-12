<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\StudentDossiers\Contracts\PdfGeneratorInterface;
use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Modules\StudentDossiers\Models\StudentDossier;
use App\Services\PeriodeInscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Suite de tests dédiée — LOOP 13 : Récépissé PDF et preuve de dépôt.
 *
 * Critères vérifiés :
 * 1. PDF généré avec les bonnes données et le bon statut.
 * 2. Accès inter-étudiants refusé (anti-IDOR et redirection des invités).
 * 3. Le PDF ne contient pas de données d’un autre dossier.
 * 4. Échec de génération communiqué sans perdre le dépôt (fail-soft).
 * 5. Référence et date strictement cohérentes avec la base de données.
 * 6. Distinction explicite entre preuve de soumission et décision d'acceptation.
 * 7. Aucune PII non nécessaire (pas de CIN).
 * 8. Liste des pièces justificatives GED intégrée si présente.
 */
class InscriptionRecepissePdfTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.student-dossiers.active' => true]);

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences de Tunis',
            'code' => 'FST',
            'active' => true,
        ]);
    }

    private function creerEtudiant(array $attributs = []): Etudiant
    {
        $unique = uniqid();

        $etudiant = Etudiant::create(array_merge([
            'cin' => 'CIN'.$unique,
            'nom' => 'Ben Salem',
            'prenom' => 'Yassine',
            'email' => 'yassine.'.$unique.'@universite.tn',
            'date_naissance' => '2003-05-15',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Avenue Habib Bourguiba',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2023',
            'password' => 'Etudiant@123',
        ], $attributs));

        if (isset($attributs['statut'])) {
            $etudiant->definirStatut($attributs['statut']);
        } else {
            $etudiant->definirStatut('en_attente');
        }

        return $etudiant->fresh();
    }

    /**
     * 1. PDF généré avec les bonnes données, en-têtes de sécurité et bon flux binaire.
     */
    public function test_etudiant_authentifie_obtient_son_recepisse_pdf(): void
    {
        $etudiant = $this->creerEtudiant();

        $reponse = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.recepisse'));

        $reponse->assertOk();
        $reponse->assertHeader('Content-Type', 'application/pdf');
        $reponse->assertHeader('Content-Disposition', 'inline; filename="recepisse-inscription-'.$etudiant->num_inscription.'.pdf"');
        $reponse->assertHeader('X-Content-Type-Options', 'nosniff');

        $contenu = $reponse->getContent();
        $this->assertNotEmpty($contenu);
        $this->assertStringStartsWith('%PDF', $contenu);
    }

    /**
     * 2. Accès invité rejeté vers la page de connexion.
     */
    public function test_invite_ne_peut_pas_acceder_au_recepisse(): void
    {
        $this->get(route('etudiant.recepisse'))
            ->assertRedirect(route('etudiant.connexion.show'));
    }

    /**
     * 3. Anti-IDOR et isolation : aucun ID d'URL et aucune donnée d'un tiers dans le document.
     */
    public function test_anti_idor_aucun_melange_de_dossiers_inter_etudiants(): void
    {
        $etudiantA = $this->creerEtudiant([
            'nom' => 'EtudiantA',
            'prenom' => 'Ali',
            'email' => 'etudiant.a@universite.tn',
        ]);

        $etudiantB = $this->creerEtudiant([
            'nom' => 'EtudiantB',
            'prenom' => 'Fatma',
            'email' => 'etudiant.b@universite.tn',
        ]);

        // Capture du HTML rendu pour l'étudiant A
        $capturedHtml = null;
        $this->swap(PdfGeneratorInterface::class, $this->mock(
            PdfGeneratorInterface::class,
            function ($mock) use (&$capturedHtml) {
                $mock->shouldReceive('generer')
                    ->once()
                    ->andReturnUsing(function (string $html) use (&$capturedHtml) {
                        $capturedHtml = $html;

                        return '%PDF-1.4 dummy';
                    });
            }
        ));

        // Connecté en tant que A
        $reponse = $this->withSession(['etudiant_auth_id' => $etudiantA->id])
            ->get(route('etudiant.recepisse'));

        $reponse->assertOk();

        // Le document contient les données de A
        $this->assertNotNull($capturedHtml);
        $this->assertStringContainsString($etudiantA->num_inscription, $capturedHtml);
        $this->assertStringContainsString('EtudiantA', $capturedHtml);
        $this->assertStringContainsString('Ali', $capturedHtml);

        // Le document ne contient STRICTEMENT AUCUNE donnée de B
        $this->assertStringNotContainsString($etudiantB->num_inscription, $capturedHtml);
        $this->assertStringNotContainsString('EtudiantB', $capturedHtml);
        $this->assertStringNotContainsString('Fatma', $capturedHtml);
        $this->assertStringNotContainsString($etudiantB->email, $capturedHtml);

        // Zéro CIN exposée (PII confidentielle)
        $this->assertStringNotContainsString($etudiantA->cin, $capturedHtml);
        $this->assertStringNotContainsString($etudiantB->cin, $capturedHtml);
    }

    /**
     * 4. Statut et référence strictement cohérents avec la base de données.
     */
    public function test_statuts_et_dates_coherents_avec_la_base(): void
    {
        $programme = Programme::create([
            'faculte_id' => $this->faculte->id,
            'nom' => 'Licence Informatique de Gestion',
            'code' => 'LIG-2026',
            'actif' => true,
        ]);

        $niveau = Niveau::create([
            'programme_id' => $programme->id,
            'nom' => 'Première Année (L1)',
            'code' => 'L1',
            'ordre' => 1,
        ]);

        $etudiantValide = $this->creerEtudiant([
            'nom' => 'Gharbi',
            'prenom' => 'Sami',
            'statut' => 'valide',
            'programme_id' => $programme->id,
            'niveau_id' => $niveau->id,
        ]);

        $capturedHtml = null;
        $this->swap(PdfGeneratorInterface::class, $this->mock(
            PdfGeneratorInterface::class,
            function ($mock) use (&$capturedHtml) {
                $mock->shouldReceive('generer')
                    ->once()
                    ->andReturnUsing(function (string $html) use (&$capturedHtml) {
                        $capturedHtml = $html;

                        return '%PDF-1.4 valide';
                    });
            }
        ));

        $this->withSession(['etudiant_auth_id' => $etudiantValide->id])
            ->get(route('etudiant.recepisse'))
            ->assertOk();

        $this->assertNotNull($capturedHtml);
        $this->assertStringContainsString($etudiantValide->num_inscription, $capturedHtml);
        $this->assertStringContainsString('Validé administrativement', $capturedHtml);
        $this->assertStringContainsString('Licence Informatique de Gestion', $capturedHtml);
        $this->assertStringContainsString('Première Année (L1)', $capturedHtml);

        $dateAttendue = $etudiantValide->created_at->setTimezone(PeriodeInscriptionService::fuseau())->format('d/m/Y');
        $this->assertStringContainsString($dateAttendue, $capturedHtml);
    }

    /**
     * 5. Distinction claire : le récépissé est une preuve de soumission et non une décision d'admission.
     */
    public function test_distinction_explicite_soumission_vs_acceptation(): void
    {
        $etudiant = $this->creerEtudiant(['statut' => 'en_attente']);

        $capturedHtml = null;
        $this->swap(PdfGeneratorInterface::class, $this->mock(
            PdfGeneratorInterface::class,
            function ($mock) use (&$capturedHtml) {
                $mock->shouldReceive('generer')
                    ->once()
                    ->andReturnUsing(function (string $html) use (&$capturedHtml) {
                        $capturedHtml = $html;

                        return '%PDF-1.4 soumis';
                    });
            }
        ));

        $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.recepisse'))
            ->assertOk();

        $this->assertNotNull($capturedHtml);
        $this->assertStringContainsString('Récépissé officiel de dépôt de candidature', $capturedHtml);
        $this->assertStringContainsString('Preuve de soumission administrative', $capturedHtml);
        $this->assertStringContainsString('atteste', $capturedHtml);
        $this->assertStringContainsString('transmission', $capturedHtml);
        $this->assertStringContainsString('Il ne constitue en aucun cas une décision d\'admission définitive', $capturedHtml);
        $this->assertStringContainsString('En attente d’examen', $capturedHtml);
    }

    /**
     * 6. Intégration des documents reçus dans le dossier GED si présents.
     */
    public function test_documents_reçus_inclus_dans_le_recepisse(): void
    {
        $etudiant = $this->creerEtudiant();

        $dossier = StudentDossier::create([
            'etudiant_id' => $etudiant->id,
            'reference' => 'DOS-'.$etudiant->num_inscription,
            'status' => 'open',
        ]);

        StudentDocument::create([
            'student_dossier_id' => $dossier->id,
            'category' => 'identite',
            'document_type' => 'piece_identite',
            'title' => 'Copie CIN recto-verso',
            'original_filename' => 'cin_scan.pdf',
            'storage_path' => 'private/dossiers/cin_scan.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 102400,
            'sha256' => hash('sha256', 'fichier_cin'),
            'status' => 'validated',
        ]);

        StudentDocument::create([
            'student_dossier_id' => $dossier->id,
            'category' => 'diplome',
            'document_type' => 'releve_notes_bac',
            'title' => 'Relevé officiel du Baccalauréat',
            'original_filename' => 'releve_bac.pdf',
            'storage_path' => 'private/dossiers/releve_bac.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 204800,
            'sha256' => hash('sha256', 'fichier_bac'),
            'status' => 'pending',
        ]);

        $capturedHtml = null;
        $this->swap(PdfGeneratorInterface::class, $this->mock(
            PdfGeneratorInterface::class,
            function ($mock) use (&$capturedHtml) {
                $mock->shouldReceive('generer')
                    ->once()
                    ->andReturnUsing(function (string $html) use (&$capturedHtml) {
                        $capturedHtml = $html;

                        return '%PDF-1.4 docs';
                    });
            }
        ));

        $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.recepisse'))
            ->assertOk();

        $this->assertNotNull($capturedHtml);
        $this->assertStringContainsString('Copie CIN recto-verso', $capturedHtml);
        $this->assertStringContainsString('Relevé officiel du Baccalauréat', $capturedHtml);
        $this->assertStringContainsString('Conforme', $capturedHtml);
        $this->assertStringContainsString('En attente', $capturedHtml);
    }

    /**
     * 7. Échec de génération communiqué proprement sans perte du dépôt (Fail-Soft).
     */
    public function test_echec_moteur_pdf_communique_sans_perte_du_depot(): void
    {
        $etudiant = $this->creerEtudiant();

        Log::spy();

        $this->swap(PdfGeneratorInterface::class, $this->mock(
            PdfGeneratorInterface::class,
            fn ($mock) => $mock->shouldReceive('generer')->andThrow(new \RuntimeException('Service PDF en surcharge')),
        ));

        $reponse = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.recepisse'));

        $reponse->assertRedirect(route('etudiant.profil.show'));
        $reponse->assertSessionHasErrors('recepisse');

        // Vérification de la journalisation sans PII
        Log::shouldHaveReceived('error')->with(
            'recepisse.generation_echec',
            \Mockery::on(function ($context) use ($etudiant) {
                return $context['etudiant_id'] === $etudiant->id
                    && $context['reference'] === $etudiant->num_inscription
                    && $context['exception'] === \RuntimeException::class;
            })
        );

        // La base de données reste totalement intacte : aucun dépôt perdu
        $this->assertDatabaseHas('etudiants', [
            'id' => $etudiant->id,
            'num_inscription' => $etudiant->num_inscription,
        ]);
    }
}
