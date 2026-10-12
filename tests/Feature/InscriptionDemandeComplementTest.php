<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\DossierCorrection;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\StudentDossiers\Models\StudentDossier;
use App\Modules\StudentDossiers\Services\StudentDocumentService;
use App\Services\DossierComplementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Suite de tests pour la LOOP 11 — Demande de complément et correction du dossier :
 * - Demande de complément autorisée uniquement pour les rôles habilités.
 * - Étudiant strictement limité à son propre dossier (anti-IDOR).
 * - Modification des seules sections permises par l'état du dossier.
 * - Revalidation systématique des éléments corrigés et ré-analyse du dossier.
 * - Conservation des versions antérieures de documents et audit trail.
 * - Interdiction formelle de modification silencieuse post-décision définitive.
 * - Notifications idempotentes et liens sécurisés.
 */
class InscriptionDemandeComplementTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private User $scolarite;

    private User $agentNonHabilite;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.student-dossiers.active' => true]);
        Storage::fake('private');

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences et Techniques',
            'code' => 'FST',
            'active' => true,
        ]);

        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Agent Scolarité',
            'email' => 'scolarite.fst@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculte->id,
        ], 'admin_scolarite');

        $this->agentNonHabilite = User::create([
            'name' => 'Agent Simple',
            'email' => 'simple@universite.test',
            'password' => bcrypt('Motdepasse@123'),
            'role' => 'auditeur_externe',
        ]);
    }

    private function creerEtudiant(string $cin = 'CIN12345', string $statut = 'en_attente'): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => 'Alami',
            'prenom' => 'Karim',
            'email' => 'karim.'.uniqid().'@test.ma',
            'telephone' => '+212600000000',
            'date_naissance' => '2004-05-15',
            'sexe' => 'M',
            'adresse' => '10 Rue de la Paix',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2023',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'password' => 'Secret@1234',
        ]);

        $etudiant->definirStatut($statut);

        // Initialiser son dossier GED
        StudentDossier::create([
            'etudiant_id' => $etudiant->id,
            'reference' => 'DOS-'.$etudiant->id,
            'status' => 'open',
        ]);

        return $etudiant->fresh();
    }

    // ── 1. Demande de complément autorisée uniquement pour rôles habilités ──────

    public function test_demande_complement_autorisee_pour_scolarite_et_refusee_pour_non_habilite(): void
    {
        $etudiant = $this->creerEtudiant('CIN-HAB1');

        // Non habilité → 403 Forbidden
        $reponseRefus = $this->actingAs($this->agentNonHabilite)
            ->postJson(route('admin.verification.demande-complement', $etudiant), [
                'champs' => [
                    ['champ' => 'telephone', 'motif' => 'Numéro erroné'],
                ],
            ]);

        $reponseRefus->assertForbidden();

        // Agent scolarité habilité → 200 OK
        $reponseSucces = $this->actingAs($this->scolarite)
            ->postJson(route('admin.verification.demande-complement', $etudiant), [
                'champs' => [
                    ['champ' => 'telephone', 'motif' => 'Numéro erroné, veuillez corriger'],
                ],
                'message' => 'Merci de vérifier votre numéro de contact.',
            ]);

        $reponseSucces->assertOk();
        $reponseSucces->assertJsonPath('success', true);

        // Vérifier la création de la proposition
        $this->assertDatabaseHas('dossier_corrections', [
            'etudiant_id' => $etudiant->id,
            'champ' => 'telephone',
            'statut' => 'proposee',
            'propose_par' => $this->scolarite->id,
        ]);

        // Notification envoyée à l'étudiant
        $notif = AppNotification::where('etudiant_id', $etudiant->id)
            ->where('type', 'dossier.complement_requis')
            ->first();

        $this->assertNotNull($notif);
        $this->assertStringContainsString('complément', $notif->message);
        $this->assertSame(route('etudiant.corrections.index'), $notif->lien);
    }

    // ── 2. Étudiant limité à son propre dossier (anti-IDOR) ─────────────────────

    public function test_etudiant_consulte_exclusivement_son_propre_dossier(): void
    {
        $etudiantA = $this->creerEtudiant('CIN-IDOR-A');
        $etudiantB = $this->creerEtudiant('CIN-IDOR-B');

        // Créer une demande sur l'étudiant A
        DossierCorrection::create([
            'etudiant_id' => $etudiantA->id,
            'champ' => 'adresse',
            'ancienne_valeur' => $etudiantA->adresse,
            'motif' => 'Préciser le numéro de rue',
            'statut' => 'proposee',
            'propose_par' => $this->scolarite->id,
        ]);

        // Étudiant B se connecte : il ne doit JAMAIS voir la demande de l'étudiant A
        $reponseB = $this->withSession(['etudiant_auth_id' => $etudiantB->id])
            ->get(route('etudiant.corrections.index'));

        $reponseB->assertOk();
        $reponseB->assertSee('Aucun complément requis pour le moment');
        $reponseB->assertDontSee('Préciser le numéro de rue');

        // Étudiant A se connecte : il voit sa demande
        $reponseA = $this->withSession(['etudiant_auth_id' => $etudiantA->id])
            ->get(route('etudiant.corrections.index'));

        $reponseA->assertOk();
        $reponseA->assertSee('Préciser le numéro de rue');
        $reponseA->assertSee('Adresse postale');
    }

    // ── 3. Seules les sections demandées sont modifiables ───────────────────────

    public function test_etudiant_ne_peut_modifier_que_les_champs_expressément_demandes(): void
    {
        $etudiant = $this->creerEtudiant('CIN-SECT1');

        // La scolarité ne demande QUE le téléphone
        DossierCorrection::create([
            'etudiant_id' => $etudiant->id,
            'champ' => 'telephone',
            'ancienne_valeur' => $etudiant->telephone,
            'motif' => 'Numéro manquant d’indicatif',
            'statut' => 'proposee',
            'propose_par' => $this->scolarite->id,
        ]);

        // L'étudiant essaie de changer son prénom ET son téléphone
        $reponse = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->post(route('etudiant.corrections.store'), [
                'champs' => [
                    'telephone' => '+212611223344',
                    'prenom' => 'HackerName', // Champ NON demandé
                ],
                'attestation_honneur' => '1',
            ]);

        $reponse->assertRedirect(route('etudiant.suivi'));

        $etudiantFresh = $etudiant->fresh();
        // Téléphone mis à jour
        $this->assertSame('+212611223344', $etudiantFresh->telephone);
        // Prénom INCHANGÉ (protection contre la modification non autorisée)
        $this->assertSame('Karim', $etudiantFresh->prenom);

        // Proposition marquée comme appliquee
        $this->assertDatabaseHas('dossier_corrections', [
            'etudiant_id' => $etudiant->id,
            'champ' => 'telephone',
            'statut' => 'appliquee',
            'nouvelle_valeur' => '+212611223344',
        ]);
    }

    // ── 4. Revalidation stricte et ré-analyse par VerificationEngine ───────────

    public function test_revalidation_stricte_des_donnees_soumises_et_reanalyse(): void
    {
        $etudiant = $this->creerEtudiant('CIN-REVAL1');

        DossierCorrection::create([
            'etudiant_id' => $etudiant->id,
            'champ' => 'date_naissance',
            'ancienne_valeur' => $etudiant->date_naissance,
            'motif' => 'Date incohérente',
            'statut' => 'proposee',
            'propose_par' => $this->scolarite->id,
        ]);

        // Soumission d'une date future invalide → rejet par validation
        $reponseInvalide = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->post(route('etudiant.corrections.store'), [
                'champs' => [
                    'date_naissance' => now()->addYear()->format('Y-m-d'),
                ],
                'attestation_honneur' => '1',
            ]);

        $reponseInvalide->assertSessionHasErrors('champs.date_naissance');

        // Soumission d'une date valide dans le passé
        $reponseValide = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->postJson(route('etudiant.corrections.store'), [
                'champs' => [
                    'date_naissance' => '2004-03-20',
                ],
                'attestation_honneur' => '1',
            ]);

        $reponseValide->assertOk();
        $reponseValide->assertJsonPath('success', true);
        $this->assertNotNull($reponseValide->json('resultat.score'));
        $this->assertNotNull($reponseValide->json('resultat.verdict'));

        // Modèle étudiant mis à jour
        $this->assertSame('2004-03-20', $etudiant->fresh()->date_naissance?->format('Y-m-d'));

        // Notification de confirmation envoyée
        $this->assertDatabaseHas('notifications', [
            'etudiant_id' => $etudiant->id,
            'type' => 'dossier.correction_soumise',
        ]);
    }

    // ── 5. Remplacement de document avec versions conservées et audit ─────────

    public function test_remplacement_document_incremente_version_et_conserve_historique(): void
    {
        $etudiant = $this->creerEtudiant('CIN-DOC1');
        $dossier = StudentDossier::where('etudiant_id', $etudiant->id)->firstOrFail();

        // 1. Dépôt initial d'un document
        $fichierV1 = UploadedFile::fake()->createWithContent('bac.pdf', '%PDF-1.4 Version initiale bac');
        $document = app(StudentDocumentService::class)->upload(
            $dossier,
            ['category' => 'academic', 'document_type' => 'bac', 'title' => 'Diplôme du Baccalauréat'],
            $fichierV1,
            null
        );

        $this->assertSame(1, $document->versions()->count());

        // 2. La scolarité rejette le document en demandant un complément
        $service = app(DossierComplementService::class);
        $service->demanderComplement(
            $etudiant,
            $this->scolarite,
            [],
            [['document_id' => $document->id, 'motif' => 'Scan tronqué, fournir le document complet']]
        );

        $documentFresh = $document->fresh();
        $this->assertSame('rejected', $documentFresh->status);
        $this->assertSame('Scan tronqué, fournir le document complet', $documentFresh->rejected_reason);

        // 3. L'étudiant transmet un nouveau fichier en remplacement
        $fichierV2 = UploadedFile::fake()->createWithContent('bac-v2.pdf', '%PDF-1.4 Version complete bac');
        $reponse = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->post(route('etudiant.corrections.store'), [
                'pieces' => [
                    $document->id => $fichierV2,
                ],
                'attestation_honneur' => '1',
            ]);

        $reponse->assertRedirect(route('etudiant.suivi'));

        // 4. Vérifications : 2 versions enregistrées, document repassé en pending
        $documentApres = $document->fresh();
        $this->assertSame('pending', $documentApres->status);
        $this->assertNull($documentApres->rejected_reason);
        $this->assertSame(2, $documentApres->versions()->count());

        // La version 1 et la version 2 sont bien préservées en base
        $this->assertDatabaseHas('student_document_versions', [
            'student_document_id' => $document->id,
            'version' => 1,
            'original_filename' => 'bac.pdf',
        ]);
        $this->assertDatabaseHas('student_document_versions', [
            'student_document_id' => $document->id,
            'version' => 2,
            'original_filename' => 'bac-v2.pdf',
        ]);
    }

    // ── 6. Interdiction de modification silencieuse post-décision définitive ───

    public function test_modification_strictement_interdite_apres_decision_definitive(): void
    {
        $etudiantValide = $this->creerEtudiant('CIN-VAL1', 'valide');
        $etudiantRejete = $this->creerEtudiant('CIN-REJ1', 'rejete');

        // Créer une correction orpheline théorique
        DossierCorrection::create([
            'etudiant_id' => $etudiantValide->id,
            'champ' => 'adresse',
            'ancienne_valeur' => 'Ancienne adresse',
            'motif' => 'Correction',
            'statut' => 'proposee',
            'propose_par' => $this->scolarite->id,
        ]);

        // Tentative de soumission sur dossier validé → refus 422 avec message explicite
        $reponseValide = $this->withSession(['etudiant_auth_id' => $etudiantValide->id])
            ->postJson(route('etudiant.corrections.store'), [
                'champs' => ['adresse' => 'Nouvelle adresse'],
                'attestation_honneur' => '1',
            ]);

        $reponseValide->assertStatus(422);
        $this->assertStringContainsString('décision définitive', $reponseValide->json('message'));

        // Tentative de soumission sur dossier rejeté → refus 422 avec message explicite
        $reponseRejete = $this->withSession(['etudiant_auth_id' => $etudiantRejete->id])
            ->postJson(route('etudiant.corrections.store'), [
                'champs' => ['adresse' => 'Nouvelle adresse'],
                'attestation_honneur' => '1',
            ]);

        $reponseRejete->assertStatus(422);
        $this->assertStringContainsString('décision définitive', $reponseRejete->json('message'));
    }

    // ── 7. Idempotence des notifications de demande de complément ──────────────

    public function test_notification_demande_complement_est_idempotente(): void
    {
        $etudiant = $this->creerEtudiant('CIN-NOTIF1');
        $service = app(DossierComplementService::class);

        // Deux demandes rapprochées dans la même heure pour le même étudiant
        $service->demanderComplement($etudiant, $this->scolarite, [
            ['champ' => 'email', 'motif' => 'Email non universitaire'],
        ]);

        $service->demanderComplement($etudiant, $this->scolarite, [
            ['champ' => 'email', 'motif' => 'Email non universitaire relancé'],
        ]);

        // Une seule notification doit être générée grâce à la déduplication
        $count = AppNotification::where('etudiant_id', $etudiant->id)
            ->where('type', 'dossier.complement_requis')
            ->count();

        $this->assertSame(1, $count);
    }
}
