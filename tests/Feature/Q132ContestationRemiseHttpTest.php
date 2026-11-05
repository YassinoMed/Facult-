<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class Q132ContestationRemiseHttpTest extends TestCase
{
    use RefreshDatabase;

    private NotificationService $service;

    private Etudiant $etudiant;

    private AppNotification $notification;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(NotificationService::class);

        $faculte = Faculte::create([
            'code' => 'FST',
            'nom' => 'Faculté des Sciences et Techniques',
        ]);

        $this->etudiant = Etudiant::create([
            'cin' => '61460011',
            'nom' => 'Idrissi',
            'prenom' => 'Salma',
            'email' => 'salma.idrissi@universite.ac.ma',
            'date_naissance' => '2004-02-10',
            'sexe' => 'F',
            'faculte_id' => $faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Rabat Agdal',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');

        $this->notification = $this->service->notifierEtudiant(
            $this->etudiant,
            'acte_scolarite',
            'Refus de preuve',
            'Votre preuve de remise a été refusée.'
        );
        $this->service->refuserPreuveRemise($this->notification, 'Signature illisible', 1);

        $this->withSession([
            'etudiant_auth_id' => $this->etudiant->id,
            'etudiant_mdp_version' => $this->etudiant->mot_de_passe_version,
        ]);
    }

    public function test_q132_http_proprietaire_conteste_sans_changer_le_statut(): void
    {
        $reponse = $this->post(route('etudiant.notifications.contester', $this->notification), [
            'motif_contestation' => 'Je conteste le refus : la preuve a bien été remise.',
        ]);

        $reponse->assertRedirect(route('etudiant.notifications.index'));
        $reponse->assertSessionHas('success');

        $actualisee = $this->notification->refresh();
        $this->assertSame('refusee', $actualisee->data['statut_remise']);
        $this->assertTrue($actualisee->data['en_contestation']);
        $this->assertCount(1, $actualisee->data['contestations']);
    }

    public function test_q132_http_autre_etudiant_recoit_404_sans_mutation(): void
    {
        $autre = Etudiant::create([
            'cin' => '61460012',
            'nom' => 'Autre',
            'prenom' => 'Etudiant',
            'email' => 'autre.etudiant@universite.ac.ma',
            'date_naissance' => '2004-03-11',
            'sexe' => 'M',
            'faculte_id' => $this->etudiant->faculte_id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Rabat',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);

        $reponse = $this->withSession([
            'etudiant_auth_id' => $autre->id,
            'etudiant_mdp_version' => $autre->mot_de_passe_version,
        ])->post(route('etudiant.notifications.contester', $this->notification), [
            'motif_contestation' => 'Tentative d’accès au dossier d’un autre étudiant.',
        ]);

        $reponse->assertNotFound();
        $this->assertNull($this->notification->refresh()->data['contestations'] ?? null);
        $this->assertSame('refusee', $this->notification->refresh()->data['statut_remise']);
    }

    public function test_q132_http_invite_redirige_vers_la_connexion(): void
    {
        $this->flushSession();

        $reponse = $this->post(route('etudiant.notifications.contester', $this->notification), [
            'motif_contestation' => 'Tentative sans session étudiante valide.',
        ]);

        $reponse->assertRedirect(route('etudiant.connexion.show'));
        $this->assertSame('refusee', $this->notification->refresh()->data['statut_remise']);
    }

    public function test_q132_http_motif_invalide_rejete_sans_mutation(): void
    {
        $reponse = $this->from(route('etudiant.notifications.index'))
            ->post(route('etudiant.notifications.contester', $this->notification), [
                'motif_contestation' => 'court',
            ]);

        $reponse->assertRedirect(route('etudiant.notifications.index'));
        $reponse->assertSessionHasErrors('motif_contestation');
        $this->assertNull($this->notification->refresh()->data['contestations'] ?? null);
    }

    public function test_q132_http_notification_non_refusee_rejetee(): void
    {
        $autreNotification = $this->service->notifierEtudiant(
            $this->etudiant,
            'acte_scolarite',
            'Notification simple',
            'Notification sans refus de preuve.'
        );

        $reponse = $this->from(route('etudiant.notifications.index'))
            ->post(route('etudiant.notifications.contester', $autreNotification), [
                'motif_contestation' => 'Contestation sans refus de preuve préalable.',
            ]);

        $reponse->assertRedirect(route('etudiant.notifications.index'));
        $reponse->assertSessionHasErrors('motif_contestation');
        $this->assertNull($autreNotification->refresh()->data['contestations'] ?? null);
    }

    public function test_q132_http_double_soumission_idempotente(): void
    {
        $donnees = ['motif_contestation' => 'Je conteste le refus de ma preuve de remise.'];

        $this->post(route('etudiant.notifications.contester', $this->notification), $donnees);
        $this->post(route('etudiant.notifications.contester', $this->notification), $donnees);

        $actualisee = $this->notification->refresh();
        $this->assertCount(1, $actualisee->data['contestations']);
        $this->assertSame('refusee', $actualisee->data['statut_remise']);
    }

    public function test_q132_http_piece_justificative_hash_conserve(): void
    {
        $fichier = UploadedFile::fake()->create('preuve.pdf', 20, 'application/pdf');
        $hashAttendu = hash_file('sha256', $fichier->getRealPath());

        $reponse = $this->post(route('etudiant.notifications.contester', $this->notification), [
            'motif_contestation' => 'Je joins la preuve de remise contestée.',
            'piece_contestation' => $fichier,
        ]);

        $reponse->assertRedirect(route('etudiant.notifications.index'));
        $reponse->assertSessionHasNoErrors();

        $actualisee = $this->notification->refresh();
        $this->assertSame($hashAttendu, $actualisee->data['piece_contestation_hash']);
        $this->assertSame('refusee', $actualisee->data['statut_remise']);
    }
}
