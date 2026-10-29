<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DossierCorrection;
use App\Models\DossierVerification;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use App\Services\FileTraitementService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LOOP 18 — Tableau de bord administratif et file de traitement.
 *
 * Vérifie :
 * - Sécurité et permissions : contrôle d'accès strict côté serveur.
 * - Scoping faculté étanche : isolation absolue des données entre facultés.
 * - Actions attendues : A_INSTRUIRE, COMPLEMENT_ATTENDU, ANOMALIE_DETECTEE, A_AFFECTER, TRAITE.
 * - Tri par ancienneté (FIFO par défaut / LIFO) et filtre depose_depuis.
 * - Mesure objective des délais et détection des dossiers en souffrance (> 48h).
 * - Fiche structurée, notes d'instruction avec motifs normalisés et traçabilité d'audit.
 * - Zéro N+1 et pagination correcte.
 */
class InscriptionFileTraitementTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private User $scolariteGlobal;

    private User $agentFaculteA;

    private User $agentFaculteB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté des Sciences de Tunis',
            'code' => 'FST',
            'active' => true,
        ]);

        $this->faculteB = Faculte::create([
            'nom' => 'Faculté de Médecine de Tunis',
            'code' => 'FMT',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->scolariteGlobal = User::creerCompteBackOffice([
            'name' => 'Agent Scolarité Central',
            'email' => 'scolarite.central@universite.tn',
            'password' => 'Secret123!',
        ], 'admin_scolarite');

        $this->agentFaculteA = User::creerCompteBackOffice([
            'name' => 'Agent Scolarité FST',
            'email' => 'agent.fst@universite.tn',
            'password' => 'Secret123!',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $this->agentFaculteB = User::creerCompteBackOffice([
            'name' => 'Agent Scolarité FMT',
            'email' => 'agent.fmt@universite.tn',
            'password' => 'Secret123!',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');
    }

    private function creerEtudiant(array $attributs = []): Etudiant
    {
        static $compteur = 1;

        $statut = $attributs['statut'] ?? 'en_attente';
        unset($attributs['statut']);

        $defauts = [
            'nom' => 'Nom'.$compteur,
            'prenom' => 'Prenom'.$compteur,
            'email' => "etudiant{$compteur}@test.tn",
            'cin' => sprintf('100000%02d', $compteur),
            'sexe' => 'M',
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '10 Rue de la République',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2025',
            'faculte_id' => $this->faculteA->id,
        ];

        $compteur++;

        $etudiant = Etudiant::create(array_merge($defauts, $attributs));
        if ($statut !== 'en_attente') {
            $etudiant->definirStatut($statut);
        }

        return $etudiant;
    }

    /**
     * Critère 1 : Accès protégé et permissions contrôlées côté serveur.
     */
    public function test_acces_securise_et_habilitation_serveur(): void
    {
        // 1. Invité non authentifié redirigé vers login
        $responseInvite = $this->get(route('admin.inscriptions.file-traitement'));
        $responseInvite->assertRedirect(route('admin.connexion.show'));

        // 2. Agent scolarité authentifié accède avec succès
        $responseAgent = $this->actingAs($this->scolariteGlobal)
            ->get(route('admin.inscriptions.file-traitement'));
        $responseAgent->assertOk();
        $responseAgent->assertViewIs('admin.inscriptions.file-traitement');
        $responseAgent->assertSee('File administrative des inscriptions');
    }

    /**
     * Critère 2 : Scoping faculté étanche appliqué sur les listes et indicateurs.
     */
    public function test_scoping_faculte_etanche_sur_la_file_et_kpis(): void
    {
        // Étudiant Faculté A
        $this->creerEtudiant([
            'nom' => 'Ben Salem',
            'prenom' => 'Ahmed',
            'email' => 'ahmed.fst@test.tn',
            'cin' => '11223344',
            'faculte_id' => $this->faculteA->id,
        ]);

        // Étudiant Faculté B
        $this->creerEtudiant([
            'nom' => 'Trabelsi',
            'prenom' => 'Sarra',
            'email' => 'sarra.fmt@test.tn',
            'cin' => '99887766',
            'sexe' => 'F',
            'faculte_id' => $this->faculteB->id,
        ]);

        // L'agent de la Faculté A ne doit voir QUE l'étudiant A
        $response = $this->actingAs($this->agentFaculteA)
            ->get(route('admin.inscriptions.file-traitement'));

        $response->assertOk();
        $response->assertSee('Ben Salem');
        $response->assertDontSee('Trabelsi');

        // Les KPIs pour l'agent Faculté A doivent comptabiliser uniquement la Faculté A
        $kpis = $response->viewData('kpis');
        $this->assertSame(1, $kpis['total']);
        $this->assertSame(1, $kpis['en_attente']);
    }

    /**
     * Critère 3 : Détermination objective des 5 actions attendues et filtrage associé.
     */
    public function test_determination_des_actions_attendues_et_filtres(): void
    {
        $groupe = Group::create([
            'code' => 'L1-INFO-A',
            'nom' => 'Groupe L1 Informatique A',
            'niveau' => 'L1',
            'faculte_id' => $this->faculteA->id,
        ]);

        // 1. A_INSTRUIRE : en attente, aucune anomalie ni complément
        $eInstruire = $this->creerEtudiant([
            'nom' => 'Aouadi', 'prenom' => 'Karim', 'email' => 'karim@test.tn',
        ]);

        // 2. COMPLEMENT_ATTENDU : en attente, avec correction proposée ouverte
        $eComplement = $this->creerEtudiant([
            'nom' => 'Gharbi', 'prenom' => 'Leila', 'email' => 'leila@test.tn',
        ]);
        DossierCorrection::create([
            'etudiant_id' => $eComplement->id,
            'champ' => 'nom',
            'ancienne_valeur' => 'Gharbi',
            'nouvelle_valeur' => 'El Gharbi',
            'statut' => 'proposee',
            'motif' => 'Correction orthographe état civil',
            'propose_par' => $this->scolariteGlobal->id,
        ]);

        // 3. ANOMALIE_DETECTEE : en attente, dernier run BLOQUANT
        $eAnomalie = $this->creerEtudiant([
            'nom' => 'Hammami', 'prenom' => 'Bilel', 'email' => 'bilel@test.tn',
        ]);
        DossierVerification::create([
            'etudiant_id' => $eAnomalie->id,
            'verdict' => 'BLOQUANT',
            'score' => 45,
            'regles_verifiees' => 10,
            'regles_reussies' => 6,
            'anomalies_count' => 2,
            'duree_ms' => 12,
            'source' => 'web',
        ]);

        // 4. A_AFFECTER : validé mais groupe_id null
        $eAffecter = $this->creerEtudiant([
            'nom' => 'Jlassi', 'prenom' => 'Mouna', 'email' => 'mouna@test.tn', 'statut' => 'valide', 'groupe_id' => null,
        ]);

        // 5. TRAITE : validé avec groupe
        $eTraite = $this->creerEtudiant([
            'nom' => 'Kefi', 'prenom' => 'Yassine', 'email' => 'yassine@test.tn', 'statut' => 'valide', 'groupe_id' => $groupe->id,
        ]);

        $service = app(FileTraitementService::class);
        $this->assertSame(FileTraitementService::ACTION_A_INSTRUIRE, $service->determinerActionAttendue($eInstruire));
        $this->assertSame(FileTraitementService::ACTION_COMPLEMENT_ATTENDU, $service->determinerActionAttendue($eComplement, true));
        $this->assertSame(FileTraitementService::ACTION_ANOMALIE_DETECTEE, $service->determinerActionAttendue($eAnomalie, false, 'BLOQUANT'));
        $this->assertSame(FileTraitementService::ACTION_A_AFFECTER, $service->determinerActionAttendue($eAffecter));
        $this->assertSame(FileTraitementService::ACTION_TRAITE, $service->determinerActionAttendue($eTraite));

        // Test du filtrage HTTP par action attendue
        $respFiltreComplement = $this->actingAs($this->scolariteGlobal)
            ->get(route('admin.inscriptions.file-traitement', ['action_attendue' => 'COMPLEMENT_ATTENDU']));
        $respFiltreComplement->assertSee('Gharbi');
        $respFiltreComplement->assertDontSee('Aouadi');

        $respFiltreAffecter = $this->actingAs($this->scolariteGlobal)
            ->get(route('admin.inscriptions.file-traitement', ['action_attendue' => 'A_AFFECTER']));
        $respFiltreAffecter->assertSee('Jlassi');
        $respFiltreAffecter->assertDontSee('Kefi');
    }

    /**
     * Critère 4 : Tri par ancienneté (FIFO par défaut) et LIFO.
     */
    public function test_tri_par_anciennete_fifo_et_lifo(): void
    {
        $ancien = $this->creerEtudiant([
            'nom' => 'AncienDossier', 'prenom' => 'Ali', 'email' => 'ali.ancien@test.tn',
        ]);
        // Forcer created_at à 5 jours en arrière
        $ancien->timestamps = false;
        $ancien->created_at = Carbon::now()->subDays(5);
        $ancien->save();

        $recent = $this->creerEtudiant([
            'nom' => 'RecentDossier', 'prenom' => 'Nour', 'email' => 'nour.recent@test.tn',
        ]);
        $recent->timestamps = false;
        $recent->created_at = Carbon::now()->subHours(2);
        $recent->save();

        // 1. FIFO (défaut) : le dossier ancien doit apparaître en premier
        $respFifo = $this->actingAs($this->scolariteGlobal)
            ->get(route('admin.inscriptions.file-traitement'));
        $respFifo->assertOk();
        $etudiantsFifo = $respFifo->viewData('etudiants')->items();
        $this->assertSame('AncienDossier', $etudiantsFifo[0]->nom);
        $this->assertSame('RecentDossier', $etudiantsFifo[1]->nom);

        // 2. LIFO (sens=desc) : le dossier récent doit apparaître en premier
        $respLifo = $this->actingAs($this->scolariteGlobal)
            ->get(route('admin.inscriptions.file-traitement', ['sens' => 'desc']));
        $respLifo->assertOk();
        $etudiantsLifo = $respLifo->viewData('etudiants')->items();
        $this->assertSame('RecentDossier', $etudiantsLifo[0]->nom);
        $this->assertSame('AncienDossier', $etudiantsLifo[1]->nom);
    }

    /**
     * Critère 5 : Détection des dossiers en souffrance (> 48h) et filtre depose_depuis.
     */
    public function test_detection_souffrance_48h_et_filtre_depose_depuis(): void
    {
        // Dossier en attente depuis 72 heures (> 48h -> en souffrance)
        $souffrant = $this->creerEtudiant([
            'nom' => 'DossierRetard', 'prenom' => 'Hedi', 'email' => 'hedi@test.tn',
        ]);
        $souffrant->timestamps = false;
        $souffrant->created_at = Carbon::now()->subHours(72);
        $souffrant->save();

        // Dossier en attente depuis 12 heures (< 48h -> normal)
        $normal = $this->creerEtudiant([
            'nom' => 'DossierNormal', 'prenom' => 'Sonia', 'email' => 'sonia@test.tn',
        ]);
        $normal->timestamps = false;
        $normal->created_at = Carbon::now()->subHours(12);
        $normal->save();

        $response = $this->actingAs($this->scolariteGlobal)
            ->get(route('admin.inscriptions.file-traitement'));

        $kpis = $response->viewData('kpis');
        $this->assertGreaterThanOrEqual(1, $kpis['souffrance_48h']);

        // Test du filtre depose_depuis=2 (au moins 2 jours / 48h)
        $respFiltre = $this->actingAs($this->scolariteGlobal)
            ->get(route('admin.inscriptions.file-traitement', ['depose_depuis' => 2]));

        $respFiltre->assertSee('DossierRetard');
        $respFiltre->assertDontSee('DossierNormal');
    }

    /**
     * Critère 6 : Ajout de note d'instruction administrative avec motif normalisé et traçabilité.
     */
    public function test_ajout_note_instruction_administrative_tracée(): void
    {
        $etudiant = $this->creerEtudiant([
            'nom' => 'Bouazizi', 'prenom' => 'Tarek', 'email' => 'tarek@test.tn',
        ]);

        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1',
            'filiere' => 'Informatique',
            'statut' => 'en_cours',
        ]);

        $response = $this->actingAs($this->scolariteGlobal)
            ->post(route('admin.inscriptions.file-traitement.commenter', $etudiant), [
                'motif_cle' => 'PIECE_IDENTITE_NON_CONFORME',
                'commentaire' => 'CIN scannée recto uniquement, verso obligatoire pour validation.',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');

        // 1. Trace dans l'audit académique
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $etudiant->id,
            'action' => HistoriqueAcademique::ACTION_COMMENTAIRE_INSTRUCTION,
        ]);

        // 2. Observation sur l'inscription académique mise à jour
        $inscription->refresh();
        $this->assertStringContainsString('CIN scannée recto uniquement', (string) $inscription->observation);
        $this->assertStringContainsString('Pièce d’identité illisible', (string) $inscription->observation);
    }

    /**
     * Critère 7 : Rejet des actions croisées hors faculté (anti-IDOR).
     */
    public function test_rejet_action_hors_faculte(): void
    {
        $etudiantB = $this->creerEtudiant([
            'nom' => 'DossierFacB', 'prenom' => 'Walid', 'email' => 'walid@test.tn',
            'faculte_id' => $this->faculteB->id,
        ]);

        // L'agent de la Faculté A ne doit pas pouvoir commenter un dossier de la Faculté B
        $response = $this->actingAs($this->agentFaculteA)
            ->post(route('admin.inscriptions.file-traitement.commenter', $etudiantB), [
                'commentaire' => 'Tentative de commentaire non autorisée',
            ]);

        $response->assertForbidden();
    }

    /**
     * Critère 8 : Absence de problème N+1 sur la pagination et chargement groupé.
     */
    public function test_absence_de_requetes_n_plus_un_sur_la_file(): void
    {
        // Créer 10 étudiants avec inscriptions
        for ($i = 1; $i <= 10; $i++) {
            $e = $this->creerEtudiant([
                'nom' => "Etudiant{$i}", 'prenom' => 'Test', 'email' => "etudiant{$i}@test.tn",
                'cin' => sprintf('600000%02d', $i),
            ]);
            InscriptionAcademique::create([
                'etudiant_id' => $e->id,
                'annee_universitaire_id' => $this->annee->id,
                'niveau' => 'L1',
                'statut' => 'en_cours',
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($this->scolariteGlobal)
            ->get(route('admin.inscriptions.file-traitement'));
        $response->assertOk();

        $queryCount = count(DB::getQueryLog());
        // Budget 25 : KPIs + pagination + eager loading + enrichissement
        // + 2 requêtes de référentiels (facultés/programmes) strictement
        // scopés. Le nombre est constant avec 10 dossiers : aucune N+1.
        $this->assertLessThanOrEqual(25, $queryCount, "Requêtes excessives sur la file de traitement : {$queryCount}");
    }
}
