<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Events\EvaluationChanged;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\EvaluationIncident;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\EpreuveService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * LOOP PARTIE EXAMENS — incidents d'épreuve (seul manque prouvé du
 * cycle : sessions, épreuves, salles, candidats, surveillants,
 * convocations, émargement, notes et compostage existent déjà et sont
 * réutilisés, jamais dupliqués).
 */
class EvaluationIncidentsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Matiere $ecue;

    private Group $groupe;

    private Epreuve $epreuve;

    private Etudiant $etudiant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.incidents@universite.test', 'password' => 'Motdepasse@123',
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

        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id, 'code' => 'S-INC-1',
            'nom' => 'Principale', 'type' => 'principale',
            'date_debut' => '2027-01-05', 'date_fin' => '2027-01-20', 'statut' => 'open', 'created_by' => $this->admin->id,
        ]);
        $examen = Examen::create([
            'session_id' => $session->id, 'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examen S1', 'type' => 'examen', 'statut' => 'scheduled', 'responsable_id' => $this->admin->id,
        ]);
        $this->epreuve = app(EpreuveService::class)->creer($examen, [
            'session_id' => $session->id, 'matiere_id' => $this->ecue->id,
            'groupe_id' => $this->groupe->id, 'date' => '2027-01-06', 'heure_debut' => '09:00', 'heure_fin' => '11:00',
        ]);

        $this->etudiant = Etudiant::create([
            'cin' => 'INC00001', 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'sara.incidents@test.ma', 'date_naissance' => '2004-01-01',
            'sexe' => 'F', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => '12 rue', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');
        Candidat::create(['epreuve_id' => $this->epreuve->id, 'etudiant_id' => $this->etudiant->id, 'statut' => 'present']);
    }

    public function test_signalement_cree_incident_statut_signale(): void
    {
        // Fake ciblé : les hooks Eloquent (uuid/statut) doivent
        // continuer à fonctionner (fake global les avale).
        Event::fake(EvaluationChanged::class);

        $this->actingAs($this->admin)->post(route('admin.evaluations.epreuves.incidents.store', $this->epreuve), [
            'type' => 'fraude',
            'description' => 'Téléphone aperçu pendant la composition.',
            'etudiant_id' => $this->etudiant->id,
        ])->assertRedirect();

        $incident = EvaluationIncident::where('epreuve_id', $this->epreuve->id)->firstOrFail();
        $this->assertSame('signale', $incident->statut);
        $this->assertSame('fraude', $incident->type);
        $this->assertSame($this->admin->id, $incident->created_by);

        Event::assertDispatched(
            EvaluationChanged::class,
            fn ($e) => $e->typeChangement === 'exam.incident.signale' && $e->entiteId === $incident->id
        );
    }

    public function test_type_invalide_refuse_422(): void
    {
        $this->actingAs($this->admin)->post(route('admin.evaluations.epreuves.incidents.store', $this->epreuve), [
            'type' => 'invasion_extraterrestre',
            'description' => 'Test.',
        ])->assertSessionHasErrors('type');

        $this->assertSame(0, EvaluationIncident::count());
    }

    public function test_etudiant_hors_epreuve_refuse_422(): void
    {
        $autre = Etudiant::create([
            'cin' => 'INC00002', 'nom' => 'Autre', 'prenom' => 'Ali',
            'email' => 'ali.incidents@test.ma', 'date_naissance' => '2004-02-02',
            'sexe' => 'M', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => '3 rue', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $autre->definirStatut('valide');

        $this->actingAs($this->admin)->post(route('admin.evaluations.epreuves.incidents.store', $this->epreuve), [
            'type' => 'retard',
            'description' => 'Test.',
            'etudiant_id' => $autre->id,
        ])->assertSessionHasErrors('etudiant_id');
    }

    public function test_transition_statut_autorisee_et_illegale_refusee(): void
    {
        $incident = new EvaluationIncident([
            'type' => 'materiel', 'description' => 'Vidéoprojecteur en panne.',
        ]);
        $incident->epreuve_id = $this->epreuve->id;
        $incident->created_by = $this->admin->id;
        $incident->save();

        $this->actingAs($this->admin)->post(
            route('admin.evaluations.incidents.transiter', [$incident, 'en_cours'])
        )->assertRedirect();
        $this->assertSame('en_cours', $incident->fresh()->statut);

        // Transition illégale en_cours → signale : refusée, rien ne change.
        $this->actingAs($this->admin)->post(
            route('admin.evaluations.incidents.transiter', [$incident, 'signale'])
        )->assertSessionHasErrors('statut');
        $this->assertSame('en_cours', $incident->fresh()->statut);
    }

    public function test_signalement_possible_epreuve_verrouillee(): void
    {
        // Un incident post-hoc (fraude découverte après coup) doit rester
        // signalable : le verrou épreuve protège les notes, pas l'observation.
        $this->epreuve->update(['statut' => 'locked']);

        $this->actingAs($this->admin)->post(route('admin.evaluations.epreuves.incidents.store', $this->epreuve), [
            'type' => 'fraude',
            'description' => 'Découverte après correction.',
        ])->assertRedirect();

        $this->assertSame(1, EvaluationIncident::where('epreuve_id', $this->epreuve->id)->count());
    }

    public function test_scope_faculte_404(): void
    {
        $autreFaculte = Faculte::create(['nom' => 'FSJES', 'code' => 'FSJES', 'description' => 'T', 'active' => true]);
        $adminRestreint = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.incidents@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $autreFaculte->id,
        ], 'admin_faculte');

        $this->actingAs($adminRestreint)->get(
            route('admin.evaluations.epreuves.incidents.index', $this->epreuve)
        )->assertNotFound();
    }

    public function test_sans_permission_403(): void
    {
        $lecteur = User::creerCompteBackOffice([
            'name' => 'Lecteur', 'email' => 'lecteur.incidents@universite.test', 'password' => 'Motdepasse@123',
        ], 'consultation');

        $this->actingAs($lecteur)->post(route('admin.evaluations.epreuves.incidents.store', $this->epreuve), [
            'type' => 'retard',
            'description' => 'Test.',
        ])->assertForbidden();
    }

    public function test_description_xss_echappee(): void
    {
        $this->actingAs($this->admin)->post(route('admin.evaluations.epreuves.incidents.store', $this->epreuve), [
            'type' => 'autre',
            'description' => '<script>alert(1)</script>',
        ])->assertRedirect();

        $reponse = $this->actingAs($this->admin)->get(
            route('admin.evaluations.epreuves.incidents.index', $this->epreuve)
        );
        $reponse->assertOk();
        $reponse->assertDontSee('<script>alert(1)</script>', false);
        $reponse->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }
}
