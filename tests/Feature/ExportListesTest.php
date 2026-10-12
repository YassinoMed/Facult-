<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\ExportTemplate;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use App\Services\Exports\ListeEtudiantsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Moteur central d'export (§46-48) : UNE population pour preview,
 * CSV, XLSX, PDF et impression. Scopes + colonnes serveur.
 */
class ExportListesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Group $groupe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.exp@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->groupe = Group::create([
            'code' => 'GA', 'nom' => 'Groupe A', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'capacite' => 30, 'actif' => true,
        ]);
    }

    public function test_dataset_filtres_combines_intersection(): void
    {
        $cible = $this->creerEtudiant('EXP00001', ['groupe_id' => $this->groupe->id, 'niveau' => 'L1']);
        $this->creerEtudiant('EXP00002', ['groupe_id' => $this->groupe->id, 'niveau' => 'L2']);
        $this->creerEtudiant('EXP00003', ['niveau' => 'L1']);

        $service = app(ListeEtudiantsService::class);
        $query = $service->requete(
            ['groupe_id' => $this->groupe->id, 'niveau' => 'L1', 'annee_universitaire_id' => $this->annee->id],
            $this->admin
        );

        $this->assertSame([$cible->id], $query->pluck('etudiants.id')->all());
    }

    public function test_sans_groupe_et_recherche(): void
    {
        $sansGroupe = $this->creerEtudiant('EXP00004', ['groupe_id' => null]);
        $this->creerEtudiant('EXP00005', ['groupe_id' => $this->groupe->id]);

        $service = app(ListeEtudiantsService::class);

        $this->assertSame(
            [$sansGroupe->id],
            $service->requete(['sans_groupe' => true], $this->admin)->pluck('etudiants.id')->all()
        );
        $this->assertSame(
            [$sansGroupe->id],
            $service->requete(['search' => 'EXP00004'], $this->admin)->pluck('etudiants.id')->all()
        );
    }

    public function test_tri_et_pagination_preview(): void
    {
        $this->creerEtudiant('EXP00006', ['nom' => 'Zebra']);
        $this->creerEtudiant('EXP00007', ['nom' => 'Alami']);

        $reponse = $this->actingAs($this->admin)->get(route('admin.listes.preview', [
            'tri' => 'nom', 'sens' => 'asc',
        ]));

        $reponse->assertOk();
        $noms = $reponse->viewData('etudiants')->pluck('nom')->all();
        $tries = $noms;
        sort($tries);
        $this->assertSame($tries, array_values($noms));
    }

    public function test_csv_xlsx_meme_population_que_preview(): void
    {
        $this->creerEtudiant('EXP00008', ['groupe_id' => $this->groupe->id]);
        $this->creerEtudiant('EXP00009', []);

        $filtres = ['groupe_id' => $this->groupe->id];

        $csv = $this->actingAs($this->admin)->get(route('admin.listes.exporter', ['format' => 'csv'] + $filtres));
        $csv->assertOk();
        $this->assertStringContainsString('EXP00008', $csv->streamedContent());
        $this->assertStringNotContainsString('EXP00009', $csv->streamedContent());

        $xlsx = $this->actingAs($this->admin)->get(route('admin.listes.exporter', ['format' => 'xlsx'] + $filtres));
        $xlsx->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $xlsx->headers->get('Content-Type')
        );
    }

    public function test_pdf_institutionnel_telechargeable(): void
    {
        $this->creerEtudiant('EXP00010', ['groupe_id' => $this->groupe->id]);

        $reponse = $this->actingAs($this->admin)->get(route('admin.listes.exporter', [
            'format' => 'pdf', 'groupe_id' => $this->groupe->id, 'emargement' => 1, 'zone_signature' => 1,
        ]));

        $reponse->assertOk();
        $this->assertSame('application/pdf', $reponse->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $reponse->streamedContent());
    }

    public function test_export_vide_explique(): void
    {
        $this->actingAs($this->admin)->get(route('admin.listes.exporter', [
            'format' => 'csv', 'search' => 'ZZZ-INEXISTANT-ZZZ',
        ]))->assertSessionHasErrors('export');
    }

    public function test_nommage_fichier_professionnel(): void
    {
        $this->creerEtudiant('EXP00011', ['groupe_id' => $this->groupe->id, 'niveau' => 'L1']);

        $reponse = $this->actingAs($this->admin)->get(route('admin.listes.exporter', [
            'format' => 'csv', 'groupe_id' => $this->groupe->id, 'niveau' => 'L1',
        ]));

        $reponse->assertOk();
        $this->assertMatchesRegularExpression(
            '/attachment; filename="?liste-etudiants-l1-ga-\d{8}\.csv"?/',
            (string) $reponse->headers->get('Content-Disposition')
        );
    }

    public function test_cin_masquee_sans_permission(): void
    {
        $this->creerEtudiant('EXP00012', ['cin' => 'BK998877', 'groupe_id' => $this->groupe->id]);

        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'agent.exp@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        // Agent : data.export OK mais pas exports.personal → CIN masquée, email/tél retirés.
        $csv = $this->actingAs($agent)->get(route('admin.listes.exporter', [
            'format' => 'csv', 'groupe_id' => $this->groupe->id, 'colonnes' => ['matricule', 'nom', 'cin', 'email'],
        ]));

        $csv->assertOk();
        $contenu = $csv->streamedContent();
        $this->assertStringNotContainsString('BK998877', $contenu);
        $this->assertStringContainsString('BK***877', $contenu);
        $this->assertStringNotContainsString('email', strtolower(explode("\n", $contenu)[0]));
    }

    public function test_isolation_faculte_bloquee(): void
    {
        $this->creerEtudiant('EXP00013', ['groupe_id' => $this->groupe->id]);

        $autreFaculte = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'T', 'active' => true]);
        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.exp@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $autreFaculte->id,
        ], 'admin_faculte');

        // Manipulation faculty_id → re-scopé serveur, 0 résultat.
        $reponse = $this->actingAs($adminFaculte)->get(route('admin.listes.preview', [
            'faculte_id' => $this->faculte->id,
        ]));
        $reponse->assertOk();
        $this->assertSame(0, $reponse->viewData('total'));

        // Groupe d'une autre faculté → vide également.
        $reponse = $this->actingAs($adminFaculte)->get(route('admin.listes.exporter', [
            'format' => 'csv', 'groupe_id' => $this->groupe->id,
        ]));
        $reponse->assertSessionHasErrors('export');
    }

    public function test_dataset_invalide_et_permission_export(): void
    {
        // Colonne inconnue ignorée (catalogue), jamais d'erreur 500.
        $this->creerEtudiant('EXP00014', []);
        $this->actingAs($this->admin)->get(route('admin.listes.exporter', [
            'format' => 'csv', 'colonnes' => ['notes', 'finance_secrete'],
        ]))->assertOk();

        // Sans data.export : 403.
        $consultation = User::creerCompteBackOffice([
            'name' => 'Cons', 'email' => 'cons.exp@universite.test', 'password' => 'Motdepasse@123',
        ], 'consultation');

        $this->actingAs($consultation)->get(route('admin.listes.exporter', ['format' => 'csv']))
            ->assertForbidden();
    }

    public function test_impression_et_historique(): void
    {
        $this->creerEtudiant('EXP00015', []);

        $this->actingAs($this->admin)->get(route('admin.listes.imprimer'))->assertOk()
            ->assertSee('window.print', false);

        $this->actingAs($this->admin)->get(route('admin.listes.exporter', ['format' => 'csv']));
        $this->assertDatabaseHas('integration_logs', [
            'integration' => 'export', 'operation' => 'export.listes', 'status' => 'completed',
        ]);
    }

    public function test_profils_enregistres_et_reutilises(): void
    {
        $this->creerEtudiant('EXP00016', ['groupe_id' => $this->groupe->id]);

        $this->actingAs($this->admin)->post(route('admin.listes.profils.store'), [
            'name' => 'Liste scolarité',
            'format' => 'pdf',
            'groupe_id' => $this->groupe->id,
        ])->assertSessionHasNoErrors();

        $profil = ExportTemplate::where('user_id', $this->admin->id)
            ->where('type', 'listes')->firstOrFail();

        $this->assertEquals((string) $this->groupe->id, (string) ($profil->filters['groupe_id'] ?? null));

        // Réutilisation : mêmes résultats via le lien profil.
        $reponse = $this->actingAs($this->admin)->get(route('admin.listes.preview', $profil->filters));
        $reponse->assertOk();
        $this->assertSame(1, $reponse->viewData('total'));

        // Propriété : un autre utilisateur ne peut pas supprimer.
        $autre = User::creerCompteBackOffice([
            'name' => 'Autre', 'email' => 'autre.exp@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->actingAs($autre)->delete(route('admin.listes.profils.destroy', $profil))->assertNotFound();
        $this->actingAs($this->admin)->delete(route('admin.listes.profils.destroy', $profil))
            ->assertSessionHasNoErrors();
    }

    public function test_pdf_dataexchange_entites(): void
    {
        $reponse = $this->actingAs($this->admin)->get(route('admin.donnees.export', [
            'entite' => 'salles', 'format' => 'pdf',
        ]));

        $reponse->assertOk();
        $this->assertSame('application/pdf', $reponse->headers->get('Content-Type'));
    }

    public function test_impression_groupe_avec_metadonnees_et_filtrage_entete(): void
    {
        $this->creerEtudiant('EXP00017', ['groupe_id' => $this->groupe->id]);

        $reponse = $this->actingAs($this->admin)->get(route('admin.listes.imprimer', [
            'groupe_id' => $this->groupe->id,
            'champs_entete' => ['etablissement', 'groupe', 'annee', 'effectif'],
            'colonnes' => ['numero', 'matricule', 'nom', 'prenom'],
        ]));

        $reponse->assertOk();
        $this->assertArrayHasKey('metadonnees', $reponse->viewData());
        $metadonnees = $reponse->viewData('metadonnees');
        $this->assertStringContainsString($this->groupe->code, (string) $metadonnees['groupe']);
        $this->assertEquals($this->faculte->nom, $metadonnees['faculte']);
        $this->assertEquals(['etablissement', 'annee', 'groupe', 'effectif'], $reponse->viewData('champsEnteteInitiaux'));
        $this->assertEquals(['numero', 'matricule', 'nom', 'prenom'], $reponse->viewData('colonnesInitiales'));
        $reponse->assertSee('window.print', false);
        $reponse->assertSee('printConfigApp', false);
    }

    public function test_export_pdf_avec_filtrage_champs_entete(): void
    {
        $this->creerEtudiant('EXP00018', ['groupe_id' => $this->groupe->id]);

        $reponse = $this->actingAs($this->admin)->get(route('admin.listes.exporter', [
            'format' => 'pdf',
            'groupe_id' => $this->groupe->id,
            'champs_entete' => ['groupe', 'annee'],
            'colonnes' => ['numero', 'matricule', 'nom'],
        ]));

        $reponse->assertOk();
        $this->assertSame('application/pdf', $reponse->headers->get('Content-Type'));
    }

    private function creerEtudiant(string $cin, array $attributs = []): Etudiant
    {
        $etudiant = Etudiant::create(array_merge([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => strtolower($cin).'@exp.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ], $attributs));
        $etudiant->definirStatut('valide');
        $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
        ]);

        return $etudiant;
    }
}
