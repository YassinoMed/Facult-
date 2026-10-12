<?php

namespace Tests\Feature\Security;

use App\Models\AnneeUniversitaire;
use App\Models\DossierAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * Sécurité autorisation/RBAC (§7-9, §51) : chaque rôle ne voit que son
 * périmètre ; les référentiels globaux sont réservés aux rôles supérieurs.
 */
class AuthorizationSecurityTest extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    private function adminFaculte(Faculte $faculte): User
    {
        $user = User::creerCompteBackOffice([
            'name' => 'Admin Faculté',
            'email' => uniqid('fac.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $faculte->id,
        ], 'admin_faculte');

        $this->actingAs($user);

        return $user;
    }

    private function dossierPour(Faculte $faculte): DossierAcademique
    {
        $etudiant = Etudiant::create([
            'cin' => 'BK'.random_int(100000, 999999),
            'nom' => 'Dossier', 'prenom' => 'Test',
            'email' => uniqid('d.', true).'@example.com',
            'sexe' => 'M', 'faculte_id' => $faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2025-2026',
            'adresse' => '1 rue', 'ville' => 'Rabat',
            'code_postal' => '10000', 'annee_bac' => '2024',
            'password' => 'motdepasse1',
        ]);

        return DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-'.strtoupper(substr(md5(uniqid('', true)), 0, 8)),
            'date_archivage' => now()->toDateString(),
        ]);
    }

    public function test_facultes_reservees_aux_roles_superieurs(): void
    {
        $faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);

        // admin_faculte : 403 même en lecture (référentiel global).
        $this->adminFaculte($faculte);
        $this->get(route('admin.facultes.index'))->assertForbidden();
        $this->post(route('admin.facultes.store'), ['nom' => 'X', 'code' => 'X'])
            ->assertForbidden();

        // consultation : 403.
        $this->scolarite('consultation');
        $this->get(route('admin.facultes.index'))->assertForbidden();

        // admin_scolarite : autorisé.
        $this->scolarite('admin_scolarite');
        $this->get(route('admin.facultes.index'))->assertOk();
    }

    public function test_formulaires_creation_modification_exigent_manage(): void
    {
        $this->scolarite('consultation');

        $this->get(route('admin.enseignants.create'))->assertForbidden();
        $this->get(route('admin.salles.create'))->assertForbidden();
        $this->get(route('admin.groupes.create'))->assertForbidden();
    }

    public function test_admin_faculte_bloque_hors_perimetre_en_ecriture(): void
    {
        $faculteA = Faculte::create(['nom' => 'FA', 'code' => 'FA', 'description' => 'T', 'active' => true]);
        $faculteB = Faculte::create(['nom' => 'FB', 'code' => 'FB', 'description' => 'T', 'active' => true]);
        $dossierB = $this->dossierPour($faculteB);

        $this->adminFaculte($faculteA);

        // Lecture + écriture cross-faculté refusées (payloads valides :
        // c'est bien l'autorisation qui bloque, pas la validation).
        $this->get(route('admin.archives.show', $dossierB))->assertForbidden();
        $this->patch(route('admin.archives.update', $dossierB), [
            'statut' => 'actif', 'observation' => 'pirate',
        ])->assertForbidden();
        $annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30', 'active' => true,
        ]);
        $this->post(route('admin.archives.inscriptions.store', $dossierB), [
            'niveau' => 'L1', 'filiere' => 'Info',
            'annee_universitaire_id' => $annee->id,
        ])->assertForbidden();
        $this->post(route('admin.archives.store'), ['etudiant_id' => $dossierB->etudiant_id])
            ->assertForbidden();
    }

    public function test_suppression_etudiant_exige_archives_delete(): void
    {
        $faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $dossier = $this->dossierPour($faculte);

        // agent_scolarite (pas de archives.delete) : 403.
        $this->scolarite('agent_scolarite');
        $this->delete(route('admin.etudiants.destroy', $dossier->etudiant))
            ->assertForbidden();
    }

    public function test_dernier_super_admin_actif_protege(): void
    {
        $superAdmin = $this->scolarite('super_admin');

        // Se désactiver soi-même : déjà interdit ; désactiver l'unique
        // super-admin restant : interdit aussi.
        $this->patch(route('admin.administrateurs.statut', $superAdmin))
            ->assertForbidden();

        // Avec un second super-admin actif, la désactivation passe.
        $second = User::creerCompteBackOffice([
            'name' => 'Second', 'email' => uniqid('s2.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], 'super_admin');

        $this->patch(route('admin.administrateurs.statut', $second))->assertRedirect();
        $this->assertFalse($second->fresh()->actif);
    }

    public function test_audit_scope_par_faculte(): void
    {
        $faculteA = Faculte::create(['nom' => 'FA', 'code' => 'FA', 'description' => 'T', 'active' => true]);
        $faculteB = Faculte::create(['nom' => 'FB', 'code' => 'FB', 'description' => 'T', 'active' => true]);
        $this->dossierPour($faculteA);
        $dossierB = $this->dossierPour($faculteB);

        $this->adminFaculte($faculteA);

        $reponse = $this->get(route('admin.audit.index'))->assertOk();
        $entrees = $reponse->viewData('entrees');

        // Aucune entrée liée à un étudiant hors périmètre ne doit fuiter.
        foreach ($entrees as $entree) {
            if ($entree->etudiant !== null) {
                $this->assertSame($faculteA->id, (int) $entree->etudiant->faculte_id);
            }
        }

        $this->assertNotNull($dossierB);
    }
}
