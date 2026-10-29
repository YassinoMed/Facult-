<?php

namespace Tests\Feature\Security;

use App\Models\AnneeUniversitaire;
use App\Models\AppNotification;
use App\Models\DocumentAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Attendance\Models\Presence;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Finances\Models\DossierPaiement;
use App\Modules\Finances\Models\RecuPaiement;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\OnlineRequests\Models\OnlineRequestDocument;
use App\Modules\OnlineRequests\Models\RequestType;
use App\Modules\ParcoursAcademique\Models\ParcoursSimulation;
use App\Modules\Schedule\Models\Room;
use App\Modules\StudentDossiers\Models\GeneratedDocument;
use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Modules\StudentDossiers\Models\StudentDocumentVersion;
use App\Modules\StudentDossiers\Models\StudentDossier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * LOOP-02 — Autorisation serveur et anti-IDOR :
 * - Empêcher qu'un étudiant A puisse lire, modifier, annuler ou télécharger les données de B.
 * - Validation de la matrice anti-IDOR sur tous les périmètres :
 *   dossier, documents GED, archives, examens/convocations, démarches en ligne,
 *   rendez-vous, notifications, paiements/reçus, simulations et assiduité.
 * - Vérification du rejet 404 (sans fuite d'information) et de l'étanchéité serveur.
 */
class Loop02AutorisationAntiIdorTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private Etudiant $etudiantA;

    private Etudiant $etudiantB;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.student-dossiers.active' => true,
            'modules.online-requests.active' => true,
            'modules.finances.active' => true,
            'modules.attendance.active' => true,
            'modules.parcours-academique.active' => true,
            'inscriptions-paiements.actif' => true,
        ]);

        Storage::fake('local');
        Storage::fake('private');

        $this->faculteA = Faculte::create(['nom' => 'Faculté A', 'code' => 'FA', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'Faculté B', 'code' => 'FB', 'active' => true]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026',
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30',
            'active' => true,
        ]);

        $this->etudiantA = $this->creerEtudiant('BK111111', 'Mehdi', 'mehdi.a@example.com', $this->faculteA);
        $this->etudiantB = $this->creerEtudiant('BK222222', 'Sami', 'sami.b@example.com', $this->faculteB);
    }

    private function creerEtudiant(string $cin, string $prenom, string $email, Faculte $faculte): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => 'Test',
            'prenom' => $prenom,
            'email' => $email,
            'telephone' => '+212600000000',
            'date_naissance' => '2004-01-01',
            'sexe' => 'M',
            'faculte_id' => $faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Rue Test',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'password' => 'Motdepasse@123',
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-2026-'.$cin])->save();
        $etudiant->definirStatut('valide');

        return $etudiant;
    }

    private function connecterEtudiant(Etudiant $etudiant): void
    {
        session([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_auth_prenom' => $etudiant->prenom,
            'etudiant_auth_nom' => $etudiant->nom,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ]);
    }

    /* ── 1. Documents GED et Dossier Étudiant ────────────────────────── */

    public function test_etudiant_a_ne_peut_pas_acceder_aux_documents_ged_de_b(): void
    {
        $dossierB = StudentDossier::create([
            'etudiant_id' => $this->etudiantB->id,
            'reference' => 'DOS-B-001',
            'status' => 'complete',
        ]);

        Storage::disk('private')->put('student-dossiers/doc-b.pdf', '%PDF-1.4 secret B');

        $documentB = StudentDocument::create([
            'student_dossier_id' => $dossierB->id,
            'category' => 'identity',
            'document_type' => 'cin',
            'title' => 'CIN de B',
            'original_filename' => 'cin_b.pdf',
            'storage_path' => 'student-dossiers/doc-b.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 100,
            'sha256' => hash('sha256', 'doc-b'),
            'status' => 'validated',
        ]);

        $versionB = StudentDocumentVersion::create([
            'student_document_id' => $documentB->id,
            'version' => 1,
            'storage_path' => 'student-dossiers/doc-b.pdf',
            'original_filename' => 'cin_b.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 100,
            'sha256' => hash('sha256', 'doc-b'),
        ]);

        $this->connecterEtudiant($this->etudiantA);

        // A consulte son dossier : 200, sans trace du document de B
        $this->get(route('etudiant.dossier.show'))
            ->assertOk()
            ->assertDontSee('CIN de B');

        // A tente de télécharger le document de B : 404
        $this->get(route('etudiant.dossier.documents.download', $documentB))
            ->assertNotFound();

        // A tente de prévisualiser le document de B : 404
        $this->get(route('etudiant.dossier.documents.preview', $documentB))
            ->assertNotFound();

        // A tente de télécharger la version du document de B : 404
        $this->get(route('etudiant.dossier.documents.versions.download', [$documentB, $versionB]))
            ->assertNotFound();

        // A tente de remplacer le document de B : 404
        $fichier = UploadedFile::fake()->createWithContent('nouveau.pdf', '%PDF-1.4 malveillant');
        $this->post(route('etudiant.dossier.documents.replace', $documentB), [
            'document' => $fichier,
        ])->assertNotFound();

        // Le document de B et son historique de versions sont intacts.
        $this->assertSame('cin_b.pdf', $documentB->fresh()->original_filename);
        $this->assertSame(1, $documentB->fresh()->versions()->count());
    }

    public function test_etudiant_a_ne_peut_pas_telecharger_document_officiel_de_b(): void
    {
        $dossierB = StudentDossier::create([
            'etudiant_id' => $this->etudiantB->id,
            'reference' => 'DOS-B-OFFICIEL',
            'status' => 'complete',
        ]);

        Storage::disk('private')->put('officiels/attestation_b.pdf', '%PDF-1.4 attestation B');

        $docOfficielB = GeneratedDocument::create([
            'student_dossier_id' => $dossierB->id,
            'type' => 'attestation_scolarite',
            'reference' => 'ATT-2026-B',
            'storage_path' => 'officiels/attestation_b.pdf',
            'statut' => 'valide',
            'sha256' => hash('sha256', 'att-b'),
            'contenu_hash' => hash('sha256', 'att-b'),
            'snapshot_donnees' => ['nom' => 'B'],
        ]);

        $this->connecterEtudiant($this->etudiantA);

        $this->get(route('etudiant.dossier.officiels.download', $docOfficielB))
            ->assertNotFound();
    }

    /* ── 2. Archives académiques & Documents ─────────────────────────── */

    public function test_etudiant_a_ne_peut_pas_telecharger_archive_de_b(): void
    {
        Storage::disk('local')->put('archives/releve_b.pdf', '%PDF-1.4 releve B');

        $docAcademiqueB = DocumentAcademique::create([
            'etudiant_id' => $this->etudiantB->id,
            'annee_universitaire_id' => $this->annee->id,
            'type_document' => 'certificat',
            'nom_original' => 'certificat_b.pdf',
            'chemin' => 'archives/releve_b.pdf',
            'mime_type' => 'application/pdf',
            'taille' => 100,
            'hash_fichier' => hash('sha256', 'certificat-b'),
        ]);

        $this->connecterEtudiant($this->etudiantA);

        $this->get(route('etudiant.archives.documents.download', $docAcademiqueB))
            ->assertNotFound();
    }

    /* ── 3. Examens et Convocations ──────────────────────────────────── */

    public function test_etudiant_a_ne_peut_pas_acceder_a_la_convocation_de_b(): void
    {
        $session = SessionExamen::create([
            'nom' => 'Session Principale 2026',
            'code' => 'SESS-2026-01',
            'annee_universitaire_id' => $this->annee->id,
            'type' => 'principale',
            'statut' => 'publiee',
            'date_debut' => now()->toDateString(),
            'date_fin' => now()->addDays(10)->toDateString(),
        ]);

        $matiere = Matiere::create([
            'code' => 'ALGO1',
            'nom' => 'Algorithmique',
            'coefficient' => 2,
            'credits' => 6,
            'niveau' => 'L1',
            'semestre' => 1,
        ]);

        $examen = Examen::create([
            'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examens S1 Info',
            'type' => 'examen',
            'statut' => 'scheduled',
        ]);

        $salle = Room::create(['code' => 'AMPHI-A', 'capacite' => 100]);

        $epreuve = Epreuve::create([
            'session_id' => $session->id,
            'examen_id' => $examen->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $matiere->id,
            'date' => now()->addDays(2)->toDateString(),
            'heure_debut' => '08:30',
            'heure_fin' => '10:30',
            'statut' => 'published',
            'salle_id' => $salle->id,
        ]);

        $candidatB = Candidat::create([
            'epreuve_id' => $epreuve->id,
            'etudiant_id' => $this->etudiantB->id,
            'num_table' => 42,
            'anonymat' => 'ANO-B-99',
            'statut' => 'inscrit',
        ]);

        $this->connecterEtudiant($this->etudiantA);

        // A tente d'accéder à la convocation du candidat B : 404
        $this->get(route('etudiant.examens.convocation', $candidatB))
            ->assertNotFound();
    }

    /* ── 4. Démarches en ligne et Rendez-vous ────────────────────────── */

    public function test_etudiant_a_ne_peut_ni_consulter_ni_modifier_demande_de_b(): void
    {
        $type = RequestType::firstOrCreate([
            'code' => 'DEMANDE_TEST_IDOR',
        ], [
            'name' => 'Demande Test',
            'active' => true,
            'fields' => [],
        ]);

        $demandeB = OnlineRequest::create([
            'objet' => 'Demande confidentielle B',
            'reference' => 'REQ-2026-B',
            'request_type_id' => $type->id,
            'etudiant_id' => $this->etudiantB->id,
            'status' => 'draft',
            'form_data' => ['objet' => 'Confidentiel B'],
        ]);

        // Le document existe réellement : sans contrôle d'appartenance,
        // la route le streamerait (200) au lieu de répondre 404.
        Storage::disk('private')->put('demandes/document-b.pdf', '%PDF-1.4 document secret B');

        OnlineRequestDocument::create([
            'reference' => 'DOC-2026-B',
            'online_request_id' => $demandeB->id,
            'type' => 'attestation',
            'version' => 1,
            'storage_path' => 'demandes/document-b.pdf',
            'sha256' => hash('sha256', 'document-b'),
        ]);

        $this->connecterEtudiant($this->etudiantA);

        // A tente de consulter la demande de B : 404
        $this->get(route('etudiant.demandes.show', $demandeB))->assertNotFound();

        // A tente de soumettre la demande de B : 404
        $this->post(route('etudiant.demandes.submit', $demandeB))->assertNotFound();

        // A tente d'annuler la demande de B : 404
        $this->post(route('etudiant.demandes.cancel', $demandeB))->assertNotFound();

        // A tente d'ajouter un message sur le fil de B : 404
        $this->post(route('etudiant.demandes.messages.repondre', $demandeB), [
            'message' => 'Tentative injection message',
        ])->assertNotFound();

        // A tente d'uploader une pièce sur la demande de B : 404
        $fichier = UploadedFile::fake()->createWithContent('piece.pdf', '%PDF-1.4 evil');
        $this->post(route('etudiant.demandes.pieces.store', $demandeB), [
            'piece' => $fichier,
        ])->assertNotFound();

        // A tente de télécharger le document de B : 404
        $this->get(route('etudiant.demandes.document.download', $demandeB))->assertNotFound();

        // Aucune écriture croisée : statut, messages et pièces de B intacts.
        $this->assertSame('draft', $demandeB->fresh()->status);
        $this->assertDatabaseCount('request_messages', 0);
        $this->assertDatabaseCount('request_attachments', 0);
    }

    public function test_etudiant_a_ne_peut_pas_annuler_ni_deplacer_rendez_vous_de_b(): void
    {
        $type = RequestType::firstOrCreate([
            'code' => 'DEMANDE_LIBRE',
        ], [
            'name' => 'Demande Libre',
            'active' => true,
            'fields' => [],
        ]);

        $rdvB = OnlineRequest::create([
            'objet' => 'Rendez-vous scolarité B',
            'reference' => 'RDV-2026-B',
            'request_type_id' => $type->id,
            'etudiant_id' => $this->etudiantB->id,
            'form_data' => [
                'type' => 'rendez_vous_scolarite',
                'date_creneau' => now()->addDays(3)->format('Y-m-d'),
                'heure_creneau' => '09:00',
            ],
        ]);
        $rdvB->forceFill(['status' => 'submitted'])->save();

        $this->connecterEtudiant($this->etudiantA);

        // A tente d'annuler le rendez-vous de B : 404
        $this->post(route('etudiant.rendez-vous.annuler', $rdvB))
            ->assertNotFound();
        $this->assertSame('submitted', $rdvB->fresh()->status);

        // A tente de déplacer le rendez-vous de B : 404
        $this->post(route('etudiant.rendez-vous.deplacer', $rdvB), [
            'nouvelle_date' => now()->addDays(4)->format('Y-m-d'),
            'nouvelle_heure' => '10:00',
        ])->assertNotFound();

        // Le rendez-vous de B n'a été ni annulé ni déplacé.
        $rdvB->refresh();
        $this->assertSame('submitted', $rdvB->status);
        $this->assertSame('09:00', $rdvB->form_data['heure_creneau']);
    }

    /* ── 5. Notifications ────────────────────────────────────────────── */

    public function test_etudiant_a_ne_peut_pas_marquer_comme_lue_notification_de_b(): void
    {
        $notifB = AppNotification::create([
            'etudiant_id' => $this->etudiantB->id,
            'type' => 'info',
            'titre' => 'Notification personnelle B',
            'message' => 'Contenu secret pour B',
            'lu' => false,
        ]);

        $this->connecterEtudiant($this->etudiantA);

        // A tente de marquer comme lue la notification de B : 404
        $this->patch(route('etudiant.notifications.lire', $notifB))
            ->assertNotFound();

        $this->assertFalse((bool) $notifB->fresh()->lu);
    }

    /* ── 6. Assiduité et Absences ────────────────────────────────────── */

    public function test_etudiant_a_ne_peut_pas_justifier_presence_de_b(): void
    {
        $admin = User::creerCompteBackOffice([
            'name' => 'Agent Scolarité Test',
            'email' => 'agent.sco@universite.tn',
            'password' => 'Pass@12345',
        ], 'admin_scolarite');

        $presenceB = Presence::create([
            'etudiant_id' => $this->etudiantB->id,
            'date' => now()->toDateString(),
            'seance' => 'S1',
            'statut' => 'absent',
            'enregistre_par' => $admin->id,
        ]);

        $this->connecterEtudiant($this->etudiantA);

        // A tente de déposer une justification sur l'absence de B : 404
        $this->post(route('etudiant.absences.justifier', $presenceB), [
            'motif' => 'Tentative falsification justification',
        ])->assertNotFound();

        // Aucune justification n'a été créée pour B.
        $this->assertDatabaseCount('attendance_justifications', 0);
    }

    /* ── 7. Finances et Dossiers de Paiement ──────────────────────────── */

    public function test_etudiant_a_ne_peut_pas_acceder_aux_paiements_de_b(): void
    {
        $inscriptionB = InscriptionAcademique::create([
            'etudiant_id' => $this->etudiantB->id,
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1',
            'filiere' => 'Sciences',
            'statut' => 'en_cours',
        ]);

        $dossierPaiementB = DossierPaiement::firstOrCreate(
            ['inscription_academique_id' => $inscriptionB->id],
            [
                'statut' => DossierPaiement::NON_PAYE,
                'montant_attendu' => 500,
                'montant_paye' => 0,
            ]
        );

        Storage::disk('private')->put('recus/recu_b.pdf', '%PDF-1.4 recu secret');

        $recuB = RecuPaiement::create([
            'dossier_paiement_id' => $dossierPaiementB->id,
            'inscription_academique_id' => $inscriptionB->id,
            'annee_universitaire_id' => $this->annee->id,
            'version' => 1,
            'storage_path' => 'recus/recu_b.pdf',
            'original_filename' => 'recu.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 100,
            'sha256' => hash('sha256', 'recu-b'),
            'montant_declare_centimes' => 50000,
            'reference_paiement' => 'VIR-2026-B',
            'date_paiement' => now()->toDateString(),
            'depose_le' => now(),
            'statut' => RecuPaiement::RECU_DEPOSE,
        ]);

        $this->connecterEtudiant($this->etudiantA);

        // A tente de consulter le détail du paiement de B : 404
        $this->get(route('etudiant.paiements.inscription', $inscriptionB))
            ->assertNotFound();

        // A tente d'uploader un reçu sur l'inscription de B : 404
        $fichier = UploadedFile::fake()->createWithContent('recu.pdf', '%PDF-1.4 test');
        $this->post(route('etudiant.paiements.recus.store', $inscriptionB), [
            'recu' => $fichier,
            'mode_paiement' => 'virement',
        ])->assertNotFound();

        // A tente de télécharger le reçu de B : 404
        $this->get(route('etudiant.paiements.recus.telecharger', [$inscriptionB, $recuB]))
            ->assertNotFound();

        // A tente de prévisualiser le reçu de B : 404
        $this->get(route('etudiant.paiements.recus.apercu', [$inscriptionB, $recuB]))
            ->assertNotFound();

        // Aucun reçu supplémentaire n'a été créé pour B.
        $this->assertSame(1, $dossierPaiementB->recus()->count());
    }

    /* ── 8. Simulations de Parcours LMD ──────────────────────────────── */

    public function test_etudiant_a_ne_peut_pas_acceder_aux_simulations_de_b(): void
    {
        $simulationB = ParcoursSimulation::create([
            'etudiant_id' => $this->etudiantB->id,
            'scenario' => 'compensation_semestre',
            'donnees' => ['moyenne' => 12],
            'resultat' => ['eligible' => true],
            'auteur_id' => null, // self-service
        ]);

        $this->connecterEtudiant($this->etudiantA);

        // A tente d'afficher la simulation de B : 404
        $this->get(route('etudiant.parcours.simulations.show', $simulationB))
            ->assertNotFound();
    }

    /* ── 9. Falsification d'identifiants dans les formulaires POST ─────── */

    public function test_falsification_etudiant_id_dans_formulaire_post_est_sans_effet(): void
    {
        $this->connecterEtudiant($this->etudiantA);

        // A tente de falsifier l'identifiant pour modifier B via profil.update
        $this->patch(route('etudiant.profil.update'), [
            'id' => $this->etudiantB->id,
            'etudiant_id' => $this->etudiantB->id,
            'email' => $this->etudiantA->email,
            'telephone' => '+212699999999',
            'adresse' => 'Adresse Modifiee A',
            'ville' => 'Rabat',
            'code_postal' => '10000',
        ])->assertRedirect(route('etudiant.profil.show'));

        // Seul le profil de A a été modifié, B reste intact
        $this->assertSame('+212699999999', $this->etudiantA->fresh()->telephone);
        $this->assertSame('+212600000000', $this->etudiantB->fresh()->telephone);
    }
}
