<?php

namespace Tests\Feature;

use App\Events\StudentRegistered;
use App\Models\AppNotification;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\StudentDossiers\Contracts\PdfGeneratorInterface;
use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Services\NotificationService;
use App\Services\Verification\CorrectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * LOOPs 11/12/13 — compléments d'expérience d'inscription :
 * 11 : correction appliquée → l'étudiant est informé (sans valeurs PII) ;
 *      décision définitive (rejete) → pas de modification silencieuse
 *      du dossier par l'étudiant, réouverture = procédure officielle.
 * 12 : « dépôt reçu » notifié à la soumission ; déduplication idempotente
 *      par clé (event rejoué ≠ notification dupliquée).
 * 13 : échec de génération du récépissé communiqué proprement (jamais
 *      500), dépôt intact, régénération contrôlée possible.
 */
class ComplementsInscriptionLoopTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private User $agentA;

    private User $agentB;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.student-dossiers.active' => true]);
        Storage::fake('private');

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->agentA = User::creerCompteBackOffice([
            'name' => 'Agent A', 'email' => 'a.cpt@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->agentB = User::creerCompteBackOffice([
            'name' => 'Agent B', 'email' => 'b.cpt@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    private function creerEtudiant(string $cin = 'CIN-CPT1'): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Nom', 'prenom' => 'Prenom',
            'email' => 'etu'.uniqid().'@test.ma', 'date_naissance' => '2004-01-01',
            'sexe' => 'M', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'rue ancienne', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('en_attente');

        return $etudiant->fresh();
    }

    // ── LOOP 12 : dépôt reçu ────────────────────────────────────────────────

    public function test_depot_recu_notifie_etudiant(): void
    {
        $etudiant = $this->creerEtudiant();

        StudentRegistered::dispatch($etudiant);

        $notification = AppNotification::where('etudiant_id', $etudiant->id)
            ->where('type', 'dossier.depot')->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('bien reçu', $notification->message);
        $this->assertSame($etudiant->num_inscription, $notification->data['reference'] ?? null);
    }

    public function test_depot_recu_idempotent_event_rejoue(): void
    {
        $etudiant = $this->creerEtudiant();

        StudentRegistered::dispatch($etudiant);
        StudentRegistered::dispatch($etudiant->fresh());

        $this->assertSame(1, AppNotification::where('etudiant_id', $etudiant->id)
            ->where('type', 'dossier.depot')->count());
    }

    public function test_deduplication_par_cle_une_par_event(): void
    {
        $etudiant = $this->creerEtudiant();
        $service = app(NotificationService::class);

        $premiere = $service->notifierEtudiant($etudiant, 'test.dedup', 'T', 'M', null, [], 0, 'evt:1');
        $seconde = $service->notifierEtudiant($etudiant, 'test.dedup', 'T', 'M', null, [], 0, 'evt:1');
        $service->notifierEtudiant($etudiant, 'test.dedup', 'T2', 'M2', null, [], 0, 'evt:2');

        $this->assertSame($premiere->id, $seconde->id); // même instance retournée
        $this->assertSame(2, AppNotification::where('etudiant_id', $etudiant->id)
            ->where('type', 'test.dedup')->count()); // clés distinctes = notifs distinctes
    }

    // ── LOOP 11 : correction appliquée + décision définitive ────────────────

    public function test_correction_appliquee_notifie_etudiant_sans_valeurs(): void
    {
        $etudiant = $this->creerEtudiant();
        $service = app(CorrectionService::class);

        $correction = $service->proposer(
            $etudiant, 'adresse', '12 nouvelle avenue', 'Adresse confirmée par téléphone.', 'R12', $this->agentA->id,
        );
        $service->approuver($correction, $this->agentB->id);
        $service->appliquer($correction, $this->agentB->id);

        $this->assertSame('12 nouvelle avenue', $etudiant->fresh()->adresse);

        $notification = AppNotification::where('etudiant_id', $etudiant->id)
            ->where('type', 'dossier.correction')->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('Adresse', $notification->message);
        // Jamais les valeurs (PII) dans la notification.
        $this->assertStringNotContainsString('12 nouvelle avenue', $notification->message);
        $this->assertStringNotContainsString('rue ancienne', $notification->message);
    }

    public function test_upload_refuse_apres_decision_definitive_puis_reouverture(): void
    {
        $etudiant = $this->creerEtudiant('CIN-DEF1');
        $etudiant->definirStatut('rejete');

        $refus = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->post(route('etudiant.dossier.documents.store'), [
                'category' => 'identity',
                'document_type' => 'cin',
                'title' => 'CIN',
                'document' => UploadedFile::fake()->createWithContent('cin.pdf', '%PDF-1.4 contenu'),
            ]);

        $refus->assertSessionHasErrors('document');
        $this->assertSame(0, StudentDocument::count()); // rien de créé

        // Procédure officielle : la scolarité rouvre le dossier.
        $etudiant->definirStatut('en_attente');

        $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->post(route('etudiant.dossier.documents.store'), [
                'category' => 'identity',
                'document_type' => 'cin',
                'title' => 'CIN',
                'document' => UploadedFile::fake()->createWithContent('cin.pdf', '%PDF-1.4 contenu'),
            ])
            ->assertRedirect(route('etudiant.dossier.show'));

        $this->assertSame(1, StudentDocument::count()); // réouverture → dépôt autorisé
    }

    public function test_remplacement_refuse_apres_decision_definitive(): void
    {
        $etudiant = $this->creerEtudiant('CIN-DEF2');

        // Pièce déposée pendant que le dossier était examinable.
        $depot = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->post(route('etudiant.dossier.documents.store'), [
                'category' => 'identity',
                'document_type' => 'cin',
                'title' => 'CIN',
                'document' => UploadedFile::fake()->createWithContent('cin.pdf', '%PDF-1.4 contenu'),
            ]);
        $depot->assertRedirect(route('etudiant.dossier.show'));

        $document = StudentDocument::firstOrFail();
        $etudiant->definirStatut('rejete');

        $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->post(route('etudiant.dossier.documents.replace', $document), [
                'document' => UploadedFile::fake()->createWithContent('cin-v2.pdf', '%PDF-1.4 corrige'),
            ])
            ->assertSessionHasErrors('document');

        $this->assertSame(1, $document->versions()->count()); // v1 seule : pas de remplacement
    }

    // ── LOOP 13 : échec de génération + régénération contrôlée ─────────────

    public function test_echec_generation_recepisse_communique_sans_500(): void
    {
        $etudiant = $this->creerEtudiant('CIN-PDF1');

        $this->mock(PdfGeneratorInterface::class)
            ->shouldReceive('generer')
            ->andThrow(new \RuntimeException('Dompdf indisponible'));

        $reponse = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.recepisse'));

        $reponse->assertRedirect(route('etudiant.profil.show'));
        $reponse->assertSessionHasErrors('recepisse');

        // Le dépôt n'est JAMAIS perdu sur un échec de PDF.
        $this->assertDatabaseHas('etudiants', ['id' => $etudiant->id, 'num_inscription' => $etudiant->num_inscription]);
    }

    public function test_echec_puis_regeneration_reussie(): void
    {
        $etudiant = $this->creerEtudiant('CIN-PDF2');

        $reel = app(PdfGeneratorInterface::class);

        $this->swap(PdfGeneratorInterface::class, $this->mock(
            PdfGeneratorInterface::class,
            fn ($mock) => $mock->shouldReceive('generer')->andThrow(new \RuntimeException('panne temporaire')),
        ));

        $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.recepisse'))
            ->assertRedirect(route('etudiant.profil.show'));

        // Régénération contrôlée : même route, moteur rétabli → PDF complet.
        $this->swap(PdfGeneratorInterface::class, $reel);

        $reponse = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.recepisse'));

        $reponse->assertOk();
        $this->assertStringContainsString('%PDF', $reponse->getContent());
    }
}
