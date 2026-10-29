<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\RegularisationPresence;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\ExamPresenceRegularisationService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOT E — Retards, absences provisoires et régularisations (§2.9).
 *
 * Prouve par exécution : l'heure d'arrivée est conservée, l'absence reste
 * provisoire, la demande est persistée, le décideur est habilité et distinct
 * du demandeur et du pointeur, et une décision rendue est immuable.
 */
class ExamPresenceRegularisationTest extends TestCase
{
    use RefreshDatabase;

    private ExamPresenceRegularisationService $service;

    private Faculte $faculte;

    private Epreuve $epreuve;

    private User $pointeur;

    private User $demandeur;

    private User $decideur;

    private User $sansHabilitation;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true, 'modules.schedule.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'Faculté Présence', 'code' => 'FPRES', 'active' => true]);

        $this->pointeur = $this->creerUtilisateur('pointeur.pres@univ.test', 'admin_faculte', $this->faculte->id);
        $this->demandeur = $this->creerUtilisateur('demandeur.pres@univ.test', 'agent_scolarite');
        $this->decideur = $this->creerUtilisateur('decideur.pres@univ.test', 'admin_faculte', $this->faculte->id);
        $this->sansHabilitation = $this->creerUtilisateur('sanshab.pres@univ.test', 'agent_scolarite');

        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'P-PRES', 'nom' => 'Programme Présence',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niveau = Niveau::create([
            'programme_id' => $programme->id, 'code' => 'L2', 'nom' => 'Licence 2', 'ordre' => 2,
        ]);
        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id, 'code' => 'S3', 'nom' => 'S3', 'numero' => 3, 'ordre' => 3,
        ]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $programme->id,
            'code' => 'UE-PRES', 'nom' => 'UE Présence', 'credits' => 6, 'coefficient' => 2,
        ]);
        $matiere = Matiere::create([
            'code' => 'MAT-PRES', 'nom' => 'Module Présence', 'ue_id' => $ue->id,
            'niveau' => 'L2', 'semestre' => 3, 'credits' => 4, 'actif' => true,
        ]);

        $session = SessionExamen::create([
            'annee_universitaire_id' => $annee->id, 'code' => 'S-PRES',
            'nom' => 'Session Présence', 'type' => 'principale',
            'date_debut' => '2027-01-10', 'date_fin' => '2027-01-25', 'statut' => 'open',
        ]);

        $examen = Examen::create([
            'code' => 'EX-PRES', 'session_id' => $session->id,
            'annee_universitaire_id' => $annee->id,
            'programme_id' => $programme->id, 'niveau_id' => $niveau->id,
            'semestre_lmd_id' => $semestre->id,
            'libelle' => 'Examen Présence', 'type' => 'examen', 'statut' => 'scheduled',
        ]);

        $this->epreuve = Epreuve::create([
            'code' => 'EPR-PRES', 'examen_id' => $examen->id, 'session_id' => $session->id,
            'annee_universitaire_id' => $annee->id, 'matiere_id' => $matiere->id,
            'date' => '2027-01-15', 'heure_debut' => '09:00', 'heure_fin' => '11:00',
            'duree_minutes' => 120, 'statut' => 'scheduled',
        ]);

        $this->service = app(ExamPresenceRegularisationService::class);
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

    private function creerCandidat(string $matricule, string $statut = Candidat::STATUT_NON_POINTE): Candidat
    {
        $etudiant = Etudiant::create([
            'num_inscription' => $matricule,
            'email' => strtolower($matricule).'@etudiant.univ.test',
            'nom' => 'Nom-'.$matricule,
            'prenom' => 'Prenom-'.$matricule,
            'cin' => 'CIN-'.$matricule,
            'date_naissance' => '2004-05-10',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L2',
            'annee_academique' => '2026-2027',
            'adresse' => 'Campus Universitaire',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => 2024,
            'password' => 'Secret@123',
        ]);

        return Candidat::create([
            'epreuve_id' => $this->epreuve->id,
            'etudiant_id' => $etudiant->id,
            'statut' => $statut,
        ]);
    }

    private function absenceProvisoirePointee(string $matricule): Candidat
    {
        $candidat = $this->creerCandidat($matricule);
        $this->service->enregistrerAbsenceProvisoire($candidat, 'Absente au premier appel', $this->pointeur->id);

        return $candidat->fresh();
    }

    public function test_retard_horodate_sans_exclusion_automatique(): void
    {
        $candidat = $this->creerCandidat('ETU-PRES-RETARD');

        $this->service->enregistrerRetard($candidat, '9:05', 'Transport en commun', $this->pointeur->id);
        $candidat->refresh();

        $this->assertSame('retard', $candidat->statut);
        $this->assertFalse($candidat->estAbsent());
        $this->assertFalse($candidat->presence_provisoire);
        $this->assertSame('09:05:00', $candidat->heure_arrivee);
        $this->assertSame($this->pointeur->id, $candidat->decide_par);

        // Aucune exclusion : le candidat reste compté comme présent.
        $audit = $this->service->verifierEcartsPresences($this->epreuve);
        $this->assertSame(1, $audit['retards']);
        $this->assertSame(1, $audit['presents'] + $audit['retards']);
    }

    public function test_heure_arrivee_invalide_refusee(): void
    {
        $candidat = $this->creerCandidat('ETU-PRES-HEURE');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("Heure d'arrivée invalide");

        $this->service->enregistrerRetard($candidat, '25:99', null, $this->pointeur->id);
    }

    public function test_absence_provisoire_persistee_et_non_convertie_automatiquement(): void
    {
        $candidat = $this->absenceProvisoirePointee('ETU-PRES-ABS');

        $this->assertSame('absent_provisoire', $candidat->statut);
        $this->assertTrue($candidat->presence_provisoire);
        $this->assertNull($candidat->heure_arrivee);

        $audit = $this->service->verifierEcartsPresences($this->epreuve);
        $this->assertTrue($audit['cloture_bloquee']);
        $this->assertSame(1, $audit['absents_provisoires']);
        $this->assertSame(0, $audit['absents_injustifies']);

        // Le simple passage du temps ne convertit jamais en injustice.
        $this->travel(3)->days();
        $this->assertSame('absent_provisoire', $candidat->fresh()->statut);
    }

    public function test_demande_regularisation_persistee_et_bloque_la_cloture(): void
    {
        $candidat = $this->absenceProvisoirePointee('ETU-PRES-DEM');

        $demande = $this->service->soumettreDemandeRegularisation(
            $candidat,
            'Certificat médical tardif',
            'MED-2027-0042',
            $this->demandeur->id
        );

        $this->assertSame(RegularisationPresence::STATUT_EN_ATTENTE, $demande->statut);
        $this->assertSame('absent_provisoire', $demande->statut_initial);
        $this->assertSame($this->demandeur->id, $demande->demande_par);
        $this->assertSame('MED-2027-0042', $demande->preuve_reference);
        $this->assertTrue($candidat->fresh()->presence_provisoire);
        $this->assertSame('regularisation_en_cours', $candidat->fresh()->statut);

        $audit = $this->service->verifierEcartsPresences($this->epreuve);
        $this->assertTrue($audit['cloture_bloquee']);
        $this->assertSame(1, $audit['regularisations_en_attente']);
        $this->assertSame(1, $audit['regularisations_en_cours']);
    }

    public function test_demande_sans_demandeur_identifie_refusee(): void
    {
        $candidat = $this->absenceProvisoirePointee('ETU-PRES-NOAUT');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('demandeur identifié');

        $this->service->soumettreDemandeRegularisation($candidat, 'Justificatif', null, null);
    }

    public function test_demande_en_double_refusee(): void
    {
        $candidat = $this->absenceProvisoirePointee('ETU-PRES-DBL');
        $this->service->soumettreDemandeRegularisation($candidat, 'Justificatif', null, $this->demandeur->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('déjà en attente');

        $this->service->soumettreDemandeRegularisation($candidat->fresh(), 'Second justificatif', null, $this->demandeur->id);
    }

    public function test_demandeur_ne_peut_pas_instruire_sa_propre_demande(): void
    {
        $candidat = $this->absenceProvisoirePointee('ETU-PRES-AUTO');
        $this->service->soumettreDemandeRegularisation($candidat, 'Justificatif', null, $this->decideur->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('le demandeur ne peut pas instruire sa propre demande');

        $this->service->instruireRegularisation($candidat->fresh(), 'present', 'Auto-validation.', $this->decideur->id);
    }

    public function test_pointeur_initial_ne_peut_pas_decider(): void
    {
        $candidat = $this->absenceProvisoirePointee('ETU-PRES-POINTEUR');
        $this->service->soumettreDemandeRegularisation($candidat, 'Justificatif', null, $this->demandeur->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('auteur du pointage ne peut pas décider');

        $this->service->instruireRegularisation($candidat->fresh(), 'present', 'Décision du pointeur.', $this->pointeur->id);
    }

    public function test_acteur_non_habilite_ne_peut_pas_instruire(): void
    {
        $candidat = $this->absenceProvisoirePointee('ETU-PRES-SANSHAB');
        $this->service->soumettreDemandeRegularisation($candidat, 'Justificatif', null, $this->demandeur->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('doit être habilité');

        $this->service->instruireRegularisation($candidat->fresh(), 'present', 'Décision non habilitée.', $this->sansHabilitation->id);
    }

    public function test_decision_sans_motif_refusee(): void
    {
        $candidat = $this->absenceProvisoirePointee('ETU-PRES-MOTIF');
        $this->service->soumettreDemandeRegularisation($candidat, 'Justificatif', null, $this->demandeur->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('motivation formelle');

        $this->service->instruireRegularisation($candidat->fresh(), 'present', '   ', $this->decideur->id);
    }

    public function test_decision_absent_injustifie_rejetee_et_tracee(): void
    {
        $candidat = $this->absenceProvisoirePointee('ETU-PRES-REJ');
        $demande = $this->service->soumettreDemandeRegularisation($candidat, 'Justificatif non probant', null, $this->demandeur->id);

        $this->service->instruireRegularisation(
            $candidat->fresh(),
            'absent_injustifie',
            'Justificatif non authentifiable : absence confirmée injustifiée.',
            $this->decideur->id
        );

        $candidat->refresh();
        $demande->refresh();

        $this->assertSame('absent_injustifie', $candidat->statut);
        $this->assertFalse($candidat->presence_provisoire);
        $this->assertSame($this->decideur->id, $candidat->decide_par);
        $this->assertSame(RegularisationPresence::STATUT_REJETEE, $demande->statut);
        $this->assertSame('absent_injustifie', $demande->statut_final);
        $this->assertSame($this->decideur->id, $demande->decide_par);
        $this->assertNotNull($demande->decide_at);
        $this->assertSame('Justificatif non authentifiable : absence confirmée injustifiée.', $demande->motif_decision);

        $audit = $this->service->verifierEcartsPresences($this->epreuve);
        $this->assertFalse($audit['cloture_bloquee']);
        $this->assertSame(1, $audit['absents_injustifies']);
        $this->assertSame(0, $audit['absents_provisoires']);
    }

    public function test_decision_rendue_est_immuable(): void
    {
        $candidat = $this->absenceProvisoirePointee('ETU-PRES-IMMU');
        $demande = $this->service->soumettreDemandeRegularisation($candidat, 'Justificatif', null, $this->demandeur->id);
        $this->service->instruireRegularisation($candidat->fresh(), 'present', 'Présence confirmée sur émargement.', $this->decideur->id);
        $demande->refresh();

        try {
            $demande->update(['motif_decision' => 'Réécriture après coup.']);
            $this->fail('Une décision rendue ne doit pas être modifiable.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('immuable', $e->getMessage());
        }

        try {
            $demande->delete();
            $this->fail('Une régularisation ne doit pas être supprimable.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('immuable', $e->getMessage());
        }

        $this->assertSame(RegularisationPresence::STATUT_APPROUVEE, $demande->fresh()->statut);
    }
}
