<?php

namespace Tests\Feature\Security;

use App\Models\AnneeUniversitaire;
use App\Models\DocumentAcademique;
use App\Models\DossierAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\ImportReport;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\Semestre;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * Tests IDOR/BOLA (§10, §50) : un ID d'URL n'est jamais une autorisation.
 * A accède à A → 200 ; A accède à B → 403/404.
 */
class IdorSecurityTest extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private Matiere $matiere;

    private int $auteurId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteA = Faculte::create(['nom' => 'FA', 'code' => 'FA', 'description' => 'T', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'FB', 'code' => 'FB', 'description' => 'T', 'active' => true]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30', 'active' => true,
        ]);
        $this->matiere = Matiere::create([
            'code' => 'WEB101', 'nom' => 'Web', 'coefficient' => 2,
            'credits' => 6, 'niveau' => 'L1', 'semestre' => 1,
        ]);
        $this->auteurId = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => uniqid('sc.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite')->id;
    }

    private function adminFaculteA(): User
    {
        $user = User::creerCompteBackOffice([
            'name' => 'Admin A', 'email' => uniqid('aa.', true).'@scolarite.test',
            'password' => 'Motdepasse@123', 'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');
        $this->actingAs($user);

        return $user;
    }

    private function etudiantDe(Faculte $faculte): Etudiant
    {
        return Etudiant::create([
            'cin' => 'BK'.random_int(100000, 999999),
            'nom' => 'Idor', 'prenom' => 'Test',
            'email' => uniqid('i.', true).'@example.com',
            'sexe' => 'M', 'faculte_id' => $faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2025-2026',
            'adresse' => '1 rue', 'ville' => 'Rabat',
            'code_postal' => '10000', 'annee_bac' => '2024',
            'password' => 'motdepasse1',
        ]);
    }

    public function test_dossier_autre_faculte_inaccessible(): void
    {
        $etudiantB = $this->etudiantDe($this->faculteB);
        $dossierB = DossierAcademique::create([
            'etudiant_id' => $etudiantB->id,
            'matricule' => 'MAT-'.strtoupper(substr(md5(uniqid('', true)), 0, 8)),
            'date_archivage' => now()->toDateString(),
        ]);

        $this->adminFaculteA();

        $this->get(route('admin.archives.show', $dossierB))->assertForbidden();
        $this->get(route('admin.archives.edit', $dossierB))->assertForbidden();
        $this->delete(route('admin.archives.destroy', $dossierB))->assertForbidden();
        $this->assertDatabaseHas('archives_etudiants', ['id' => $dossierB->id]);
    }

    public function test_note_autre_faculte_non_modifiable(): void
    {
        $etudiantB = $this->etudiantDe($this->faculteB);
        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiantB->id, 'niveau' => 'L1', 'filiere' => 'Info',
            'annee_universitaire_id' => $this->annee->id, 'statut' => 'en_cours',
        ]);
        $semestre = Semestre::create([
            'inscription_academique_id' => $inscription->id, 'numero' => 1,
        ]);
        $note = Note::create([
            'inscription_academique_id' => $inscription->id, 'etudiant_id' => $etudiantB->id,
            'semestre_id' => $semestre->id, 'matiere_id' => $this->matiere->id,
            'note' => 12, 'coefficient' => 2, 'credits' => 6,
        ]);

        $this->adminFaculteA();

        $this->patch(route('admin.archives.notes.update', $note), ['note' => 20])
            ->assertForbidden();
        $this->assertSame(12.0, (float) $note->fresh()->note);
    }

    public function test_document_autre_faculte_non_telechargeable(): void
    {
        $etudiantB = $this->etudiantDe($this->faculteB);
        $document = DocumentAcademique::create([
            'etudiant_id' => $etudiantB->id, 'type_document' => 'autre',
            'nom_original' => 'secret.pdf', 'chemin' => 'archives/x.pdf',
            'mime_type' => 'application/pdf', 'taille' => 10,
            'hash_fichier' => 'x', 'uploaded_by' => $this->auteurId,
        ]);

        $this->adminFaculteA();

        $this->get(route('admin.archives.documents.download', $document))->assertForbidden();
    }

    public function test_etudiant_ne_telecharge_que_ses_documents(): void
    {
        $etudiantA = $this->etudiantDe($this->faculteA);
        $etudiantB = $this->etudiantDe($this->faculteB);
        $documentB = DocumentAcademique::create([
            'etudiant_id' => $etudiantB->id, 'type_document' => 'autre',
            'nom_original' => 'secret.pdf', 'chemin' => 'archives/y.pdf',
            'mime_type' => 'application/pdf', 'taille' => 10,
            'hash_fichier' => 'y', 'uploaded_by' => $this->auteurId,
        ]);

        $this->etudiantConnecte($etudiantA);

        $this->get(route('etudiant.archives.documents.download', $documentB))
            ->assertNotFound();
    }

    public function test_rapport_erreurs_autrui_inaccessible_au_perimetre_restreint(): void
    {
        $autre = $this->scolarite('admin_scolarite');
        $rapport = ImportReport::create([
            'user_id' => $autre->id, 'integration' => 'web', 'direction' => 'import',
            'type' => 'data.salles', 'format' => 'csv', 'filename' => 's.csv',
            'external_reference' => 'DX-X', 'status' => 'completed',
            'mode' => 'atomique', 'total_rows' => 1, 'valid_rows' => 1,
        ]);

        $this->adminFaculteA();

        $this->get(route('admin.donnees.rapport.erreurs', [$rapport, 'csv']))
            ->assertNotFound();
    }

    public function test_import_groupe_autre_faculte_refuse(): void
    {
        $this->adminFaculteA();

        $fichier = UploadedFile::fake()->createWithContent(
            'groupes.csv',
            "code;nom;faculte;niveau;filiere\nGX;Groupe X;FB;L1;Info\n",
            'text/csv'
        );

        $preview = $this->post(route('admin.donnees.import.preview', 'groupes'), ['fichier' => $fichier])
            ->assertOk();

        $this->post(route('admin.donnees.import.confirmer', 'groupes'), [
            'token' => $preview->viewData('token'), 'mode' => 'creer',
        ])->assertRedirect();

        $this->assertSame(0, Group::where('code', 'GX')->count());
    }

    public function test_schedule_import_groupe_autre_faculte_bloque_au_confirmer(): void
    {
        config(['modules.schedule.active' => true]);

        Group::create([
            'code' => 'GB-1', 'nom' => 'GB', 'faculte_id' => $this->faculteB->id,
            'niveau' => 'L1', 'filiere' => 'Info', 'actif' => true,
        ]);
        Teacher::create(['nom' => 'Enseignant', 'prenom' => 'A']);
        Room::create(['code' => 'B12', 'capacite' => 40]);

        // Le groupe du FICHIER (colonne group) appartient à l'autre faculté :
        // la preview ne persiste rien, le confirmer refuse (périmètre
        // re-vérifié côté service sur le groupe du fichier).
        $fichier = UploadedFile::fake()->createWithContent(
            'planning.csv',
            "academic_year,semester,group,day,start_time,end_time,course,teacher,room,type\n"
            ."2026/2027,S1,GB-1,monday,08:00,10:00,Développement Web,Enseignant A,B12,course\n",
            'text/csv'
        );

        $this->adminFaculteA();

        $anneePlanning = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30', 'active' => false,
        ]);

        $preview = $this->post(route('admin.emplois-du-temps.import.preview'), [
            'fichier' => $fichier,
            'annee_universitaire_id' => $anneePlanning->id,
            'semestre' => 1,
        ])->assertOk();

        $token = $preview->viewData('token');
        $this->assertNotNull($token);

        $this->post(route('admin.emplois-du-temps.import.confirmer'), [
            'token' => $token, 'mode' => 'creer',
        ])->assertRedirect()->assertSessionHasErrors('fichier');

        $this->assertSame(0, Schedule::count());
    }

    public function test_recherche_isolee_par_faculte(): void
    {
        $this->etudiantDe($this->faculteB)->forceFill(['cin' => 'CIN-ISOLE-B'])->save();

        $this->adminFaculteA();

        $reponse = $this->get(route('admin.recherche', ['q' => 'CIN-ISOLE-B']))->assertOk();

        foreach ($reponse->viewData('sections')['Étudiants']['resultats'] ?? [] as $resultat) {
            $this->assertStringNotContainsString('CIN-ISOLE-B', $resultat['detail']);
        }
    }
}
