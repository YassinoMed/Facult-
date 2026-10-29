<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\PlanningEvenement;
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
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOT D — Certification, double validation, rejet, escalade, gel (§2.7, §2.8, §3.5).
 *
 * Chaque contrôle est prouvé par exécution : acteur autorisé, acteur non
 * autorisé, acteur du mauvais rôle, et conservation de l'historique.
 */
class ExamGouvernanceDoubleValidationTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Examen $examen;

    private User $preparateur;

    private User $certificateur;

    private User $approbateur1;

    private User $approbateur2;

    private User $publicateur;

    private User $preparateurSansDroitDerogation;

    private ExamGouvernanceValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true, 'modules.schedule.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'Faculté Gouvernance', 'code' => 'FGOUV', 'active' => true]);

        // §2.7 — séparation des pouvoirs PAR RÔLE : chaque étape est portée
        // par un rôle distinct. Le préparateur conserve `admin_scolarite`
        // (préparation) ; les décisions sont réservées aux rôles dédiés.
        $this->preparateur = $this->creerUtilisateur('prep.gouv@univ.test', 'admin_scolarite');
        $this->certificateur = $this->creerUtilisateur('cert.gouv@univ.test', 'certificateur_qualite');
        $this->approbateur1 = $this->creerUtilisateur('app1.gouv@univ.test', 'direction_etudes');
        $this->approbateur2 = $this->creerUtilisateur('app2.gouv@univ.test', 'direction_etudes');
        $this->publicateur = $this->creerUtilisateur('pub.gouv@univ.test', 'autorite_publication');

        // Rôle de préparation SANS droit de publication : c'est le profil
        // exact qui ne doit jamais pouvoir déroger au gel institutionnel.
        $this->preparateurSansDroitDerogation = User::creerCompteBackOffice([
            'name' => 'Préparateur sans dérogation',
            'email' => 'prep.faculte.gouv@univ.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculte->id,
        ], 'admin_faculte');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'P-GOUV', 'nom' => 'Programme Gouvernance',
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
            'code' => 'UE-GOUV', 'nom' => 'UE Gouvernance', 'credits' => 6, 'coefficient' => 2,
        ]);
        $matiere = Matiere::create([
            'code' => 'MAT-GOUV', 'nom' => 'Module Gouvernance', 'ue_id' => $ue->id,
            'niveau' => 'L3', 'semestre' => 5, 'credits' => 4, 'actif' => true,
        ]);

        $groupe = Group::create([
            'code' => 'GRP-GOUV', 'nom' => 'Groupe Gouvernance',
            'faculte_id' => $this->faculte->id, 'programme_id' => $programme->id,
            'niveau_id' => $niveau->id, 'niveau' => 'L3', 'capacite' => 24, 'actif' => true,
        ]);

        $salle = Room::create([
            'code' => 'SALLE-GOUV', 'nom' => 'Salle Gouvernance', 'capacite' => 60,
            'type' => 'salle', 'actif' => true, 'min_surveillants' => 2,
        ]);

        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id, 'code' => 'S-GOUV',
            'nom' => 'Session Gouvernance', 'type' => 'principale',
            'date_debut' => '2027-01-10', 'date_fin' => '2027-01-25', 'statut' => 'open',
        ]);

        $this->examen = Examen::create([
            'code' => 'EX-GOUV', 'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $programme->id, 'niveau_id' => $niveau->id,
            'semestre_lmd_id' => $semestre->id,
            'libelle' => 'Examen Gouvernance', 'type' => 'examen', 'statut' => 'scheduled',
        ]);

        $epreuve = Epreuve::create([
            'code' => 'EPR-GOUV', 'examen_id' => $this->examen->id, 'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id, 'matiere_id' => $matiere->id,
            'groupe_id' => $groupe->id, 'salle_id' => $salle->id,
            'date' => '2027-01-15', 'heure_debut' => '09:00', 'heure_fin' => '11:00',
            'duree_minutes' => 120, 'statut' => 'scheduled', 'capacite_necessaire' => 24,
        ]);

        foreach (['prof1.gouv@univ.test', 'prof2.gouv@univ.test'] as $email) {
            $teacher = Teacher::create([
                'nom' => 'Prof', 'prenom' => 'Gouvernance', 'email' => $email, 'statut' => 'actif',
            ]);
            Surveillance::create([
                'epreuve_id' => $epreuve->id, 'teacher_id' => $teacher->id,
                'role' => 'principal', 'statut' => 'confirme',
            ]);
        }

        $this->service = app(ExamGouvernanceValidationService::class);
    }

    private function creerUtilisateur(string $email, string $role): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Utilisateur '.$email,
            'email' => $email,
            'password' => 'Motdepasse@123',
        ], $role);
    }

    private function soumettre(): PlanningValidation
    {
        return $this->service->soumettrePourValidation($this->examen, $this->preparateur->id);
    }

    private function validerJusquAuxApprobations(): PlanningValidation
    {
        $v = $this->soumettre();
        $v = $this->service->certifierConformite($v, $this->certificateur->id);
        $v = $this->service->approuverPremierNiveau($v, $this->approbateur1->id);

        return $this->service->approuverSecondNiveau($v, $this->approbateur2->id);
    }

    public function test_soumission_cree_une_version_persistee_et_auditee(): void
    {
        $v = $this->soumettre();

        $this->assertSame(PlanningValidation::STATUT_SOUMIS, $v->statut);
        $this->assertSame(1, $v->version);
        $this->assertSame($this->preparateur->id, $v->prepare_par);
        $this->assertNotNull($v->empreinte_planning);
        $this->assertSame(1, $v->evenements()->where('action', PlanningEvenement::ACTION_SOUMIS)->count());
    }

    /** AC-D1 — le préparateur ne peut pas certifier sa propre version. */
    public function test_preparateur_ne_peut_pas_certifier(): void
    {
        $v = $this->soumettre();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('préparateur');

        $this->service->certifierConformite($v, $this->preparateur->id);
    }

    /**
     * §2.7 — la règle est ACTIVE par défaut : deux personnes portant le
     * même rôle ne peuvent pas se relayer à la certification, même si elles
     * sont distinctes. C'est la preuve que la configuration livrée n'est pas
     * seulement une possibilité théorique.
     */
    public function test_separation_par_role_active_par_defaut_refuse_un_meme_role(): void
    {
        $this->assertTrue((bool) config('modules.evaluation.separation_pouvoirs_par_role'));

        $homologue = $this->creerUtilisateur('homologue.gouv@univ.test', 'admin_scolarite');
        $v = $this->soumettre();

        try {
            $this->service->certifierConformite($v, $homologue->id);
            $this->fail('Deux personnes du même rôle ne doivent pas se relayer à la certification.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Séparation des pouvoirs par rôle', $e->getMessage());
            $this->assertStringContainsString('rôle différent', $e->getMessage());
        }
    }

    public function test_certificateur_ne_peut_pas_etre_premier_approbateur(): void
    {
        $v = $this->soumettre();
        $v = $this->service->certifierConformite($v, $this->certificateur->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('certificateur');

        $this->service->approuverPremierNiveau($v, $this->certificateur->id);
    }

    /** AC-D2 — deux approbateurs distincts obligatoires. */
    public function test_second_approbateur_doit_etre_distinct_du_premier(): void
    {
        $v = $this->soumettre();
        $v = $this->service->certifierConformite($v, $this->certificateur->id);
        $v = $this->service->approuverPremierNiveau($v, $this->approbateur1->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('distinct');

        $this->service->approuverSecondNiveau($v, $this->approbateur1->id);
    }

    public function test_approbations_distinctes_menent_au_statut_approuve(): void
    {
        $v = $this->validerJusquAuxApprobations();

        $this->assertSame(PlanningValidation::STATUT_APPROUVE, $v->statut);
        $this->assertTrue($v->estApprouvee());
        $this->assertNotSame($v->approbateur_1, $v->approbateur_2);
    }

    /** AC-D1 — publication impossible sans double validation. */
    public function test_publication_refusee_sans_double_validation(): void
    {
        $v = $this->soumettre();
        $v = $this->service->certifierConformite($v, $this->certificateur->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Action « publier » impossible');

        $this->service->publierExamenOfficiel($v, $this->publicateur->id);
    }

    public function test_preparateur_ne_peut_pas_publier(): void
    {
        $v = $this->validerJusquAuxApprobations();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('préparateur');

        $this->service->publierExamenOfficiel($v, $this->preparateur->id);
    }

    public function test_publication_conforme_est_auditee(): void
    {
        $v = $this->validerJusquAuxApprobations();

        $examen = $this->service->publierExamenOfficiel($v, $this->publicateur->id);

        $this->assertSame('published', $examen->statut);
        $this->assertSame(PlanningValidation::STATUT_PUBLIE, $v->fresh()->statut);
        $this->assertSame($this->publicateur->id, $v->fresh()->publie_par);
        $this->assertSame(1, $v->evenements()->where('action', PlanningEvenement::ACTION_PUBLIE)->count());
    }

    /** AC-D3 — rejet motivé, retour au préparateur, historique conservé. */
    public function test_rejet_sans_motif_refuse_et_rejet_motive_conserve_l_historique(): void
    {
        $v = $this->soumettre();

        try {
            $this->service->refuserPlanning($v, $this->approbateur1->id, '   ');
            $this->fail('Un rejet sans motif doit être refusé.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('motif', $e->getMessage());
        }

        $v = $this->service->refuserPlanning($v, $this->approbateur1->id, 'Salle indisponible le 15/01.');
        $this->assertSame(PlanningValidation::STATUT_REJETE, $v->statut);
        $this->assertSame('draft', $this->examen->fresh()->statut);
        $this->assertSame('Salle indisponible le 15/01.', $v->motif_rejet);

        // Nouvelle soumission : version 2, la version 1 rejetée reste lisible.
        $v2 = $this->soumettre();
        $this->assertSame(2, $v2->version);
        $this->assertSame(PlanningValidation::STATUT_REJETE, $v->fresh()->statut);
        $this->assertSame(2, PlanningValidation::query()->where('examen_id', $this->examen->id)->count());
    }

    /** AC-D4 — une escalade non résolue bloque la publication. */
    public function test_escalade_bloque_la_publication_jusqu_a_resolution(): void
    {
        $v = $this->validerJusquAuxApprobations();
        $v = $this->service->escaladerDesaccord($v, $this->approbateur1->id, 'Désaccord sur la salle.');

        $this->assertTrue($v->estBloqueeParEscalade());

        try {
            $this->service->publierExamenOfficiel($v, $this->publicateur->id);
            $this->fail('Une escalade non résolue doit bloquer la publication.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('escalade', $e->getMessage());
        }

        // Le déclarant ne peut pas résoudre sa propre escalade.
        try {
            $this->service->resoudreEscalade($v, $this->approbateur1->id, 'Auto-arbitrage.');
            $this->fail('Le déclarant ne doit pas pouvoir résoudre son escalade.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('propre escalade', $e->getMessage());
        }

        $v = $this->service->resoudreEscalade($v, $this->publicateur->id, 'Arbitrage : la salle est confirmée.');
        $this->assertTrue($v->escalade_resolue);
        $this->assertSame(PlanningValidation::STATUT_APPROUVE, $v->statut);

        $examen = $this->service->publierExamenOfficiel($v, $this->publicateur->id);
        $this->assertSame('published', $examen->statut);
    }

    /** §2.8 — toute modification post-certification bloque la publication. */
    public function test_modification_apres_certification_bloque_l_approbation_et_la_publication(): void
    {
        $v = $this->soumettre();
        $v = $this->service->certifierConformite($v, $this->certificateur->id);

        // Modification substantielle : déplacement de l'épreuve.
        Epreuve::where('examen_id', $this->examen->id)->first()->update(['date' => '2027-01-16']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('changé après certification');

        $this->service->approuverSecondNiveau(
            $this->service->approuverPremierNiveau($v, $this->approbateur1->id),
            $this->approbateur2->id
        );
    }

    /** AC-D5 — gel institutionnel : dérogation motivée par un acteur habilité uniquement. */
    public function test_gel_institutionnel_exige_une_derogation_motivee_et_habilitee(): void
    {
        $gel = now()->subDay()->toDateString();

        try {
            $this->service->soumettrePourValidation($this->examen, $this->preparateur->id, $gel);
            $this->fail('Une soumission après gel sans justification doit être refusée.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('gel', $e->getMessage());
        }

        try {
            $this->service->soumettrePourValidation($this->examen, $this->preparateurSansDroitDerogation->id, $gel, 'Urgence médicale.');
            $this->fail('Un acteur non habilité ne doit pas pouvoir déroger au gel.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('habilité', $e->getMessage());
        }

        // Contrôle positif de séparation : ce même acteur peut préparer,
        // il ne peut simplement pas porter la dérogation.
        $this->assertFalse($this->preparateurSansDroitDerogation->hasPermission('evaluations.publish'));
        $this->assertTrue($this->preparateurSansDroitDerogation->hasPermission('evaluations.update'));

        $v = $this->service->soumettrePourValidation(
            $this->examen,
            $this->publicateur->id,
            $gel,
            'Urgence médicale dûment constatée.'
        );

        $this->assertTrue($v->exceptionnel);
        $this->assertSame('Urgence médicale dûment constatée.', $v->motif_exceptionnel);
        $this->assertNotNull($v->date_gel_appliquee);
    }

    /** §3.3 — l'audit de gouvernance ne peut être réécrit ni supprimé. */
    public function test_audit_de_gouvernance_est_immuable(): void
    {
        $v = $this->soumettre();
        $evenement = $v->evenements()->firstOrFail();

        try {
            $evenement->update(['motif' => 'réécriture']);
            $this->fail('Un événement d’audit ne doit pas être modifiable.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('immuable', $e->getMessage());
        }

        try {
            $evenement->delete();
            $this->fail('Un événement d’audit ne doit pas être supprimable.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('immuable', $e->getMessage());
        }

        $this->assertSame(1, $v->evenements()->count());
    }

    /** AC-D6 — la route HTTP de transition ne permet plus de publier seul. */
    public function test_route_transiter_ne_permet_plus_de_publier_sans_gouvernance(): void
    {
        // Le préparateur porte aussi `evaluations.publish` (admin_scolarite) :
        // même avec toutes les permissions, la transition directe reste
        // bloquée et renvoie vers le circuit de gouvernance.
        $this->actingAs($this->preparateur)
            ->post(route('admin.evaluations.examens.transiter', [$this->examen, 'published']))
            ->assertRedirect();

        $this->assertSame('scheduled', $this->examen->fresh()->statut);
        $this->assertSame(0, PlanningValidation::query()->where('statut', PlanningValidation::STATUT_PUBLIE)->count());
    }
}
