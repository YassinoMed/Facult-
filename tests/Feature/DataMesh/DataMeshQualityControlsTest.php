<?php

namespace Tests\Feature\DataMesh;

use App\DataMesh\Qualite\ControlesDonnees;
use App\Models\AffectationGroupe;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests des 8 contrôles de qualité des données Data Mesh (§9, §11).
 */
class DataMeshQualityControlsTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private User $admin;

    private AnneeUniversitaire $annee;

    private ControlesDonnees $moteurQualite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteA = Faculte::create(['nom' => 'Faculté A', 'code' => 'FACA', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'Faculté B', 'code' => 'FACB', 'active' => true]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Auditeur Qualité',
            'email' => 'qualite@univ.test',
            'password' => 'Motdepasse@123',
        ], 'super_admin');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->moteurQualite = new ControlesDonnees;
    }

    public function test_detecte_inscriptions_sans_programme_valide(): void
    {
        $etudiant = $this->creerEtudiant('ETU-PROG-01', $this->faculteA->id, 'L1');

        // Inscription sans programme_id
        InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1',
            'programme_id' => null,
            'statut' => 'valide',
        ]);

        $rapport = $this->moteurQualite->controler($this->admin, $this->faculteA->id, $this->annee->id);
        $resultat = collect($rapport['resultats'])->firstWhere('regle', 'inscriptions_sans_programme');

        $this->assertNotNull($resultat);
        $this->assertSame(1, $resultat['anomalies']);
        $this->assertSame('bloquant', $resultat['gravite']);
        $this->assertNotEmpty($resultat['exemples']);
    }

    public function test_detecte_references_lmd_incoherentes(): void
    {
        $etudiant = $this->creerEtudiant('ETU-LMD-01', $this->faculteA->id, 'INCONNU');

        InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'NIVEAU_INEXISTANT',
            'statut' => 'valide',
        ]);

        $rapport = $this->moteurQualite->controler($this->admin, $this->faculteA->id, $this->annee->id);
        $resultat = collect($rapport['resultats'])->firstWhere('regle', 'references_lmd_incoherentes');

        $this->assertNotNull($resultat);
        $this->assertSame(1, $resultat['anomalies']);
        $this->assertSame('bloquant', $resultat['gravite']);
    }

    public function test_detecte_affectations_incompatibles_inter_facultes(): void
    {
        $etudiant = $this->creerEtudiant('ETU-INC-01', $this->faculteA->id, 'L1');

        // Groupe appartenant à la faculté B
        $groupeB = Group::create([
            'code' => 'FACB-G1',
            'nom' => 'Groupe B',
            'faculte_id' => $this->faculteB->id,
            'niveau' => 'L1',
            'actif' => true,
        ]);

        // Affectation inter-facultés incohérente
        AffectationGroupe::create([
            'etudiant_id' => $etudiant->id,
            'groupe_id' => $groupeB->id,
            'annee_universitaire_id' => $this->annee->id,
            'date_debut' => '2026-09-01',
            'statut' => 'active',
        ]);

        $rapport = $this->moteurQualite->controler($this->admin, null, $this->annee->id);
        $resultat = collect($rapport['resultats'])->firstWhere('regle', 'affectations_incompatibles');

        $this->assertNotNull($resultat);
        $this->assertGreaterThanOrEqual(1, $resultat['anomalies']);
        $this->assertSame('bloquant', $resultat['gravite']);
    }

    public function test_detecte_doublons_d_affectations(): void
    {
        $etudiant = $this->creerEtudiant('ETU-DOUBLON', $this->faculteA->id, 'L1');

        $g1 = Group::create(['code' => 'G1', 'nom' => 'G1', 'niveau' => 'L1', 'faculte_id' => $this->faculteA->id, 'actif' => true]);
        $g2 = Group::create(['code' => 'G2', 'nom' => 'G2', 'niveau' => 'L1', 'faculte_id' => $this->faculteA->id, 'actif' => true]);

        // 2 affectations actives pour la même année
        AffectationGroupe::create([
            'etudiant_id' => $etudiant->id,
            'groupe_id' => $g1->id,
            'annee_universitaire_id' => $this->annee->id,
            'date_debut' => '2026-09-01',
            'statut' => 'active',
        ]);
        AffectationGroupe::create([
            'etudiant_id' => $etudiant->id,
            'groupe_id' => $g2->id,
            'annee_universitaire_id' => $this->annee->id,
            'date_debut' => '2026-09-01',
            'statut' => 'active',
        ]);

        $rapport = $this->moteurQualite->controler($this->admin, $this->faculteA->id, $this->annee->id);
        $resultat = collect($rapport['resultats'])->firstWhere('regle', 'doublons_affectations');

        $this->assertNotNull($resultat);
        $this->assertSame(1, $resultat['anomalies']);
        $this->assertSame('bloquant', $resultat['gravite']);
    }

    public function test_detecte_groupes_surcharges_et_capacites_inconnues(): void
    {
        // Groupe avec capacité dépassée
        $groupePlein = Group::create([
            'code' => 'G-SURCHARGE',
            'nom' => 'G Surchargé',
            'niveau' => 'L1',
            'faculte_id' => $this->faculteA->id,
            'capacite' => 1,
            'actif' => true,
        ]);

        // Groupe sans capacité renseignée
        Group::create([
            'code' => 'G-INCONNUE',
            'nom' => 'G Inconnue',
            'niveau' => 'L1',
            'faculte_id' => $this->faculteA->id,
            'capacite' => null,
            'actif' => true,
        ]);

        $e1 = $this->creerEtudiant('ETU-S1', $this->faculteA->id, 'L1');
        $e2 = $this->creerEtudiant('ETU-S2', $this->faculteA->id, 'L1');

        AffectationGroupe::create(['etudiant_id' => $e1->id, 'groupe_id' => $groupePlein->id, 'annee_universitaire_id' => $this->annee->id, 'date_debut' => '2026-09-01', 'statut' => 'active']);
        AffectationGroupe::create(['etudiant_id' => $e2->id, 'groupe_id' => $groupePlein->id, 'annee_universitaire_id' => $this->annee->id, 'date_debut' => '2026-09-01', 'statut' => 'active']);

        $rapport = $this->moteurQualite->controler($this->admin, $this->faculteA->id, $this->annee->id);

        $surcharge = collect($rapport['resultats'])->firstWhere('regle', 'groupes_surcharges');
        $this->assertSame(1, $surcharge['anomalies']);
        $this->assertSame('bloquant', $surcharge['gravite']);

        $inconnue = collect($rapport['resultats'])->firstWhere('regle', 'capacites_inconnues');
        $this->assertGreaterThanOrEqual(1, $inconnue['anomalies']);
        $this->assertSame('avertissement', $inconnue['gravite']);
    }

    public function test_synchronisation_vers_anomalies_qualite_persiste_les_ecarts(): void
    {
        $etudiant = $this->creerEtudiant('ETU-SYNCHRO', $this->faculteA->id, 'L1');

        // Inscription sans programme
        InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1',
            'programme_id' => null,
            'statut' => 'valide',
        ]);

        $nbSync = $this->moteurQualite->synchroniserVersAnomalies($this->admin, $this->faculteA->id, $this->annee->id);

        $this->assertGreaterThan(0, $nbSync);
        $this->assertDatabaseHas('anomalies_qualite', [
            'source' => 'data_mesh_quality',
            'statut' => 'ouverte',
        ]);
    }

    private function creerEtudiant(string $cin, int $faculteId, string $niveau): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => 'Nom'.$cin,
            'prenom' => 'Prenom'.$cin,
            'email' => strtolower($cin).'@univ.test',
            'date_naissance' => '2004-01-01',
            'sexe' => 'F',
            'faculte_id' => $faculteId,
            'niveau' => $niveau,
            'annee_academique' => '2026-2027',
            'adresse' => 'Campus',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2023',
            'password' => 'Pass@123',
        ]);
        $etudiant->definirStatut('valide');

        return $etudiant;
    }

    public function test_triptyque_capacites_definie_illimitee_inconnue(): void
    {
        $defini = Group::create([
            'code' => 'TRI-FINI', 'nom' => 'Fini', 'faculte_id' => $this->faculteA->id,
            'niveau' => 'L1', 'capacite' => 30, 'actif' => true,
        ]);
        $illimite = Group::create([
            'code' => 'TRI-INF', 'nom' => 'Infini', 'faculte_id' => $this->faculteA->id,
            'niveau' => 'L1', 'capacite' => null, 'capacite_illimitee' => true, 'actif' => true,
        ]);
        $inconnu = Group::create([
            'code' => 'TRI-INC', 'nom' => 'Inconnu', 'faculte_id' => $this->faculteA->id,
            'niveau' => 'L1', 'capacite' => null, 'actif' => true,
        ]);

        $this->assertSame('DEFINIE', $defini->modeCapacite());
        $this->assertSame('ILLIMITEE', $illimite->modeCapacite());
        $this->assertSame('INCONNUE', $inconnu->modeCapacite());

        // Règle moteur inchangée (décision de bascule en attente) : ni le
        // groupe inconnu ni l'illimité explicite ne sont déclarés complets.
        $this->assertFalse($inconnu->estComplet());
        $this->assertFalse($illimite->estComplet());

        // Qualité : seul l'INCONNU est signalé, jamais l'ILLIMITE explicite.
        $rapport = $this->moteurQualite->controler($this->admin, $this->faculteA->id, $this->annee->id);
        $inconnues = collect($rapport['resultats'])->firstWhere('regle', 'capacites_inconnues');
        $this->assertContains('TRI-INC', $inconnues['exemples']);
        $this->assertNotContains('TRI-INF', $inconnues['exemples']);
    }

    public function test_capacites_zero_et_negatives_restent_finies(): void
    {
        $zero = Group::create([
            'code' => 'TRI-ZERO', 'nom' => 'Zéro', 'faculte_id' => $this->faculteA->id,
            'niveau' => 'L1', 'capacite' => 0, 'actif' => true,
        ]);

        // 0 est une capacité FINIE (pas une absence) : le groupe est complet d'office.
        $this->assertSame('DEFINIE', $zero->modeCapacite());
        $this->assertTrue($zero->estComplet());
    }
}
