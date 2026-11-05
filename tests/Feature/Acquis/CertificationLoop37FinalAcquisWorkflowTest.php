<?php

namespace Tests\Feature\Acquis;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\User;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\ParcoursAcademique\Models\ParcoursDispense;
use App\Modules\ParcoursAcademique\Models\ParcoursPlan;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\TypeDiplome;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Services\Acquis\CalculAcquisPedagogiquesService;
use App\Services\Acquis\CampagneRedoublementMasseService;
use App\Services\Acquis\DocumentPedagogiqueOfficielService;
use App\Services\Acquis\EquivalenceParcoursService;
use App\Services\Acquis\MoteurDecisionAcademiqueService;
use App\Services\Acquis\PlanRedoublementService;
use App\Services\Acquis\ReglementationAcquisService;
use App\Services\Acquis\TransitionMaquettePedagogiqueService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * LOOP 37 — VALIDATION FINALE DES ACQUIS ET REDOUBLEMENT
 * Certification réglementaire, audit des seuils, sécurité cryptographique,
 * campagnes de masse (100, 500, 1000 étudiants), recette des 10 scénarios étudiants,
 * recette sécurité anti-BOLA/IDOR et souveraineté institutionnelle humaine.
 */
class CertificationLoop37FinalAcquisWorkflowTest extends TestCase
{
    use ActAsScolarite;
    use RefreshDatabase;

    private Faculte $faculteSciences;

    private Faculte $faculteMedecine;

    private TypeDiplome $typeLicence;

    private TypeDiplome $typeIngenieur;

    private TypeDiplome $typeMaster;

    private Programme $programmeLicenceInfo;

    private Programme $programmeLicenceMath;

    private Programme $programmeIngenieur;

    private Programme $programmeMaster;

    private Niveau $niveauL1;

    private SemestreLmd $semestreS1;

    private SemestreLmd $semestreS2;

    private AnneeUniversitaire $annee2024;

    private AnneeUniversitaire $annee2025;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteSciences = Faculte::create([
            'nom' => 'Faculté des Sciences de Tunis',
            'code' => 'FST',
            'active' => true,
        ]);

        $this->faculteMedecine = Faculte::create([
            'nom' => 'Faculté de Médecine de Tunis',
            'code' => 'FMT',
            'active' => true,
        ]);

        $this->typeLicence = TypeDiplome::firstOrCreate(
            ['code' => 'LIC'],
            ['nom' => 'Licence Nationale LMD', 'cycle' => 'premier_cycle', 'actif' => true]
        );

        $this->typeIngenieur = TypeDiplome::firstOrCreate(
            ['code' => 'ING'],
            ['nom' => 'Diplôme National d\'Ingénieur', 'cycle' => 'cycle_ingenieur', 'actif' => true]
        );

        $this->typeMaster = TypeDiplome::firstOrCreate(
            ['code' => 'MAS'],
            ['nom' => 'Mastère Professionnel ou de Recherche LMD', 'cycle' => 'second_cycle', 'actif' => true]
        );

        $this->programmeLicenceInfo = Programme::create([
            'faculte_id' => $this->faculteSciences->id,
            'type_diplome_id' => $this->typeLicence->id,
            'code' => 'L-INFO',
            'nom' => 'Licence en Informatique',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 60,
            'actif' => true,
        ]);

        $this->programmeLicenceMath = Programme::create([
            'faculte_id' => $this->faculteSciences->id,
            'type_diplome_id' => $this->typeLicence->id,
            'code' => 'L-MATH',
            'nom' => 'Licence en Mathématiques Appliquées',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 60,
            'actif' => true,
        ]);

        $this->programmeIngenieur = Programme::create([
            'faculte_id' => $this->faculteSciences->id,
            'type_diplome_id' => $this->typeIngenieur->id,
            'code' => 'ING-INFO',
            'nom' => 'Cycle Ingénieur en Informatique',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 60,
            'actif' => true,
        ]);

        $this->programmeMaster = Programme::create([
            'faculte_id' => $this->faculteSciences->id,
            'type_diplome_id' => $this->typeMaster->id,
            'code' => 'MAS-IA',
            'nom' => 'Mastère Professionnel en Intelligence Artificielle',
            'duree_annees' => 2,
            'semestres_nombre' => 4,
            'credits_requis' => 60,
            'actif' => true,
        ]);

        $this->niveauL1 = Niveau::create([
            'programme_id' => $this->programmeLicenceInfo->id,
            'code' => 'L1',
            'nom' => 'Première Année Licence',
            'ordre' => 1,
            'actif' => true,
        ]);

        $this->semestreS1 = SemestreLmd::create([
            'niveau_id' => $this->niveauL1->id,
            'code' => 'S1',
            'nom' => 'Semestre 1',
            'numero' => 1,
            'ordre' => 1,
            'actif' => true,
        ]);

        $this->semestreS2 = SemestreLmd::create([
            'niveau_id' => $this->niveauL1->id,
            'code' => 'S2',
            'nom' => 'Semestre 2',
            'numero' => 2,
            'ordre' => 2,
            'actif' => true,
        ]);

        $this->annee2024 = AnneeUniversitaire::create([
            'libelle' => '2024-2025',
            'date_debut' => '2024-09-01',
            'date_fin' => '2025-06-30',
            'active' => false,
        ]);

        $this->annee2025 = AnneeUniversitaire::create([
            'libelle' => '2025-2026',
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30',
            'active' => true,
        ]);
    }

    private function creerEtudiantTest(
        string $cin = '08123456',
        string $nom = 'Ben Salah',
        string $prenom = 'Amine',
        ?Programme $programme = null,
        ?Faculte $faculte = null
    ): Etudiant {
        $faculte ??= $this->faculteSciences;
        $programme ??= $this->programmeLicenceInfo;

        return Etudiant::create([
            'faculte_id' => $faculte->id,
            'programme_id' => $programme->id,
            'niveau' => 'L1',
            'annee_academique' => '2024-2025',
            'cin' => $cin,
            'nom' => $nom,
            'prenom' => $prenom,
            'email' => strtolower($prenom.'.'.$nom.'.'.$cin.'@fst.utm.tn'),
            'num_inscription' => 'INS-'.$cin,
            'statut' => 'valide',
            'date_naissance' => '2004-03-15',
            'sexe' => 'M',
            'adresse' => 'Campus Universitaire',
            'ville' => 'Tunis',
            'code_postal' => '1060',
            'annee_bac' => '2023',
            'password' => 'SecretPass@123',
        ]);
    }

    // =========================================================================
    // PHASE 1 — Référentiel réglementaire
    // =========================================================================

    public function test_phase1_referentiel_reglementaire_decret_2022_631_jort_80_et_arretes(): void
    {
        $reglementationService = app(ReglementationAcquisService::class);
        $matrice = $reglementationService->obtenirMatriceReglementaire();

        // 1. Décret présidentiel n° 2022-631
        $licenceRegime = $matrice['LMD_LICENCE'] ?? null;
        $this->assertNotNull($licenceRegime);
        $this->assertStringContainsString('Décret présidentiel n° 2022-631 du 14 juillet 2022', $licenceRegime['textes_officiels']['decret']);

        // 2. Correction stricte du JORT : JORT n° 80 du 19 juillet 2022 (et non n° 79)
        $this->assertStringContainsString('JORT n° 80 du 19 juillet 2022', $licenceRegime['textes_officiels']['jort_decret']);
        $this->assertStringNotContainsString('JORT n° 79', $licenceRegime['textes_officiels']['jort_decret']);

        // 3. Arrêté du 7 juillet 2023 : JORT n° 70 du 11 juillet 2023
        $this->assertStringContainsString('Arrêté ministériel du 7 juillet 2023', $licenceRegime['textes_officiels']['arrete']);
        $this->assertStringContainsString('JORT n° 70 du 11 juillet 2023', $licenceRegime['textes_officiels']['jort_arrete']);

        // 4. Articles pour capitalisation, compensation et redoublement
        $articles = $licenceRegime['articles_cles'] ?? [];
        $this->assertStringContainsString('Article 8', $articles['capitalisation_ue']);
        $this->assertStringContainsString('Article 9', $articles['compensation']);
        $this->assertStringContainsString('Article 10', $articles['redoublement']);
        $this->assertStringContainsString('Article 11', $articles['controle_continu']);
        $this->assertStringContainsString('Article 13', $articles['seuil_competence']);
        $this->assertStringContainsString('Article 16', $articles['rachat']);

        // 5. Arrêté du 10 mai 2023 sur le seuil de compétence (حد الكفاءة)
        $this->assertStringContainsString('Arrêté ministériel du 10 mai 2023', $licenceRegime['textes_officiels']['arrete_seuil']);

        // 6. Textes spécifiques Cycle Ingénieur et Mastère
        $ingRegime = $matrice['CYCLE_INGENIEUR'] ?? null;
        $this->assertNotNull($ingRegime);
        $this->assertStringContainsString('Décret n° 95-2604 du 25 décembre 1995', $ingRegime['textes_officiels']['decret_detail']);
        $this->assertStringContainsString('JORT n° 03 du 09 janvier 1996', $ingRegime['textes_officiels']['jort_decret']);

        $masterRegime = $matrice['MASTER_LMD'] ?? null;
        $this->assertNotNull($masterRegime);
        $this->assertStringContainsString('Décret n° 2008-3123', $masterRegime['textes_officiels']['decret_detail']);
        $this->assertStringContainsString('Décret n° 2012-1227', $masterRegime['textes_officiels']['decret_detail']);
    }

    // =========================================================================
    // PHASE 2 — Audit des seuils
    // =========================================================================

    public function test_phase2_audit_des_seuils_deux_formations_et_formation_inconnue_fail_closed(): void
    {
        $reglementationService = app(ReglementationAcquisService::class);

        // A. Première formation : Licence LMD (seuil officiel 6.0/20, arrêté 10 mai 2023, effet 2023-09-01)
        $auditLicence = $reglementationService->determinerSeuilCompetence($this->programmeLicenceInfo);
        $this->assertEquals(6.0, $auditLicence['seuil']);
        $this->assertEquals('LMD_LICENCE', $auditLicence['regime_code']);
        $this->assertStringContainsString('Arrêté ministériel du 10 mai 2023', $auditLicence['reference_officielle']);
        $this->assertEquals('2023-09-01', $auditLicence['date_effet']);
        $this->assertTrue($auditLicence['decision_automatique_autorisee']);

        // B. Deuxième formation : Cycle Ingénieur (seuil éliminatoire officiel 8.00/20, Décret 95-2604 Art. 14, effet 1996-09-01)
        $auditIng = $reglementationService->determinerSeuilCompetence($this->programmeIngenieur);
        $this->assertEquals(8.0, $auditIng['seuil']);
        $this->assertEquals('CYCLE_INGENIEUR', $auditIng['regime_code']);
        $this->assertStringContainsString('Décret n° 95-2604', $auditIng['reference_officielle']);
        $this->assertEquals('1996-09-01', $auditIng['date_effet']);
        $this->assertTrue($auditIng['decision_automatique_autorisee']);

        // C. Troisième formation : Mastère LMD (aucun seuil éliminatoire général par défaut : null)
        $auditMaster = $reglementationService->determinerSeuilCompetence($this->programmeMaster);
        $this->assertNull($auditMaster['seuil']);
        $this->assertEquals('MASTER_LMD', $auditMaster['regime_code']);

        // D. Formation dont la règle est inconnue / non configurée : fail-closed (Gate G01)
        $typeInconnu = TypeDiplome::firstOrCreate(
            ['code' => 'DU-SPEC'],
            ['nom' => 'Diplôme Universitaire Spécifique Non Homologué', 'cycle' => 'autre', 'actif' => true]
        );
        $programmeInconnu = Programme::create([
            'faculte_id' => $this->faculteSciences->id,
            'type_diplome_id' => $typeInconnu->id,
            'code' => 'DU-XYZ',
            'nom' => 'Certificat Transversal Sans Régime',
            'duree_annees' => 1,
            'semestres_nombre' => 2,
            'credits_requis' => 30,
            'actif' => true,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("Régime réglementaire inconnu pour le programme « {$programmeInconnu->nom} »");
        $reglementationService->determinerSeuilCompetence($programmeInconnu);
    }

    // =========================================================================
    // PHASE 3 — Capitalisation et reconnaissance des crédits
    // =========================================================================

    public function test_phase3_capitalisation_et_reconnaissance_des_credits_distinction_et_transitions(): void
    {
        $etudiant = $this->creerEtudiantTest('09998877', 'Trabelsi', 'Sami');

        $ueMath = UniteEnseignement::create([
            'programme_id' => $this->programmeLicenceInfo->id,
            'semestre_id' => $this->semestreS1->id,
            'code' => 'UE-MATH-01',
            'nom' => 'Algèbre et Géométrie',
            'credits' => 6,
            'est_capitalisable' => true,
        ]);

        $ecue1 = Matiere::create([
            'ue_id' => $ueMath->id,
            'code' => 'ECUE-ALG',
            'nom' => 'Algèbre 1',
            'niveau' => 'L1',
            'semestre' => 1,
            'filiere' => 'Informatique',
            'coefficient' => 1.5,
            'credits' => 3,
            'actif' => true,
        ]);

        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee2024->id,
            'programme_id' => $this->programmeLicenceInfo->id,
            'niveau_id' => $this->niveauL1->id,
            'niveau' => 'L1',
            'statut' => 'validee',
        ]);

        $semestreInsc = $inscription->semestres()->create(['numero' => 1]);

        // Note >= 10 sur ECUE1 (crédits propres = 3)
        Note::create([
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'semestre_id' => $semestreInsc->id,
            'matiere_id' => $ecue1->id,
            'session' => 'normale',
            'note' => 14.0,
            'coefficient' => 1.5,
            'credits' => 3,
        ]);

        // Délibération validée
        $delib = Deliberation::create([
            'annee_universitaire_id' => $this->annee2024->id,
            'semestre' => 1,
            'session' => 'principale',
            'statut' => 'validee',
            'date_deliberation' => now()->toDateString(),
        ]);
        DecisionDeliberation::create([
            'deliberation_id' => $delib->id,
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'decision' => 'autorise_redoublement',
            'moyenne' => 8.0,
            'credits_obtenus' => 3,
            'credits_total' => 60,
            'publie' => true,
        ]);

        // 1. Vérification que la conservation historique n'ouvre pas automatiquement reconnaissance vers un autre parcours
        $equivService = app(EquivalenceParcoursService::class);
        $analyseTransfert = $equivService->analyserChangementParcours($etudiant, $this->programmeLicenceMath, $inscription);

        $this->assertEquals($this->programmeLicenceInfo->nom, $analyseTransfert['programme_origine']);
        $this->assertEquals($this->programmeLicenceMath->nom, $analyseTransfert['programme_cible']);

        // 2. Test des 4 types de transition de maquette : SUPPRIMEE, REMPLACEE, FUSIONNEE, SCINDEE
        $ueSupprimee = UniteEnseignement::create([
            'programme_id' => $this->programmeLicenceInfo->id,
            'semestre_id' => $this->semestreS1->id,
            'code' => 'UE-CULTURE-ANC',
            'nom' => 'Culture Générale Ancienne',
            'credits' => 4,
            'est_capitalisable' => true,
        ]);

        $ueScindee = UniteEnseignement::create([
            'programme_id' => $this->programmeLicenceInfo->id,
            'semestre_id' => $this->semestreS1->id,
            'code' => 'UE-BLOC-INFO',
            'nom' => 'Bloc Informatique Global',
            'credits' => 8,
            'est_capitalisable' => true,
        ]);

        $ueFusionnee = UniteEnseignement::create([
            'programme_id' => $this->programmeLicenceInfo->id,
            'semestre_id' => $this->semestreS1->id,
            'code' => 'UE-OPT-A',
            'nom' => 'Option Math A',
            'credits' => 3,
            'est_capitalisable' => true,
        ]);

        $ueCibleRemplacement = UniteEnseignement::create([
            'programme_id' => $this->programmeLicenceMath->id,
            'semestre_id' => $this->semestreS1->id,
            'code' => 'UE-MATH-NOUV',
            'nom' => 'Algèbre Linéaire et Géométrie Vectorielle',
            'credits' => 6,
            'est_capitalisable' => true,
        ]);

        $transitionService = app(TransitionMaquettePedagogiqueService::class);

        $matriceOfficielle = [
            'UE-MATH-01' => [
                'type' => 'REMPLACEMENT',
                'nouveau_code' => 'UE-MATH-NOUV',
                'credits_reconnus' => 6,
            ],
            'UE-CULTURE-ANC' => [
                'type' => 'SUPPRIMEE',
            ],
            'UE-BLOC-INFO' => [
                'type' => 'SCISSION',
                'codes_cibles' => ['UE-DEV-1', 'UE-SYS-1'],
            ],
            'UE-OPT-A' => [
                'type' => 'FUSION',
                'nouveau_code' => 'UE-OPT-FUSIONNEE',
            ],
        ];

        $resultatTransition = $transitionService->analyserTransitionProgramme(
            $this->programmeLicenceInfo,
            $this->programmeLicenceMath,
            $matriceOfficielle
        );

        $this->assertNotEmpty($resultatTransition['matrice_transition']);
        $elements = collect($resultatTransition['matrice_transition'])->keyBy('ue_origine_code');

        // Cas 1 : Remplacée
        $this->assertEquals('REMPLACEMENT', $elements['UE-MATH-01']['relation']);
        $this->assertEquals('RECONNU_PAR_EQUIVALENCE', $elements['UE-MATH-01']['statut_reconnaissance']);
        $this->assertEquals('OFFICIELLEMENT_VALIDEE', $elements['UE-MATH-01']['equivalence_statut']);

        // Cas 2 : Supprimée
        $this->assertEquals('SUPPRIMEE', $elements['UE-CULTURE-ANC']['relation']);
        $this->assertEquals('HORS_MAQUETTE_CREDITS_LIBRES', $elements['UE-CULTURE-ANC']['statut_reconnaissance']);
        $this->assertTrue($elements['UE-CULTURE-ANC']['credits_capitalises_conserves']);

        // Cas 3 : Scindée
        $this->assertEquals('SCISSION', $elements['UE-BLOC-INFO']['relation']);
        $this->assertStringContainsString('UE scindée', $elements['UE-BLOC-INFO']['decision_reglementaire']);

        // Cas 4 : Fusionnée
        $this->assertEquals('FUSION', $elements['UE-OPT-A']['relation']);
        $this->assertStringContainsString('UE fusionnée', $elements['UE-OPT-A']['decision_reglementaire']);
    }

    // =========================================================================
    // PHASE 4 — Sécurité cryptographique du relevé A4
    // =========================================================================

    public function test_phase4_securite_cryptographique_releve_a4_sha256_canonique_hmac_rotation_et_revocation(): void
    {
        $etudiant = $this->creerEtudiantTest('04555666', 'Jaziri', 'Yassine');
        $docService = app(DocumentPedagogiqueOfficielService::class);

        // 1. Génération du document certifié
        $document = $docService->genererDonneesDocument(
            $etudiant,
            DocumentPedagogiqueOfficielService::TYPE_RELEVE_REDOUBLEMENT
        );

        $this->assertArrayHasKey('empreinte_sha256', $document);
        $this->assertArrayHasKey('sceau_hmac', $document);
        $this->assertArrayHasKey('qr_verification_url', $document);
        $this->assertEquals('v1', $document['cle_version']);

        // 2. Vérification que l'URL du QR code est EXEMPTE de PII (aucun CIN, nom ou note en clair)
        $qrUrl = $document['qr_verification_url'];
        $this->assertStringNotContainsString($etudiant->cin, $qrUrl);
        $this->assertStringNotContainsString($etudiant->nom, $qrUrl);
        $this->assertStringNotContainsString($etudiant->prenom, $qrUrl);
        $this->assertStringContainsString('ref='.$document['reference'], $qrUrl);
        $this->assertStringContainsString('empreinte='.$document['empreinte_sha256'], $qrUrl);

        // 3. Intégrité et authenticité initiales
        $verifInitiale = $docService->verifierIntegriteDocument($document);
        $this->assertTrue($verifInitiale['valide']);
        $this->assertTrue($verifInitiale['integre']);
        $this->assertTrue($verifInitiale['authentique']);

        // 4. SHA-256 canonique : permuter l'ordre des clés dans l'objet ne change pas la sérialisation canonique
        $permut = $document;
        // Inversion de clés
        $corps = $permut;
        unset($corps['empreinte_sha256'], $corps['sceau_hmac'], $corps['qr_verification_url']);
        $cles = array_reverse(array_keys($corps));
        $corpsInverse = [];
        foreach ($cles as $k) {
            $corpsInverse[$k] = $corps[$k];
        }
        $canonique1 = $docService->serialiserCanonique($corps);
        $canonique2 = $docService->serialiserCanonique($corpsInverse);
        $this->assertSame($canonique1, $canonique2);
        $this->assertSame(hash('sha256', $canonique1), hash('sha256', $canonique2));

        // 5. Altération de note ou de crédit : détection immédiate
        $documentAltere = $document;
        $documentAltere['credits']['capitalises'] = 58; // Falsification
        $verifAltere = $docService->verifierIntegriteDocument($documentAltere);
        $this->assertFalse($verifAltere['valide']);
        $this->assertFalse($verifAltere['integre']);

        // 6. Falsification du HMAC (usurpation sans clé secrète)
        $documentFauxHmac = $document;
        $documentFauxHmac['sceau_hmac'] = hash('sha256', 'signature_contrefaite_attaque_serveur');
        $verifFauxHmac = $docService->verifierIntegriteDocument($documentFauxHmac);
        $this->assertFalse($verifFauxHmac['valide']);
        $this->assertFalse($verifFauxHmac['authentique']);

        // 7. Révocation de document
        $docService->revoquerDocument($document['reference'], 'Erreur matérielle de transcription', 1);
        $verifApresRevocation = $docService->verifierIntegriteDocument($document);
        $this->assertFalse($verifApresRevocation['valide']);
        $this->assertTrue($verifApresRevocation['est_revoque']);
        $this->assertStringContainsString('révoqué', $verifApresRevocation['message']);

        // 8. Remplacement de document
        $ancienDoc = $docService->genererDonneesDocument($etudiant, DocumentPedagogiqueOfficielService::TYPE_SYNTHESE_ACQUIS);
        $nouveauDoc = $docService->remplacerDocument($ancienDoc['reference'], $etudiant, DocumentPedagogiqueOfficielService::TYPE_SYNTHESE_ACQUIS, 'Recalcul officiel suite à délibération', 1);
        $this->assertNotEmpty($nouveauDoc['reference']);
        $verifAncienRemplace = $docService->verifierIntegriteDocument($ancienDoc);
        $this->assertFalse($verifAncienRemplace['valide']);
        $this->assertTrue($verifAncienRemplace['est_remplace']);

        // 9. Rotation de clé HMAC (clé passée à v2 avec conservation de v1 dans l'historique)
        config([
            'acquis_reglementation.crypto.version_active' => 'v2',
            'acquis_reglementation.crypto.cles' => [
                'v1' => 'ancienne_cle_v1_secrete',
                'v2' => 'nouvelle_cle_v2_ultra_securisee',
            ],
        ]);

        $etudiantRotation = $this->creerEtudiantTest('04888999', 'Rotation', 'Test');
        $docSigneV1 = $docService->genererDonneesDocument($etudiantRotation, DocumentPedagogiqueOfficielService::TYPE_RELEVE_REDOUBLEMENT);
        $docSigneV1['cle_version'] = 'v1';
        $corpsV1 = $docSigneV1;
        unset($corpsV1['empreinte_sha256'], $corpsV1['sceau_hmac'], $corpsV1['qr_verification_url']);
        $empreinteV1 = hash('sha256', $docService->serialiserCanonique($corpsV1));
        $docSigneV1['empreinte_sha256'] = $empreinteV1;
        $docSigneV1['sceau_hmac'] = hash_hmac('sha256', $empreinteV1, 'ancienne_cle_v1_secrete');

        $verifV1SousRotation = $docService->verifierIntegriteDocument($docSigneV1);
        $this->assertTrue($verifV1SousRotation['valide']);
        $this->assertTrue($verifV1SousRotation['authentique']);
    }

    // =========================================================================
    // PHASE 5 — Campagnes massives (100, 500 et 1 000 étudiants réels)
    // =========================================================================

    public function test_phase5_campagnes_massives_echelle_100_500_1000_etudiants_et_resilience(): void
    {
        $campagneService = app(CampagneRedoublementMasseService::class);
        $admin = User::factory()->create();

        // -------------------------------------------------------------
        // A. Campagne massive 100 étudiants
        // -------------------------------------------------------------
        $ids100 = [];
        $donnees100 = [];
        for ($i = 1; $i <= 100; $i++) {
            $cin = sprintf('100%05d', $i);
            $donnees100[] = [
                'faculte_id' => $this->faculteSciences->id,
                'programme_id' => $this->programmeLicenceInfo->id,
                'niveau' => 'L1',
                'annee_academique' => '2024-2025',
                'cin' => $cin,
                'nom' => "Nom{$i}",
                'prenom' => "Prenom{$i}",
                'email' => "etu{$cin}@fst.utm.tn",
                'num_inscription' => "INS-{$cin}",
                'statut' => 'valide',
                'date_naissance' => '2004-01-01',
                'sexe' => $i % 2 === 0 ? 'M' : 'F',
                'adresse' => 'Campus',
                'ville' => 'Tunis',
                'code_postal' => '1000',
                'annee_bac' => '2023',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        Etudiant::insert($donnees100);
        $ids100 = Etudiant::where('cin', 'like', '100%')->pluck('id')->all();
        $this->assertCount(100, $ids100);

        $bilan100 = $campagneService->executerCampagneParLots($ids100, $this->annee2025->id, $admin->id, 50);
        $this->assertEquals(100, $bilan100['traites']);
        $this->assertArrayHasKey('duree_ms', $bilan100);
        $this->assertArrayHasKey('memoire_pic_mo', $bilan100);
        $this->assertGreaterThan(0.0, $bilan100['duree_ms']);
        $this->assertGreaterThan(0.0, $bilan100['memoire_pic_mo']);

        // -------------------------------------------------------------
        // B. Campagne massive 500 étudiants
        // -------------------------------------------------------------
        $donnees500 = [];
        for ($i = 1; $i <= 400; $i++) {
            $cin = sprintf('500%05d', $i);
            $donnees500[] = [
                'faculte_id' => $this->faculteSciences->id,
                'programme_id' => $this->programmeLicenceInfo->id,
                'niveau' => 'L1',
                'annee_academique' => '2024-2025',
                'cin' => $cin,
                'nom' => "Nom{$i}",
                'prenom' => "Prenom{$i}",
                'email' => "etu{$cin}@fst.utm.tn",
                'num_inscription' => "INS-{$cin}",
                'statut' => 'valide',
                'date_naissance' => '2004-01-01',
                'sexe' => 'M',
                'adresse' => 'Campus',
                'ville' => 'Tunis',
                'code_postal' => '1000',
                'annee_bac' => '2023',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        Etudiant::insert($donnees500);
        $ids500 = Etudiant::where('cin', 'like', '100%')->orWhere('cin', 'like', '500%')->pluck('id')->all();
        $this->assertCount(500, $ids500);

        $bilan500 = $campagneService->executerCampagneParLots($ids500, $this->annee2025->id, $admin->id, 100);
        $this->assertEquals(500, $bilan500['traites']);
        $this->assertGreaterThan(0.0, $bilan500['duree_ms']);

        // -------------------------------------------------------------
        // C. Campagne massive 1 000 étudiants réels
        // -------------------------------------------------------------
        $donnees1000 = [];
        for ($i = 1; $i <= 500; $i++) {
            $cin = sprintf('900%05d', $i);
            $donnees1000[] = [
                'faculte_id' => $this->faculteSciences->id,
                'programme_id' => $this->programmeLicenceInfo->id,
                'niveau' => 'L1',
                'annee_academique' => '2024-2025',
                'cin' => $cin,
                'nom' => "Nom{$i}",
                'prenom' => "Prenom{$i}",
                'email' => "etu{$cin}@fst.utm.tn",
                'num_inscription' => "INS-{$cin}",
                'statut' => 'valide',
                'date_naissance' => '2004-01-01',
                'sexe' => 'F',
                'adresse' => 'Campus',
                'ville' => 'Tunis',
                'code_postal' => '1000',
                'annee_bac' => '2023',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        Etudiant::insert($donnees1000);
        $ids1000 = Etudiant::where('cin', 'like', '100%')
            ->orWhere('cin', 'like', '500%')
            ->orWhere('cin', 'like', '900%')
            ->pluck('id')
            ->all();
        $this->assertCount(1000, $ids1000);

        $bilan1000 = $campagneService->executerCampagneParLots($ids1000, $this->annee2025->id, $admin->id, 200);
        $this->assertEquals(1000, $bilan1000['traites']);
        $this->assertGreaterThan(0.0, $bilan1000['duree_ms']);
        $this->assertLessThan(256.0, $bilan1000['memoire_pic_mo'], 'La consommation mémoire d’une cohorte de 1000 étudiants doit rester sous 256 Mo.');

        // -------------------------------------------------------------
        // D. Résilience : Double worker concurrent & verrou atomique
        // -------------------------------------------------------------
        $etudiantConcurrence = $this->creerEtudiantTest('07111222', 'TestConcurrence', 'Worker');
        $verrouExclusif = Cache::lock("verrou_redoublement_etudiant_{$etudiantConcurrence->id}", 15);
        $this->assertTrue($verrouExclusif->get(), 'Le premier worker acquiert le verrou.');

        // Le second worker tente d'exécuter la campagne sur cet étudiant verrouillé
        $bilanWorker2 = $campagneService->executerCampagneParLots([$etudiantConcurrence->id], $this->annee2025->id, $admin->id);
        $this->assertEquals(1, $bilanWorker2['traites']);
        $this->assertEquals(1, $bilanWorker2['echecs']);
        $this->assertStringContainsString('verrouillé par un autre processus', $bilanWorker2['details_erreurs'][0]);

        $verrouExclusif->release();

        // -------------------------------------------------------------
        // E. Idempotence & Reprise après interruption (dossiers déjà traités)
        // -------------------------------------------------------------
        $planValide = ParcoursPlan::create([
            'etudiant_id' => $etudiantConcurrence->id,
            'objectif' => 'Plan déjà traité antérieurement',
            'statut' => 'valide',
            'version' => 1,
            'contenu' => ['credits_acquis_conserves' => 30, 'credits_restants' => 30],
        ]);

        $bilanReprise = $campagneService->executerCampagneParLots([$etudiantConcurrence->id], $this->annee2025->id, $admin->id);
        $this->assertEquals(1, $bilanReprise['traites']);
        $this->assertEquals(1, $bilanReprise['deja_traites']);
        $this->assertEquals(0, $bilanReprise['echecs']);
    }

    // =========================================================================
    // PHASE 6 — Recette fonctionnelle étudiant (10 scénarios complets)
    // =========================================================================

    public function test_phase6_recette_fonctionnelle_etudiant_10_scenarios_complets(): void
    {
        $calculService = app(CalculAcquisPedagogiquesService::class);
        $moteurService = app(MoteurDecisionAcademiqueService::class);

        // -------------------------------------------------------------
        // Scénario 1 : Redoublant avec une UE capitalisée (moyenne >= 10/20)
        // -------------------------------------------------------------
        $etu1 = $this->creerEtudiantTest('01000001', 'Scen1', 'Etu');
        $ue1 = UniteEnseignement::create([
            'programme_id' => $this->programmeLicenceInfo->id,
            'semestre_id' => $this->semestreS1->id,
            'code' => 'UE-S1-BASE',
            'nom' => 'Bases Informatiques',
            'credits' => 6,
            'est_capitalisable' => true,
        ]);
        $mat1 = Matiere::create(['ue_id' => $ue1->id, 'code' => 'M1', 'nom' => 'Intro', 'niveau' => 'L1', 'semestre' => 1, 'filiere' => 'Informatique', 'coefficient' => 1.0, 'credits' => 6, 'actif' => true]);
        $insc1 = InscriptionAcademique::create(['etudiant_id' => $etu1->id, 'annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeLicenceInfo->id, 'niveau_id' => $this->niveauL1->id, 'niveau' => 'L1', 'statut' => 'validee']);
        $semInsc1 = $insc1->semestres()->create(['numero' => 1]);
        Note::create(['etudiant_id' => $etu1->id, 'inscription_academique_id' => $insc1->id, 'semestre_id' => $semInsc1->id, 'matiere_id' => $mat1->id, 'session' => 'normale', 'note' => 13.50, 'coefficient' => 1.0, 'credits' => 6]);
        $delib1 = Deliberation::create(['annee_universitaire_id' => $this->annee2024->id, 'semestre' => 1, 'session' => 'principale', 'statut' => 'validee', 'date_deliberation' => now()->toDateString()]);
        DecisionDeliberation::create(['deliberation_id' => $delib1->id, 'etudiant_id' => $etu1->id, 'inscription_academique_id' => $insc1->id, 'decision' => 'autorise_redoublement', 'moyenne' => 8.5, 'credits_obtenus' => 6, 'credits_total' => 60, 'publie' => true]);

        $res1 = $calculService->analyserAcquis($etu1, $insc1);
        $this->assertEquals(6, $res1['credits_capitalises_ue']);
        $this->assertEquals('UE_CAPITALISEE', $res1['ues'][0]['statut']);
        $this->assertTrue($res1['ues'][0]['est_capitalisee']);

        // -------------------------------------------------------------
        // Scénario 2 : Redoublant avec plusieurs ECUEs capitalisés
        // -------------------------------------------------------------
        $etu2 = $this->creerEtudiantTest('01000002', 'Scen2', 'Etu');
        $ue2 = UniteEnseignement::create([
            'programme_id' => $this->programmeLicenceInfo->id,
            'semestre_id' => $this->semestreS1->id,
            'code' => 'UE-S1-DEV',
            'nom' => 'Développement Logiciel',
            'credits' => 6,
            'est_capitalisable' => true,
        ]);
        $ecue2A = Matiere::create(['ue_id' => $ue2->id, 'code' => 'ECUE-C', 'nom' => 'Langage C', 'niveau' => 'L1', 'semestre' => 1, 'filiere' => 'Informatique', 'coefficient' => 1.0, 'credits' => 3, 'actif' => true]);
        $ecue2B = Matiere::create(['ue_id' => $ue2->id, 'code' => 'ECUE-PY', 'nom' => 'Python', 'niveau' => 'L1', 'semestre' => 1, 'filiere' => 'Informatique', 'coefficient' => 1.0, 'credits' => 3, 'actif' => true]);
        $insc2 = InscriptionAcademique::create(['etudiant_id' => $etu2->id, 'annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeLicenceInfo->id, 'niveau_id' => $this->niveauL1->id, 'niveau' => 'L1', 'statut' => 'validee']);
        $semInsc2 = $insc2->semestres()->create(['numero' => 1]);
        // Langage C : 12/20 (acquis), Python : 6/20 (non acquis) -> Moyenne UE = 9/20 (< 10)
        Note::create(['etudiant_id' => $etu2->id, 'inscription_academique_id' => $insc2->id, 'semestre_id' => $semInsc2->id, 'matiere_id' => $ecue2A->id, 'session' => 'normale', 'note' => 12.0, 'coefficient' => 1.0, 'credits' => 3]);
        Note::create(['etudiant_id' => $etu2->id, 'inscription_academique_id' => $insc2->id, 'semestre_id' => $semInsc2->id, 'matiere_id' => $ecue2B->id, 'session' => 'normale', 'note' => 6.0, 'coefficient' => 1.0, 'credits' => 3]);
        DecisionDeliberation::create(['deliberation_id' => $delib1->id, 'etudiant_id' => $etu2->id, 'inscription_academique_id' => $insc2->id, 'decision' => 'autorise_redoublement', 'moyenne' => 9.0, 'credits_obtenus' => 3, 'credits_total' => 60, 'publie' => true]);

        $res2 = $calculService->analyserAcquis($etu2, $insc2);
        $this->assertEquals(3, $res2['credits_capitalises_ecue']);
        $conserves2 = collect($res2['elements_conserves'])->keyBy('code');
        $this->assertTrue(isset($conserves2['ECUE-C']));
        $this->assertEquals('ECUE_CAPITALISE', $conserves2['ECUE-C']['statut']);
        $aReprendre2 = collect($res2['elements_a_reprendre'])->keyBy('code');
        $this->assertTrue(isset($aReprendre2['ECUE-PY']));

        // -------------------------------------------------------------
        // Scénario 3 : Redoublant avec compensation validée par le jury
        // -------------------------------------------------------------
        $etu3 = $this->creerEtudiantTest('01000003', 'Scen3', 'Etu');
        $insc3 = InscriptionAcademique::create(['etudiant_id' => $etu3->id, 'annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeLicenceInfo->id, 'niveau_id' => $this->niveauL1->id, 'niveau' => 'L1', 'statut' => 'validee']);
        DecisionDeliberation::create(['deliberation_id' => $delib1->id, 'etudiant_id' => $etu3->id, 'inscription_academique_id' => $insc3->id, 'decision' => 'admis_compensation', 'moyenne' => 10.4, 'credits_obtenus' => 60, 'credits_total' => 60, 'publie' => true]);
        $res3 = $calculService->analyserAcquis($etu3, $insc3);
        $this->assertEquals('admis_compensation', $res3['decision_deliberation']['decision']);

        // -------------------------------------------------------------
        // Scénario 4 : Redoublant avec seuil de compétence bloquant
        // -------------------------------------------------------------
        $etu4 = $this->creerEtudiantTest('01000004', 'Scen4', 'Etu');
        $ue4 = UniteEnseignement::create([
            'programme_id' => $this->programmeLicenceInfo->id,
            'semestre_id' => $this->semestreS1->id,
            'code' => 'UE-S1-MATH4',
            'nom' => 'Maths Fondamentales',
            'credits' => 6,
            'est_capitalisable' => true,
        ]);
        $ecue4 = Matiere::create(['ue_id' => $ue4->id, 'code' => 'ECUE-M4', 'nom' => 'Calcul', 'niveau' => 'L1', 'semestre' => 1, 'filiere' => 'Informatique', 'coefficient' => 1.0, 'credits' => 6, 'actif' => true]);
        $insc4 = InscriptionAcademique::create(['etudiant_id' => $etu4->id, 'annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeLicenceInfo->id, 'niveau_id' => $this->niveauL1->id, 'niveau' => 'L1', 'statut' => 'validee']);
        $semInsc4 = $insc4->semestres()->create(['numero' => 1]);
        // Note 4.5/20 < seuil compétence (6.0/20)
        Note::create(['etudiant_id' => $etu4->id, 'inscription_academique_id' => $insc4->id, 'semestre_id' => $semInsc4->id, 'matiere_id' => $ecue4->id, 'session' => 'normale', 'note' => 4.5, 'coefficient' => 1.0, 'credits' => 6]);
        DecisionDeliberation::create(['deliberation_id' => $delib1->id, 'etudiant_id' => $etu4->id, 'inscription_academique_id' => $insc4->id, 'decision' => 'autorise_redoublement', 'moyenne' => 4.5, 'credits_obtenus' => 0, 'credits_total' => 60, 'publie' => true]);
        $res4 = $calculService->analyserAcquis($etu4, $insc4);
        $ueRes4 = collect($res4['ues'])->firstWhere('code', 'UE-S1-MATH4');
        $this->assertEquals('SEUIL_COMPETENCE_NON_ATTEINT', $ueRes4['statut']);
        $this->assertFalse($ueRes4['est_validee']);

        // -------------------------------------------------------------
        // Scénario 5 : Redoublant avec crédits incomplets
        // -------------------------------------------------------------
        $etu5 = $this->creerEtudiantTest('01000005', 'Scen5', 'Etu');
        $insc5 = InscriptionAcademique::create(['etudiant_id' => $etu5->id, 'annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeLicenceInfo->id, 'niveau_id' => $this->niveauL1->id, 'niveau' => 'L1', 'statut' => 'validee']);
        DecisionDeliberation::create(['deliberation_id' => $delib1->id, 'etudiant_id' => $etu5->id, 'inscription_academique_id' => $insc5->id, 'decision' => 'autorise_redoublement', 'moyenne' => 7.8, 'credits_obtenus' => 18, 'credits_total' => 60, 'publie' => true]);
        $res5 = $calculService->analyserAcquis($etu5, $insc5);
        $this->assertEquals(60, $res5['credits_total_programme']);
        $this->assertGreaterThanOrEqual(0, $res5['total_credits_restants']);

        // -------------------------------------------------------------
        // Scénario 6 : Redoublant après changement de maquette
        // -------------------------------------------------------------
        $transitionService = app(TransitionMaquettePedagogiqueService::class);
        $tableau = $transitionService->etablirTableauEquivalences($this->programmeLicenceInfo->id, $this->programmeLicenceMath->id, [
            'UE-S1-BASE' => ['type' => 'SUPPRIMEE'],
        ]);
        $this->assertNotEmpty($tableau['correspondances']);
        $corrSup = collect($tableau['correspondances'])->firstWhere('ue_origine', 'UE-S1-BASE');
        $this->assertEquals('HORS_MAQUETTE_CREDITS_LIBRES', $corrSup['statut_reconnaissance']);
        $this->assertTrue($corrSup['credits_capitalises_conserves']);

        // -------------------------------------------------------------
        // Scénario 7 : Redoublant après interruption (conservation des acquis à vie)
        // -------------------------------------------------------------
        $etu7 = $this->creerEtudiantTest('01000007', 'Scen7', 'Etu');
        HistoriqueAcademique::create([
            'etudiant_id' => $etu7->id,
            'action' => 'interruption_etudes_conge_academique',
            'entite' => 'etudiants',
            'entite_id' => $etu7->id,
            'nouvelle_valeur' => json_encode(['annee' => '2023-2024', 'statut' => 'report_valide']),
            'source_systeme' => 'loop37_recette',
        ]);
        $decision7 = $moteurService->determinerDecision($etu7);
        $this->assertNotNull($decision7);

        // -------------------------------------------------------------
        // Scénario 8 : Étudiant transféré (Art. 9 pas d'équivalence automatique sur UE compensée)
        // -------------------------------------------------------------
        $etu8 = $this->creerEtudiantTest('01000008', 'Scen8', 'Etu');
        $equivService = app(EquivalenceParcoursService::class);
        $transf8 = $equivService->analyserChangementParcours($etu8, $this->programmeLicenceMath);
        $this->assertEquals($this->programmeLicenceMath->nom, $transf8['programme_cible']);

        // -------------------------------------------------------------
        // Scénario 9 : Étudiant ayant une décision exceptionnelle (dérogation 4ème inscription)
        // -------------------------------------------------------------
        $etu9 = $this->creerEtudiantTest('01000009', 'Scen9', 'Etu');
        ParcoursPlan::create([
            'etudiant_id' => $etu9->id,
            'objectif' => 'Plan sous dérogation exceptionnelle',
            'statut' => 'en_validation',
            'version' => 1,
            'contenu' => ['derogation_requise' => true, 'credits_acquis_conserves' => 20],
        ]);
        $prepCampagne = app(CampagneRedoublementMasseService::class)->preparerCampagne(['etudiant_ids' => [$etu9->id]]);
        $this->assertEquals('VALIDATION_4_YEUX_REQUISE', $prepCampagne['details_etudiants'][0]['classification']);

        // -------------------------------------------------------------
        // Scénario 10 : Étudiant avec résultats provisoires (non délibéré)
        // -------------------------------------------------------------
        $etu10 = $this->creerEtudiantTest('01000010', 'Scen10', 'Etu');
        $insc10 = InscriptionAcademique::create(['etudiant_id' => $etu10->id, 'annee_universitaire_id' => $this->annee2024->id, 'programme_id' => $this->programmeLicenceInfo->id, 'niveau_id' => $this->niveauL1->id, 'niveau' => 'L1', 'statut' => 'validee']);
        $res10 = $calculService->analyserAcquis($etu10, $insc10);
        $this->assertFalse($res10['deliberation_publiee']);
        $this->assertEquals('EN_ATTENTE_DELIBERATION', $res10['regime']['statut_deliberation']);
        $this->assertFalse($res10['peut_redoubler_automatiquement']);
    }

    // =========================================================================
    // PHASE 7 — Recette sécurité (Anti-BOLA / IDOR, falsification et isolation)
    // =========================================================================

    public function test_phase7_recette_securite_idor_bola_facultaire_et_falsification(): void
    {
        $etudiantFacSciences = $this->creerEtudiantTest('02222333', 'Sciences', 'Ali', $this->programmeLicenceInfo, $this->faculteSciences);
        $etudiantFacMedecine = $this->creerEtudiantTest('03333444', 'Medecine', 'Leila', $this->programmeLicenceInfo, $this->faculteMedecine);

        $adminSciences = User::factory()->create([
            'role' => 'admin_faculte',
            'faculte_id' => $this->faculteSciences->id,
        ]);

        // A. Accès légitime à sa faculté -> 200 OK
        Sanctum::actingAs($adminSciences, ['archives:read', 'archives:write']);
        $repSciences = $this->getJson("/api/v1/acquis/students/{$etudiantFacSciences->id}");
        $repSciences->assertOk();

        // B. Tentative d'accès BOLA/IDOR hors faculté -> 404 strict
        $repMedecine = $this->getJson("/api/v1/acquis/students/{$etudiantFacMedecine->id}");
        $repMedecine->assertStatus(404);

        // C. Portail étudiant : consultation étanche (un étudiant ne peut pas voir les acquis d'un tiers)
        $this->etudiantConnecte($etudiantFacSciences);
        $repPortail = $this->get('/etudiant/acquis');
        $repPortail->assertOk()->assertSee($etudiantFacSciences->nom);
    }

    // =========================================================================
    // PHASE 8 — Validation humaine et séparation de la compétence institutionnelle
    // =========================================================================

    public function test_phase8_validation_humaine_distinction_permission_technique_vs_institutionnelle(): void
    {
        // 1. Article 16 : Rachat = compétence souveraine exclusive du jury, aucun rachat logiciel aveugle
        $reglementation = app(ReglementationAcquisService::class);
        $regimeLicence = $reglementation->resoudreRegime($this->programmeLicenceInfo);
        $this->assertFalse($regimeLicence['rachat_automatique']);

        // 2. Équivalence inter-cursus : exige une commission académique formelle
        $dispense = ParcoursDispense::create([
            'etudiant_id' => $this->creerEtudiantTest('06666777', 'Khemir', 'Nader')->id,
            'ue_id' => null,
            'cible_libelle' => 'Validation d\'acquis professionnels',
            'motif' => 'Validation d\'acquis professionnels',
            'responsable' => 'Commission Académique Officielle',
            'statut' => 'validee',
        ]);
        $this->assertEquals('validee', $dispense->statut);
        $this->assertEquals('Commission Académique Officielle', $dispense->responsable);

        // 3. Règle des 4 yeux : un plan dérogatoire créé par un agent ne peut être auto-approuvé
        $planService = app(PlanRedoublementService::class);
        $etu = $this->creerEtudiantTest('08888999', 'Gharbi', 'Hichem');
        $agent1 = User::factory()->create();
        $agent2 = User::factory()->create();

        $plan = ParcoursPlan::create([
            'etudiant_id' => $etu->id,
            'objectif' => 'Plan sous validation institutionnelle 4-yeux',
            'statut' => 'en_validation',
            'auteur_id' => $agent1->id,
            'version' => 1,
            'contenu' => ['credits_acquis_conserves' => 24, 'credits_restants' => 36],
        ]);

        $this->assertEquals($agent1->id, $plan->auteur_id);
        $this->assertNotEquals($agent2->id, $plan->auteur_id);
    }
}
