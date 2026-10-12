<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\MaquetteUeEcue;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Services\DataExchange\DataImportService;
use App\Services\Io\IoException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Import des plans d'études (LOOP B6) : brouillon alimenté ou créé,
 * gardes INV-1/INV-5/régime, verrou respecté, idempotence par clé
 * unique, périmètre faculté — jamais d'approbation ni d'activation.
 */
class MaquettePlanImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculteA;

    private Programme $programme;

    private AnneeUniversitaire $annee;

    private UniteEnseignement $ue;

    private Matiere $ecue;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.referentiel.active' => true]);

        $this->faculteA = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.plan@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->programme = Programme::create([
            'faculte_id' => $this->faculteA->id, 'code' => 'PLN', 'nom' => 'Programme plan',
            'statut' => 'actif', 'actif' => true,
        ]);
        $niveau = Niveau::create([
            'programme_id' => $this->programme->id, 'code' => 'L1', 'nom' => 'L1',
            'ordre' => 1, 'actif' => true,
        ]);
        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1',
            'numero' => 1, 'ordre' => 1, 'actif' => true,
        ]);
        $this->ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $this->programme->id,
            'code' => 'UE-PLN', 'nom' => 'UE plan', 'credits' => 6, 'actif' => true,
        ]);
        $this->ecue = Matiere::create([
            'code' => 'EC-PLN', 'nom' => 'ECUE plan', 'credits' => 3, 'ue_id' => $this->ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'volume_cm' => 20, 'actif' => true,
        ]);
    }

    private function csv(array $lignes): string
    {
        return "programme;annee;ue;ecue;credits;volume_cm;regime\n".implode("\n", $lignes)."\n";
    }

    private function preparer(string $contenu): array
    {
        return app(DataImportService::class)->preparer(
            UploadedFile::fake()->createWithContent('plan.csv', $contenu, 'text/csv'),
            'maquettes-plan', $this->admin->id
        );
    }

    public function test_import_cree_brouillon_sans_activer(): void
    {
        $preparation = $this->preparer($this->csv(['PLN;2026-2027;UE-PLN;EC-PLN;5;30;obligatoire']));
        $this->assertFalse($preparation['resultat']->bloque());

        app(DataImportService::class)->confirmer($preparation['token'], 'creer', $this->admin->id);

        $maquette = Maquette::where('programme_id', $this->programme->id)->firstOrFail();
        $this->assertSame('draft', $maquette->statut);
        $this->assertSame(1, $maquette->version);

        $occurrence = MaquetteUeEcue::firstOrFail();
        $this->assertSame(5, (int) $occurrence->credits);
        $this->assertSame(30, (int) $occurrence->volume_cm);
        $this->assertSame('obligatoire', $occurrence->regime);

        // Matière canonique intacte (valeurs du plan seules).
        $this->assertSame(3, (int) $this->ecue->fresh()->credits);
    }

    public function test_reimport_idempotent(): void
    {
        $csv = $this->csv(['PLN;2026-2027;UE-PLN;EC-PLN;5;30;obligatoire']);
        $premier = $this->preparer($csv);
        app(DataImportService::class)->confirmer($premier['token'], 'creer', $this->admin->id);

        $second = $this->preparer($csv);

        $miseAJour = false;
        foreach ($second['resultat']->avertissements as $avertissement) {
            if (str_contains((string) $avertissement, 'mise à jour')) {
                $miseAJour = true;
            }
        }

        $this->assertTrue($miseAJour, 'Reclassification mise à jour absente.');
        app(DataImportService::class)->confirmer($second['token'], 'upsert', $this->admin->id);

        $this->assertSame(1, MaquetteUeEcue::count());
        $this->assertSame(1, Maquette::count());
    }

    public function test_gardes_metier_bloquantes(): void
    {
        $cas = [
            'UE autre programme' => $this->csv(['PLN;2026-2027;UE-AUTRE;EC-PLN;3;20;obligatoire']),
            'ECUE hors UE' => $this->csv(['PLN;2026-2027;UE-PLN;EC-AUTRE;3;20;obligatoire']),
            'régime inconnu' => $this->csv(['PLN;2026-2027;UE-PLN;EC-PLN;3;20;festif']),
            'année inconnue' => $this->csv(['PLN;2099-2100;UE-PLN;EC-PLN;3;20;obligatoire']),
        ];

        // Références hors périmètre pour les cas 1-2.
        $faculteB = Faculte::create(['nom' => 'FDL', 'code' => 'FDL', 'active' => true]);
        $progB = Programme::create([
            'faculte_id' => $faculteB->id, 'code' => 'AUT', 'nom' => 'Autre',
            'statut' => 'actif', 'actif' => true,
        ]);
        $nivB = Niveau::create([
            'programme_id' => $progB->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1, 'actif' => true,
        ]);
        $semB = SemestreLmd::create([
            'niveau_id' => $nivB->id, 'code' => 'S1', 'nom' => 'S1',
            'numero' => 1, 'ordre' => 1, 'actif' => true,
        ]);
        $ueB = UniteEnseignement::create([
            'semestre_id' => $semB->id, 'programme_id' => $progB->id,
            'code' => 'UE-AUTRE', 'nom' => 'UE autre', 'credits' => 6, 'actif' => true,
        ]);
        Matiere::create([
            'code' => 'EC-AUTRE', 'nom' => 'ECUE autre', 'credits' => 3, 'ue_id' => $ueB->id,
            'niveau' => 'L1', 'semestre' => 1, 'actif' => true,
        ]);

        foreach ($cas as $titre => $csv) {
            $preparation = $this->preparer($csv);
            $this->assertTrue($preparation['resultat']->bloque(), "Cas « {$titre} » non bloqué.");
        }

        $this->assertSame(0, Maquette::count());
        $this->assertSame(0, MaquetteUeEcue::count());
    }

    public function test_maquette_verrouillee_refusee(): void
    {
        Maquette::create([
            'programme_id' => $this->programme->id,
            'annee_universitaire_id' => $this->annee->id,
            'version' => 1, 'statut' => 'approved',
        ]);

        $preparation = $this->preparer($this->csv(['PLN;2026-2027;UE-PLN;EC-PLN;3;20;obligatoire']));

        $trouve = false;
        foreach ($preparation['resultat']->erreurs as $erreur) {
            if (($erreur['code'] ?? null) === 'MAQUETTE_VERROUILLEE') {
                $trouve = true;
            }
        }

        $this->assertTrue($trouve, 'Verrou non signalé.');
        $this->assertSame(0, MaquetteUeEcue::count());
    }

    public function test_perimetre_et_double_confirmation(): void
    {
        $faculteB = Faculte::create(['nom' => 'FDL', 'code' => 'FDL', 'active' => true]);
        $restreint = User::creerCompteBackOffice([
            'name' => 'Admin B', 'email' => 'b.plan@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $faculteB->id,
        ], 'admin_faculte');

        $pirate = app(DataImportService::class)->preparer(
            UploadedFile::fake()->createWithContent('plan.csv',
                $this->csv(['PLN;2026-2027;UE-PLN;EC-PLN;3;20;obligatoire']), 'text/csv'),
            'maquettes-plan', $restreint->id
        );

        try {
            app(DataImportService::class)->confirmer($pirate['token'], 'creer', $restreint->id);
            $this->fail('Écriture hors périmètre acceptée.');
        } catch (IoException) {
            $this->assertTrue(true);
        }

        $this->assertSame(0, Maquette::count());

        $legitime = $this->preparer($this->csv(['PLN;2026-2027;UE-PLN;EC-PLN;3;20;obligatoire']));
        app(DataImportService::class)->confirmer($legitime['token'], 'creer', $this->admin->id);

        try {
            app(DataImportService::class)->confirmer($legitime['token'], 'creer', $this->admin->id);
            $this->fail('Double confirmation acceptée.');
        } catch (IoException $e) {
            $this->assertStringContainsString('expirée', $e->getMessage());
        }

        $this->assertSame(1, MaquetteUeEcue::count());
    }

    public function test_http_preview_confirmer(): void
    {
        $fichier = UploadedFile::fake()->createWithContent('plan.csv',
            $this->csv(['PLN;2026-2027;UE-PLN;EC-PLN;3;20;obligatoire']), 'text/csv');

        $preview = $this->actingAs($this->admin)
            ->post(route('admin.donnees.import.preview', 'maquettes-plan'), ['fichier' => $fichier])
            ->assertOk();

        $this->actingAs($this->admin)->post(
            route('admin.donnees.import.confirmer', 'maquettes-plan'),
            ['token' => $preview->viewData('token'), 'mode' => 'creer']
        )->assertOk();

        $this->assertSame('draft', Maquette::firstOrFail()->statut);
    }
}
