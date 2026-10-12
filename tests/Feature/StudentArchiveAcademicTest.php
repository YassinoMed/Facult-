<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\StudentArchive\Services\StudentArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Archive académique (§40) : UE/ECUE/notes/crédits/délibérations exacts,
 * filtres server-side, requêtes bornées.
 */
class StudentArchiveAcademicTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Etudiant $etudiant;

    private Matiere $matiere;

    private UniteEnseignement $ue;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.student-archive.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.acad@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LGL', 'nom' => 'Licence GL',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niveau = Niveau::create(['programme_id' => $programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $semestreLmd = SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1]);
        $this->ue = UniteEnseignement::create([
            'semestre_id' => $semestreLmd->id, 'programme_id' => $programme->id,
            'code' => 'UE01', 'nom' => 'Bases', 'credits' => 6, 'coefficient' => 2,
        ]);
        $this->matiere = Matiere::create([
            'code' => 'ACD01', 'nom' => 'Algo', 'ue_id' => $this->ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 4, 'coefficient' => 2,
        ]);

        $this->etudiant = Etudiant::create([
            'cin' => 'ACD00001', 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'acd@acad.test', 'date_naissance' => '2004-01-01', 'sexe' => 'F',
            'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'annee_academique' => '2026-2027',
            'adresse' => 'X', 'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');
        $inscription = $this->etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
            'moyenne_annuelle' => 14.5, 'credits_obtenus' => 4, 'credits_total' => 4,
        ]);
        $semestre = $inscription->semestres()->create(['numero' => 1]);
        Note::create([
            'etudiant_id' => $this->etudiant->id, 'inscription_academique_id' => $inscription->id,
            'semestre_id' => $semestre->id, 'matiere_id' => $this->matiere->id,
            'note' => 14.5, 'coefficient' => 2, 'credits' => 4, 'session' => 'normale',
        ]);
    }

    public function test_ue_ecue_notes_exactes(): void
    {
        $academique = app(StudentArchiveService::class)->academic($this->etudiant);

        $this->assertCount(1, $academique['annees']);
        $bloc = $academique['annees'][0];

        $this->assertCount(1, $bloc['ues']);
        $this->assertSame('UE01', $bloc['ues'][0]['ue']->code);
        $this->assertSame('ACD01', $bloc['ues'][0]['ecues'][0]['matiere']->code);
        $this->assertSame(14.5, (float) $bloc['ues'][0]['ecues'][0]['note']->note);
        $this->assertSame('normale', $bloc['ues'][0]['ecues'][0]['note']->session);
        $this->assertSame(14.5, $bloc['ues'][0]['moyenne']);
        $this->assertSame(4, $bloc['ues'][0]['credits']);
    }

    public function test_synthese_moteur_metier(): void
    {
        $academique = app(StudentArchiveService::class)->academic($this->etudiant);

        $this->assertSame(14.5, $academique['synthese']['moyenne_generale']);
        $this->assertSame(4, $academique['synthese']['credits_obtenus']);
    }

    public function test_filtres_server_side(): void
    {
        $service = app(StudentArchiveService::class);

        $this->assertCount(1, $service->academic($this->etudiant, ['session' => 'normale'])['annees'][0]['notes']);
        $this->assertCount(0, $service->academic($this->etudiant, ['session' => 'rattrapage'])['annees'][0]['notes']);
        $this->assertCount(0, $service->academic($this->etudiant, ['matiere_id' => 999999])['annees'][0]['notes']);
    }

    public function test_onglet_academique_requetes_bornees(): void
    {
        $requetes = 0;
        DB::listen(function () use (&$requetes) {
            $requetes++;
        });

        $this->actingAs($this->admin)->get(route('admin.etudiants.archive', [
            $this->etudiant, 'onglet' => 'academique',
        ]))->assertOk()->assertSee('ACD01');

        $this->assertLessThanOrEqual(40, $requetes, "Trop de requêtes : {$requetes}.");
    }

    public function test_sessions_existantes_uniquement(): void
    {
        $sessions = Note::where('etudiant_id', $this->etudiant->id)->distinct()->pluck('session')->all();

        $this->assertSame(['normale'], $sessions);
    }
}
