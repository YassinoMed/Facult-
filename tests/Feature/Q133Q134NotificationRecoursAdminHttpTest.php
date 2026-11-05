<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP 146 / Phases Q133 et Q134 — Traitement administratif du recours officiel de remise.
 * - Q133 / STAT-004 : La décision officielle met à jour le statut de remise selon ses conclusions.
 * - Q134 / BUS-004 : Les dossiers associés sont recensés sans aucune mutation automatique.
 */
class Q133Q134NotificationRecoursAdminHttpTest extends TestCase
{
    use RefreshDatabase;

    private NotificationService $service;

    private User $admin;

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

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Scolarite',
            'email' => 'admin.scol@universite.ac.ma',
            'password' => 'Admin@123',
            'telephone' => '0600000001',
            'faculte_id' => $faculte->id,
            'actif' => true,
        ], 'super_admin');

        $this->etudiant = Etudiant::create([
            'cin' => '81460021',
            'nom' => 'Chraibi',
            'prenom' => 'Mehdi',
            'email' => 'mehdi.chraibi@universite.ac.ma',
            'date_naissance' => '2004-03-20',
            'sexe' => 'M',
            'faculte_id' => $faculte->id,
            'niveau' => 'L2',
            'annee_academique' => '2026-2027',
            'adresse' => 'Rabat',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2023',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');

        $this->notification = $this->service->notifierEtudiant(
            $this->etudiant,
            'acte_scolarite',
            'Convocation examen',
            'Votre convocation officielle.'
        );
        $this->service->refuserPreuveRemise($this->notification, 'Signature absente', 1);
    }

    public function test_q133_q134_admin_applique_recours_confirmant_la_remise(): void
    {
        $reponse = $this->actingAs($this->admin)->post(
            route('admin.notifications.recours', $this->notification),
            [
                'issue' => 'remise_confirmee',
                'reference_recours' => 'DEC-RECOURS-2026-09',
                'dossiers_associes' => ['DOSSIER-BOURSE-2026', 'DOSSIER-LOGEMENT-2026'],
            ]
        );

        $reponse->assertRedirect();
        $reponse->assertSessionHas('success');

        $actualisee = $this->notification->refresh();
        $this->assertSame('remise_confirmee_apres_recours', $actualisee->data['statut_remise']);
        $this->assertTrue($actualisee->data['remise_confirmee']);
        $this->assertSame('DEC-RECOURS-2026-09', $actualisee->data['recours_reference']);
        $this->assertTrue($actualisee->data['recours_applique']);
        $this->assertFalse($actualisee->data['en_contestation']);
    }

    public function test_q133_admin_applique_recours_maintenant_non_confirme(): void
    {
        $reponse = $this->actingAs($this->admin)->post(
            route('admin.notifications.recours', $this->notification),
            [
                'issue' => 'remise_non_confirmee',
                'reference_recours' => 'DEC-RECOURS-2026-10',
            ]
        );

        $reponse->assertRedirect();
        $reponse->assertSessionHas('success');

        $actualisee = $this->notification->refresh();
        $this->assertSame('remise_non_confirmee_apres_recours', $actualisee->data['statut_remise']);
        $this->assertFalse($actualisee->data['remise_confirmee']);
        $this->assertSame('DEC-RECOURS-2026-10', $actualisee->data['recours_reference']);
    }

    public function test_q133_admin_sans_permission_de_gestion_ne_peut_pas_appliquer_le_recours(): void
    {
        $lecteur = User::creerCompteBackOffice([
            'name' => 'Agent Consultation',
            'email' => 'consultation.recours@universite.ac.ma',
            'password' => 'Admin@123',
            'telephone' => '0600000004',
            'faculte_id' => $this->etudiant->faculte_id,
            'actif' => true,
        ], 'consultation');

        $reponse = $this->actingAs($lecteur)->post(
            route('admin.notifications.recours', $this->notification),
            [
                'issue' => 'remise_confirmee',
                'reference_recours' => 'DEC-RECOURS-2026-11',
            ]
        );

        $reponse->assertForbidden();

        $actualisee = $this->notification->refresh();
        $this->assertArrayNotHasKey('recours_applique', $actualisee->data ?? []);
    }

    public function test_q133_utilisateur_non_authentifie_est_redirige(): void
    {
        $reponse = $this->post(
            route('admin.notifications.recours', $this->notification),
            [
                'issue' => 'remise_confirmee',
                'reference_recours' => 'DEC-2026',
            ]
        );

        $reponse->assertRedirect(route('admin.connexion.show'));
    }
}
