<?php

namespace Tests\Feature\Acquis;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\TypeDiplome;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Services\Acquis\MoteurDecisionAcademiqueService;
use App\Services\Acquis\PilotAcademiqueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * LOOP 42 — PILOTE ACADÉMIQUE CONTRÔLÉ.
 *
 * Vérifie :
 * - le fail-closed (pilote inactif, validation absente, périmètre vide, mode non supporté) ;
 * - l'isolation inter-facultés ;
 * - la double calculation moteur vs référence officielle ;
 * - les 7 classes de divergence ;
 * - les dénominateurs explicites des indicateurs ;
 * - l'absence totale d'écriture.
 */
class PiloteAcademiqueLoop42Test extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteFST;

    private Faculte $faculteENIT;

    private Programme $programmeLicence;

    private AnneeUniversitaire $annee2024;

    private Niveau $niveauL1;

    private UniteEnseignement $ueAlgo;

    private Matiere $matiereAlgo;

    private Deliberation $deliberation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteFST = Faculte::create(['nom' => 'Faculté des Sciences de Tunis', 'code' => 'FST']);
        $this->faculteENIT = Faculte::create(['nom' => 'École Nationale d\'Ingénieurs de Tunis', 'code' => 'ENIT']);

        $typeLicence = TypeDiplome::create(['nom' => 'Licence Nationale', 'code' => 'LIC']);

        $this->programmeLicence = Programme::create([
            'nom' => 'Licence Nationale en Sciences de l\'Informatique',
            'code' => 'LIC_INFO_STANDARD',
            'type_diplome_id' => $typeLicence->id,
            'faculte_id' => $this->faculteFST->id,
        ]);

        $this->annee2024 = AnneeUniversitaire::create([
            'libelle' => '2024-2025',
            'date_debut' => '2024-09-01',
            'date_fin' => '2025-06-30',
            'active' => true,
        ]);

        $this->niveauL1 = Niveau::create([
            'code' => 'L1',
            'nom' => 'L1',
            'ordre' => 1,
            'actif' => true,
            'programme_id' => $this->programmeLicence->id,
        ]);

        $semestre = SemestreLmd::create([
            'code' => 'S1-PILOTE',
            'nom' => 'Semestre 1',
            'numero' => 1,
            'ordre' => 1,
            'actif' => true,
            'niveau_id' => $this->niveauL1->id,
        ]);

        $this->ueAlgo = UniteEnseignement::create([
            'nom' => 'Algorithmique et Programmation',
            'code' => 'UE-ALGO-101',
            'credits' => 6,
            'programme_id' => $this->programmeLicence->id,
            'semestre_id' => $semestre->id,
            'actif' => true,
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

        $this->deliberation = Deliberation::create([
            'annee_universitaire_id' => $this->annee2024->id,
            'programme_id' => $this->programmeLicence->id,
            'niveau_id' => $this->niveauL1->id,
            'statut' => 'published',
            'published_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function configurerPilote(array $surcharges = []): void
    {
        config(array_merge([
            'pilote_academique.actif' => true,
            'pilote_academique.statut_validation' => 'VALIDE',
            'pilote_academique.reference_validation' => 'DECISION-TEST-2026-001',
            'pilote_academique.mode' => 'SIMULATION',
            'pilote_academique.limite_dossiers' => null,
            'pilote_academique.perimetre' => [
                'facultes' => [$this->faculteFST->id],
                'programmes' => [],
                'annees' => [],
                'niveaux' => [],
                'etudiants' => [],
            ],
        ], $surcharges));
    }

    private function creerEtudiant(string $cin, ?Faculte $faculte = null): Etudiant
    {
        return Etudiant::create([
            'identifiant_national' => 'ID-'.$cin,
            'matricule' => 'ETU-'.strtoupper(substr(md5($cin), 0, 8)),
            'numero_etudiant' => 'NUM-'.strtoupper(substr(md5($cin), 0, 8)),
            'num_inscription' => 'INS-'.$cin,
            'cin' => $cin,
            'nom' => 'Test',
            'prenom' => 'Pilote',
            'email' => strtolower($cin).'@univ.tn',
            'date_naissance' => '2003-05-15',
            'sexe' => 'M',
            'nationalite' => 'TN',
            'adresse' => 'Campus Universitaire',
            'ville' => 'Tunis',
            'code_postal' => '1060',
            'annee_bac' => 2021,
            'faculte_id' => ($faculte ?? $this->faculteFST)->id,
            'programme_id' => $this->programmeLicence->id,
            'niveau' => 'L1',
            'annee_academique' => '2024-2025',
            'actif' => true,
        ]);
    }

    /**
     * @return array{0: Etudiant, 1: InscriptionAcademique}
     */
    private function creerDossier(string $cin, float $note = 14.5, ?Faculte $faculte = null): array
    {
        $etudiant = $this->creerEtudiant($cin, $faculte);

        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee2024->id,
            'programme_id' => $this->programmeLicence->id,
            'niveau_id' => $this->niveauL1->id,
            'niveau' => 'L1',
            'statut' => 'validee',
        ]);

        $inscription->semestres()->create([
            'numero' => 1,
            'credits_total' => 30,
            'credits_obtenus' => 6,
        ]);

        Note::create([
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'semestre_id' => $inscription->semestres()->first()->id,
            'matiere_id' => $this->matiereAlgo->id,
            'note' => $note,
            'coefficient' => 1.5,
            'credits' => 3,
            'session' => 'normale',
        ]);

        return [$etudiant, $inscription];
    }

    /**
     * Crée une décision officielle publiée avec les valeurs transmises.
     */
    private function creerDecision(
        Etudiant $etudiant,
        InscriptionAcademique $inscription,
        int $credits,
        int $ues,
        ?string $decision = 'admis',
        ?float $moyenne = null,
        bool $publie = true
    ): DecisionDeliberation {
        return DecisionDeliberation::create([
            'deliberation_id' => $this->deliberation->id,
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'moyenne' => $moyenne,
            'credits_obtenus' => $credits,
            'credits_total' => 30,
            'ues_validees' => $ues,
            'ues_total' => 1,
            'decision' => $decision,
            'publie' => $publie,
        ]);
    }

    /**
     * Calcule les valeurs réelles du moteur pour construire une référence
     * officielle strictement concordante.
     *
     * @return array{credits: int, ues: int}
     */
    private function mesuresMoteur(Etudiant $etudiant, InscriptionAcademique $inscription): array
    {
        $moteur = app(MoteurDecisionAcademiqueService::class)->determinerDecision($etudiant, $inscription);

        $ues = count(array_filter(
            $moteur['unites'],
            fn (array $ue) => in_array($ue['statut'], ['CAPITALISEE', 'VALIDEE_PAR_COMPENSATION'], true)
        ));

        return [
            'credits' => (int) $moteur['credits']['capitalises'],
            'ues' => $ues,
        ];
    }

    // ---------------------------------------------------------------------
    // 1. Fail-closed
    // ---------------------------------------------------------------------

    public function test_pilote_inactif_par_defaut_bloque_sans_traiter_de_dossier(): void
    {
        [$etudiant, $inscription] = $this->creerDossier('90000001');
        $this->creerDecision($etudiant, $inscription, 6, 1);

        // Configuration par défaut : non touchée.
        $rapport = app(PilotAcademiqueService::class)->executerSimulation();

        $this->assertSame('BLOQUE', $rapport['statut']);
        $this->assertSame([], $rapport['resultats']);
        $this->assertSame(0, $rapport['indicateurs']['dossiers_analyses']);
        $this->assertContains('PILOTE_INACTIF', array_column($rapport['blocages'], 'code'));
        $this->assertTrue($rapport['lecture_seule']);
    }

    public function test_validation_institutionnelle_absente_bloque(): void
    {
        $this->configurerPilote([
            'pilote_academique.statut_validation' => 'A_VALIDER',
            'pilote_academique.reference_validation' => null,
            'pilote_academique.perimetre' => ['facultes' => [$this->faculteFST->id]],
        ]);

        $preconditions = app(PilotAcademiqueService::class)->verifierPreconditions();

        $this->assertFalse($preconditions['autorise']);
        $codes = array_column($preconditions['blocages'], 'code');
        $this->assertContains('VALIDATION_INSTITUTIONNELLE_MANQUANTE', $codes);
        $this->assertContains('REFERENCE_VALIDATION_MANQUANTE', $codes);
    }

    public function test_perimetre_vide_bloque_meme_si_pilote_actif_et_valide(): void
    {
        $this->configurerPilote([
            'pilote_academique.perimetre' => [
                'facultes' => [], 'programmes' => [], 'annees' => [], 'niveaux' => [], 'etudiants' => [],
            ],
        ]);

        [$etudiant, $inscription] = $this->creerDossier('90000002');
        $this->creerDecision($etudiant, $inscription, 6, 1);

        $rapport = app(PilotAcademiqueService::class)->executerSimulation();

        $this->assertSame('BLOQUE', $rapport['statut']);
        $this->assertContains('PERIMETRE_VIDE', array_column($rapport['blocages'], 'code'));
        $this->assertSame([], $rapport['resultats']);
    }

    public function test_mode_ecriture_refuse(): void
    {
        $this->configurerPilote(['pilote_academique.mode' => 'ECRITURE']);

        $preconditions = app(PilotAcademiqueService::class)->verifierPreconditions();

        $this->assertFalse($preconditions['autorise']);
        $this->assertContains('MODE_NON_SUPPORTE', array_column($preconditions['blocages'], 'code'));
    }

    // ---------------------------------------------------------------------
    // 2. Isolation
    // ---------------------------------------------------------------------

    public function test_perimetre_isole_strictement_les_facultes(): void
    {
        $this->configurerPilote(); // faculté FST uniquement

        [$etudiantFst, $inscriptionFst] = $this->creerDossier('90000003');
        [$etudiantEnit, $inscriptionEnit] = $this->creerDossier('90000004', 14.5, $this->faculteENIT);

        $this->creerDecision($etudiantFst, $inscriptionFst, 6, 1);
        $this->creerDecision($etudiantEnit, $inscriptionEnit, 6, 1);

        $rapport = app(PilotAcademiqueService::class)->executerSimulation();

        $this->assertSame('EXECUTE', $rapport['statut']);
        $this->assertCount(1, $rapport['resultats']);
        $this->assertSame($this->faculteFST->id, $rapport['resultats'][0]['faculte_id']);
        $this->assertNotContains(
            $etudiantEnit->id,
            array_column($rapport['resultats'], 'etudiant_id')
        );
        $this->assertSame(1, $rapport['indicateurs']['dossiers_analyses']);
    }

    // ---------------------------------------------------------------------
    // 3. Double calculation et classes de divergence
    // ---------------------------------------------------------------------

    public function test_concordance_exacte_quand_moteur_et_reference_coincident(): void
    {
        $this->configurerPilote();

        [$etudiant, $inscription] = $this->creerDossier('90000005');
        $inscription->update(['decision' => 'admis', 'moyenne_annuelle' => 14.5]);

        $mesures = $this->mesuresMoteur($etudiant, $inscription);
        $this->assertSame(6, $mesures['credits'], 'Le moteur doit capitaliser l\'UE à 6 crédits.');
        $this->assertSame(1, $mesures['ues']);

        $this->creerDecision($etudiant, $inscription, $mesures['credits'], $mesures['ues'], 'admis', 14.5);

        $rapport = app(PilotAcademiqueService::class)->executerSimulation();

        $this->assertSame('EXECUTE', $rapport['statut']);
        $resultat = $rapport['resultats'][0];
        $this->assertTrue($resultat['comparable']);
        $this->assertTrue($resultat['concordant']);
        $this->assertNull($resultat['classe']);
        $this->assertSame([], $resultat['ecarts']);
        $this->assertSame(1, $rapport['indicateurs']['dossiers_concordants']);
    }

    public function test_ecart_credits(): void
    {
        $this->configurerPilote();

        [$etudiant, $inscription] = $this->creerDossier('90000006');
        $this->creerDecision($etudiant, $inscription, 9, 1);

        $rapport = app(PilotAcademiqueService::class)->executerSimulation();
        $resultat = $rapport['resultats'][0];

        $this->assertFalse($resultat['concordant']);
        $this->assertSame('ECART_CREDITS', $resultat['classe']);
        $this->assertArrayHasKey('credits', $resultat['ecarts']);
        $this->assertSame(3, $resultat['ecarts']['credits']['delta']);
    }

    public function test_ecart_ues(): void
    {
        $this->configurerPilote();

        [$etudiant, $inscription] = $this->creerDossier('90000007');
        $this->creerDecision($etudiant, $inscription, 6, 2);

        $rapport = app(PilotAcademiqueService::class)->executerSimulation();
        $resultat = $rapport['resultats'][0];

        $this->assertSame('ECART_UES', $resultat['classe']);
        $this->assertArrayHasKey('ues', $resultat['ecarts']);
    }

    public function test_ecart_decision_entre_reference_et_decision_historique(): void
    {
        $this->configurerPilote();

        [$etudiant, $inscription] = $this->creerDossier('90000008');
        $inscription->update(['decision' => 'ajourne']);
        $this->creerDecision($etudiant, $inscription, 6, 1, 'admis', null);

        $rapport = app(PilotAcademiqueService::class)->executerSimulation();
        $resultat = $rapport['resultats'][0];

        $this->assertSame('ECART_DECISION', $resultat['classe']);
        $this->assertSame('admis', $resultat['ecarts']['decision']['reference']);
        $this->assertSame('ajourne', $resultat['ecarts']['decision']['legacy']);
    }

    public function test_ecart_moyenne_au_dela_de_la_tolerance(): void
    {
        $this->configurerPilote();

        [$etudiant, $inscription] = $this->creerDossier('90000009');
        $inscription->update(['decision' => 'admis', 'moyenne_annuelle' => 14.5]);
        $this->creerDecision($etudiant, $inscription, 6, 1, 'admis', 12.0);

        $rapport = app(PilotAcademiqueService::class)->executerSimulation();
        $resultat = $rapport['resultats'][0];

        $this->assertSame('ECART_MOYENNE', $resultat['classe']);
        $this->assertSame(12.0, $resultat['ecarts']['moyenne']['reference']);
        $this->assertSame(14.5, $resultat['ecarts']['moyenne']['legacy']);
    }

    public function test_ecart_multiple_quand_plusieurs_dimensions_divergent(): void
    {
        $this->configurerPilote();

        [$etudiant, $inscription] = $this->creerDossier('90000010');
        $inscription->update(['decision' => 'ajourne']);
        $this->creerDecision($etudiant, $inscription, 9, 1, 'admis', null);

        $rapport = app(PilotAcademiqueService::class)->executerSimulation();
        $resultat = $rapport['resultats'][0];

        $this->assertSame('ECART_MULTIPLE', $resultat['classe']);
        $this->assertArrayHasKey('credits', $resultat['ecarts']);
        $this->assertArrayHasKey('decision', $resultat['ecarts']);
    }

    public function test_reference_non_publiee_n_est_jamais_comparable(): void
    {
        $this->configurerPilote();

        [$etudiant, $inscription] = $this->creerDossier('90000011');
        $this->creerDecision($etudiant, $inscription, 6, 1, 'admis', null, false);

        $rapport = app(PilotAcademiqueService::class)->executerSimulation();
        $resultat = $rapport['resultats'][0];

        $this->assertFalse($resultat['comparable']);
        $this->assertFalse($resultat['concordant']);
        $this->assertSame('REFERENCE_NON_PUBLIEE', $resultat['classe']);
    }

    public function test_reference_absente_n_est_jamais_comparable(): void
    {
        $this->configurerPilote();

        $this->creerDossier('90000012');

        $rapport = app(PilotAcademiqueService::class)->executerSimulation();
        $resultat = $rapport['resultats'][0];

        $this->assertFalse($resultat['comparable']);
        $this->assertSame('REFERENCE_ABSENTE', $resultat['classe']);
    }

    // ---------------------------------------------------------------------
    // 4. Indicateurs et absence d'écriture
    // ---------------------------------------------------------------------

    public function test_indicateurs_exposent_des_denominateurs_explicites(): void
    {
        $this->configurerPilote();

        // A — concordant
        [$etuA, $inscA] = $this->creerDossier('90000013');
        $this->creerDecision($etuA, $inscA, 6, 1, 'admis', null);

        // B — écart de crédits
        [$etuB, $inscB] = $this->creerDossier('90000014');
        $this->creerDecision($etuB, $inscB, 9, 1, 'admis', null);

        // C — aucune référence
        $this->creerDossier('90000015');

        $rapport = app(PilotAcademiqueService::class)->executerSimulation();

        $this->assertSame(3, $rapport['indicateurs']['dossiers_analyses']);
        $this->assertSame(2, $rapport['indicateurs']['dossiers_comparables']);
        $this->assertSame(1, $rapport['indicateurs']['dossiers_concordants']);
        $this->assertSame(1, $rapport['indicateurs']['dossiers_non_comparables']);

        // Le taux ne divise jamais par le total : 1/2, pas 1/3.
        $this->assertSame(1, $rapport['indicateurs']['taux_concordance']['numerateur']);
        $this->assertSame(2, $rapport['indicateurs']['taux_concordance']['denominateur']);
        $this->assertSame(0.5, $rapport['indicateurs']['taux_concordance']['valeur']);
        $this->assertSame(1, $rapport['indicateurs']['taux_concordance']['exclus_non_comparables']);
    }

    public function test_simulation_ne_modifie_aucune_donnee(): void
    {
        $this->configurerPilote();

        [$etudiant, $inscription] = $this->creerDossier('90000016');
        $this->creerDecision($etudiant, $inscription, 6, 1);

        $compteursAvant = [
            'etudiants' => DB::table('etudiants')->count(),
            'inscriptions_academiques' => DB::table('inscriptions_academiques')->count(),
            'notes' => DB::table('notes')->count(),
            'evaluation_decisions' => DB::table('evaluation_decisions')->count(),
            'parcours_plans' => DB::table('parcours_plans')->count(),
        ];

        app(PilotAcademiqueService::class)->executerSimulation();

        foreach ($compteursAvant as $table => $valeur) {
            $this->assertSame($valeur, DB::table($table)->count(), "La table {$table} a été modifiée.");
        }
    }

    // ---------------------------------------------------------------------
    // 5. Conditions Spéciales ADDENDUM LOOP 42
    // ---------------------------------------------------------------------

    public function test_r42_1_separation_des_5_sous_ensembles_du_corpus_historique(): void
    {
        $service = app(PilotAcademiqueService::class);

        // Dossier 1 : PV officiel exploitable
        [$etu1, $insc1] = $this->creerDossier('90000017');
        $this->creerDecision($etu1, $insc1, 6, 1, 'admis', null, true);

        // Dossier 2 : Résultat normalisé non publié
        [$etu2, $insc2] = $this->creerDossier('90000018');
        $this->creerDecision($etu2, $insc2, 6, 1, 'admis', null, false);

        // Dossier 3 : Notes historiques sans délibération normalisée
        [$etu3, $insc3] = $this->creerDossier('90000019');

        // Dossier 4 : Source justificative absente (inscription sans notes ni décision)
        $etu4 = $this->creerEtudiant('90000020');
        $insc4 = InscriptionAcademique::create([
            'etudiant_id' => $etu4->id,
            'annee_universitaire_id' => $this->annee2024->id,
            'programme_id' => $this->programmeLicence->id,
            'niveau_id' => $this->niveauL1->id,
            'niveau' => 'L1',
            'statut' => 'en_cours',
        ]);

        $classification = $service->classifierCorpusHistorique([$insc1, $insc2, $insc3, $insc4]);

        $this->assertSame(4, $classification['total_dossiers']);
        $this->assertSame(1, $classification['repartition'][PilotAcademiqueService::CORPUS_PV_OFFICIEL_EXPLOITABLE]);
        $this->assertSame(1, $classification['repartition'][PilotAcademiqueService::CORPUS_RESULTAT_NORMALISE]);
        $this->assertSame(1, $classification['repartition'][PilotAcademiqueService::CORPUS_NOTE_SANS_DELIBERATION_NORMALISEE]);
        $this->assertSame(1, $classification['repartition'][PilotAcademiqueService::CORPUS_SOURCE_JUSTIFICATIVE_ABSENTE]);

        // R42.1 : Vérification que l'absence de délibération n'invalide pas l'inscription
        $dossierSansDelib = $classification['details'][PilotAcademiqueService::CORPUS_NOTE_SANS_DELIBERATION_NORMALISEE][0];
        $this->assertTrue($dossierSansDelib['inscription_valide']);
        $this->assertStringContainsString('inscription réputée valide', $dossierSansDelib['motif']);
    }

    public function test_r42_2_audit_des_matieres_sans_ue_et_interdiction_affectation_par_libelle(): void
    {
        $service = app(PilotAcademiqueService::class);

        // Création d'une matière orpheline sans ue_id
        Matiere::create([
            'code' => 'ORPH-101',
            'nom' => 'Matière Sans UE Dédiée',
            'coefficient' => 1.0,
            'credits' => 2,
            'ue_id' => null,
            'niveau' => 'L1',
            'semestre' => 1,
        ]);

        $audit = $service->auditerMatieresSansUe();

        $this->assertGreaterThanOrEqual(1, $audit['total_matieres_sans_ue']);
        $this->assertTrue($audit['interdiction_affectation_automatique_par_libelle']);
        $this->assertStringContainsString('Aucune UE ne doit être attribuée automatiquement', $audit['principe_securite']);

        $codes = array_column($audit['matieres'], 'code');
        $this->assertContains('ORPH-101', $codes);
    }

    public function test_r42_3_normalisation_canonique_et_determinisme_sha256(): void
    {
        $service = app(PilotAcademiqueService::class);

        $donneesA = [
            'z_champ' => 12.5,
            'a_champ' => 'valeur',
            'created_at' => '2026-10-31 10:00:00', // volatile -> doit être ignoré
            'sous_tableau' => [
                'b' => 2,
                'a' => 1,
            ],
        ];

        $donneesB = [
            'sous_tableau' => [
                'a' => 1,
                'b' => 2,
            ],
            'a_champ' => 'valeur',
            'updated_at' => '2026-10-31 12:00:00', // volatile -> doit être ignoré
            'z_champ' => 12.50,
        ];

        // L'ordre des clés et la présence de champs volatiles différents ne doivent pas altérer l'empreinte
        $hashA = $service->genererEmpreinteCanonique($donneesA);
        $hashB = $service->genererEmpreinteCanonique($donneesB);

        $this->assertSame($hashA, $hashB);

        // Une modification réelle doit impérativement altérer le hash
        $donneesModifiees = $donneesA;
        $donneesModifiees['z_champ'] = 14.0;
        $hashModifie = $service->genererEmpreinteCanonique($donneesModifiees);

        $this->assertNotSame($hashA, $hashModifie);
    }

    public function test_r42_4_absence_effets_de_bord_observers_et_events_en_simulation(): void
    {
        $this->configurerPilote();

        [$etudiant, $inscription] = $this->creerDossier('90000021');
        $this->creerDecision($etudiant, $inscription, 6, 1);

        // Surveillance des requêtes SQL pour interdire toute écriture (INSERT, UPDATE, DELETE)
        $requetesEcriture = [];
        DB::listen(function ($query) use (&$requetesEcriture) {
            $sql = mb_strtoupper(trim((string) $query->sql));
            if (str_starts_with($sql, 'INSERT') || str_starts_with($sql, 'UPDATE') || str_starts_with($sql, 'DELETE')) {
                $requetesEcriture[] = $sql;
            }
        });

        // Surveillance des événements mutateurs Eloquent
        Event::fake([
            'eloquent.creating:*',
            'eloquent.created:*',
            'eloquent.updating:*',
            'eloquent.updated:*',
            'eloquent.saving:*',
            'eloquent.saved:*',
            'eloquent.deleting:*',
            'eloquent.deleted:*',
        ]);

        $rapport = app(PilotAcademiqueService::class)->executerSimulation();

        $this->assertSame('EXECUTE', $rapport['statut']);
        $this->assertTrue($rapport['lecture_seule']);

        // Vérification qu'aucune requête d'écriture SQL n'a été exécutée
        $this->assertSame([], $requetesEcriture, 'Le pilote a exécuté des requêtes d\'écriture interdites.');

        // Vérification qu'aucun événement mutateur n'a été émis par le pilote
        Event::assertNotDispatched('eloquent.creating:*');
        Event::assertNotDispatched('eloquent.updating:*');
        Event::assertNotDispatched('eloquent.deleting:*');
    }

    public function test_phase_42_4_classification_des_7_typologies_de_divergence(): void
    {
        $service = app(PilotAcademiqueService::class);

        // Cas 1 : Référence absente -> SOURCE_INSUFFISANTE
        $typ1 = $service->qualifierTypologieDivergence([
            'concordant' => false,
            'classe' => PilotAcademiqueService::CLASSE_REFERENCE_ABSENTE,
            'couverture_reglementaire' => 'FORMATION',
        ]);
        $this->assertSame(PilotAcademiqueService::TYPOLOGIE_SOURCE_INSUFFISANTE, $typ1);

        // Cas 2 : Absence de couverture réglementaire confirmée -> DIVERGENCE_REGLEMENTAIRE
        $typ2 = $service->qualifierTypologieDivergence([
            'concordant' => false,
            'classe' => PilotAcademiqueService::CLASSE_ECART_CREDITS,
            'couverture_reglementaire' => 'AUCUNE',
        ]);
        $this->assertSame(PilotAcademiqueService::TYPOLOGIE_DIVERGENCE_REGLEMENTAIRE, $typ2);

        // Cas 3 : Référence non publiée -> DIVERGENCE_INTERPRETATION
        $typ3 = $service->qualifierTypologieDivergence([
            'concordant' => false,
            'classe' => PilotAcademiqueService::CLASSE_REFERENCE_NON_PUBLIEE,
            'couverture_reglementaire' => 'FORMATION',
        ]);
        $this->assertSame(PilotAcademiqueService::TYPOLOGIE_DIVERGENCE_INTERPRETATION, $typ3);

        // Cas 4 : Écart de décision legacy -> DIVERGENCE_HISTORIQUE
        $typ4 = $service->qualifierTypologieDivergence([
            'concordant' => false,
            'classe' => PilotAcademiqueService::CLASSE_ECART_DECISION,
            'couverture_reglementaire' => 'FORMATION',
            'ecarts' => ['decision' => ['reference' => 'admis', 'legacy' => 'ajourne']],
        ]);
        $this->assertSame(PilotAcademiqueService::TYPOLOGIE_DIVERGENCE_HISTORIQUE, $typ4);

        // Cas 5 : Écart de moyenne -> DIVERGENCE_CALCUL
        $typ5 = $service->qualifierTypologieDivergence([
            'concordant' => false,
            'classe' => PilotAcademiqueService::CLASSE_ECART_MOYENNE,
            'couverture_reglementaire' => 'FORMATION',
            'ecarts' => ['moyenne' => ['reference' => 12.0, 'legacy' => 14.5]],
        ]);
        $this->assertSame(PilotAcademiqueService::TYPOLOGIE_DIVERGENCE_CALCUL, $typ5);

        // Cas 6 : Écart de crédits ou d'UEs -> DIVERGENCE_MAQUETTE
        $typ6 = $service->qualifierTypologieDivergence([
            'concordant' => false,
            'classe' => PilotAcademiqueService::CLASSE_ECART_CREDITS,
            'couverture_reglementaire' => 'FORMATION',
            'ecarts' => ['credits' => ['delta' => 3]],
        ]);
        $this->assertSame(PilotAcademiqueService::TYPOLOGIE_DIVERGENCE_MAQUETTE, $typ6);
    }
}
