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
use App\Modules\Evaluation\Services\ExamSeparationRolesDiagnosticService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Teacher;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §2.7 — séparation des pouvoirs PAR RÔLE (complément de la séparation par
 * personne).
 *
 * La règle est ACTIVE par défaut ; ce fichier prouve trois choses :
 * 1. désactivée, elle ne change rien au circuit existant ;
 * 2. activée, elle refuse un acteur dont le rôle est déjà intervenu et un
 *    rôle qui ne porte pas la permission de l'étape ;
 * 3. le diagnostic rend la décision institutionnelle mesurable : la matrice
 *    livrée est faisable (rôles dédiés), et l'algorithme sait aussi
 *    reconnaître une matrice infaisable et lister les actions requises.
 */
class ExamSeparationRolesParRoleTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private Examen $examen;

    private ExamGouvernanceValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.evaluation.active' => true,
            'modules.schedule.active' => true,
            'modules.evaluation.separation_pouvoirs_par_role' => false,
        ]);

        $this->faculte = Faculte::create(['nom' => 'Faculté Rôles', 'code' => 'FROLE', 'active' => true]);

        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'P-ROLE', 'nom' => 'Programme Rôles',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niveau = Niveau::create([
            'programme_id' => $programme->id, 'code' => 'L3', 'nom' => 'Licence 3', 'ordre' => 3,
        ]);
        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id, 'code' => 'S5', 'nom' => 'S5', 'numero' => 5, 'ordre' => 5,
        ]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $programme->id,
            'code' => 'UE-ROLE', 'nom' => 'UE Rôles', 'credits' => 6, 'coefficient' => 2,
        ]);
        $matiere = Matiere::create([
            'code' => 'MAT-ROLE', 'nom' => 'Module Rôles', 'ue_id' => $ue->id,
            'niveau' => 'L3', 'semestre' => 5, 'credits' => 4, 'actif' => true,
        ]);

        $groupe = Group::create([
            'code' => 'GRP-ROLE', 'nom' => 'Groupe Rôles',
            'faculte_id' => $this->faculte->id, 'programme_id' => $programme->id,
            'niveau_id' => $niveau->id, 'niveau' => 'L3', 'capacite' => 24, 'actif' => true,
        ]);

        $salle = Room::create([
            'code' => 'SALLE-ROLE', 'nom' => 'Salle Rôles', 'capacite' => 60,
            'type' => 'salle', 'actif' => true, 'min_surveillants' => 2,
        ]);

        $session = SessionExamen::create([
            'annee_universitaire_id' => $annee->id, 'code' => 'S-ROLE',
            'nom' => 'Session Rôles', 'type' => 'principale',
            'date_debut' => '2027-01-10', 'date_fin' => '2027-01-25', 'statut' => 'open',
        ]);

        $this->examen = Examen::create([
            'code' => 'EX-ROLE', 'session_id' => $session->id,
            'annee_universitaire_id' => $annee->id,
            'programme_id' => $programme->id, 'niveau_id' => $niveau->id,
            'semestre_lmd_id' => $semestre->id,
            'libelle' => 'Examen Rôles', 'type' => 'examen', 'statut' => 'scheduled',
        ]);

        $epreuve = Epreuve::create([
            'code' => 'EPR-ROLE', 'examen_id' => $this->examen->id, 'session_id' => $session->id,
            'annee_universitaire_id' => $annee->id, 'matiere_id' => $matiere->id,
            'groupe_id' => $groupe->id, 'salle_id' => $salle->id,
            'date' => '2027-01-15', 'heure_debut' => '09:00', 'heure_fin' => '11:00',
            'duree_minutes' => 120, 'statut' => 'scheduled', 'capacite_necessaire' => 24,
        ]);

        foreach (['prof1.role@univ.test', 'prof2.role@univ.test'] as $email) {
            $teacher = Teacher::create([
                'nom' => 'Prof', 'prenom' => 'Rôles', 'email' => $email, 'statut' => 'actif',
            ]);
            Surveillance::create([
                'epreuve_id' => $epreuve->id, 'teacher_id' => $teacher->id,
                'role' => 'principal', 'statut' => 'confirme',
            ]);
        }

        $this->service = app(ExamGouvernanceValidationService::class);
    }

    private function utilisateur(string $prefixe, string $role): User
    {
        $attributs = [
            'name' => 'Utilisateur '.$prefixe,
            'email' => $prefixe.'@univ.test',
            'password' => 'Motdepasse@123',
        ];

        if ($role === 'admin_faculte') {
            $attributs['faculte_id'] = $this->faculte->id;
        }

        return User::creerCompteBackOffice($attributs, $role);
    }

    /** Mode par défaut : la séparation par personne suffit, rien ne change. */
    public function test_mode_desactive_le_circuit_reste_par_personne(): void
    {
        $preparateur = $this->utilisateur('prep.role', 'admin_scolarite');
        $certificateur = $this->utilisateur('cert.role', 'admin_scolarite');

        $v = $this->service->soumettrePourValidation($this->examen, $preparateur->id);
        $v = $this->service->certifierConformite($v, $certificateur->id);

        $this->assertSame(PlanningValidation::STATUT_CERTIFIE, $v->statut);
        $this->assertSame($certificateur->id, $v->certifie_par);
    }

    /** Mode actif : même rôle, personnes distinctes → refus explicite. */
    public function test_mode_actif_refuse_le_meme_role_a_la_certification(): void
    {
        config(['modules.evaluation.separation_pouvoirs_par_role' => true]);

        $preparateur = $this->utilisateur('prep.role', 'admin_scolarite');
        $certificateur = $this->utilisateur('cert.role', 'admin_scolarite');

        $v = $this->service->soumettrePourValidation($this->examen, $preparateur->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Séparation des pouvoirs par rôle');

        $this->service->certifierConformite($v, $certificateur->id);
    }

    /** Mode actif : rôle différent mais sans la permission de l'étape → refus. */
    public function test_mode_actif_exige_que_le_role_porte_la_permission(): void
    {
        config(['modules.evaluation.separation_pouvoirs_par_role' => true]);

        $preparateur = $this->utilisateur('prep.role', 'admin_faculte');

        // Le préparateur admin_faculte n'a pas evaluations.certify : c'est le
        // rôle du préparateur qui est comparé, pas seulement celui de l'acteur.
        // On vérifie donc l'autre sens : un admin_faculte ne peut pas certifier.
        $v = $this->service->soumettrePourValidation($this->examen, $preparateur->id);

        $certificateurFaculte = $this->utilisateur('cert.faculte.role', 'admin_faculte');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ne porte pas la permission « evaluations.certify »');

        $this->service->certifierConformite($v, $certificateurFaculte->id);
    }

    /** Mode actif : le compte de secours reste utilisable (exemption tracée). */
    public function test_mode_actif_laisse_le_compte_de_secours_certifier(): void
    {
        config(['modules.evaluation.separation_pouvoirs_par_role' => true]);

        $preparateur = $this->utilisateur('prep.role', 'admin_scolarite');
        $secours = $this->utilisateur('secours.role', 'super_admin');

        $v = $this->service->soumettrePourValidation($this->examen, $preparateur->id);
        $v = $this->service->certifierConformite($v, $secours->id);

        $this->assertSame(PlanningValidation::STATUT_CERTIFIE, $v->statut);
    }

    /** Mode actif : publier avec le rôle qui a approuvé → refus. */
    public function test_mode_actif_refuse_la_publication_par_un_role_deja_intervenu(): void
    {
        config(['modules.evaluation.separation_pouvoirs_par_role' => true]);

        $preparateur = $this->utilisateur('prep.role', 'admin_faculte');
        $certificateur = $this->utilisateur('cert.role', 'super_admin');
        $approbateur1 = $this->utilisateur('app1.role', 'admin_scolarite');
        $approbateur2 = $this->utilisateur('app2.role', 'admin_scolarite');
        $publicateur = $this->utilisateur('pub.role', 'admin_scolarite');

        $v = $this->service->soumettrePourValidation($this->examen, $preparateur->id);
        $v = $this->service->certifierConformite($v, $certificateur->id);
        $v = $this->service->approuverPremierNiveau($v, $approbateur1->id);
        $v = $this->service->approuverSecondNiveau($v, $approbateur2->id);

        $this->assertSame(PlanningValidation::STATUT_APPROUVE, $v->statut);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('le publicateur doit exercer un rôle différent');

        $this->service->publierExamenOfficiel($v, $publicateur->id);
    }

    /** Le diagnostic sur la matrice livrée : faisable, attribution proposée. */
    public function test_diagnostic_matrice_livree_est_faisable(): void
    {
        $diagnostic = app(ExamSeparationRolesDiagnosticService::class)->diagnostic();

        $this->assertTrue($diagnostic['faisable']);
        $this->assertSame([], $diagnostic['etapes_sans_role']);
        $this->assertSame([], $diagnostic['actions_requises']);
        $this->assertSame('super_admin', $diagnostic['role_technique']);
        $this->assertSame(
            ['admin_scolarite', 'certificateur_qualite'],
            $diagnostic['roles_par_etape']['certificateur']
        );
        $this->assertSame([
            'preparateur' => 'admin_faculte',
            'certificateur' => 'admin_scolarite',
            'approbateur' => 'direction_etudes',
            'publicateur' => 'autorite_publication',
        ], $diagnostic['proposition']);
    }

    /** Une matrice qui concentre tout sur un rôle reste infaisable et documentée. */
    public function test_diagnostic_matrice_infaisable_liste_les_actions_requises(): void
    {
        $matrice = [
            'admin_faculte' => ['evaluations.update'],
            'admin_scolarite' => [
                'evaluations.update', 'evaluations.certify',
                'evaluations.approve', 'evaluations.publish',
            ],
            'super_admin' => [
                'evaluations.update', 'evaluations.certify',
                'evaluations.approve', 'evaluations.publish',
            ],
        ];

        $diagnostic = app(ExamSeparationRolesDiagnosticService::class)->diagnostic($matrice);

        $this->assertFalse($diagnostic['faisable']);
        $this->assertNull($diagnostic['proposition']);
        $this->assertSame([], $diagnostic['etapes_sans_role']);
        $this->assertCount(4, $diagnostic['actions_requises']);
    }

    /** L'algorithme reconnaît une matrice qui permet une attribution distincte. */
    public function test_diagnostic_propose_une_attribution_quand_la_matrice_le_permet(): void
    {
        $matrice = [
            'agent_scolarite' => ['evaluations.update'],
            'certificateur_qualite' => ['evaluations.certify'],
            'direction_etudes' => ['evaluations.approve'],
            'autorite_publication' => ['evaluations.publish'],
            'super_admin' => ['evaluations.update', 'evaluations.certify', 'evaluations.approve', 'evaluations.publish'],
        ];

        $diagnostic = app(ExamSeparationRolesDiagnosticService::class)->diagnostic($matrice);

        $this->assertTrue($diagnostic['faisable']);
        $this->assertSame([], $diagnostic['actions_requises']);
        $this->assertSame([
            'preparateur' => 'agent_scolarite',
            'certificateur' => 'certificateur_qualite',
            'approbateur' => 'direction_etudes',
            'publicateur' => 'autorite_publication',
        ], $diagnostic['proposition']);
    }

    /** La commande réussit avec la matrice livrée, que la règle soit active ou non. */
    public function test_commande_diagnostic_reussit_avec_la_matrice_livree(): void
    {
        config(['modules.evaluation.separation_pouvoirs_par_role' => true]);

        $this->artisan('examens:separation-roles')
            ->expectsOutputToContain('Attribution distincte possible')
            ->assertExitCode(0);

        config(['modules.evaluation.separation_pouvoirs_par_role' => false]);

        $this->artisan('examens:separation-roles')
            ->assertExitCode(0);
    }

    /** La commande échoue quand la règle est active et non satisfaite. */
    public function test_commande_diagnostic_echoue_si_mode_actif_et_infaisable(): void
    {
        config(['modules.evaluation.separation_pouvoirs_par_role' => true]);

        $this->mock(ExamSeparationRolesDiagnosticService::class, function ($mock): void {
            $mock->shouldReceive('diagnostic')->once()->andReturn([
                'mode_actif' => true,
                'roles_par_etape' => [
                    'preparateur' => ['admin_faculte', 'admin_scolarite'],
                    'certificateur' => ['admin_scolarite'],
                    'approbateur' => ['admin_scolarite'],
                    'publicateur' => ['admin_scolarite'],
                ],
                'etapes_sans_role' => [],
                'faisable' => false,
                'proposition' => null,
                'actions_requises' => ['Créer des rôles distincts pour chaque étape.'],
                'role_technique' => 'super_admin',
            ]);
        });

        $this->artisan('examens:separation-roles')
            ->expectsOutputToContain('Aucune attribution distincte')
            ->assertExitCode(1);
    }
}
