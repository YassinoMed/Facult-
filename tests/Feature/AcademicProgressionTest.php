<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Services\AcademicProgressionService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Progression LMD (§15) : décision publiée → cible référentiel.
 * Jamais d'incrément aveugle, jamais de Master deviné.
 */
class AcademicProgressionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $anneeN;

    private AnneeUniversitaire $anneeN1;

    private Programme $licence;

    private Programme $master;

    /** @var array<string, Niveau> */
    private array $niveaux = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.prog@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->anneeN = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-06-30', 'active' => false,
        ]);
        $this->anneeN1 = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $this->licence = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LIC', 'nom' => 'Licence',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $this->master = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'MAS', 'nom' => 'Master',
            'duree_annees' => 2, 'semestres_nombre' => 4, 'credits_requis' => 120,
        ]);

        foreach (['L1' => 1, 'L2' => 2, 'L3' => 3] as $code => $ordre) {
            $this->niveaux[$code] = Niveau::create([
                'programme_id' => $this->licence->id, 'code' => $code, 'nom' => $code, 'ordre' => $ordre,
            ]);
        }

        foreach (['M1' => 1, 'M2' => 2] as $code => $ordre) {
            $this->niveaux[$code] = Niveau::create([
                'programme_id' => $this->master->id, 'code' => $code, 'nom' => $code, 'ordre' => $ordre,
            ]);
        }
    }

    public function test_l1_vers_l2_et_l2_vers_l3(): void
    {
        foreach (['L1' => 'L2', 'L2' => 'L3'] as $source => $cible) {
            $etudiant = $this->creerEtudiant('PRG-'.$source);
            $inscription = $this->inscrire($etudiant, $source, $this->licence->id);
            $this->decider($etudiant, $inscription, 'admis');

            $service = app(AcademicProgressionService::class);
            $evaluation = $service->evaluer($etudiant, $inscription);

            $this->assertTrue($evaluation['peut_progresser']);
            $this->assertSame('progresser', $evaluation['action']);
            $this->assertSame($cible, $evaluation['niveau_cible']->code);

            $nouvelle = $service->appliquer($etudiant, $inscription, $this->admin->id);

            $this->assertSame($this->anneeN1->id, $nouvelle->annee_universitaire_id);
            $this->assertSame($this->niveaux[$cible]->id, $nouvelle->niveau_id);
            $this->assertSame('en_cours', $nouvelle->statut);
        }
    }

    public function test_l3_admis_sans_cible_ne_cree_rien(): void
    {
        $etudiant = $this->creerEtudiant('PRG-L3');
        $inscription = $this->inscrire($etudiant, 'L3', $this->licence->id);
        $this->decider($etudiant, $inscription, 'admis');

        $service = app(AcademicProgressionService::class);
        $evaluation = $service->evaluer($etudiant, $inscription);

        $this->assertFalse($evaluation['peut_progresser']);
        $this->assertSame('fin_cycle', $evaluation['action']);

        $this->expectException(DomainException::class);
        $service->appliquer($etudiant, $inscription, $this->admin->id);

        $this->assertSame(1, $etudiant->inscriptionsAcademiques()->count());
    }

    public function test_l3_admis_avec_admission_master_vers_m1(): void
    {
        $etudiant = $this->creerEtudiant('PRG-MAS');
        $inscription = $this->inscrire($etudiant, 'L3', $this->licence->id);
        $this->decider($etudiant, $inscription, 'admis');

        $nouvelle = app(AcademicProgressionService::class)->appliquer(
            $etudiant, $inscription, $this->admin->id,
            ['programme_id' => $this->master->id, 'niveau_id' => $this->niveaux['M1']->id],
        );

        $this->assertSame($this->master->id, $nouvelle->programme_id);
        $this->assertSame($this->niveaux['M1']->id, $nouvelle->niveau_id);
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $etudiant->id, 'action' => 'creation_inscription',
        ]);
        $this->assertDatabaseHas('notifications', [
            'etudiant_id' => $etudiant->id, 'type' => 'inscription.progression',
        ]);
    }

    public function test_m1_vers_m2(): void
    {
        $etudiant = $this->creerEtudiant('PRG-M1');
        $inscription = $this->inscrire($etudiant, 'M1', $this->master->id);
        $this->decider($etudiant, $inscription, 'admis_compensation');

        $evaluation = app(AcademicProgressionService::class)->evaluer($etudiant, $inscription);

        $this->assertTrue($evaluation['peut_progresser']);
        $this->assertSame('M2', $evaluation['niveau_cible']->code);
    }

    #[DataProvider('fournisseurRedoublement')]
    public function test_redoublement_conserve_niveau(string $decision, string $niveau): void
    {
        $etudiant = $this->creerEtudiant('PRG-R-'.$niveau.'-'.$decision);
        $inscription = $this->inscrire($etudiant, $niveau, $this->licence->id);
        $this->decider($etudiant, $inscription, $decision);

        $service = app(AcademicProgressionService::class);
        $evaluation = $service->evaluer($etudiant, $inscription);

        $this->assertSame('redoubler', $evaluation['action']);
        $nouvelle = $service->appliquer($etudiant, $inscription, $this->admin->id);

        $this->assertSame($this->niveaux[$niveau]->id, $nouvelle->niveau_id);
        $this->assertSame($this->anneeN1->id, $nouvelle->annee_universitaire_id);
    }

    /** @return array<string, array{string, string}> */
    public static function fournisseurRedoublement(): array
    {
        return [
            'ajourné L1' => ['ajourne', 'L1'],
            'rattrapage L2' => ['rattrapage', 'L2'],
            'redoublant M1' => ['redouble', 'L1'],
        ];
    }

    #[DataProvider('fournisseurBlocage')]
    public function test_decisions_bloquantes(string $decision): void
    {
        $etudiant = $this->creerEtudiant('PRG-B-'.$decision);
        $inscription = $this->inscrire($etudiant, 'L2', $this->licence->id);
        $this->decider($etudiant, $inscription, $decision);

        $evaluation = app(AcademicProgressionService::class)->evaluer($etudiant, $inscription);

        $this->assertFalse($evaluation['peut_progresser']);

        $this->expectException(DomainException::class);
        app(AcademicProgressionService::class)->appliquer($etudiant, $inscription, $this->admin->id);
    }

    /** @return array<string, array{string}> */
    public static function fournisseurBlocage(): array
    {
        return ['exclu' => ['exclu'], 'à examiner' => ['a_examiner']];
    }

    public function test_sans_decision_pas_de_progression(): void
    {
        $etudiant = $this->creerEtudiant('PRG-NODEC');
        $inscription = $this->inscrire($etudiant, 'L1', $this->licence->id);

        $evaluation = app(AcademicProgressionService::class)->evaluer($etudiant, $inscription);

        $this->assertFalse($evaluation['peut_progresser']);
        $this->assertNotEmpty($evaluation['blocages']);
    }

    public function test_idempotence_double_application(): void
    {
        $etudiant = $this->creerEtudiant('PRG-IDEM');
        $inscription = $this->inscrire($etudiant, 'L1', $this->licence->id);
        $this->decider($etudiant, $inscription, 'admis');

        $service = app(AcademicProgressionService::class);
        $premiere = $service->appliquer($etudiant, $inscription, $this->admin->id);
        $seconde = $service->appliquer($etudiant, $inscription, $this->admin->id);

        $this->assertSame($premiere->id, $seconde->id);
        $this->assertSame(1, $etudiant->inscriptionsAcademiques()
            ->where('annee_universitaire_id', $this->anneeN1->id)->count());
    }

    public function test_cibles_incoherentes_refusees(): void
    {
        $etudiant = $this->creerEtudiant('PRG-INC');
        $inscription = $this->inscrire($etudiant, 'L3', $this->licence->id);
        $this->decider($etudiant, $inscription, 'admis');

        // Niveau M1 dans programme Licence : incohérent.
        $this->expectException(DomainException::class);
        app(AcademicProgressionService::class)->appliquer(
            $etudiant, $inscription, $this->admin->id,
            ['programme_id' => $this->licence->id, 'niveau_id' => $this->niveaux['M1']->id],
        );
    }

    public function test_cibles_exigent_decision_admis(): void
    {
        $etudiant = $this->creerEtudiant('PRG-NAD');
        $inscription = $this->inscrire($etudiant, 'L2', $this->licence->id);
        $this->decider($etudiant, $inscription, 'ajourne');

        $this->expectException(DomainException::class);
        app(AcademicProgressionService::class)->appliquer(
            $etudiant, $inscription, $this->admin->id,
            ['programme_id' => $this->master->id, 'niveau_id' => $this->niveaux['M1']->id],
        );
    }

    public function test_cross_faculty_et_idor(): void
    {
        $autreFaculte = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'T', 'active' => true]);
        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.prog@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $autreFaculte->id,
        ], 'admin_faculte');

        $etudiant = $this->creerEtudiant('PRG-SEC');
        $inscription = $this->inscrire($etudiant, 'L1', $this->licence->id);
        $this->decider($etudiant, $inscription, 'admis');

        // Hors faculté : refusé (convention du contrôleur : 403 policy,
        // la route exige déjà archives.create + update sur l'inscription).
        $this->actingAs($adminFaculte)->post(route('admin.inscriptions.progresser', $inscription), [
            'confirmer' => 1,
        ])->assertForbidden();

        // Inscription d'un autre étudiant : 404 via le service.
        $autre = $this->creerEtudiant('PRG-SEC2');
        $this->expectException(HttpException::class);
        app(AcademicProgressionService::class)->evaluer($autre, $inscription);
    }

    public function test_route_progresser_bout_en_bout(): void
    {
        $etudiant = $this->creerEtudiant('PRG-E2E');
        $inscription = $this->inscrire($etudiant, 'L2', $this->licence->id);
        $this->decider($etudiant, $inscription, 'admis');

        $this->actingAs($this->admin)->post(route('admin.inscriptions.progresser', $inscription), [
            'confirmer' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('inscriptions_academiques', [
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->anneeN1->id,
            'niveau_id' => $this->niveaux['L3']->id,
        ]);
    }

    private function creerEtudiant(string $cin): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => strtolower($cin).'@prog.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2025-2026', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');

        return $etudiant;
    }

    private function inscrire(Etudiant $etudiant, string $niveau, int $programmeId): InscriptionAcademique
    {
        $inscription = $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->anneeN->id,
            'programme_id' => $programmeId,
            'niveau_id' => $this->niveaux[$niveau]->id,
            'niveau' => $niveau,
            'statut' => 'en_cours',
        ]);
        $inscription->semestres()->create(['numero' => 1]);
        $inscription->semestres()->create(['numero' => 2]);

        return $inscription;
    }

    private function decider(Etudiant $etudiant, InscriptionAcademique $inscription, string $decision): void
    {
        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $this->anneeN->id,
            'statut' => 'published',
            'created_by' => $this->admin->id,
            'published_at' => now(),
        ]);
        DecisionDeliberation::create([
            'deliberation_id' => $deliberation->id,
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'moyenne' => 12.0,
            'credits_obtenus' => 30,
            'credits_total' => 30,
            'decision' => $decision,
            'publie' => true,
        ]);
    }
}
