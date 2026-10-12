<?php

namespace Tests\Feature;

use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Referentiel\Services\MatiereStandaloneService;
use App\Services\DataExchange\DataImportService;
use App\Services\NormalisationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Consolidation imports référentiel (LOOP A) : forme comparable unique
 * (divergence accents MySQL/PHP résorbée), références inconnues
 * fail-closed, résolution programme canonique avec faculté.
 */
class ReferentielNormalisationImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculteA;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.referentiel.active' => true]);

        $this->faculteA = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.norma@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    public function test_nom_comparable_neutralise_accents(): void
    {
        $service = app(NormalisationService::class);

        $this->assertSame(
            $service->nomComparable('Bases de données'),
            $service->nomComparable('bases de DONNEES')
        );
        $this->assertNotSame(
            $service->nomComparable('Algorithmique'),
            $service->nomComparable('Base de données')
        );
    }

    public function test_classifieurs_convergent_accents(): void
    {
        // Motif INFO201 : jumeau accentué côté rattaché, brut côté legacy.
        $niveau = Niveau::create([
            'programme_id' => Programme::create([
                'faculte_id' => $this->faculteA->id, 'code' => 'LGL', 'nom' => 'Licence GL',
                'statut' => 'actif', 'actif' => true,
            ])->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1, 'actif' => true,
        ]);
        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1',
            'numero' => 1, 'ordre' => 1, 'actif' => true,
        ]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $niveau->programme_id,
            'code' => 'UE01', 'nom' => 'UE 01', 'credits' => 6, 'actif' => true,
        ]);
        Matiere::create([
            'code' => 'EC-RAT', 'nom' => 'Bases de données', 'credits' => 4, 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'actif' => true,
        ]);
        Matiere::create([
            'code' => 'INFO201', 'nom' => 'Bases de donnees', 'credits' => 4,
            'niveau' => 'L1', 'semestre' => 1, 'actif' => true,
        ]);

        // Inventaire : DOCUMENTEE des deux côtés de l'accent.
        $inventaire = app(MatiereStandaloneService::class)->inventaire();
        $ligne = collect($inventaire['matieres'])->firstWhere('code', 'INFO201');
        $this->assertSame('DOCUMENTEE', $ligne['categorie']);

        // Backfill dry-run : même verdict DUPLICATE (convergence).
        Artisan::call('referentiel:backfill');
        $sortie = Artisan::output();
        $this->assertStringContainsString('DUPLICATE', $sortie);
        $this->assertStringContainsString('INFO201', $sortie);
    }

    public function test_specialite_diplome_inconnus_bloques(): void
    {
        $service = app(DataImportService::class);
        $fichier = fn (string $contenu) => UploadedFile::fake()->createWithContent('programmes.csv', $contenu, 'text/csv');

        foreach ([
            "code;nom;faculte;specialite\nPXA;Programme A;FST;SPE-XXX\n" => 'SPECIALITE_INTROUVABLE',
            "code;nom;faculte;diplome\nPXA;Programme A;FST;DIP-XXX\n" => 'DIPLOME_INTROUVABLE',
        ] as $csv => $code) {
            $preparation = $service->preparer($fichier($csv), 'programmes', $this->admin->id);

            $trouve = false;
            foreach ($preparation['resultat']->erreurs as $erreur) {
                if (($erreur['code'] ?? null) === $code) {
                    $trouve = true;
                }
            }

            $this->assertTrue($trouve, "Code {$code} absent.");
        }

        $this->assertSame(0, Programme::count());
    }

    public function test_resolution_programme_canonique(): void
    {
        $faculteB = Faculte::create(['nom' => 'FDL', 'code' => 'FDL', 'active' => true]);
        foreach ([$this->faculteA, $faculteB] as $faculte) {
            Programme::create([
                'faculte_id' => $faculte->id, 'code' => 'DUPL', 'nom' => 'Doublon '.$faculte->code,
                'statut' => 'actif', 'actif' => true,
            ]);
        }

        // Sans faculté : repli historique (premier résultat).
        $this->assertNotNull(Programme::resoudreParCode('dupl'));

        // Avec faculté : levée d'ambiguïté exacte.
        $this->assertSame(
            $faculteB->id,
            Programme::resoudreParCode('DUPL', 'FDL')?->faculte_id
        );
        $this->assertSame(
            $this->faculteA->id,
            Programme::resoudreParCode('DUPL', 'FST')?->faculte_id
        );

        // Faculté inconnue : fail-closed (null, jamais un hasard).
        $this->assertNull(Programme::resoudreParCode('DUPL', 'ZZZ'));
        $this->assertNull(Programme::resoudreParCode(''));
    }

    public function test_import_ue_faculte_desambigue(): void
    {
        $faculteB = Faculte::create(['nom' => 'FDL', 'code' => 'FDL', 'active' => true]);

        foreach ([$this->faculteA, $faculteB] as $faculte) {
            $programme = Programme::create([
                'faculte_id' => $faculte->id, 'code' => 'DUPL', 'nom' => 'Doublon '.$faculte->code,
                'statut' => 'actif', 'actif' => true,
            ]);
            $niveau = Niveau::create([
                'programme_id' => $programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1, 'actif' => true,
            ]);
            SemestreLmd::create([
                'niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1',
                'numero' => 1, 'ordre' => 1, 'actif' => true,
            ]);
        }

        $service = app(DataImportService::class);
        $csv = "code;semestre;programme;faculte;nom;credits\nUE-X;S1;DUPL;FDL;UE X;6\n";
        $preparation = $service->preparer(
            UploadedFile::fake()->createWithContent('ues.csv', $csv, 'text/csv'), 'ues', $this->admin->id
        );
        $service->confirmer($preparation['token'], 'creer', $this->admin->id);

        $creee = UniteEnseignement::where('code', 'UE-X')->firstOrFail();
        $this->assertSame($faculteB->id, (int) $creee->programme->faculte_id);
    }
}
