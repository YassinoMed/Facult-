<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\GroupeVirtuelExamen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\GroupeVirtuelExamenService;
use App\Modules\Evaluation\Services\PreparationExamenService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * Tests obligatoires (Phase G.1 à G.15) :
 * Consultation, export multi-formats (XLSX, CSV, PDF, HTML) et impression professionnelle
 * des groupes virtuels des examens finaux.
 */
class ExamGroupesVirtuelsConsultationExportTest extends TestCase
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

    private User $utilisateurSansDroits;

    private User $gestionnaireFaculteA;

    private User $gestionnaireFaculteB;

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
            'email' => 'admin.scol@univ.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->gestionnaireFaculteA = User::creerCompteBackOffice([
            'name' => 'Gestionnaire FST',
            'email' => 'gest.fst@univ.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $this->gestionnaireFaculteB = User::creerCompteBackOffice([
            'name' => 'Gestionnaire FDSP',
            'email' => 'gest.fdsp@univ.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');

        $this->utilisateurSansDroits = User::creerCompteBackOffice([
            'name' => 'Utilisateur Auditeur Sans Droits Examen',
            'email' => 'auditeur.sansdroits@univ.test',
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

    /** 1. Affichage des groupes virtuels dans le tableau principal */
    public function test_01_affichage_des_groupes_virtuels(): void
    {
        $groupeTD1 = $this->creerGroupe('TD1', $this->faculteA, $this->programmeA, $this->niveauA);
        $groupeTD2 = $this->creerGroupe('TD2', $this->faculteA, $this->programmeA, $this->niveauA);

        $this->creerEtudiant($groupeTD1, 'CIN01', 'Ben Ali', 'Mohamed', true);
        $this->creerEtudiant($groupeTD2, 'CIN02', 'Trabelsi', 'Amira', true);

        $groupeVirtuel = app(GroupeVirtuelExamenService::class)
            ->proposer($this->epreuveA, [$groupeTD1->id, $groupeTD2->id], $this->adminScolarite->id);

        $response = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.groupes-virtuels.index'));

        $response->assertOk();
        $response->assertSee($groupeVirtuel->libelle);
        $response->assertSee('ALGO-101');
        $response->assertSee('2 éligible(s)');
        $response->assertSee('Proposition générée');
    }

    /** 2. Filtrage par session, matière et faculté */
    public function test_02_filtrage_par_session_matiere_et_faculte(): void
    {
        $groupeA = $this->creerGroupe('TD-A', $this->faculteA, $this->programmeA, $this->niveauA);
        $this->creerEtudiant($groupeA, 'CINA1', 'Fakhfakh', 'Yassine', true);

        $gvA = app(GroupeVirtuelExamenService::class)->proposer($this->epreuveA, [$groupeA->id], $this->adminScolarite->id);

        // Autre session
        $autreSession = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id, 'code' => 'SESS-RATT-2027',
            'nom' => 'Session Rattrapage', 'type' => 'rattrapage',
            'date_debut' => '2027-02-10', 'date_fin' => '2027-02-20', 'statut' => 'open',
        ]);

        $autreExamen = Examen::create([
            'session_id' => $autreSession->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programmeA->id,
            'niveau_id' => $this->niveauA->id,
            'code' => 'EXAM-RATT-2027',
            'libelle' => 'Examen Rattrapage Algorithmique',
            'statut' => 'draft',
        ]);

        $autreEpreuve = Epreuve::create([
            'examen_id' => $autreExamen->id,
            'session_id' => $autreSession->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiereA->id,
            'date' => '2027-02-12', 'heure_debut' => '09:00', 'heure_fin' => '11:00',
            'statut' => 'draft',
        ]);

        $groupeB = $this->creerGroupe('TD-B', $this->faculteA, $this->programmeA, $this->niveauA);
        $this->creerEtudiant($groupeB, 'CINB1', 'Autre', 'Etudiant', true);

        $gvB = app(GroupeVirtuelExamenService::class)->proposer($autreEpreuve, [$groupeB->id], $this->adminScolarite->id);

        // Filtre par session A : seul gvA doit être visible dans les résultats
        $repSession = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.groupes-virtuels.index', ['session_id' => $this->sessionA->id]));
        $repSession->assertOk();
        $repSession->assertSee($gvA->libelle);
        $repSession->assertDontSee($gvB->libelle);

        // Filtre par matière A
        $repMatiere = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.groupes-virtuels.index', ['matiere_id' => $this->matiereA->id]));
        $repMatiere->assertOk();
        $repMatiere->assertSee('ALGO-101');
    }

    /** 3. Correspondance exacte entre étudiants, matière et groupe virtuel */
    public function test_03_correspondance_exacte_entre_etudiants_matiere_et_groupe_virtuel(): void
    {
        $groupeTD1 = $this->creerGroupe('TD3', $this->faculteA, $this->programmeA, $this->niveauA);

        $inscrit = $this->creerEtudiant($groupeTD1, 'CIN03', 'Mansour', 'Sami', true);
        $nonInscrit = $this->creerEtudiant($groupeTD1, 'CIN04', 'Dridi', 'Khadija', false);

        $groupeVirtuel = app(GroupeVirtuelExamenService::class)
            ->proposer($this->epreuveA, [$groupeTD1->id], $this->adminScolarite->id);

        $response = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.groupes-virtuels.show', $groupeVirtuel->id));

        $response->assertOk();
        // L'étudiant inscrit est dans les éligibles
        $response->assertSee('Mansour');
        $response->assertSee('Sami');
        $response->assertSee($inscrit->num_inscription);
        $response->assertSee('Éligible');

        // L'étudiant non inscrit est dans les exclusions motivées
        $response->assertSee('Dridi');
        $response->assertSee('Khadija');
        $response->assertSee($nonInscrit->num_inscription);
    }

    /** 4. Export XLSX valide */
    public function test_04_export_xlsx_valide(): void
    {
        $groupeTD1 = $this->creerGroupe('TD4', $this->faculteA, $this->programmeA, $this->niveauA);
        $this->creerEtudiant($groupeTD1, 'CIN05', 'Ayari', 'Nour', true);

        $groupeVirtuel = app(GroupeVirtuelExamenService::class)
            ->proposer($this->epreuveA, [$groupeTD1->id], $this->adminScolarite->id);

        $response = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.groupes-virtuels.groupe.exporter', [$groupeVirtuel->id, 'xlsx']));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->assertHeader('X-Export-Effectif', '1');

        $contenu = $response->getContent();
        // Signature d'un fichier ZIP / XLSX valide (PK\x03\x04)
        $this->assertStringStartsWith("PK\x03\x04", $contenu);
    }

    /** 5. Export CSV valide et protégé contre l’injection de formules */
    public function test_05_export_csv_valide_et_protege_contre_injection_formules(): void
    {
        $groupeTD1 = $this->creerGroupe('TD5', $this->faculteA, $this->programmeA, $this->niveauA);
        // Nom commençant par un signe de formule (=, +, -, @)
        $this->creerEtudiant($groupeTD1, 'CIN06', '=cmd|calc', '+FormuleTest', true);

        $groupeVirtuel = app(GroupeVirtuelExamenService::class)
            ->proposer($this->epreuveA, [$groupeTD1->id], $this->adminScolarite->id);

        $response = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.groupes-virtuels.groupe.exporter', [$groupeVirtuel->id, 'csv']));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $contenu = $response->getContent();
        // BOM UTF-8
        $this->assertStringStartsWith("\xEF\xBB\xBF", $contenu);
        // Les formules sont neutralisées par un préfixe apostrophe
        $this->assertStringContainsString(";'=cmd|calc;", $contenu);
        $this->assertStringContainsString(";'+FormuleTest;", $contenu);
    }

    /** 6. Export PDF exploitable */
    public function test_06_export_pdf_exploitable(): void
    {
        $groupeTD1 = $this->creerGroupe('TD6', $this->faculteA, $this->programmeA, $this->niveauA);
        $this->creerEtudiant($groupeTD1, 'CIN07', 'Kacem', 'Zied', true);

        $groupeVirtuel = app(GroupeVirtuelExamenService::class)
            ->proposer($this->epreuveA, [$groupeTD1->id], $this->adminScolarite->id);

        $response = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.groupes-virtuels.groupe.exporter', [$groupeVirtuel->id, 'pdf']));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');

        $contenu = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $contenu);
    }

    /** 7. Rendu HTML imprimable */
    public function test_07_rendu_html_imprimable(): void
    {
        $groupeTD1 = $this->creerGroupe('TD7', $this->faculteA, $this->programmeA, $this->niveauA);
        $this->creerEtudiant($groupeTD1, 'CIN08', 'Jlassi', 'Ines', true);

        $groupeVirtuel = app(GroupeVirtuelExamenService::class)
            ->proposer($this->epreuveA, [$groupeTD1->id], $this->adminScolarite->id);

        // Page HTML imprimable (fiche)
        $repFiche = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.groupes-virtuels.groupe.imprimer', [$groupeVirtuel->id, 'fiche']));
        $repFiche->assertOk();
        $repFiche->assertSee('Fiche récapitulative du groupe virtuel');
        $repFiche->assertSee('@media print');

        // Page HTML feuille d'émargement
        $repEmargement = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.groupes-virtuels.groupe.imprimer', [$groupeVirtuel->id, 'emargement']));
        $repEmargement->assertOk();
        $repEmargement->assertSee('Feuille d\'émargement');
        $repEmargement->assertSee('Signature de l\'étudiant', false);
    }

    /** 8. Respect des filtres dans les exports de sélection */
    public function test_08_respect_des_filtres_dans_les_exports(): void
    {
        $groupe1 = $this->creerGroupe('TD8A', $this->faculteA, $this->programmeA, $this->niveauA);
        $this->creerEtudiant($groupe1, 'CIN09', 'Bouazizi', 'Karim', true);

        $gv = app(GroupeVirtuelExamenService::class)->proposer($this->epreuveA, [$groupe1->id], $this->adminScolarite->id);

        $response = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.groupes-virtuels.exporter', [
                'format' => 'csv',
                'session_id' => $this->sessionA->id,
            ]));

        $response->assertOk();
        $contenu = $response->getContent();
        $this->assertStringContainsString($gv->libelle, $contenu);
        $this->assertStringContainsString('ALGO-101', $contenu);
    }

    /** 9. Accès refusé aux utilisateurs non autorisés */
    public function test_09_acces_refuse_aux_utilisateurs_non_autorises(): void
    {
        $groupe1 = $this->creerGroupe('TD9', $this->faculteA, $this->programmeA, $this->niveauA);
        $this->creerEtudiant($groupe1, 'CIN10', 'Mejri', 'Salma', true);
        $gv = app(GroupeVirtuelExamenService::class)->proposer($this->epreuveA, [$groupe1->id], $this->adminScolarite->id);

        // Utilisateur invité sans permission
        $response = $this->actingAs($this->utilisateurSansDroits)
            ->get(route('admin.evaluation.examens.groupes-virtuels.index'));
        $response->assertForbidden();

        // Non authentifié : redirection vers la page de connexion administrative
        auth()->logout();
        $repGuest = $this->get(route('admin.evaluation.examens.groupes-virtuels.index'));
        $repGuest->assertRedirect(route('admin.connexion.show'));
    }

    /** 10. Isolation entre facultés */
    public function test_10_isolation_entre_facultes(): void
    {
        $groupeFst = $this->creerGroupe('TD10', $this->faculteA, $this->programmeA, $this->niveauA);
        $this->creerEtudiant($groupeFst, 'CIN11', 'Gharbi', 'Walid', true);
        $gvFst = app(GroupeVirtuelExamenService::class)->proposer($this->epreuveA, [$groupeFst->id], $this->adminScolarite->id);

        // Gestionnaire FST a accès à son groupe
        $repFst = $this->actingAs($this->gestionnaireFaculteA)
            ->get(route('admin.evaluation.examens.groupes-virtuels.show', $gvFst->id));
        $repFst->assertOk();

        // Gestionnaire FDSP (Faculté B) est strictement interdit d'accès (403)
        $repFdsp = $this->actingAs($this->gestionnaireFaculteB)
            ->get(route('admin.evaluation.examens.groupes-virtuels.show', $gvFst->id));
        $repFdsp->assertForbidden();

        // Export interdit également
        $repExportFdsp = $this->actingAs($this->gestionnaireFaculteB)
            ->get(route('admin.evaluation.examens.groupes-virtuels.groupe.exporter', [$gvFst->id, 'xlsx']));
        $repExportFdsp->assertForbidden();
    }

    /** 11. Blocage des exports officiels lorsque la correspondance est invalide */
    public function test_11_blocage_des_exports_officiels_lorsque_correspondance_invalide(): void
    {
        $groupe1 = $this->creerGroupe('TD11', $this->faculteA, $this->programmeA, $this->niveauA);
        $this->creerEtudiant($groupe1, 'CIN12', 'Chahed', 'Leila', true);
        $gv = app(GroupeVirtuelExamenService::class)->proposer($this->epreuveA, [$groupe1->id], $this->adminScolarite->id);

        // Annuler le groupe virtuel pour simuler un état non exportable
        $gv->update(['statut' => GroupeVirtuelExamen::STATUT_ANNULE]);

        $response = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.groupes-virtuels.groupe.exporter', [$gv->id, 'xlsx']));

        // Doit renvoyer HTTP 422 avec message explicatif
        $response->assertStatus(422);
        $this->assertStringContainsString('annulé', $response->getContent());
    }

    /** 12. Détection d’une dérive de population après génération ou validation */
    public function test_12_detection_d_une_derive_de_population_apres_generation_ou_validation(): void
    {
        $groupe1 = $this->creerGroupe('TD12', $this->faculteA, $this->programmeA, $this->niveauA);
        $etudiant1 = $this->creerEtudiant($groupe1, 'CIN13', 'Marzouki', 'Anis', true);

        $gv = app(GroupeVirtuelExamenService::class)->proposer($this->epreuveA, [$groupe1->id], $this->adminScolarite->id);

        // Valider la population de l'épreuve
        app(PreparationExamenService::class)->validerPopulation($this->epreuveA, $this->adminScolarite->id);

        // Ajouter un nouvel étudiant au groupe après validation -> provoque une dérive
        $this->creerEtudiant($groupe1, 'CIN14', 'Nouveau', 'Candidat', true);

        $response = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.groupes-virtuels.show', $gv->id));

        $response->assertOk();
        // Le signalement de dérive doit être visible
        $response->assertSee('Dérive de population détectée');

        // L'export officiel doit être bloqué avec 422
        $repExport = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.groupes-virtuels.groupe.exporter', [$gv->id, 'xlsx']));
        $repExport->assertStatus(422);
    }

    /** 13. Absence de modification des groupes et inscriptions sources */
    public function test_13_absence_de_modification_des_groupes_et_inscriptions_sources(): void
    {
        $groupe1 = $this->creerGroupe('TD13', $this->faculteA, $this->programmeA, $this->niveauA);
        $etudiant = $this->creerEtudiant($groupe1, 'CIN15', 'Soltani', 'Wafa', true);

        $gv = app(GroupeVirtuelExamenService::class)->proposer($this->epreuveA, [$groupe1->id], $this->adminScolarite->id);

        $etatGroupeAvant = $groupe1->fresh()->toArray();
        $groupeIdEtudiantAvant = $etudiant->fresh()->groupe_id;
        $inscriptionsAvant = $etudiant->fresh()->inscriptionsAcademiques()->count();

        // Consulter et exporter
        $this->actingAs($this->adminScolarite)->get(route('admin.evaluation.examens.groupes-virtuels.show', $gv->id));
        $this->actingAs($this->adminScolarite)->get(route('admin.evaluation.examens.groupes-virtuels.groupe.exporter', [$gv->id, 'xlsx']));
        $this->actingAs($this->adminScolarite)->get(route('admin.evaluation.examens.groupes-virtuels.groupe.imprimer', [$gv->id, 'etudiants']));

        // Vérification stricte d'immuabilité
        $this->assertSame($etatGroupeAvant, $groupe1->fresh()->toArray());
        $this->assertSame($groupeIdEtudiantAvant, $etudiant->fresh()->groupe_id);
        $this->assertSame($inscriptionsAvant, $etudiant->fresh()->inscriptionsAcademiques()->count());
    }

    /** 14. Gestion des groupes vides, des caractères accentués et des noms longs */
    public function test_14_gestion_des_groupes_vides_caracteres_accentues_et_noms_longs(): void
    {
        $groupeAccentue = $this->creerGroupe('TD-ÉLÈVES-EXPÉRIMENTAUX', $this->faculteA, $this->programmeA, $this->niveauA);
        $nomLong = 'De La Tour d\'Auvergne-Lauragais de Châteaubriant';
        $prenomAccentue = 'Éléonore-Chloë';
        $this->creerEtudiant($groupeAccentue, 'CIN16', $nomLong, $prenomAccentue, true);

        $gv = app(GroupeVirtuelExamenService::class)->proposer($this->epreuveA, [$groupeAccentue->id], $this->adminScolarite->id);

        $response = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.groupes-virtuels.show', $gv->id));

        $response->assertOk();
        $response->assertSee('Éléonore-Chloë');
        $response->assertSee($nomLong);

        // Export CSV avec accents et BOM UTF-8
        $repCsv = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.groupes-virtuels.groupe.exporter', [$gv->id, 'csv']));
        $repCsv->assertOk();
        $this->assertStringContainsString('Éléonore-Chloë', $repCsv->getContent());
    }

    /** 15. Cohérence des effectifs entre écran, XLSX, CSV, PDF et impression */
    public function test_15_coherence_des_effectifs_entre_ecran_xlsx_csv_pdf_et_impression(): void
    {
        $groupe1 = $this->creerGroupe('TD15A', $this->faculteA, $this->programmeA, $this->niveauA);
        $groupe2 = $this->creerGroupe('TD15B', $this->faculteA, $this->programmeA, $this->niveauA);

        $this->creerEtudiant($groupe1, 'CIN17', 'Etudiant1', 'Prenom1', true);
        $this->creerEtudiant($groupe1, 'CIN18', 'Etudiant2', 'Prenom2', true);
        $this->creerEtudiant($groupe2, 'CIN19', 'Etudiant3', 'Prenom3', true);

        $gv = app(GroupeVirtuelExamenService::class)->proposer($this->epreuveA, [$groupe1->id, $groupe2->id], $this->adminScolarite->id);

        $effectifAttendu = 3;

        // 1. Écran
        $repEcran = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.groupes-virtuels.show', $gv->id));
        $repEcran->assertOk();
        $repEcran->assertSee("Liste des étudiants rattachés au groupe virtuel et éligibles à la matière ({$effectifAttendu})");

        // 2. Export XLSX
        $repXlsx = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.groupes-virtuels.groupe.exporter', [$gv->id, 'xlsx']));
        $repXlsx->assertOk();
        $this->assertSame((string) $effectifAttendu, $repXlsx->headers->get('X-Export-Effectif'));

        // 3. Export CSV
        $repCsv = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.groupes-virtuels.groupe.exporter', [$gv->id, 'csv']));
        $repCsv->assertOk();
        $this->assertSame((string) $effectifAttendu, $repCsv->headers->get('X-Export-Effectif'));
        $lignesCsv = array_filter(explode("\n", trim($repCsv->getContent())));
        // 1 ligne d'en-tête + 3 étudiants = 4 lignes
        $this->assertCount($effectifAttendu + 1, $lignesCsv);

        // 4. Export PDF
        $repPdf = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.groupes-virtuels.groupe.exporter', [$gv->id, 'pdf']));
        $repPdf->assertOk();
        $this->assertSame((string) $effectifAttendu, $repPdf->headers->get('X-Export-Effectif'));

        // 5. Impression HTML
        $repPrint = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.groupes-virtuels.groupe.imprimer', [$gv->id, 'etudiants']));
        $repPrint->assertOk();
        $this->assertStringContainsString("{$effectifAttendu} étudiant(s)", $repPrint->getContent());
    }

    /** 16. Détection et génération automatique via action contrôleur/UI */
    public function test_16_generation_automatique_via_action_ui(): void
    {
        $groupe1 = $this->creerGroupe('TD16', $this->faculteA, $this->programmeA, $this->niveauA);
        $this->creerEtudiant($groupe1, 'CIN20', 'TestAuto', 'Candidat', true);

        $response = $this->actingAs($this->adminScolarite)
            ->post(route('admin.evaluation.examens.groupes-virtuels.generer-automatique', [
                'session_id' => $this->sessionA->id,
            ]));

        $response->assertRedirect(route('admin.evaluation.examens.groupes-virtuels.index', [
            'session_id' => $this->sessionA->id,
        ]));
        $response->assertSessionHas('succes');

        // Vérifie qu'un groupe virtuel a bien été persisté pour l'épreuve
        $this->assertDatabaseHas('evaluation_groupes_virtuels', [
            'epreuve_id' => $this->epreuveA->id,
            'statut' => 'propose',
        ]);
    }

    /** 17. Option A — Re-synchronisation d'un groupe virtuel en dérive et filtrage KPI par statut */
    public function test_17_option_a_resynchronisation_groupe_virtuel_en_derive(): void
    {
        $groupe1 = $this->creerGroupe('TD17', $this->faculteA, $this->programmeA, $this->niveauA);
        $this->creerEtudiant($groupe1, 'CIN21', 'EtudiantInitial', 'PrenomInit', true);

        // Proposition initiale
        $gv = app(GroupeVirtuelExamenService::class)->proposer($this->epreuveA, [$groupe1->id], $this->adminScolarite->id);

        // Validation officielle de la population
        $this->epreuveA->update([
            'population_validated_at' => now(),
            'statut_validation' => 'valide',
        ]);

        // Ajout d'un nouvel étudiant éligible : déclenche une dérive de population
        $this->creerEtudiant($groupe1, 'CIN22', 'NouvelEtudiant', 'PrenomNouv', true);

        // 1. Consultation de la fiche : détection de la dérive et affichage du bouton d'actualisation
        $repShow = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.groupes-virtuels.show', $gv->id));
        $repShow->assertOk();
        $repShow->assertSee('Dérive de population détectée');
        $repShow->assertSee('Actualiser la proposition');

        // 2. Déclenchement de la re-synchronisation
        $repResync = $this->actingAs($this->adminScolarite)
            ->post(route('admin.evaluation.examens.groupes-virtuels.groupe.resynchroniser', $gv->id));

        // Vérification de la redirection vers la nouvelle proposition
        $repResync->assertRedirect();
        $repResync->assertSessionHas('succes');

        // L'ancienne proposition est archivée avec le statut 'remplace'
        $this->assertDatabaseHas('evaluation_groupes_virtuels', [
            'id' => $gv->id,
            'statut' => 'remplace',
        ]);

        // Une nouvelle proposition active existe avec les 2 étudiants éligibles
        $nouveauGv = GroupeVirtuelExamen::where('epreuve_id', $this->epreuveA->id)
            ->where('statut', 'propose')
            ->firstOrFail();

        $this->assertNotEquals($gv->id, $nouveauGv->id);
        $this->assertSame(2, $nouveauGv->membres()->where('decision', 'eligible')->count());

        // 3. Vérification du filtrage KPI par statut
        $repFiltreDerive = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.groupes-virtuels.index', ['statut' => 'propose']));
        $repFiltreDerive->assertOk();
        $repFiltreDerive->assertSee($nouveauGv->libelle);
    }

    /** 18. Option B — Présence du QR Code vectoriel SVG sur la feuille d'émargement et la fiche */
    public function test_18_option_b_qr_code_vectoriel_sur_documents_et_emargements(): void
    {
        $groupe1 = $this->creerGroupe('TD18', $this->faculteA, $this->programmeA, $this->niveauA);
        $this->creerEtudiant($groupe1, 'CIN23', 'EtudiantQR', 'PrenomQR', true);

        $gv = app(GroupeVirtuelExamenService::class)->proposer($this->epreuveA, [$groupe1->id], $this->adminScolarite->id);

        // 1. Feuille d'émargement officielle
        $repEmargement = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.groupes-virtuels.groupe.imprimer', [$gv->id, 'emargement']));
        $repEmargement->assertOk();

        $contenuEmargement = $repEmargement->getContent();
        $this->assertStringContainsString('<svg', $contenuEmargement);
        $this->assertStringContainsString('Certification UniRegistre', $contenuEmargement);
        $this->assertStringContainsString('émargement', $contenuEmargement);

        // 2. Fiche récapitulative
        $repFiche = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.groupes-virtuels.groupe.imprimer', [$gv->id, 'fiche']));
        $repFiche->assertOk();
        $this->assertStringContainsString('<svg', $repFiche->getContent());
    }

    /** 19. Option B — Téléchargement du pack ZIP des émargements d'une session */
    public function test_19_option_b_telechargement_pack_zip_emargements(): void
    {
        $groupe1 = $this->creerGroupe('TD19', $this->faculteA, $this->programmeA, $this->niveauA);
        $this->creerEtudiant($groupe1, 'CIN24', 'EtudiantZip', 'PrenomZip', true);

        $gv = app(GroupeVirtuelExamenService::class)->proposer($this->epreuveA, [$groupe1->id], $this->adminScolarite->id);

        $repZip = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.groupes-virtuels.pack-zip', [
                'session_id' => $this->sessionA->id,
            ]));

        $repZip->assertOk();
        $this->assertSame('application/zip', $repZip->headers->get('Content-Type'));
        $this->assertStringContainsString('pack_emargements_', $repZip->headers->get('Content-Disposition'));

        // Accès au fichier physique généré par la réponse de téléchargement
        $zipPath = $repZip->baseResponse->getFile()->getPathname();
        $this->assertFileExists($zipPath);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);
        $this->assertNotFalse($zip->locateName('00_BORDEREAU_CENTRE_EXAMEN.txt'));

        $bordereau = $zip->getFromName('00_BORDEREAU_CENTRE_EXAMEN.txt');
        $this->assertStringContainsString('BORDEREAU DE LIVRAISON DU PACK D\'ÉMARGEMENTS', $bordereau);
        $this->assertStringContainsString($gv->libelle, $bordereau);

        $zip->close();
    }

    /** 20. Option C — Passerelle capacitaire D-04 : Salles et jauges associées */
    public function test_20_option_c_passerelle_capacitaire_d04_salles_et_jauges(): void
    {
        $room = Room::create([
            'code' => 'AMPHI-C1',
            'nom' => 'Amphithéâtre Pasteur',
            'capacite' => 120,
            'faculte_id' => $this->faculteA->id,
            'type_salle' => 'amphi',
            'actif' => true,
        ]);

        $this->epreuveA->update(['salle_id' => $room->id]);

        $groupe1 = $this->creerGroupe('TD20', $this->faculteA, $this->programmeA, $this->niveauA);
        $this->creerEtudiant($groupe1, 'CIN25', 'EtudiantCapacite', 'PrenomCap', true);

        $gv = app(GroupeVirtuelExamenService::class)->proposer($this->epreuveA, [$groupe1->id], $this->adminScolarite->id);

        // 1. Index : affichage de la salle, de la jauge et du lien Mode B D-04
        $repIndex = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.groupes-virtuels.index', [
                'session_id' => $this->sessionA->id,
            ]));
        $repIndex->assertOk();
        $repIndex->assertSee('AMPHI-C1');
        $repIndex->assertSee('Cap. 120 pl.');
        $repIndex->assertSee('Salles D-04');

        // 2. Show : fiche détaillée avec le panneau capacitaire D-04
        $repShow = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluation.examens.groupes-virtuels.show', $gv->id));
        $repShow->assertOk();
        $repShow->assertSee('Capacitaire Salles et Amphis (D-04 Mode B)');
        $repShow->assertSee('AMPHI-C1');
        $repShow->assertSee('Jauge suffisante');
        $repShow->assertSee('Ouvrir la répartition D-04 (Mode B)');
    }
}
