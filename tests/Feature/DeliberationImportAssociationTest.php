<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\User;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use Database\Seeders\NotesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Import « Délibérations et décisions de jury » (entité `deliberations`) :
 * les fichiers d'exemple de docs/evaluation/ se résolvent contre le jeu
 * démo et s'importent réellement. Ligne `code` → délibération (statut
 * déduit) ; ligne `decision` → délibération parente publiée + décision
 * `publie=true`, donc visible sur le portail étudiant.
 */
class DeliberationImportAssociationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.delib@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        // Référentiel minimal aligné sur le jeu démo (DEMO-LGL / L1 / S1-S2).
        $this->programme = Programme::create([
            'faculte_id' => $faculte->id, 'code' => 'DEMO-LGL', 'nom' => 'Licence Génie Logiciel (démo)',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niveau = Niveau::create(['programme_id' => $this->programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1]);
        SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => 'S2', 'nom' => 'S2', 'numero' => 2, 'ordre' => 2]);

        // 12 étudiants démo (MAT-DEMO-001…012), inscriptions + semestres, matières DEMO*.
        $this->artisan('db:seed', ['--class' => NotesDemoSeeder::class]);

        SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'DEMO-S1', 'nom' => 'Session principale (démo)', 'type' => 'principale',
            'date_debut' => '2027-01-05', 'date_fin' => '2027-01-20', 'statut' => 'open',
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_exemple_deliberations_par_code_importable(): void
    {
        $this->importerFichier('docs/evaluation/exemple-deliberations.csv');

        $s1 = Deliberation::where('code', 'DELIB-DEMO-L1-S1')->firstOrFail();
        $this->assertSame('published', $s1->statut);
        $this->assertSame($this->annee->id, $s1->annee_universitaire_id);
        $this->assertSame($this->programme->id, $s1->programme_id);
        $this->assertSame('L1', Niveau::findOrFail($s1->niveau_id)->code);
        $this->assertSame('S1', SemestreLmd::findOrFail($s1->semestre_lmd_id)->code);

        $this->assertSame('prepared', Deliberation::where('code', 'DELIB-DEMO-L1-S2')->firstOrFail()->statut);
    }

    public function test_exemple_decisions_jury_importable_et_publiees_portail(): void
    {
        $this->importerFichier('docs/evaluation/exemple-decisions-jury.csv', [
            'SESSION_NOT_FOUND', 'PROGRAMME_NOT_FOUND', 'STUDENT_NOT_FOUND',
        ]);

        $session = SessionExamen::where('code', 'DEMO-S1')->firstOrFail();
        $deliberation = Deliberation::where('session_id', $session->id)
            ->where('programme_id', $this->programme->id)
            ->firstOrFail();

        $this->assertSame('published', $deliberation->statut);
        $this->assertNotNull($deliberation->published_at);
        $this->assertSame($this->annee->id, $deliberation->annee_universitaire_id);
        $this->assertSame(9, DecisionDeliberation::where('deliberation_id', $deliberation->id)->count());
        $this->assertSame(
            9,
            DecisionDeliberation::where('deliberation_id', $deliberation->id)->where('publie', true)->count()
        );

        $admis = Etudiant::where('cin', '12000109')->firstOrFail();
        $decision = DecisionDeliberation::where('deliberation_id', $deliberation->id)
            ->where('etudiant_id', $admis->id)->firstOrFail();
        $this->assertSame('admis', $decision->decision);
        $this->assertSame('15.00', (string) $decision->moyenne);
        $this->assertSame(30, $decision->credits_obtenus);
        $this->assertNotNull($decision->inscription_academique_id);

        $rattrapage = DecisionDeliberation::where('deliberation_id', $deliberation->id)
            ->where('etudiant_id', Etudiant::where('cin', '12000103')->value('id'))->firstOrFail();
        $this->assertSame('rattrapage', $rattrapage->decision);
        $this->assertSame(0, $rattrapage->credits_obtenus);

        // Portail : décision publiée + note → la matière est visible.
        $this->creerNote($admis, 15);

        $this->withSession(['etudiant_auth_id' => $admis->id])
            ->get('/etudiant/notes')->assertOk()->assertSee('Algorithmique (démo)');

        // Témoin : note sans décision publiée → portail toujours masqué.
        $temoin = Etudiant::where('cin', '12000111')->firstOrFail();
        $this->creerNote($temoin, 12);

        $this->withSession(['etudiant_auth_id' => $temoin->id])
            ->get('/etudiant/notes')->assertOk()->assertSee('Aucune note enregistrée pour le moment.');
    }

    public function test_decision_sans_credits_obtenus_stocke_zero(): void
    {
        // Régression : cellule crédits vide → 0 (colonne NOT NULL — un NULL
        // provoquerait une erreur SQL 1048 à la confirmation).
        $this->importerContenu(
            'deliberations',
            "session;programme;niveau;matricule;cin;moyenne;credits_obtenus;decision\n"
            ."DEMO-S1;DEMO-LGL;L1;MAT-DEMO-010;12000110;8;;admis\n",
            'decisions-sans-credits.csv',
        );

        $decision = DecisionDeliberation::where('etudiant_id', Etudiant::where('cin', '12000110')->value('id'))->firstOrFail();
        $this->assertSame(0, $decision->credits_obtenus);
        $this->assertSame('admis', $decision->decision);
    }

    public function test_decision_jury_sans_credits_obtenus_stocke_zero(): void
    {
        Deliberation::create([
            'code' => 'DELIB-JURY-TEST', 'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id, 'statut' => 'draft', 'created_by' => $this->admin->id,
        ]);

        $this->importerContenu(
            'decisions-jury',
            "deliberation_code;etudiant_matricule;decision\nDELIB-JURY-TEST;MAT-DEMO-010;admis\n",
            'decisions-jury-sans-credits.csv',
        );

        $decision = DecisionDeliberation::where('etudiant_id', Etudiant::where('cin', '12000110')->value('id'))->firstOrFail();
        $this->assertSame(0, $decision->credits_obtenus);
        $this->assertSame('admis', $decision->decision);
    }

    private function creerNote(Etudiant $etudiant, float $valeur): void
    {
        $inscription = $etudiant->inscriptionsAcademiques()
            ->where('annee_universitaire_id', $this->annee->id)->firstOrFail();
        $semestre = $inscription->semestres()->where('numero', 1)->firstOrFail();

        Note::create([
            'etudiant_id' => $etudiant->id, 'inscription_academique_id' => $inscription->id,
            'semestre_id' => $semestre->id, 'matiere_id' => Matiere::where('code', 'DEMO101')->value('id'),
            'note' => $valeur, 'coefficient' => 2, 'credits' => 6, 'session' => 'normale',
        ]);
    }

    /** @param array<int, string> $codesRefuses */
    private function importerFichier(string $chemin, array $codesRefuses = []): void
    {
        $contenu = file_get_contents(base_path($chemin));
        $this->assertNotFalse($contenu);

        $this->importerContenu('deliberations', (string) $contenu, basename($chemin), $codesRefuses);
    }

    /** @param array<int, string> $codesRefuses */
    private function importerContenu(string $entite, string $contenu, string $nom, array $codesRefuses = []): void
    {
        $reponse = $this->actingAs($this->admin)
            ->post("/admin/donnees/{$entite}/import/preview", [
                'fichier' => UploadedFile::fake()->createWithContent($nom, $contenu, 'text/csv'),
            ])
            ->assertOk();

        $page = (string) $reponse->getContent();
        foreach ($codesRefuses as $code) {
            $this->assertStringNotContainsString($code, $page);
        }

        $token = $reponse->viewData('token');
        $this->assertNotEmpty($token);

        $this->actingAs($this->admin)
            ->post("/admin/donnees/{$entite}/import/confirmer", ['token' => $token, 'mode' => 'creer'])
            ->assertOk();
    }
}
