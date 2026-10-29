<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DossierCorrection;
use App\Models\DossierVerification;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Services\FileTraitementService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LOOP C — Catégorisation de la file de traitement (§7 automatisation).
 *
 * Catégorie DÉRIVÉE (aucun statut persisté nouveau côté étudiant) :
 * raffinement de l'action attendue existante par famille de règles en
 * échec du dernier run de vérification (codes persistés au run —
 * migration additive). Miroir strict de la priorité existante
 * (`determinerActionAttendue`) : complément > anomalie ; familles
 * spécialisées mens > identite > doublons > documents ; sinon
 * BLOQUANT→BLOCKED, A_VERIFIER générique→READY_FOR_REVIEW.
 */
class FileTraitementCategoriesTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private User $scolarite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.cat@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    public function test_en_attente_sans_signal_ready_for_review(): void
    {
        $etudiant = $this->creerEtudiant('30000001', 'en_attente');

        $this->assertCategorie($etudiant, 'READY_FOR_REVIEW');
    }

    public function test_complement_propose_missing_documents(): void
    {
        $etudiant = $this->creerEtudiant('30000002', 'en_attente');
        DossierCorrection::create([
            'etudiant_id' => $etudiant->id, 'champ' => 'email',
            'nouvelle_valeur' => 'x@y.tn', 'motif' => 'Correction proposée test',
            'statut' => 'proposee', 'propose_par' => $this->scolarite->id,
        ]);

        $this->assertCategorie($etudiant, 'MISSING_DOCUMENTS');
    }

    public function test_run_bloquant_divergence_mens_mens_review(): void
    {
        $etudiant = $this->creerEtudiant('30000003', 'en_attente');
        $this->creerRun($etudiant, 'BLOQUANT', ['mens.divergences', 'identite.complete']);

        $this->assertCategorie($etudiant, 'MENS_REVIEW');
    }

    public function test_run_averifier_identite_identity_review(): void
    {
        $etudiant = $this->creerEtudiant('30000004', 'en_attente');
        $this->creerRun($etudiant, 'A_VERIFIER', ['identite.coherence']);

        $this->assertCategorie($etudiant, 'IDENTITY_REVIEW');
    }

    public function test_run_averifier_doublon_duplicate_review(): void
    {
        $etudiant = $this->creerEtudiant('30000005', 'en_attente');
        $this->creerRun($etudiant, 'A_VERIFIER', ['doublons.detection']);

        $this->assertCategorie($etudiant, 'DUPLICATE_REVIEW');
    }

    public function test_run_averifier_documents_missing_documents(): void
    {
        $etudiant = $this->creerEtudiant('30000006', 'en_attente');
        $this->creerRun($etudiant, 'A_VERIFIER', ['documents.requis']);

        $this->assertCategorie($etudiant, 'MISSING_DOCUMENTS');
    }

    public function test_run_bloquant_autre_regle_blocked(): void
    {
        $etudiant = $this->creerEtudiant('30000007', 'en_attente');
        $this->creerRun($etudiant, 'BLOQUANT', ['inscription.coherence', 'parcours.lmd']);

        $this->assertCategorie($etudiant, 'BLOCKED');
    }

    public function test_valide_sans_groupe_ready_for_assignment_puis_assigned(): void
    {
        $etudiant = $this->creerEtudiant('30000008', 'valide');
        $this->assertCategorie($etudiant, 'READY_FOR_ASSIGNMENT');

        $groupe = Group::create([
            'code' => 'G-CAT', 'nom' => 'Groupe cat', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'actif' => true,
        ]);
        $etudiant->update(['groupe_id' => $groupe->id]);

        $this->assertCategorie($etudiant, 'ASSIGNED');
    }

    public function test_rejete_completed(): void
    {
        $etudiant = $this->creerEtudiant('30000009', 'rejete');
        $this->assertCategorie($etudiant, 'COMPLETED');
    }

    public function test_repli_run_bloquant_sans_codes_blocked(): void
    {
        // Run antérieur à la migration (codes non persistés) : repli
        // documenté sur le verdict seul.
        $etudiant = $this->creerEtudiant('30000010', 'en_attente');
        DossierVerification::create([
            'etudiant_id' => $etudiant->id, 'verdict' => 'BLOQUANT', 'score' => 30,
            'regles_verifiees' => 12, 'regles_reussies' => 10, 'anomalies_count' => 2,
            'duree_ms' => 25.0, 'source' => 'web',
        ]);

        $this->assertCategorie($etudiant, 'BLOCKED');
    }

    public function test_repli_run_averifier_sans_codes_ready_for_review(): void
    {
        $etudiant = $this->creerEtudiant('30000011', 'en_attente');
        DossierVerification::create([
            'etudiant_id' => $etudiant->id, 'verdict' => 'A_VERIFIER', 'score' => 85,
            'regles_verifiees' => 12, 'regles_reussies' => 11, 'anomalies_count' => 1,
            'duree_ms' => 25.0, 'source' => 'web',
        ]);

        $this->assertCategorie($etudiant, 'READY_FOR_REVIEW');
    }

    public function test_categorie_complete_sans_pii_et_prochaine_action(): void
    {
        $etudiant = $this->creerEtudiant('30000012', 'en_attente');
        $this->creerRun($etudiant, 'A_VERIFIER', ['doublons.detection']);

        $enrichi = $this->enrichir([$etudiant])[0];

        $this->assertSame('DUPLICATE_REVIEW', $enrichi->categorie_file);
        $this->assertNotEmpty($enrichi->categorie_libelle);
        $this->assertNotEmpty($enrichi->prochaine_action);
        $this->assertStringNotContainsString((string) $etudiant->cin, $enrichi->categorie_motif);
        $this->assertStringNotContainsString($etudiant->email, $enrichi->categorie_motif);
        $this->assertStringContainsString('doublons.detection', $enrichi->categorie_motif);
    }

    public function test_purete_categorisation_aucune_ecriture(): void
    {
        $etudiant = $this->creerEtudiant('30000013', 'en_attente');
        $this->creerRun($etudiant, 'BLOQUANT', ['mens.divergences']);
        $compteurAvant = DB::table('dossier_verifications')->count();

        $this->enrichir([$etudiant]);

        $this->assertSame($compteurAvant, DB::table('dossier_verifications')->count());
        $this->assertSame('en_attente', $etudiant->fresh()->statut);
    }

    public function test_filtre_categorie_http_scopant_la_liste(): void
    {
        $mens = $this->creerEtudiant('30000014', 'en_attente');
        $this->creerRun($mens, 'BLOQUANT', ['mens.divergences']);
        $doublon = $this->creerEtudiant('30000015', 'en_attente');
        $this->creerRun($doublon, 'A_VERIFIER', ['doublons.detection']);
        $this->creerEtudiant('30000016', 'en_attente'); // READY_FOR_REVIEW

        $reponse = $this->actingAs($this->scolarite)
            ->get(route('admin.inscriptions.file-traitement', ['categorie' => 'MENS_REVIEW']))
            ->assertOk();

        $contenu = $reponse->getContent();
        $this->assertStringContainsString($mens->nom, $contenu);
        $this->assertStringNotContainsString($doublon->nom, $contenu);

        // Filtres existants conservés : le doublon reste visible via sa catégorie.
        $this->actingAs($this->scolarite)
            ->get(route('admin.inscriptions.file-traitement', ['categorie' => 'DUPLICATE_REVIEW']))
            ->assertOk()->assertSee($doublon->nom);
    }

    public function test_filtre_ready_for_review_inclut_a_verifier_sans_famille(): void
    {
        // D-03 : le repli applicatif A_VERIFIER sans code spécialisé est
        // READY_FOR_REVIEW ; le filtre SQL doit inclure le même dossier.
        $etudiant = $this->creerEtudiant('30000017', 'en_attente');
        DossierVerification::create([
            'etudiant_id' => $etudiant->id, 'verdict' => 'A_VERIFIER', 'score' => 85,
            'regles_verifiees' => 12, 'regles_reussies' => 11, 'anomalies_count' => 1,
            'duree_ms' => 25.0, 'source' => 'web',
        ]);

        $this->actingAs($this->scolarite)
            ->get(route('admin.inscriptions.file-traitement', ['categorie' => 'READY_FOR_REVIEW']))
            ->assertOk()->assertSee($etudiant->nom);

        $this->actingAs($this->scolarite)
            ->get(route('admin.inscriptions.file-traitement', ['categorie' => 'BLOCKED']))
            ->assertOk()->assertDontSee($etudiant->nom);
    }

    public function test_filtres_sql_categories_ne_se_chevauchent_pas(): void
    {
        // Complément + MENS : le complément est prioritaire (MISSING_DOCUMENTS),
        // le dossier ne doit pas apparaître aussi dans MENS_REVIEW.
        $complementMens = $this->creerEtudiant('30000018', 'en_attente');
        DossierCorrection::create([
            'etudiant_id' => $complementMens->id, 'champ' => 'email',
            'nouvelle_valeur' => 'x@y.tn', 'motif' => 'Correction proposée test',
            'statut' => 'proposee', 'propose_par' => $this->scolarite->id,
        ]);
        $this->creerRun($complementMens, 'BLOQUANT', ['mens.divergences']);

        $this->actingAs($this->scolarite)
            ->get(route('admin.inscriptions.file-traitement', ['categorie' => 'MISSING_DOCUMENTS']))
            ->assertOk()->assertSee($complementMens->nom);
        $this->actingAs($this->scolarite)
            ->get(route('admin.inscriptions.file-traitement', ['categorie' => 'MENS_REVIEW']))
            ->assertOk()->assertDontSee($complementMens->nom);

        // MENS + identité : MENS est prioritaire.
        $mensIdentite = $this->creerEtudiant('30000019', 'en_attente');
        $this->creerRun($mensIdentite, 'BLOQUANT', ['mens.divergences', 'identite.complete']);

        $this->actingAs($this->scolarite)
            ->get(route('admin.inscriptions.file-traitement', ['categorie' => 'MENS_REVIEW']))
            ->assertOk()->assertSee($mensIdentite->nom);
        $this->actingAs($this->scolarite)
            ->get(route('admin.inscriptions.file-traitement', ['categorie' => 'IDENTITY_REVIEW']))
            ->assertOk()->assertDontSee($mensIdentite->nom);

        // Identité + documents : identité est prioritaire.
        $identiteDocuments = $this->creerEtudiant('30000020', 'en_attente');
        $this->creerRun($identiteDocuments, 'A_VERIFIER', ['identite.coherence', 'documents.requis']);

        $this->actingAs($this->scolarite)
            ->get(route('admin.inscriptions.file-traitement', ['categorie' => 'IDENTITY_REVIEW']))
            ->assertOk()->assertSee($identiteDocuments->nom);
        $this->actingAs($this->scolarite)
            ->get(route('admin.inscriptions.file-traitement', ['categorie' => 'MISSING_DOCUMENTS']))
            ->assertOk()->assertDontSee($identiteDocuments->nom);
    }

    public function test_filtre_categorie_invalide_refuse(): void
    {
        $this->actingAs($this->scolarite)
            ->get(route('admin.inscriptions.file-traitement', ['categorie' => 'CATEGORIE_INCONNUE']))
            ->assertRedirect();
    }

    /** Teste la catégorie d'un étudiant après enrichissement. */
    private function assertCategorie(Etudiant $etudiant, string $categorieAttendue): void
    {
        $enrichi = $this->enrichir([$etudiant])[0];
        $this->assertSame(
            $categorieAttendue,
            $enrichi->categorie_file,
            "Catégorie attendue {$categorieAttendue}, obtenue ".($enrichi->categorie_file ?? 'null')
        );
    }

    /**
     * @param  array<int, Etudiant>  $etudiants
     * @return array<int, Etudiant>
     */
    private function enrichir(array $etudiants): array
    {
        $collection = new Collection($etudiants);
        app(FileTraitementService::class)->enrichirEtudiants($collection);

        return $collection->all();
    }

    private function creerRun(Etudiant $etudiant, string $verdict, array $reglesEchouees): DossierVerification
    {
        return DossierVerification::create([
            'etudiant_id' => $etudiant->id, 'verdict' => $verdict, 'score' => 60,
            'regles_verifiees' => 12, 'regles_reussies' => 12 - count($reglesEchouees),
            'anomalies_count' => count($reglesEchouees), 'duree_ms' => 25.0, 'source' => 'web',
            'regles_echouees' => $reglesEchouees,
        ]);
    }

    private function creerEtudiant(string $cin, string $statut): Etudiant
    {
        $programme = Programme::firstOrCreate(
            ['code' => 'LGL-CAT', 'faculte_id' => $this->faculte->id],
            ['nom' => 'Licence GL', 'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180]
        );
        $niveau = Niveau::firstOrCreate(
            ['programme_id' => $programme->id, 'code' => 'L1'],
            ['nom' => 'L1', 'ordre' => 1]
        );

        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => 'Categorie'.$cin, 'prenom' => 'Test', 'email' => mb_strtolower($cin).'@cat.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'programme_id' => $programme->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        // `statut` n'est pas mass-assignable (audit §P3-17) — setter dédié.
        $etudiant->definirStatut($statut);

        $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $programme->id, 'niveau_id' => $niveau->id,
            'niveau' => 'L1', 'type' => 'premiere', 'statut' => 'en_cours',
        ]);

        return $etudiant->fresh();
    }
}
