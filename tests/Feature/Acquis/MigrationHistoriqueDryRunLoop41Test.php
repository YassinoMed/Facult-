<?php

namespace Tests\Feature\Acquis;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\TypeDiplome;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Services\Acquis\SimulationMigrationHistoriqueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SUITE DE TESTS DU DRY-RUN ET DE LA RÉCONCILIATION HISTORIQUE (LOOP 41).
 *
 * Valide les 10 exigences impératives de la Phase 8 :
 * 1. L'inventaire ne modifie aucune donnée.
 * 2. Le dry-run ne modifie aucune donnée.
 * 3. Le mapping ambigu est bloqué.
 * 4. Une UE similaire n'est pas automatiquement assimilée à une équivalence.
 * 5. Les crédits ne sont jamais dupliqués.
 * 6. Un étudiant ne reçoit pas un acquis provenant d'un autre dossier.
 * 7. Les dossiers interfacultés restent isolés.
 * 8. Une reprise du dry-run reste idempotente.
 * 9. Les cas réglementaires non confirmés sont exclus de la migration automatique.
 * 10. Les empreintes identifient toute altération des sources.
 */
class MigrationHistoriqueDryRunLoop41Test extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteFST;

    private Faculte $faculteENIT;

    private TypeDiplome $typeLicence;

    private TypeDiplome $typeIngenieur;

    private Programme $programmeLicence;

    private Programme $programmeIngenieur;

    private Programme $programmeCoConstruit;

    private AnneeUniversitaire $annee2024;

    private Niveau $niveauL1;

    private Niveau $niveauING1;

    private UniteEnseignement $ueAlgo;

    private Matiere $matiereAlgo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteFST = Faculte::create(['nom' => 'Faculté des Sciences de Tunis', 'code' => 'FST']);
        $this->faculteENIT = Faculte::create(['nom' => 'École Nationale d\'Ingénieurs de Tunis', 'code' => 'ENIT']);

        $this->typeLicence = TypeDiplome::create(['nom' => 'Licence Nationale', 'code' => 'LIC']);
        $this->typeIngenieur = TypeDiplome::create(['nom' => 'Diplôme d\'Ingénieur', 'code' => 'ING']);

        $this->programmeLicence = Programme::create([
            'nom' => 'Licence Nationale en Sciences de l\'Informatique',
            'code' => 'LIC_INFO_STANDARD',
            'type_diplome_id' => $this->typeLicence->id,
            'faculte_id' => $this->faculteFST->id,
        ]);

        $this->programmeIngenieur = Programme::create([
            'nom' => 'Diplôme National d\'Ingénieur en Génie Informatique (ENIT)',
            'code' => 'ING_INFO_ENIT',
            'type_diplome_id' => $this->typeIngenieur->id,
            'faculte_id' => $this->faculteENIT->id,
        ]);

        $this->programmeCoConstruit = Programme::create([
            'nom' => 'Licence Co-construite IA & Embarqué',
            'code' => 'FORMATION_A_VALIDER',
            'type_diplome_id' => $this->typeLicence->id,
            'faculte_id' => $this->faculteFST->id,
        ]);

        $this->annee2024 = AnneeUniversitaire::create([
            'libelle' => '2024-2025',
            'date_debut' => '2024-09-01',
            'date_fin' => '2025-06-30',
            'active' => true,
        ]);

        $this->niveauL1 = Niveau::firstOrCreate(['code' => 'L1', 'programme_id' => $this->programmeLicence->id], ['nom' => 'L1', 'ordre' => 1, 'actif' => true]);
        $this->niveauING1 = Niveau::firstOrCreate(['code' => 'ING1', 'programme_id' => $this->programmeIngenieur->id], ['nom' => 'ING1', 'ordre' => 1, 'actif' => true]);

        $semestreS1 = SemestreLmd::firstOrCreate(
            ['code' => 'S1-ALGO', 'niveau_id' => $this->niveauL1->id],
            ['nom' => 'Semestre 1', 'numero' => 1, 'ordre' => 1, 'actif' => true]
        );

        $this->ueAlgo = UniteEnseignement::create([
            'nom' => 'Algorithmique et Programmation',
            'code' => 'UE-ALGO-101',
            'credits' => 6,
            'programme_id' => $this->programmeLicence->id,
            'semestre_id' => $semestreS1->id,
        ]);

        $this->matiereAlgo = Matiere::create([
            'nom' => 'Algorithmique Avancée',
            'code' => 'ALGO-101',
            'coefficient' => 1.5,
            'credits' => 3,
            'ue_id' => $this->ueAlgo->id,
            'niveau' => 'L1',
            'semestre' => 'S1',
        ]);
    }

    private function creerEtudiant(string $cin, string $nom, string $prenom, ?Faculte $faculte = null, ?Programme $programme = null): Etudiant
    {
        return Etudiant::create([
            'identifiant_national' => 'ID-'.$cin,
            'matricule' => 'ETU-'.strtoupper(substr(md5($cin), 0, 8)),
            'numero_etudiant' => 'NUM-'.strtoupper(substr(md5($cin), 0, 8)),
            'cin' => $cin,
            'nom' => $nom,
            'prenom' => $prenom,
            'email' => strtolower($prenom.'.'.$nom.'@univ.tn'),
            'date_naissance' => '2003-05-15',
            'sexe' => 'M',
            'nationalite' => 'TN',
            'adresse' => 'Campus Universitaire',
            'ville' => 'Tunis',
            'code_postal' => '1060',
            'annee_bac' => 2021,
            'faculte_id' => $faculte ? $faculte->id : $this->faculteFST->id,
            'programme_id' => $programme ? $programme->id : $this->programmeLicence->id,
            'niveau' => 'L1',
            'annee_academique' => '2024-2025',
            'actif' => true,
        ]);
    }

    private function creerNote(Etudiant $etudiant, InscriptionAcademique $inscription, Matiere $matiere, float $valeurNote, string $session = 'normale'): Note
    {
        $semestre = $inscription->semestres()->first() ?? $inscription->semestres()->create([
            'numero' => 1,
            'credits_total' => 30,
            'credits_obtenus' => 15,
        ]);

        return Note::create([
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'semestre_id' => $semestre->id,
            'matiere_id' => $matiere->id,
            'note' => $valeurNote,
            'coefficient' => 1.5,
            'credits' => 3,
            'session' => $session,
        ]);
    }

    public function test_exigence1_et_2_dry_run_ne_modifie_aucune_donnee(): void
    {
        $service = app(SimulationMigrationHistoriqueService::class);
        $etudiant = $this->creerEtudiant('80000001', 'Test', 'ZeroWrite');

        $insc = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee2024->id,
            'programme_id' => $this->programmeLicence->id,
            'niveau_id' => $this->niveauL1->id,
            'niveau' => 'L1',
            'statut' => 'validee',
        ]);
        $this->creerNote($etudiant, $insc, $this->matiereAlgo, 14.5);

        // Comptages initiaux
        $nbEtudiantsAvant = DB::table('etudiants')->count();
        $nbInscriptionsAvant = DB::table('inscriptions_academiques')->count();
        $nbNotesAvant = DB::table('notes')->count();
        $nbPlansAvant = DB::table('parcours_plans')->count();

        // Exécution de la simulation
        $simulation = $service->simulerDossierEtudiant($etudiant);

        // Vérification de l'absence totale d'écritures
        $this->assertEquals($nbEtudiantsAvant, DB::table('etudiants')->count());
        $this->assertEquals($nbInscriptionsAvant, DB::table('inscriptions_academiques')->count());
        $this->assertEquals($nbNotesAvant, DB::table('notes')->count());
        $this->assertEquals($nbPlansAvant, DB::table('parcours_plans')->count());
        $this->assertNotEmpty($simulation['empreinte_source']);
        $this->assertNotEmpty($simulation['empreinte_resultat']);
    }

    public function test_exigence3_et_4_mapping_ambigu_bloque_et_ue_similaire_non_assimilee(): void
    {
        $service = app(SimulationMigrationHistoriqueService::class);

        // Cas A : Codes identiques -> MAPPING_CONFIRME
        $mapExact = $service->cartographierUe($this->ueAlgo, $this->ueAlgo);
        $this->assertEquals('MAPPING_CONFIRME', $mapExact['statut_mapping']);
        $this->assertTrue($mapExact['certifie']);

        // Cas B : Intitulés identiques sans code -> MAPPING_A_VALIDER
        $mapNom = $service->cartographierUe('Algorithmique et Programmation', 'Algorithmique et Programmation');
        $this->assertEquals('MAPPING_A_VALIDER', $mapNom['statut_mapping']);
        $this->assertFalse($mapNom['certifie']);

        // Cas C : Intitulés proches / similaires -> MAPPING_AMBIGU bloqué
        $mapAmbigu = $service->cartographierUe('Algorithmique et Programmation C', 'Algorithmique et Programmation Java');
        $this->assertEquals('MAPPING_AMBIGU', $mapAmbigu['statut_mapping']);
        $this->assertFalse($mapAmbigu['certifie']);
        $this->assertStringContainsString('Similarité textuelle', $mapAmbigu['motif']);
    }

    public function test_exigence5_et_6_credits_non_dupliques_et_etancheite_entre_etudiants(): void
    {
        $service = app(SimulationMigrationHistoriqueService::class);

        $etu1 = $this->creerEtudiant('80000002', 'Etudiant', 'Un');
        $etu2 = $this->creerEtudiant('80000003', 'Etudiant', 'Deux');

        $insc1 = InscriptionAcademique::create(['etudiant_id' => $etu1->id, 'annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeLicence->id, 'niveau_id' => $this->niveauL1->id, 'niveau' => 'L1', 'statut' => 'validee']);
        $sem1 = $insc1->semestres()->create(['numero' => 1, 'credits_total' => 30, 'credits_obtenus' => 15]);

        $insc2 = InscriptionAcademique::create(['etudiant_id' => $etu2->id, 'annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeLicence->id, 'niveau_id' => $this->niveauL1->id, 'niveau' => 'L1', 'statut' => 'validee']);
        $sem2 = $insc2->semestres()->create(['numero' => 1, 'credits_total' => 30, 'credits_obtenus' => 15]);

        // Etudiant 1 a 14.5 en Algo
        $this->creerNote($etu1, $insc1, $this->matiereAlgo, 14.5);
        // Etudiant 2 a 8.0 en Algo
        $this->creerNote($etu2, $insc2, $this->matiereAlgo, 8.0);

        $simul1 = $service->simulerDossierEtudiant($etu1);
        $simul2 = $service->simulerDossierEtudiant($etu2);

        // Etudiant 1 reçoit 3 crédits reconnus
        $this->assertEquals(3, $simul1['resultat_calcule']['total_credits_reconnus']);
        $this->assertCount(1, $simul1['resultat_calcule']['elements_capitalises']);

        // Etudiant 2 ne reçoit AUCUN crédit (étanchéité stricte)
        $this->assertEquals(0, $simul2['resultat_calcule']['total_credits_reconnus']);
        $this->assertCount(0, $simul2['resultat_calcule']['elements_capitalises']);
    }

    public function test_exigence7_isolation_interfacultes(): void
    {
        $service = app(SimulationMigrationHistoriqueService::class);

        $etuFst = $this->creerEtudiant('80000004', 'Faculte', 'Sciences', $this->faculteFST);
        $etuEnit = $this->creerEtudiant('80000005', 'Faculte', 'Ingenieur', $this->faculteENIT);

        $simulFst = $service->simulerDossierEtudiant($etuFst);
        $simulEnit = $service->simulerDossierEtudiant($etuEnit);

        $this->assertEquals($this->faculteFST->id, $simulFst['faculte_id']);
        $this->assertEquals($this->faculteENIT->id, $simulEnit['faculte_id']);
        $this->assertNotEquals($simulFst['faculte_id'], $simulEnit['faculte_id']);
    }

    public function test_exigence8_idempotence_et_reprise_dry_run(): void
    {
        $service = app(SimulationMigrationHistoriqueService::class);
        $etudiant = $this->creerEtudiant('80000006', 'Reprise', 'Idempotente');

        $insc = InscriptionAcademique::create(['etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeLicence->id, 'niveau_id' => $this->niveauL1->id, 'niveau' => 'L1', 'statut' => 'validee']);
        $this->creerNote($etudiant, $insc, $this->matiereAlgo, 12.0);

        // 1ère simulation
        $run1 = $service->simulerDossierEtudiant($etudiant);
        // 2ème simulation (reprise)
        $run2 = $service->simulerDossierEtudiant($etudiant);

        // Les deux exécutions doivent produire exactement les mêmes empreintes
        $this->assertEquals($run1['empreinte_source'], $run2['empreinte_source']);
        $this->assertEquals($run1['empreinte_resultat'], $run2['empreinte_resultat']);
        $this->assertEquals($run1['statut_migration'], $run2['statut_migration']);
    }

    public function test_exigence9_cas_reglementaires_non_confirmes_exclus_de_la_migration_automatique(): void
    {
        $service = app(SimulationMigrationHistoriqueService::class);

        // Formation pilote en co-construction (REGLE_A_VALIDER)
        $etuCo = $this->creerEtudiant('80000007', 'Pilote', 'CoConstruit', null, $this->programmeCoConstruit);
        InscriptionAcademique::create(['etudiant_id' => $etuCo->id, 'annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeCoConstruit->id, 'niveau_id' => $this->niveauL1->id, 'niveau' => 'L1', 'statut' => 'validee']);

        $simulCo = $service->simulerDossierEtudiant($etuCo);

        // Ne doit JAMAIS être classifié en MIGRABLE_AUTOMATIQUEMENT
        $this->assertNotEquals('MIGRABLE_AUTOMATIQUEMENT_SOUS_REGLE_CONFIRME', $simulCo['statut_migration']);
        $this->assertEquals('MIGRABLE_APRES_VALIDATION', $simulCo['statut_migration']);
        $this->assertStringContainsString('en attente d\'homologation ministérielle', $simulCo['justification']);
    }

    /**
     * ANOM-41-02 — l'absence d'acte officiel de décision ne doit jamais
     * produire un transfert automatique de crédits ni une exclusion :
     * le dossier est classé MIGRABLE_APRES_VALIDATION avec un avertissement
     * explicite, et la note reste intacte.
     */
    public function test_anom41_02_absence_de_decision_officielle_exige_validation(): void
    {
        $service = app(SimulationMigrationHistoriqueService::class);

        $etudiant = $this->creerEtudiant('80000009', 'Sans', 'Decision');
        $insc = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee2024->id,
            'programme_id' => $this->programmeLicence->id,
            'niveau_id' => $this->niveauL1->id,
            'niveau' => 'L1',
            'statut' => 'validee',
        ]);
        $this->creerNote($etudiant, $insc, $this->matiereAlgo, 14.0);

        $simulation = $service->simulerDossierEtudiant($etudiant);

        $this->assertEquals('MIGRABLE_APRES_VALIDATION', $simulation['statut_migration']);
        $this->assertStringContainsString('ANOM-41-02', $simulation['justification']);
        $this->assertFalse($simulation['resultat_calcule']['sources_officielles']['source_officielle_suffisante']);
        $this->assertNotEmpty($simulation['avertissements']);

        // Aucune exclusion, aucune sanction, aucune écriture.
        $this->assertEquals(14.0, (float) $etudiant->notesAcademiques()->first()->note);
        $this->assertNotContains('EXCLU', [$simulation['statut_migration']]);
    }

    /**
     * Une décision textuelle d'origine suffit à rendre la source officielle
     * suffisante pour le transfert simulé (elle reste à normaliser).
     */
    public function test_anom41_02_decision_textuelle_origine_suffit_a_la_simulation(): void
    {
        $service = app(SimulationMigrationHistoriqueService::class);

        $etudiant = $this->creerEtudiant('80000010', 'Avec', 'Decision');
        $insc = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee2024->id,
            'programme_id' => $this->programmeLicence->id,
            'niveau_id' => $this->niveauL1->id,
            'niveau' => 'L1',
            'statut' => 'validee',
            'decision' => 'admis',
        ]);
        $this->creerNote($etudiant, $insc, $this->matiereAlgo, 12.0);

        $simulation = $service->simulerDossierEtudiant($etudiant);

        $this->assertTrue($simulation['resultat_calcule']['sources_officielles']['decision_textuelle_origine']);
        $this->assertEquals('MIGRABLE_AUTOMATIQUEMENT_SOUS_REGLE_CONFIRME', $simulation['statut_migration']);
        $this->assertEmpty($simulation['avertissements']);
    }

    public function test_exigence10_empreinte_detecte_toute_alteration_source(): void
    {
        $service = app(SimulationMigrationHistoriqueService::class);
        $etudiant = $this->creerEtudiant('80000008', 'Alteration', 'Empreinte');

        $insc = InscriptionAcademique::create(['etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeLicence->id, 'niveau_id' => $this->niveauL1->id, 'niveau' => 'L1', 'statut' => 'validee']);
        $note = $this->creerNote($etudiant, $insc, $this->matiereAlgo, 11.0);

        $runInitial = $service->simulerDossierEtudiant($etudiant);
        $hashInitial = $runInitial['empreinte_source'];

        // Modification de la note source (ex. rectification scolarité de 11.0 à 13.0)
        $note->update(['note' => 13.0]);

        $runApresModif = $service->simulerDossierEtudiant($etudiant);
        $hashApresModif = $runApresModif['empreinte_source'];

        // L'empreinte source DOIT avoir changé
        $this->assertNotEquals($hashInitial, $hashApresModif, 'Une modification de note source doit impérativement altérer l\'empreinte source.');
    }
}
