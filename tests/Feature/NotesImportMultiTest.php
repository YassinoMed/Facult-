<?php

namespace Tests\Feature;

use App\Jobs\ExecuteDataImportJob;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\ImportJob;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\NoteEpreuve;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\CompostageService;
use App\Modules\Evaluation\Services\EpreuveService;
use App\Modules\Evaluation\Services\SaisieNoteService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Services\DataExchange\DefinitionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\ODS\Writer as OdsWriter;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Tests\TestCase;

/**
 * LOOP IMPORT MULTI-FORMAT (§CSV→XLSX→JSON→XML→ODS) : N parsers vers UN
 * DTO normalisé, UN pipeline (DataExchange preview→confirm), UN moteur
 * (SaisieNoteService). Aucun student_id comme autorité, jamais.
 */
class NotesImportMultiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Matiere $ecue;

    private Group $groupe;

    private int $compteur = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.multiformat@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LGL', 'nom' => 'Licence GL',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niveau = Niveau::create(['programme_id' => $programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $semestre = SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $programme->id,
            'code' => 'UE01', 'nom' => 'Algo', 'credits' => 6, 'coefficient' => 2,
        ]);
        $this->ecue = Matiere::create([
            'code' => 'A101', 'nom' => 'Algorithmique', 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 6, 'coefficient' => 2,
        ]);
        $this->groupe = Group::create([
            'code' => 'G1', 'nom' => 'Groupe 1', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'actif' => true,
        ]);
        Room::create(['code' => 'B12', 'nom' => 'B12', 'capacite' => 40, 'actif' => true]);
    }

    private function creerEpreuve(): Epreuve
    {
        $this->compteur++;
        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id, 'code' => 'S-MF-'.$this->compteur.random_int(100, 999),
            'nom' => 'Principale', 'type' => 'principale',
            'date_debut' => '2027-01-05', 'date_fin' => '2027-01-20', 'statut' => 'open', 'created_by' => $this->admin->id,
        ]);
        $examen = Examen::create([
            'session_id' => $session->id, 'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examen S1', 'type' => 'examen', 'statut' => 'scheduled', 'responsable_id' => $this->admin->id,
        ]);

        return app(EpreuveService::class)->creer($examen, [
            'session_id' => $session->id, 'matiere_id' => $this->ecue->id,
            'groupe_id' => $this->groupe->id,
            'date' => '2027-01-'.sprintf('%02d', 10 + $this->compteur),
            'heure_debut' => sprintf('%02d:00', 7 + $this->compteur),
            'heure_fin' => sprintf('%02d:00', 9 + $this->compteur),
        ]);
    }

    private function creerCandidat(Epreuve $epreuve, string $cin): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'sara.'.mb_strtolower(preg_replace('/[^a-z0-9]/i', '', $cin)).'@test.ma', 'date_naissance' => '2004-01-01',
            'sexe' => 'F', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => '12 rue', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');
        $inscription = $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
        ]);
        $inscription->semestres()->create(['numero' => 1]);
        $inscription->semestres()->create(['numero' => 2]);
        Candidat::create(['epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'present']);

        return $etudiant;
    }

    private function numeros(Epreuve $epreuve, Etudiant ...$etudiants): string
    {
        $service = app(CompostageService::class);
        $numeros = [];

        foreach ($etudiants as $etudiant) {
            $numeros[] = $service->getOrCreate($epreuve, $etudiant)->numero;
        }

        return implode(',', $numeros);
    }

    private function preview(Epreuve $epreuve, UploadedFile $fichier): TestResponse
    {
        return $this->actingAs($this->admin)->post(
            route('admin.evaluations.notes.import-multi', $epreuve),
            ['fichier' => $fichier]
        );
    }

    private function confirmer(Epreuve $epreuve, string $token, string $mode = 'upsert'): TestResponse
    {
        $reponse = $this->actingAs($this->admin)->post(
            route('admin.evaluations.notes.import-multi-confirmer', $epreuve),
            ['token' => $token, 'mode' => $mode]
        );

        // Confirmation synchrone → vue rapport (200) ; volumineux → file (redirect).
        if ($reponse->status() === 200) {
            $reponse->assertSee('Rapport d’import');
        }

        return $reponse;
    }

    private function extraireToken(TestResponse $reponse): string
    {
        $reponse->assertOk();
        $token = $reponse->viewData('token');
        $this->assertNotEmpty($token);

        return $token;
    }

    private function fichierXlsx(string $nom, array $entetes, array $lignes): UploadedFile
    {
        $chemin = sys_get_temp_dir()."/{$nom}-".uniqid().'.xlsx';
        $writer = new XlsxWriter(new XlsxOptions);
        $writer->openToFile($chemin);
        $writer->addRow(Row::fromValues($entetes));

        foreach ($lignes as $ligne) {
            $writer->addRow(Row::fromValues($ligne));
        }

        $writer->close();

        return new UploadedFile($chemin, $nom.'.xlsx', null, null, true);
    }

    private function fichierOds(string $nom, array $entetes, array $lignes): UploadedFile
    {
        $chemin = sys_get_temp_dir()."/{$nom}-".uniqid().'.ods';
        $writer = new OdsWriter;
        $writer->openToFile($chemin);
        $writer->addRow(Row::fromValues($entetes));

        foreach ($lignes as $ligne) {
            $writer->addRow(Row::fromValues($ligne));
        }

        $writer->close();

        return new UploadedFile($chemin, $nom.'.ods', null, null, true);
    }

    // ── Socle : entité + preview→confirm ──────────────────────────

    public function test_entite_import_enregistree(): void
    {
        $definition = app(DefinitionRegistry::class)->import('notes-epreuve-anonymes');
        $this->assertSame('notes-epreuve-anonymes', $definition->entite());
    }

    public function test_preview_csv_sans_ecriture(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerCandidat($epreuve, 'MFCSV001');
        [$numero] = explode(',', $this->numeros($epreuve, $etudiant));

        $fichier = UploadedFile::fake()->createWithContent(
            'notes.csv', "compostage,note\n{$numero},14.5\n"
        );

        $token = $this->extraireToken($this->preview($epreuve, $fichier));

        // Preview ne doit rien écrire.
        $this->assertSame(0, NoteEpreuve::where('epreuve_id', $epreuve->id)->count());

        $this->confirmer($epreuve, $token)->assertOk();
        $this->assertDatabaseHas('evaluation_notes', [
            'epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'valeur' => 14.50,
        ]);
    }

    public function test_alias_numero_valeur(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerCandidat($epreuve, 'MFALIAS01');
        [$numero] = explode(',', $this->numeros($epreuve, $etudiant));

        $fichier = UploadedFile::fake()->createWithContent(
            'notes.csv', "numero;valeur\n{$numero};11\n"
        );

        $token = $this->extraireToken($this->preview($epreuve, $fichier));
        $this->confirmer($epreuve, $token)->assertOk();
        $this->assertDatabaseHas('evaluation_notes', [
            'epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'valeur' => 11.00,
        ]);
    }

    // ── Erreurs CSV ───────────────────────────────────────────

    public function test_csv_note_hors_bornes_bloquee(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerCandidat($epreuve, 'MFBORN01');
        [$numero] = explode(',', $this->numeros($epreuve, $etudiant));

        $fichier = UploadedFile::fake()->createWithContent(
            'notes.csv', "compostage,note\n{$numero},27\n"
        );

        $reponse = $this->preview($epreuve, $fichier);
        $reponse->assertOk();
        $this->assertNotEmpty($reponse->viewData('resultat')->erreurs);
        $this->assertSame('NOTE_OUT_OF_RANGE', $reponse->viewData('resultat')->erreurs[0]['code']);
        $this->assertSame(0, NoteEpreuve::where('epreuve_id', $epreuve->id)->count());
    }

    public function test_csv_compostage_inexistant_bloque(): void
    {
        $epreuve = $this->creerEpreuve();

        $fichier = UploadedFile::fake()->createWithContent(
            'notes.csv', "compostage,note\nCMP-000000,12\n"
        );

        $reponse = $this->preview($epreuve, $fichier);
        $reponse->assertOk();
        $this->assertSame('COMPOSTAGE_NOT_FOUND', $reponse->viewData('resultat')->erreurs[0]['code']);
    }

    public function test_csv_compostage_autre_epreuve_bloque(): void
    {
        $epreuveA = $this->creerEpreuve();
        $epreuveB = $this->creerEpreuve();
        $etudiant = $this->creerCandidat($epreuveB, 'MFIDOR01');
        [$numeroA] = explode(',', $this->numeros($epreuveA, $etudiant));

        $fichier = UploadedFile::fake()->createWithContent(
            'notes.csv', "compostage,note\n{$numeroA},12\n"
        );

        $reponse = $this->preview($epreuveB, $fichier);
        $reponse->assertOk();
        $this->assertSame('COMPOSTAGE_NOT_FOUND', $reponse->viewData('resultat')->erreurs[0]['code']);
        $this->assertSame(0, NoteEpreuve::where('epreuve_id', $epreuveB->id)->count());
    }

    public function test_csv_colonne_pii_rejette_fichier(): void
    {
        $epreuve = $this->creerEpreuve();

        $fichier = UploadedFile::fake()->createWithContent(
            'notes.csv', "compostage,note,student_id\nCMP-123456,12,1\n"
        );

        $this->preview($epreuve, $fichier)->assertSessionHasErrors('fichier');
        $this->assertSame(0, NoteEpreuve::where('epreuve_id', $epreuve->id)->count());
    }

    public function test_csv_doublon_intra_fichier_bloque(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerCandidat($epreuve, 'MFDUP001');
        [$numero] = explode(',', $this->numeros($epreuve, $etudiant));

        $fichier = UploadedFile::fake()->createWithContent(
            'notes.csv', "compostage,note\n{$numero},12\n{$numero},14\n"
        );

        $reponse = $this->preview($epreuve, $fichier);
        $reponse->assertOk();
        $this->assertSame('DUPLICATE_FILE', $reponse->viewData('resultat')->erreurs[0]['code']);
    }

    public function test_csv_sans_colonne_compostage_refuse(): void
    {
        $epreuve = $this->creerEpreuve();

        $fichier = UploadedFile::fake()->createWithContent('notes.csv', "note\n12\n");

        $this->preview($epreuve, $fichier)->assertSessionHasErrors('fichier');
    }

    // ── Verrouillage ──────────────────────────────────────────

    public function test_preview_refusee_epreuve_verrouillee(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerCandidat($epreuve, 'MFLOCK01');
        [$numero] = explode(',', $this->numeros($epreuve, $etudiant));
        $epreuve->update(['statut' => 'locked']);

        $fichier = UploadedFile::fake()->createWithContent(
            'notes.csv', "compostage,note\n{$numero},12\n"
        );

        $this->preview($epreuve, $fichier)->assertSessionHasErrors('fichier');
        $this->assertSame(0, NoteEpreuve::where('epreuve_id', $epreuve->id)->count());
    }

    public function test_confirm_apres_verrouillage_refuse_sans_ecriture(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerCandidat($epreuve, 'MFTOCTOU1');
        [$numero] = explode(',', $this->numeros($epreuve, $etudiant));

        $fichier = UploadedFile::fake()->createWithContent(
            'notes.csv', "compostage,note\n{$numero},12\n"
        );

        $token = $this->extraireToken($this->preview($epreuve, $fichier));

        // Verrou posé ENTRE preview et confirmation (TOCTOU).
        $epreuve->update(['statut' => 'locked']);

        $this->confirmer($epreuve, $token)->assertSessionHasErrors('fichier');
        $this->assertSame(0, NoteEpreuve::where('epreuve_id', $epreuve->id)->count());
    }

    public function test_double_confirmation_refusee(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerCandidat($epreuve, 'MFDBL001');
        [$numero] = explode(',', $this->numeros($epreuve, $etudiant));

        $fichier = UploadedFile::fake()->createWithContent(
            'notes.csv', "compostage,note\n{$numero},12\n"
        );

        $token = $this->extraireToken($this->preview($epreuve, $fichier));
        $this->confirmer($epreuve, $token)->assertOk();

        // Token consommé : la seconde confirmation est refusée.
        $this->confirmer($epreuve, $token)->assertSessionHasErrors('fichier');
        $this->assertSame(1, NoteEpreuve::where('epreuve_id', $epreuve->id)->count());
    }

    public function test_mode_creer_refuse_une_note_existante(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerCandidat($epreuve, 'MFMODE001');
        [$numero] = explode(',', $this->numeros($epreuve, $etudiant));

        app(SaisieNoteService::class)->saisirLot(
            $epreuve,
            [['etudiant_id' => $etudiant->id, 'valeur' => 12]],
            $this->admin->id
        );

        $fichier = UploadedFile::fake()->createWithContent(
            'notes.csv', "compostage,note\n{$numero},16\n"
        );

        $token = $this->extraireToken($this->preview($epreuve, $fichier));

        $this->confirmer($epreuve, $token, 'creer')->assertSessionHasErrors('fichier');
        $this->assertEquals(
            12.00,
            (float) NoteEpreuve::where('epreuve_id', $epreuve->id)->firstOrFail()->valeur
        );
    }

    // ── XLSX ──────────────────────────────────────────────

    public function test_xlsx_valide(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerCandidat($epreuve, 'MFXLSX01');
        [$numero] = explode(',', $this->numeros($epreuve, $etudiant));

        $fichier = $this->fichierXlsx('notes', ['Compostage', 'Note'], [[$numero, 15.5]]);

        $token = $this->extraireToken($this->preview($epreuve, $fichier));
        $this->confirmer($epreuve, $token)->assertOk();
        $this->assertDatabaseHas('evaluation_notes', [
            'epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'valeur' => 15.50,
        ]);
    }

    public function test_xlsx_pii_rejette(): void
    {
        $epreuve = $this->creerEpreuve();

        $fichier = $this->fichierXlsx('notes', ['compostage', 'note', 'cin'], [['CMP-123456', 12, 'AB123']]);

        $this->preview($epreuve, $fichier)->assertSessionHasErrors('fichier');
    }

    public function test_xlsx_corrompu_erreur_propre(): void
    {
        $epreuve = $this->creerEpreuve();

        // En-tête ZIP valide (MIME application/zip) mais contenu illisible.
        $fichier = UploadedFile::fake()->createWithContent('notes.xlsx', "PK\x03\x04".str_repeat('x', 200));

        $this->preview($epreuve, $fichier)->assertSessionHasErrors('fichier');
        $this->assertSame(0, NoteEpreuve::where('epreuve_id', $epreuve->id)->count());
    }

    // ── XLS (BIFF sans lecteur : refus propre documenté) ──

    public function test_xls_refuse_proprement(): void
    {
        $epreuve = $this->creerEpreuve();

        $fichier = UploadedFile::fake()->createWithContent('notes.xls', "compostage,note\nCMP-123456,12\n");

        $this->preview($epreuve, $fichier)->assertSessionHasErrors('fichier');
        $this->assertSame(0, NoteEpreuve::where('epreuve_id', $epreuve->id)->count());
    }

    // ── JSON ──────────────────────────────────────────────

    public function test_json_valide(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerCandidat($epreuve, 'MFJSON01');
        [$numero] = explode(',', $this->numeros($epreuve, $etudiant));

        $fichier = UploadedFile::fake()->createWithContent(
            'notes.json', json_encode([['compostage' => $numero, 'note' => 13.5]])
        );

        $token = $this->extraireToken($this->preview($epreuve, $fichier));
        $this->confirmer($epreuve, $token)->assertOk();
        $this->assertDatabaseHas('evaluation_notes', [
            'epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'valeur' => 13.50,
        ]);
    }

    public function test_json_enveloppe_notes(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerCandidat($epreuve, 'MFJSON02');
        [$numero] = explode(',', $this->numeros($epreuve, $etudiant));

        $fichier = UploadedFile::fake()->createWithContent(
            'notes.json', json_encode(['notes' => [['compostage' => $numero, 'note' => 9.5]]])
        );

        $token = $this->extraireToken($this->preview($epreuve, $fichier));
        $this->confirmer($epreuve, $token)->assertOk();
        $this->assertDatabaseHas('evaluation_notes', [
            'epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'valeur' => 9.50,
        ]);
    }

    public function test_json_invalide_refuse(): void
    {
        $epreuve = $this->creerEpreuve();

        $fichier = UploadedFile::fake()->createWithContent('notes.json', '{"notes": [}');

        $this->preview($epreuve, $fichier)->assertSessionHasErrors('fichier');
    }

    public function test_json_pii_rejette(): void
    {
        $epreuve = $this->creerEpreuve();

        $fichier = UploadedFile::fake()->createWithContent(
            'notes.json', json_encode([['compostage' => 'CMP-123456', 'note' => 12, 'cin' => 'AB123']])
        );

        $this->preview($epreuve, $fichier)->assertSessionHasErrors('fichier');
    }

    // ── XML ───────────────────────────────────────────────

    public function test_xml_valide_row(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerCandidat($epreuve, 'MFXML001');
        [$numero] = explode(',', $this->numeros($epreuve, $etudiant));

        $fichier = UploadedFile::fake()->createWithContent(
            'notes.xml',
            "<rows><row><compostage>{$numero}</compostage><note>16</note></row></rows>"
        );

        $token = $this->extraireToken($this->preview($epreuve, $fichier));
        $this->confirmer($epreuve, $token)->assertOk();
        $this->assertDatabaseHas('evaluation_notes', [
            'epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'valeur' => 16.00,
        ]);
    }

    public function test_xml_valide_note_items(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerCandidat($epreuve, 'MFXML002');
        [$numero] = explode(',', $this->numeros($epreuve, $etudiant));

        $fichier = UploadedFile::fake()->createWithContent(
            'notes.xml',
            "<notes><note><compostage>{$numero}</compostage><note>10.5</note></note></notes>"
        );

        $token = $this->extraireToken($this->preview($epreuve, $fichier));
        $this->confirmer($epreuve, $token)->assertOk();
        $this->assertDatabaseHas('evaluation_notes', [
            'epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'valeur' => 10.50,
        ]);
    }

    public function test_xml_xxe_refuse(): void
    {
        $epreuve = $this->creerEpreuve();

        $fichier = UploadedFile::fake()->createWithContent(
            'notes.xml',
            '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><rows><row><compostage>&xxe;</compostage><note>12</note></row></rows>'
        );

        $this->preview($epreuve, $fichier)->assertSessionHasErrors('fichier');
        $this->assertSame(0, NoteEpreuve::where('epreuve_id', $epreuve->id)->count());
    }

    public function test_xml_malforme_refuse(): void
    {
        $epreuve = $this->creerEpreuve();

        $fichier = UploadedFile::fake()->createWithContent('notes.xml', '<rows><row><compostage>CMP-1');

        $this->preview($epreuve, $fichier)->assertSessionHasErrors('fichier');
    }

    // ── ODS ───────────────────────────────────────────────

    public function test_ods_valide(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerCandidat($epreuve, 'MFODS001');
        [$numero] = explode(',', $this->numeros($epreuve, $etudiant));

        $fichier = $this->fichierOds('notes', ['compostage', 'note'], [[$numero, 17]]);

        $token = $this->extraireToken($this->preview($epreuve, $fichier));
        $this->confirmer($epreuve, $token)->assertOk();
        $this->assertDatabaseHas('evaluation_notes', [
            'epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'valeur' => 17.00,
        ]);
    }

    // ── Sécurité : permissions, scope, IDOR ────────────────

    public function test_preview_sans_permission_import_403(): void
    {
        $epreuve = $this->creerEpreuve();
        // Rôle consultation : notes_eval.view seul, sans enter (convention
        // épreuve : import = enter, comme import-anonyme/export-anonyme).
        $lecteur = User::creerCompteBackOffice([
            'name' => 'Lecteur', 'email' => 'lecteur.multiformat@universite.test', 'password' => 'Motdepasse@123',
        ], 'consultation');

        $fichier = UploadedFile::fake()->createWithContent('notes.csv', "compostage,note\nCMP-123456,12\n");

        $this->actingAs($lecteur)->post(
            route('admin.evaluations.notes.import-multi', $epreuve),
            ['fichier' => $fichier]
        )->assertForbidden();
    }

    public function test_preview_hors_perimetre_404(): void
    {
        $autreFaculte = Faculte::create(['nom' => 'FSJES', 'code' => 'FSJES', 'description' => 'T', 'active' => true]);
        $adminRestreint = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.multiformat@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $autreFaculte->id,
        ], 'admin_faculte');

        $epreuve = $this->creerEpreuve();

        $fichier = UploadedFile::fake()->createWithContent('notes.csv', "compostage,note\nCMP-123456,12\n");

        $this->actingAs($adminRestreint)->post(
            route('admin.evaluations.notes.import-multi', $epreuve),
            ['fichier' => $fichier]
        )->assertNotFound();
    }

    public function test_preview_sans_pii_dans_reponse(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerCandidat($epreuve, 'MFNOPII01');
        [$numero] = explode(',', $this->numeros($epreuve, $etudiant));

        $fichier = UploadedFile::fake()->createWithContent(
            'notes.csv', "compostage,note\n{$numero},12\nCMP-000000,99\n"
        );

        $reponse = $this->preview($epreuve, $fichier);
        $reponse->assertOk();
        $contenu = (string) $reponse->getContent();

        $this->assertStringContainsString($numero, $contenu);
        $this->assertStringNotContainsString($etudiant->nom, $contenu);
        $this->assertStringNotContainsString($etudiant->prenom, $contenu);
        $this->assertStringNotContainsString($etudiant->cin, $contenu);
        $this->assertStringNotContainsString($etudiant->email, $contenu);
        $this->assertStringNotContainsString('Benali', $contenu);
    }

    // ── Cycle export → réimport ────────────────────────────

    public function test_export_reimport_cycle(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerCandidat($epreuve, 'MF cycle01');
        [$numero] = explode(',', $this->numeros($epreuve, $etudiant));

        // Export anonyme officiel, modification externe de la note.
        $export = $this->actingAs($this->admin)->get(
            route('admin.evaluations.notes.export-anonyme', $epreuve)
        )->assertOk()->streamedContent();

        $this->assertStringContainsString($numero, (string) $export);

        // Réimport avec l'en-tête RÉELLE de l'export (compatibilité
        // prouvée du pipeline), seule la note est modifiée en externe.
        // La colonne `statut` inconnue est ignorée, le compte de
        // colonnes doit rester cohérent (garde anti-décalage).
        $lignesExport = explode("\n", trim((string) $export));
        $this->assertCount(2, $lignesExport);
        $fichier = UploadedFile::fake()->createWithContent(
            'reimport.csv', $lignesExport[0]."\n{$numero},15.5,present"
        );

        $token = $this->extraireToken($this->preview($epreuve, $fichier));
        $this->confirmer($epreuve, $token)->assertOk();
        $this->assertDatabaseHas('evaluation_notes', [
            'epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'valeur' => 15.50,
        ]);
    }

    // ── Note verrouillée existante ─────────────────────────

    public function test_import_note_verrouillee_bloquee(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerCandidat($epreuve, 'MFVERL01');
        [$numero] = explode(',', $this->numeros($epreuve, $etudiant));

        $saisie = app(SaisieNoteService::class);
        $saisie->saisirLot($epreuve, [['etudiant_id' => $etudiant->id, 'valeur' => 12]], $this->admin->id);
        $note = NoteEpreuve::where('epreuve_id', $epreuve->id)->firstOrFail();
        $saisie->valider($note->fresh(), $this->admin->id);
        $saisie->verrouiller($note->fresh(), $this->admin->id, 'Jury');

        $fichier = UploadedFile::fake()->createWithContent(
            'notes.csv', "compostage,note\n{$numero},16\n"
        );

        $reponse = $this->preview($epreuve, $fichier);
        $reponse->assertOk();
        $this->assertSame('NOTE_VERROUILLEE', $reponse->viewData('resultat')->erreurs[0]['code']);
        $this->assertEquals(12.00, (float) NoteEpreuve::where('epreuve_id', $epreuve->id)->firstOrFail()->valeur);
    }

    // ── Limites ────────────────────────────────────────────

    public function test_fichier_trop_volumineux_refuse(): void
    {
        $epreuve = $this->creerEpreuve();

        $lignes = ['compostage,note'];

        for ($i = 0; $i < 5001; $i++) {
            $lignes[] = 'CMP-'.(100000 + ($i % 800000)).',12';
        }

        $fichier = UploadedFile::fake()->createWithContent('notes.csv', implode("\n", $lignes));

        $this->preview($epreuve, $fichier)->assertSessionHasErrors('fichier');
        $this->assertSame(0, NoteEpreuve::where('epreuve_id', $epreuve->id)->count());
    }

    // ── Performance mesurée ────────────────────────────────

    public function test_performance_100_et_1000_lignes(): void
    {
        foreach ([100, 1000] as $volume) {
            $epreuve = $this->creerEpreuve();
            $lignes = ['compostage,note'];
            $numeros = [];

            for ($i = 0; $i < $volume; $i++) {
                $etudiant = $this->creerCandidat($epreuve, 'MFPERF'.str_pad((string) $i, 4, '0', STR_PAD_LEFT).$this->compteur);
                [$numero] = explode(',', $this->numeros($epreuve, $etudiant));
                $numeros[] = $numero;
                $lignes[] = "{$numero},".(10 + ($i % 9)).'.5';
            }

            $fichier = UploadedFile::fake()->createWithContent('notes.csv', implode("\n", $lignes));

            $debut = microtime(true);
            \DB::enableQueryLog();
            $token = $this->extraireToken($this->preview($epreuve, $fichier));
            $requetesPreview = count(\DB::getQueryLog());
            \DB::flushQueryLog();
            $reponseConfirm = $this->confirmer($epreuve, $token);

            if ($reponseConfirm->status() === 302) {
                // Volume > seuil file (500) : traitement asynchrone, rien
                // d'écrit en synchrone mais job stagé (traçabilité).
                $reponseConfirm->assertRedirect();
                $job = ImportJob::where('entite', 'notes-epreuve-anonymes')->firstOrFail();

                // Exécution synchrone du job en test : le contexte épreuve
                // voyage via les options, les 1000 notes sont écrites.
                app()->call([(new ExecuteDataImportJob($job->id)), 'handle']);
                $this->assertSame($volume, NoteEpreuve::where('epreuve_id', $epreuve->id)->count());

                $requetesConfirm = count(\DB::getQueryLog());
                \DB::disableQueryLog();
                $duree = microtime(true) - $debut;

                fwrite(
                    STDERR,
                    "\n[PERF-IMPORT] volume={$volume} duree=".round($duree, 2).'s '.
                    "req_preview={$requetesPreview} req_confirm={$requetesConfirm} ".
                    'pic_memoire='.round(memory_get_peak_usage(true) / 1048576, 1)."Mo (FILE+JOB)\n"
                );

                $this->assertLessThan(1500, $requetesConfirm, "Import {$volume} lignes : budget de requêtes dépassé.");

                continue;
            }

            $reponseConfirm->assertOk();
            $requetesConfirm = count(\DB::getQueryLog());
            \DB::disableQueryLog();
            $duree = microtime(true) - $debut;

            fwrite(
                STDERR,
                "\n[PERF-IMPORT] volume={$volume} duree=".round($duree, 2).'s '.
                "req_preview={$requetesPreview} req_confirm={$requetesConfirm} ".
                'pic_memoire='.round(memory_get_peak_usage(true) / 1048576, 1)."Mo\n"
            );

            $this->assertLessThan(1500, $requetesConfirm, "Import {$volume} lignes : budget de requêtes dépassé.");
            $this->assertSame($volume, NoteEpreuve::where('epreuve_id', $epreuve->id)->count());
            $this->assertLessThan(120, $duree, "Import {$volume} lignes trop lent.");
        }
    }
}
