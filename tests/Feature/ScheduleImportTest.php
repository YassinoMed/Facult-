<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleImport;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/**
 * Import semestriel CSV/JSON/XML : formats, validation, conflits,
 * transaction, remplacement, sécurité, module désactivé.
 */
class ScheduleImportTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculte;

    protected User $admin;

    protected Group $groupe;

    protected AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.schedule.active' => true]);

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences', 'code' => 'FST',
            'description' => 'Sciences', 'active' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité Test',
            'email' => 'scolarite.test@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->groupe = Group::create([
            'code' => 'GI2-A', 'nom' => 'GI2 A',
            'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
            'filiere' => 'Informatique', 'actif' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        Matiere::create([
            'code' => 'WEB101', 'nom' => 'Développement Web',
            'coefficient' => 2, 'credits' => 6, 'niveau' => 'L1', 'semestre' => 1,
        ]);
        Teacher::create(['nom' => 'Enseignant', 'prenom' => 'A', 'email' => 'a@universite.test']);
        Room::create(['code' => 'B12', 'capacite' => 40]);
    }

    protected function fichierCsv(string $contenu, string $nom = 'planning.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nom, $contenu, 'text/csv');
    }

    protected function csvValide(): string
    {
        return implode("\n", [
            'academic_year,semester,group,day,start_time,end_time,course,teacher,room,type',
            '2026/2027,S1,GI2-A,monday,08:00,10:00,Développement Web,Enseignant A,B12,course',
            '2026/2027,S1,GI2-A,tuesday,14:00,16:00,Développement Web,Enseignant A,B12,td',
        ]);
    }

    /**
     * @return array{token: string}
     */
    protected function preview(string $contenu, string $nom = 'planning.csv'): array
    {
        $reponse = $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.import.preview'),
            ['fichier' => $this->fichierCsv($contenu, $nom)]
        );

        $reponse->assertOk();

        return ['token' => $this->tokenDepuisSession()];
    }

    protected function tokenDepuisSession(): string
    {
        $cles = array_keys(session()->all());
        foreach ($cles as $cle) {
            if (str_starts_with($cle, 'schedule_imports.')) {
                return substr($cle, strlen('schedule_imports.'));
            }
        }

        foreach (session()->get('schedule_imports', []) as $token => $payload) {
            return $token;
        }

        $this->fail('Aucun token de prévisualisation en session.');
    }

    public function test_module_desactive_404(): void
    {
        config(['modules.schedule.active' => false]);

        $this->actingAs($this->admin)->get('/admin/emplois-du-temps/import')->assertNotFound();
    }

    public function test_csv_valide_preview_puis_confirmation(): void
    {
        ['token' => $token] = $this->preview($this->csvValide());

        $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.import.confirmer'),
            ['token' => $token, 'mode' => 'creer']
        )->assertOk()->assertSee('Import terminé');

        $planning = Schedule::firstOrFail();
        $this->assertSame(2, $planning->slots()->count());
        $this->assertSame('2026-2027', $planning->anneeUniversitaire->libelle);

        $rapport = ScheduleImport::firstOrFail();
        $this->assertSame('completed', $rapport->status);
        $this->assertSame(2, (int) $rapport->successful_rows);
    }

    public function test_csv_accents_et_separateur_point_virgule(): void
    {
        $contenu = implode("\n", [
            'academic_year;semester;group;day;start_time;end_time;course;teacher;room;type',
            '2026/2027;S1;GI2-A;lundi;08h00;10h00;Développement Web;Enseignant A;B12;course',
        ]);

        ['token' => $token] = $this->preview($contenu);

        $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.import.confirmer'),
            ['token' => $token, 'mode' => 'creer']
        )->assertOk();

        $this->assertSame(1, Schedule::firstOrFail()->slots()->count());
    }

    public function test_csv_colonnes_manquantes_et_lignes_invalides(): void
    {
        $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.import.preview'),
            ['fichier' => $this->fichierCsv("day,start_time\nmonday,08:00\n")]
        )->assertSessionHasErrors('fichier');
    }

    public function test_csv_doublons_detectes(): void
    {
        $contenu = implode("\n", [
            'academic_year,semester,group,day,start_time,end_time,course,teacher,room,type',
            '2026/2027,S1,GI2-A,monday,08:00,10:00,Développement Web,Enseignant A,B12,course',
            '2026/2027,S1,GI2-A,monday,08:00,10:00,Développement Web,Enseignant A,B12,course',
        ]);

        $reponse = $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.import.preview'),
            ['fichier' => $this->fichierCsv($contenu)]
        );

        $reponse->assertOk()->assertSee('Doublon');
        $this->assertSame(0, Schedule::count());
    }

    public function test_json_valide_et_invalide(): void
    {
        $valide = json_encode([
            'academic_year' => '2026/2027', 'semester' => 'S1', 'group' => 'GI2-A',
            'slots' => [
                ['day' => 'wednesday', 'start_time' => '08:00', 'end_time' => '10:00', 'course' => 'WEB101', 'room' => 'B12'],
            ],
        ], JSON_UNESCAPED_UNICODE);

        ['token' => $token] = $this->preview($valide, 'planning.json');

        $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.import.confirmer'),
            ['token' => $token, 'mode' => 'creer']
        )->assertOk();

        $this->assertSame(1, Schedule::firstOrFail()->slots()->count());

        // JSON invalide : refusé proprement.
        $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.import.preview'),
            ['fichier' => UploadedFile::fake()->createWithContent('x.json', '{invalide', 'application/json')]
        )->assertSessionHasErrors('fichier');

        // Structure incorrecte : refusée.
        $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.import.preview'),
            ['fichier' => UploadedFile::fake()->createWithContent('x.json', '{"slots":[]}', 'application/json')]
        )->assertSessionHasErrors('fichier');
    }

    public function test_xml_valide_invalide_et_xxe(): void
    {
        $valide = <<<'XML'
        <schedule>
            <academic_year>2026/2027</academic_year>
            <semester>S1</semester>
            <group>GI2-A</group>
            <slots>
                <slot>
                    <day>thursday</day>
                    <start_time>08:00</start_time>
                    <end_time>10:00</end_time>
                    <course>WEB101</course>
                    <room>B12</room>
                    <type>course</type>
                </slot>
            </slots>
        </schedule>
        XML;

        ['token' => $token] = $this->preview($valide, 'planning.xml');

        $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.import.confirmer'),
            ['token' => $token, 'mode' => 'creer']
        )->assertOk();

        $this->assertSame(1, Schedule::count());

        // XXE refusée.
        $xxe = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><schedule><academic_year>&xxe;</academic_year></schedule>';

        $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.import.preview'),
            ['fichier' => UploadedFile::fake()->createWithContent('x.xml', $xxe, 'application/xml')]
        )->assertSessionHasErrors('fichier');

        // XML malformé refusé.
        $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.import.preview'),
            ['fichier' => UploadedFile::fake()->createWithContent('x.xml', '<schedule><oops>', 'application/xml')]
        )->assertSessionHasErrors('fichier');
    }

    public function test_conflits_bloquent_import(): void
    {
        $contenu = implode("\n", [
            'academic_year,semester,group,day,start_time,end_time,course,teacher,room,type',
            '2026/2027,S1,GI2-A,monday,10:00,11:30,Développement Web,Enseignant A,B12,course',
            '2026/2027,S1,GI2-A,monday,11:00,12:30,Développement Web,Enseignant A,B12,course',
        ]);

        $reponse = $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.import.preview'),
            ['fichier' => $this->fichierCsv($contenu)]
        );

        $reponse->assertOk()->assertSee('CONFLIT');
        $this->assertSame(0, Schedule::count());
    }

    public function test_conflit_base_bloque(): void
    {
        ['token' => $token] = $this->preview($this->csvValide());
        $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.import.confirmer'),
            ['token' => $token, 'mode' => 'creer']
        );

        // Second import chevauchant, mode création → conflit contre la base.
        $reponse = $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.import.preview'),
            ['fichier' => $this->fichierCsv($this->csvValide())]
        );

        $reponse->assertOk()->assertSee('CONFLIT');
    }

    public function test_remplacement_exige_confirmation(): void
    {
        ['token' => $token] = $this->preview($this->csvValide());
        $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.import.confirmer'),
            ['token' => $token, 'mode' => 'creer']
        );
        $this->assertSame(2, Schedule::firstOrFail()->slots()->count());

        // Sans confirmation : refusé, planning intact.
        ['token' => $token2] = $this->preview($this->csvValide());
        $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.import.confirmer'),
            ['token' => $token2, 'mode' => 'remplacer']
        )->assertSessionHasErrors('confirmation');
        $this->assertSame(2, Schedule::firstOrFail()->slots()->count());

        // Avec confirmation : remplacé (nouveau contenu).
        $remplace = str_replace('Développement Web', 'Développement Web', $this->csvValide());
        ['token' => $token3] = $this->preview($remplace);
        $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.import.confirmer'),
            ['token' => $token3, 'mode' => 'remplacer', 'confirmation' => '1']
        )->assertOk();

        $this->assertSame(1, Schedule::count());
        $this->assertSame(2, Schedule::firstOrFail()->slots()->count());
    }

    public function test_rollback_aucun_planning_partiel(): void
    {
        ['token' => $token] = $this->preview($this->csvValide());

        // Token inexistant (36 caractères) : la confirmation re-valide et bloque.
        $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.import.confirmer'),
            ['token' => 'token-inexistant-0000000000000000000', 'mode' => 'creer']
        )->assertSessionHasErrors('fichier');

        $this->assertSame(0, Schedule::count());
        $this->assertSame(0, ScheduleImport::where('status', 'completed')->count());
    }

    public function test_extension_dangereuse_et_taille(): void
    {
        $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.import.preview'),
            ['fichier' => UploadedFile::fake()->createWithContent('evil.php', '<?php echo 1; ?>')]
        )->assertSessionHasErrors('fichier');

        $this->assertSame(0, ScheduleImport::count());
    }

    public function test_permission_et_faculte(): void
    {
        $lecteur = User::creerCompteBackOffice([
            'name' => 'Consultation', 'email' => 'lecteur@universite.test',
            'password' => 'Motdepasse@123',
        ], 'consultation');

        $this->actingAs($lecteur)->get(route('admin.emplois-du-temps.import.form'))->assertForbidden();

        $adminB = User::creerCompteBackOffice([
            'name' => 'Admin B', 'email' => 'admin.b@universite.test',
            'password' => 'Motdepasse@123', 'faculte_id' => Faculte::create([
                'nom' => 'Autre', 'code' => 'AUT', 'description' => 'x', 'active' => true,
            ])->id,
        ], 'admin_faculte');

        // Fichier visant un groupe hors périmètre : bloqué à la confirmation.
        ['token' => $token] = (function () use ($adminB) {
            $reponse = $this->actingAs($adminB)->post(
                route('admin.emplois-du-temps.import.preview'),
                ['fichier' => $this->fichierCsv($this->csvValide())]
            );
            $reponse->assertOk();

            return ['token' => $this->tokenDepuisSession()];
        })();

        $this->actingAs($adminB)->post(
            route('admin.emplois-du-temps.import.confirmer'),
            ['token' => $token, 'mode' => 'creer']
        )->assertSessionHasErrors('fichier');
    }

    public function test_import_page_stepper_et_dropzone(): void
    {
        $this->actingAs($this->admin)->get(route('admin.emplois-du-temps.import.form'))
            ->assertOk()
            ->assertSee('aria-label="Progression"', false)
            ->assertSee('Déposez votre fichier ici')
            ->assertSee("Valider l'import", false);
    }

    public function test_preview_resume_et_modale(): void
    {
        ['token' => $token] = $this->preview($this->csvValide());

        // Rejouer la prévisualisation pour inspecter la page.
        $reponse = $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.import.preview'),
            ['fichier' => $this->fichierCsv($this->csvValide())]
        );

        $reponse->assertOk()
            ->assertSee('Entités reconnues')
            ->assertSee('Remplacer l&#039;emploi du temps ?', false)
            ->assertSee('role="dialog"', false);
    }

    public function test_etudiant_voit_planning_importe(): void
    {
        ['token' => $token] = $this->preview($this->csvValide());
        $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.import.confirmer'),
            ['token' => $token, 'mode' => 'creer']
        );

        // Contrat SCHEDULE-09 : le portail étudiant n'expose que les
        // plannings publiés — l'import (brouillon) doit être publié avant
        // de prétendre à la visibilité étudiante.
        Schedule::where('groupe_id', $this->groupe->id)->firstOrFail()
            ->forceFill(['statut' => 'publie', 'publie_a' => now()])->save();

        $etudiant = Etudiant::create([
            'cin' => 'BK950001', 'nom' => 'Test', 'prenom' => 'Etudiant',
            'email' => 'bk950001@example.com', 'sexe' => 'M',
            'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'Rue',
            'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'motdepasse1', 'groupe_id' => $this->groupe->id,
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-2026-BK950001'])->save();
        $etudiant->definirStatut('valide');

        session(['etudiant_auth_id' => $etudiant->fresh()->id]);

        $this->get(route('etudiant.emploi-du-temps.semaine'))
            ->assertOk()->assertSee('Développement Web');
    }

    /**
     * @param  array<int, array<int, string>>  $lignes
     */
    protected function fichierXlsx(array $lignes): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($chemin);
        foreach ($lignes as $ligne) {
            $writer->addRow(Row::fromValues($ligne));
        }
        $writer->close();

        return new UploadedFile(
            $chemin, 'planning.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null, true
        );
    }

    public function test_xlsx_valide_preview_puis_confirmation(): void
    {
        $fichier = $this->fichierXlsx([
            ['academic_year', 'semester', 'group', 'day', 'start_time', 'end_time', 'course', 'teacher', 'room', 'type'],
            ['2026/2027', 'S1', 'GI2-A', 'friday', '10:00', '12:00', 'Développement Web', 'Enseignant A', 'B12', 'course'],
        ]);

        $reponse = $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.import.preview'),
            ['fichier' => $fichier]
        );

        $reponse->assertOk()->assertSee('Développement Web');

        $token = null;
        foreach (array_keys(session()->get('schedule_imports', [])) as $cle) {
            $token = $cle;
        }

        $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.import.confirmer'),
            ['token' => $token, 'mode' => 'creer']
        )->assertOk();

        $this->assertSame(1, Schedule::firstOrFail()->slots()->count());
    }

    public function test_xlsx_vide_et_colonnes_manquantes(): void
    {
        // En-têtes seules : refusé.
        $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.import.preview'),
            ['fichier' => $this->fichierXlsx([['academic_year', 'semester']])]
        )->assertSessionHasErrors('fichier');

        // Colonnes métier manquantes : refusé.
        $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.import.preview'),
            ['fichier' => $this->fichierXlsx([
                ['academic_year', 'semester', 'group'],
                ['2026/2027', 'S1', 'GI2-A'],
            ])]
        )->assertSessionHasErrors('fichier');

        $this->assertSame(0, Schedule::count());
    }
}
