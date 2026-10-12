<?php

namespace Tests\Feature;

use App\Support\Permissions;
use Tests\TestCase;

/**
 * LOOP 43 — cohérence RBAC : aucune permission déclarée ne doit
 * rester sans contrôleur consommateur (`can:`/`hasPermission`/Gate).
 * Cas traité : `attendance.update`, jamais branchée (l'upsert passe
 * par `store` sous `attendance.create`).
 */
class PermissionsCoherenceTest extends TestCase
{
    public function test_aucune_permission_attendance_inoperante(): void
    {
        $code = file_get_contents(app_path('Support/Permissions.php'));
        $this->assertStringNotContainsString("'attendance.update'", $code);

        foreach (['super_admin', 'admin_scolarite', 'agent_scolarite', 'admin_faculte', 'consultation'] as $role) {
            $this->assertNotContains('attendance.update', Permissions::pourRole($role));
        }
    }

    public function test_upsert_presence_couvert_par_create(): void
    {
        $controleur = file_get_contents(app_path('Modules/Attendance/Controllers/PresenceController.php'));
        $policy = file_get_contents(app_path('Modules/Attendance/Policies/PresencePolicy.php'));

        // Le seul chemin d'écriture (création + mise à jour via upsert)
        // exige attendance.create + périmètre : aucune zone aveugle.
        $this->assertStringContainsString("authorize('create'", $controleur);
        $this->assertStringContainsString("'attendance.create'", $policy);
    }
}
