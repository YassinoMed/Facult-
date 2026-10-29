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
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\ConvocationService;
use App\Modules\Evaluation\Services\ExamCandidatsConvocationsOrchestratorService;
use App\Modules\Evaluation\Services\PreparationExamenService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite de tests Enterprise pour EA-EXAMENS-04 — Candidats, Émargement & Convocations (Menus 4, 9 & 11).
 *
 * Valide :
 *  - Figeage et signature cryptographique du snapshot de population (population_validated_at, population_hash)
 *  - Attribution déterministe d'un numéro d'anonymat strict (format AN-XXXX, aucune divulgation CIN / matricule)
 *  - Détection automatique de dérive de population (populationModifiee)
 *  - Idempotence et préservation des numéros anonymes lors d'une revalidation
 *  - Génération des listes d'émargement officielles (PDF paysage certifié)
 *  - Génération des convocations individuelles avec QR Code anti-fraude et signature HMAC SHA-256
 *  - Vérification cryptographique et rejet des altérations/contrefaçons de QR Code
 *  - Cloisonnement facultaire et garantie zéro écriture sur les routes de consultation
 */
class ExamCandidatsConvocationsEnterpriseTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $adminFaculteA;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Matiere $matiere;

    private Room $salle;

    private SessionExamen $session;

    private Examen $examen;

    private Epreuve $epreuve;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté des Sciences',
            'code' => 'FS',
            'active' => true,
        ]);

        $this->faculteB = Faculte::create([
            'nom' => 'Faculté de Droit',
            'code' => 'FD',
            'active' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Responsable Scolarité Centrale',
            'email' => 'resp.scol.'.uniqid().'@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->adminFaculteA = User::creerCompteBackOffice([
            'name' => 'Admin Faculté Sciences',
            'email' => 'admin.fs.'.uniqid().'@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programme = Programme::create([
            'faculte_id' => $this->faculteA->id,
            'code' => 'INFO-L3',
            'nom' => 'Licence 3 Informatique',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);

        $niveau = Niveau::create([
            'programme_id' => $this->programme->id,
            'code' => 'L3',
            'nom' => 'Licence 3',
            'ordre' => 3,
        ]);

        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id,
            'code' => 'S5',
            'nom' => 'Semestre 5',
            'numero' => 5,
            'ordre' => 5,
        ]);

        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id,
            'programme_id' => $this->programme->id,
            'code' => 'UE-INF501',
            'nom' => 'Génie Logiciel Avancé',
            'credits' => 6,
            'coefficient' => 2,
        ]);

        $this->matiere = Matiere::create([
            'code' => 'GLA-301',
            'nom' => 'Architecture Logicielle',
            'ue_id' => $ue->id,
            'niveau' => 'L3',
            'semestre' => 5,
            'credits' => 6,
            'coefficient' => 2,
        ]);

        $this->salle = Room::create([
            'code' => 'AMPHI-TURING',
            'nom' => 'Amphithéâtre Alan Turing',
            'capacite' => 120,
            'actif' => true,
        ]);

        $this->session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-NORM-26',
            'nom' => 'Session Normale Janvier 2027',
            'type' => 'principale',
            'date_debut' => '2027-01-10',
            'date_fin' => '2027-01-25',
            'statut' => 'draft',
        ]);

        $this->examen = Examen::create([
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examens Semestre 5 Informatique',
            'type' => 'examen',
            'statut' => 'published',
            'responsable_id' => $this->admin->id,
        ]);

        $this->epreuve = Epreuve::create([
            'code' => 'EPR-GLA-01',
            'examen_id' => $this->examen->id,
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiere->id,
            'salle_id' => $this->salle->id,
            'date' => '2027-01-15',
            'heure_debut' => '08:30',
            'heure_fin' => '11:30',
            'statut' => 'published',
        ]);
    }

    private function creerEtudiant(string $nom, string $prenom, ?Faculte $faculte = null): Etudiant
    {
        $fac = $faculte ?? $this->faculteA;
        $etudiant = Etudiant::create([
            'cin' => 'AB'.random_int(100000, 999999),
            'nom' => $nom,
            'prenom' => $prenom,
            'email' => strtolower($nom).'.'.random_int(1000, 9999).'@univ.test',
            'date_naissance' => '2003-05-12',
            'sexe' => 'M',
            'faculte_id' => $fac->id,
            'niveau' => 'L3',
            'annee_academique' => '2026-2027',
            'adresse' => 'Campus Universitaire',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2023',
            'password' => 'Pass@12345',
        ]);

        $etudiant->forceFill(['num_inscription' => 'INS-2026-'.random_int(10000, 99999)])->save();

        return $etudiant;
    }

    private function attacherCandidat(Epreuve $epreuve, Etudiant $etudiant, ?string $anonymat = null): Candidat
    {
        return Candidat::create([
            'epreuve_id' => $epreuve->id,
            'etudiant_id' => $etudiant->id,
            'salle_id' => $epreuve->salle_id,
            'numero_anonyme' => $anonymat,
            'statut' => 'present',
        ]);
    }

    public function test_acces_non_authentifie_redirige(): void
    {
        $this->get(route('admin.evaluation.examens.convocations'))->assertRedirect();
        $this->get(route('admin.evaluation.examens.eligibilite'))->assertRedirect();
        $this->get(route('admin.examens.preparation.epreuves.emargement', $this->epreuve))->assertRedirect();
    }

    public function test_audit_eligibilite_epreuve_calcule_statistiques_exactes(): void
    {
        $e1 = $this->creerEtudiant('Alami', 'Karim');
        $e2 = $this->creerEtudiant('Benjelloun', 'Sofia');
        $this->attacherCandidat($this->epreuve, $e1, 'AN-0001');
        $this->attacherCandidat($this->epreuve, $e2, 'AN-0002');

        $orchestrateur = app(ExamCandidatsConvocationsOrchestratorService::class);
        $audit = $orchestrateur->auditerEligibiliteEpreuve($this->epreuve);

        $this->assertSame(2, $audit['total_candidats']);
        $this->assertSame(2, $audit['candidats_avec_anonymat']);
        $this->assertSame(0, $audit['candidats_sans_anonymat']);
        $this->assertFalse($audit['est_validee']);
        $this->assertSame('en_attente', $audit['statut_code']);
    }

    public function test_validation_population_cree_snapshot_et_assigne_numeros_anonymes_an(): void
    {
        $e1 = $this->creerEtudiant('Mansouri', 'Yassine');
        $e2 = $this->creerEtudiant('Tazi', 'Khadija');
        $this->attacherCandidat($this->epreuve, $e1);
        $this->attacherCandidat($this->epreuve, $e2);

        $this->actingAs($this->admin)
            ->post(route('admin.examens.preparation.epreuves.population', $this->epreuve))
            ->assertRedirect()
            ->assertSessionHas('succes');

        $this->epreuve->refresh();

        $this->assertNotNull($this->epreuve->population_validated_at);
        $this->assertSame(2, $this->epreuve->population_effectif);
        $this->assertSame(64, strlen((string) $this->epreuve->population_hash));

        $candidats = $this->epreuve->candidats()->get();
        foreach ($candidats as $candidat) {
            $this->assertNotNull($candidat->numero_anonyme);
            $this->assertStringStartsWith('AN-', $candidat->numero_anonyme);
            $this->assertNotSame($candidat->etudiant->cin, $candidat->numero_anonyme);
            $this->assertNotSame($candidat->etudiant->num_inscription, $candidat->numero_anonyme);
        }
    }

    public function test_detection_derive_population_si_nouveaux_inscrits_apres_validation(): void
    {
        $e1 = $this->creerEtudiant('Amrani', 'Mehdi');
        $this->attacherCandidat($this->epreuve, $e1);

        // Validation initiale de la population
        app(PreparationExamenService::class)->validerPopulation($this->epreuve, $this->admin->id);
        $this->epreuve->refresh();

        $orchestrateur = app(ExamCandidatsConvocationsOrchestratorService::class);
        $auditAvant = $orchestrateur->auditerEligibiliteEpreuve($this->epreuve);
        $this->assertFalse($auditAvant['est_derivee']);
        $this->assertSame('validee', $auditAvant['statut_code']);

        // Ajout d'un nouvel étudiant après le figeage (dérive de population)
        $e2 = $this->creerEtudiant('Chraibi', 'Leila');
        $this->attacherCandidat($this->epreuve, $e2);

        $this->epreuve->refresh();
        $auditApres = $orchestrateur->auditerEligibiliteEpreuve($this->epreuve);

        $this->assertTrue($auditApres['est_derivee']);
        $this->assertSame('derivee', $auditApres['statut_code']);
        $this->assertSame(1, $auditApres['ecart_derive']);
        $this->assertTrue($auditApres['blocage_ouverture']);
    }

    public function test_revalidation_preserve_les_anonymats_precedemment_assignes(): void
    {
        $e1 = $this->creerEtudiant('Ziani', 'Adam');
        $this->attacherCandidat($this->epreuve, $e1);

        $prepService = app(PreparationExamenService::class);
        $prepService->validerPopulation($this->epreuve, $this->admin->id);

        $candidat1 = $this->epreuve->candidats()->where('etudiant_id', $e1->id)->first();
        $anonymat1 = $candidat1->numero_anonyme;
        $this->assertNotNull($anonymat1);

        // Ajout d'un 2ème étudiant et revalidation
        $e2 = $this->creerEtudiant('Berrada', 'Fatima');
        $this->attacherCandidat($this->epreuve, $e2);

        $prepService->validerPopulation($this->epreuve, $this->admin->id);

        $candidat1Apres = $this->epreuve->candidats()->where('etudiant_id', $e1->id)->first();
        $candidat2 = $this->epreuve->candidats()->where('etudiant_id', $e2->id)->first();

        // Le premier conserve son numéro d'origine
        $this->assertSame($anonymat1, $candidat1Apres->numero_anonyme);
        // Le second a un numéro distinct et non nul
        $this->assertNotNull($candidat2->numero_anonyme);
        $this->assertNotSame($anonymat1, $candidat2->numero_anonyme);
    }

    public function test_generation_liste_emargement_pdf_produit_un_document_valide(): void
    {
        $e1 = $this->creerEtudiant('Kabbaj', 'Anas');
        $this->attacherCandidat($this->epreuve, $e1, 'AN-0001');

        $this->actingAs($this->admin);

        $convocationService = app(ConvocationService::class);
        $reponse = $convocationService->listeEmargement($this->epreuve);

        $this->assertSame(200, $reponse->getStatusCode());
        $this->assertSame('application/pdf', $reponse->headers->get('Content-Type'));
        $this->assertStringContainsString('emargement-'.$this->epreuve->id, (string) $reponse->headers->get('Content-Disposition'));
    }

    public function test_generation_convocation_pdf_avec_qr_code_securise(): void
    {
        $e1 = $this->creerEtudiant('Sebti', 'Rim');
        $candidat = $this->attacherCandidat($this->epreuve, $e1, 'AN-0042');

        $this->actingAs($this->admin);

        $convocationService = app(ConvocationService::class);
        $reponse = $convocationService->convocation($candidat);

        $this->assertSame(200, $reponse->getStatusCode());
        $this->assertSame('application/pdf', $reponse->headers->get('Content-Type'));
        $this->assertStringContainsString('convocation-'.$candidat->id.'.pdf', (string) $reponse->headers->get('Content-Disposition'));
    }

    public function test_verification_cryptographique_du_payload_qr_code_anti_fraude(): void
    {
        $e1 = $this->creerEtudiant('Idrissi', 'Nawal');
        $candidat = $this->attacherCandidat($this->epreuve, $e1, 'AN-0099');

        $orchestrateur = app(ExamCandidatsConvocationsOrchestratorService::class);
        $payload = $orchestrateur->genererPayloadQrCode($candidat);

        // 1. Vérification avec signature authentique
        $resultat = $orchestrateur->verifierPayloadQrCode($payload);
        $this->assertTrue($resultat['valide']);
        $this->assertSame('UNIR', $resultat['donnees']['app']);
        $this->assertSame($candidat->id, $resultat['donnees']['cid']);
        $this->assertSame('AN-0099', $resultat['donnees']['ano']);

        // 2. Falsification du numéro d'anonymat ou de la salle dans le payload
        $donneesAlteres = json_decode($payload, true);
        $donneesAlteres['ano'] = 'AN-0001'; // tentative de substitution
        $payloadFalsifie = json_encode($donneesAlteres);

        $resultatFalsifie = $orchestrateur->verifierPayloadQrCode($payloadFalsifie);
        $this->assertFalse($resultatFalsifie['valide']);
        $this->assertStringContainsString('Signature cryptographique invalide', $resultatFalsifie['message']);
    }

    public function test_cloisonnement_facultaire_et_zero_ecriture_en_consultation(): void
    {
        // Créer un groupe rattaché à la faculté B
        $groupeB = Group::create([
            'faculte_id' => $this->faculteB->id,
            'code' => 'DROIT-L1',
            'nom' => 'Licence 1 Droit',
            'niveau' => 'L1',
            'capacite' => 60,
            'actif' => true,
        ]);

        $epreuveB = Epreuve::create([
            'code' => 'EPR-DROIT-01',
            'examen_id' => $this->examen->id,
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiere->id,
            'groupe_id' => $groupeB->id,
            'date' => '2027-01-16',
            'heure_debut' => '09:00',
            'heure_fin' => '12:00',
            'statut' => 'published',
        ]);

        // Un admin restreint à la faculté A ne peut pas accéder à l'émargement d'une épreuve de la faculté B
        $this->actingAs($this->adminFaculteA)
            ->get(route('admin.examens.preparation.epreuves.emargement', $epreuveB))
            ->assertNotFound();

        // Vérification de la garantie zéro écriture sur les requêtes GET
        $nbCandidatsAvant = Candidat::count();
        $nbEpreuvesAvant = Epreuve::count();

        $this->actingAs($this->admin)
            ->get(route('admin.evaluation.examens.convocations'))
            ->assertOk();

        $this->actingAs($this->admin)
            ->get(route('admin.evaluation.examens.eligibilite', ['epreuve_id' => $this->epreuve->id]))
            ->assertOk();

        $this->assertSame($nbCandidatsAvant, Candidat::count());
        $this->assertSame($nbEpreuvesAvant, Epreuve::count());
    }

    public function test_vue_convocations_affiche_kpi_et_table_sans_erreur(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.evaluation.examens.convocations'))
            ->assertOk()
            ->assertSee('Convocations aux examens')
            ->assertSee('Épreuves publiées')
            ->assertSee('Candidats convoqués')
            ->assertSee('Sécurisation Anti-Fraude')
            ->assertSee('Interdiction formelle pré-publication');
    }

    public function test_vue_eligibilite_affiche_statut_et_bouton_revalidation(): void
    {
        $e1 = $this->creerEtudiant('Naciri', 'Hassan');
        $this->attacherCandidat($this->epreuve, $e1, 'AN-0001');

        $this->actingAs($this->admin)
            ->get(route('admin.evaluation.examens.eligibilite', ['epreuve_id' => $this->epreuve->id]))
            ->assertOk()
            ->assertSee('Éligibilité des candidats')
            ->assertSee('AN-0001')
            ->assertSee('Architecture Logicielle');
    }
}
