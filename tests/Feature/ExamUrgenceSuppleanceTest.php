<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\DelegationUrgence;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\ExamUrgenceSuppleanceService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOT F — Suppléance d'urgence bornée, révocable et tracée (§2.11, §2.12).
 *
 * Prouve par exécution : le mandat est persisté, la liste d'actions est
 * fermée, la fenêtre est bornée, la révocation est réelle, et ni
 * l'auto-désignation ni l'auto-validation ne sont possibles.
 */
class ExamUrgenceSuppleanceTest extends TestCase
{
    use RefreshDatabase;

    private ExamUrgenceSuppleanceService $service;

    private Epreuve $epreuve;

    private User $titulaire;

    private User $suppleant;

    private User $autorite;

    private User $sansHabilitation;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true, 'modules.schedule.active' => true]);

        $faculte = Faculte::create(['nom' => 'Faculté Urgence', 'code' => 'FURG', 'active' => true]);

        $this->titulaire = $this->creerUtilisateur('titulaire.urg@univ.test', 'admin_faculte', $faculte->id);
        $this->suppleant = $this->creerUtilisateur('suppleant.urg@univ.test', 'admin_faculte', $faculte->id);
        $this->autorite = $this->creerUtilisateur('autorite.urg@univ.test', 'admin_scolarite', $faculte->id);
        $this->sansHabilitation = $this->creerUtilisateur('sanshab.urg@univ.test', 'agent_scolarite', $faculte->id);

        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $programme = Programme::create([
            'faculte_id' => $faculte->id, 'code' => 'P-URG', 'nom' => 'Programme Urgence',
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
            'code' => 'UE-URG', 'nom' => 'UE Urgence', 'credits' => 6, 'coefficient' => 2,
        ]);
        $matiere = Matiere::create([
            'code' => 'MAT-URG', 'nom' => 'Module Urgence', 'ue_id' => $ue->id,
            'niveau' => 'L3', 'semestre' => 5, 'credits' => 4, 'actif' => true,
        ]);

        $session = SessionExamen::create([
            'annee_universitaire_id' => $annee->id, 'code' => 'S-URG',
            'nom' => 'Session Urgence', 'type' => 'principale',
            'date_debut' => '2027-01-10', 'date_fin' => '2027-01-25', 'statut' => 'open',
        ]);

        $examen = Examen::create([
            'code' => 'EX-URG', 'session_id' => $session->id,
            'annee_universitaire_id' => $annee->id,
            'programme_id' => $programme->id, 'niveau_id' => $niveau->id,
            'semestre_lmd_id' => $semestre->id,
            'libelle' => 'Examen Urgence', 'type' => 'examen', 'statut' => 'scheduled',
        ]);

        $this->epreuve = Epreuve::create([
            'code' => 'EPR-URG', 'examen_id' => $examen->id, 'session_id' => $session->id,
            'annee_universitaire_id' => $annee->id, 'matiere_id' => $matiere->id,
            'date' => '2027-01-15', 'heure_debut' => '09:00', 'heure_fin' => '11:00',
            'duree_minutes' => 120, 'statut' => 'scheduled',
        ]);

        $this->service = app(ExamUrgenceSuppleanceService::class);
    }

    private function creerUtilisateur(string $email, string $role, ?int $faculteId = null): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Utilisateur '.$email,
            'email' => $email,
            'password' => 'Motdepasse@123',
            'faculte_id' => $faculteId,
        ], $role);
    }

    /**
     * @param  array<string, mixed>  $surcharges
     * @return array<string, mixed>
     */
    private function donneesMandat(array $surcharges = []): array
    {
        return array_merge([
            'epreuve_id' => $this->epreuve->id,
            'suppleant_id' => $this->suppleant->id,
            'titulaire_id' => $this->titulaire->id,
            'perimetre_autorise' => 'validation_urgences_jour_j',
            'date_debut' => now()->subDay()->toDateString(),
            'date_fin' => now()->addDay()->toDateString(),
            'mandataire_nom' => 'Suppléant de test',
            'motif_suppleance' => 'Mission extérieure urgente du titulaire',
        ], $surcharges);
    }

    public function test_mandat_persiste_borne_et_trace(): void
    {
        $mandat = $this->service->activerMandatSuppleance($this->donneesMandat(), $this->autorite->id);

        $this->assertDatabaseHas('evaluation_delegations_urgence', [
            'id' => $mandat->id,
            'delegue_id' => $this->suppleant->id,
            'delegant_id' => $this->titulaire->id,
            'statut' => DelegationUrgence::STATUT_ACTIVE,
            'created_by' => $this->autorite->id,
        ]);

        $this->assertSame(['mesure_urgence', 'resolution_incident'], $mandat->actions);
        $this->assertTrue($mandat->estActive());
        $this->assertTrue($mandat->actionAutorisee('mesure_urgence'));
        $this->assertFalse($mandat->actionAutorisee('report_epreuve'));
        $this->assertNotNull($mandat->uuid);
        $this->assertSame($this->epreuve->session_id, $mandat->session_id);
        $this->assertNotNull($mandat->faculte_id);
    }

    public function test_action_hors_perimetre_refusee(): void
    {
        $mandat = $this->service->activerMandatSuppleance($this->donneesMandat(), $this->autorite->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('hors du périmètre du mandat');

        $this->service->verifierActionSuppleant($mandat, $this->suppleant->id, 'report_epreuve', []);
    }

    public function test_action_inconnue_refusee(): void
    {
        $mandat = $this->service->activerMandatSuppleance($this->donneesMandat(), $this->autorite->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("Action d'urgence inconnue");

        $this->service->verifierActionSuppleant($mandat, $this->suppleant->id, 'suppression_notes', []);
    }

    public function test_utilisateur_non_designe_refuse(): void
    {
        $mandat = $this->service->activerMandatSuppleance($this->donneesMandat(), $this->autorite->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("n'est pas le suppléant désigné");

        $this->service->verifierActionSuppleant($mandat, $this->titulaire->id, 'mesure_urgence', []);
    }

    public function test_mandat_expire_refuse(): void
    {
        $mandat = $this->service->activerMandatSuppleance($this->donneesMandat(), $this->autorite->id);

        $this->travel(3)->days();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('expiré ou non encore en vigueur');

        $this->service->verifierActionSuppleant($mandat, $this->suppleant->id, 'mesure_urgence', []);
    }

    public function test_revocation_persistee_et_effective(): void
    {
        $mandat = $this->service->activerMandatSuppleance($this->donneesMandat(), $this->autorite->id);

        $revoque = $this->service->revoquerMandat($mandat, $this->titulaire->id, 'Retour anticipé du titulaire');

        $this->assertSame(DelegationUrgence::STATUT_REVOQUEE, $revoque->statut);
        $this->assertNotNull($revoque->revoquee_at);
        $this->assertSame($this->titulaire->id, $revoque->revoquee_par);
        $this->assertFalse($revoque->estActive());

        try {
            $this->service->verifierActionSuppleant($mandat, $this->suppleant->id, 'mesure_urgence', []);
            $this->fail('Un mandat révoqué ne doit plus autoriser la moindre action.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('révoqué', $e->getMessage());
        }
    }

    public function test_suppleant_ne_peut_pas_revoquer_son_mandat(): void
    {
        $mandat = $this->service->activerMandatSuppleance($this->donneesMandat(), $this->autorite->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('le suppléant ne peut pas révoquer son propre mandat');

        $this->service->revoquerMandat($mandat, $this->suppleant->id, 'Auto-révocation.');
    }

    public function test_revocation_sans_motif_refusee(): void
    {
        $mandat = $this->service->activerMandatSuppleance($this->donneesMandat(), $this->autorite->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('exige un motif explicite');

        $this->service->revoquerMandat($mandat, $this->titulaire->id, '   ');
    }

    public function test_auto_validation_du_suppleant_refusee(): void
    {
        $mandat = $this->service->activerMandatSuppleance($this->donneesMandat(), $this->autorite->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('un suppléant ne peut jamais valider sa propre proposition');

        $this->service->verifierActionSuppleant(
            $mandat,
            $this->suppleant->id,
            'mesure_urgence',
            ['acteur_original_id' => $this->suppleant->id]
        );
    }

    public function test_suppleant_identique_au_titulaire_refuse(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('personne distincte du titulaire');

        $this->service->activerMandatSuppleance(
            $this->donneesMandat(['suppleant_id' => $this->titulaire->id]),
            $this->autorite->id
        );
    }

    public function test_auto_designation_de_l_autorite_refusee(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ne peut pas se désigner elle-même');

        $this->service->activerMandatSuppleance(
            $this->donneesMandat(['suppleant_id' => $this->autorite->id]),
            $this->autorite->id
        );
    }

    public function test_autorite_non_habilitee_refusee(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('autorité habilitée à publier');

        $this->service->activerMandatSuppleance($this->donneesMandat(), $this->sansHabilitation->id);
    }

    public function test_duree_superieure_a_sept_jours_refusee(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('bornée à 7 jours maximum');

        $this->service->activerMandatSuppleance(
            $this->donneesMandat([
                'date_debut' => now()->toDateString(),
                'date_fin' => now()->addDays(8)->toDateString(),
            ]),
            $this->autorite->id
        );
    }

    public function test_fenetre_inversee_refusee(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('la fin doit être postérieure au début');

        $this->service->activerMandatSuppleance(
            $this->donneesMandat([
                'date_debut' => now()->addDays(2)->toDateString(),
                'date_fin' => now()->toDateString(),
            ]),
            $this->autorite->id
        );
    }

    public function test_action_non_delegable_refusee(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('non délégable');

        $this->service->activerMandatSuppleance(
            $this->donneesMandat(['actions' => ['suppression_notes']]),
            $this->autorite->id
        );
    }

    public function test_mandat_revoque_est_immuable(): void
    {
        $mandat = $this->service->activerMandatSuppleance($this->donneesMandat(), $this->autorite->id);
        $this->service->revoquerMandat($mandat, $this->titulaire->id, 'Retour du titulaire');
        $mandat->refresh();

        try {
            $mandat->update(['motif' => 'Réécriture après révocation.']);
            $this->fail('Un mandat révoqué ne doit pas être modifiable.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('immuable', $e->getMessage());
        }

        try {
            $mandat->delete();
            $this->fail('Un mandat de suppléance ne doit pas être supprimable.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('immuable', $e->getMessage());
        }

        $this->assertSame(DelegationUrgence::STATUT_REVOQUEE, $mandat->fresh()->statut);
    }
}
