<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\Semestre;
use App\Models\User;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\NoteEpreuve;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\SaisieNoteService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * LOOP INDUSTRIALISATION ÉVALUATION — SEC-012 / P0-03.
 *
 * L'historique des notes (avant/après) est un journal critique :
 * il doit être append-only au niveau modèle (aucune mise à jour ni
 * suppression possible, y compris par appel direct au service).
 */
class NotesHistoriqueAppendOnlyTest extends TestCase
{
    use RefreshDatabase;

    private Epreuve $epreuve;

    private Etudiant $etudiant;

    private NoteEpreuve $note;

    protected function setUp(): void
    {
        parent::setUp();

        $faculte = Faculte::create([
            'nom' => 'Faculté Historique Test',
            'code' => 'FHT-'.substr(md5(uniqid('', true)), 0, 4),
            'active' => true,
        ]);

        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $matiere = Matiere::create([
            'code' => 'HIST-'.substr(md5(uniqid('', true)), 0, 5),
            'nom' => 'Matière historique',
            'credits' => 3,
            'coefficient' => 1,
            'niveau' => 'L1',
            'semestre' => 1,
            'actif' => true,
        ]);

        $this->etudiant = Etudiant::create([
            'cin' => 'BK'.random_int(100000, 999999),
            'nom' => 'HistoTest',
            'prenom' => 'Note',
            'email' => uniqid('histo.', true).'@example.com',
            'sexe' => 'M',
            'faculte_id' => $faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '1 Rue du Test',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2024',
            'password' => 'motdepasse1',
        ]);

        $session = SessionExamen::create([
            'annee_universitaire_id' => $annee->id,
            'code' => 'S-HIST-'.substr(md5(uniqid('', true)), 0, 5),
            'nom' => 'Session historique',
            'type' => 'principale',
            'date_debut' => '2027-01-05',
            'date_fin' => '2027-01-20',
            'statut' => 'open',
        ]);

        $examen = Examen::create([
            'session_id' => $session->id,
            'annee_universitaire_id' => $annee->id,
            'libelle' => 'Examen historique',
            'type' => 'examen',
            'statut' => 'completed',
        ]);

        $this->epreuve = Epreuve::create([
            'session_id' => $session->id,
            'examen_id' => $examen->id,
            'annee_universitaire_id' => $annee->id,
            'matiere_id' => $matiere->id,
            'date' => '2027-01-10',
            'heure_debut' => '09:00:00',
            'heure_fin' => '11:00:00',
            'type_evaluation' => 'examen',
            'bareme' => 20,
            'note_max' => 20,
            'coefficient' => 1,
            'capacite_necessaire' => 0,
            'statut' => 'completed',
        ]);

        // Chaîne académique : inscription active + semestre S1
        // (la resync vers la note officielle exige ce rattachement).
        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $this->etudiant->id,
            'annee_universitaire_id' => $annee->id,
            'niveau' => 'L1',
            'statut' => 'inscrit',
        ]);

        Semestre::create([
            'inscription_academique_id' => $inscription->id,
            'numero' => 1,
            'credits_total' => 30,
        ]);

        // Candidat convoqué (prérequis du contrôle serveur de saisie).
        Candidat::create([
            'epreuve_id' => $this->epreuve->id,
            'etudiant_id' => $this->etudiant->id,
            'numero_anonymat' => 'A'.random_int(1000, 9999),
            'statut' => 'convoque',
        ]);

        // Saisie initiale (génère une entrée d'historique).
        $resultat = app(SaisieNoteService::class)->saisirLot($this->epreuve, [
            ['etudiant_id' => $this->etudiant->id, 'valeur' => 12.5],
        ]);

        $this->assertSame(1, $resultat['saisies'], 'La saisie initiale doit réussir.');

        $this->note = NoteEpreuve::where('epreuve_id', $this->epreuve->id)
            ->where('etudiant_id', $this->etudiant->id)
            ->firstOrFail();
    }

    public function test_sec_012_toute_saisie_leave_une_trace_avant_apres(): void
    {
        $lignes = $this->note->historique()->get();

        $this->assertGreaterThanOrEqual(1, $lignes->count());
        $premiere = $lignes->first();
        $this->assertNull($premiere->ancienne_valeur, 'La création n\'a pas d\'ancienne valeur.');
        $this->assertEquals(12.5, (float) $premiere->nouvelle_valeur);
    }

    public function test_sec_012_re_saisie_consolore_ancienne_valeur_dans_le_journal(): void
    {
        app(SaisieNoteService::class)->saisirLot($this->epreuve, [
            ['etudiant_id' => $this->etudiant->id, 'valeur' => 15],
        ]);

        $lignes = $this->note->historique()->orderBy('id')->get();

        $this->assertGreaterThanOrEqual(2, $lignes->count());
        $derniere = $lignes->last();
        $this->assertEquals(12.5, (float) $derniere->ancienne_valeur, 'L\'ancienne valeur doit être conservée.');
        $this->assertEquals(15, (float) $derniere->nouvelle_valeur);

        $this->note->refresh();
        $this->assertEquals(15, (float) $this->note->valeur);
    }

    public function test_sec_012_modification_du_journal_refusee(): void
    {
        $ligne = $this->note->historique()->firstOrFail();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('append-only');

        $ligne->update(['nouvelle_valeur' => 20]);
    }

    public function test_sec_012_suppression_du_journal_refusee(): void
    {
        $ligne = $this->note->historique()->firstOrFail();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('append-only');

        $ligne->delete();
    }

    public function test_sec_012_le_journal_survit_a_la_revalidation(): void
    {
        // Re-validation explicite (matrice validated→validated admise) :
        // la trace de saisie reste intacte, rien n'est écrasé.
        $avant = $this->note->historique()->count();

        app(SaisieNoteService::class)->valider($this->note->refresh(), $this->auteurAdmin());

        $this->note->refresh();
        $this->assertSame('validated', $this->note->statut);
        $this->assertGreaterThanOrEqual($avant, $this->note->historique()->count(), 'Le journal n\'est jamais réduit.');

        // La note officielle a été créée/synchronisée.
        $this->assertNotNull($this->note->note_id);
        $officielle = Note::find($this->note->note_id);
        $this->assertNotNull($officielle);
    }

    private function auteurAdmin(): int
    {
        return (int) User::creerCompteBackOffice([
            'name' => 'Validateur SEC',
            'email' => 'sec.validateur.'.uniqid('', true).'@scolarite.test',
            'password' => Hash::make('Motdepasse@123'),
        ], 'admin_scolarite')->id;
    }
}
