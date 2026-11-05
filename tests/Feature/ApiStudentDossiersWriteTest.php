<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\User;
use App\Modules\StudentDossiers\Models\StudentDossier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsApiClient;
use Tests\TestCase;

/**
 * API v1 — écritures des dossiers administratifs permanents (§16-17).
 *
 * Contrôles : scope `archives:write`, permission RBAC, isolation faculté
 * (anti-BOLA : 404 jamais 403), création idempotente auditée, statut
 * limité à la liste blanche, champs non modifiables, absence d'écriture
 * et d'audit lorsqu'un statut est inchangé, module désactivé = 404.
 */
class ApiStudentDossiersWriteTest extends TestCase
{
    use ActAsApiClient, RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private Etudiant $etudiantAvecDossier;

    private Etudiant $etudiantSansDossier;

    private Etudiant $etudiantHorsPerimetre;

    private StudentDossier $dossierA;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.student-dossiers.active' => true]);

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté des Sciences', 'code' => 'FST', 'description' => 'Test', 'active' => true,
        ]);
        $this->faculteB = Faculte::create([
            'nom' => 'Faculté de Droit', 'code' => 'FDEG', 'description' => 'Test', 'active' => true,
        ]);

        $this->etudiantAvecDossier = $this->creerEtudiant('ECRIDOS001', 'Ndiaye', 'Awa', $this->faculteA);
        $this->etudiantSansDossier = $this->creerEtudiant('ECRIDOS002', 'Sow', 'Ibrahima', $this->faculteA);
        $this->etudiantHorsPerimetre = $this->creerEtudiant('ECRIDOS003', 'Fall', 'Moussa', $this->faculteB);

        $this->dossierA = StudentDossier::create([
            'etudiant_id' => $this->etudiantAvecDossier->id,
            'reference' => 'DOS-ECRI-A',
            'status' => 'open',
        ]);
    }

    public function test_creation_idempotente_et_auditee_une_seule_fois(): void
    {
        [$client, $jeton] = $this->clientApi(['archives:write']);

        $creation = $this->postJsonApi('/api/v1/student-dossiers', [
            'etudiant_id' => $this->etudiantSansDossier->id,
        ], $jeton);

        $creation->assertCreated()
            ->assertJsonPath('meta.created', true)
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.etudiant.id', $this->etudiantSansDossier->id);

        $reference = (string) $creation->json('data.reference');
        $this->assertStringStartsWith('DOS-', $reference);

        $dossier = StudentDossier::where('etudiant_id', $this->etudiantSansDossier->id)->sole();
        $this->assertSame($reference, $dossier->reference);

        $audits = HistoriqueAcademique::where('action', HistoriqueAcademique::ACTION_CREATION_DOSSIER)
            ->where('entite', 'dossier')
            ->where('entite_id', $dossier->id)
            ->get();

        $this->assertCount(1, $audits);
        $this->assertSame('api', $audits->first()->source_systeme);
        $this->assertSame($client->id, $audits->first()->utilisateur_id);
        $this->assertSame($this->etudiantSansDossier->id, $audits->first()->etudiant_id);

        // Rejeu identique : même dossier, aucun doublon, aucun second audit.
        $this->postJsonApi('/api/v1/student-dossiers', [
            'etudiant_id' => $this->etudiantSansDossier->id,
        ], $jeton)
            ->assertOk()
            ->assertJsonPath('meta.created', false)
            ->assertJsonPath('data.id', $dossier->id);

        $this->assertSame(1, StudentDossier::where('etudiant_id', $this->etudiantSansDossier->id)->count());
        $this->assertSame(1, HistoriqueAcademique::where('action', HistoriqueAcademique::ACTION_CREATION_DOSSIER)
            ->where('entite_id', $dossier->id)->count());
    }

    public function test_creation_ignore_les_champs_forges_par_le_client(): void
    {
        [, $jeton] = $this->clientApi(['archives:write']);

        $reponse = $this->postJsonApi('/api/v1/student-dossiers', [
            'etudiant_id' => $this->etudiantSansDossier->id,
            'reference' => 'DOS-FORGEE',
            'status' => 'archived',
        ], $jeton)->assertCreated();

        $this->assertNotSame('DOS-FORGEE', $reponse->json('data.reference'));
        $reponse->assertJsonPath('data.status', 'open');

        $dossier = StudentDossier::where('etudiant_id', $this->etudiantSansDossier->id)->sole();
        $this->assertNotSame('DOS-FORGEE', $dossier->reference);
        $this->assertSame('open', $dossier->status);
    }

    public function test_creation_refusee_sans_permission_metier(): void
    {
        // Le rôle `consultation` ne possède pas student_dossiers.create :
        // le scope seul ne doit jamais suffire.
        [$client, $jeton] = $this->clientApiRole('consultation', ['archives:write']);

        $this->postJsonApi('/api/v1/student-dossiers', [
            'etudiant_id' => $this->etudiantSansDossier->id,
        ], $jeton)
            ->assertForbidden();

        $this->assertFalse(StudentDossier::where('etudiant_id', $this->etudiantSansDossier->id)->exists());
        $this->assertSame(0, HistoriqueAcademique::where('utilisateur_id', $client->id)->count());
    }

    public function test_creation_hors_perimetre_et_etudiant_inconnu_rep_404(): void
    {
        [, $jeton] = $this->clientApiRole('admin_faculte', ['archives:write'], $this->faculteA);

        $horsPerimetre = $this->postJsonApi('/api/v1/student-dossiers', [
            'etudiant_id' => $this->etudiantHorsPerimetre->id,
        ], $jeton);

        $inconnu = $this->postJsonApi('/api/v1/student-dossiers', [
            'etudiant_id' => (int) Etudiant::max('id') + 1000,
        ], $jeton);

        // Même statut et même code : aucune confirmation d'existence.
        $horsPerimetre->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        $inconnu->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        $this->assertSame($horsPerimetre->json('message'), $inconnu->json('message'));

        $this->assertFalse(StudentDossier::where('etudiant_id', $this->etudiantHorsPerimetre->id)->exists());
    }

    public function test_mise_a_jour_du_statut_avec_audit(): void
    {
        [$client, $jeton] = $this->clientApi(['archives:write']);

        $this->patchJsonApi('/api/v1/student-dossiers/'.$this->dossierA->id, [
            'status' => 'complete',
        ], $jeton)
            ->assertOk()
            ->assertJsonPath('data.id', $this->dossierA->id)
            ->assertJsonPath('data.status', 'complete');

        $this->assertSame('complete', $this->dossierA->fresh()->status);

        $audit = HistoriqueAcademique::where('action', HistoriqueAcademique::ACTION_MODIFICATION_DOSSIER)
            ->where('entite', 'dossier')
            ->where('entite_id', $this->dossierA->id)
            ->sole();

        $this->assertSame('api', $audit->source_systeme);
        $this->assertSame($client->id, $audit->utilisateur_id);
        $this->assertSame(['status' => 'open'], json_decode((string) $audit->ancienne_valeur, true));
        $this->assertSame(['status' => 'complete'], json_decode((string) $audit->nouvelle_valeur, true));
    }

    public function test_mise_a_jour_statut_inchange_sans_ecriture_ni_audit(): void
    {
        [, $jeton] = $this->clientApi(['archives:write']);

        $this->dossierA->forceFill(['updated_at' => now()->subDay()])->saveQuietly();
        $avant = $this->dossierA->fresh()->updated_at;

        $this->patchJsonApi('/api/v1/student-dossiers/'.$this->dossierA->id, [
            'status' => 'open',
        ], $jeton)
            ->assertOk()
            ->assertJsonPath('data.status', 'open');

        $apres = $this->dossierA->fresh();
        $this->assertSame('open', $apres->status);
        $this->assertTrue($avant->equalTo($apres->updated_at));
        $this->assertSame(0, HistoriqueAcademique::where('action', HistoriqueAcademique::ACTION_MODIFICATION_DOSSIER)
            ->where('entite_id', $this->dossierA->id)->count());
    }

    public function test_mise_a_jour_refusee_sans_permission_metier(): void
    {
        [, $jeton] = $this->clientApiRole('consultation', ['archives:write']);

        $this->patchJsonApi('/api/v1/student-dossiers/'.$this->dossierA->id, [
            'status' => 'archived',
        ], $jeton)
            ->assertForbidden();

        $this->assertSame('open', $this->dossierA->fresh()->status);
        $this->assertSame(0, HistoriqueAcademique::where('action', HistoriqueAcademique::ACTION_MODIFICATION_DOSSIER)
            ->where('entite_id', $this->dossierA->id)->count());
    }

    public function test_mise_a_jour_hors_perimetre_rep_404_avant_permission(): void
    {
        [, $jeton] = $this->clientApiRole('admin_faculte', ['archives:write'], $this->faculteA);

        $this->patchJsonApi('/api/v1/student-dossiers/'.$this->dossierA->id, [
            'status' => 'archived',
        ], $jeton)->assertOk();

        $dossierB = StudentDossier::create([
            'etudiant_id' => $this->etudiantHorsPerimetre->id,
            'reference' => 'DOS-ECRI-B',
            'status' => 'open',
        ]);

        $this->resetAuthGuards();

        $this->patchJsonApi('/api/v1/student-dossiers/'.$dossierB->id, [
            'status' => 'archived',
        ], $jeton)->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');

        $this->assertSame('open', $dossierB->fresh()->status);
    }

    public function test_statut_valide_et_champs_non_modifiables(): void
    {
        [, $jeton] = $this->clientApi(['archives:write']);

        $this->patchJsonApi('/api/v1/student-dossiers/'.$this->dossierA->id, [
            'status' => 'invalide',
        ], $jeton)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['status']);

        // `status` est obligatoire pour PATCH : aucun autre champ ne peut
        // servir de prétexte à une écriture.
        $this->patchJsonApi('/api/v1/student-dossiers/'.$this->dossierA->id, [
            'etudiant_id' => $this->etudiantHorsPerimetre->id,
            'reference' => 'DOS-PIRATE',
        ], $jeton)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->patchJsonApi('/api/v1/student-dossiers/'.$this->dossierA->id, [
            'status' => 'complete',
            'etudiant_id' => $this->etudiantHorsPerimetre->id,
            'reference' => 'DOS-PIRATE',
        ], $jeton)
            ->assertOk()
            ->assertJsonPath('data.status', 'complete')
            ->assertJsonPath('data.reference', 'DOS-ECRI-A')
            ->assertJsonPath('data.etudiant.id', $this->etudiantAvecDossier->id);

        $dossier = $this->dossierA->fresh();
        $this->assertSame($this->etudiantAvecDossier->id, $dossier->etudiant_id);
        $this->assertSame('DOS-ECRI-A', $dossier->reference);
    }

    public function test_scope_read_ne_permet_pas_les_ecritures(): void
    {
        [, $jetonLecture] = $this->clientApi(['archives:read']);

        $this->postJsonApi('/api/v1/student-dossiers', [
            'etudiant_id' => $this->etudiantSansDossier->id,
        ], $jetonLecture)->assertForbidden();

        $this->patchJsonApi('/api/v1/student-dossiers/'.$this->dossierA->id, [
            'status' => 'archived',
        ], $jetonLecture)->assertForbidden();

        $this->assertFalse(StudentDossier::where('etudiant_id', $this->etudiantSansDossier->id)->exists());
        $this->assertSame('open', $this->dossierA->fresh()->status);
    }

    public function test_module_desactive_repond_404_meme_avec_le_scope_write(): void
    {
        [, $jeton] = $this->clientApi(['archives:write']);

        config(['modules.student-dossiers.active' => false]);

        $this->postJsonApi('/api/v1/student-dossiers', [
            'etudiant_id' => $this->etudiantSansDossier->id,
        ], $jeton)->assertNotFound();

        $this->patchJsonApi('/api/v1/student-dossiers/'.$this->dossierA->id, [
            'status' => 'archived',
        ], $jeton)->assertNotFound();

        $this->assertFalse(StudentDossier::where('etudiant_id', $this->etudiantSansDossier->id)->exists());
        $this->assertSame('open', $this->dossierA->fresh()->status);
    }

    public function test_authentification_obligatoire(): void
    {
        $this->postJson('/api/v1/student-dossiers', [
            'etudiant_id' => $this->etudiantSansDossier->id,
        ])->assertUnauthorized();

        $this->patchJson('/api/v1/student-dossiers/'.$this->dossierA->id, [
            'status' => 'archived',
        ])->assertUnauthorized();
    }

    /**
     * Crée un jeton pour un rôle et un périmètre faculté donnés.
     *
     * @param  array<int, string>  $scopes
     */
    private function clientApiRole(string $role, array $scopes, ?Faculte $faculte = null): array
    {
        $client = User::creerCompteBackOffice([
            'name' => 'Client API '.$role,
            'email' => uniqid('api-'.$role.'-', true).'@integration.systeme',
            'password' => 'Motdepasse@123',
            'faculte_id' => $faculte?->id,
        ], $role);

        return [$client, $client->createToken('integration-test', $scopes)->plainTextToken];
    }

    private function creerEtudiant(string $cin, string $nom, string $prenom, Faculte $faculte): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => $nom,
            'prenom' => $prenom,
            'email' => strtolower($cin).'@example.com',
            'telephone' => '+212600000000',
            'date_naissance' => '2004-03-15',
            'sexe' => 'F',
            'faculte_id' => $faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '12 Rue de la Liberté',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'mention_bac' => 'Bien',
            'password' => 'motdepasse1',
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-2026-'.$cin])->save();

        return $etudiant->fresh();
    }
}
