<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Services\FileTraitementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP D — Métriques d'automatisation dérivées (§14) : taux d'affectation
 * automatique réussie (audits `affectation.auto_assignee` /
 * `auto_non_assignee`) + volume de changements de groupe (`changee`).
 *
 * Invariants :
 * - Agrégats SANS PII (comptages purs, scopés faculté).
 * - Aucune nouvelle table : sources = `historique_academique` (append-only).
 * - Ensemble vide = taux `null` explicite (jamais 0 % trompeur).
 * - « Taux de changements nécessitant une intervention » : NOT_APPLICABLE
 *   par design — tout changement de groupe EST une décision humaine
 *   (les refus ne s'auditent pas : aucune écriture).
 */
class FileTraitementMetriquesTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private User $scolarite;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteA = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'M', 'active' => true]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.metriques@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    public function test_taux_affectation_auto_calcule_et_sans_pii(): void
    {
        $a = $this->creerEtudiant('40000001');
        $b = $this->creerEtudiant('40000002');
        $c = $this->creerEtudiant('40000003');

        $this->auditer($a, 'affectation.auto_assignee');
        $this->auditer($b, 'affectation.auto_assignee');
        $this->auditer($c, 'affectation.auto_non_assignee');

        $kpis = app(FileTraitementService::class)->kpis($this->scolarite);

        $this->assertSame(2, $kpis['affectations_auto']['reussies']);
        $this->assertSame(1, $kpis['affectations_auto']['non_assignees']);
        $this->assertSame(3, $kpis['affectations_auto']['tentatives']);
        $this->assertEqualsWithDelta(66.7, $kpis['affectations_auto']['taux_reussite'], 0.1);

        // Agrégats purs : aucune valeur personnelle ne traverse.
        $this->assertStringNotContainsString($a->cin, json_encode($kpis['affectations_auto']));
    }

    public function test_aucune_tentative_taux_null_explicite(): void
    {
        $kpis = app(FileTraitementService::class)->kpis($this->scolarite);

        $this->assertSame(0, $kpis['affectations_auto']['tentatives']);
        $this->assertNull($kpis['affectations_auto']['taux_reussite'], 'Jamais 0 % trompeur sur ensemble vide.');
    }

    public function test_changements_groupe_comptes_et_scopes_faculte(): void
    {
        $dansFaculte = $this->creerEtudiant('40000004');
        $autreFaculte = $this->creerEtudiant('40000005', $this->faculteB);

        $this->auditer($dansFaculte, 'affectation.changee');
        $this->auditer($dansFaculte, 'affectation.changee');
        $this->auditer($autreFaculte, 'affectation.changee'); // hors périmètre

        // Global (admin scolarité) : tous visibles.
        $kpis = app(FileTraitementService::class)->kpis($this->scolarite);
        $this->assertSame(3, $kpis['changements_groupe']['effectues']);

        // Restreint à la faculté A : les changements de B exclus.
        // (`faculte_id` dans les attributs — la fabrique ne prend que 2 arguments.)
        $adminFaculteA = User::creerCompteBackOffice([
            'name' => 'Admin A', 'email' => 'admin.a.met@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');
        $kpisA = app(FileTraitementService::class)->kpis($adminFaculteA);

        $this->assertSame(2, $kpisA['changements_groupe']['effectues']);
        $this->assertSame(0, $kpisA['affectations_auto']['tentatives']);
    }

    private function auditer(Etudiant $etudiant, string $action): void
    {
        HistoriqueAcademique::create([
            'etudiant_id' => $etudiant->id,
            'action' => $action,
            'entite' => 'etudiant',
            'entite_id' => $etudiant->id,
            'source_systeme' => 'web',
        ]);
    }

    private function creerEtudiant(string $cin, ?Faculte $faculte = null): Etudiant
    {
        $faculte ??= $this->faculteA;
        $programme = Programme::firstOrCreate(
            ['code' => 'LGL-MET', 'faculte_id' => $faculte->id],
            ['nom' => 'Licence GL', 'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180]
        );
        $niveau = Niveau::firstOrCreate(
            ['programme_id' => $programme->id, 'code' => 'L1'],
            ['nom' => 'L1', 'ordre' => 1]
        );

        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Metriques'.$cin, 'prenom' => 'Test',
            'email' => mb_strtolower($cin).'@met.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $faculte->id,
            'programme_id' => $programme->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $programme->id, 'niveau_id' => $niveau->id,
            'niveau' => 'L1', 'type' => 'premiere', 'statut' => 'en_cours',
        ]);

        return $etudiant->fresh();
    }
}
