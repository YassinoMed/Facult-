<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\AnomalieQualite;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\ImportReport;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Enseignement\Models\TypeEnseignement;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Services\BIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\ActAsApiClient;
use Tests\TestCase;

/**
 * Phases 10-11 — Pilotage + interopérabilité : nouveaux KPI BI
 * (passage, redoublements, volumes, documents), filtre programme,
 * anomalies scopées, isolation cache, export BI, prewarm, idempotence.
 */
class PilotageInteropTest extends TestCase
{
    use ActAsApiClient;
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private Programme $programmeA;

    private AnneeUniversitaire $annee;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.evaluation.active' => true,
            'modules.enseignement.active' => true,
            'modules.student-dossiers.active' => true,
        ]);

        $this->faculteA = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'T', 'active' => true]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->programmeA = Programme::create([
            'faculte_id' => $this->faculteA->id, 'code' => 'LMI', 'nom' => 'Licence MI',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.bi@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    private function creerEtudiant(string $cin, int $faculteId, ?int $programmeId = null): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => strtolower($cin).'@bi.test', 'date_naissance' => '2004-01-01',
            'sexe' => 'F', 'faculte_id' => $faculteId, 'programme_id' => $programmeId,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X',
            'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');

        return $etudiant->refresh();
    }

    public function test_kpi_passage_redoublements_volumes_documents(): void
    {
        $etudiant = $this->creerEtudiant('BKBI0001', $this->faculteA->id, $this->programmeA->id);
        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programmeA->id, 'niveau' => 'L1',
            'statut' => 'en_cours', 'type' => 'reinscription', 'regime' => 'initiale',
            'observation' => 'Redoublement — décision « ajourne » jury',
        ]);
        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $this->annee->id, 'programme_id' => $this->programmeA->id,
            'statut' => 'published', 'created_by' => $this->admin->id, 'published_at' => now(),
        ]);
        DecisionDeliberation::create([
            'deliberation_id' => $deliberation->id, 'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'moyenne' => 12.5, 'credits_obtenus' => 30, 'credits_total' => 30,
            'decision' => 'admis', 'publie' => true,
        ]);

        $type = TypeEnseignement::create(['code' => 'CM', 'nom' => 'Cours']);
        $niveau = Niveau::create(['programme_id' => $this->programmeA->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $semestre = SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $this->programmeA->id,
            'code' => 'UE01', 'nom' => 'UE', 'credits' => 6, 'coefficient' => 1,
        ]);
        $matiere = Matiere::create([
            'code' => 'INF101', 'nom' => 'Info', 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 4,
        ]);
        Enseignement::create([
            'matiere_id' => $matiere->id, 'type_id' => $type->id,
            'annee_universitaire_id' => $this->annee->id, 'programme_id' => $this->programmeA->id,
            'volume_cm' => 18, 'volume_td' => 12, 'statut' => 'active', 'cree_par' => $this->admin->id,
        ]);

        $indicateurs = app(BIService::class)->indicateurs($this->annee->id, $this->faculteA->id);

        $this->assertSame('100 %', $indicateurs['taux_passage']['valeur']);
        $this->assertSame(1, $indicateurs['redoublements']['valeur']);
        $this->assertSame('1 ens. / 30 h', $indicateurs['volumes_enseignement']['valeur']);
        $this->assertSame('0 valides / 0 révoqués', $indicateurs['documents_genere']['valeur']);
    }

    public function test_filtre_programme_isole(): void
    {
        $programmeB = Programme::create([
            'faculte_id' => $this->faculteA->id, 'code' => 'LMP', 'nom' => 'Licence MP',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $this->creerEtudiant('BKBI0002', $this->faculteA->id, $this->programmeA->id);
        $this->creerEtudiant('BKBI0003', $this->faculteA->id, $programmeB->id);

        $bi = app(BIService::class);

        $this->assertSame(1, $bi->indicateurs(null, $this->faculteA->id, $this->programmeA->id)['effectif']['valeur']);
        $this->assertSame(1, $bi->indicateurs(null, $this->faculteA->id, $programmeB->id)['effectif']['valeur']);
        $this->assertSame(2, $bi->indicateurs(null, $this->faculteA->id)['effectif']['valeur']);
    }

    public function test_anomalies_scopees_et_cache_isole(): void
    {
        AnomalieQualite::create([
            'cle_unique' => 'qa', 'source' => 'population', 'gravite' => 'erreur',
            'message' => 'A', 'lien' => '/', 'action_recommandee' => 'Voir',
            'entite_libelle' => 'E1', 'statut' => 'ouverte', 'faculte_id' => $this->faculteA->id,
        ]);
        AnomalieQualite::create([
            'cle_unique' => 'qb', 'source' => 'population', 'gravite' => 'erreur',
            'message' => 'B', 'lien' => '/', 'action_recommandee' => 'Voir',
            'entite_libelle' => 'E2', 'statut' => 'ouverte', 'faculte_id' => $this->faculteB->id,
        ]);
        AnomalieQualite::create([
            'cle_unique' => 'qc', 'source' => 'population', 'gravite' => 'info',
            'message' => 'C', 'lien' => '/', 'action_recommandee' => 'Voir',
            'entite_libelle' => 'E3', 'statut' => 'ouverte', 'faculte_id' => null,
        ]);

        $bi = app(BIService::class);

        // Périmètre A : sienne + globale, jamais celle de B (pas de fuite).
        $this->assertSame(2, $bi->indicateurs(null, $this->faculteA->id)['anomalies_ouvertes']['valeur']);
        $this->assertSame(2, $bi->indicateurs(null, $this->faculteB->id)['anomalies_ouvertes']['valeur']);
        $this->assertSame(3, $bi->indicateurs(null, null)['anomalies_ouvertes']['valeur']);
    }

    public function test_export_bi_parite_et_perimetre(): void
    {
        $this->creerEtudiant('BKBI0004', $this->faculteA->id);

        $reponse = $this->actingAs($this->admin)
            ->get(route('admin.bi.export', ['faculte_id' => $this->faculteA->id]))
            ->assertOk();

        $contenu = (string) $reponse->streamedContent();
        $this->assertStringContainsString('taux_passage', $contenu);
        $this->assertStringContainsString('volumes_enseignement', $contenu);
        $this->assertStringContainsString('documents_genere', $contenu);
    }

    public function test_prewarm_commande(): void
    {
        Cache::flush();

        $this->artisan('bi:prewarmer')->assertSuccessful();

        // Le cache est peuplé pour le périmètre global (2e appel = hit).
        $bi = app(BIService::class);
        $avant = $bi->indicateurs($this->annee->id, null);
        $this->assertArrayHasKey('taux_passage', $avant);
    }

    public function test_idempotence_post_exports(): void
    {
        [, $token] = $this->clientApi(['exports:create']);

        $payload = ['type' => 'students', 'format' => 'json'];
        $headers = ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'test-cle-12345678'];

        $premiere = $this->postJson('/api/v1/exports', $payload, $headers)->assertCreated();
        $this->assertSame('false', $premiere->headers->get('X-Idempotent-Replayed'));

        $rejeu = $this->postJson('/api/v1/exports', $payload, $headers)->assertCreated();
        $this->assertSame('true', $rejeu->headers->get('X-Idempotent-Replayed'));
        $this->assertSame($premiere->json('report.id'), $rejeu->json('report.id'));

        // Un seul rapport créé : pas de double opération métier.
        $this->assertSame(1, ImportReport::count());
    }

    public function test_sans_cle_pas_de_deduplication(): void
    {
        [, $token] = $this->clientApi(['exports:create']);
        $headers = ['Authorization' => 'Bearer '.$token];

        $this->postJson('/api/v1/exports', ['type' => 'students', 'format' => 'json'], $headers)->assertCreated();
        $this->postJson('/api/v1/exports', ['type' => 'students', 'format' => 'json'], $headers)->assertCreated();

        $this->assertSame(2, ImportReport::count());
    }
}
