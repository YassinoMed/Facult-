<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\PlanningValidation;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Evaluation\Services\ExamGouvernanceValidationService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOT D — surface HTTP du circuit de gouvernance (§2.7, §2.8).
 *
 * Les tests de service prouvent les règles métier ; ce fichier prouve que
 * les routes existent réellement, qu'elles sont protégées côté serveur
 * (authentification, permission, périmètre facultaire) et qu'aucune
 * transition ne permet de publier en contournant le circuit.
 */
class ExamGouvernanceHttpAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private Examen $examenA;

    private User $preparateurA;

    private User $adminScolarite;

    private User $adminFaculteB;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true, 'modules.schedule.active' => true]);

        $this->faculteA = Faculte::create(['nom' => 'Faculté HTTP A', 'code' => 'FHA', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'Faculté HTTP B', 'code' => 'FHB', 'active' => true]);

        $this->adminScolarite = User::creerCompteBackOffice([
            'name' => 'Administrateur Scolarité HTTP',
            'email' => 'admin.scolarite.http@univ.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->preparateurA = User::creerCompteBackOffice([
            'name' => 'Préparateur Faculté A',
            'email' => 'prep.http.a@univ.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $this->adminFaculteB = User::creerCompteBackOffice([
            'name' => 'Administrateur Faculté B',
            'email' => 'admin.http.b@univ.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');

        $this->examenA = $this->creerExamenConforme($this->faculteA, 'EX-HTTP-A', 'GRP-HTTP-A');
    }

    private function creerExamenConforme(Faculte $faculte, string $codeExamen, string $codeGroupe): Examen
    {
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $programme = Programme::create([
            'faculte_id' => $faculte->id, 'code' => 'P-'.$codeExamen,
            'nom' => 'Programme '.$codeExamen, 'duree_annees' => 3,
            'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);

        $niveau = Niveau::create([
            'programme_id' => $programme->id, 'code' => 'L3',
            'nom' => 'Licence 3', 'ordre' => 3,
        ]);

        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id, 'code' => 'S5',
            'nom' => 'S5', 'numero' => 5, 'ordre' => 5,
        ]);

        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $programme->id,
            'code' => 'UE-'.$codeExamen, 'nom' => 'UE '.$codeExamen,
            'credits' => 6, 'coefficient' => 2,
        ]);

        $matiere = Matiere::create([
            'code' => 'MAT-'.$codeExamen, 'nom' => 'Module '.$codeExamen,
            'ue_id' => $ue->id, 'niveau' => 'L3', 'semestre' => 5,
            'credits' => 4, 'actif' => true,
        ]);

        $groupe = Group::create([
            'code' => $codeGroupe, 'nom' => 'Groupe '.$codeGroupe,
            'faculte_id' => $faculte->id, 'programme_id' => $programme->id,
            'niveau_id' => $niveau->id, 'niveau' => 'L3',
            'capacite' => 24, 'actif' => true,
        ]);

        $salle = Room::create([
            'code' => 'SALLE-'.$codeExamen, 'nom' => 'Salle '.$codeExamen,
            'capacite' => 60, 'type' => 'salle', 'actif' => true,
            'min_surveillants' => 2,
        ]);

        $session = SessionExamen::create([
            'annee_universitaire_id' => $annee->id, 'code' => 'S-'.$codeExamen,
            'nom' => 'Session '.$codeExamen, 'type' => 'principale',
            'date_debut' => '2027-01-10', 'date_fin' => '2027-01-25',
            'statut' => 'open',
        ]);

        $examen = Examen::create([
            'code' => $codeExamen, 'session_id' => $session->id,
            'annee_universitaire_id' => $annee->id,
            'programme_id' => $programme->id, 'niveau_id' => $niveau->id,
            'semestre_lmd_id' => $semestre->id,
            'libelle' => 'Examen '.$codeExamen, 'type' => 'examen',
            'statut' => 'scheduled',
        ]);

        $epreuve = Epreuve::create([
            'code' => 'EPR-'.$codeExamen, 'examen_id' => $examen->id,
            'session_id' => $session->id, 'annee_universitaire_id' => $annee->id,
            'matiere_id' => $matiere->id, 'groupe_id' => $groupe->id,
            'salle_id' => $salle->id, 'date' => '2027-01-15',
            'heure_debut' => '09:00', 'heure_fin' => '11:00',
            'duree_minutes' => 120, 'statut' => 'scheduled',
            'capacite_necessaire' => 24,
        ]);

        foreach (['prof.http.1@univ.test', 'prof.http.2@univ.test'] as $email) {
            $teacher = Teacher::create([
                'nom' => 'Prof', 'prenom' => 'HTTP', 'email' => $email,
                'statut' => 'actif',
            ]);

            Surveillance::create([
                'epreuve_id' => $epreuve->id, 'teacher_id' => $teacher->id,
                'role' => 'principal', 'statut' => 'confirme',
            ]);
        }

        return $examen;
    }

    private function creerValidationSoumise(): PlanningValidation
    {
        return app(ExamGouvernanceValidationService::class)
            ->soumettrePourValidation($this->examenA, $this->preparateurA->id);
    }

    private function creerUtilisateurGouvernance(string $email, string $role): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Utilisateur '.$email,
            'email' => $email,
            'password' => 'Motdepasse@123',
        ], $role);
    }

    /** Circuit complet par HTTP, sans passer par les services. */
    private function publierViaHttp(): PlanningValidation
    {
        // §2.7 — un rôle distinct par étape, comme en exploitation.
        $certificateur = $this->creerUtilisateurGouvernance('cert.http@univ.test', 'certificateur_qualite');
        $approbateur1 = $this->creerUtilisateurGouvernance('app1.http@univ.test', 'direction_etudes');
        $approbateur2 = $this->creerUtilisateurGouvernance('app2.http@univ.test', 'direction_etudes');
        $publicateur = $this->creerUtilisateurGouvernance('pub.http@univ.test', 'autorite_publication');

        $this->actingAs($this->preparateurA)
            ->post(route('admin.evaluations.gouvernance.soumettre', $this->examenA))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $validation = PlanningValidation::firstOrFail();

        $this->actingAs($certificateur)
            ->post(route('admin.evaluations.gouvernance.certifier', $validation))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingAs($approbateur1)
            ->post(route('admin.evaluations.gouvernance.approuver-1', $validation))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingAs($approbateur2)
            ->post(route('admin.evaluations.gouvernance.approuver-2', $validation))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingAs($publicateur)
            ->post(route('admin.evaluations.gouvernance.publier', $validation))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(PlanningValidation::STATUT_PUBLIE, $validation->fresh()->statut);
        $this->assertSame('published', $this->examenA->fresh()->statut);

        return $validation->fresh();
    }

    public function test_publication_affiche_les_actions_selon_statut_et_permission(): void
    {
        $service = app(ExamGouvernanceValidationService::class);
        $validation = $this->creerValidationSoumise();

        // Le préparateur (sans certify/approve/publish) ne voit aucune
        // action de décision, même si la version est ouverte.
        $this->actingAs($this->preparateurA)
            ->get(route('admin.evaluation.examens.publication'))
            ->assertOk()
            ->assertDontSee('Certifier la conformité')
            ->assertDontSee('Approuver (1/2)')
            ->assertDontSee('Publier officiellement');

        $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.publication'))
            ->assertOk()
            ->assertSee('Version 1 — soumis')
            ->assertSee('Certifier la conformité');

        $service->certifierConformite($validation, $this->adminScolarite->id);

        $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.publication'))
            ->assertOk()
            ->assertSee('Approuver (1/2)');

        // §2.7 — approbations et publication portées par des rôles distincts.
        $approbateur1 = $this->creerUtilisateurGouvernance('ui.app1@univ.test', 'direction_etudes');
        $approbateur2 = $this->creerUtilisateurGouvernance('ui.app2@univ.test', 'direction_etudes');
        $publicateur = $this->creerUtilisateurGouvernance('ui.pub@univ.test', 'autorite_publication');

        $service->approuverPremierNiveau($validation, $approbateur1->id);
        $service->approuverSecondNiveau($validation, $approbateur2->id);

        $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.publication'))
            ->assertOk()
            ->assertSee('Publier officiellement');

        $service->publierExamenOfficiel($validation, $publicateur->id);

        $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.publication'))
            ->assertOk()
            ->assertSee('Nouvelle version')
            ->assertSee('Version officielle');
    }

    public function test_soumission_post_gel_sans_justification_refusee(): void
    {
        $this->actingAs($this->preparateurA)
            ->post(route('admin.evaluations.gouvernance.soumettre', $this->examenA), [
                'date_gel' => now()->subDay()->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('gouvernance');

        $this->assertDatabaseCount('evaluation_planning_validations', 0);
        $this->assertSame('scheduled', $this->examenA->fresh()->statut);
    }

    public function test_soumission_post_gel_par_acteur_sans_permission_publish_refusee(): void
    {
        $this->actingAs($this->preparateurA)
            ->post(route('admin.evaluations.gouvernance.soumettre', $this->examenA), [
                'date_gel' => now()->subDay()->toDateString(),
                'justification_exceptionnelle' => 'Retard de saisie indépendant de ma volonté.',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('gouvernance');

        $this->assertDatabaseCount('evaluation_planning_validations', 0);
        $this->assertSame('scheduled', $this->examenA->fresh()->statut);
    }

    public function test_soumission_post_gel_avec_justification_habilitée_est_tracée(): void
    {
        $this->actingAs($this->adminScolarite)
            ->post(route('admin.evaluations.gouvernance.soumettre', $this->examenA), [
                'date_gel' => now()->subDay()->toDateString(),
                'justification_exceptionnelle' => 'Arbitrage exceptionnel du doyen après blocage administratif.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $validation = PlanningValidation::firstOrFail();

        $this->assertTrue((bool) $validation->exceptionnel);
        $this->assertSame(
            'Arbitrage exceptionnel du doyen après blocage administratif.',
            $validation->motif_exceptionnel
        );
        $this->assertNotNull($validation->date_gel_appliquee);
        $this->assertSame(PlanningValidation::STATUT_SOUMIS, $validation->statut);
    }

    public function test_revision_post_publication_cree_une_nouvelle_version_sans_alterer_la_publiee(): void
    {
        $publiee = $this->publierViaHttp();

        $publicateur = $this->creerUtilisateurGouvernance('rev.http@univ.test', 'autorite_publication');

        $this->actingAs($publicateur)
            ->post(route('admin.evaluations.gouvernance.nouvelle-version', $this->examenA), [
                'motif_modification' => 'Correction d’un conflit de salle découvert après publication.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $versions = PlanningValidation::where('examen_id', $this->examenA->id)
            ->orderBy('version')
            ->get();

        $this->assertCount(2, $versions);

        $versionPubliee = $versions->firstWhere('version', $publiee->version);
        $nouvelleVersion = $versions->firstWhere('version', $publiee->version + 1);

        $this->assertSame(PlanningValidation::STATUT_PUBLIE, $versionPubliee->statut);
        $this->assertSame(PlanningValidation::STATUT_SOUMIS, $nouvelleVersion->statut);
        $this->assertSame($publicateur->id, $nouvelleVersion->prepare_par);
        $this->assertNotSame(
            $versionPubliee->empreinte_planning,
            null,
            'La version publiée conserve son empreinte de scellement.'
        );

        // L'examen reste officiellement publié : la version publiée fait
        // foi jusqu'à validation complète de la suivante.
        $this->assertSame('published', $this->examenA->fresh()->statut);
    }

    public function test_revision_post_publication_refusee_sans_permission_publish(): void
    {
        $this->publierViaHttp();

        $this->actingAs($this->preparateurA)
            ->post(route('admin.evaluations.gouvernance.nouvelle-version', $this->examenA), [
                'motif_modification' => 'Tentative de révision par un préparateur non habilité.',
            ])
            ->assertForbidden();

        $this->assertSame(
            1,
            PlanningValidation::where('examen_id', $this->examenA->id)->count()
        );
    }

    public function test_invite_ne_peut_pas_soumettre_un_planning(): void
    {
        $this->post(route('admin.evaluations.gouvernance.soumettre', $this->examenA))
            ->assertRedirect(route('admin.connexion.show'));

        $this->assertDatabaseCount('evaluation_planning_validations', 0);
    }

    public function test_preparateur_soumet_son_propre_examen(): void
    {
        $this->actingAs($this->preparateurA)
            ->post(route('admin.evaluations.gouvernance.soumettre', $this->examenA))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $validation = PlanningValidation::firstOrFail();
        $this->assertSame($this->examenA->id, $validation->examen_id);
        $this->assertSame($this->preparateurA->id, $validation->prepare_par);
        $this->assertSame(PlanningValidation::STATUT_SOUMIS, $validation->statut);
    }

    /** Anti-IDOR : un acteur d'une autre faculté est refusé côté serveur. */
    public function test_administrateur_d_une_autre_faculte_ne_peut_pas_soumettre(): void
    {
        $this->actingAs($this->adminFaculteB)
            ->post(route('admin.evaluations.gouvernance.soumettre', $this->examenA))
            ->assertForbidden();

        $this->assertDatabaseCount('evaluation_planning_validations', 0);
    }

    /** Un rôle de préparation ne peut pas certifier, même sur sa faculté. */
    public function test_preparateur_ne_peut_pas_certifier_via_http(): void
    {
        $validation = $this->creerValidationSoumise();

        $this->actingAs($this->preparateurA)
            ->post(route('admin.evaluations.gouvernance.certifier', $validation))
            ->assertForbidden();

        $this->assertSame(
            PlanningValidation::STATUT_SOUMIS,
            $validation->fresh()->statut
        );
    }

    public function test_autorite_habilitée_certifie_une_version_soumise(): void
    {
        $validation = $this->creerValidationSoumise();

        $this->actingAs($this->adminScolarite)
            ->post(route('admin.evaluations.gouvernance.certifier', $validation))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $fraiche = $validation->fresh();
        $this->assertSame(PlanningValidation::STATUT_CERTIFIE, $fraiche->statut);
        $this->assertSame($this->adminScolarite->id, $fraiche->certifie_par);
        $this->assertNotNull($fraiche->certifie_at);
    }

    /** La publication reste impossible tant que la double approbation manque. */
    public function test_publication_refusee_avant_double_approbation(): void
    {
        $validation = $this->creerValidationSoumise();

        $this->actingAs($this->adminScolarite)
            ->post(route('admin.evaluations.gouvernance.certifier', $validation))
            ->assertRedirect();

        $this->actingAs($this->adminScolarite)
            ->post(route('admin.evaluations.gouvernance.publier', $validation))
            ->assertRedirect()
            ->assertSessionHasErrors('gouvernance');

        $this->assertSame(
            PlanningValidation::STATUT_CERTIFIE,
            $validation->fresh()->statut
        );
        $this->assertSame('scheduled', $this->examenA->fresh()->statut);
    }

    /** La route de transition ne doit jamais publier en un clic. */
    public function test_transition_directe_vers_published_est_bloquee(): void
    {
        $this->actingAs($this->preparateurA)
            ->post(route('admin.evaluations.examens.transiter', [
                'examen' => $this->examenA,
                'cible' => 'published',
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors('examen');

        $this->assertSame('scheduled', $this->examenA->fresh()->statut);
        $this->assertNull($this->examenA->fresh()->published_at);
    }
}
