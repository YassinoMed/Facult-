<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\TypeDiplome;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Services\DataExchange\ImportDependencyService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * LOT 2 — Consolidation du référentiel LMD : dépendances d'import
 * réelles, année active protégée DB, statut/capacité programme,
 * cycle/crédits diplôme, type d'évaluation contrôlé. Aucun flux
 * inscription modifié (non-régression dédiée en voisins).
 */
class ReferentielLot2Test extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private SemestreLmd $semestre;

    private UniteEnseignement $ue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.ref2@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LMI', 'nom' => 'Licence MI',
            'statut' => 'actif',
        ]);
        $niveau = Niveau::create(['programme_id' => $this->programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $this->semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1,
        ]);
        $this->ue = UniteEnseignement::create([
            'semestre_id' => $this->semestre->id, 'programme_id' => $this->programme->id,
            'code' => 'UE01', 'nom' => 'Bases', 'credits' => 6, 'coefficient' => 2,
        ]);
    }

    private function csv(string $nom, string $contenu): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nom, $contenu, 'text/csv');
    }

    private function importer(string $entite, UploadedFile $fichier): TestResponse
    {
        $apercu = $this->actingAs($this->admin)
            ->post("/admin/donnees/{$entite}/import/preview", ['fichier' => $fichier])
            ->assertOk();

        return $this->actingAs($this->admin)->post("/admin/donnees/{$entite}/import/confirmer", [
            'token' => $apercu->viewData('token'), 'mode' => 'creer',
        ]);
    }

    // ── Graphe de dépendances ────────────────────────────────────

    public function test_graphe_dependances_reelles(): void
    {
        $service = app(ImportDependencyService::class);

        $this->assertSame(['programmes', 'semestres'], $service->dependancesDe('ues'));
        $this->assertSame(['facultes'], $service->dependancesDe('programmes'));
        $this->assertSame([], $service->dependancesDe('matieres'));
        $this->assertSame(['etudiants', 'matieres'], $service->dependancesDe('notes'));
        $this->assertSame(['sessions-examen', 'programmes'], $service->dependancesDe('deliberations'));
        $this->assertSame(['deliberations', 'etudiants'], $service->dependancesDe('decisions-jury'));
        $this->assertSame([], $service->dependancesDe('referentiel-mens'));
        $this->assertSame(['etudiants', 'niveaux', 'annees-universitaires'], $service->dependancesDe('progressions'));
        $this->assertSame(['etudiants'], $service->dependancesDe('documents-academiques'));
    }

    public function test_prerequis_ecue_exige_rattachement(): void
    {
        Matiere::create([
            'code' => 'ORPH01', 'nom' => 'Orpheline', 'niveau' => 'L1', 'semestre' => 1,
            'credits' => 2, 'coefficient' => 1,
        ]);

        // Matière standalone seule : l'ECUE rattachée manque toujours.
        $manquantes = app(ImportDependencyService::class)->verifierPrerequis('inscriptions-pedagogiques', $this->admin->id);
        $this->assertArrayHasKey('ecues', $manquantes);

        Matiere::create([
            'code' => 'RATT01', 'nom' => 'Rattachée', 'niveau' => 'L1', 'semestre' => 1,
            'credits' => 2, 'coefficient' => 1, 'ue_id' => $this->ue->id,
        ]);

        $manquantes = app(ImportDependencyService::class)->verifierPrerequis('inscriptions-pedagogiques', $this->admin->id);
        $this->assertArrayNotHasKey('ecues', $manquantes);
    }

    // ── Année active (DB + application) ──────────────────────────

    public function test_annee_active_unique_db(): void
    {
        $this->assertSame('Y', $this->annee->fresh()->active_unique);

        // Garde DB : deux 'Y' impossibles même en écriture brute.
        try {
            DB::table('annees_universitaires')->insert([
                'libelle' => '2027-2028', 'date_debut' => '2027-09-01', 'date_fin' => '2028-06-30',
                'active' => false, 'active_unique' => 'Y',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->fail('La contrainte unique aurait dû refuser.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        // Bascule propre : l'ancienne est désactivée, une seule active.
        $nouvelle = AnneeUniversitaire::create([
            'libelle' => '2027-2028', 'date_debut' => '2027-09-01', 'date_fin' => '2028-06-30',
        ]);
        $nouvelle->definirCourante();

        $this->assertFalse($this->annee->fresh()->active);
        $this->assertTrue($nouvelle->fresh()->active);
        $this->assertSame(1, AnneeUniversitaire::where('active', true)->count());
    }

    public function test_update_annee_desactive_anciennes(): void
    {
        $autre = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-06-30',
        ]);

        $this->actingAs($this->admin)->patch(route('admin.archives.annees.update', $autre), [
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-06-30', 'active' => true,
        ])->assertRedirect();

        $this->assertTrue($autre->fresh()->active);
        $this->assertFalse($this->annee->fresh()->active);
        $this->assertSame('Y', $autre->fresh()->active_unique);
        $this->assertNull($this->annee->fresh()->active_unique);
    }

    // ── Programme : statut + capacité ────────────────────────────

    public function test_programme_statut_capacite_crud(): void
    {
        $this->actingAs($this->admin)->post(route('admin.referentiel.entites.store', 'programmes'), [
            'faculte_id' => $this->faculte->id, 'code' => 'LGL', 'nom' => 'Licence GL',
            'statut' => 'brouillon', 'capacite' => 120,
        ])->assertRedirect();

        $programme = Programme::where('code', 'LGL')->firstOrFail();
        $this->assertSame('brouillon', $programme->statut);
        $this->assertSame(120, $programme->capacite);
        // `actif` synchronisé : un brouillon n'est jamais proposable.
        $this->assertFalse($programme->actif);

        $this->actingAs($this->admin)->patch(route('admin.referentiel.entites.update', ['programmes', $programme->id]), [
            'faculte_id' => $this->faculte->id, 'code' => 'LGL', 'nom' => 'Licence GL',
            'statut' => 'actif', 'capacite' => 150,
        ])->assertRedirect();

        $programme->refresh();
        $this->assertTrue($programme->actif);
        $this->assertSame(150, $programme->capacite);
    }

    public function test_programme_archive_terminal(): void
    {
        $this->programme->update(['statut' => 'archive']);
        $this->assertFalse($this->programme->fresh()->actif);

        $this->actingAs($this->admin)->patch(
            route('admin.referentiel.entites.update', ['programmes', $this->programme->id]),
            ['faculte_id' => $this->faculte->id, 'code' => 'LMI', 'nom' => 'Licence MI', 'statut' => 'actif']
        )->assertRedirect()->assertSessionHasErrors('statut');

        $this->assertSame('archive', $this->programme->fresh()->statut);
    }

    public function test_programme_import_statut_capacite(): void
    {
        $this->importer('programmes', $this->csv(
            'programmes.csv',
            "code;nom;faculte;statut;capacite\nLGL;Licence GL;FST;inactif;90\n"
        ))->assertOk();

        $programme = Programme::where('code', 'LGL')->firstOrFail();
        $this->assertSame('inactif', $programme->statut);
        $this->assertFalse($programme->actif);
        $this->assertSame(90, $programme->capacite);
    }

    public function test_programme_import_statut_invalide(): void
    {
        $apercu = $this->actingAs($this->admin)
            ->post('/admin/donnees/programmes/import/preview', ['fichier' => $this->csv(
                'programmes.csv',
                "code;nom;faculte;statut\nLGL;Licence GL;FST;obsolete\n"
            )])
            ->assertOk();

        $codes = array_column($apercu->viewData('resultat')->erreurs, 'code');
        $this->assertContains('PROGRAMME_STATUT_INVALIDE', $codes);
        $this->assertNull(Programme::where('code', 'LGL')->first());
    }

    // ── UE/ECUE : programme archivé + type évaluation ────────────

    public function test_ue_programme_archive_refusee(): void
    {
        $this->programme->update(['statut' => 'archive']);

        $apercu = $this->actingAs($this->admin)
            ->post('/admin/donnees/ues/import/preview', ['fichier' => $this->csv(
                'ues.csv',
                "code;semestre;programme;nom\nUEX;S1;LMI;Test\n"
            )])
            ->assertOk();

        $codes = array_column($apercu->viewData('resultat')->erreurs, 'code');
        $this->assertContains('PROGRAMME_ARCHIVE', $codes);
    }

    public function test_ecue_type_evaluation_controle(): void
    {
        // Code inconnu : refusé dès la prévisualisation.
        $apercu = $this->actingAs($this->admin)
            ->post('/admin/donnees/ecues/import/preview', ['fichier' => $this->csv(
                'ecues.csv',
                "code;ue;nom;type_evaluation\nECX;UE01;Test;interro_surprise\n"
            )])
            ->assertOk();

        $codes = array_column($apercu->viewData('resultat')->erreurs, 'code');
        $this->assertContains('TYPE_EVALUATION_INCONNU', $codes);

        // Code du référentiel (graine migration) : accepté et persisté.
        $this->importer('ecues', $this->csv(
            'ecues.csv',
            "code;ue;nom;type_evaluation\nECV;UE01;Valide;examen\n"
        ))->assertOk();

        $this->assertSame('examen', Matiere::where('code', 'ECV')->firstOrFail()->type_evaluation);
    }

    public function test_ecue_controleur_refuse_type_inconnu(): void
    {
        $ecue = Matiere::create([
            'code' => 'ECU01', 'nom' => 'ECUE', 'niveau' => 'L1', 'semestre' => 1,
            'credits' => 2, 'coefficient' => 1, 'ue_id' => $this->ue->id,
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.referentiel.ecues.update', $ecue), ['type_evaluation' => 'nimporte_quoi'])
            ->assertRedirect()
            ->assertSessionHasErrors('type_evaluation');

        $this->actingAs($this->admin)
            ->patch(route('admin.referentiel.ecues.update', $ecue), ['type_evaluation' => 'controle'])
            ->assertRedirect();

        $this->assertSame('controle', $ecue->fresh()->type_evaluation);
    }

    public function test_legacy_type_evaluation_libre_conserve(): void
    {
        // Valeurs historiques hors référentiel : jamais réécrites par
        // le code (YELLOW documenté — seule la saisie/import contrôle).
        $ecue = Matiere::create([
            'code' => 'ECU99', 'nom' => 'Legacy', 'niveau' => 'L1', 'semestre' => 1,
            'credits' => 2, 'coefficient' => 1, 'type_evaluation' => 'AncienLibre',
        ]);

        $this->assertSame('AncienLibre', $ecue->fresh()->type_evaluation);
    }

    // ── Type de diplôme : cycle + crédits ────────────────────────

    public function test_type_diplome_cycle_credits(): void
    {
        $this->actingAs($this->admin)->post(route('admin.referentiel.entites.store', 'types-diplomes'), [
            'code' => 'LIC', 'nom' => 'Licence', 'ordre' => 1,
            'cycle' => 'licence', 'credits_requis' => 180, 'duree_annees' => 3,
        ])->assertRedirect();

        $type = TypeDiplome::where('code', 'LIC')->firstOrFail();
        $this->assertSame('licence', $type->cycle);
        $this->assertSame(180, $type->credits_requis);
        $this->assertSame(3, $type->duree_annees);

        $this->actingAs($this->admin)->post(route('admin.referentiel.entites.store', 'types-diplomes'), [
            'code' => 'XXX', 'nom' => 'Inconnu', 'cycle' => 'cycle_imaginaire',
        ])->assertRedirect()->assertSessionHasErrors('cycle');
    }

    public function test_type_diplome_import_mappe_colonnes(): void
    {
        $this->importer('type-diplomes', $this->csv(
            'types-diplomes.csv',
            "code;nom;niveau_diplome;credits_total;duree_annees\nMAS;Master;master;120;2\nLIC;Licence;LICENCE;180;3\n"
        ))->assertOk();

        $type = TypeDiplome::where('code', 'MAS')->firstOrFail();
        $this->assertSame('master', $type->cycle);
        $this->assertSame(120, $type->credits_requis);
        $this->assertSame(2, $type->duree_annees);

        // Casse indifférente (fichiers réels en majuscules).
        $this->assertSame('licence', TypeDiplome::where('code', 'LIC')->firstOrFail()->cycle);
    }
}
