<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\Schedule\Services\ScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * SCHEDULE-09 (réconciliation clôture) — les portails étudiant n'exposent
 * JAMAIS un planning non publié : la workflow Phase-12 (`Schedule::
 * TRANSITIONS`) fait de `publie`/`verrouille` (verrouille uniquement
 * accessible DEPUIS publie) l'ensemble « publié ». Avant publication
 * (brouillon/relecture/valide), le planning reste côté administration —
 * le contrôle pré-publication (conflits, §12.19) serait sinon contourné.
 */
class PortailEtudiantPublicationTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Matiere $matiere;

    private Group $groupe;

    private Etudiant $etudiant;

    private Teacher $enseignant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.schedule.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->matiere = Matiere::create(['code' => 'PUB-1', 'nom' => 'Publication', 'niveau' => 'L1', 'semestre' => 1]);
        $this->groupe = Group::create([
            'code' => 'G-PUB', 'nom' => 'Groupe pub', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'actif' => true,
        ]);

        // LOT 2 : un créneau sans enseignant est un conflit dur de
        // publication (§12.19). Le fixture doit représenter un planning
        // publiable pour tester la visibilité portail, pas le conflit.
        $this->enseignant = Teacher::create([
            'nom' => 'Ben Salah', 'prenom' => 'Karim',
            'email' => 'portail.pub.ens@test', 'password' => 'Enseignant@123',
            'actif' => true, 'statut' => 'actif',
        ]);

        $this->etudiant = Etudiant::create([
            'cin' => '50000001', 'nom' => 'Portail', 'prenom' => 'Etudiant', 'email' => 'portail.pub@t.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
            'groupe_id' => $this->groupe->id,
        ]);
        $this->etudiant->definirStatut('valide');
    }

    private function creerPlanning(array $surcharge = []): Schedule
    {
        return Schedule::create(array_merge([
            'annee_universitaire_id' => $this->annee->id, 'semestre' => 1,
            'groupe_id' => $this->groupe->id, 'nom' => 'Planning', 'actif' => true,
        ], $surcharge));
    }

    private function ajouterCreneau(Schedule $planning, ?string $jour = null): ScheduleSlot
    {
        return ScheduleSlot::create([
            'schedule_id' => $planning->id, 'matiere_id' => $this->matiere->id,
            'teacher_id' => $this->enseignant->id,
            'day_of_week' => $jour ?? 'monday', 'start_time' => '08:00:00', 'end_time' => '10:00:00',
            'type' => 'course', 'status' => 'scheduled',
        ]);
    }

    #[DataProvider('statutsNonPublies')]
    public function test_portail_ne_doit_pas_exposer_les_plannings_non_publies(string $statut): void
    {
        $planning = $this->creerPlanning(['statut' => $statut]);
        $this->ajouterCreneau($planning);

        // Sémantique portail existante : aucun planning visible pour le
        // groupe → 404 « Aucun emploi du temps » — rien n'est exposé.
        $reponse = $this->withSession(['etudiant_auth_id' => $this->etudiant->id])
            ->get(route('etudiant.emploi-du-temps.semaine'));

        $this->assertTrue(in_array($reponse->status(), [200, 404], true));
        $this->assertStringNotContainsString($this->matiere->nom, $reponse->getContent());
    }

    public static function statutsNonPublies(): array
    {
        return [
            'brouillon' => ['brouillon'],
            'relecture' => ['relecture'],
            'valide' => ['valide'],
        ];
    }

    /**
     * Publication via le workflow réel : brouillon → valide → publie
     * (TRANSITIONS serveur), contrôle pré-publication §12.19 inclus.
     */
    private function publier(Schedule $planning): void
    {
        $service = app(ScheduleService::class);
        if ($planning->fresh()->statut === 'brouillon') {
            $service->transiterPlanning($planning->fresh(), 'valide');
        }
        $service->publierPlanning($planning->fresh());
    }

    public function test_portail_expose_uniquement_apres_publication(): void
    {
        $planning = $this->creerPlanning();
        $this->ajouterCreneau($planning);

        // Brouillon : invisible (404 « aucun emploi du temps » — rien exposé).
        $reponse = $this->withSession(['etudiant_auth_id' => $this->etudiant->id])
            ->get(route('etudiant.emploi-du-temps.semaine'));
        $this->assertTrue(in_array($reponse->status(), [200, 404], true));
        $this->assertStringNotContainsString($this->matiere->nom, $reponse->getContent());

        // Publication via le service (contrôle §12.19 passé : aucun conflit).
        $this->publier($planning);

        $this->withSession(['etudiant_auth_id' => $this->etudiant->id])
            ->get(route('etudiant.emploi-du-temps.semaine'))
            ->assertOk()
            ->assertSee($this->matiere->nom);
    }

    public function test_portail_expose_les_plannings_verrouilles(): void
    {
        // `verrouille` n'est accessible QUE depuis `publie` (TRANSITIONS) :
        // un planning verrouillé a été publié — il reste visible.
        $planning = $this->creerPlanning();
        $this->ajouterCreneau($planning);
        $this->publier($planning);
        app(ScheduleService::class)->verrouillerPlanning($planning->fresh());

        $this->withSession(['etudiant_auth_id' => $this->etudiant->id])
            ->get(route('etudiant.emploi-du-temps.semaine'))
            ->assertOk()
            ->assertSee($this->matiere->nom);
    }

    public function test_dashboard_ne_doit_pas_exposer_les_cours_non_publies(): void
    {
        $planning = $this->creerPlanning(); // brouillon
        // Le dashboard n'affiche que les cours du JOUR réel : créneau posé
        // sur le jour courant pour que la visibilité dépende uniquement
        // du statut de publication.
        $jourCourant = strtolower(Carbon::now()->format('l'));
        $this->ajouterCreneau($planning, $jourCourant);

        $reponse = $this->withSession(['etudiant_auth_id' => $this->etudiant->id])
            ->get(route('etudiant.dashboard'));

        $reponse->assertOk();
        $this->assertStringNotContainsString($this->matiere->nom, $reponse->getContent());

        $this->publier($planning);

        $contenuPublie = $this->withSession(['etudiant_auth_id' => $this->etudiant->id])
            ->get(route('etudiant.dashboard'))->getContent();
        $this->assertStringContainsString($this->matiere->nom, $contenuPublie);
    }
}
