<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Centre de notifications — non-régression des deux défauts corrigés.
 *
 * D-04 : la vue partagée `notifications/index.blade.php` ne distinguait
 * que `admin.*` et « tout le reste », ce qui envoyait un enseignant vers
 * la route étudiante (404). Les tests existants appelaient la route
 * enseignant directement : ils ne traversaient jamais l'interface.
 *
 * D-07 : aucune entrée « Notifications » n'était rendue dans la
 * navigation du portail étudiant, alors que la route existait.
 */
class CentreNotificationsPortailTest extends TestCase
{
    use RefreshDatabase;

    private Etudiant $etudiant;

    private Teacher $enseignant;

    protected function setUp(): void
    {
        parent::setUp();

        $faculte = Faculte::create([
            'nom' => 'FST', 'code' => 'FST', 'description' => 'Test', 'active' => true,
        ]);

        $this->etudiant = Etudiant::create([
            'cin' => '60000001', 'nom' => 'Notifications', 'prenom' => 'Etudiant',
            'email' => 'notifications.etudiant@test', 'date_naissance' => '2004-01-01',
            'sexe' => 'F', 'faculte_id' => $faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');

        $this->enseignant = Teacher::create([
            'nom' => 'Ben Salah', 'prenom' => 'Karim',
            'email' => 'notifications.enseignant@test', 'password' => 'Enseignant@123',
            'actif' => true, 'statut' => 'actif',
        ]);
    }

    public function test_la_page_notifications_enseignant_marque_via_la_route_enseignant(): void
    {
        $notification = AppNotification::create([
            'teacher_id' => $this->enseignant->id,
            'type' => 'info',
            'titre' => 'Rappel de saisie',
            'message' => 'Les notes du semestre 1 sont attendues.',
        ]);

        $reponse = $this->withSession(['enseignant_auth_id' => $this->enseignant->id])
            ->get(route('enseignant.notifications.index'));

        $reponse->assertOk();
        $reponse->assertSee(route('enseignant.notifications.lire', $notification), false);
        $reponse->assertDontSee(route('etudiant.notifications.lire', $notification), false);
    }

    public function test_la_navigation_etudiante_expose_le_centre_de_notifications(): void
    {
        $reponse = $this->withSession(['etudiant_auth_id' => $this->etudiant->id])
            ->get(route('etudiant.dashboard'));

        $reponse->assertOk();
        $reponse->assertSee(route('etudiant.notifications.index'), false);
    }
}
