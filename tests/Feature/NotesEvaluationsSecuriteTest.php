<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DocumentAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\User;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\EvaluationCompostage;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\NoteEpreuve;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Policies\EvaluationPolicy;
use App\Modules\Evaluation\Services\CompostageService;
use App\Modules\Evaluation\Services\DeliberationService;
use App\Modules\Evaluation\Services\EpreuveService;
use App\Modules\Evaluation\Services\SaisieNoteService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\StudentDossiers\Models\GeneratedDocument;
use App\Modules\StudentDossiers\Models\StudentDossier;
use App\Services\ArchiveService;
use App\Services\DataExchange\Definitions\NoteEpreuveAnonymeDefinition;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Non-régression des correctifs sûrs Notes/Évaluations :
 * - F-01 : une note validée modifiée repasse en `entered` (revalidation exigée) ;
 * - F-02 : aucun enregistrement fantôme pour un absent sans note ;
 * - F-07 : le périmètre faculté tient compte de la faculté de l'épreuve ;
 * - F-09 : import CSV limité aux fichiers texte et à 2 000 lignes ;
 * - F-12 : les notes verrouillées ignorées sont signalées.
 */
class NotesEvaluationsSecuriteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Matiere $matiere;

    private Group $groupe;

    private Room $salle;

    private int $compteur = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculte = Faculte::create([
            'nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true,
        ]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.notes.secu@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LGL', 'nom' => 'Licence GL',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niveau = Niveau::create([
            'programme_id' => $this->programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1,
        ]);
        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1,
        ]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $this->programme->id,
            'code' => 'UE01', 'nom' => 'Algo', 'credits' => 6, 'coefficient' => 2,
        ]);
        $this->matiere = Matiere::create([
            'code' => 'A101', 'nom' => 'Algorithmique', 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 6, 'coefficient' => 2,
        ]);
        $this->groupe = Group::create([
            'code' => 'G1', 'nom' => 'Groupe 1', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'actif' => true,
        ]);
        $this->salle = Room::create([
            'code' => 'B12', 'nom' => 'B12', 'capacite' => 40, 'actif' => true,
        ]);
    }

    public function test_note_validee_modifiee_redevient_entered_et_exige_une_revalidation(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerEtudiant();
        $candidat = $this->creerCandidat($epreuve, $etudiant, 'present');

        $officielle = $this->creerNoteOfficielle($etudiant, 12);
        $note = NoteEpreuve::create([
            'epreuve_id' => $epreuve->id,
            'etudiant_id' => $etudiant->id,
            'candidat_id' => $candidat->id,
            'note_id' => $officielle->id,
            'valeur' => 12,
            'note_max' => 20,
            'coefficient' => 2,
            'statut' => 'validated',
        ]);

        $resultat = app(SaisieNoteService::class)->saisirLot($epreuve, [
            ['etudiant_id' => $etudiant->id, 'valeur' => 14],
        ], $this->admin->id);

        $this->assertSame(1, $resultat['saisies']);
        $this->assertSame([], $resultat['erreurs']);

        $note->refresh();
        $this->assertSame('entered', $note->statut);
        $this->assertEquals(14.0, (float) $note->valeur);

        // La note officielle n'est pas modifiée tant que la revalidation n'a pas eu lieu.
        $this->assertEquals(12.0, (float) $officielle->fresh()->note);

        $validee = app(SaisieNoteService::class)->valider($note, $this->admin->id, 'Revalidation après correction');

        $this->assertSame('validated', $validee->statut);
        $this->assertEquals(14.0, (float) $officielle->fresh()->note);
    }

    public function test_absent_sans_note_ne_cree_aucun_enregistrement_fantome(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerEtudiant();
        $this->creerCandidat($epreuve, $etudiant, 'absent');

        $numero = app(CompostageService::class)->getOrCreate($epreuve, $etudiant)->numero;

        $resultat = app(SaisieNoteService::class)->saisirLotParCompostages($epreuve, [
            ['numero' => $numero, 'valeur' => null],
        ], $this->admin->id);

        $this->assertSame(0, $resultat['saisies']);
        $this->assertSame(1, $resultat['ignorees']);
        $this->assertSame([], $resultat['erreurs']);
        $this->assertDatabaseCount('evaluation_notes', 0);
    }

    public function test_perimetre_note_epreuve_refuse_une_epreuve_d_une_autre_faculte(): void
    {
        $autreFaculte = Faculte::create([
            'nom' => 'FSJ', 'code' => 'FSJ', 'description' => 'Droit', 'active' => true,
        ]);
        $autreProgramme = Programme::create([
            'faculte_id' => $autreFaculte->id, 'code' => 'DRO', 'nom' => 'Droit',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $autreNiveau = Niveau::create([
            'programme_id' => $autreProgramme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1,
        ]);
        $autreSemestre = SemestreLmd::create([
            'niveau_id' => $autreNiveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1,
        ]);
        $autreUe = UniteEnseignement::create([
            'semestre_id' => $autreSemestre->id, 'programme_id' => $autreProgramme->id,
            'code' => 'UE-D', 'nom' => 'Droit civil', 'credits' => 6, 'coefficient' => 2,
        ]);
        $autreMatiere = Matiere::create([
            'code' => 'D101', 'nom' => 'Droit civil', 'ue_id' => $autreUe->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 6, 'coefficient' => 2,
        ]);
        $autreGroupe = Group::create([
            'code' => 'GD1', 'nom' => 'Groupe Droit 1', 'faculte_id' => $autreFaculte->id,
            'niveau' => 'L1', 'actif' => true,
        ]);

        $epreuveAutreFaculte = $this->creerEpreuve($autreGroupe, $autreMatiere);
        $etudiant = $this->creerEtudiant($this->faculte->id);

        $note = NoteEpreuve::create([
            'epreuve_id' => $epreuveAutreFaculte->id,
            'etudiant_id' => $etudiant->id,
            'valeur' => 10,
            'note_max' => 20,
            'coefficient' => 2,
            'statut' => 'entered',
        ]);

        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Admin FST', 'email' => 'admin.fst.notes@universite.test',
            'password' => 'Motdepasse@123', 'faculte_id' => $this->faculte->id,
        ], 'admin_faculte');

        $policy = app(EvaluationPolicy::class);

        // L'ancien fallback « faculté de l'étudiant d'abord » autorisait ce cas :
        // l'étudiant est en FST, mais l'épreuve appartient à la FSJ.
        $this->assertFalse($policy->validerNote($adminFaculte, $note));
        $this->assertTrue($policy->validerNote($this->admin, $note));
    }

    public function test_import_csv_refuse_un_fichier_non_textuel(): void
    {
        $epreuve = $this->creerEpreuve();

        $this->actingAs($this->admin)
            ->post(route('admin.evaluations.notes.import-anonyme', $epreuve), [
                'fichier' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
            ])
            ->assertSessionHasErrors('fichier');

        $this->assertDatabaseCount('evaluation_notes', 0);
    }

    public function test_import_csv_accepte_un_fichier_valide(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerEtudiant();
        $this->creerCandidat($epreuve, $etudiant, 'present');

        $numero = app(CompostageService::class)->getOrCreate($epreuve, $etudiant)->numero;
        $csv = "compostage,note\n{$numero},12\n";

        $this->actingAs($this->admin)
            ->post(route('admin.evaluations.notes.import-anonyme', $epreuve), [
                'fichier' => UploadedFile::fake()->createWithContent('notes.csv', $csv),
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('evaluation_notes', [
            'epreuve_id' => $epreuve->id,
            'etudiant_id' => $etudiant->id,
            'statut' => 'entered',
        ]);
    }

    public function test_import_csv_refuse_plus_de_2000_lignes(): void
    {
        $epreuve = $this->creerEpreuve();

        $csv = "compostage,note\n";
        for ($i = 1; $i <= 2001; $i++) {
            $csv .= sprintf("CMP-%06d,12\n", $i);
        }

        $this->actingAs($this->admin)
            ->post(route('admin.evaluations.notes.import-anonyme', $epreuve), [
                'fichier' => UploadedFile::fake()->createWithContent('notes.csv', $csv),
            ])
            ->assertSessionHasErrors('fichier');

        $this->assertStringContainsString('2000', (string) session('errors')->first('fichier'));
    }

    public function test_note_verrouillee_ignoree_est_signalee_a_l_utilisateur(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerEtudiant();
        $candidat = $this->creerCandidat($epreuve, $etudiant, 'present');

        $note = NoteEpreuve::create([
            'epreuve_id' => $epreuve->id,
            'etudiant_id' => $etudiant->id,
            'candidat_id' => $candidat->id,
            'valeur' => 10,
            'note_max' => 20,
            'coefficient' => 2,
            'statut' => 'locked',
            'verrouillee_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.evaluations.notes.store', $epreuve), [
                'lignes' => [
                    ['etudiant_id' => $etudiant->id, 'valeur' => 15],
                ],
            ])
            ->assertSessionHas('succes');

        $this->assertStringContainsString('ignorée', (string) session('succes'));
        $this->assertEquals(10.0, (float) $note->fresh()->valeur);
    }

    public function test_candidat_en_retard_peut_avoir_une_note_saisie(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerEtudiant();
        $candidat = $this->creerCandidat($epreuve, $etudiant, 'retard');

        $this->assertFalse($candidat->estAbsent());

        $resultat = app(SaisieNoteService::class)->saisirLot($epreuve, [
            ['etudiant_id' => $etudiant->id, 'valeur' => 13.5],
        ], $this->admin->id);

        $this->assertSame(1, $resultat['saisies']);
        $this->assertSame([], $resultat['erreurs']);
        $this->assertDatabaseHas('evaluation_notes', [
            'epreuve_id' => $epreuve->id,
            'etudiant_id' => $etudiant->id,
            'valeur' => 13.5,
            'statut' => 'entered',
        ]);
    }

    public function test_valider_tout_le_lot_valide_toutes_les_notes_saisies(): void
    {
        $epreuve = $this->creerEpreuve();
        $e1 = $this->creerEtudiant();
        $e2 = $this->creerEtudiant();
        $this->creerCandidat($epreuve, $e1, 'present');
        $this->creerCandidat($epreuve, $e2, 'present');

        $service = app(SaisieNoteService::class);
        $service->saisirLot($epreuve, [
            ['etudiant_id' => $e1->id, 'valeur' => 11],
            ['etudiant_id' => $e2->id, 'valeur' => 15],
        ], $this->admin->id);

        $this->actingAs($this->admin)
            ->post(route('admin.evaluations.epreuves.notes.valider-tout', $epreuve))
            ->assertRedirect()
            ->assertSessionHas('succes');

        $this->assertDatabaseHas('evaluation_notes', [
            'epreuve_id' => $epreuve->id,
            'etudiant_id' => $e1->id,
            'statut' => 'validated',
            'valide_par' => $this->admin->id,
        ]);
        $this->assertDatabaseHas('evaluation_notes', [
            'epreuve_id' => $epreuve->id,
            'etudiant_id' => $e2->id,
            'statut' => 'validated',
            'valide_par' => $this->admin->id,
        ]);
    }

    public function test_portail_etudiant_affiche_la_valeur_reelle_de_la_note(): void
    {
        $etudiant = $this->creerEtudiant();
        $inscription = $etudiant->inscriptionsAcademiques()->firstOrFail();
        $semestre = $inscription->semestres()->where('numero', 1)->firstOrFail();

        // Création d'une délibération publiée rattachée
        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'S-DELIB-'.random_int(100, 999),
            'nom' => 'Session Principale',
            'type' => 'principale',
            'date_debut' => '2027-01-05',
            'date_fin' => '2027-01-20',
            'statut' => 'open',
            'created_by' => $this->admin->id,
        ]);

        $deliberation = Deliberation::create([
            'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'niveau_id' => $this->programme->niveaux()->first()->id,
            'type' => 'semestre',
            'statut' => 'published',
            'semestre_id' => $semestre->id,
            'publie_at' => now(),
            'publie_par' => $this->admin->id,
        ]);

        DecisionDeliberation::create([
            'deliberation_id' => $deliberation->id,
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'decision' => 'admis',
            'moyenne' => 14.50,
            'credits_obtenus' => 30,
            'publie' => true,
        ]);

        Note::create([
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'semestre_id' => $semestre->id,
            'matiere_id' => $this->matiere->id,
            'note' => 14.50,
            'coefficient' => 2,
            'credits' => 6,
            'session' => 'normale',
        ]);

        $response = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.notes.index'))
            ->assertOk();

        $response->assertSee('14.50/20');
        $response->assertDontSee('—/20');
    }

    public function test_performance_saisie_par_compostage_sans_explosion_n_plus_un(): void
    {
        $epreuve = $this->creerEpreuve();
        $compostages = [];

        for ($i = 0; $i < 5; $i++) {
            $e = $this->creerEtudiant();
            $this->creerCandidat($epreuve, $e, 'present');
            $num = app(CompostageService::class)->getOrCreate($epreuve, $e)->numero;
            $compostages[] = ['numero' => $num, 'valeur' => 10 + $i];
        }

        DB::enableQueryLog();
        DB::flushQueryLog();

        $resultat = app(SaisieNoteService::class)->saisirLotParCompostages($epreuve, $compostages, $this->admin->id);

        $requetes = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(5, $resultat['saisies']);
        $this->assertLessThan(25, count($requetes), 'La saisie par lot doit précharger les entités et ne pas exploser en N+1.');
    }

    public function test_note_egale_a_zero_est_enregistree_et_distinguee_de_l_absence(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerEtudiant();
        $candidat = $this->creerCandidat($epreuve, $etudiant, 'present');

        $resultat = app(SaisieNoteService::class)->saisirLot($epreuve, [
            ['etudiant_id' => $etudiant->id, 'valeur' => 0.0],
        ], $this->admin->id);

        $this->assertSame(1, $resultat['saisies']);
        $this->assertSame(0, $resultat['ignorees']);
        $this->assertDatabaseHas('evaluation_notes', [
            'epreuve_id' => $epreuve->id,
            'etudiant_id' => $etudiant->id,
            'valeur' => 0.0,
            'statut' => 'entered',
        ]);
    }

    public function test_import_csv_refuse_doublon_de_compostage(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerEtudiant();
        $this->creerCandidat($epreuve, $etudiant, 'present');

        $numero = app(CompostageService::class)->getOrCreate($epreuve, $etudiant)->numero;
        $csv = "compostage,note\n{$numero},12\n{$numero},14\n";

        $this->actingAs($this->admin)
            ->post(route('admin.evaluations.notes.import-anonyme', $epreuve), [
                'fichier' => UploadedFile::fake()->createWithContent('notes.csv', $csv),
            ])
            ->assertSessionHasErrors('fichier');

        $this->assertStringContainsString('Doublon', (string) session('errors')->first('fichier'));
    }

    public function test_import_csv_signale_ligne_malformee_ou_hors_bareme(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerEtudiant();
        $this->creerCandidat($epreuve, $etudiant, 'present');

        $numero = app(CompostageService::class)->getOrCreate($epreuve, $etudiant)->numero;
        $csv = "compostage,note\n{$numero},25\n"; // 25 > note_max 20

        $this->actingAs($this->admin)
            ->post(route('admin.evaluations.notes.import-anonyme', $epreuve), [
                'fichier' => UploadedFile::fake()->createWithContent('notes.csv', $csv),
            ])
            ->assertSessionHasErrors();
    }

    public function test_validation_en_masse_avec_echec_partiel(): void
    {
        $epreuve = $this->creerEpreuve();
        $e1 = $this->creerEtudiant();
        $e2 = $this->creerEtudiant();
        $c1 = $this->creerCandidat($epreuve, $e1, 'present');
        $c2 = $this->creerCandidat($epreuve, $e2, 'absent'); // Absent : validation interdite

        $service = app(SaisieNoteService::class);
        $service->saisirLot($epreuve, [
            ['etudiant_id' => $e1->id, 'valeur' => 14],
        ], $this->admin->id);

        // On injecte manuellement une copie invalide pour tester la tolérance et le rapport d'échec partiel
        NoteEpreuve::create([
            'epreuve_id' => $epreuve->id,
            'etudiant_id' => $e2->id,
            'candidat_id' => $c2->id,
            'valeur' => 12,
            'note_max' => 20,
            'coefficient' => 2,
            'statut' => 'entered',
        ]);

        $resultat = $service->validerTout($epreuve, $this->admin->id);

        $this->assertSame(1, $resultat['validees']);
        $this->assertCount(1, $resultat['erreurs']);
        $this->assertStringContainsString('absent', $resultat['erreurs'][0]);

        $this->assertDatabaseHas('evaluation_notes', [
            'epreuve_id' => $epreuve->id,
            'etudiant_id' => $e1->id,
            'statut' => 'validated',
        ]);
        $this->assertDatabaseHas('evaluation_notes', [
            'epreuve_id' => $epreuve->id,
            'etudiant_id' => $e2->id,
            'statut' => 'entered',
        ]);
    }

    public function test_validation_en_masse_refusee_si_utilisateur_non_autorise(): void
    {
        $epreuve = $this->creerEpreuve();
        $userConsultation = User::creerCompteBackOffice([
            'name' => 'Agent Consultation', 'email' => 'consult.eval@universite.test',
            'password' => 'Motdepasse@123',
        ], 'consultation');

        $this->actingAs($userConsultation)
            ->post(route('admin.evaluations.epreuves.notes.valider-tout', $epreuve))
            ->assertForbidden();
    }

    public function test_validation_en_masse_refusee_si_epreuve_verrouillee(): void
    {
        $epreuve = $this->creerEpreuve();
        $epreuve->update(['statut' => 'locked']);

        $this->actingAs($this->admin)
            ->post(route('admin.evaluations.epreuves.notes.valider-tout', $epreuve))
            ->assertSessionHasErrors('epreuve');
    }

    public function test_refus_de_telechargement_d_un_releve_non_publie(): void
    {
        Storage::fake('local');
        $etudiant = $this->creerEtudiant();
        $inscription = $etudiant->inscriptionsAcademiques()->firstOrFail();

        $cheminRelatif = "archives/etudiant_{$etudiant->id}/releve_notes.pdf";
        Storage::disk('local')->put($cheminRelatif, "%PDF-1.4\nFaux relevé de notes\n");

        $document = DocumentAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'type_document' => 'releve_notes',
            'nom_original' => 'releve_notes.pdf',
            'chemin' => $cheminRelatif,
            'mime_type' => 'application/pdf',
            'taille' => 30,
            'hash_fichier' => hash('sha256', 'test'),
            'uploaded_by' => $this->admin->id,
        ]);

        // 1. Sans délibération publiée : refus strict HTTP 403
        $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.archives.documents.download', $document))
            ->assertForbidden();

        // 2. Publication d'une délibération pour cette inscription
        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'S-PUB-'.random_int(1000, 9999),
            'nom' => 'Session 1',
            'type' => 'principale',
            'date_debut' => '2027-01-05',
            'date_fin' => '2027-01-20',
            'statut' => 'open',
            'created_by' => $this->admin->id,
        ]);
        $deliberation = Deliberation::create([
            'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'niveau_id' => $this->programme->niveaux()->first()->id,
            'type' => 'semestre',
            'statut' => 'published',
            'semestre_id' => $inscription->semestres()->first()->id,
            'publie_at' => now(),
            'publie_par' => $this->admin->id,
        ]);
        DecisionDeliberation::create([
            'deliberation_id' => $deliberation->id,
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'decision' => 'admis',
            'moyenne' => 14.00,
            'credits_obtenus' => 30,
            'publie' => true,
        ]);

        // 3. Après publication : téléchargement autorisé
        $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.archives.documents.download', $document))
            ->assertOk();
    }

    public function test_absence_de_fuite_des_resultats_non_publies_sur_le_portail_etudiant(): void
    {
        $etudiant = $this->creerEtudiant();
        $this->creerNoteOfficielle($etudiant, 17.5);

        // Aucune décision publiée n'existe pour cet étudiant
        $responseNotes = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.notes.index'))
            ->assertOk();

        // Les notes provisoires ne doivent pas être divulguées
        $responseNotes->assertDontSee('17.50');
        $responseNotes->assertSee('Aucune note enregistrée');

        $responseDashboard = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.dashboard'))
            ->assertOk();

        $resume = $responseDashboard->viewData('resumeAcademique');
        $this->assertFalse($resume['publie']);
        $this->assertNull($resume['moyenne']);
    }

    public function test_mesure_performance_requetes_import_lot_notes(): void
    {
        $epreuve = $this->creerEpreuve();
        $lignes = [];

        for ($i = 0; $i < 30; $i++) {
            $e = $this->creerEtudiant();
            $this->creerCandidat($epreuve, $e, 'present');
            $lignes[] = [
                'etudiant_id' => $e->id,
                'valeur' => 10 + ($i % 10),
            ];
        }

        DB::enableQueryLog();
        DB::flushQueryLog();

        $resultat = app(SaisieNoteService::class)->saisirLot($epreuve, $lignes, $this->admin->id);

        $requetes = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(30, $resultat['saisies']);
        // 30 notes insérées en masse doivent consommer moins de 15 requêtes
        $this->assertLessThan(15, count($requetes), 'La saisie par lot optimisée doit exécuter moins de 15 requêtes pour un lot de 30 notes.');
    }

    public function test_saisie_lot_detecte_et_rejette_doublon_dans_le_lot(): void
    {
        $epreuve = $this->creerEpreuve();
        $e1 = $this->creerEtudiant();
        $this->creerCandidat($epreuve, $e1, 'present');

        $service = app(SaisieNoteService::class);
        $resultat = $service->saisirLot($epreuve, [
            ['etudiant_id' => $e1->id, 'valeur' => 12.0],
            ['etudiant_id' => $e1->id, 'valeur' => 14.0],
        ], $this->admin->id);

        $this->assertSame(1, $resultat['saisies']);
        $this->assertCount(1, $resultat['erreurs']);
        $this->assertStringContainsString('Doublon détecté', $resultat['erreurs'][1]);

        $note = NoteEpreuve::where('epreuve_id', $epreuve->id)->where('etudiant_id', $e1->id)->first();
        $this->assertNotNull($note);
        $this->assertEquals(12.0, (float) $note->valeur);
    }

    public function test_modification_note_validee_en_grand_lot_reinitialise_valide_par_et_statut(): void
    {
        $epreuve = $this->creerEpreuve();
        $service = app(SaisieNoteService::class);

        // Créer 15 candidats (>10 lines pour tester la branche grand lot avec bulk upsert)
        $candidats = [];
        for ($i = 0; $i < 15; $i++) {
            $e = $this->creerEtudiant();
            $c = $this->creerCandidat($epreuve, $e, 'present');
            $candidats[] = [$e, $c];
        }

        // Valider la première note
        [$e0, $c0] = $candidats[0];
        $service->saisirLot($epreuve, [['etudiant_id' => $e0->id, 'valeur' => 11.0]], $this->admin->id);
        $note0 = NoteEpreuve::where('epreuve_id', $epreuve->id)->where('etudiant_id', $e0->id)->firstOrFail();
        $service->valider($note0, $this->admin->id, 'Validation initiale');

        $this->assertSame('validated', $note0->fresh()->statut);
        $this->assertSame($this->admin->id, $note0->fresh()->valide_par);

        // Préparer un lot de 15 lignes modifiant la note 0 de 11.0 à 16.0
        $lignes = [];
        foreach ($candidats as $idx => [$etudiant, $candidat]) {
            $lignes[] = [
                'etudiant_id' => $etudiant->id,
                'valeur' => $idx === 0 ? 16.0 : (10.0 + ($idx % 10)),
            ];
        }

        $resultat = $service->saisirLot($epreuve, $lignes, $this->admin->id);
        $this->assertSame(15, $resultat['saisies']);

        $note0Modifiee = $note0->fresh();
        $this->assertEquals(16.0, (float) $note0Modifiee->valeur);
        $this->assertSame('entered', $note0Modifiee->statut, 'Une note validée modifiée en lot doit repasser en entered');
        $this->assertNull($note0Modifiee->valide_par, 'Le valide_par doit être réinitialisé à null');
    }

    public function test_definition_import_rejette_candidat_absent_avec_note_et_etudiant_hors_perimetre(): void
    {
        $epreuve = $this->creerEpreuve();
        $e1 = $this->creerEtudiant();
        $c1 = $this->creerCandidat($epreuve, $e1, 'absent');
        $compostages = app(CompostageService::class)->genererPourEpreuve($epreuve, $this->admin->id);
        $comp1 = $epreuve->compostages()->where('etudiant_id', $e1->id)->firstOrFail();

        $definition = app(NoteEpreuveAnonymeDefinition::class);
        $definition->definirContexte(['epreuve_id' => $epreuve->id]);

        // Absent avec note doit être rejeté
        $erreurs = $definition->validerLigneMetier(['compostage' => $comp1->numero, 'note' => '12.5'], $this->admin->id);
        $this->assertNotEmpty($erreurs);
        $this->assertSame('CANDIDAT_ABSENT', $erreurs[0]['code']);

        // Candidat d'une autre faculté pour un agent restreint
        $autreFaculte = Faculte::create(['code' => 'FDSP', 'nom' => 'Droit', 'statut' => 'actif']);
        $e2 = $this->creerEtudiant($autreFaculte->id);
        $c2 = $this->creerCandidat($epreuve, $e2, 'present');
        $comp2 = EvaluationCompostage::create([
            'epreuve_id' => $epreuve->id,
            'etudiant_id' => $e2->id,
            'numero' => 'CMP-999999',
            'statut' => 'attribue',
        ]);

        $agentRestreint = User::creerCompteBackOffice([
            'name' => 'Agent FSEGS',
            'email' => 'agent.fsegs@universite.test',
            'password' => 'Agent@123456',
            'faculte_id' => $this->faculte->id,
        ], 'admin_faculte');

        $definition->definirContexte(['epreuve_id' => $epreuve->id]);
        $erreursFac = $definition->validerLigneMetier(['compostage' => $comp2->numero, 'note' => '14.0'], $agentRestreint->id);
        $this->assertNotEmpty($erreursFac);
        $this->assertSame('HORS_PERIMETRE', $erreursFac[0]['code']);
    }

    public function test_telechargement_releve_mon_dossier_securise_par_publication_et_anti_idor(): void
    {
        Storage::fake('private');
        $etudiant = $this->creerEtudiant();
        $autreEtudiant = $this->creerEtudiant();

        $dossier = StudentDossier::create([
            'etudiant_id' => $etudiant->id,
            'reference' => 'DOS-'.$etudiant->cin,
            'matricule' => 'DOS-'.$etudiant->cin,
            'statut' => 'valide',
        ]);
        $autreDossier = StudentDossier::create([
            'etudiant_id' => $autreEtudiant->id,
            'reference' => 'DOS-'.$autreEtudiant->cin,
            'matricule' => 'DOS-'.$autreEtudiant->cin,
            'statut' => 'valide',
        ]);

        $cheminRel = 'documents-centre/2026/releve-test.pdf';
        Storage::disk('private')->put($cheminRel, '%PDF-1.4 Fake Releve');

        $doc = GeneratedDocument::create([
            'reference' => 'REL-2026-0001',
            'numero' => 'REL-2026-0001',
            'code_verification' => 'VERIF12345',
            'student_dossier_id' => $dossier->id,
            'type' => 'releve_notes',
            'version' => 1,
            'statut' => 'valide',
            'storage_path' => $cheminRel,
            'sha256' => hash('sha256', '%PDF-1.4 Fake Releve'),
            'contenu_hash' => hash('sha256', '%PDF-1.4 Fake Releve'),
            'snapshot_donnees' => ['annee_universitaire_id' => $this->annee->id],
        ]);

        // 1. Non publié -> 403 Forbidden
        $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.dossier.officiels.download', $doc))
            ->assertStatus(403);

        // 2. Anti-IDOR -> Un autre étudiant reçoit 404
        $this->withSession(['etudiant_auth_id' => $autreEtudiant->id])
            ->get(route('etudiant.dossier.officiels.download', $doc))
            ->assertStatus(404);

        // 3. Après publication de la délibération -> 200 OK
        $inscription = $etudiant->inscriptionsAcademiques()->firstOrFail();
        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $this->annee->id,
            'titre' => 'Jury S1',
            'statut' => 'published',
            'published_at' => now(),
            'created_by' => $this->admin->id,
        ]);
        DecisionDeliberation::create([
            'deliberation_id' => $deliberation->id,
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'moyenne' => 14.5,
            'decision' => 'admis',
            'publie' => true,
        ]);

        $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.dossier.officiels.download', $doc))
            ->assertOk();
    }

    public function test_dependances_deliberation_invalidation_et_non_modification_snapshot_publie(): void
    {
        $etudiant = $this->creerEtudiant();
        $inscription = $etudiant->inscriptionsAcademiques()->firstOrFail();

        // 1. Délibération préparée avant note
        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $this->annee->id,
            'titre' => 'Jury Annuel',
            'statut' => 'prepared',
            'created_by' => $this->admin->id,
        ]);
        $decision = DecisionDeliberation::create([
            'deliberation_id' => $deliberation->id,
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'moyenne' => 11.0,
            'decision' => 'admis',
            'publie' => false,
        ]);

        // Modification d'une note via ArchiveService déclenche l'invalidation de la délibération
        $note = $this->creerNoteOfficielle($etudiant, 11.0);
        app(ArchiveService::class)->modifierNote($note, [
            'note' => 13.0,
            'raison' => 'Correction autorisée',
        ]);
        $this->assertNotNull($deliberation->fresh()->resultats_invalides_le);

        // La validation de la délibération est alors bloquée tant qu'elle n'est pas re-préparée
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Résultats invalidés');
        app(DeliberationService::class)->valider($deliberation->fresh());
    }

    public function test_modification_note_apres_deliberation_publiee_ne_modifie_pas_silencieusement_decision_snapshot(): void
    {
        $etudiant = $this->creerEtudiant();
        $inscription = $etudiant->inscriptionsAcademiques()->firstOrFail();
        $note = $this->creerNoteOfficielle($etudiant, 12.0);

        // Délibération validée et publiée
        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $this->annee->id,
            'titre' => 'Jury Session Principale',
            'statut' => 'published',
            'verrouillee_at' => now(),
            'published_at' => now(),
            'created_by' => $this->admin->id,
        ]);
        $decision = DecisionDeliberation::create([
            'deliberation_id' => $deliberation->id,
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'moyenne' => 12.0,
            'decision' => 'admis',
            'publie' => true,
        ]);

        // Modification autorisée de la note officielle
        app(ArchiveService::class)->modifierNote($note, [
            'note' => 15.0,
            'raison' => 'Correction d’erreur matérielle autorisée',
        ]);

        // La décision publiée reste strictement identique (snapshot préservé, pas d'écrasement silencieux)
        $this->assertEquals(12.0, (float) $decision->fresh()->moyenne);
        $this->assertSame('admis', $decision->fresh()->decision);
        $this->assertTrue($decision->fresh()->publie);
    }

    public function test_benchmark_1000_lignes_saisir_lot_moins_de_15_requetes(): void
    {
        $epreuve = $this->creerEpreuve();
        $lignes = [];

        // Générer 1 000 lignes
        for ($i = 0; $i < 1000; $i++) {
            $e = $this->creerEtudiant();
            $this->creerCandidat($epreuve, $e, 'present');
            $lignes[] = [
                'etudiant_id' => $e->id,
                'valeur' => (float) (8 + ($i % 12)),
            ];
        }

        $memoireDebut = memory_get_usage(true);
        $tempsDebut = microtime(true);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $resultat = app(SaisieNoteService::class)->saisirLot($epreuve, $lignes, $this->admin->id);

        $requetes = DB::getQueryLog();
        DB::disableQueryLog();

        $tempsEcoule = microtime(true) - $tempsDebut;
        $picMemoire = (memory_get_peak_usage(true) - $memoireDebut) / (1024 * 1024);

        $this->assertSame(1000, $resultat['saisies']);
        $this->assertSame([], $resultat['erreurs']);
        $this->assertSame(0, $resultat['ignorees']);

        // Objectif indicatif : moins de 50 requêtes pour 1 000 lignes
        // Mesure réelle : moins de 15 requêtes
        $this->assertLessThan(15, count($requetes), 'La saisie par lot de 1 000 notes doit exécuter moins de 15 requêtes.');
        $this->assertLessThan(15.0, $tempsEcoule, 'La saisie de 1 000 lignes doit s’exécuter en moins de 15 secondes.');
    }

    /* ── Durcissement « max saisie » : bornes, précision, barème ── */

    public function test_saisie_refuse_une_note_superieure_au_bareme_avant_toute_ecriture(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerEtudiant();
        $this->creerCandidat($epreuve, $etudiant, 'present');
        $numero = app(CompostageService::class)->getOrCreate($epreuve, $etudiant)->numero;

        $this->actingAs($this->admin)
            ->from(route('admin.evaluations.notes.saisie', $epreuve))
            ->post(route('admin.evaluations.notes.store-anonyme', $epreuve), [
                'lignes' => [['numero' => $numero, 'valeur' => 20.01]],
            ])
            ->assertSessionHasErrors('lignes.0.valeur');

        $this->assertDatabaseCount('evaluation_notes', 0);
    }

    public function test_saisie_refuse_note_negative_non_finie_et_trois_decimales(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerEtudiant();
        $this->creerCandidat($epreuve, $etudiant, 'present');
        $numero = app(CompostageService::class)->getOrCreate($epreuve, $etudiant)->numero;

        foreach ([-0.01, '1e9999', 12.345] as $valeur) {
            $this->actingAs($this->admin)
                ->from(route('admin.evaluations.notes.saisie', $epreuve))
                ->post(route('admin.evaluations.notes.store-anonyme', $epreuve), [
                    'lignes' => [['numero' => $numero, 'valeur' => $valeur]],
                ])
                ->assertSessionHasErrors('lignes.0.valeur');
        }

        $this->assertDatabaseCount('evaluation_notes', 0);
    }

    public function test_bareme_nul_bloque_la_saisie_http_et_le_service(): void
    {
        $epreuve = $this->creerEpreuve();
        $epreuve->forceFill(['note_max' => 0])->save();
        $etudiant = $this->creerEtudiant();
        $this->creerCandidat($epreuve, $etudiant, 'present');
        $numero = app(CompostageService::class)->getOrCreate($epreuve, $etudiant)->numero;

        $this->actingAs($this->admin)
            ->from(route('admin.evaluations.notes.saisie', $epreuve))
            ->post(route('admin.evaluations.notes.store-anonyme', $epreuve), [
                'lignes' => [['numero' => $numero, 'valeur' => 10]],
            ])
            ->assertSessionHasErrors('lignes.0.valeur');

        $this->assertDatabaseCount('evaluation_notes', 0);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Barème');

        app(SaisieNoteService::class)->saisirLotParCompostages($epreuve, [
            ['numero' => $numero, 'valeur' => 10],
        ], $this->admin->id);
    }

    /**
     * Défense en profondeur : même si la validation HTTP était contournée
     * (appel direct au service, import, script), le service rejette les
     * valeurs non finies et la précision > 2 décimales sans rien écrire.
     */
    public function test_service_rejette_les_valeurs_invalides_sans_validation_http(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiantA = $this->creerEtudiant();
        $etudiantB = $this->creerEtudiant();
        $this->creerCandidat($epreuve, $etudiantA, 'present');
        $this->creerCandidat($epreuve, $etudiantB, 'present');

        $numeroA = app(CompostageService::class)->getOrCreate($epreuve, $etudiantA)->numero;
        $numeroB = app(CompostageService::class)->getOrCreate($epreuve, $etudiantB)->numero;

        $resultat = app(SaisieNoteService::class)->saisirLotParCompostages($epreuve, [
            ['numero' => $numeroA, 'valeur' => 12.345],
            ['numero' => $numeroB, 'valeur' => '1e9999'],
        ], $this->admin->id);

        $this->assertSame(0, $resultat['saisies']);
        $this->assertArrayHasKey(0, $resultat['erreurs']);
        $this->assertArrayHasKey(1, $resultat['erreurs']);
        $this->assertDatabaseCount('evaluation_notes', 0);
    }

    /**
     * Le flux anonyme fait autorité sur le numéro CMP résolu serveur :
     * un `etudiant_id` injecté par le client ne doit jamais déplacer la
     * note vers un autre étudiant.
     */
    public function test_flux_anonyme_ignore_l_etudiant_id_fourni_par_le_client(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiantA = $this->creerEtudiant();
        $etudiantB = $this->creerEtudiant();
        $this->creerCandidat($epreuve, $etudiantA, 'present');
        $this->creerCandidat($epreuve, $etudiantB, 'present');
        $numeroA = app(CompostageService::class)->getOrCreate($epreuve, $etudiantA)->numero;

        $this->actingAs($this->admin)
            ->post(route('admin.evaluations.notes.store-anonyme', $epreuve), [
                'lignes' => [[
                    'numero' => $numeroA,
                    'etudiant_id' => $etudiantB->id,
                    'valeur' => 12,
                ]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('evaluation_notes', [
            'epreuve_id' => $epreuve->id,
            'etudiant_id' => $etudiantA->id,
        ]);
        $this->assertDatabaseMissing('evaluation_notes', [
            'epreuve_id' => $epreuve->id,
            'etudiant_id' => $etudiantB->id,
        ]);
    }

    /**
     * L'autorisation est évaluée AVANT la validation : une épreuve hors
     * périmètre faculté renvoie 403 sans révéler d'erreur de charge utile.
     */
    public function test_saisie_hors_perimetre_faculte_renvoie_403_avant_validation(): void
    {
        $epreuveAutre = $this->creerEpreuveFaculte('FSJ', 'DRO');
        $etudiantAutre = $this->creerEtudiant($epreuveAutre->groupe->faculte_id);
        $this->creerCandidat($epreuveAutre, $etudiantAutre, 'present');
        $numero = app(CompostageService::class)->getOrCreate($epreuveAutre, $etudiantAutre)->numero;

        $adminFst = User::creerCompteBackOffice([
            'name' => 'Admin FST', 'email' => 'admin.fst.bareme@universite.test',
            'password' => 'Motdepasse@123', 'faculte_id' => $this->faculte->id,
        ], 'admin_faculte');

        $this->actingAs($adminFst)
            ->post(route('admin.evaluations.notes.store-anonyme', $epreuveAutre), [
                'lignes' => [['numero' => $numero, 'valeur' => 999]],
            ])
            ->assertForbidden();

        $this->actingAs($adminFst)
            ->post(route('admin.evaluations.notes.store', $epreuveAutre), [
                'lignes' => [['etudiant_id' => $etudiantAutre->id, 'valeur' => 999]],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('evaluation_notes', 0);
        $this->assertFalse(session('errors')?->has('lignes.0.valeur') ?? false);
    }

    public function test_pages_et_export_de_notes_interdisent_cache_et_indexation(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerEtudiant();
        $this->creerCandidat($epreuve, $etudiant, 'present');
        app(CompostageService::class)->getOrCreate($epreuve, $etudiant);

        $page = $this->actingAs($this->admin)
            ->get(route('admin.evaluations.notes.saisie', $epreuve));

        $page->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));

        $export = $this->actingAs($this->admin)
            ->get(route('admin.evaluations.notes.export-anonyme', $epreuve));

        $export->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('no-store', (string) $export->headers->get('Cache-Control'));
    }

    private function creerEpreuveFaculte(string $codeFaculte, string $codeMatiere): Epreuve
    {
        $faculte = Faculte::create([
            'nom' => $codeFaculte, 'code' => $codeFaculte, 'description' => 'Test', 'active' => true,
        ]);
        $programme = Programme::create([
            'faculte_id' => $faculte->id, 'code' => $codeMatiere, 'nom' => $codeMatiere,
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niveau = Niveau::create([
            'programme_id' => $programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1,
        ]);
        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1,
        ]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $programme->id,
            'code' => 'UE-'.$codeMatiere, 'nom' => 'UE '.$codeMatiere, 'credits' => 6, 'coefficient' => 2,
        ]);
        $matiere = Matiere::create([
            'code' => $codeMatiere.'101', 'nom' => 'Matière '.$codeMatiere, 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 6, 'coefficient' => 2,
        ]);
        $groupe = Group::create([
            'code' => 'G-'.$codeMatiere, 'nom' => 'Groupe '.$codeMatiere,
            'faculte_id' => $faculte->id, 'niveau' => 'L1', 'actif' => true,
        ]);

        return $this->creerEpreuve($groupe, $matiere);
    }

    private function creerEpreuve(?Group $groupe = null, ?Matiere $matiere = null): Epreuve
    {
        $this->compteur++;
        $groupe ??= $this->groupe;
        $matiere ??= $this->matiere;

        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'S-SECU-'.$this->compteur.'-'.random_int(100, 999),
            'nom' => 'Principale',
            'type' => 'principale',
            'date_debut' => '2027-01-05',
            'date_fin' => '2027-01-20',
            'statut' => 'open',
            'created_by' => $this->admin->id,
        ]);
        $examen = Examen::create([
            'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examen S1',
            'type' => 'examen',
            'statut' => 'scheduled',
            'responsable_id' => $this->admin->id,
        ]);

        return app(EpreuveService::class)->creer($examen, [
            'session_id' => $session->id,
            'matiere_id' => $matiere->id,
            'groupe_id' => $groupe->id,
            'date' => '2027-01-'.sprintf('%02d', 10 + $this->compteur),
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
        ]);
    }

    private function creerEtudiant(?int $faculteId = null): Etudiant
    {
        static $compteurEtudiant = 100000;
        $suffixe = ++$compteurEtudiant;

        $etudiant = Etudiant::create([
            'cin' => 'NS'.$suffixe,
            'nom' => 'Benali',
            'prenom' => 'Sara',
            'email' => 'sara.'.$suffixe.'@test.ma',
            'date_naissance' => '2004-01-01',
            'sexe' => 'F',
            'faculte_id' => $faculteId ?? $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '12 rue des Tests',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-2026-'.$suffixe])->save();
        $etudiant->definirStatut('valide');

        $inscription = $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1',
            'statut' => 'en_cours',
        ]);
        $inscription->semestres()->create(['numero' => 1]);
        $inscription->semestres()->create(['numero' => 2]);

        return $etudiant;
    }

    private function creerCandidat(Epreuve $epreuve, Etudiant $etudiant, string $statut): Candidat
    {
        return Candidat::create([
            'epreuve_id' => $epreuve->id,
            'etudiant_id' => $etudiant->id,
            'statut' => $statut,
        ]);
    }

    private function creerNoteOfficielle(Etudiant $etudiant, float $valeur): Note
    {
        $inscription = $etudiant->inscriptionsAcademiques()->firstOrFail();
        $semestre = $inscription->semestres()->where('numero', 1)->firstOrFail();

        return Note::create([
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'semestre_id' => $semestre->id,
            'matiere_id' => $this->matiere->id,
            'note' => $valeur,
            'coefficient' => 2,
            'credits' => 6,
            'session' => 'normale',
        ]);
    }
}
