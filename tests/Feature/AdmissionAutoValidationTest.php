<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Services\AdmissionService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * LOOP-ADMISSIONS-AUTO-VALIDATION-01 : couverture des exigences LOOP 06
 * non couvertes par AdmissionTest (qui garde les cas nominaux).
 */
class AdmissionAutoValidationTest extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    private Faculte $faculteA;

    private AnneeUniversitaire $anneeSource;

    private AnneeUniversitaire $anneeCible;

    private Programme $licence;

    private Programme $master;

    private Niveau $l3;

    private Niveau $m1;

    private User $scolarite;

    private string $fichierAudit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fichierAudit = storage_path('logs/audit-admissions-test.log');
        @unlink($this->fichierAudit);
        config()->set('logging.channels.audit_test', [
            'driver' => 'single', 'path' => $this->fichierAudit, 'level' => 'debug',
        ]);
        config()->set('logging.default', 'audit_test');

        $this->faculteA = Faculte::create(['nom' => 'FA', 'code' => 'FA', 'description' => 'T', 'active' => true]);
        $this->anneeSource = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->anneeCible = AnneeUniversitaire::create([
            'libelle' => '2027-2028', 'date_debut' => '2027-09-01', 'date_fin' => '2028-06-30', 'active' => false,
        ]);
        $this->licence = Programme::create([
            'faculte_id' => $this->faculteA->id, 'code' => 'LINFO', 'nom' => 'Licence Informatique',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $this->l3 = Niveau::create(['programme_id' => $this->licence->id, 'code' => 'L3', 'nom' => 'Licence 3', 'ordre' => 3]);
        $this->master = Programme::create([
            'faculte_id' => $this->faculteA->id, 'code' => 'MINFO', 'nom' => 'Master Informatique',
            'duree_annees' => 2, 'semestres_nombre' => 4, 'credits_requis' => 120,
        ]);
        $this->m1 = Niveau::create(['programme_id' => $this->master->id, 'code' => 'M1', 'nom' => 'Master 1', 'ordre' => 1]);
        $this->scolarite = $this->scolarite('admin_scolarite');
    }

    protected function tearDown(): void
    {
        // Le throttle partage le cache array du processus PHPUnit : purger
        // pour ne polluer ni subir les autres fichiers (clés user id 1).
        Cache::flush();

        parent::tearDown();
    }

    private function etudiant(): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => 'BK'.random_int(100000, 999999),
            'nom' => 'Candidat', 'prenom' => 'Admission',
            'email' => uniqid('adm.', true).'@example.com',
            'sexe' => 'M', 'faculte_id' => $this->faculteA->id,
            'niveau' => 'L3', 'annee_academique' => '2026-2027',
            'adresse' => '1 rue', 'ville' => 'Sfax',
            'code_postal' => '3000', 'annee_bac' => '2021',
            'password' => 'motdepasse1',
        ]);
        InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $this->anneeSource->id,
            'programme_id' => $this->licence->id, 'niveau_id' => $this->l3->id,
            'niveau' => 'L3', 'filiere' => 'Info', 'statut' => 'valide',
        ]);

        return $etudiant;
    }

    private function payload(Etudiant $etudiant, array $surcharge = []): array
    {
        return array_merge([
            'etudiant_id' => $etudiant->id, 'programme_id' => $this->master->id,
            'niveau_id' => $this->m1->id, 'annee_universitaire_id' => $this->anneeCible->id,
            'decision' => 'admis', 'motif' => 'Commission',
        ], $surcharge);
    }

    private function contenuAudit(): string
    {
        return is_file($this->fichierAudit) ? (string) file_get_contents($this->fichierAudit) : '';
    }

    /* ── A1 : refus antérieur ne bloque pas une décision ultérieure ── */

    public function test_refus_puis_admis_consommable(): void
    {
        $etudiant = $this->etudiant();
        $service = app(AdmissionService::class);

        $service->accorder($etudiant, $this->master, $this->m1, $this->anneeCible, 'refuse', 'Incomplet', (int) $this->scolarite->id);
        $admis = $service->accorder($etudiant, $this->master, $this->m1, $this->anneeCible, 'admis', 'Recours', (int) $this->scolarite->id);

        $this->assertTrue($admis->estActive());
        $this->assertSame(2, Admission::where('etudiant_id', $etudiant->id)->count());
        $this->assertDatabaseHas('historique_academique', ['etudiant_id' => $etudiant->id, 'action' => 'admission_refusee']);
        $this->assertDatabaseHas('historique_academique', ['etudiant_id' => $etudiant->id, 'action' => 'admission_accordee']);
    }

    public function test_admis_bloque_toujours_un_second_admis(): void
    {
        $etudiant = $this->etudiant();
        $service = app(AdmissionService::class);

        $service->accorder($etudiant, $this->master, $this->m1, $this->anneeCible, 'admis', null, (int) $this->scolarite->id);

        $this->expectException(\DomainException::class);
        $service->accorder($etudiant, $this->master, $this->m1, $this->anneeCible, 'admis_conditionnel', null, (int) $this->scolarite->id);
    }

    /* ── Fonctionnels ── */

    public function test_trois_decisions_acceptes_http(): void
    {
        $this->actingAs($this->scolarite);

        foreach (Admission::DECISIONS as $decision) {
            $etudiant = $this->etudiant();
            $this->post(route('admin.admissions.store'), $this->payload($etudiant, ['decision' => $decision]))
                ->assertRedirect()->assertSessionHasNoErrors();
            $this->assertDatabaseHas('admissions', ['etudiant_id' => $etudiant->id, 'decision' => $decision]);
        }
    }

    public function test_creation_ne_cree_pas_d_inscription(): void
    {
        $etudiant = $this->etudiant();
        $this->actingAs($this->scolarite);

        $this->post(route('admin.admissions.store'), $this->payload($etudiant))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, InscriptionAcademique::where('etudiant_id', $etudiant->id)->count());
    }

    public function test_champs_invalides_rejetes(): void
    {
        $etudiant = $this->etudiant();
        $this->actingAs($this->scolarite);

        // Décision hors liste, niveau hors programme, ids inexistants, motif trop long.
        $this->post(route('admin.admissions.store'), $this->payload($etudiant, ['decision' => 'peut_etre']))
            ->assertSessionHasErrors('decision');
        $this->post(route('admin.admissions.store'), $this->payload($etudiant, ['niveau_id' => $this->l3->id]))
            ->assertSessionHasErrors('admission');
        $this->post(route('admin.admissions.store'), $this->payload($etudiant, ['programme_id' => 999999]))
            ->assertSessionHasErrors('programme_id');
        $this->post(route('admin.admissions.store'), $this->payload($etudiant, ['motif' => str_repeat('x', 501)]))
            ->assertSessionHasErrors('motif');

        $this->assertSame(0, Admission::count());
    }

    public function test_pagination_liste(): void
    {
        $service = app(AdmissionService::class);
        for ($i = 0; $i < 21; $i++) {
            $service->accorder($this->etudiant(), $this->master, $this->m1, $this->anneeCible, 'admis', null, (int) $this->scolarite->id);
        }

        $reponse = $this->actingAs($this->scolarite)->get(route('admin.admissions.index', ['page' => 2]))->assertOk();
        $this->assertSame(1, $reponse->viewData('admissions')->count());
    }

    public function test_conditionnel_consommable_et_trace(): void
    {
        [$etudiant] = [$this->etudiant()];
        $source = InscriptionAcademique::where('etudiant_id', $etudiant->id)->firstOrFail();
        app(AdmissionService::class)->accorder($etudiant, $this->master, $this->m1, $this->anneeCible, 'admis_conditionnel', null, (int) $this->scolarite->id);

        $this->actingAs($this->scolarite);
        $this->post(route('admin.inscriptions.progresser', $source), [
            'programme_id' => $this->master->id, 'niveau_id' => $this->m1->id, 'confirmer' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('historique_academique', ['etudiant_id' => $etudiant->id, 'action' => 'admission_consommee']);
        $admission = Admission::where('etudiant_id', $etudiant->id)->firstOrFail();
        $this->assertNotNull($admission->consumed_at);
        $this->assertNotNull($admission->consumed_inscription_id);
    }

    /* ── Sécurité ── */

    public function test_invite_et_sans_droit_refuses(): void
    {
        $etudiant = $this->etudiant();
        auth()->logout(); // Le setUp authentifie via le trait : retour invité réel.

        $this->get(route('admin.admissions.index'))->assertRedirect();
        $this->post(route('admin.admissions.store'), $this->payload($etudiant))->assertRedirect();

        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => uniqid('ag.', true).'@example.com', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');
        $this->actingAs($agent);
        $this->get(route('admin.admissions.index'))->assertForbidden();
        $this->post(route('admin.admissions.store'), $this->payload($etudiant))->assertForbidden();

        $this->assertSame(0, Admission::count());
    }

    public function test_double_soumission_sequentielle(): void
    {
        $etudiant = $this->etudiant();
        $this->actingAs($this->scolarite);

        $this->post(route('admin.admissions.store'), $this->payload($etudiant))->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('admin.admissions.store'), $this->payload($etudiant))->assertSessionHasErrors('admission');

        $this->assertSame(1, Admission::where('etudiant_id', $etudiant->id)->count());
    }

    public function test_throttle_store(): void
    {
        $this->actingAs($this->scolarite);

        for ($i = 0; $i < 20; $i++) {
            $this->post(route('admin.admissions.store'), ['decision' => 'invalide']);
        }
        $this->post(route('admin.admissions.store'), ['decision' => 'invalide'])->assertStatus(429);
    }

    /* ── Intégrité ── */

    public function test_suppression_non_consommee_tracee(): void
    {
        $etudiant = $this->etudiant();
        $admission = app(AdmissionService::class)->accorder($etudiant, $this->master, $this->m1, $this->anneeCible, 'admis', null, (int) $this->scolarite->id);

        $this->actingAs($this->scolarite);
        $this->delete(route('admin.admissions.destroy', $admission))->assertRedirect();

        $this->assertDatabaseMissing('admissions', ['id' => $admission->id]);
        $this->assertDatabaseHas('historique_academique', ['etudiant_id' => $etudiant->id, 'action' => 'admission_supprimee']);
    }

    public function test_consommation_unique_second_appel_echoue(): void
    {
        $etudiant = $this->etudiant();
        $source = InscriptionAcademique::where('etudiant_id', $etudiant->id)->firstOrFail();
        app(AdmissionService::class)->accorder($etudiant, $this->master, $this->m1, $this->anneeCible, 'admis', null, (int) $this->scolarite->id);

        $this->actingAs($this->scolarite);
        // Premier appel : progression créée via l'admission.
        $this->post(route('admin.inscriptions.progresser', $source), [
            'programme_id' => $this->master->id, 'niveau_id' => $this->m1->id, 'confirmer' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $nbInscriptions = InscriptionAcademique::where('etudiant_id', $etudiant->id)->count();

        // Second appel : l'admission est consommée → refus déterministe, zéro inscription ajoutée.
        $this->post(route('admin.inscriptions.progresser', $source), [
            'programme_id' => $this->master->id, 'niveau_id' => $this->m1->id, 'confirmer' => '1',
        ])->assertRedirect();
        $this->assertSame($nbInscriptions, InscriptionAcademique::where('etudiant_id', $etudiant->id)->count());
    }

    /* ── R1 : verrou d'unicité des actifs ── */

    public function test_verrou_pose_actif_nul_refuse_libere_consomme(): void
    {
        $etudiant = $this->etudiant();
        $service = app(AdmissionService::class);

        $refuse = $service->accorder($etudiant, $this->master, $this->m1, $this->anneeCible, 'refuse', null, (int) $this->scolarite->id);
        $this->assertNull($refuse->verrou_actif);

        $admis = $service->accorder($etudiant, $this->master, $this->m1, $this->anneeCible, 'admis', null, (int) $this->scolarite->id);
        $this->assertSame($etudiant->id.'-'.$this->master->id.'-'.$this->anneeCible->id, $admis->verrou_actif);

        // Consommation : verrou libéré dans la même transaction (nouvel acte possible, comportement conservé).
        $source = InscriptionAcademique::where('etudiant_id', $etudiant->id)->firstOrFail();
        $this->actingAs($this->scolarite);
        $this->post(route('admin.inscriptions.progresser', $source), [
            'programme_id' => $this->master->id, 'niveau_id' => $this->m1->id, 'confirmer' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull($admis->fresh()->verrou_actif);
        $nouveau = $service->accorder($etudiant, $this->master, $this->m1, $this->anneeCible, 'admis', null, (int) $this->scolarite->id);
        $this->assertNotNull($nouveau->verrou_actif);
    }

    public function test_contrainte_unique_verrou_niveau_base(): void
    {
        // Preuve DB-level de R1 : deux actifs identiques sont rejetés par la
        // base elle-même (la course simultanée ne peut plus créer de doublon
        // silencieux — le service la convertit en DomainException).
        $etudiant = $this->etudiant();
        $attributs = [
            'etudiant_id' => $etudiant->id, 'programme_id' => $this->master->id,
            'niveau_id' => $this->m1->id, 'annee_universitaire_id' => $this->anneeCible->id,
            'decision' => 'admis', 'decided_by' => $this->scolarite->id, 'decided_at' => now(),
            'verrou_actif' => $etudiant->id.'-'.$this->master->id.'-'.$this->anneeCible->id,
        ];

        Admission::create($attributs);

        $this->expectException(UniqueConstraintViolationException::class);
        Admission::create($attributs);
    }

    /* ── R4 : refus journalisés, validation non journalisée ── */

    public function test_refus_metier_journalise_sans_pii(): void
    {
        $etudiant = $this->etudiant();
        $this->actingAs($this->scolarite);

        $this->post(route('admin.admissions.store'), $this->payload($etudiant))->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('admin.admissions.store'), $this->payload($etudiant))->assertSessionHasErrors('admission');

        $journal = $this->contenuAudit();
        $this->assertStringContainsString('admission_operation_refusee', $journal);
        $this->assertStringContainsString('Une admission active existe déjà', $journal);
        $this->assertStringContainsString((string) $etudiant->id, $journal);
        $this->assertStringNotContainsStringIgnoringCase('password', $journal);
        $this->assertStringNotContainsStringIgnoringCase('token', $journal);
    }

    public function test_sondage_hors_perimetre_journalise_404(): void
    {
        $faculteB = Faculte::create(['nom' => 'FB', 'code' => 'FB', 'description' => 'T', 'active' => true]);
        $etudiantB = $this->etudiant();
        $etudiantB->update(['faculte_id' => $faculteB->id]);
        $adminA = User::creerCompteBackOffice([
            'name' => 'Fac A', 'email' => uniqid('fa.', true).'@example.com', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $this->actingAs($adminA);
        $this->post(route('admin.admissions.store'), $this->payload($etudiantB))->assertNotFound();

        $this->assertStringContainsString('admission_operation_refusee', $this->contenuAudit());
    }

    public function test_erreur_validation_non_journalisee(): void
    {
        $etudiant = $this->etudiant();
        $this->actingAs($this->scolarite);

        $this->post(route('admin.admissions.store'), $this->payload($etudiant, ['decision' => 'peut_etre']))
            ->assertSessionHasErrors('decision');

        $this->assertStringNotContainsString('admission_operation_refusee', $this->contenuAudit());
    }
}
