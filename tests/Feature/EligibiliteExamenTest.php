<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\SituationEtudiant;
use App\Modules\Evaluation\DTO\DecisionEligibiliteExamen;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\ServiceEligibiliteExamen;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lot 1 — service central d’éligibilité aux examens.
 *
 * Objectif : prouver qu’un groupe pédagogique ne suffit jamais à déclarer
 * un étudiant éligible, et que chaque décision est explicable.
 */
class EligibiliteExamenTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private SemestreLmd $semestre;

    private UniteEnseignement $ue;

    private Matiere $matiere;

    private SessionExamen $session;

    private Examen $examen;

    private Epreuve $epreuve;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculte = Faculte::create([
            'nom' => 'Faculté Éligibilité',
            'code' => 'FELIG',
            'description' => 'Tests éligibilité examens',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $prog = Programme::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'PROG-ELIG',
            'nom' => 'Programme Éligibilité',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);

        $niv = Niveau::create([
            'programme_id' => $prog->id,
            'code' => 'L1',
            'nom' => 'Licence 1',
            'ordre' => 1,
        ]);

        $this->semestre = SemestreLmd::create([
            'niveau_id' => $niv->id,
            'code' => 'S1',
            'nom' => 'Semestre 1',
            'numero' => 1,
            'ordre' => 1,
        ]);

        $this->ue = UniteEnseignement::create([
            'semestre_id' => $this->semestre->id,
            'programme_id' => $prog->id,
            'code' => 'UE-ELIG01',
            'nom' => 'UE Éligibilité',
            'credits' => 6,
            'coefficient' => 2,
        ]);

        $this->matiere = Matiere::create([
            'code' => 'ELIG01',
            'nom' => 'Module éligibilité',
            'ue_id' => $this->ue->id,
            'niveau' => 'L1',
            'semestre' => 1,
            'credits' => 4,
            'actif' => true,
        ]);

        $this->session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'ELIG-PRINCIPALE',
            'nom' => 'Session principale éligibilité',
            'type' => 'principale',
            'date_debut' => '2027-01-10',
            'date_fin' => '2027-01-20',
            'statut' => 'open',
        ]);

        $this->examen = Examen::create([
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examen éligibilité',
            'type' => 'examen',
            'statut' => 'draft',
        ]);

        $this->epreuve = Epreuve::create([
            'examen_id' => $this->examen->id,
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiere->id,
            'date' => '2027-01-12',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
            'statut' => 'draft',
        ]);
    }

    public function test_etudiant_avec_inscriptions_actives_est_eligible(): void
    {
        $etudiant = $this->creerEtudiant('ELIG00001', avecInscriptionPedagogique: true);

        $decision = app(ServiceEligibiliteExamen::class)->evaluer($this->epreuve, $etudiant);

        $this->assertTrue($decision->estEligible());
        $this->assertSame(DecisionEligibiliteExamen::ELIGIBLE, $decision->decision);
        $this->assertSame(DecisionEligibiliteExamen::SOURCE_INSCRIPTION_PEDAGOGIQUE, $decision->source);
        $this->assertSame(ServiceEligibiliteExamen::REGLE_INSCRIPTION_MODULE_ACTIVE, $decision->regle);
        $this->assertTrue($decision->donneesCompletes);
        $this->assertNotNull($decision->inscriptionAcademiqueId);
        $this->assertNotNull($decision->inscriptionPedagogiqueId);
        $this->assertSame($this->matiere->id, $decision->matiereId);
    }

    public function test_appartenance_au_groupe_ne_suffit_pas_sans_inscription_pedagogique(): void
    {
        $groupe = Group::create([
            'code' => 'ELIG-G1',
            'nom' => 'Groupe éligibilité',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'capacite' => 30,
            'actif' => true,
        ]);

        $etudiant = $this->creerEtudiant('ELIG00002', groupe: $groupe, avecInscriptionPedagogique: false);

        $decision = app(ServiceEligibiliteExamen::class)->evaluer($this->epreuve, $etudiant);

        $this->assertTrue($decision->estNonEligible());
        $this->assertSame(DecisionEligibiliteExamen::NON_ELIGIBLE, $decision->decision);
        $this->assertSame(DecisionEligibiliteExamen::SOURCE_INSCRIPTION_PEDAGOGIQUE, $decision->source);
        $this->assertSame(ServiceEligibiliteExamen::REGLE_INSCRIPTION_MODULE_ACTIVE, $decision->regle);
        $this->assertStringContainsString('pédagogique', (string) $decision->motif);
    }

    public function test_inscription_academique_inactive_est_non_eligible(): void
    {
        $etudiant = $this->creerEtudiant('ELIG00003', statutAcademique: 'abandon');

        $decision = app(ServiceEligibiliteExamen::class)->evaluer($this->epreuve, $etudiant);

        $this->assertTrue($decision->estNonEligible());
        $this->assertSame(DecisionEligibiliteExamen::SOURCE_INSCRIPTION_ACADEMIQUE, $decision->source);
        $this->assertSame(ServiceEligibiliteExamen::REGLE_INSCRIPTION_ACADEMIQUE_ACTIVE, $decision->regle);
        $this->assertNull($decision->inscriptionAcademiqueId);
    }

    public function test_etudiant_suspendu_est_non_eligible(): void
    {
        $etudiant = $this->creerEtudiant('ELIG00004', avecInscriptionPedagogique: true);

        SituationEtudiant::create([
            'etudiant_id' => $etudiant->id,
            'type' => 'suspension',
            'motif' => 'Suspension de test',
            'date_effet' => now()->subDay()->toDateString(),
        ]);

        $decision = app(ServiceEligibiliteExamen::class)->evaluer($this->epreuve, $etudiant);

        $this->assertTrue($decision->estNonEligible());
        $this->assertSame(DecisionEligibiliteExamen::SOURCE_ETUDIANT, $decision->source);
        $this->assertSame(ServiceEligibiliteExamen::REGLE_SUSPENSION_ACTIVE, $decision->regle);
        $this->assertNotNull($decision->inscriptionAcademiqueId);
        $this->assertStringContainsString('suspendu', (string) $decision->motif);
    }

    public function test_session_rattrapage_produit_une_anomalie_tant_que_la_regle_n_est_pas_validee(): void
    {
        $etudiant = $this->creerEtudiant('ELIG00005', avecInscriptionPedagogique: true);

        $rattrapage = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'session_origine_id' => $this->session->id,
            'code' => 'ELIG-RAT',
            'nom' => 'Rattrapage éligibilité',
            'type' => 'rattrapage',
            'date_debut' => '2027-02-10',
            'date_fin' => '2027-02-20',
            'statut' => 'draft',
        ]);

        $epreuveRattrapage = Epreuve::create([
            'examen_id' => $this->examen->id,
            'session_id' => $rattrapage->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiere->id,
            'date' => '2027-02-12',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
            'statut' => 'draft',
        ]);

        $decision = app(ServiceEligibiliteExamen::class)->evaluer($epreuveRattrapage, $etudiant);

        $this->assertTrue($decision->estAnomalie());
        $this->assertSame(DecisionEligibiliteExamen::ANOMALIE, $decision->decision);
        $this->assertSame(DecisionEligibiliteExamen::SOURCE_SESSION, $decision->source);
        $this->assertSame(ServiceEligibiliteExamen::REGLE_RATTRAPAGE_OFFICIELLE, $decision->regle);
        $this->assertFalse($decision->donneesCompletes);
        $this->assertStringContainsString('rattrapage', (string) $decision->motif);
    }

    public function test_population_groupe_retourne_les_decisions_explicables(): void
    {
        $groupe = Group::create([
            'code' => 'ELIG-G2',
            'nom' => 'Population groupe',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'capacite' => 30,
            'actif' => true,
        ]);

        $eligible = $this->creerEtudiant('ELIG00006', groupe: $groupe, avecInscriptionPedagogique: true);
        $exclu = $this->creerEtudiant('ELIG00007', groupe: $groupe, avecInscriptionPedagogique: false);

        $decisions = app(ServiceEligibiliteExamen::class)
            ->evaluerPopulation($this->epreuve, $groupe);

        $this->assertCount(2, $decisions);

        $decisionEligible = $decisions->first(fn (DecisionEligibiliteExamen $decision) => $decision->etudiantId === $eligible->id);
        $decisionExclu = $decisions->first(fn (DecisionEligibiliteExamen $decision) => $decision->etudiantId === $exclu->id);

        $this->assertNotNull($decisionEligible);
        $this->assertNotNull($decisionExclu);
        $this->assertTrue($decisionEligible->estEligible());
        $this->assertTrue($decisionExclu->estNonEligible());
        $this->assertSame(ServiceEligibiliteExamen::REGLE_INSCRIPTION_MODULE_ACTIVE, $decisionExclu->regle);
    }

    public function test_population_sans_groupe_utilise_les_inscriptions_pedagogiques_actives(): void
    {
        $eligible = $this->creerEtudiant('ELIG00008', avecInscriptionPedagogique: true);
        $this->creerEtudiant('ELIG00009', avecInscriptionPedagogique: false);

        $decisions = app(ServiceEligibiliteExamen::class)
            ->evaluerPopulation($this->epreuve);

        $this->assertCount(1, $decisions);
        $this->assertSame($eligible->id, $decisions->first()->etudiantId);
        $this->assertTrue($decisions->first()->estEligible());
    }

    public function test_incoherence_programme_produit_une_anomalie(): void
    {
        $programmeA = Programme::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'ELIG-PA',
            'nom' => 'Programme A',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
            'actif' => true,
        ]);

        $programmeB = Programme::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'ELIG-PB',
            'nom' => 'Programme B',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
            'actif' => true,
        ]);

        $examen = Examen::create([
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $programmeA->id,
            'libelle' => 'Examen programme A',
            'type' => 'examen',
            'statut' => 'draft',
        ]);

        $epreuve = Epreuve::create([
            'examen_id' => $examen->id,
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiere->id,
            'date' => '2027-01-13',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
            'statut' => 'draft',
        ]);

        $etudiant = $this->creerEtudiant('ELIG00010', programmeId: $programmeB->id, avecInscriptionPedagogique: true);

        $decision = app(ServiceEligibiliteExamen::class)->evaluer($epreuve, $etudiant);

        $this->assertTrue($decision->estAnomalie());
        $this->assertSame(DecisionEligibiliteExamen::SOURCE_INSCRIPTION_ACADEMIQUE, $decision->source);
        $this->assertSame(ServiceEligibiliteExamen::REGLE_COHERENCE_PROGRAMME, $decision->regle);
        $this->assertFalse($decision->donneesCompletes);
        $this->assertStringContainsString('Programme', (string) $decision->motif);
    }

    private function creerEtudiant(
        string $cin,
        ?Group $groupe = null,
        string $statutAcademique = 'en_cours',
        ?int $programmeId = null,
        bool $avecInscriptionPedagogique = true,
    ): Etudiant {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => 'Étudiant',
            'prenom' => 'Éligibilité',
            'email' => strtolower($cin).'@eligibilite.test',
            'date_naissance' => '2004-01-01',
            'sexe' => 'F',
            'faculte_id' => $this->faculte->id,
            'groupe_id' => $groupe?->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Adresse test',
            'ville' => 'Ville test',
            'code_postal' => '20000',
            'annee_bac' => 2024,
            'password' => 'Etudiant@123',
        ]);

        $etudiant->definirStatut('valide');

        $inscription = $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $programmeId,
            'niveau' => 'L1',
            'statut' => $statutAcademique,
        ]);

        if ($avecInscriptionPedagogique) {
            $inscription->pedagogiques()->create([
                'semestre_id' => $this->semestre->id,
                'ue_id' => $this->ue->id,
                'matiere_id' => $this->matiere->id,
                'statut' => 'inscrit',
            ]);
        }

        return $etudiant;
    }
}
