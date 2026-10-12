<?php

namespace Tests\Feature;

use App\Models\Faculte;
use App\Models\User;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Schedule\Models\Teacher;
use App\Services\DataExchange\Definitions\TeacherDefinition;
use App\Services\Io\IoException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Phase C — Import/export enseignants : parité des colonnes, statuts
 * normalisés et persistés, grades explicites, conflits de matricule,
 * périmètre faculté, formats prouvés.
 */
class EnseignantImportExportTest extends TestCase
{
    use RefreshDatabase;

    private TeacherDefinition $definition;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private Departement $departementA;

    private Departement $departementB;

    private User $adminFaculteA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->definition = app(TeacherDefinition::class);
        $this->faculteA = Faculte::create(['nom' => 'FA', 'code' => 'FA', 'description' => 'T', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'FB', 'code' => 'FB', 'description' => 'T', 'active' => true]);
        $this->departementA = Departement::create([
            'code' => 'DEP-A', 'nom' => 'Département A', 'faculte_id' => $this->faculteA->id,
        ]);
        $this->departementB = Departement::create([
            'code' => 'DEP-B', 'nom' => 'Département B', 'faculte_id' => $this->faculteB->id,
        ]);
        $this->adminFaculteA = User::creerCompteBackOffice([
            'name' => 'Admin FA', 'email' => 'admin.fa.imp@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');
    }

    private function ligne(array $surcharge = []): array
    {
        return array_merge([
            'ligne' => 2, 'nom' => 'Import', 'prenom' => 'Test',
            'email' => 'import.'.uniqid().'@universite.test',
        ], $surcharge);
    }

    public function test_statut_normalise_persiste_et_synchronise_actif(): void
    {
        // SALIMA écrit ACTIF en majuscules : normalisé, persisté, compte ouvert.
        $this->definition->persister($this->ligne(['statut' => 'ACTIF']), 'creer', null);
        $enseignant = Teacher::where('nom', 'Import')->firstOrFail();
        $this->assertSame('actif', $enseignant->statut);
        $this->assertTrue($enseignant->actif);

        // Suspendu : persisté + compte fermé (option A Phase B).
        $this->definition->persister($this->ligne(['statut' => 'suspendu']), 'creer', null);
        $suspendu = Teacher::where('statut', 'suspendu')->firstOrFail();
        $this->assertFalse($suspendu->actif);
    }

    public function test_statut_inconnu_refuse_explicitement(): void
    {
        $erreurs = $this->definition->validerLigneMetier($this->ligne(['statut' => 'ZOMBIE']), null);

        $this->assertNotEmpty($erreurs);
        $this->assertStringContainsString('statut', strtolower(implode(' ', $erreurs)));

        try {
            $this->definition->persister($this->ligne(['statut' => 'ZOMBIE']), 'creer', null);
            $this->fail('Statut inconnu accepté silencieusement.');
        } catch (IoException $e) {
            $this->assertStringContainsString('statut', strtolower($e->getMessage()));
        }
        $this->assertSame(0, Teacher::count());
    }

    public function test_grade_invalide_refuse_grade_inconnu_cree_explicitement(): void
    {
        // Code grade garbage : refusé, rien créé (ni grade ni enseignant).
        $erreurs = $this->definition->validerLigneMetier($this->ligne(['grade' => '!!!']), null);
        $this->assertNotEmpty($erreurs);
        $this->assertSame(0, Teacher::count());
        $this->assertDatabaseMissing('grades_enseignants', ['code' => '!!!']);

        // Code valide inconnu (cas SALIMA MAITRE_ASSISTANT) : créé mais
        // signalé dans le message (jamais silencieux).
        $message = $this->definition->persister($this->ligne(['grade' => 'MAITRE_ASSISTANT']), 'creer', null);
        $this->assertStringContainsString('créé', $message);
        $this->assertStringContainsString('MAITRE_ASSISTANT', $message);
        $this->assertDatabaseHas('grades_enseignants', ['code' => 'MAITRE_ASSISTANT']);
    }

    public function test_faculte_incoherente_refusee(): void
    {
        $erreurs = $this->definition->validerLigneMetier(
            $this->ligne(['departement' => 'DEP-A', 'faculte_code' => 'FB']), null
        );

        $this->assertNotEmpty($erreurs);
        $this->assertStringContainsString('facult', strtolower(implode(' ', $erreurs)));
        $this->assertSame(0, Teacher::count());

        // Cohérent : accepté.
        $this->assertSame(
            [],
            $this->definition->validerLigneMetier(
                $this->ligne(['departement' => 'DEP-A', 'faculte_code' => 'FA']), null
            )
        );
    }

    public function test_conflit_matricule_refuse(): void
    {
        Teacher::create([
            'nom' => 'Existant', 'prenom' => 'E', 'email' => 'existant@universite.test',
            'matricule' => 'MAT-CONFLIT',
        ]);

        // Même matricule, email différent → refus (jamais d'écrasement croisé).
        $erreurs = $this->definition->validerLigneMetier(
            $this->ligne(['email' => 'autre@universite.test', 'matricule' => 'MAT-CONFLIT']), null
        );
        $this->assertNotEmpty($erreurs);
        $this->assertStringContainsString('matricule', strtolower(implode(' ', $erreurs)));

        try {
            $this->definition->persister(
                $this->ligne(['email' => 'autre@universite.test', 'matricule' => 'MAT-CONFLIT']), 'upsert', null
            );
            $this->fail('Conflit de matricule accepté.');
        } catch (IoException $e) {
            $this->assertStringContainsString('matricule', strtolower($e->getMessage()));
        }
        $this->assertSame(1, Teacher::count());

        // Même email + même matricule → mise à jour légitime.
        $message = $this->definition->persister(
            $this->ligne(['email' => 'existant@universite.test', 'matricule' => 'MAT-CONFLIT', 'nom' => 'Existant']),
            'upsert', null
        );
        $this->assertStringContainsString('jour', $message);
        $this->assertSame(1, Teacher::count());
    }

    public function test_hors_perimetre_refuse(): void
    {
        $enseignantB = Teacher::create([
            'nom' => 'NomB', 'prenom' => 'B', 'email' => 'b@universite.test',
            'departement_id' => $this->departementB->id,
        ]);

        // Mise à jour d'un enseignant d'une autre faculté → refus.
        $erreurs = $this->definition->validerLigneMetier(
            $this->ligne(['email' => 'b@universite.test']), $this->adminFaculteA->id
        );
        $this->assertNotEmpty($erreurs);

        try {
            $this->definition->persister(
                $this->ligne(['email' => 'b@universite.test']), 'upsert', $this->adminFaculteA->id
            );
            $this->fail('Écriture hors périmètre acceptée.');
        } catch (IoException $e) {
            $this->assertStringContainsString('périmètre', strtolower($e->getMessage()));
        }
        $this->assertSame('NomB', $enseignantB->fresh()->nom);

        // Création vers un département étranger → refus.
        $erreursCreation = $this->definition->validerLigneMetier(
            $this->ligne(['departement' => 'DEP-B']), $this->adminFaculteA->id
        );
        $this->assertNotEmpty($erreursCreation);
    }

    public function test_filtrer_scope_au_perimetre(): void
    {
        Teacher::create(['nom' => 'NomA', 'prenom' => 'A', 'departement_id' => $this->departementA->id]);
        Teacher::create(['nom' => 'NomB', 'prenom' => 'B', 'departement_id' => $this->departementB->id]);
        Teacher::create(['nom' => 'NomT', 'prenom' => 'T']);

        $requete = Request::create('/');
        $requete->setUserResolver(fn () => $this->adminFaculteA);

        $noms = $this->definition->filtrer($this->definition->requete($requete), $requete)
            ->pluck('nom')->all();

        $this->assertContains('NomA', $noms);
        $this->assertContains('NomT', $noms);
        $this->assertNotContains('NomB', $noms);
    }

    public function test_ligne_couvre_toutes_les_colonnes_contractuelles(): void
    {
        $enseignant = Teacher::create([
            'nom' => 'NomA', 'prenom' => 'A', 'email' => 'a@universite.test',
            'matricule' => 'MAT-A', 'departement_id' => $this->departementA->id,
            'statut' => 'actif', 'bureau' => 'B12',
        ]);

        $ligne = $this->definition->ligne($enseignant->fresh());

        foreach (['nom', 'prenom', 'email', 'matricule', 'grade', 'departement', 'bureau', 'faculte_code', 'statut'] as $colonne) {
            $this->assertArrayHasKey($colonne, $ligne, "Colonne {$colonne} absente de l'export.");
        }
        $this->assertSame('DEP-A', $ligne['departement']);
        $this->assertSame('FA', $ligne['faculte_code']);
        $this->assertSame('actif', $ligne['statut']);
    }

    public function test_export_csv_scope_au_perimetre(): void
    {
        Teacher::create(['nom' => 'NomA', 'prenom' => 'A', 'departement_id' => $this->departementA->id]);
        Teacher::create(['nom' => 'NomB', 'prenom' => 'B', 'departement_id' => $this->departementB->id]);

        $reponse = $this->actingAs($this->adminFaculteA)
            ->get(route('admin.donnees.export', ['enseignants', 'csv']));

        $reponse->assertOk();
        $contenu = $reponse->streamedContent();
        $this->assertStringContainsString('NomA', $contenu);
        $this->assertStringNotContainsString('NomB', $contenu);
    }
}
