<?php

use App\Models\AnomalieQualite;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\User;
use App\Modules\AssuranceQualite\Models\ConstatAccreditation;
use App\Modules\AssuranceQualite\Models\CritereAccreditation;
use App\Modules\AssuranceQualite\Models\PlanActionAccreditation;
use App\Modules\AssuranceQualite\Models\PreuveConstatAccreditation;
use App\Modules\AssuranceQualite\Models\RapportAccreditation;
use App\Modules\AssuranceQualite\Models\ReferentielAccreditation;
use App\Modules\AssuranceQualite\Models\VersionReferentielAccreditation;
use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Modules\StudentDossiers\Models\StudentDossier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Lot 16 — Assurance qualité et accréditation : tests du cahier.
 *
 * Couverture : catalogue versionné SAISI (aucun critère inventé par le
 * système), version FIGÉE à l'approbation, chaîne critère → constat →
 * preuve (artefacts RÉELS uniquement) → plan d'action tracé, rapport
 * COMPLET exigé à la soumission (totaux figés, `non_evalue` jamais
 * conforme), approbateur ≠ soumetteur, rejet motivé, export sous
 * permissions doubles, isolation facultaire, module désactivé,
 * non-écriture des données officielles, performance synthétique.
 *
 * Données 100% synthétiques — aucune donnée réelle d'étudiant.
 */
class AssuranceQualiteTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('modules.assurance-qualite.active', true);

        $this->faculteA = Faculte::create(['nom' => 'Sciences Test', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'Droit Test', 'code' => 'FDEG', 'description' => 'T', 'active' => true]);
    }

    /* ── Données de synthèse ─────────────────────────────────────── */

    /** Référentiel + version brouillon + 2 critères par défaut. */
    private function referentielVersion(array $criteres = ['C1', 'C2']): array
    {
        $referentiel = ReferentielAccreditation::create([
            'code' => 'REF-'.uniqid(), 'nom' => 'Referentiel Test',
            'organisme' => 'Organisme Test', 'cree_par' => $this->adminScolarite()->id,
        ]);
        $version = VersionReferentielAccreditation::create([
            'referentiel_id' => $referentiel->id, 'numero' => 1, 'statut' => 'brouillon',
        ]);
        foreach ($criteres as $code) {
            CritereAccreditation::create([
                'version_referentiel_id' => $version->id, 'code' => $code,
                'libelle' => 'Critère '.$code,
            ]);
        }

        return [$referentiel, $version];
    }

    /** Version approvée prête pour l'évaluation. */
    private function versionApprouvee(array $criteres = ['C1', 'C2']): VersionReferentielAccreditation
    {
        [, $version] = $this->referentielVersion($criteres);
        $version->forceFill([
            'statut' => 'approuvee', 'motif_figage' => 'Adoption officielle du conseil test.',
            'approuve_par' => $this->adminScolarite()->id, 'approuve_le' => now(),
        ])->save();

        return $version;
    }

    private function constat(CritereAccreditation $critere, string $evaluation, ?int $faculteId = null): ConstatAccreditation
    {
        return ConstatAccreditation::create([
            'critere_id' => $critere->id,
            'perimetre' => ConstatAccreditation::perimetrePour($faculteId),
            'faculte_id' => $faculteId,
            'evaluation' => $evaluation,
            'evaluateur_id' => $this->adminScolarite()->id,
            'evalue_le' => now(),
        ]);
    }

    /** Document RÉEL du coffre (dossier étudiant complet). */
    private function documentCoffre(): StudentDocument
    {
        $etudiant = Etudiant::create([
            'cin' => 'CIN-'.uniqid(), 'nom' => 'Nom'.uniqid(), 'prenom' => 'Pre',
            'email' => 'etu-'.uniqid().'@example.test', 'sexe' => 'M',
            'faculte_id' => $this->faculteA->id, 'niveau' => 'L1', 'annee_academique' => '2024-2025',
            'adresse' => 'Rue test', 'ville' => 'Dakar', 'code_postal' => '10000', 'annee_bac' => '2020',
        ]);
        $dossier = StudentDossier::create([
            'etudiant_id' => $etudiant->id, 'reference' => 'DOS-'.uniqid(), 'status' => 'open',
        ]);

        return StudentDocument::create([
            'student_dossier_id' => $dossier->id, 'category' => 'academique',
            'document_type' => 'releve', 'title' => 'Relevé de notes',
            'original_filename' => 'releve.pdf', 'storage_path' => 'dossiers/test/releve.pdf',
            'mime_type' => 'application/pdf', 'extension' => 'pdf', 'size' => 1024,
            'sha256' => str_repeat('a', 64), 'status' => 'validated',
        ]);
    }

    private function anomalie(): AnomalieQualite
    {
        return AnomalieQualite::create([
            'cle_unique' => uniqid('test-'), 'source' => 'population', 'gravite' => 'avertissement',
            'message' => 'Anomalie synthétique de test.', 'statut' => 'ouverte',
        ]);
    }

    private function journal(): HistoriqueAcademique
    {
        // FK etudiant_id : un étudiant synthétique est créé si aucun n'existe
        // (l'entrée de journal cite toujours un étudiant RÉEL de la base de test).
        $etudiant = Etudiant::first() ?? Etudiant::create([
            'cin' => 'CIN-'.uniqid(), 'nom' => 'Nom'.uniqid(), 'prenom' => 'Pre',
            'email' => 'etu-'.uniqid().'@example.test', 'sexe' => 'M',
            'faculte_id' => $this->faculteA->id, 'niveau' => 'L1', 'annee_academique' => '2024-2025',
            'adresse' => 'Rue test', 'ville' => 'Dakar', 'code_postal' => '10000', 'annee_bac' => '2020',
        ]);

        return HistoriqueAcademique::create([
            'etudiant_id' => $etudiant->id, 'utilisateur_id' => $this->adminScolarite()->id,
            'action' => 'verification', 'entite' => 'test', 'entite_id' => 1, 'created_at' => now(),
        ]);
    }

    /* ── Utilisateurs ────────────────────────────────────────────── */

    private function adminScolarite(): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Admin Scolarite', 'email' => 'scol-'.uniqid().'@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    private function adminFaculte(Faculte $faculte): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Admin Faculte', 'email' => 'adminfac-'.uniqid().'@universite.test',
            'password' => 'Motdepasse@123', 'faculte_id' => $faculte->id,
        ], 'admin_faculte');
    }

    private function consultateur(): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Consultation', 'email' => 'consult-'.uniqid().'@universite.test', 'password' => 'Motdepasse@123',
        ], 'consultation');
    }

    /* ── Appels ──────────────────────────────────────────────────── */

    private function appelPost(User $u, string $route, array $params, array $data): TestResponse
    {
        return $this->actingAs($u)->postJson(route($route, $params), $data);
    }

    private function appelGet(User $u, string $route, array $params = []): TestResponse
    {
        return $this->actingAs($u)->getJson(route($route, $params));
    }

    /* ── Catalogue versionné (aucun critère inventé) ─────────────── */

    public function test_catalogue_vide_par_defaut_aucun_critere_invente(): void
    {
        $reponse = $this->appelGet($this->adminScolarite(), 'admin.assurance-qualite.referentiels.index');
        $reponse->assertOk()->assertJson(['referentiels' => []]);
        $this->assertSame(0, ReferentielAccreditation::count());
        $this->assertSame(0, CritereAccreditation::count()); // jamais seedé
    }

    public function test_creation_referentiel_refusee_sans_qualite_manage(): void
    {
        $this->appelPost($this->consultateur(), 'admin.assurance-qualite.referentiels.creer', [], [
            'code' => 'REF-X', 'nom' => 'X', 'organisme' => 'Organisme X',
        ])->assertStatus(403);
    }

    public function test_version_numeros_incrementes(): void
    {
        $admin = $this->adminScolarite();
        $referentiel = ReferentielAccreditation::create([
            'code' => 'REF-N', 'nom' => 'N', 'organisme' => 'O', 'cree_par' => $admin->id,
        ]);

        $v1 = $this->appelPost($admin, 'admin.assurance-qualite.versions.creer', ['id' => $referentiel->id], [])->assertStatus(201)->json('version');
        $v2 = $this->appelPost($admin, 'admin.assurance-qualite.versions.creer', ['id' => $referentiel->id], [])->assertStatus(201)->json('version');

        $this->assertSame(1, $v1['numero']);
        $this->assertSame(2, $v2['numero']);
        $this->assertSame('brouillon', $v2['statut']);
    }

    public function test_critere_ajoute_en_brouillon_puis_code_duplique_refuse(): void
    {
        // Version SANS critère : le test crée C1 PAR L'API, puis tente le doublon.
        [, $version] = $this->referentielVersion([]);
        $admin = $this->adminScolarite();

        $this->appelPost($admin, 'admin.assurance-qualite.criteres.creer', ['id' => $version->id], [
            'code' => 'C1', 'libelle' => 'Premier critère',
        ])->assertStatus(201);

        $this->appelPost($admin, 'admin.assurance-qualite.criteres.creer', ['id' => $version->id], [
            'code' => 'C1', 'libelle' => 'Doublon',
        ])->assertStatus(422); // jamais d'écrasement silencieux
    }

    public function test_version_approuvee_est_figee(): void
    {
        $admin = $this->adminScolarite();
        [, $version] = $this->referentielVersion(['C1']);

        $reponse = $this->appelPost($admin, 'admin.assurance-qualite.versions.approuver', ['id' => $version->id], [
            'motif_figage' => 'Adoption officielle du conseil.',
        ])->assertOk();

        $version->refresh();
        $this->assertSame('approuvee', $version->statut);
        $this->assertSame($admin->id, (int) $version->approuve_par);
        $this->assertNotNull($version->approuve_le);

        // FIGÉE : plus aucun critère ajoutable.
        $this->appelPost($admin, 'admin.assurance-qualite.criteres.creer', ['id' => $version->id], [
            'code' => 'C-NOUVEAU', 'libelle' => 'Interdit après figage',
        ])->assertStatus(422);
    }

    public function test_approbation_version_exige_motif_ecrit(): void
    {
        [, $version] = $this->referentielVersion();
        $admin = $this->adminScolarite();

        $this->appelPost($admin, 'admin.assurance-qualite.versions.approuver', ['id' => $version->id], [])->assertStatus(422);
        $this->appelPost($admin, 'admin.assurance-qualite.versions.approuver', ['id' => $version->id], ['motif_figage' => 'court'])->assertStatus(422);
        $this->assertSame('brouillon', $version->refresh()->statut);
    }

    public function test_re_approbation_et_archivage_sans_etat_valide_refuses(): void
    {
        $admin = $this->adminScolarite();
        $version = $this->versionApprouvee();

        $this->appelPost($admin, 'admin.assurance-qualite.versions.approuver', ['id' => $version->id], ['motif_figage' => 'Re-approbation interdite.'])->assertStatus(422);
        $this->appelPost($admin, 'admin.assurance-qualite.versions.archiver', ['id' => $version->id], [])->assertOk();
        $this->assertSame('archivee', $version->refresh()->statut);
    }

    public function test_version_archivee_n_est_plus_evaluable(): void
    {
        $admin = $this->adminScolarite();
        $version = $this->versionApprouvee();
        $this->appelPost($admin, 'admin.assurance-qualite.versions.archiver', ['id' => $version->id], [])->assertOk();

        $critere = $version->criteres()->first();
        $this->appelPost($admin, 'admin.assurance-qualite.constats.enregistrer', ['id' => $critere->id], [
            'evaluation' => 'conforme',
        ])->assertStatus(422);
    }

    /* ── Chaîne constat → preuve → plan d'action ────────────────── */

    public function test_constat_upsert_par_critere_et_perimetre(): void
    {
        $admin = $this->adminScolarite();
        $version = $this->versionApprouvee();
        $critere = $version->criteres()->first();

        $this->appelPost($admin, 'admin.assurance-qualite.constats.enregistrer', ['id' => $critere->id], [
            'evaluation' => 'conforme',
        ])->assertOk();
        $this->appelPost($admin, 'admin.assurance-qualite.constats.enregistrer', ['id' => $critere->id], [
            'evaluation' => 'partiellement_conforme', 'commentaire' => 'Dossier partiel.',
        ])->assertOk();

        $constats = ConstatAccreditation::where('critere_id', $critere->id)->get();
        $this->assertCount(1, $constats); // upsert : une ligne par (critère, périmètre)
        $this->assertSame('partiellement_conforme', $constats->sole()->evaluation);
        $this->assertSame($admin->id, (int) $constats->sole()->evaluateur_id);
        $this->assertNotNull($constats->sole()->evalue_le);
    }

    public function test_critere_a_portee_faculte_coherence_exigee(): void
    {
        $admin = $this->adminScolarite();
        [, $version] = $this->referentielVersion(['C1']);
        $critere = CritereAccreditation::create([
            'version_referentiel_id' => $version->id, 'code' => 'C-FAC',
            'libelle' => 'Critère faculté', 'faculte_id' => $this->faculteA->id,
        ]);

        // Évaluation sur une AUTRE faculté : refusée.
        $this->appelPost($admin, 'admin.assurance-qualite.constats.enregistrer', ['id' => $critere->id], [
            'faculte_id' => $this->faculteB->id, 'evaluation' => 'conforme',
        ])->assertStatus(422);

        // Évaluation sur SA faculté : acceptée.
        $this->appelPost($admin, 'admin.assurance-qualite.constats.enregistrer', ['id' => $critere->id], [
            'faculte_id' => $this->faculteA->id, 'evaluation' => 'conforme',
        ])->assertOk();
    }

    public function test_preuve_document_reel_acceptee_et_cible_inexistante_refusee(): void
    {
        $admin = $this->adminScolarite();
        $version = $this->versionApprouvee();
        $constat = $this->constat($version->criteres()->first(), 'conforme');
        $document = $this->documentCoffre();

        $this->appelPost($admin, 'admin.assurance-qualite.preuves.ajouter', ['id' => $constat->id], [
            'type' => 'document', 'cible_id' => $document->id,
        ])->assertStatus(201)->assertJsonPath('preuve.cible_libelle', 'Document #'.$document->id.' — releve.pdf');

        // Lien mort : jamais accepté.
        $this->appelPost($admin, 'admin.assurance-qualite.preuves.ajouter', ['id' => $constat->id], [
            'type' => 'document', 'cible_id' => 999999,
        ])->assertStatus(422);
    }

    public function test_preuve_anomalie_et_journal_reelles_acceptees(): void
    {
        $admin = $this->adminScolarite();
        $version = $this->versionApprouvee(['C1']);
        $constat = $this->constat($version->criteres()->first(), 'non_conforme');
        $anomalie = $this->anomalie();
        $journal = $this->journal();

        $p1 = $this->appelPost($admin, 'admin.assurance-qualite.preuves.ajouter', ['id' => $constat->id], [
            'type' => 'anomalie', 'cible_id' => $anomalie->id,
        ])->assertStatus(201)->json('preuve');
        $p2 = $this->appelPost($admin, 'admin.assurance-qualite.preuves.ajouter', ['id' => $constat->id], [
            'type' => 'journal', 'cible_id' => $journal->id,
        ])->assertStatus(201)->json('preuve');

        $this->assertStringContainsString('Anomalie #'.$anomalie->id, (string) $p1['cible_libelle']);
        $this->assertStringContainsString('Journal #'.$journal->id, (string) $p2['cible_libelle']);
    }

    public function test_preuve_idempotente_pas_de_doublon(): void
    {
        $admin = $this->adminScolarite();
        $version = $this->versionApprouvee(['C1']);
        $constat = $this->constat($version->criteres()->first(), 'conforme');
        $document = $this->documentCoffre();

        $id1 = $this->appelPost($admin, 'admin.assurance-qualite.preuves.ajouter', ['id' => $constat->id], [
            'type' => 'document', 'cible_id' => $document->id,
        ])->assertStatus(201)->json('preuve.id');
        $id2 = $this->appelPost($admin, 'admin.assurance-qualite.preuves.ajouter', ['id' => $constat->id], [
            'type' => 'document', 'cible_id' => $document->id,
        ])->assertStatus(201)->json('preuve.id');

        $this->assertSame($id1, $id2); // firstOrCreate : idempotent
        $this->assertSame(1, PreuveConstatAccreditation::count());
    }

    public function test_plan_action_cloture_tracee_et_re_cloture_refusee(): void
    {
        $admin = $this->adminScolarite();
        $version = $this->versionApprouvee(['C1']);
        $constat = $this->constat($version->criteres()->first(), 'non_conforme');

        $plan = $this->appelPost($admin, 'admin.assurance-qualite.plans.creer', ['id' => $constat->id], [
            'mesure' => 'Renforcer le tutorat de la matière concernée.',
        ])->assertStatus(201)->json('plan');

        $this->appelPost($admin, 'admin.assurance-qualite.plans.cloturer', ['id' => $plan['id']], [])->assertOk();
        $planModel = PlanActionAccreditation::sole();
        $this->assertSame('termine', $planModel->statut);
        $this->assertSame($admin->id, (int) $planModel->cloture_par);
        $this->assertNotNull($planModel->cloture_le);

        $this->appelPost($admin, 'admin.assurance-qualite.plans.cloturer', ['id' => $plan['id']], [])->assertStatus(422);
    }

    /* ── Rapport : complétude, totaux figés, workflow ───────────── */

    public function test_soumission_refusee_si_constat_manquant(): void
    {
        $admin = $this->adminScolarite();
        $version = $this->versionApprouvee(['C1', 'C2']);
        $this->constat($version->criteres()->where('code', 'C1')->first(), 'conforme');
        // C2 reste SANS constat — jamais conforme par défaut.

        $reponse = $this->appelPost($admin, 'admin.assurance-qualite.rapports.soumettre', [], [
            'version_referentiel_id' => $version->id,
        ])->assertStatus(422);

        $this->assertStringContainsString('C2', (string) $reponse->json('erreur'));
        $this->assertSame(0, RapportAccreditation::count());
    }

    public function test_soumission_fige_les_totaux_exacts(): void
    {
        $admin = $this->adminScolarite();
        $version = $this->versionApprouvee(['C1', 'C2', 'C3', 'C4']);
        $criteres = $version->criteres()->orderBy('code')->get();
        $this->constat($criteres[0], 'conforme');
        $this->constat($criteres[1], 'partiellement_conforme');
        $this->constat($criteres[2], 'non_conforme');
        $this->constat($criteres[3], 'non_evalue'); // EXPLICITE : compté non évalué

        $rapport = $this->appelPost($admin, 'admin.assurance-qualite.rapports.soumettre', [], [
            'version_referentiel_id' => $version->id,
        ])->assertOk()->json('rapport');

        // Totaux FIGÉS — `non_evalue` jamais compté conforme.
        $this->assertSame(4, $rapport['total_criteres']);
        $this->assertSame(1, $rapport['total_conformes']);
        $this->assertSame(1, $rapport['total_partiels']);
        $this->assertSame(1, $rapport['total_non_conformes']);
        $this->assertSame(1, $rapport['total_non_evalues']);
        $this->assertSame('soumis', $rapport['statut']);
    }

    public function test_rapport_exige_une_version_approuvee(): void
    {
        $admin = $this->adminScolarite();
        [, $version] = $this->referentielVersion(['C1']); // brouillon
        $this->constat($version->criteres()->first(), 'conforme');

        $this->appelPost($admin, 'admin.assurance-qualite.rapports.soumettre', [], [
            'version_referentiel_id' => $version->id,
        ])->assertStatus(422);
    }

    public function test_approbateur_doit_differe_du_soumetteur(): void
    {
        $admin = $this->adminScolarite();
        $version = $this->versionApprouvee(['C1']);
        $this->constat($version->criteres()->first(), 'conforme');

        $rapport = $this->appelPost($admin, 'admin.assurance-qualite.rapports.soumettre', [], [
            'version_referentiel_id' => $version->id,
        ])->assertOk()->json('rapport');

        // Le MÊME utilisateur approuve : refus (séparation des pouvoirs).
        $this->appelPost($admin, 'admin.assurance-qualite.rapports.approuver', ['id' => $rapport['id']], [])->assertStatus(422);
        $this->appelPost($admin, 'admin.assurance-qualite.rapports.rejeter', ['id' => $rapport['id']], ['motif_rejet' => 'Motif de rejet test.'])->assertStatus(422);
        $this->assertSame('soumis', RapportAccreditation::sole()->refresh()->statut);
    }

    public function test_approbation_et_rejet_traces_par_un_autre_agent(): void
    {
        $soumetteur = $this->adminScolarite();
        $approbateur = $this->adminScolarite();
        $version = $this->versionApprouvee(['C1']);
        $this->constat($version->criteres()->first(), 'conforme');

        $rapport = $this->appelPost($soumetteur, 'admin.assurance-qualite.rapports.soumettre', [], [
            'version_referentiel_id' => $version->id,
        ])->assertOk()->json('rapport');

        // Rejet motivé par un autre agent, puis re-soumission possible.
        $this->appelPost($approbateur, 'admin.assurance-qualite.rapports.rejeter', ['id' => $rapport['id']], [])->assertStatus(422); // motif requis
        $this->appelPost($approbateur, 'admin.assurance-qualite.rapports.rejeter', ['id' => $rapport['id']], [
            'motif_rejet' => 'Preuves insuffisantes sur le critère C1.',
        ])->assertOk();
        $model = RapportAccreditation::sole();
        $this->assertSame('rejete', $model->statut);
        $this->assertSame($approbateur->id, (int) $model->approuve_par);

        $reponse = $this->appelPost($soumetteur, 'admin.assurance-qualite.rapports.soumettre', [], [
            'version_referentiel_id' => $version->id,
        ])->assertOk();
        $this->assertSame('soumis', RapportAccreditation::sole()->refresh()->statut);

        // Puis approbation définitive.
        $this->appelPost($approbateur, 'admin.assurance-qualite.rapports.approuver', ['id' => $reponse->json('rapport.id')], [
            'commentaire_approbation' => 'Rapport complet et vérifiable.',
        ])->assertOk();
        $model = RapportAccreditation::sole()->refresh();
        $this->assertSame('approuve', $model->statut);
        $this->assertNotNull($model->approuve_le);

        // Rapport APPROUVÉ : immuable.
        $this->appelPost($soumetteur, 'admin.assurance-qualite.rapports.soumettre', [], [
            'version_referentiel_id' => $version->id,
        ])->assertStatus(422);
        $this->appelPost($approbateur, 'admin.assurance-qualite.rapports.approuver', ['id' => $model->id], [])->assertStatus(422);
    }

    /* ── Export, isolation, module, non-écriture, performance ───── */

    public function test_export_exige_qualite_view_et_export_create(): void
    {
        $admin = $this->adminScolarite();
        $version = $this->versionApprouvee(['C1']);
        $this->constat($version->criteres()->first(), 'conforme');
        $rapport = $this->appelPost($admin, 'admin.assurance-qualite.rapports.soumettre', [], [
            'version_referentiel_id' => $version->id,
        ])->assertOk()->json('rapport');
        $this->appelPost($this->adminScolarite(), 'admin.assurance-qualite.rapports.approuver', ['id' => $rapport['id']], [])->assertOk();

        // consultateur : qualite.view SANS export.create → 403.
        $this->appelGet($this->consultateur(), 'admin.assurance-qualite.rapports.export', ['id' => $rapport['id']])->assertStatus(403);

        // admin : les deux clés → export attaché avec totaux figés.
        $reponse = $this->appelGet($admin, 'admin.assurance-qualite.rapports.export', ['id' => $rapport['id']])->assertOk();
        $reponse->assertHeader('Content-Disposition', 'attachment; filename="rapport-accreditation-'.$rapport['id'].'.json"');
        $contenu = json_decode((string) $reponse->getContent(), true);
        $this->assertSame(1, $contenu['rapport']['total_conformes']); // total FIGÉ présent dans l'export
        $this->assertSame('C1', $contenu['lignes'][0]['code']);
    }

    public function test_admin_faculte_lecture_seule_et_perimetre_force(): void
    {
        $admin = $this->adminScolarite();
        $adminFacA = $this->adminFaculte($this->faculteA);

        // Un critère à portée faculté A, rapport du périmètre A.
        [, $version] = $this->referentielVersion([]);
        $version->forceFill(['statut' => 'approuvee', 'motif_figage' => 'Figage test.', 'approuve_par' => $admin->id])->save();
        $critereA = CritereAccreditation::create([
            'version_referentiel_id' => $version->id, 'code' => 'C-A',
            'libelle' => 'Critère A', 'faculte_id' => $this->faculteA->id,
        ]);
        $this->appelPost($admin, 'admin.assurance-qualite.constats.enregistrer', ['id' => $critereA->id], [
            'faculte_id' => $this->faculteA->id, 'evaluation' => 'conforme',
        ])->assertOk();
        $rapportA = $this->appelPost($admin, 'admin.assurance-qualite.rapports.soumettre', [], [
            'version_referentiel_id' => $version->id, 'faculte_id' => $this->faculteA->id,
        ])->assertOk()->json('rapport');

        // Rapport du périmètre B (autre faculté) : invisible pour adminFacA.
        $critereB = CritereAccreditation::create([
            'version_referentiel_id' => $version->id, 'code' => 'C-B',
            'libelle' => 'Critère B', 'faculte_id' => $this->faculteB->id,
        ]);
        $this->appelPost($admin, 'admin.assurance-qualite.constats.enregistrer', ['id' => $critereB->id], [
            'faculte_id' => $this->faculteB->id, 'evaluation' => 'non_evalue',
        ])->assertOk();
        $this->appelPost($admin, 'admin.assurance-qualite.rapports.soumettre', [], [
            'version_referentiel_id' => $version->id, 'faculte_id' => $this->faculteB->id,
        ])->assertOk();

        // Lecture : SEULEMENT les rapports de SA faculté.
        $visibles = $this->appelGet($adminFacA, 'admin.assurance-qualite.rapports.index')->assertOk()->json('rapports');
        $this->assertCount(1, $visibles);
        $this->assertSame($this->faculteA->id, (int) $visibles[0]['faculte_id']);
        $this->assertSame($rapportA['id'], $visibles[0]['id']);

        // Écriture : qualite.manage absent → 403.
        $this->appelPost($adminFacA, 'admin.assurance-qualite.constats.enregistrer', ['id' => $critereA->id], [
            'faculte_id' => $this->faculteA->id, 'evaluation' => 'non_conforme',
        ])->assertStatus(403);
    }

    public function test_module_desactive_renvoie_404(): void
    {
        Config::set('modules.assurance-qualite.active', false);
        $admin = $this->adminScolarite();

        $this->appelGet($admin, 'admin.assurance-qualite.referentiels.index')->assertStatus(404);
        $this->appelPost($admin, 'admin.assurance-qualite.rapports.soumettre', [], [])->assertStatus(404);
    }

    public function test_donnees_officielles_non_modifiees_par_le_flux(): void
    {
        $snapDocuments = StudentDocument::count();
        $snapAnomalies = AnomalieQualite::count();
        $snapJournal = HistoriqueAcademique::count();

        $admin = $this->adminScolarite();
        $version = $this->versionApprouvee(['C1']);
        $constat = $this->constat($version->criteres()->first(), 'conforme');
        $document = $this->documentCoffre();
        $anomalie = $this->anomalie();
        $journal = $this->journal();

        $snapDocumentsApresScaffold = StudentDocument::count(); // artefacts créés PAR le test

        $this->appelPost($admin, 'admin.assurance-qualite.preuves.ajouter', ['id' => $constat->id], [
            'type' => 'document', 'cible_id' => $document->id,
        ])->assertStatus(201);
        $this->appelPost($admin, 'admin.assurance-qualite.preuves.ajouter', ['id' => $constat->id], [
            'type' => 'anomalie', 'cible_id' => $anomalie->id,
        ])->assertStatus(201);
        $this->appelPost($admin, 'admin.assurance-qualite.preuves.ajouter', ['id' => $constat->id], [
            'type' => 'journal', 'cible_id' => $journal->id,
        ])->assertStatus(201);
        $this->appelPost($admin, 'admin.assurance-qualite.rapports.soumettre', [], [
            'version_referentiel_id' => $version->id,
        ])->assertOk();

        // Les preuves sont des LIENS : les sources restent intactes.
        $this->assertSame($snapDocumentsApresScaffold, StudentDocument::count());
        $this->assertSame($snapAnomalies + 1, AnomalieQualite::count());
        $this->assertSame($snapJournal + 1, HistoriqueAcademique::count());
        $this->assertSame('validated', $document->refresh()->status);
    }

    public function test_performance_synthetique_sous_trois_secondes(): void
    {
        $admin = $this->adminScolarite();
        $codes = array_map(fn ($i) => sprintf('C%03d', $i), range(1, 50));
        $version = $this->versionApprouvee($codes);

        $debut = microtime(true);
        foreach ($version->criteres()->get() as $critere) {
            $this->appelPost($admin, 'admin.assurance-qualite.constats.enregistrer', ['id' => $critere->id], [
                'evaluation' => 'conforme',
            ])->assertOk();
        }
        $this->appelPost($admin, 'admin.assurance-qualite.rapports.soumettre', [], [
            'version_referentiel_id' => $version->id,
        ])->assertOk()->assertJsonPath('rapport.total_criteres', 50);
        $duree = microtime(true) - $debut;

        $this->assertLessThan(3.0, $duree, 'Flux synthétique trop lent : '.$duree.' s');
        $this->assertSame(50, ConstatAccreditation::count());
    }
}
