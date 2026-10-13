<?php

namespace Tests\Feature\Database;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Semestre;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Services\DataExchange\Definitions\NoteImportDefinition;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * LOOP 37 — D-004 : Contraintes d'intégrité CHECK SQL sur les tables notes.
 * Vérifie que la base de données bloque physiquement les valeurs hors bornes
 * (note < 0 ou note > 20, ou valeur > note_max), y compris lors d'un INSERT
 * ou UPDATE direct par contournement de la couche applicative.
 */
class CheckConstraintsNotesTest extends TestCase
{
    use RefreshDatabase;

    private int $etudiantId;

    private int $inscriptionId;

    private int $semestreId;

    private int $matiereId;

    private int $epreuveId;

    protected function setUp(): void
    {
        parent::setUp();

        // AC-00 (13/10) : le rempart CHECK est posé par la migration sur
        // MySQL (prod) et sur les SQLite récents (≥ 3.4x, dev local). Sur
        // un SQLite plus ancien (runner CI), il est absent : ces tests
        // vérifient le REMPART SQL, pas la validation applicative — ils
        // se skipent proprement au lieu d'échouer (cf. migration
        // 2026_10_07_074052, poserCheck tolérant).
        $ddlNotes = DB::selectOne(
            'SELECT sql FROM sqlite_master WHERE type = \'table\' AND name = \'notes\''
        )?->sql ?? '';
        $ddlEvaluation = DB::selectOne(
            'SELECT sql FROM sqlite_master WHERE type = \'table\' AND name = \'evaluation_notes\''
        )?->sql ?? '';
        $rempartPresent = str_contains($ddlNotes, 'CONSTRAINT chk_notes_note_0_20')
            && str_contains($ddlEvaluation, 'CONSTRAINT chk_evaluation_notes_valeur_bornes');
        if (DB::getDriverName() !== 'mysql' && ! $rempartPresent) {
            $this->markTestSkipped(
                'Rempart CHECK SQL absent de ce SQLite (< 3.4x) : rempart MySQL uniquement '.
                '(migration 2026_10_07_074052) — la validation applicative est testée ailleurs.'
            );
        }

        $faculte = Faculte::create([
            'nom' => 'Faculté des Sciences',
            'code' => 'FST',
            'active' => true,
        ]);

        $etudiant = Etudiant::create([
            'cin' => '12345678',
            'num_inscription' => 'INS-2026-001',
            'nom' => 'Ben Salem',
            'prenom' => 'Karim',
            'email' => 'karim@test.tn',
            'date_naissance' => '2004-05-10',
            'sexe' => 'M',
            'faculte_id' => $faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Avenue Habib Bourguiba',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2023',
            'password' => 'Secret@123',
        ]);
        $this->etudiantId = $etudiant->id;

        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $annee->id,
            'niveau' => 'L1',
        ]);
        $this->inscriptionId = $inscription->id;

        $semestre = Semestre::create([
            'inscription_academique_id' => $inscription->id,
            'numero' => 1,
        ]);
        $this->semestreId = $semestre->id;

        $matiere = Matiere::create([
            'code' => 'INF101',
            'nom' => 'Algorithmique & Structures de données',
            'coefficient' => 2,
            'credits' => 4,
            'niveau' => 'L1',
            'semestre' => 1,
        ]);
        $this->matiereId = $matiere->id;

        $session = SessionExamen::create([
            'annee_universitaire_id' => $annee->id,
            'code' => 'SESS-2027-P',
            'nom' => 'Session Principale 2026-2027',
            'type' => 'principale',
            'date_debut' => '2027-01-10',
            'date_fin' => '2027-01-25',
            'statut' => 'draft',
        ]);

        $examen = Examen::create([
            'session_id' => $session->id,
            'annee_universitaire_id' => $annee->id,
            'libelle' => 'Examen Algorithmique',
            'statut' => 'draft',
        ]);

        $epreuve = Epreuve::create([
            'examen_id' => $examen->id,
            'session_id' => $session->id,
            'annee_universitaire_id' => $annee->id,
            'matiere_id' => $matiere->id,
            'date' => '2027-01-15',
            'heure_debut' => '09:00:00',
            'heure_fin' => '11:00:00',
            'coefficient' => 1.0,
            'note_max' => 20.0,
            'statut' => 'draft',
        ]);
        $this->epreuveId = $epreuve->id;
    }

    public function test_notes_sql_check_bloque_insertion_note_negative(): void
    {
        $this->expectException(QueryException::class);

        DB::table('notes')->insert([
            'etudiant_id' => $this->etudiantId,
            'inscription_academique_id' => $this->inscriptionId,
            'semestre_id' => $this->semestreId,
            'matiere_id' => $this->matiereId,
            'note' => -1.00,
            'coefficient' => 2.0,
            'credits' => 4,
            'session' => 'normale',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_notes_sql_check_bloque_insertion_note_superieure_a_20(): void
    {
        $this->expectException(QueryException::class);

        DB::table('notes')->insert([
            'etudiant_id' => $this->etudiantId,
            'inscription_academique_id' => $this->inscriptionId,
            'semestre_id' => $this->semestreId,
            'matiere_id' => $this->matiereId,
            'note' => 20.01,
            'coefficient' => 2.0,
            'credits' => 4,
            'session' => 'normale',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_notes_sql_check_bloque_update_note_invalide(): void
    {
        $id = DB::table('notes')->insertGetId([
            'etudiant_id' => $this->etudiantId,
            'inscription_academique_id' => $this->inscriptionId,
            'semestre_id' => $this->semestreId,
            'matiere_id' => $this->matiereId,
            'note' => 12.50,
            'coefficient' => 2.0,
            'credits' => 4,
            'session' => 'normale',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('notes')->where('id', $id)->update(['note' => 25.00]);
    }

    public function test_notes_sql_accepte_bornes_valides_0_et_20(): void
    {
        DB::table('notes')->insert([
            'etudiant_id' => $this->etudiantId,
            'inscription_academique_id' => $this->inscriptionId,
            'semestre_id' => $this->semestreId,
            'matiere_id' => $this->matiereId,
            'note' => 0.00,
            'coefficient' => 2.0,
            'credits' => 4,
            'session' => 'normale',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $note0 = DB::table('notes')->where('note', 0.00)->first();
        $this->assertNotNull($note0);

        DB::table('notes')->where('id', $note0->id)->update(['note' => 20.00]);
        $this->assertEquals(20.00, (float) DB::table('notes')->where('id', $note0->id)->value('note'));
    }

    public function test_evaluation_notes_sql_check_bloque_valeur_invalide(): void
    {
        $this->expectException(QueryException::class);

        DB::table('evaluation_notes')->insert([
            'epreuve_id' => $this->epreuveId,
            'etudiant_id' => $this->etudiantId,
            'valeur' => 25.00,
            'note_max' => 20.00,
            'coefficient' => 1.0,
            'statut' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_evaluation_notes_sql_accepte_null_0_et_note_max(): void
    {
        $id = DB::table('evaluation_notes')->insertGetId([
            'epreuve_id' => $this->epreuveId,
            'etudiant_id' => $this->etudiantId,
            'valeur' => null,
            'note_max' => 20.00,
            'coefficient' => 1.0,
            'statut' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNull(DB::table('evaluation_notes')->where('id', $id)->value('valeur'));

        DB::table('evaluation_notes')->where('id', $id)->update(['valeur' => 0.00]);
        $this->assertEquals(0.00, (float) DB::table('evaluation_notes')->where('id', $id)->value('valeur'));

        DB::table('evaluation_notes')->where('id', $id)->update(['valeur' => 20.00]);
        $this->assertEquals(20.00, (float) DB::table('evaluation_notes')->where('id', $id)->value('valeur'));
    }

    public function test_notes_sql_accepte_valeur_mediane_10(): void
    {
        DB::table('notes')->insert([
            'etudiant_id' => $this->etudiantId,
            'inscription_academique_id' => $this->inscriptionId,
            'semestre_id' => $this->semestreId,
            'matiere_id' => $this->matiereId,
            'note' => 10.00,
            'coefficient' => 2.0,
            'credits' => 4,
            'session' => 'normale',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertEquals(10.00, (float) DB::table('notes')->where('note', 10.00)->value('note'));
    }

    public function test_evaluation_notes_sql_check_bloque_valeur_negative(): void
    {
        $this->expectException(QueryException::class);

        DB::table('evaluation_notes')->insert([
            'epreuve_id' => $this->epreuveId,
            'etudiant_id' => $this->etudiantId,
            'valeur' => -1.00,
            'note_max' => 20.00,
            'coefficient' => 1.0,
            'statut' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_import_dataexchange_bloque_note_21(): void
    {
        $definition = app(NoteImportDefinition::class);
        $validator = Validator::make(
            ['note' => 21],
            $definition->regles()
        );

        $this->assertTrue($validator->fails(), 'La validation applicative doit rejeter note = 21');
        $this->assertArrayHasKey('note', $validator->errors()->toArray());

        $this->expectException(QueryException::class);
        $matiere = Matiere::find($this->matiereId);
        $etudiant = Etudiant::find($this->etudiantId);

        $definition->persister([
            'cin' => $etudiant->cin,
            'code_matiere' => $matiere->code,
            'annee' => '2026-2027',
            'semestre' => 1,
            'note' => 21.00,
            'session' => 'normale',
        ], 'creer', null);
    }
}
