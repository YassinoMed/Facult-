<?php

namespace Tests\Feature;

use App\Jobs\VerifyStudentDossierJob;
use App\Models\AnneeUniversitaire;
use App\Models\DocumentAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Services\Verification\CorrectionService;
use App\Services\Verification\VerificationEngine;
use App\Services\Verification\VerificationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActAsApiClient;
use Tests\TestCase;

class VerificationFeatureTest extends TestCase
{
    use ActAsApiClient, RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private User $adminScolarite;

    private User $adminFaculteA;

    private User $adminFaculteB;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Niveau $niveauL1;

    private Niveau $niveauL2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteA = Faculte::create(['nom' => 'Faculté des Sciences', 'code' => 'FST', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'Faculté de Droit', 'code' => 'FD', 'active' => true]);

        $this->adminScolarite = User::creerCompteBackOffice([
            'name' => 'Agent Scolarité', 'email' => 'scol@univ.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->adminFaculteA = User::creerCompteBackOffice([
            'name' => 'Admin Fac A', 'email' => 'facA@univ.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $this->adminFaculteB = User::creerCompteBackOffice([
            'name' => 'Admin Fac B', 'email' => 'facB@univ.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programme = Programme::create([
            'code' => 'INFO',
            'nom' => 'Informatique',
            'faculte_id' => $this->faculteA->id,
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);

        $this->niveauL1 = Niveau::create([
            'programme_id' => $this->programme->id,
            'code' => 'L1',
            'nom' => 'Licence 1',
            'ordre' => 1,
        ]);

        $this->niveauL2 = Niveau::create([
            'programme_id' => $this->programme->id,
            'code' => 'L2',
            'nom' => 'Licence 2',
            'ordre' => 2,
        ]);
    }

    private function creerEtudiant(array $attributs = []): Etudiant
    {
        static $compteur = 10000000;
        $compteur++;

        $etudiant = Etudiant::create(array_merge([
            'nom' => 'Ben Ali',
            'prenom' => 'Sami',
            'email' => 'sami.'.$compteur.'@univ.tn',
            'cin' => (string) $compteur,
            'sexe' => 'M',
            'date_naissance' => '2004-05-15',
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Avenue Habib Bourguiba',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2023',
            'password' => 'Etudiant@123',
            'faculte_id' => $this->faculteA->id,
            'programme_id' => $this->programme->id,
            'niveau_id' => $this->niveauL1->id,
        ], $attributs));

        if (isset($attributs['statut']) && $attributs['statut'] !== 'en_attente') {
            $etudiant->definirStatut($attributs['statut']);
        }

        return $etudiant;
    }

    // ── SCÉNARIOS §23 DU CAHIER DES CHARGES ──

    public function test_cas_1_dossier_parfaitement_conforme(): void
    {
        $groupe = Group::create([
            'code' => 'G-INFO-1',
            'nom' => 'Groupe Info 1',
            'programme_id' => $this->programme->id,
            'faculte_id' => $this->faculteA->id,
            'niveau' => 'L1',
            'capacite' => 30,
        ]);

        $etudiant = $this->creerEtudiant([
            'groupe_id' => $groupe->id,
        ]);
        $etudiant->definirStatut('valide');

        InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'niveau_id' => $this->niveauL1->id,
            'niveau' => 'L1',
            'statut' => 'valide',
        ]);

        foreach (['cin', 'photo', 'baccalaureat'] as $type) {
            DocumentAcademique::create([
                'etudiant_id' => $etudiant->id,
                'type_document' => $type,
                'nom_original' => "{$type}.pdf",
                'chemin' => "documents/{$type}.pdf",
                'mime_type' => 'application/pdf',
                'taille' => 1024,
                'hash_fichier' => hash('sha256', $type),
                'annee_universitaire_id' => $this->annee->id,
            ]);
        }

        $engine = app(VerificationEngine::class);
        $resultat = $engine->verifier($etudiant);

        $this->assertSame(VerificationResult::CONFORME, $resultat->verdict);
        $this->assertGreaterThanOrEqual(90, $resultat->score);
        $this->assertSame(0, $resultat->nombreBloquants());
    }

    public function test_cas_2_inscription_absente_est_bloquant(): void
    {
        $etudiant = $this->creerEtudiant();
        $etudiant->definirStatut('valide');

        $engine = app(VerificationEngine::class);
        $resultat = $engine->verifier($etudiant);

        $this->assertSame(VerificationResult::BLOQUANT, $resultat->verdict);
        $this->assertLessThanOrEqual(49, $resultat->score);
        $this->assertTrue(collect($resultat->anomalies)->contains(fn ($a) => $a->estBloquante() && $a->champ === 'statut'));
    }

    public function test_cas_3_document_manquant_est_a_verifier(): void
    {
        $etudiant = $this->creerEtudiant();
        $etudiant->definirStatut('valide');

        InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'niveau_id' => $this->niveauL1->id,
            'niveau' => 'L1',
            'statut' => 'valide',
        ]);

        // Manque le baccalauréat
        DocumentAcademique::create([
            'etudiant_id' => $etudiant->id,
            'type_document' => 'cin',
            'nom_original' => 'cin.pdf',
            'chemin' => 'documents/cin.pdf',
            'mime_type' => 'application/pdf',
            'taille' => 1024,
            'hash_fichier' => hash('sha256', 'cin'),
            'annee_universitaire_id' => $this->annee->id,
        ]);

        $engine = app(VerificationEngine::class);
        $resultat = $engine->verifier($etudiant);

        $this->assertSame(VerificationResult::A_VERIFIER, $resultat->verdict);
        $this->assertTrue(collect($resultat->anomalies)->contains(fn ($a) => $a->regle === 'documents.requis'));
    }

    public function test_cas_4_saut_de_niveau_sans_niveau_inferieur_est_bloquant(): void
    {
        $etudiant = $this->creerEtudiant([
            'niveau_id' => $this->niveauL2->id,
        ]);
        $etudiant->definirStatut('valide');

        InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'niveau_id' => $this->niveauL2->id,
            'niveau' => 'L2',
            'statut' => 'valide',
        ]);

        $engine = app(VerificationEngine::class);
        $resultat = $engine->verifier($etudiant);

        $this->assertSame(VerificationResult::BLOQUANT, $resultat->verdict);
        $this->assertTrue(collect($resultat->anomalies)->contains(fn ($a) => $a->regle === 'parcours.lmd' && $a->estBloquante()));
    }

    public function test_cas_5_doublon_potentiel_est_a_verifier(): void
    {
        $etudiant1 = $this->creerEtudiant([
            'nom' => 'Homonyme',
            'prenom' => 'Karim',
            'date_naissance' => '2003-01-01',
        ]);
        $etudiant2 = $this->creerEtudiant([
            'nom' => 'Homonyme',
            'prenom' => 'Karim',
            'date_naissance' => '2003-01-01',
            'email' => 'autre@univ.tn',
        ]);

        $engine = app(VerificationEngine::class);
        $resultat = $engine->verifier($etudiant2);

        $this->assertTrue(collect($resultat->anomalies)->contains(fn ($a) => $a->regle === 'doublons.detection'));
    }

    public function test_cas_10_donnees_contradictoires_affiche_sources(): void
    {
        $autreProg = Programme::create([
            'code' => 'GEST',
            'nom' => 'Gestion',
            'faculte_id' => $this->faculteA->id,
        ]);

        $groupeAutre = Group::create([
            'code' => 'G-GEST-1',
            'nom' => 'Groupe Gestion',
            'programme_id' => $autreProg->id,
            'faculte_id' => $this->faculteA->id,
            'niveau' => 'L1',
            'capacite' => 30,
        ]);

        $etudiant = $this->creerEtudiant([
            'programme_id' => $this->programme->id,
            'groupe_id' => $groupeAutre->id,
        ]);
        $etudiant->definirStatut('valide');

        $engine = app(VerificationEngine::class);
        $resultat = $engine->verifier($etudiant);

        $anomalie = collect($resultat->anomalies)->first(fn ($a) => $a->regle === 'affectation.groupe');
        $this->assertNotNull($anomalie);
        $this->assertArrayHasKey('groupe_programme', $anomalie->donnees);
        $this->assertArrayHasKey('etudiant_programme', $anomalie->donnees);
    }

    // ── TESTS CONTRÔLEURS & INTERACTIONS WEB ──

    public function test_page_verification_accessible_par_agent_autorise(): void
    {
        $etudiant = $this->creerEtudiant();

        $this->actingAs($this->adminScolarite)
            ->get(route('admin.verification.show', $etudiant))
            ->assertOk()
            ->assertViewIs('admin.verification.show')
            ->assertSee('Vérification intelligente');
    }

    public function test_reverification_unitaire_enregistre_audit(): void
    {
        $etudiant = $this->creerEtudiant();

        $this->actingAs($this->adminScolarite)
            ->post(route('admin.verification.verifier', $etudiant))
            ->assertRedirect(route('admin.verification.show', $etudiant))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $etudiant->id,
            'action' => HistoriqueAcademique::ACTION_VERIFICATION_DOSSIER,
        ]);
    }

    public function test_correction_assistee_modifie_champ_et_cree_audit(): void
    {
        $etudiant = $this->creerEtudiant(['email' => 'ancien@univ.tn']);

        $this->actingAs($this->adminScolarite)
            ->post(route('admin.verification.corriger', $etudiant), [
                'champ' => 'email',
                'nouvelle_valeur' => 'nouveau@univ.tn',
                'motif' => 'Mise en conformité avec la scolarité',
            ])
            ->assertRedirect(route('admin.verification.show', $etudiant));

        $this->assertSame('nouveau@univ.tn', $etudiant->refresh()->email);

        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $etudiant->id,
            'action' => HistoriqueAcademique::ACTION_CORRECTION_DOSSIER,
        ]);
    }

    /**
     * LOOP saisies : la correction par champ valide CHAQUE type de
     * valeur (CIN 8 chiffres, email unique, date réelle, groupe
     * existant, statut via la matrice) — jamais de valeur brute.
     */
    public function test_correction_par_champ_cin_non_conforme_refusee(): void
    {
        $etudiant = $this->creerEtudiant(['cin' => '87654321']);

        foreach (['1234567', '123456789', '12A45678', '12 45678', ''] as $cinInvalide) {
            $this->actingAs($this->adminScolarite)
                ->post(route('admin.verification.corriger', $etudiant), [
                    'champ' => 'cin',
                    'nouvelle_valeur' => $cinInvalide,
                    'motif' => 'Correction CIN',
                ])
                ->assertSessionHasErrors('cin');
        }

        $this->assertSame('87654321', $etudiant->refresh()->cin);
    }

    public function test_correction_par_champ_cin_conforme_8_chiffres_appliquee(): void
    {
        $etudiant = $this->creerEtudiant(['cin' => '87654321']);

        $this->actingAs($this->adminScolarite)
            ->post(route('admin.verification.corriger', $etudiant), [
                'champ' => 'cin',
                'nouvelle_valeur' => '01234567',
                'motif' => 'CIN avec zéro initial',
            ])
            ->assertRedirect(route('admin.verification.show', $etudiant));

        $this->assertSame('01234567', $etudiant->refresh()->cin);
    }

    public function test_correction_par_champ_cin_doublon_refusee(): void
    {
        $autre = $this->creerEtudiant(['cin' => '11112222']);
        $etudiant = $this->creerEtudiant(['cin' => '87654321']);

        $this->actingAs($this->adminScolarite)
            ->post(route('admin.verification.corriger', $etudiant), [
                'champ' => 'cin',
                'nouvelle_valeur' => '11112222',
                'motif' => 'Tentative doublon',
            ])
            ->assertSessionHasErrors('cin');

        $this->assertSame('87654321', $etudiant->refresh()->cin);
        $this->assertSame($autre->id, $autre->refresh()->id);
    }

    public function test_correction_par_champ_date_naissance_texte_arbitraire_refusee(): void
    {
        $etudiant = $this->creerEtudiant();

        // Avant : texte arbitraire envoyé à une colonne DATE (exception SQL ou donnée corrompue).
        $this->actingAs($this->adminScolarite)
            ->post(route('admin.verification.corriger', $etudiant), [
                'champ' => 'date_naissance',
                'nouvelle_valeur' => 'pas-une-date',
                'motif' => 'Saisie invalide',
            ])
            ->assertSessionHasErrors('date_naissance');

        // Date future refusée.
        $this->actingAs($this->adminScolarite)
            ->post(route('admin.verification.corriger', $etudiant), [
                'champ' => 'date_naissance',
                'nouvelle_valeur' => now()->addYear()->toDateString(),
                'motif' => 'Date future',
            ])
            ->assertSessionHasErrors('date_naissance');

        $this->assertSame('2004-05-15', $etudiant->refresh()->date_naissance->toDateString());
    }

    public function test_correction_par_champ_groupe_id_fantome_refusee(): void
    {
        $etudiant = $this->creerEtudiant();

        $this->actingAs($this->adminScolarite)
            ->post(route('admin.verification.corriger', $etudiant), [
                'champ' => 'groupe_id',
                'nouvelle_valeur' => '999999',
                'motif' => 'Groupe fantôme (FK cassée avant le correctif)',
            ])
            ->assertSessionHasErrors('groupe_id');

        $this->assertNull($etudiant->refresh()->groupe_id);
    }

    public function test_correction_par_champ_groupe_id_hors_faculte_refusee(): void
    {
        $etudiant = $this->creerEtudiant();

        $programmeB = Programme::create([
            'code' => 'DROIT',
            'nom' => 'Droit',
            'faculte_id' => $this->faculteB->id,
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);

        $groupeB = Group::create([
            'code' => 'G-DROIT-1',
            'nom' => 'Groupe Droit 1',
            'programme_id' => $programmeB->id,
            'faculte_id' => $this->faculteB->id,
            'niveau' => 'L1',
            'capacite' => 30,
        ]);

        $this->actingAs($this->adminScolarite)
            ->post(route('admin.verification.corriger', $etudiant), [
                'champ' => 'groupe_id',
                'nouvelle_valeur' => (string) $groupeB->id,
                'motif' => 'Tentative de rattachement à un groupe d’une autre faculté.',
            ])
            ->assertSessionHasErrors('groupe_id');

        $this->assertNull($etudiant->refresh()->groupe_id);
    }

    public function test_correction_par_champ_email_doublon_refusee(): void
    {
        $this->creerEtudiant(['email' => 'pris@univ.tn']);
        $etudiant = $this->creerEtudiant(['email' => 'libre@univ.tn']);

        $this->actingAs($this->adminScolarite)
            ->post(route('admin.verification.corriger', $etudiant), [
                'champ' => 'email',
                'nouvelle_valeur' => 'pris@univ.tn',
                'motif' => 'Email déjà pris',
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame('libre@univ.tn', $etudiant->refresh()->email);
    }

    public function test_correction_par_champ_champ_inexistant_et_statut_transition_canonique(): void
    {
        $etudiant = $this->creerEtudiant();

        // lieu_naissance / nationalite : colonnes inexistantes — la
        // whitelist seule provoquait un 500 (colonne SQL inconnue).
        $this->actingAs($this->adminScolarite)
            ->post(route('admin.verification.corriger', $etudiant), [
                'champ' => 'lieu_naissance',
                'nouvelle_valeur' => 'Tunis',
                'motif' => 'Champ inexistant',
            ])
            ->assertSessionHasErrors('champ');

        $this->assertNull($etudiant->refresh()->nationalite);

        // statut : transition canonique (matrice + Situation + audit),
        // jamais un update brut (non mass-assignable → no-op fantôme).
        $this->actingAs($this->adminScolarite)
            ->post(route('admin.verification.corriger', $etudiant), [
                'champ' => 'statut',
                'nouvelle_valeur' => 'valide',
                'motif' => 'Dossier validé après vérification',
            ])
            ->assertRedirect(route('admin.verification.show', $etudiant));

        $this->assertSame('valide', $etudiant->refresh()->statut);

        $this->assertDatabaseHas('situations_etudiant', [
            'etudiant_id' => $etudiant->id,
            'type' => 'statut',
            'nouvelle_valeur' => 'valide',
            'motif' => 'Dossier validé après vérification',
        ]);
    }

    /**
     * LOOP saisies : le workflow 4-eyes revalide la VALEUR à
     * l'application (anti-TOCTOU) — une proposition forgée avec une
     * valeur invalide ne peut pas être appliquée.
     */
    public function test_quatre_eyes_applique_refuse_valeur_invalide(): void
    {
        $etudiant = $this->creerEtudiant(['cin' => '87654321']);
        $proposition = app(CorrectionService::class)->proposer(
            $etudiant, 'cin', 'BADCIN99', 'Proposition forgée avec valeur invalide', null, $this->adminScolarite->id,
        );
        $proposition->update(['statut' => 'approuvee']);

        try {
            app(CorrectionService::class)->appliquer($proposition, $this->adminScolarite->id);
            $this->fail('La valeur invalide aurait dû être refusée à l’application.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('CIN', $e->getMessage());
        }

        $this->assertSame('87654321', $etudiant->refresh()->cin);
        $this->assertSame('approuvee', $proposition->refresh()->statut);
    }

    public function test_quatre_eyes_applique_statut_via_transition_canonique(): void
    {
        $etudiant = $this->creerEtudiant(['statut' => 'en_attente']);
        $proposition = app(CorrectionService::class)->proposer(
            $etudiant, 'statut', 'valide', 'Dossier complet', null, $this->adminScolarite->id,
        );
        $proposition->update(['statut' => 'approuvee']);

        app(CorrectionService::class)->appliquer($proposition, $this->adminScolarite->id);

        $this->assertSame('valide', $etudiant->refresh()->statut);

        // La transition canonique trace la Situation (jamais un update
        // brut no-op + audit fantôme).
        $this->assertDatabaseHas('situations_etudiant', [
            'etudiant_id' => $etudiant->id,
            'type' => 'statut',
            'nouvelle_valeur' => 'valide',
        ]);
    }

    public function test_verification_en_masse_dispatch_job(): void
    {
        Queue::fake();

        $etudiant1 = $this->creerEtudiant();
        $etudiant2 = $this->creerEtudiant();

        $this->actingAs($this->adminScolarite)
            ->post(route('admin.verification.bulk'), [
                'etudiant_ids' => [$etudiant1->id, $etudiant2->id],
                'synchroniser_qualite' => true,
            ])
            ->assertRedirect();

        Queue::assertPushed(VerifyStudentDossierJob::class);
    }

    // ── TESTS SÉCURITÉ : FACULTY SCOPE & IDOR ──

    public function test_admin_faculte_b_ne_peut_pas_voir_etudiant_faculte_a(): void
    {
        $etudiantFacA = $this->creerEtudiant(['faculte_id' => $this->faculteA->id]);

        $this->actingAs($this->adminFaculteB)
            ->get(route('admin.verification.show', $etudiantFacA))
            ->assertForbidden();
    }

    public function test_admin_faculte_b_ne_peut_pas_corriger_etudiant_faculte_a(): void
    {
        $etudiantFacA = $this->creerEtudiant(['faculte_id' => $this->faculteA->id]);

        $this->actingAs($this->adminFaculteB)
            ->post(route('admin.verification.corriger', $etudiantFacA), [
                'champ' => 'nom',
                'nouvelle_valeur' => 'Hacker',
                'motif' => 'Attaque IDOR',
            ])
            ->assertForbidden();
    }

    // ── TESTS API V1 ──

    public function test_api_verification_et_anomalies(): void
    {
        $etudiant = $this->creerEtudiant();
        $etudiant->definirStatut('valide');

        [, $token] = $this->clientApi(['students:read']);

        $res = $this->getApi("/api/v1/students/{$etudiant->id}/verification", $token)
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'verdict', 'score', 'rules_checked', 'rules_passed', 'anomalies',
                ],
            ]);

        $this->assertSame(VerificationResult::BLOQUANT, $res->json('data.verdict'));

        // Test anomalies endpoint
        $this->getApi("/api/v1/students/{$etudiant->id}/verification/anomalies", $token)
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['code', 'categorie', 'gravite', 'message', 'recommandation'],
                ],
                'meta' => ['total', 'verdict', 'score'],
            ]);
    }

    public function test_api_bola_protection_périmètre_faculte(): void
    {
        $etudiantFacB = $this->creerEtudiant(['faculte_id' => $this->faculteB->id]);

        // Token associé à un utilisateur limité à la Faculté A
        $token = $this->adminFaculteA->createToken('test-api', ['students:read'])->plainTextToken;

        // BOLA (§17) : l'API doit renvoyer 404 strict, sans confirmer l'existence
        $this->getApi("/api/v1/students/{$etudiantFacB->id}/verification", $token)
            ->assertNotFound();
    }

    /**
     * LOOP saisies : POST students/{student}/verification doit exécuter la
     * vérification ET persiste un run audité (§21) — jamais de 500.
     */
    public function test_api_run_verification_execute_et_persiste_le_run(): void
    {
        $etudiant = $this->creerEtudiant();
        $etudiant->definirStatut('valide');

        [, $token] = $this->clientApi(['students:read']);

        $res = $this->postApi("/api/v1/students/{$etudiant->id}/verification", [], $token)
            ->assertOk()
            ->assertJsonStructure([
                'message', 'verification_id',
                'data' => ['verdict', 'score'],
            ]);

        $this->assertDatabaseHas('dossier_verifications', [
            'id' => $res->json('verification_id'),
            'source' => 'api',
        ]);
    }

    public function test_job_verification_execution_et_synchronisation(): void
    {
        $etudiant = $this->creerEtudiant();
        $etudiant->definirStatut('valide');

        // Exécution synchrone du job de vérification avec synchronisation qualité
        VerifyStudentDossierJob::dispatchSync([$etudiant->id], true, $this->adminScolarite->id);

        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $etudiant->id,
            'action' => HistoriqueAcademique::ACTION_VERIFICATION_DOSSIER,
        ]);

        $this->assertDatabaseHas('anomalies_qualite', [
            'entite_id' => $etudiant->id,
            'source' => 'verification_dossier',
        ]);
    }
}
