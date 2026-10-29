<?php

namespace Tests\Feature;

use App\Jobs\VerifyStudentDossierJob;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Régression Lot 4 (missions LOOP-UNIREGISTRE-ADMIN-INSCRIPTIONS-ADVANCED) :
 * action en masse « vérification des dossiers » sur admin/etudiants/verification/bulk.
 *
 * Exigence sécurité : le serveur revalide chaque ID (exists:etudiants,id),
 * impose le périmètre facultaire serveur (admin_faculte restreint) et
 * limite le volume (max:500) — jamais la seule confiance navigateur.
 */
class InscriptionsBulkVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte1;

    private Faculte $faculte2;

    private User $superAdmin;

    private User $adminFaculte1;

    private User $conseiller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte1 = Faculte::create([
            'code' => 'FST',
            'nom' => 'Faculté des Sciences de Tunis',
            'active' => true,
        ]);
        $this->faculte2 = Faculte::create([
            'code' => 'FSEGS',
            'nom' => 'Faculté de Sfax',
            'active' => true,
        ]);

        $this->superAdmin = User::creerCompteBackOffice([
            'name' => 'Super Administrateur',
            'email' => 'super.bulk@uniregistre.test',
            'password' => 'Secret@123',
        ], 'super_admin');

        $this->adminFaculte1 = User::creerCompteBackOffice([
            'name' => 'Doyen FST',
            'email' => 'doyen.bulk@fst.test',
            'password' => 'Secret@123',
            'faculte_id' => $this->faculte1->id,
        ], 'admin_faculte');

        $this->conseiller = User::creerCompteBackOffice([
            'name' => 'Auditeur Consultation',
            'email' => 'auditeur.bulk@uniregistre.test',
            'password' => 'Secret@123',
        ], 'consultation');
    }

    private function creerEtudiant(Faculte $faculte, int $n): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => str_pad((string) $n, 8, '0', STR_PAD_LEFT),
            'nom' => "Famille{$n}",
            'prenom' => 'Test',
            'email' => "bulk{$n}@{$faculte->code}.test",
            'date_naissance' => '2004-06-15',
            'sexe' => 'M',
            'faculte_id' => $faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Campus',
            'ville' => 'Tunis',
            'code_postal' => '1060',
            'annee_bac' => '2023',
            'password' => 'SecretHash@123',
        ]);
        $etudiant->definirStatut('en_attente');

        return $etudiant;
    }

    /** 1. Non authentifié : redirection vers la connexion. */
    public function test_bulk_non_authentifie_redirige_vers_connexion(): void
    {
        $this->post(route('admin.verification.bulk'), ['etudiant_ids' => [1]])
            ->assertRedirect(route('admin.connexion.show'));
    }

    /** 2. Rôle sans permission : 403 serveur, jamais de file de jobs. */
    public function test_bulk_sans_permission_rejete_en_403(): void
    {
        Queue::fake();

        $this->actingAs($this->conseiller)->post(route('admin.verification.bulk'), [
            'etudiant_ids' => [1, 2, 3],
        ])->assertForbidden();

        Queue::assertNothingPushed();
    }

    /** 3. ID improbable ou corridor forgot : l'endpoint exige un tableau (422). */
    public function test_bulk_rejette_payload_corrompu_et_id_inexistant(): void
    {
        Queue::fake();

        $this->actingAs($this->superAdmin)->post(route('admin.verification.bulk'), [
            'etudiant_ids' => 42,
        ])->assertSessionHasErrors('etudiant_ids');

        $this->actingAs($this->superAdmin)->post(route('admin.verification.bulk'), [
            'etudiant_ids' => [999999],
        ])->assertSessionHasErrors('etudiant_ids.*');

        Queue::assertNothingPushed();
    }

    /** 4. Périmètre facultaire : l'agent restreint ne fait vérifier que sa faculté. */
    public function test_bulk_agent_admin_faculte_1_ne_traitie_que_son_perimetre(): void
    {
        Queue::fake();

        $sienne = $this->creerEtudiant($this->faculte1, 1);
        $autrui = $this->creerEtudiant($this->faculte2, 2);

        $reponse = $this->actingAs($this->adminFaculte1)->post(route('admin.verification.bulk'), [
            'etudiant_ids' => [$sienne->id, $autrui->id],
        ]);

        $reponse->assertRedirect();
        // Seule l'étudiante de sa faculté part en vérification — l'autre est
        // silencieusement exclue (compteur serveur, jamais l'autre).
        Queue::assertPushed(
            VerifyStudentDossierJob::class,
            fn ($job) => $job->etudiantIds === [$sienne->id],
        );
    }

    /** 5. Le super-administrateur traverse les facultés (périmètre global). */
    public function test_bulk_super_admin_traitie_les_deux_facultes(): void
    {
        Queue::fake();

        $e1 = $this->creerEtudiant($this->faculte1, 3);
        $e2 = $this->creerEtudiant($this->faculte2, 4);

        $reponse = $this->actingAs($this->superAdmin)->post(route('admin.verification.bulk'), [
            'etudiant_ids' => [$e1->id, $e2->id],
        ]);

        $reponse->assertRedirect();
        Queue::assertPushed(
            VerifyStudentDossierJob::class,
            fn ($job) => $job->etudiantIds === [$e1->id, $e2->id],
        );
    }

    /** 6. Découpage en tranches de 50 pour la file d'attente (Lot 4). */
    public function test_bulk_decoupe_en_tranches_de_50(): void
    {
        Queue::fake();

        $ids = [];
        foreach (range(1, 120) as $n) {
            $ids[] = $this->creerEtudiant($this->faculte1, $n)->id;
        }

        $this->actingAs($this->superAdmin)->post(route('admin.verification.bulk'), [
            'etudiant_ids' => $ids,
        ])->assertRedirect();

        $tranches = Queue::pushed(VerifyStudentDossierJob::class)->map->etudiantIds->all();
        $this->assertCount(3, $tranches); // 50 + 50 + 20
        $total = 0;
        foreach ($tranches as $tranche) {
            $this->assertLessThanOrEqual(50, count($tranche));
            $total += count($tranche);
        }
        $this->assertSame(120, $total);
    }

    /** 7. Volumétrie protégée : au-delà de 500, la validation rejette. */
    public function test_bulk_limite_volume_max_500(): void
    {
        Queue::fake();

        $etudiants = [];
        foreach (range(1, 501) as $n) {
            $etudiants[] = $this->creerEtudiant($this->faculte1, $n)->id;
        }

        $reponse = $this->actingAs($this->superAdmin)->post(route('admin.verification.bulk'), [
            'etudiant_ids' => $etudiants,
        ])->assertSessionHasErrors('etudiant_ids');

        Queue::assertNothingPushed();
    }
}
