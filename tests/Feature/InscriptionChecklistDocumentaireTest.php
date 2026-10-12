<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Modules\StudentDossiers\Models\StudentDossier;
use App\Services\DocumentsRequisService;
use App\Services\PeriodeInscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP 04 : Checklist documentaire dynamique.
 * Vérifie :
 * - L'affichage adaptatif des pièces attendues selon le type d'inscription ;
 * - Les métadonnées officielles de chaque document (obligatoire/facultatif, formats, taille, consignes) ;
 * - L'isolation et le non-blocage par les pièces facultatives ;
 * - Les statuts réels du dossier GED (validé, en attente, rejeté avec motif, expiré, non fourni) ;
 * - L'endpoint public /inscription/pieces-requises et sa sécurité anti-énumération.
 */
class InscriptionChecklistDocumentaireTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculte;

    protected AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences et Techniques',
            'code' => 'FST',
            'description' => 'Sciences et technologies',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026',
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30',
            'active' => true,
        ]);

        app(PeriodeInscriptionService::class)->creerNormale([
            'annee_universitaire_id' => $this->annee->id,
            'opened_at' => '2020-01-01 00:00',
            'closed_at' => '2030-12-31 23:59',
        ], null);
    }

    public function test_page_inscription_contient_checklists_documentaires_et_etape_5_dynamique(): void
    {
        $response = $this->get(route('inscription.create'));

        $response->assertStatus(200);
        $response->assertSee('Étape 5 — Checklist des pièces justificatives');
        $response->assertSee('Pièces requises pour votre parcours');
        $response->assertSee('checklist-pieces-container');
        $response->assertSee('checklists[categorie]', false);
        $response->assertSee('piece.libelle', false);
        $response->assertSee('piece.obligatoire', false);
        $response->assertSee('piece.taille_max_mo', false);
        $response->assertSee('Formats :', false);
        $response->assertSee('Obligatoire');
        $response->assertSee('Facultatif');
    }

    public function test_endpoint_pieces_requises_renvoie_catalogue_et_matrice_selon_le_type(): void
    {
        // 1. Première inscription
        $repPremiere = $this->getJson(route('inscription.pieces-requises', ['type' => 'premiere']));
        $repPremiere->assertStatus(200);
        $repPremiere->assertJsonPath('success', true);
        $repPremiere->assertJsonPath('type', 'premiere');
        $piecesPremiere = collect($repPremiere->json('pieces'));
        $this->assertTrue($piecesPremiere->contains('code', 'cin'));
        $this->assertTrue($piecesPremiere->contains('code', 'photo_identite'));
        $this->assertTrue($piecesPremiere->contains('code', 'releve_notes'));
        $this->assertTrue($piecesPremiere->contains('code', 'acte_naissance'));

        $pieceCin = $piecesPremiere->firstWhere('code', 'cin');
        $this->assertTrue($pieceCin['obligatoire']);
        $this->assertContains('pdf', $pieceCin['formats']);
        $this->assertGreaterThanOrEqual(5, $pieceCin['taille_max_mo']);

        $pieceActe = $piecesPremiere->firstWhere('code', 'acte_naissance');
        $this->assertFalse($pieceActe['obligatoire']);

        // 2. Réinscription
        $repReinscription = $this->getJson(route('inscription.pieces-requises', ['type' => 'reinscription']));
        $repReinscription->assertStatus(200);
        $repReinscription->assertJsonPath('type', 'reinscription');
        $piecesReinscription = collect($repReinscription->json('pieces'));
        $this->assertTrue($piecesReinscription->contains('code', 'quitus_scolarite'));

        // 3. Transfert
        $repTransfert = $this->getJson(route('inscription.pieces-requises', ['type' => 'transfert']));
        $repTransfert->assertStatus(200);
        $repTransfert->assertJsonPath('type', 'transfert');
        $piecesTransfert = collect($repTransfert->json('pieces'));
        $this->assertTrue($piecesTransfert->contains('code', 'attestation_transfert'));
        $this->assertTrue($piecesTransfert->contains('code', 'releves_anterieurs'));
    }

    public function test_pieces_facultatives_ne_bloquent_jamais_la_completude_du_dossier(): void
    {
        $service = app(DocumentsRequisService::class);
        $etudiant = $this->creerEtudiant('08123456', '2002-05-10');

        // On fournit uniquement les pièces obligatoires pour première inscription
        $this->deposerPieceGed($etudiant, 'cin', 'validated');
        $this->deposerPieceGed($etudiant, 'releve_notes', 'validated');
        $this->deposerPieceGed($etudiant, 'photo_identite', 'validated');
        // 'acte_naissance' est facultatif et non fourni

        $bilan = $service->checklistDossier($etudiant, 'premiere');

        $this->assertTrue($bilan['complet']);
        $this->assertTrue($bilan['peut_soumettre']);
        $this->assertEmpty($bilan['obligatoires_manquantes']);
        $this->assertSame($bilan['obligatoires_total'], $bilan['obligatoires_valides']);

        $pieceActe = collect($bilan['pieces'])->firstWhere('code', 'acte_naissance');
        $this->assertNotNull($pieceActe);
        $this->assertFalse($pieceActe['obligatoire']);
        $this->assertSame('non_fourni', $pieceActe['statut']);
    }

    public function test_piece_obligatoire_manquante_ou_rejetee_ou_expiree_bloque_la_completude_avec_motif(): void
    {
        $service = app(DocumentsRequisService::class);
        $etudiant = $this->creerEtudiant('08654321', '2001-09-15');

        // CIN validée
        $this->deposerPieceGed($etudiant, 'cin', 'validated');
        // Relevé de notes rejeté avec motif explicite
        $this->deposerPieceGed($etudiant, 'releve_notes', 'rejected', 'Document illisible, scannez l\'original en 300 DPI.');
        // Photo d'identité expirée
        $this->deposerPieceGed($etudiant, 'photo_identite', 'validated', null, now()->subDay());

        $bilan = $service->checklistDossier($etudiant, 'premiere');

        $this->assertFalse($bilan['complet']);
        $this->assertFalse($bilan['peut_soumettre']);
        $this->assertContains('releve_notes', $bilan['obligatoires_manquantes']);
        $this->assertContains('photo_identite', $bilan['obligatoires_manquantes']);

        $pieceReleve = collect($bilan['pieces'])->firstWhere('code', 'releve_notes');
        $this->assertSame('rejete', $pieceReleve['statut']);
        $this->assertSame('Document illisible, scannez l\'original en 300 DPI.', $pieceReleve['motif_rejet']);

        $piecePhoto = collect($bilan['pieces'])->firstWhere('code', 'photo_identite');
        $this->assertSame('expire', $piecePhoto['statut']);
    }

    public function test_endpoint_pieces_requises_evalue_dossier_etudiant_avec_second_facteur_valide(): void
    {
        $etudiant = $this->creerEtudiant('08777777', '2000-01-20');
        $this->deposerPieceGed($etudiant, 'cin', 'validated');

        $response = $this->getJson(route('inscription.pieces-requises', [
            'type' => 'premiere',
            'cin' => '08777777',
            'date_naissance' => '2000-01-20',
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'success',
            'type',
            'dossier' => [
                'type',
                'complet',
                'total_pieces',
                'obligatoires_total',
                'obligatoires_valides',
                'obligatoires_manquantes',
                'peut_soumettre',
                'progression',
                'pieces',
            ],
            'catalogue',
        ]);

        $dossier = $response->json('dossier');
        $cinPiece = collect($dossier['pieces'])->firstWhere('code', 'cin');
        $this->assertSame('valide', $cinPiece['statut']);
    }

    public function test_anti_enumeration_endpoint_pieces_requises_sans_date_ou_date_invalide_ne_divulgue_aucun_dossier(): void
    {
        $this->creerEtudiant('08888888', '2000-01-20');

        // 1. Avec CIN seul sans date de naissance : aucune évaluation de dossier
        $repSansDate = $this->getJson(route('inscription.pieces-requises', [
            'type' => 'premiere',
            'cin' => '08888888',
        ]));
        $repSansDate->assertStatus(200);
        $this->assertNull($repSansDate->json('dossier'));
        $this->assertNotNull($repSansDate->json('pieces'));

        // 2. Avec CIN et mauvaise date de naissance : aucune fuite
        $repMauvaiseDate = $this->getJson(route('inscription.pieces-requises', [
            'type' => 'premiere',
            'cin' => '08888888',
            'date_naissance' => '1995-12-31',
        ]));
        $repMauvaiseDate->assertStatus(200);
        $this->assertNull($repMauvaiseDate->json('dossier'));
        $this->assertNotNull($repMauvaiseDate->json('pieces'));
    }

    public function test_validation_type_invalide_rejete_en_422(): void
    {
        $response = $this->getJson(route('inscription.pieces-requises', [
            'type' => 'type_inexistant_attaque',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['type']);
    }

    private function creerEtudiant(string $cin, string $dateNaissance): Etudiant
    {
        return Etudiant::create([
            'cin' => $cin,
            'nom' => 'Ben Salah',
            'prenom' => 'Ahmed',
            'email' => "ahmed.{$cin}@universite.tn",
            'telephone' => '+216 20 000 000',
            'date_naissance' => $dateNaissance,
            'lieu_naissance' => 'Tunis',
            'sexe' => 'M',
            'nationalite' => 'Tunisienne',
            'adresse' => 'Avenue Habib Bourguiba',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2024',
            'mention_bac' => 'Bien',
            'annee_academique' => '2025-2026',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'statut' => 'nouveau',
            'type_inscription' => 'premiere',
            'password' => 'Etudiant@123',
        ]);
    }

    private function deposerPieceGed(
        Etudiant $etudiant,
        string $code,
        string $status,
        ?string $motifRejet = null,
        $expiresAt = null,
    ): StudentDocument {
        $dossier = StudentDossier::firstOrCreate(
            ['etudiant_id' => $etudiant->id],
            [
                'reference' => 'DOS-'.strtoupper($etudiant->cin),
                'status' => 'open',
            ]
        );

        return StudentDocument::create([
            'student_dossier_id' => $dossier->id,
            'category' => 'identity',
            'document_type' => $code,
            'title' => ucfirst(str_replace('_', ' ', $code)),
            'original_filename' => "{$code}.pdf",
            'storage_path' => "dossiers/{$dossier->id}/{$code}.pdf",
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 102400,
            'sha256' => hash('sha256', "piece-{$code}"),
            'status' => $status,
            'rejected_reason' => $motifRejet,
            'expires_at' => $expiresAt,
        ]);
    }
}
