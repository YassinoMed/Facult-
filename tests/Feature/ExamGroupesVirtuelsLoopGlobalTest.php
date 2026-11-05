<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\IntegrationLog;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\AssistantIa\Exceptions\SecuriteIaException;
use App\Modules\AssistantIa\Tools\Metier\ComparerScenariosD04Tool;
use App\Modules\AssistantIa\Tools\Metier\ConsulterDerivesSessionTool;
use App\Modules\Evaluation\Console\AuditerGroupesVirtuelsCommand;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\GroupeVirtuelExamen;
use App\Modules\Evaluation\Models\GroupeVirtuelMembre;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\ConsultationExportGroupeVirtuelService;
use App\Modules\Evaluation\Services\ExamDecisionCapacitePermutationService;
use App\Modules\Evaluation\Services\GroupeVirtuelExamenService;
use App\Modules\Evaluation\Services\PreparationExamenService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LOOP GLOBAL UniRegistre — groupes virtuels d'examen.
 *
 * Vérifie de bout en bout :
 * 1. l'autorisation d'écriture (T1) ;
 * 2. l'exactitude des KPI au-delà de l'ancien plafond de 200 (T2) ;
 * 3. les filtres calculés, le tri, la pagination et la mémorisation (T2/T3) ;
 * 4. le contrôle groupé strictement en lecture seule et l'isolation facultaire (T4) ;
 * 5. le classement D-04 indicatif et son endpoint (T5) ;
 * 6. l'audit planifié idempotent, verrouillé et sans mutation (T6) ;
 * 7. les outils AssistantIa existants enrichis (T7).
 *
 * Aucune assertion ne dépend d'une base MySQL réelle : le test suit la garde
 * de tests/TestCase.php (SQLite :memory: uniquement).
 */
final class ExamGroupesVirtuelsLoopGlobalTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private Programme $programmeA;

    private Programme $programmeB;

    private Niveau $niveauA;

    private SemestreLmd $semestreA;

    private UniteEnseignement $ueA;

    private Matiere $matiereA;

    private SessionExamen $sessionA;

    private Examen $examenA;

    private Epreuve $epreuveA;

    private User $adminScolarite;

    private User $gestionnaireFaculteA;

    private User $gestionnaireFaculteB;

    private User $consultation;

    private User $auditeur;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté des Sciences de Tunis', 'code' => 'FST', 'active' => true,
        ]);
        $this->faculteB = Faculte::create([
            'nom' => 'Faculté de Droit et Sciences Politiques', 'code' => 'FDSP', 'active' => true,
        ]);

        $this->adminScolarite = User::creerCompteBackOffice([
            'name' => 'Admin Central Scolarité',
            'email' => 'admin.scol.loop@univ.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->gestionnaireFaculteA = User::creerCompteBackOffice([
            'name' => 'Gestionnaire FST',
            'email' => 'gest.fst.loop@univ.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $this->gestionnaireFaculteB = User::creerCompteBackOffice([
            'name' => 'Gestionnaire FDSP',
            'email' => 'gest.fdsp.loop@univ.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');

        $this->consultation = User::creerCompteBackOffice([
            'name' => 'Consultation Examen',
            'email' => 'consultation.loop@univ.test',
            'password' => 'Motdepasse@123',
        ], 'consultation');

        $this->auditeur = User::creerCompteBackOffice([
            'name' => 'Auditeur sans droits examen',
            'email' => 'auditeur.loop@univ.test',
            'password' => 'Motdepasse@123',
        ], 'auditeur');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $this->programmeA = Programme::create([
            'faculte_id' => $this->faculteA->id, 'code' => 'INF-L1', 'nom' => 'Licence Informatique',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $this->programmeB = Programme::create([
            'faculte_id' => $this->faculteB->id, 'code' => 'DROIT-L1', 'nom' => 'Licence Droit',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);

        $this->niveauA = Niveau::create([
            'programme_id' => $this->programmeA->id, 'code' => 'L1', 'nom' => 'Licence 1ère année', 'ordre' => 1,
        ]);
        $this->semestreA = SemestreLmd::create([
            'niveau_id' => $this->niveauA->id, 'code' => 'S1', 'nom' => 'Semestre 1',
            'numero' => 1, 'ordre' => 1,
        ]);
        $this->ueA = UniteEnseignement::create([
            'semestre_id' => $this->semestreA->id, 'programme_id' => $this->programmeA->id,
            'code' => 'UE-ALGO', 'nom' => 'UE Algorithmique & Programmation', 'credits' => 6, 'coefficient' => 2,
        ]);
        $this->matiereA = Matiere::create([
            'code' => 'ALGO-101', 'nom' => 'Algorithmique Avancée', 'ue_id' => $this->ueA->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 4, 'actif' => true,
        ]);

        $this->sessionA = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id, 'code' => 'SESS-PRINC-2027',
            'nom' => 'Session Principale Janvier 2027', 'type' => 'principale',
            'date_debut' => '2027-01-10', 'date_fin' => '2027-01-25', 'statut' => 'open',
        ]);
        $this->examenA = Examen::create([
            'session_id' => $this->sessionA->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programmeA->id,
            'niveau_id' => $this->niveauA->id,
            'libelle' => 'Examen Final Algorithmique L1', 'type' => 'examen', 'statut' => 'planned',
        ]);
        $this->epreuveA = Epreuve::create([
            'examen_id' => $this->examenA->id,
            'session_id' => $this->sessionA->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiereA->id,
            'date' => '2027-01-15', 'heure_debut' => '09:00', 'heure_fin' => '11:00',
            'statut' => 'draft',
        ]);
    }

    private function creerGroupe(string $code, Faculte $faculte, Programme $programme, Niveau $niveau): Group
    {
        return Group::create([
            'code' => $code,
            'nom' => 'Groupe '.$code,
            'faculte_id' => $faculte->id,
            'programme_id' => $programme->id,
            'niveau_id' => $niveau->id,
            'niveau' => $niveau->code,
            'capacite' => 40,
            'actif' => true,
        ]);
    }

    private function creerEtudiant(
        Group $groupe,
        string $cin,
        string $nom,
        string $prenom,
        bool $inscritMatiere = true
    ): Etudiant {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'num_inscription' => 'ETU-'.$cin,
            'nom' => $nom,
            'prenom' => $prenom,
            'email' => strtolower($cin).'@univ.test',
            'date_naissance' => '2004-05-15',
            'sexe' => 'M',
            'faculte_id' => $groupe->faculte_id,
            'groupe_id' => $groupe->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Campus Universitaire',
            'ville' => 'Tunis',
            'code_postal' => '1002',
            'annee_bac' => 2024,
            'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');

        $inscription = $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $groupe->programme_id,
            'niveau' => 'L1',
            'statut' => 'en_cours',
        ]);

        if ($inscritMatiere) {
            $inscription->pedagogiques()->create([
                'semestre_id' => $this->semestreA->id,
                'ue_id' => $this->ueA->id,
                'matiere_id' => $this->matiereA->id,
                'statut' => 'inscrit',
            ]);
        }

        return $etudiant;
    }

    /**
     * Crée une proposition sans passer par le service (utile pour les volumes
     * et les scénarios multi-facultés). Aucune écriture sur les groupes
     * académiques ni sur les inscriptions.
     *
     * @param  array<int, int>  $sourcesIds
     * @param  array<int, array{etudiant_id: int, decision?: string, groupe_source_id?: ?int}>  $membres
     */
    private function creerGroupeVirtuelDirect(
        string $libelle,
        Epreuve $epreuve,
        array $sourcesIds,
        array $membres = [],
        ?int $programmeId = null,
        ?int $auteurId = null
    ): GroupeVirtuelExamen {
        $gv = GroupeVirtuelExamen::create([
            'examen_id' => $epreuve->examen_id,
            'epreuve_id' => $epreuve->id,
            'matiere_id' => $epreuve->matiere_id,
            'programme_id' => $programmeId ?? $this->programmeA->id,
            'niveau_id' => $this->niveauA->id,
            'libelle' => $libelle,
            'cle' => hash('sha256', $libelle.'|'.Str::uuid()),
            'statut' => GroupeVirtuelExamen::STATUT_PROPOSE,
            'sources' => $sourcesIds,
            'cree_par' => $auteurId ?? $this->adminScolarite->id,
        ]);

        foreach ($membres as $membre) {
            GroupeVirtuelMembre::create([
                'groupe_virtuel_id' => $gv->id,
                'etudiant_id' => (int) $membre['etudiant_id'],
                'groupe_source_id' => $membre['groupe_source_id'] ?? ($sourcesIds[0] ?? null),
                'decision' => $membre['decision'] ?? GroupeVirtuelMembre::DECISION_ELIGIBLE,
            ]);
        }

        return $gv;
    }

    private function creerEpreuveSupplementaire(string $libelle): Epreuve
    {
        $examen = Examen::create([
            'session_id' => $this->sessionA->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programmeA->id,
            'niveau_id' => $this->niveauA->id,
            'libelle' => $libelle, 'type' => 'examen', 'statut' => 'planned',
        ]);

        return Epreuve::create([
            'examen_id' => $examen->id,
            'session_id' => $this->sessionA->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiereA->id,
            'date' => '2027-01-18', 'heure_debut' => '09:00', 'heure_fin' => '11:00',
            'statut' => 'draft',
        ]);
    }

    private function creerSalle(string $code, int $capacite): Room
    {
        return Room::create([
            'code' => $code,
            'nom' => 'Salle '.$code,
            'capacite' => $capacite,
            'faculte_id' => $this->faculteA->id,
            'type_salle' => 'salle',
            'actif' => true,
        ]);
    }

    /** T1 — les écritures restent interdites sans epreuves.update. */
    public function test_01_ecritures_refusees_sans_permission_epreuves_update(): void
    {
        $groupe = $this->creerGroupe('TD-AUTH', $this->faculteA, $this->programmeA, $this->niveauA);
        $this->creerEtudiant($groupe, 'CIN-AUTH', 'Ben Ali', 'Sami');
        $gv = app(GroupeVirtuelExamenService::class)->proposer($this->epreuveA, [$groupe->id], $this->adminScolarite->id);
        $avant = $gv->fresh()->toArray();

        $this->actingAs($this->consultation)
            ->post(route('admin.evaluation.examens.groupes-virtuels.generer-automatique'))
            ->assertForbidden();

        $this->actingAs($this->consultation)
            ->post(route('admin.evaluation.examens.groupes-virtuels.resynchroniser-selection'), ['ids' => [$gv->id]])
            ->assertForbidden();

        $this->actingAs($this->consultation)
            ->post(route('admin.evaluation.examens.groupes-virtuels.groupe.resynchroniser', $gv->id))
            ->assertForbidden();

        $this->assertSame($avant, $gv->fresh()->toArray());
    }

    /** T1 — les écritures fonctionnent avec epreuves.update et restent tracées. */
    public function test_02_ecritures_autorisees_avec_permission_epreuves_update(): void
    {
        $groupe = $this->creerGroupe('TD-WRITE', $this->faculteA, $this->programmeA, $this->niveauA);
        $this->creerEtudiant($groupe, 'CIN-WRITE', 'Trabelsi', 'Amira');
        $gv = app(GroupeVirtuelExamenService::class)->proposer($this->epreuveA, [$groupe->id], $this->adminScolarite->id);

        $this->actingAs($this->adminScolarite)
            ->post(route('admin.evaluation.examens.groupes-virtuels.generer-automatique'))
            ->assertRedirect();

        $this->actingAs($this->adminScolarite)
            ->post(route('admin.evaluation.examens.groupes-virtuels.resynchroniser-selection'), ['ids' => [$gv->id]])
            ->assertRedirect();

        $this->assertDatabaseHas('evaluation_groupes_virtuels', [
            'id' => $gv->id,
            'statut' => GroupeVirtuelExamen::STATUT_REMPLACE,
        ]);
        $this->assertNotNull($gv->fresh()->remplace_par_id);
    }

    /** T2 — les KPI restent exacts au-delà de l'ancien plafond de 200. */
    public function test_03_kpi_exacts_au_dela_de_200_groupes_et_pagination(): void
    {
        $groupe = $this->creerGroupe('TD-KPI', $this->faculteA, $this->programmeA, $this->niveauA);
        $this->creerEtudiant($groupe, 'CIN-KPI', 'Marzouki', 'Anis');

        for ($i = 1; $i <= 205; $i++) {
            $this->creerGroupeVirtuelDirect('GV-KPI-'.$i, $this->epreuveA, [$groupe->id]);
        }

        $donnees = app(ConsultationExportGroupeVirtuelService::class)
            ->listerGroupesVirtuels(['session_id' => $this->sessionA->id], $this->adminScolarite);

        $this->assertSame(205, (int) $donnees['kpis']['total_groupes']);
        $this->assertFalse((bool) $donnees['kpis']['plafond_atteint']);
        $this->assertSame(205, $donnees['paginator']->total());
        $this->assertCount(15, $donnees['lignes']);
        $this->assertSame(205, (int) $donnees['kpis']['groupes_en_derive']);
        $this->assertSame(205, (int) $donnees['kpis']['anomalies_bloquantes']);
    }

    /** T2 — les étudiants éligibles uniques ne sont jamais double-comptés. */
    public function test_04_etudiants_eligibles_uniques_et_total_exclus(): void
    {
        $groupe = $this->creerGroupe('TD-UNIQ', $this->faculteA, $this->programmeA, $this->niveauA);
        $e1 = $this->creerEtudiant($groupe, 'CIN-U1', 'Fakhfakh', 'Yassine');
        $e2 = $this->creerEtudiant($groupe, 'CIN-U2', 'Soltani', 'Wafa');
        $e3 = $this->creerEtudiant($groupe, 'CIN-U3', 'Nouveau', 'Candidat', false);

        $gv1 = app(GroupeVirtuelExamenService::class)->proposer($this->epreuveA, [$groupe->id], $this->adminScolarite->id);

        $membres = $gv1->membres->map(fn (GroupeVirtuelMembre $m) => [
            'etudiant_id' => (int) $m->etudiant_id,
            'decision' => (string) $m->decision,
        ])->all();

        $this->creerGroupeVirtuelDirect('GV-UNIQ-2', $this->epreuveA, [$groupe->id], $membres);

        $donnees = app(ConsultationExportGroupeVirtuelService::class)
            ->listerGroupesVirtuels(['session_id' => $this->sessionA->id], $this->adminScolarite);

        $this->assertSame(2, (int) $donnees['kpis']['total_groupes']);
        $this->assertSame(4, (int) $donnees['kpis']['total_eligibles']);
        $this->assertSame(2, (int) $donnees['kpis']['etudiants_eligibles_uniques']);
        $this->assertSame(2, (int) $donnees['kpis']['total_exclus']);
        $this->assertContains($e1->id, $gv1->membres()->pluck('etudiant_id')->all());
        $this->assertContains($e2->id, $gv1->membres()->pluck('etudiant_id')->all());
        $this->assertContains($e3->id, $gv1->membres()->pluck('etudiant_id')->all());
    }

    /** T2 — le filtre « dérive » et les cartes KPI portent sur le sous-ensemble filtré. */
    public function test_05_filtre_derive_et_kpis_du_sous_ensemble(): void
    {
        $salleA = $this->creerSalle('A-10', 10);
        $this->epreuveA->update(['salle_id' => $salleA->id]);

        $groupeDerive = $this->creerGroupe('TD-DER', $this->faculteA, $this->programmeA, $this->niveauA);
        $this->creerEtudiant($groupeDerive, 'CIN-D1', 'Derive', 'Un');
        $gvDerive = app(GroupeVirtuelExamenService::class)->proposer($this->epreuveA, [$groupeDerive->id], $this->adminScolarite->id);

        // Une autre épreuve reste propre : elle ne doit pas apparaître dans le filtre.
        $epreuveB = $this->creerEpreuveSupplementaire('Examen propre');
        $epreuveB->update(['salle_id' => $salleA->id]);
        $groupePropre = $this->creerGroupe('TD-PRO', $this->faculteA, $this->programmeA, $this->niveauA);
        $this->creerEtudiant($groupePropre, 'CIN-P1', 'Propre', 'Deux');
        app(GroupeVirtuelExamenService::class)->proposer($epreuveB, [$groupePropre->id], $this->adminScolarite->id);

        app(PreparationExamenService::class)->validerPopulation($this->epreuveA, $this->adminScolarite->id);
        $this->creerEtudiant($groupeDerive, 'CIN-D2', 'Derive', 'Trois');

        $service = app(ConsultationExportGroupeVirtuelService::class);

        $derive = $service->listerGroupesVirtuels(['statut' => 'derive'], $this->adminScolarite);
        $this->assertSame(1, $derive['paginator']->total());
        $this->assertSame(1, (int) $derive['kpis']['total_groupes']);
        $this->assertSame(1, (int) $derive['kpis']['groupes_en_derive']);
        $this->assertSame(1, (int) $derive['kpis']['anomalies_bloquantes']);
        $this->assertSame($gvDerive->id, (int) $derive['lignes'][0]['id']);

        $bloquant = $service->listerGroupesVirtuels(['statut' => 'bloquant'], $this->adminScolarite);
        $this->assertSame(1, $bloquant['paginator']->total());
        $this->assertSame(1, (int) $bloquant['kpis']['anomalies_bloquantes']);

        $tous = $service->listerGroupesVirtuels(['statut' => 'tous'], $this->adminScolarite);
        $this->assertSame(2, $tous['paginator']->total());

        $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.groupes-virtuels.anomalies'))
            ->assertOk()
            ->assertSee('Centre des anomalies des groupes virtuels')
            ->assertSee($gvDerive->libelle);
    }

    /** T2 — filtres « sans salle » et « sans étudiant éligible ». */
    public function test_06_filtres_sans_salle_et_sans_eligible(): void
    {
        $groupeVide = $this->creerGroupe('TD-VIDE', $this->faculteA, $this->programmeA, $this->niveauA);
        $gvVide = $this->creerGroupeVirtuelDirect('GV-VIDE', $this->epreuveA, [$groupeVide->id]);

        $groupeNormal = $this->creerGroupe('TD-NORM', $this->faculteA, $this->programmeA, $this->niveauA);
        $this->creerEtudiant($groupeNormal, 'CIN-N1', 'Normal', 'Quatre');
        app(GroupeVirtuelExamenService::class)->proposer($this->epreuveA, [$groupeNormal->id], $this->adminScolarite->id);

        $service = app(ConsultationExportGroupeVirtuelService::class);

        $sansSalle = $service->listerGroupesVirtuels(['statut' => 'sans_salle'], $this->adminScolarite);
        $this->assertSame(2, $sansSalle['paginator']->total());
        $this->assertSame(2, (int) $sansSalle['kpis']['groupes_sans_salle']);

        $sansEligible = $service->listerGroupesVirtuels(['statut' => 'sans_eligible'], $this->adminScolarite);
        $this->assertSame(1, $sansEligible['paginator']->total());
        $this->assertSame(1, (int) $sansEligible['kpis']['groupes_sans_eligible']);
        $this->assertSame($gvVide->id, (int) $sansEligible['lignes'][0]['id']);
    }

    /** T2 — le filtre « dépassement » s'appuie sur la capacité réelle de la salle. */
    public function test_07_filtre_depassement_de_capacite(): void
    {
        $salle = $this->creerSalle('A-1', 1);
        $this->epreuveA->update(['salle_id' => $salle->id]);

        $groupe = $this->creerGroupe('TD-DEP', $this->faculteA, $this->programmeA, $this->niveauA);
        $this->creerEtudiant($groupe, 'CIN-DEP1', 'Depasse', 'Un');
        $this->creerEtudiant($groupe, 'CIN-DEP2', 'Depasse', 'Deux');
        app(GroupeVirtuelExamenService::class)->proposer($this->epreuveA, [$groupe->id], $this->adminScolarite->id);

        $service = app(ConsultationExportGroupeVirtuelService::class);

        $depassement = $service->listerGroupesVirtuels(['statut' => 'depassement'], $this->adminScolarite);
        $this->assertSame(1, $depassement['paginator']->total());
        $this->assertSame(1, (int) $depassement['kpis']['groupes_depassement']);
        $this->assertSame(1, (int) $depassement['kpis']['anomalies_bloquantes']);

        $sansSalle = $service->listerGroupesVirtuels(['statut' => 'sans_salle'], $this->adminScolarite);
        $this->assertSame(0, $sansSalle['paginator']->total());
    }

    /** T3 — tri, tailles de page, mémorisation et restauration des filtres. */
    public function test_08_tri_pagination_memorisation_et_restauration(): void
    {
        $specs = [
            ['libelle' => 'Alpha', 'cin' => 'CIN-S1', 'effectif' => 1],
            ['libelle' => 'Beta', 'cin' => 'CIN-S2', 'effectif' => 3],
            ['libelle' => 'Gamma', 'cin' => 'CIN-S3', 'effectif' => 2],
        ];

        foreach ($specs as $index => $spec) {
            $groupe = $this->creerGroupe('TD-SORT-'.$index, $this->faculteA, $this->programmeA, $this->niveauA);
            $membres = [];
            for ($i = 0; $i < $spec['effectif']; $i++) {
                $etudiant = $this->creerEtudiant($groupe, $spec['cin'].'-'.$i, $spec['libelle'], 'Etudiant'.$i);
                $membres[] = ['etudiant_id' => $etudiant->id];
            }
            $this->creerGroupeVirtuelDirect($spec['libelle'], $this->epreuveA, [$groupe->id], $membres);
        }

        $reponse = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.groupes-virtuels.index', [
                'tri' => 'libelle', 'par_page' => 25, 'memoriser' => 1,
            ]));
        $reponse->assertOk();
        $this->assertSame(['Alpha', 'Beta', 'Gamma'], array_column($reponse->viewData('lignes'), 'libelle'));
        $this->assertSame(25, $reponse->viewData('filtres_appliques')['par_page']);
        $this->assertSame('libelle', $reponse->viewData('filtres_appliques')['tri']);

        $restauree = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.groupes-virtuels.index', ['restaurer' => 1]));
        $restauree->assertOk();
        $this->assertSame('libelle', $restauree->viewData('filtres_appliques')['tri']);
        $this->assertSame(25, $restauree->viewData('filtres_appliques')['par_page']);

        $parEffectif = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.groupes-virtuels.index', ['tri' => 'effectif']));
        $parEffectif->assertOk();
        $this->assertSame(['Beta', 'Gamma', 'Alpha'], array_column($parEffectif->viewData('lignes'), 'libelle'));

        $invalides = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.groupes-virtuels.index', ['tri' => 'inconnu', 'par_page' => 999]));
        $invalides->assertOk();
        $this->assertSame('recent', $invalides->viewData('filtres_appliques')['tri']);
        $this->assertSame(15, $invalides->viewData('filtres_appliques')['par_page']);
    }

    /** T4 — contrôle groupé en lecture seule, isolation facultaire et borne de 50. */
    public function test_09_controle_selection_lecture_seule_et_isolation(): void
    {
        $groupeA = $this->creerGroupe('TD-BULK-A', $this->faculteA, $this->programmeA, $this->niveauA);
        $this->creerEtudiant($groupeA, 'CIN-BA', 'Bulk', 'A');
        $gvA = app(GroupeVirtuelExamenService::class)->proposer($this->epreuveA, [$groupeA->id], $this->adminScolarite->id);

        $groupeB = $this->creerGroupe('TD-BULK-B', $this->faculteB, $this->programmeB, $this->niveauA);
        $gvB = $this->creerGroupeVirtuelDirect('GV-BULK-B', $this->epreuveA, [$groupeB->id], [], $this->programmeB->id);

        $avantA = $gvA->fresh()->toArray();
        $avantB = $gvB->fresh()->toArray();
        $membresAvant = GroupeVirtuelMembre::query()->count();

        $reponse = $this->actingAs($this->gestionnaireFaculteA)
            ->postJson(route('admin.evaluation.examens.groupes-virtuels.controler-selection'), [
                'ids' => [$gvA->id, $gvB->id],
            ]);

        $reponse->assertOk()
            ->assertJsonPath('compteurs.total', 2)
            ->assertJsonPath('compteurs.inaccessibles', 1);
        $this->assertCount(2, $reponse->json('resultats'));

        $resultatB = collect($reponse->json('resultats'))->firstWhere('id', $gvB->id);
        $this->assertSame('inaccessible', $resultatB['statut']);

        $this->assertSame($avantA, $gvA->fresh()->toArray());
        $this->assertSame($avantB, $gvB->fresh()->toArray());
        $this->assertSame($membresAvant, GroupeVirtuelMembre::query()->count());

        // Un lecteur autorisé (evaluations.view) peut contrôler, sans écrire.
        $lecture = $this->actingAs($this->consultation)
            ->postJson(route('admin.evaluation.examens.groupes-virtuels.controler-selection'), ['ids' => [$gvA->id]]);
        $lecture->assertOk()->assertJsonPath('compteurs.total', 1);
        $this->assertSame($avantA, $gvA->fresh()->toArray());

        // Borne serveur : 51 identifiants refusés.
        $this->actingAs($this->adminScolarite)
            ->postJson(route('admin.evaluation.examens.groupes-virtuels.controler-selection'), [
                'ids' => range(1, 51),
            ])
            ->assertStatus(422);
    }

    /** T5 — classement D-04 indicatif, sans « optimal », et endpoint JSON. */
    public function test_10_classement_d04_indicatif_et_endpoint_comparer(): void
    {
        $service = app(ExamDecisionCapacitePermutationService::class);

        $comparaison = $service->comparerVariantes([
            ['cle' => 'A', 'capacite_utile_totale' => 100, 'effectif_couvert' => 60, 'nb_salles' => 1, 'score' => 10, 'viable' => true],
            ['cle' => 'B', 'capacite_utile_totale' => 100, 'effectif_couvert' => 90, 'nb_salles' => 1, 'score' => 90, 'viable' => true],
            ['cle' => 'C', 'capacite_utile_totale' => 50, 'effectif_couvert' => 60, 'nb_salles' => 1, 'score' => 99, 'viable' => true],
        ]);

        $this->assertSame(['B', 'A', 'C'], array_column($comparaison['classement'], 'cle'));
        $this->assertSame('B', $comparaison['recommandee']);
        $this->assertStringContainsString('viable', $comparaison['justification']);
        $this->assertStringNotContainsString('optimal', mb_strtolower($comparaison['justification']));

        $reponse = $this->actingAs($this->consultation)
            ->getJson(route('admin.evaluation.examens.groupes.capacite.comparer', [
                'epreuve_id' => $this->epreuveA->id,
                'annee_id' => $this->annee->id,
            ]));

        $reponse->assertOk()
            ->assertJsonPath('evenement', 'comparaison_terminee')
            ->assertJsonPath('epreuve_id', $this->epreuveA->id);
        $this->assertIsArray($reponse->json('classement'));
        $this->assertIsArray($reponse->json('criteres'));

        $this->actingAs($this->auditeur)
            ->getJson(route('admin.evaluation.examens.groupes.capacite.comparer', [
                'epreuve_id' => $this->epreuveA->id,
                'annee_id' => $this->annee->id,
            ]))
            ->assertForbidden();
    }

    /** T6 — audit planifié idempotent, verrouillé, sans aucune mutation. */
    public function test_11_audit_planifie_idempotent_verrouille_et_sans_mutation(): void
    {
        $salle = $this->creerSalle('A-AUDIT', 10);
        $this->epreuveA->update(['salle_id' => $salle->id]);

        $groupe = $this->creerGroupe('TD-AUDIT', $this->faculteA, $this->programmeA, $this->niveauA);
        $this->creerEtudiant($groupe, 'CIN-AUD1', 'Audit', 'Un');
        $gv = app(GroupeVirtuelExamenService::class)->proposer($this->epreuveA, [$groupe->id], $this->adminScolarite->id);

        app(PreparationExamenService::class)->validerPopulation($this->epreuveA, $this->adminScolarite->id);
        $this->creerEtudiant($groupe, 'CIN-AUD2', 'Audit', 'Deux');

        $avant = $gv->fresh()->toArray();
        $membresAvant = GroupeVirtuelMembre::query()->count();

        $this->artisan('examens:auditer-groupes-virtuels', ['--json' => true])
            ->assertExitCode(0);

        $etat = Cache::get(AuditerGroupesVirtuelsCommand::CACHE_ETAT);
        $this->assertIsArray($etat);
        $this->assertTrue((bool) $etat['aucune_mutation']);
        $this->assertSame(1, (int) $etat['compteurs']['analyses']);
        $this->assertSame(1, (int) $etat['compteurs']['derives']);
        $this->assertFalse((bool) $etat['plafond_atteint']);
        $this->assertSame(64, strlen((string) $etat['empreinte']));
        $this->assertSame(1, IntegrationLog::where('operation', 'audit_derive')->count());

        $this->artisan('examens:auditer-groupes-virtuels', ['--json' => true])
            ->assertExitCode(0);

        $etat2 = Cache::get(AuditerGroupesVirtuelsCommand::CACHE_ETAT);
        $this->assertTrue((bool) $etat2['identique_a_precedent']);
        $this->assertSame(1, IntegrationLog::where('operation', 'audit_derive')->count(), 'Une exécution identique ne doit pas dupliquer la trace.');

        $this->assertSame($avant, $gv->fresh()->toArray());
        $this->assertSame($membresAvant, GroupeVirtuelMembre::query()->count());

        $verrou = Cache::lock(AuditerGroupesVirtuelsCommand::CACHE_VERROU, 300);
        $this->assertTrue($verrou->get());

        try {
            $this->artisan('examens:auditer-groupes-virtuels', ['--json' => true])
                ->assertExitCode(1);
        } finally {
            $verrou->release();
        }
    }

    /** T7 — outil AssistantIa de dérives : lecture seule, KPI exacts, isolation. */
    public function test_12_outil_ia_derives_lecture_seule_et_isolation_facultaire(): void
    {
        $groupe = $this->creerGroupe('TD-IA', $this->faculteA, $this->programmeA, $this->niveauA);
        $this->creerEtudiant($groupe, 'CIN-IA1', 'Ia', 'Un');
        $gv = app(GroupeVirtuelExamenService::class)->proposer($this->epreuveA, [$groupe->id], $this->adminScolarite->id);
        $avant = $gv->fresh()->toArray();

        $outil = app(ConsulterDerivesSessionTool::class);

        $resultat = $outil->executer(['session_id' => $this->sessionA->id], $this->adminScolarite);
        $this->assertTrue($resultat->succes);
        $this->assertTrue((bool) $resultat->donnees['lecture_seule']);
        $this->assertTrue((bool) $resultat->donnees['aucune_mutation']);
        $this->assertSame(1, (int) $resultat->donnees['total_groupes']);
        $this->assertSame(1, (int) $resultat->donnees['etudiants_eligibles_uniques']);
        $this->assertSame(0, (int) $resultat->donnees['total_exclus']);
        $this->assertArrayHasKey('plafond_atteint', $resultat->donnees);
        $this->assertStringNotContainsString('CIN-IA1', json_encode($resultat->donnees, JSON_UNESCAPED_UNICODE));

        try {
            $outil->executer(['session_id' => $this->sessionA->id], $this->consultation);
            $this->fail('Un profil sans assistant_ia.mode_metier ne doit pas exécuter l’outil.');
        } catch (SecuriteIaException $e) {
            $this->assertStringContainsString('habilitation', $e->getMessage());
        }

        // Isolation par les données : le gestionnaire B ne voit aucun groupe de A.
        $resultatB = $outil->executer(['session_id' => $this->sessionA->id], $this->gestionnaireFaculteB);
        $this->assertTrue($resultatB->succes);
        $this->assertSame(0, (int) $resultatB->donnees['total_groupes']);

        $this->assertSame($avant, $gv->fresh()->toArray());
    }

    /** T7 — outil AssistantIa D-04 : classement indicatif et périmètre facultaire. */
    public function test_13_outil_ia_d04_classement_indicatif_et_perimetre(): void
    {
        $groupe = $this->creerGroupe('TD-IA-D04', $this->faculteA, $this->programmeA, $this->niveauA);
        $this->creerEtudiant($groupe, 'CIN-IAD1', 'IaD04', 'Un');
        app(GroupeVirtuelExamenService::class)->proposer($this->epreuveA, [$groupe->id], $this->adminScolarite->id);

        $outil = app(ComparerScenariosD04Tool::class);

        $resultat = $outil->executer(['epreuve_id' => $this->epreuveA->id], $this->adminScolarite);
        $this->assertTrue($resultat->succes);
        $this->assertTrue((bool) $resultat->donnees['lecture_seule']);
        $this->assertIsArray($resultat->donnees['classement_d04']);
        $this->assertIsArray($resultat->donnees['criteres_classement']);
        $this->assertStringContainsString('indicatif', mb_strtolower((string) $resultat->donnees['avertissement_classement']));
        $this->assertStringNotContainsString('optimal', mb_strtolower((string) $resultat->message));

        try {
            $outil->executer(['epreuve_id' => $this->epreuveA->id], $this->gestionnaireFaculteB);
            $this->fail('Une épreuve de la faculté A ne doit pas être visible par le gestionnaire B.');
        } catch (SecuriteIaException $e) {
            $this->assertStringContainsString('périmètre', mb_strtolower($e->getMessage()));
        }

        try {
            $outil->executer(['epreuve_id' => $this->epreuveA->id], $this->consultation);
            $this->fail('Un profil sans assistant_ia.mode_metier ne doit pas exécuter l’outil.');
        } catch (SecuriteIaException $e) {
            $this->assertStringContainsString('habilitation', $e->getMessage());
        }
    }
}
