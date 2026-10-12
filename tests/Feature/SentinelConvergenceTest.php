<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DocumentAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Sentinel\Listeners\CapterJobEchoueSentinel;
use App\Modules\Sentinel\Models\SentinelIncident;
use App\Modules\Sentinel\Services\SentinelService;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Tests\TestCase;

/**
 * Convergence 2ᵉ main — contrôles ajoutés en parallèle (configuration,
 * comptes, stockage, épreuves, pièces) : vérifiés, sans PII exposée,
 * sans boucle, avec le même contrat `SentinelCheckInterface`.
 */
class SentinelConvergenceTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
    }

    public function test_configuration_exige_annee_active(): void
    {
        $this->annee->update(['active' => false]);

        app(SentinelService::class)->executer('CONFIG_COHERENCE');

        $this->assertNotNull(SentinelIncident::where('code', 'CONFIG_COHERENCE')->first());

        // Année réactivée : la ronde suivante résout.
        $this->annee->update(['active' => true]);
        app(SentinelService::class)->executer('CONFIG_COHERENCE');

        $this->assertSame('resolu', SentinelIncident::where('code', 'CONFIG_COHERENCE')->first()->statut);
    }

    public function test_securite_exige_super_admin_actif(): void
    {
        app(SentinelService::class)->executer('SECURITE_COMPTES_ADMIN');

        $this->assertNotNull(SentinelIncident::where('code', 'SECURITE_COMPTES_ADMIN')->first());

        User::creerCompteBackOffice([
            'name' => 'Super', 'email' => 'super.conv@universite.test', 'password' => 'Motdepasse@123',
        ], 'super_admin');

        app(SentinelService::class)->executer('SECURITE_COMPTES_ADMIN');

        $this->assertSame('resolu', SentinelIncident::where('code', 'SECURITE_COMPTES_ADMIN')->first()->statut);
    }

    public function test_stockage_prive_sain_silencieux(): void
    {
        $resultat = app(SentinelService::class)->executer('INFRA_STOCKAGE_PRIVE');

        $this->assertSame([], $resultat['erreurs']);
        $this->assertSame(0, SentinelIncident::where('code', 'INFRA_STOCKAGE_PRIVE')->count());
    }

    public function test_epreuve_horaires_incoherents_signalee(): void
    {
        $programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LIC-CV', 'nom' => 'Licence',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niveau = Niveau::create(['programme_id' => $programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $semestre = SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $programme->id,
            'code' => 'UE-CV', 'nom' => 'UE', 'credits' => 6, 'coefficient' => 2,
        ]);
        $matiere = Matiere::create([
            'code' => 'MAT-CV', 'nom' => 'Matière', 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 3, 'coefficient' => 1,
        ]);
        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id, 'code' => 'SESS-CV', 'nom' => 'Session',
            'type' => 'principale', 'date_debut' => '2027-01-10', 'date_fin' => '2027-01-25', 'statut' => 'open',
        ]);
        $examen = Examen::create([
            'session_id' => $session->id, 'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examens', 'statut' => 'scheduled',
        ]);
        Epreuve::create([
            'examen_id' => $examen->id, 'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id, 'matiere_id' => $matiere->id,
            'code' => 'EPR-CV', 'date' => '2027-01-12',
            'heure_debut' => '10:00:00', 'heure_fin' => '09:00:00', 'statut' => 'scheduled',
        ]);

        app(SentinelService::class)->executer('EVAL_CONFLIT_EXAMENS');

        $incident = SentinelIncident::where('code', 'EVAL_CONFLIT_EXAMENS')->first();
        $this->assertNotNull($incident);
        $this->assertStringContainsString('EPR-CV', $incident->titre);
    }

    public function test_dossier_sans_piece_signale_sans_pii(): void
    {
        $etudiant = Etudiant::create([
            'cin' => 'CV000001', 'num_inscription' => 'MAT-CV1', 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'cv1@universite.test', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'R', 'ville' => 'Tunis',
            'code_postal' => '1000', 'annee_bac' => '2023', 'password' => 'Secret@123',
        ]);
        $etudiant->definirStatut('valide');

        app(SentinelService::class)->executer('DOSSIER_PIECES_REQUISES');

        $incident = SentinelIncident::where('code', 'DOSSIER_PIECES_REQUISES')->first();
        $this->assertNotNull($incident);
        // Aucune PII (nom, CIN, e-mail) dans le titre exposé au cockpit.
        $this->assertStringNotContainsString('Benali', $incident->titre);
        $this->assertStringNotContainsString('CV000001', $incident->titre.$incident->diagnostic);

        // Pièce versée : la ronde suivante résout.
        DocumentAcademique::create([
            'etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $this->annee->id,
            'type_document' => 'cin', 'nom_original' => 'cin.pdf', 'chemin' => 'prive/cin.pdf',
            'mime_type' => 'application/pdf', 'taille' => 10, 'hash_fichier' => sha1('x'),
        ]);
        app(SentinelService::class)->executer('DOSSIER_PIECES_REQUISES');

        $this->assertSame('resolu', $incident->fresh()->statut);
    }

    public function test_listener_job_echoue_sans_boucle_ni_pii(): void
    {
        $job = \Mockery::mock(Job::class);
        $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\TacheLourde');
        $job->shouldReceive('getQueue')->andReturn('default');

        $listener = app(CapterJobEchoueSentinel::class);
        $listener->handle(new JobFailed('database', $job, new \RuntimeException('Échec test /tmp/secret password=xxx')));

        $incident = SentinelIncident::where('code', 'INFRA_JOB_ECHOUE')->first();
        $this->assertNotNull($incident);
        $this->assertSame('erreur', $incident->gravite);
        $this->assertStringNotContainsString('password=xxx', $incident->diagnostic);

        // Rejeu : dédupliqué, pas de doublon.
        $listener->handle(new JobFailed('database', $job, new \RuntimeException('Échec test')));
        $this->assertSame(1, SentinelIncident::where('code', 'INFRA_JOB_ECHOUE')->count());
    }
}
