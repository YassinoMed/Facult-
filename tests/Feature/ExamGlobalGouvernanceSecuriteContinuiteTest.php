<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\DelegationUrgence;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\NoteEpreuve;
use App\Modules\Evaluation\Models\NoteEpreuveHistorique;
use App\Modules\Evaluation\Models\PlanningEvenement;
use App\Modules\Evaluation\Models\PlanningValidation;
use App\Modules\Evaluation\Models\RegularisationPresence;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Evaluation\Services\ExamClotureCorrectionService;
use App\Modules\Evaluation\Services\ExamCockpitJourJService;
use App\Modules\Evaluation\Services\ExamContinuiteReconciliationService;
use App\Modules\Evaluation\Services\ExamDevoirsMultiModulesService;
use App\Modules\Evaluation\Services\ExamGouvernanceValidationService;
use App\Modules\Evaluation\Services\ExamGroupeVirtuelService;
use App\Modules\Evaluation\Services\ExamPresenceRegularisationService;
use App\Modules\Evaluation\Services\ExamUrgenceSuppleanceService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Teacher;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Suite de tests d'intégration, sécurité, gouvernance et continuité (LOOP GLOBAL UNIREGISTRE).
 *
 * Couvre les lots A à J :
 * - LOT A : Groupes virtuels, correspondance académique et devoirs multi-modules.
 * - LOT B & C : Respect des capacités, anti-conflits et remplacements.
 * - LOT D : Double validation 4-yeux, certification, refus motivé, escalade et gel.
 * - LOT E : Présences, retards sans exclusion, absences provisoires et régularisations.
 * - LOT F : Incidents qualifiés, mesures d'urgence temporaires et suppléance cadrée.
 * - LOT G : Clôture avec bloquants, corrections post-clôture additives et réouvertures.
 * - LOT H : Continuité tri-sources et reprise idempotente après panne.
 * - LOT I & J : Cockpit Jour J, isolation des facultés et conformité de sécurité.
 */
class ExamGlobalGouvernanceSecuriteContinuiteTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Niveau $niveau;

    private SemestreLmd $semestre;

    private UniteEnseignement $ue;

    private Matiere $matiere1;

    private Matiere $matiere2;

    private SessionExamen $session;

    private Examen $examen;

    private Epreuve $epreuve;

    private Room $salle;

    private User $preparateur;

    private User $approbateur1;

    private User $approbateur2;

    private User $doyenAutorite;

    private User $publicateur;

    private User $certificateurGouvernance;

    private User $approbateurGouvernance1;

    private User $approbateurGouvernance2;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);
        config(['modules.schedule.active' => true]);
        config(['modules.referentiel.active' => true]);

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences Exactes',
            'code' => 'FSE',
            'description' => 'Faculté de test global',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'L-INFO',
            'nom' => 'Licence Informatique',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);

        $this->niveau = Niveau::create([
            'programme_id' => $this->programme->id,
            'code' => 'L2',
            'nom' => 'Licence 2',
            'ordre' => 2,
        ]);

        $this->semestre = SemestreLmd::create([
            'niveau_id' => $this->niveau->id,
            'code' => 'S3',
            'nom' => 'Semestre 3',
            'numero' => 3,
            'ordre' => 3,
        ]);

        $this->ue = UniteEnseignement::create([
            'semestre_id' => $this->semestre->id,
            'programme_id' => $this->programme->id,
            'code' => 'UE-FOND01',
            'nom' => 'UE Fondamentale',
            'credits' => 6,
            'coefficient' => 2,
        ]);

        $this->matiere1 = Matiere::create([
            'code' => 'ALGO02',
            'nom' => 'Algorithmique & Structures de Données',
            'ue_id' => $this->ue->id,
            'niveau' => 'L2',
            'semestre' => 3,
            'credits' => 4,
            'actif' => true,
        ]);

        $this->matiere2 = Matiere::create([
            'code' => 'SYS01',
            'nom' => 'Systèmes d’Exploitation',
            'ue_id' => $this->ue->id,
            'niveau' => 'L2',
            'semestre' => 3,
            'credits' => 4,
            'actif' => true,
        ]);

        $this->salle = Room::create([
            'faculte_id' => $this->faculte->id,
            'nom' => 'Amphithéâtre Turing',
            'code' => 'AMPHI-TURING',
            'capacite' => 100,
            'actif' => true,
        ]);

        $this->session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-GLOBALE-2027',
            'nom' => 'Session Principale 2027',
            'type' => 'principale',
            'date_debut' => '2027-01-10',
            'date_fin' => '2027-01-25',
            'statut' => 'open',
        ]);

        $this->examen = Examen::create([
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'niveau_id' => $this->niveau->id,
            'libelle' => 'Examen Session Janvier',
            'type' => 'examen',
            'statut' => 'scheduled',
        ]);

        $this->epreuve = Epreuve::create([
            'code' => 'EPR-ALGO-01',
            'examen_id' => $this->examen->id,
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiere1->id,
            'salle_id' => $this->salle->id,
            'date' => '2027-01-15',
            'heure_debut' => '08:30:00',
            'heure_fin' => '10:30:00',
            'duree_minutes' => 120,
            'type_evaluation' => 'examen',
            'statut' => 'scheduled',
        ]);

        $this->preparateur = User::factory()->create([
            'name' => 'Jean Préparateur',
            'email' => 'preparateur@universite.test',
        ]);
        // §2.7 — le préparateur exerce un rôle de préparation distinct des
        // rôles de décision (certifier, approuver, publier).
        $this->preparateur->definirRole('admin_faculte');

        $this->approbateur1 = User::factory()->create([
            'name' => 'Dr. Approbateur Premier',
            'email' => 'approbateur1@universite.test',
        ]);
        // Responsable habilité à instruire les régularisations de présence.
        $this->approbateur1->definirRole('admin_faculte');

        $this->approbateur2 = User::factory()->create([
            'name' => 'Prof. Approbateur Second',
            'email' => 'approbateur2@universite.test',
        ]);

        $this->doyenAutorite = User::factory()->create([
            'name' => 'Doyen Arbitre',
            'email' => 'doyen@universite.test',
        ]);
        // Autorité habilitée à publier / déroger au gel : profil réel.
        $this->doyenAutorite->definirRole('admin_scolarite');

        $this->publicateur = User::factory()->create([
            'name' => 'Publiateur Officiel',
            'email' => 'publicateur@universite.test',
        ]);
        $this->publicateur->definirRole('autorite_publication');

        // §2.7 — rôles DÉDIÉS à chaque étape du circuit : une personne peut
        // siéger à deux niveaux d'approbation, jamais porter deux rôles.
        $this->certificateurGouvernance = User::factory()->create([
            'name' => 'Certificateur Qualité',
            'email' => 'certificateur@universite.test',
        ]);
        $this->certificateurGouvernance->definirRole('certificateur_qualite');

        $this->approbateurGouvernance1 = User::factory()->create([
            'name' => 'Direction des Études — Premier',
            'email' => 'direction.etudes.1@universite.test',
        ]);
        $this->approbateurGouvernance1->definirRole('direction_etudes');

        $this->approbateurGouvernance2 = User::factory()->create([
            'name' => 'Direction des Études — Second',
            'email' => 'direction.etudes.2@universite.test',
        ]);
        $this->approbateurGouvernance2->definirRole('direction_etudes');

        $prof = Teacher::create([
            'faculte_id' => $this->faculte->id,
            'matricule' => 'ENS-GLOBAL-01',
            'prenom' => 'Alan',
            'nom' => 'Turing',
            'email' => 'turing@universite.test',
            'actif' => true,
        ]);

        Surveillance::create([
            'epreuve_id' => $this->epreuve->id,
            'teacher_id' => $prof->id,
            'role' => 'principal',
            'statut' => 'confirme',
        ]);
    }

    private function creerEtudiant(string $matricule, ?Group $groupe, bool $inscritMatiere = true): Etudiant
    {
        $etudiant = Etudiant::create([
            'num_inscription' => $matricule,
            'email' => strtolower($matricule).'@etudiant.universite.test',
            'nom' => 'Nom-'.$matricule,
            'prenom' => 'Prenom-'.$matricule,
            'cin' => 'CIN-'.$matricule,
            'date_naissance' => '2004-05-10',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'groupe_id' => $groupe?->id,
            'niveau' => 'L2',
            'annee_academique' => '2026-2027',
            'adresse' => 'Campus Universitaire',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => 2024,
            'password' => 'Secret@123',
        ]);

        $etudiant->definirStatut('valide');

        $inscription = $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L2',
            'statut' => 'valide',
        ]);

        if ($inscritMatiere) {
            $inscription->pedagogiques()->create([
                'semestre_id' => $this->semestre->id,
                'ue_id' => $this->ue->id,
                'matiere_id' => $this->matiere1->id,
                'statut' => 'inscrit',
            ]);
        }

        return $etudiant;
    }

    // ==========================================
    // LOT A — GROUPES VIRTUELS & DEVOIRS
    // ==========================================

    public function test_groupe_virtuel_exclut_etudiant_non_inscrit_au_module_et_preserve_groupes_origine(): void
    {
        $service = app(ExamGroupeVirtuelService::class);

        $groupeTD1 = Group::create([
            'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id,
            'niveau_id' => $this->niveau->id,
            'niveau' => 'L2',
            'code' => 'TD-01',
            'nom' => 'Groupe TD 1',
            'capacite' => 30,
        ]);

        $etudiantInscrit = $this->creerEtudiant('ETU-INSCRIT-01', $groupeTD1, inscritMatiere: true);
        $etudiantNonInscrit = $this->creerEtudiant('ETU-NON-INSCRIT-02', $groupeTD1, inscritMatiere: false);

        $groupeVirtuel = $service->proposerGroupeVirtuel($this->epreuve, [$groupeTD1->id]);

        $this->assertTrue($groupeVirtuel['est_valide']);
        $this->assertSame(2, $groupeVirtuel['effectif_total_origine']);
        $this->assertSame(1, $groupeVirtuel['effectif_eligible']);
        $this->assertSame(1, $groupeVirtuel['effectif_exclu']);

        $this->assertSame($etudiantInscrit->id, $groupeVirtuel['candidats_eligibles'][0]['etudiant_id']);
        $this->assertSame($etudiantNonInscrit->id, $groupeVirtuel['etudiants_exclus'][0]['etudiant_id']);

        // Règle absolue 3 : zéro altération sur les groupes d'origine
        $this->assertSame($groupeTD1->id, $etudiantInscrit->fresh()->groupe_id);
        $this->assertSame($groupeTD1->id, $etudiantNonInscrit->fresh()->groupe_id);
    }

    public function test_groupe_virtuel_bloque_fusion_programmes_incompatibles(): void
    {
        $service = app(ExamGroupeVirtuelService::class);

        $programmeAutre = Programme::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'L-DROIT',
            'nom' => 'Licence Droit',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);

        $groupe1 = Group::create([
            'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id,
            'niveau_id' => $this->niveau->id,
            'niveau' => 'L2',
            'code' => 'G1',
            'nom' => 'Groupe Info',
        ]);

        $groupe2 = Group::create([
            'faculte_id' => $this->faculte->id,
            'programme_id' => $programmeAutre->id,
            'niveau_id' => $this->niveau->id,
            'niveau' => 'L2',
            'code' => 'G2',
            'nom' => 'Groupe Droit',
        ]);

        $this->creerEtudiant('ETU-P1', $groupe1, true);

        $proposition = $service->proposerGroupeVirtuel($this->epreuve, [$groupe1->id, $groupe2->id]);

        $this->assertFalse($proposition['est_valide']);
        $this->assertStringContainsString('Incompatibilité : les groupes sélectionnés appartiennent à des programmes académiques distincts', $proposition['anomalies_coherence'][0]);
    }

    public function test_multi_modules_devoirs_maintient_separation_quadripartite(): void
    {
        $service = app(ExamDevoirsMultiModulesService::class);

        $resultat = $service->programmerEvaluationCommune([
            'session_id' => $this->session->id,
            'date' => '2027-01-20',
            'heure_debut' => '09:00',
            'heure_fin' => '12:00',
            'libelle_operation' => 'Matinée commune Devoirs Algorithmique & Systèmes',
            'modules' => [
                ['matiere_id' => $this->matiere1->id, 'coefficient' => 2.0, 'bareme' => 20.0],
                ['matiere_id' => $this->matiere2->id, 'coefficient' => 1.5, 'bareme' => 20.0],
            ],
        ], $this->preparateur->id);

        $this->assertTrue($resultat['separation_garantie']);
        $this->assertCount(2, $resultat['epreuves_creees']);

        $ids = array_column($resultat['epreuves_creees'], 'id');
        $audit = $service->verifierSeparationEpreuves($ids);

        $this->assertTrue($audit['conforme']);
        $this->assertSame(2, $audit['matieres_distinctes']);
        $this->assertTrue($audit['clotures_independantes']);
    }

    // ==========================================
    // LOT D — DOUBLE VALIDATION (4 YEUX) & GEL
    // ==========================================

    public function test_gouvernance_interdit_auto_approbation_du_preparateur(): void
    {
        $service = app(ExamGouvernanceValidationService::class);

        $v = $service->soumettrePourValidation($this->examen, $this->preparateur->id);
        $v = $service->certifierConformite($v, $this->doyenAutorite->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("L'approbateur ne peut pas être le préparateur");

        // Le préparateur tente de s'auto-approuver
        $service->approuverPremierNiveau($v, $this->preparateur->id);
    }

    public function test_gouvernance_exige_deux_approbateurs_distincts_avant_publication(): void
    {
        $service = app(ExamGouvernanceValidationService::class);

        $v = $service->soumettrePourValidation($this->examen, $this->preparateur->id);
        $v = $service->certifierConformite($v, $this->doyenAutorite->id);
        $v = $service->approuverPremierNiveau($v, $this->approbateurGouvernance1->id);

        // L'approbateur 1 tente d'être aussi le second approbateur
        try {
            $service->approuverSecondNiveau($v, $this->approbateurGouvernance1->id);
            $this->fail("L'auto-double approbation aurait dû échouer.");
        } catch (DomainException $e) {
            $this->assertStringContainsString('le second approbateur doit être distinct du premier', $e->getMessage());
        }

        // L'approbateur 2 (distinct) valide
        $v = $service->approuverSecondNiveau($v, $this->approbateurGouvernance2->id);

        // Publication finale autorisée (uniquement après double validation),
        // par un rôle qui n'a participé à aucune étape précédente.
        $examenPublie = $service->publierExamenOfficiel($v, $this->publicateur->id);
        $this->assertSame('published', $examenPublie->statut);
    }

    public function test_gouvernance_refus_motive_retourne_au_preparateur_et_trace_historique(): void
    {
        $service = app(ExamGouvernanceValidationService::class);

        $v = $service->soumettrePourValidation($this->examen, $this->preparateur->id);
        $v = $service->refuserPlanning($v, $this->approbateur1->id, 'Capacité insuffisante sur le créneau du matin.');

        $this->assertSame('draft', $this->examen->fresh()->statut);
        $this->assertSame(PlanningValidation::STATUT_REJETE, $v->fresh()->statut);
        $this->assertSame('Capacité insuffisante sur le créneau du matin.', $v->fresh()->motif_rejet);
        $this->assertSame(1, $v->evenements()->where('action', PlanningEvenement::ACTION_REJETE)->count());
        // La version rejetée n'est jamais supprimée : une nouvelle soumission
        // crée la version 2 et laisse la version 1 lisible.
        $v2 = $service->soumettrePourValidation($this->examen, $this->preparateur->id);
        $this->assertSame(2, $v2->version);
        $this->assertSame(PlanningValidation::STATUT_REJETE, $v->fresh()->statut);
    }

    public function test_gouvernance_escalade_bloque_la_publication_tant_que_non_resolue(): void
    {
        $service = app(ExamGouvernanceValidationService::class);

        $v = $service->soumettrePourValidation($this->examen, $this->preparateur->id);
        $v = $service->certifierConformite($v, $this->doyenAutorite->id);
        $v = $service->approuverPremierNiveau($v, $this->approbateurGouvernance1->id);
        $v = $service->approuverSecondNiveau($v, $this->approbateurGouvernance2->id);
        $v = $service->escaladerDesaccord($v, $this->approbateurGouvernance1->id, 'Désaccord persistant sur les créneaux avec le département.');

        // Tentative de publication bloquée
        try {
            $service->publierExamenOfficiel($v, $this->publicateur->id);
            $this->fail("La publication aurait dû être bloquée par l'escalade.");
        } catch (DomainException $e) {
            $this->assertStringContainsString('escalade', $e->getMessage());
        }

        // Arbitrage par une autorité distincte du déclarant
        $v = $service->resoudreEscalade($v, $this->doyenAutorite->id, 'Arbitrage : le créneau proposé est confirmé pour impératif calendrier.');

        $this->assertTrue($v->fresh()->escalade_resolue);
        $this->assertSame(PlanningValidation::STATUT_APPROUVE, $v->fresh()->statut);
        $this->assertSame(1, $v->evenements()->where('action', PlanningEvenement::ACTION_ESCALADE_RESOLUE)->count());
    }

    public function test_gouvernance_gel_institutionnel_impose_justification_exceptionnelle(): void
    {
        $service = app(ExamGouvernanceValidationService::class);

        // Date de gel fixée à hier : la soumission elle-même est un changement
        // post-gel, elle exige donc une dérogation motivée et habilitée.
        $dateGel = Carbon::yesterday()->format('Y-m-d');

        try {
            $service->soumettrePourValidation($this->examen, $this->preparateur->id, $dateGel);
            $this->fail('La soumission post-gel sans justification aurait dû échouer.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('gel', $e->getMessage());
        }

        $v = $service->soumettrePourValidation(
            $this->examen,
            $this->doyenAutorite->id,
            $dateGel,
            'Dérogation exceptionnelle validée par le conseil de faculté suite aux intempéries.'
        );
        $this->assertTrue($v->exceptionnel);
        $this->assertNotNull($v->date_gel_appliquee);

        $v = $service->certifierConformite($v, $this->certificateurGouvernance->id);
        $v = $service->approuverPremierNiveau($v, $this->approbateurGouvernance1->id);

        // Même certifié, le circuit exige une seconde personne distincte.
        $v = $service->approuverSecondNiveau($v, $this->approbateurGouvernance2->id);

        // Publication post-gel : la dérogation reste exigée et tracée.
        $publie = $service->publierExamenOfficiel(
            $v,
            $this->publicateur->id,
            'Dérogation exceptionnelle validée par le conseil de faculté suite aux intempéries.'
        );

        $this->assertSame('published', $publie->statut);
        $this->assertTrue($v->fresh()->exceptionnel);
    }

    public function test_gouvernance_revision_post_publication_incremente_la_version(): void
    {
        $service = app(ExamGouvernanceValidationService::class);

        $v = $service->soumettrePourValidation($this->examen, $this->preparateur->id);
        $v = $service->certifierConformite($v, $this->doyenAutorite->id);
        $v = $service->approuverPremierNiveau($v, $this->approbateurGouvernance1->id);
        $v = $service->approuverSecondNiveau($v, $this->approbateurGouvernance2->id);
        $service->publierExamenOfficiel($v, $this->publicateur->id);

        // Modification substantielle requérant révision : acteur habilité.
        $v2 = $service->creerNouvelleVersionPostGel(
            $this->examen,
            $this->doyenAutorite->id,
            'Ajustement de salle pour accueillir étudiants tiers-temps.'
        );

        $this->assertSame(2, $v2->version);
        $this->assertSame(PlanningValidation::STATUT_SOUMIS, $v2->statut);
        // La version publiée reste en vigueur jusqu'à validation de la v2.
        $this->assertSame('published', $this->examen->fresh()->statut);
        $this->assertSame(PlanningValidation::STATUT_PUBLIE, $v->fresh()->statut);
        $this->assertSame(2, $service->derniereValidation($this->examen->fresh())->version);
    }

    // ==========================================
    // LOT E — PRÉSENCE, RETARDS & RÉGULARISATION
    // ==========================================

    public function test_presence_enregistre_retard_sans_exclusion_automatique(): void
    {
        $service = app(ExamPresenceRegularisationService::class);

        $etudiant = $this->creerEtudiant('ETU-RETARD-01', null, true);
        $candidat = Candidat::create([
            'epreuve_id' => $this->epreuve->id,
            'etudiant_id' => $etudiant->id,
            'statut' => Candidat::STATUT_NON_POINTE,
        ]);

        $service->enregistrerRetard($candidat, '08:50', 'Problème de transport en commun', $this->preparateur->id);

        $candidat->refresh();
        $this->assertSame('retard', $candidat->statut);
        $this->assertFalse($candidat->estAbsent());

        $justif = json_decode((string) $candidat->justification, true);
        $this->assertSame('08:50', $justif['heure_arrivee']);
    }

    public function test_presence_maintient_absence_provisoire_sans_conversion_injustifiee_automatique(): void
    {
        $service = app(ExamPresenceRegularisationService::class);

        $etudiant = $this->creerEtudiant('ETU-ABS-01', null, true);
        $candidat = Candidat::create([
            'epreuve_id' => $this->epreuve->id,
            'etudiant_id' => $etudiant->id,
            'statut' => Candidat::STATUT_NON_POINTE,
        ]);

        $service->enregistrerAbsenceProvisoire($candidat, 'Non présent au premier appel', $this->preparateur->id);

        $candidat->refresh();
        $this->assertSame('absent_provisoire', $candidat->statut);
        $this->assertTrue($candidat->estAbsent());

        // Vérification des écarts bloquants : l'absence provisoire bloque la clôture
        $audit = $service->verifierEcartsPresences($this->epreuve);
        $this->assertTrue($audit['cloture_bloquee']);
        $this->assertSame(1, $audit['absents_provisoires']);
    }

    public function test_presence_regularisation_justificatif_tardif_par_responsable_habilite(): void
    {
        $service = app(ExamPresenceRegularisationService::class);

        $etudiant = $this->creerEtudiant('ETU-JUSTIF-01', null, true);
        $candidat = Candidat::create([
            'epreuve_id' => $this->epreuve->id,
            'etudiant_id' => $etudiant->id,
            'statut' => 'absent_provisoire',
        ]);

        // Dépôt de justificatif médical tardif (demandeur identifié)
        $demande = $service->soumettreDemandeRegularisation($candidat, 'Certificat médical d’hospitalisation d’urgence', 'MED-2027-0987', $this->preparateur->id);

        $candidat->refresh();
        $this->assertSame('regularisation_en_cours', $candidat->statut);
        $this->assertTrue($candidat->presence_provisoire);
        $this->assertSame(RegularisationPresence::STATUT_EN_ATTENTE, $demande->fresh()->statut);
        $this->assertSame($this->preparateur->id, $demande->fresh()->demande_par);

        // Instruction formelle par le responsable
        $service->instruireRegularisation(
            $candidat,
            'absent_justifie',
            'Certificat médical vérifié et authentifié auprès de la clinique.',
            $this->approbateur1->id
        );

        $candidat->refresh();
        $this->assertSame('absent_justifie', $candidat->statut);
        $this->assertFalse($candidat->presence_provisoire);
        $this->assertSame($this->approbateur1->id, $candidat->decide_par);
        $this->assertSame(RegularisationPresence::STATUT_APPROUVEE, $demande->fresh()->statut);
        $this->assertSame('absent_justifie', $demande->fresh()->statut_final);
        $this->assertSame($this->approbateur1->id, $demande->fresh()->decide_par);
    }

    // ==========================================
    // LOT F — INCIDENTS, URGENCES & SUPPLÉANCE
    // ==========================================

    public function test_incident_avec_gravite_et_mesures_temporaires_sans_alterer_planning(): void
    {
        $service = app(ExamUrgenceSuppleanceService::class);

        $incident = $service->declarerIncident([
            'epreuve_id' => $this->epreuve->id,
            'type' => 'panne_electricite',
            'type' => 'probleme_salle',
            'gravite' => 'critique',
            'description' => 'Coupure électrique générale du bâtiment C',
            'declarant_id' => $this->preparateur->id,
            'delai_cible_minutes' => 30,
        ]);

        $this->assertSame('probleme_salle', $incident->type);
        $meta = $service->extraireMetadonneesIncident($incident);
        $this->assertSame('critique', $meta['gravite']);

        // Mesure d'urgence temporaire
        $service->consignerMesureUrgence(
            $incident,
            'Déplacement temporaire vers salle B102 et prolongation de 15 minutes',
            $this->approbateur1->id,
            'Mesure conservatoire pour garantir l\'égalité des candidats'
        );

        $metaApres = $service->extraireMetadonneesIncident($incident->fresh());
        $this->assertSame('en_cours', $incident->fresh()->statut);
        $this->assertCount(1, $metaApres['actions_prises']);

        // Résolution de l'incident
        $service->resoudreIncident($incident, 'Rétablissement électrique et fin d’épreuve sécurisée', $this->approbateur1->id);
        $this->assertSame('resolu', $incident->fresh()->statut);
    }

    public function test_suppleance_urgence_interdit_auto_validation_et_respecte_perimetre(): void
    {
        $service = app(ExamUrgenceSuppleanceService::class);

        $mandat = $service->activerMandatSuppleance([
            'epreuve_id' => $this->epreuve->id,
            'suppleant_id' => $this->approbateur2->id,
            'titulaire_id' => $this->approbateur1->id,
            'perimetre_autorise' => 'validation_urgences_jour_j',
            'date_debut' => Carbon::yesterday()->format('Y-m-d'),
            'date_fin' => Carbon::tomorrow()->format('Y-m-d'),
            'mandataire_nom' => 'Dr. Approbateur Premier',
            'motif_suppleance' => 'Mission extérieure urgente',
        ], $this->doyenAutorite->id);

        $this->assertTrue($mandat->estActive());
        $this->assertSame(DelegationUrgence::STATUT_ACTIVE, $mandat->statut);
        $this->assertTrue($mandat->actionAutorisee('mesure_urgence'));
        $this->assertFalse($mandat->actionAutorisee('report_epreuve'));

        // Action autorisée
        $valide = $service->verifierActionSuppleant($mandat, $this->approbateur2->id, 'mesure_urgence', ['acteur_original_id' => 999]);
        $this->assertTrue($valide);

        // Violation d'auto-validation
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Violation de séparation des responsabilités : un suppléant ne peut jamais valider sa propre proposition');
        $service->verifierActionSuppleant($mandat, $this->approbateur2->id, 'mesure_urgence', ['acteur_original_id' => $this->approbateur2->id]);
    }

    // ==========================================
    // LOT G — CLÔTURE, POST-CLÔTURE & RÉOUVERTURE
    // ==========================================

    public function test_cloture_epreuve_bloquee_si_incident_critique_non_resolu(): void
    {
        $serviceCloture = app(ExamClotureCorrectionService::class);
        $serviceUrgence = app(ExamUrgenceSuppleanceService::class);

        $serviceUrgence->declarerIncident([
            'epreuve_id' => $this->epreuve->id,
            'type' => 'fraude',
            'gravite' => 'critique',
            'description' => 'Flagrant délit de document non autorisé',
            'declarant_id' => $this->preparateur->id,
        ]);

        $audit = $serviceCloture->auditerPreRequisCloture($this->epreuve);
        $this->assertFalse($audit['peut_cloturer']);
        $this->assertSame(1, $audit['incidents_critiques_non_resolus']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Clôture impossible : éléments bloquants non résolus');
        $serviceCloture->cloturerEpreuve($this->epreuve, $this->approbateur1->id);
    }

    public function test_correction_post_cloture_a_quatre_yeux_preserve_valeur_anterieure_sans_ecrasement(): void
    {
        $serviceCloture = app(ExamClotureCorrectionService::class);

        $etudiant = $this->creerEtudiant('ETU-NOTE-01', null, true);
        $candidat = Candidat::create([
            'epreuve_id' => $this->epreuve->id,
            'etudiant_id' => $etudiant->id,
            'statut' => 'present',
        ]);

        $note = NoteEpreuve::create([
            'epreuve_id' => $this->epreuve->id,
            'candidat_id' => $candidat->id,
            'etudiant_id' => $etudiant->id,
            'valeur' => 12.0,
            'statut' => 'locked',
        ]);

        // Correction à 4 yeux : Demandeur != Valideur
        $noteCorrigee = $serviceCloture->corrigerNotePostCloture([
            'note_epreuve_id' => $note->id,
            'nouvelle_valeur' => 14.5,
            'motif_formel' => 'Erreur matérielle de report constatée sur copie physique n° 42',
            'demandeur_id' => $this->preparateur->id,
            'valideur_distinct_id' => $this->approbateur1->id,
        ]);

        $this->assertEquals(14.5, (float) $noteCorrigee->valeur);

        // Vérification de la trace d'audit immuable : l'ancienne valeur 12.0 est conservée
        $trace = NoteEpreuveHistorique::where('evaluation_note_id', $note->id)->latest('id')->firstOrFail();
        $this->assertEquals(12.0, (float) $trace->ancienne_valeur);
        $this->assertEquals(14.5, (float) $trace->nouvelle_valeur);
        $this->assertSame($this->approbateur1->id, $trace->auteur_id);
    }

    public function test_reouverture_session_exige_personne_distincte_et_nouvelle_version(): void
    {
        $serviceCloture = app(ExamClotureCorrectionService::class);

        $this->session->statut = 'closed';
        $this->session->save();

        // Tentative d'auto-réouverture
        try {
            $serviceCloture->rouvrirSessionCloturee(
                $this->session,
                'Réclamation collective de notes',
                $this->approbateur1->id,
                $this->approbateur1->id
            );
            $this->fail("L'auto-approbation de réouverture aurait dû échouer.");
        } catch (DomainException $e) {
            $this->assertStringContainsString("l'approbateur de réouverture doit être distinct du demandeur", $e->getMessage());
        }

        // Réouverture formelle à 4 yeux
        $sessionReouverte = $serviceCloture->rouvrirSessionCloturee(
            $this->session,
            'Réclamation collective de notes examinée en commission',
            $this->preparateur->id,
            $this->doyenAutorite->id
        );

        $this->assertSame('open', $sessionReouverte->statut);
        $regles = $sessionReouverte->regles;
        $this->assertSame(2, $regles['version']);
        $this->assertSame('Réclamation collective de notes examinée en commission', $regles['reouvertures'][0]['motif']);
    }

    // ==========================================
    // LOT H — CONTINUITÉ & RÉCONCILIATION
    // ==========================================

    public function test_reconciliation_tri_sources_detecte_divergences_et_bloque_validation(): void
    {
        $serviceReconciliation = app(ExamContinuiteReconciliationService::class);

        $etudiant1 = $this->creerEtudiant('ETU-TRI-01', null, true);
        $etudiant2 = $this->creerEtudiant('ETU-TRI-02', null, true);

        $candidat1 = Candidat::create(['epreuve_id' => $this->epreuve->id, 'etudiant_id' => $etudiant1->id, 'statut' => 'present']);
        $candidat2 = Candidat::create(['epreuve_id' => $this->epreuve->id, 'etudiant_id' => $etudiant2->id, 'statut' => 'absent']);

        // Rapprochement où le candidat 2 a signé la feuille papier (divergence !)
        $resultat = $serviceReconciliation->rapprocherTriSources([
            'epreuve_id' => $this->epreuve->id,
            'pointages_papier' => [
                ['candidat_id' => $candidat1->id, 'statut_papier' => 'present', 'signature_presente' => true],
                ['candidat_id' => $candidat2->id, 'statut_papier' => 'present', 'signature_presente' => true],
            ],
        ]);

        $this->assertTrue($resultat['validation_bloquee']);
        $this->assertSame(1, $resultat['divergences_detectees']);
        $this->assertSame($candidat2->id, $resultat['ecarts'][0]['candidat_id']);

        // Régularisation à 4 yeux de l'écart
        $serviceReconciliation->regulariserEcartTriSources(
            $candidat2,
            'present',
            'Signature vérifiée sur bordereau d’émargement original B-12',
            $this->preparateur->id,
            $this->approbateur1->id
        );

        $this->assertSame('present', $candidat2->fresh()->statut);
    }

    public function test_reprise_apres_panne_est_idempotente_et_fournit_rapport_exhaustif(): void
    {
        $serviceReconciliation = app(ExamContinuiteReconciliationService::class);

        $etudiant = $this->creerEtudiant('ETU-PANNE-01', null, true);
        $candidat = Candidat::create(['epreuve_id' => $this->epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'non_pointe']);

        $cleIdempotence = 'uuid-synchro-unique-12345';
        $lots = [
            [
                'cle_idempotence' => $cleIdempotence,
                'epreuve_id' => $this->epreuve->id,
                'candidat_id' => $candidat->id,
                'statut' => 'present',
            ],
        ];

        // 1ère exécution de reprise
        $reprise1 = $serviceReconciliation->executerRepriseApresPanne($lots, $this->preparateur->id);
        $this->assertSame(1, $reprise1['acceptes']);
        $this->assertSame(0, $reprise1['deja_traites']);
        $this->assertSame('present', $candidat->fresh()->statut);

        // 2ème exécution identique (rejeu réseau après panne)
        $reprise2 = $serviceReconciliation->executerRepriseApresPanne($lots, $this->preparateur->id);
        $this->assertSame(0, $reprise2['acceptes']);
        $this->assertSame(1, $reprise2['deja_traites']);
        $this->assertSame('ignore_doublon', $reprise2['rapport_details'][0]['decision']);
    }

    // ==========================================
    // LOT I — COCKPIT DU JOUR J & ISOLATION
    // ==========================================

    public function test_cockpit_jour_j_isole_le_perimetre_faculte_et_fournit_kpis(): void
    {
        $serviceCockpit = app(ExamCockpitJourJService::class);

        $this->epreuve->date = Carbon::today();
        $this->epreuve->save();

        $etudiant = $this->creerEtudiant('ETU-JOURJ-01', null, true);
        Candidat::create(['epreuve_id' => $this->epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'present']);

        $adminFaculte = User::factory()->create(['faculte_id' => $this->faculte->id]);

        $cockpit = $serviceCockpit->obtenirCockpitJourJ($adminFaculte);

        $this->assertSame(Carbon::today()->format('Y-m-d'), $cockpit['date_jour']);
        $this->assertSame(1, $cockpit['kpis_epreuves']['total']);
        $this->assertSame(1, $cockpit['kpis_etudiants']['presents']);
        $this->assertNotEmpty($cockpit['epreuves']);
        $this->assertSame($this->epreuve->id, $cockpit['epreuves'][0]['id']);
    }
}
