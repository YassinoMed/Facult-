<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\User;
use App\Modules\ParcoursAcademique\Models\ParcoursDispense;
use App\Modules\ParcoursAcademique\Services\AmenagementParcoursService;
use App\Modules\ParcoursAcademique\Services\SimulationParcoursService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * LOOP-04 — vérification indépendante du passeport académique LMD.
 *
 * Distinct de `ParcoursAcademiqueTest` et du rapport LOOP-04 préexistant :
 * ce fichier prouve les réserves R-04/4, R-04/5 et R-04/6 par des tests
 * ciblés, puis sert de non-régression au correctif minimal.
 */
class Loop04PasseportAcademiqueVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Niveau $niveauL1;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.parcours-academique.active' => true]);

        $this->faculte = Faculte::create([
            'nom' => 'Faculté Vérification LOOP-04',
            'code' => 'FAC-VL04',
            'description' => 'Vérification LOOP-04',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027 Vérification LOOP-04',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'LIC-VL04',
            'nom' => 'Licence Vérification LOOP-04',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);

        $this->niveauL1 = Niveau::create([
            'programme_id' => $this->programme->id,
            'code' => 'L1',
            'nom' => 'Licence 1',
            'ordre' => 1,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité Vérification LOOP-04',
            'email' => 'loop04.verification@universite.test',
            'password' => 'MotDePasse123!',
            'faculte_id' => $this->faculte->id,
        ], 'admin_scolarite');
    }

    public function test_simulation_matiere_recalcule_sans_ecriture_officielle(): void
    {
        [$etudiant, $inscription, $matiere] = $this->creerDossierNote(12.0);

        $notesAvant = Note::where('etudiant_id', $etudiant->id)->count();

        $simulation = app(SimulationParcoursService::class)->simuler($etudiant, 'validation_matiere', [
            'matiere_id' => $matiere->id,
            'note' => 18.0,
        ], $inscription, $this->admin->id);

        $this->assertSame('validation_matiere', $simulation->resultat['scenario_propose']);
        $this->assertEquals(18.0, $simulation->resultat['moyenne_simulee']);
        $this->assertSame('amélioration', $simulation->resultat['resultat_final']);
        $this->assertSame($notesAvant, Note::where('etudiant_id', $etudiant->id)->count());
        $this->assertSame(12.0, (float) Note::where('etudiant_id', $etudiant->id)->firstOrFail()->note);
    }

    public function test_simulation_matiere_hypothese_incomplete_ne_modifie_rien(): void
    {
        [$etudiant, $inscription] = $this->creerDossierNote(10.0);

        $simulation = app(SimulationParcoursService::class)->simuler($etudiant, 'validation_matiere', [
            'matiere_id' => 0,
            'note' => 25.0,
        ], $inscription, $this->admin->id);

        $this->assertSame('impossible', $simulation->resultat['resultat_final']);
        $this->assertSame([], $simulation->resultat['conditions_satisfaites']);
        $this->assertNotEmpty($simulation->resultat['conditions_non_satisfaites']);
    }

    public function test_repli_matiere_id_est_compatible_tableau_et_non_accentue(): void
    {
        $methode = new \ReflectionMethod(SimulationParcoursService::class, 'matiereIdDeLigne');
        $methode->setAccessible(true);

        $this->assertSame(12, $methode->invoke(null, ['matiere_id' => 12]));
        // R-04/4 : après toArray(), la relation est un tableau ; `?->id`
        // émettrait un warning et perdrait le repli.
        $this->assertSame(7, $methode->invoke(null, ['matiere_id' => null, 'matiere' => ['id' => 7]]));
        $this->assertSame(0, $methode->invoke(null, ['matiere_id' => null, 'matiere' => null]));
    }

    public function test_fsm_dispense_refuse_les_transitions_non_autorisees(): void
    {
        [$etudiant] = $this->creerDossierNote(11.0, avecNote: false);

        $service = app(AmenagementParcoursService::class);
        $dispense = $service->proposerDispense($etudiant, [
            'cible_libelle' => 'Anglais L1',
            'motif' => 'Certification externe',
        ], $this->admin->id);

        $this->assertSame('proposee', $dispense->statut);

        try {
            $service->deciderDispense($dispense, 'annulee', 'Transition réservée', $this->admin->id);
            $this->fail('La transition proposee → annulee aurait dû être refusée.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame('proposee', $dispense->fresh()->statut);
        $this->assertDatabaseMissing('parcours_dispenses', [
            'id' => $dispense->id,
            'statut' => 'annulee',
        ]);

        $decidee = $service->deciderDispense($dispense->fresh(), 'validee', 'Dossier conforme', $this->admin->id);
        $this->assertSame('validee', $decidee->statut);
        $this->assertSame('validee', ParcoursDispense::findOrFail($dispense->id)->statut);
    }

    /**
     * @return array{0: Etudiant, 1: InscriptionAcademique, 2: Matiere|null}
     */
    private function creerDossierNote(float $note, bool $avecNote = true): array
    {
        $etudiant = Etudiant::create([
            'cin' => 'L04-'.uniqid(),
            'nom' => 'Benali',
            'prenom' => 'Sara',
            'email' => 'loop04.'.uniqid().'@universite.test',
            'date_naissance' => '2004-01-01',
            'sexe' => 'F',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Rue de vérification',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');

        $inscription = $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'niveau_id' => $this->niveauL1->id,
            'niveau' => 'L1',
            'statut' => 'en_cours',
        ]);
        $semestre = $inscription->semestres()->create(['numero' => 1]);

        if (! $avecNote) {
            return [$etudiant, $inscription, null];
        }

        $matiere = Matiere::create([
            'code' => 'L04-'.uniqid(),
            'nom' => 'Matière de vérification LOOP-04',
            'coefficient' => 2,
            'credits' => 5,
            'niveau' => 'L1',
            'semestre' => 1,
        ]);

        Note::create([
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'semestre_id' => $semestre->id,
            'matiere_id' => $matiere->id,
            'note' => $note,
            'coefficient' => $matiere->coefficient,
            'credits' => $matiere->credits,
            'session' => 'normale',
        ]);

        return [$etudiant, $inscription, $matiere];
    }
}
