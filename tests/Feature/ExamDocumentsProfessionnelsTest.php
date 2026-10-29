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
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite de tests pour la zone d'impression professionnelle des examens finaux (LOT 6).
 *
 * Couvre :
 * 1. Disponibilité de la section « Documents professionnels — Aperçu et impression » dans chaque option.
 * 2. Catalogue dynamique des documents autorisés.
 * 3. Aperçu HTML immersif et JSON AJAX.
 * 4. Génération PDF A4 certifiée (%PDF binary header).
 * 5. Impression navigateur avec styles A4 et auto-print.
 * 6. Cloisonnement facultaire et prévention anti-IDOR.
 * 7. Non-mutation des données métier pendant les exports.
 * 8. Gestion déterministe des états vides.
 */
class ExamDocumentsProfessionnelsTest extends TestCase
{
    use RefreshDatabase;

    private User $adminCentral;

    private User $adminFaculteA;

    private User $adminFaculteB;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private SessionExamen $sessionA;

    private SessionExamen $sessionB;

    private Examen $examenA;

    private Examen $examenB;

    private Epreuve $epreuveA;

    private Epreuve $epreuveB;

    private Room $salleA;

    private Room $salleB;

    private Group $groupeA;

    private Matiere $matiereA;

    private Matiere $matiereB;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté des Sciences Exactes',
            'code' => 'FSE',
            'active' => true,
        ]);

        $this->faculteB = Faculte::create([
            'nom' => 'Faculté de Médecine',
            'code' => 'FMED',
            'active' => true,
        ]);

        $this->adminCentral = User::creerCompteBackOffice([
            'name' => 'Directeur Général Examens',
            'email' => 'dir.central.'.uniqid().'@univ.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->adminFaculteA = User::creerCompteBackOffice([
            'name' => 'Doyen Sciences Exactes',
            'email' => 'doyen.fse.'.uniqid().'@univ.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $this->adminFaculteB = User::creerCompteBackOffice([
            'name' => 'Doyen Médecine',
            'email' => 'doyen.fmed.'.uniqid().'@univ.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $progA = Programme::create([
            'faculte_id' => $this->faculteA->id,
            'code' => 'MATH-L3',
            'nom' => 'Licence 3 Mathématiques',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);

        $niveauA = Niveau::create([
            'programme_id' => $progA->id,
            'code' => 'L3',
            'nom' => 'Licence 3',
            'ordre' => 3,
        ]);

        $semestreA = SemestreLmd::create([
            'niveau_id' => $niveauA->id,
            'code' => 'S5',
            'nom' => 'Semestre 5',
            'numero' => 5,
            'ordre' => 5,
        ]);

        $ueA = UniteEnseignement::create([
            'semestre_id' => $semestreA->id,
            'programme_id' => $progA->id,
            'code' => 'UE-MTH501',
            'nom' => 'Analyse Complexe',
            'credits' => 6,
            'coefficient' => 2,
        ]);

        $this->matiereA = Matiere::create([
            'code' => 'ANA-501',
            'nom' => 'Analyse Complexe Avancée',
            'ue_id' => $ueA->id,
            'niveau' => 'L3',
            'semestre' => 5,
            'credits' => 6,
            'coefficient' => 2,
        ]);

        $progB = Programme::create([
            'faculte_id' => $this->faculteB->id,
            'code' => 'MED-D1',
            'nom' => 'Diplôme Médecine 1',
            'duree_annees' => 6,
            'semestres_nombre' => 12,
            'credits_requis' => 360,
        ]);

        $niveauB = Niveau::create([
            'programme_id' => $progB->id,
            'code' => 'D1',
            'nom' => 'Doctorat 1',
            'ordre' => 1,
        ]);

        $semestreB = SemestreLmd::create([
            'niveau_id' => $niveauB->id,
            'code' => 'S1-MED',
            'nom' => 'Semestre 1 Med',
            'numero' => 1,
            'ordre' => 1,
        ]);

        $ueB = UniteEnseignement::create([
            'semestre_id' => $semestreB->id,
            'programme_id' => $progB->id,
            'code' => 'UE-MED101',
            'nom' => 'Anatomie Générale',
            'credits' => 6,
            'coefficient' => 2,
        ]);

        $this->matiereB = Matiere::create([
            'code' => 'ANA-MED-101',
            'nom' => 'Anatomie Humaine',
            'ue_id' => $ueB->id,
            'niveau' => 'D1',
            'semestre' => 1,
            'credits' => 6,
            'coefficient' => 2,
        ]);

        $this->salleA = Room::create([
            'code' => 'AMPHI-NEWTON',
            'nom' => 'Amphithéâtre Newton',
            'capacite' => 200,
            'faculte_id' => $this->faculteA->id,
            'actif' => true,
        ]);

        $this->salleB = Room::create([
            'code' => 'AMPHI-PASTEUR',
            'nom' => 'Amphithéâtre Pasteur',
            'capacite' => 180,
            'faculte_id' => $this->faculteB->id,
            'actif' => true,
        ]);

        $this->groupeA = Group::create([
            'code' => 'GRP-MATH-L3-A',
            'nom' => 'Groupe Math L3 Principal',
            'faculte_id' => $this->faculteA->id,
            'capacite' => 45,
            'niveau' => 'L3',
        ]);

        $this->sessionA = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculteA->id,
            'code' => 'SESS-2026-FSE-1',
            'nom' => 'Session Principale Sciences 2026',
            'date_debut' => '2026-11-01',
            'date_fin' => '2026-11-20',
            'statut' => 'open',
            'type' => 'normale',
        ]);

        $this->sessionB = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculteB->id,
            'code' => 'SESS-2026-FMED-1',
            'nom' => 'Session Principale Médecine 2026',
            'date_debut' => '2026-11-01',
            'date_fin' => '2026-11-20',
            'statut' => 'open',
            'type' => 'normale',
        ]);

        $this->examenA = Examen::create([
            'session_id' => $this->sessionA->id,
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'EXAM-MTH-2026',
            'libelle' => 'Examen Final Analyse Complexe',
            'statut' => 'published',
            'programme_id' => $progA->id,
            'semestre_lmd_id' => $semestreA->id,
            'niveau_id' => $niveauA->id,
        ]);

        $this->examenB = Examen::create([
            'session_id' => $this->sessionB->id,
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'EXAM-MED-2026',
            'libelle' => 'Examen Final Anatomie',
            'statut' => 'draft',
            'programme_id' => $progB->id,
            'semestre_lmd_id' => $semestreB->id,
            'niveau_id' => $niveauB->id,
        ]);

        $this->epreuveA = Epreuve::create([
            'examen_id' => $this->examenA->id,
            'session_id' => $this->sessionA->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiereA->id,
            'salle_id' => $this->salleA->id,
            'faculte_id' => $this->faculteA->id,
            'code' => 'EPR-ANA-01',
            'nom' => 'Épreuve Écrite Analyse',
            'date' => '2026-11-05',
            'heure_debut' => '08:30:00',
            'heure_fin' => '11:30:00',
            'duree_minutes' => 180,
            'statut' => 'published',
        ]);

        $this->epreuveB = Epreuve::create([
            'examen_id' => $this->examenB->id,
            'session_id' => $this->sessionB->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiereB->id,
            'salle_id' => $this->salleB->id,
            'faculte_id' => $this->faculteB->id,
            'code' => 'EPR-MED-01',
            'nom' => 'Épreuve Anatomie',
            'date' => '2026-11-06',
            'heure_debut' => '09:00:00',
            'heure_fin' => '12:00:00',
            'duree_minutes' => 180,
            'statut' => 'draft',
        ]);

        // Création de 2 candidats pour epreuveA
        $etud1 = Etudiant::create([
            'cin' => 'CD'.random_int(100000, 999999),
            'matricule' => 'ETUD-MTH-001',
            'nom' => 'Benali',
            'prenom' => 'Karim',
            'email' => 'karim.benali.'.uniqid().'@etudiant.test',
            'date_naissance' => '2004-02-15',
            'groupe_id' => $this->groupeA->id,
            'faculte_id' => $this->faculteA->id,
            'sexe' => 'M',
            'niveau' => 'L3',
            'annee_academique' => '2026-2027',
            'adresse' => 'Campus',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2023',
            'password' => 'Pass@12345',
        ]);

        $etud2 = Etudiant::create([
            'cin' => 'CD'.random_int(100000, 999999),
            'matricule' => 'ETUD-MTH-002',
            'nom' => 'Mansouri',
            'prenom' => 'Amira',
            'email' => 'amira.mansouri.'.uniqid().'@etudiant.test',
            'date_naissance' => '2004-05-20',
            'groupe_id' => $this->groupeA->id,
            'faculte_id' => $this->faculteA->id,
            'sexe' => 'F',
            'niveau' => 'L3',
            'annee_academique' => '2026-2027',
            'adresse' => 'Campus',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2023',
            'password' => 'Pass@12345',
        ]);

        Candidat::create([
            'epreuve_id' => $this->epreuveA->id,
            'etudiant_id' => $etud1->id,
            'salle_id' => $this->salleA->id,
            'numero_place' => 'A-01',
            'est_present' => true,
        ]);

        Candidat::create([
            'epreuve_id' => $this->epreuveA->id,
            'etudiant_id' => $etud2->id,
            'salle_id' => $this->salleA->id,
            'numero_place' => 'A-02',
            'est_present' => false,
        ]);
    }

    /**
     * Test 1 : Vérifie que la zone documentaire est présente et rendue dans chaque écran cible.
     */
    public function test_zone_documents_professionnels_est_presente_dans_chaque_option(): void
    {
        $routesCibles = [
            'groupes' => route('admin.evaluation.examens.groupes'),
            'groupes_mode_b' => route('admin.evaluation.examens.groupes', ['mode' => 'B']),
            'salles' => route('admin.evaluation.examens.salles'),
            'planification' => route('admin.evaluation.examens.planification'),
            'surveillance' => route('admin.evaluation.examens.surveillance'),
            'convocations' => route('admin.evaluation.examens.convocations'),
            'historique' => route('admin.evaluation.examens.historique'),
            'publication' => route('admin.evaluation.examens.publication'),
        ];

        foreach ($routesCibles as $option => $url) {
            $response = $this->actingAs($this->adminCentral)->get($url);
            $response->assertStatus(200);
            $response->assertSee('Documents professionnels — Aperçu et impression', false);
            $response->assertSee('Aperçu avant impression', false);
            $response->assertSee('Générer PDF officiel (A4)', false);
        }
    }

    /**
     * Test 2 : Vérifie le catalogue JSON des documents autorisés pour une option.
     */
    public function test_catalogue_retourne_les_documents_autorises_par_option(): void
    {
        $response = $this->actingAs($this->adminCentral)
            ->getJson(route('admin.evaluation.examens.documents.catalogue', ['option' => 'groupes']));

        $response->assertStatus(200)
            ->assertJson([
                'succes' => true,
                'option' => 'groupes',
            ])
            ->assertJsonFragment(['id' => 'liste_candidats_groupe_virtuel'])
            ->assertJsonFragment(['id' => 'recapitulatif_regroupements_d04']);
    }

    /**
     * Test 3 : Aperçu HTML et rendu avec filigranes officiels / métadonnées.
     */
    public function test_apercu_document_retourne_html_conforme_avec_metadonnees_et_filigrane(): void
    {
        // 1. Aperçu d'un document publié (epreuveA est publiée -> DOCUMENT OFFICIEL PUBLIÉ)
        $response = $this->actingAs($this->adminCentral)->get(route('admin.evaluation.examens.documents.apercu', [
            'type_document' => 'liste_candidats_groupe_virtuel',
            'epreuve_id' => $this->epreuveA->id,
        ]));

        $response->assertStatus(200);
        $response->assertSee('Liste officielle des candidats', false);
        $response->assertSee('DOCUMENT OFFICIEL PUBLIÉ', false);
        $response->assertSee('BENALI', false);
        $response->assertSee('MANSOURI', false);
        $response->assertSee('Visas, cachets et approbations institutionnelles', false);

        // 2. Aperçu JSON AJAX
        $responseAjax = $this->actingAs($this->adminCentral)->getJson(route('admin.evaluation.examens.documents.apercu', [
            'type_document' => 'liste_candidats_groupe_virtuel',
            'epreuve_id' => $this->epreuveA->id,
        ]));

        $responseAjax->assertStatus(200)
            ->assertJson([
                'succes' => true,
                'statutDoc' => 'officiel',
                'filigrane' => 'DOCUMENT OFFICIEL PUBLIÉ',
            ]);
    }

    /**
     * Test 4 : Téléchargement d'un PDF certifié A4 pour chaque catégorie de document.
     */
    public function test_telechargement_pdf_genere_un_flux_binaire_valide(): void
    {
        $typesDocs = [
            'liste_candidats_groupe_virtuel' => ['epreuve_id' => $this->epreuveA->id],
            'recapitulatif_regroupements_d04' => ['session_id' => $this->sessionA->id],
            'planning_examens_general' => ['session_id' => $this->sessionA->id],
            'fiche_salle_capacite_d04' => ['salle_id' => $this->salleA->id],
            'ordre_mission_surveillants' => ['epreuve_id' => $this->epreuveA->id],
            'lot_convocations_epreuve' => ['epreuve_id' => $this->epreuveA->id],
            'fiche_declaration_incident' => ['epreuve_id' => $this->epreuveA->id],
            'pv_approbation_4_yeux' => ['examen_id' => $this->examenA->id],
        ];

        foreach ($typesDocs as $type => $filtres) {
            $params = array_merge(['type_document' => $type], $filtres);
            $response = $this->actingAs($this->adminCentral)
                ->get(route('admin.evaluation.examens.documents.telecharger', $params));

            $response->assertStatus(200);
            $response->assertHeader('Content-Type', 'application/pdf');

            // Vérification de la signature binaire PDF A4
            $contenu = $response->getContent();
            $this->assertStringStartsWith('%PDF-', $contenu, "Le document [{$type}] doit débuter par l'en-tête binaire %PDF-");
            $this->assertGreaterThan(1000, strlen($contenu), "Le document [{$type}] doit contenir au moins 1 Ko de données PDF");
        }
    }

    /**
     * Test 5 : Route d'impression navigateur avec en-tête d'impression et auto-print.
     */
    public function test_route_impression_navigateur(): void
    {
        $response = $this->actingAs($this->adminCentral)->get(route('admin.evaluation.examens.documents.imprimer', [
            'type_document' => 'planning_examens_general',
            'session_id' => $this->sessionA->id,
            'auto' => 1,
        ]));

        $response->assertStatus(200);
        $response->assertSee('window.print()', false);
        $response->assertSee('UniRegistre', false);
    }

    /**
     * Test 6 : Étancheité de faculté et protection anti-IDOR.
     * Un administrateur de Faculté A ne peut pas imprimer les documents d'une épreuve de Faculté B.
     */
    public function test_securite_anti_idor_et_cloisonnement_faculte(): void
    {
        // 1. Doyen Faculté A tente d'imprimer l'épreuve de Faculté B -> Refus 403
        $responseIdor = $this->actingAs($this->adminFaculteA)->get(route('admin.evaluation.examens.documents.apercu', [
            'type_document' => 'liste_candidats_groupe_virtuel',
            'epreuve_id' => $this->epreuveB->id,
        ]));
        $responseIdor->assertStatus(403);

        // 2. Doyen Faculté A tente d'imprimer l'épreuve de Faculté B en PDF -> Refus 403
        $responsePdfIdor = $this->actingAs($this->adminFaculteA)->get(route('admin.evaluation.examens.documents.telecharger', [
            'type_document' => 'liste_candidats_groupe_virtuel',
            'epreuve_id' => $this->epreuveB->id,
        ]));
        $responsePdfIdor->assertStatus(403);

        // 3. Doyen Faculté A accède à sa propre épreuve -> Autorisé 200
        $responseAutorisee = $this->actingAs($this->adminFaculteA)->get(route('admin.evaluation.examens.documents.apercu', [
            'type_document' => 'liste_candidats_groupe_virtuel',
            'epreuve_id' => $this->epreuveA->id,
        ]));
        $responseAutorisee->assertStatus(200);
    }

    /**
     * Test 7 : Non-mutation des données métier pendant la consultation ou l'export.
     */
    public function test_non_mutation_des_donnees_metier_pendant_export(): void
    {
        $candidatsAvant = Candidat::count();
        $epreuvesAvant = Epreuve::count();
        $examensAvant = Examen::count();
        $statutEpreuveAvant = $this->epreuveA->fresh()->statut;

        // Téléchargement multiple de documents
        $this->actingAs($this->adminCentral)->get(route('admin.evaluation.examens.documents.telecharger', [
            'type_document' => 'liste_candidats_groupe_virtuel',
            'epreuve_id' => $this->epreuveA->id,
        ]));

        $this->actingAs($this->adminCentral)->get(route('admin.evaluation.examens.documents.telecharger', [
            'type_document' => 'pv_approbation_4_yeux',
            'examen_id' => $this->examenA->id,
        ]));

        // Vérification de la stricte intégrité
        $this->assertEquals($candidatsAvant, Candidat::count());
        $this->assertEquals($epreuvesAvant, Epreuve::count());
        $this->assertEquals($examensAvant, Examen::count());
        $this->assertEquals($statutEpreuveAvant, $this->epreuveA->fresh()->statut);
    }

    /**
     * Test 8 : État vide explicite si aucune donnée ne correspond aux filtres.
     */
    public function test_gestion_etat_vide_sans_exception(): void
    {
        // Création d'une épreuve vide sans candidat
        $epreuveVide = Epreuve::create([
            'examen_id' => $this->examenA->id,
            'session_id' => $this->sessionA->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiereA->id,
            'faculte_id' => $this->faculteA->id,
            'code' => 'EPR-VIDE-99',
            'nom' => 'Épreuve Sans Candidat',
            'date' => '2026-11-20',
            'heure_debut' => '08:00:00',
            'heure_fin' => '10:00:00',
            'statut' => 'draft',
        ]);

        $response = $this->actingAs($this->adminCentral)->get(route('admin.evaluation.examens.documents.apercu', [
            'type_document' => 'liste_candidats_groupe_virtuel',
            'epreuve_id' => $epreuveVide->id,
        ]));

        $response->assertStatus(200);
        $response->assertSee('Aucune donnée correspondante enregistrée', false);
        $response->assertSee('BROUILLON - NON CERTIFIÉ', false);
    }

    /**
     * Test 9 : Vérifie que le document officiel imprimable et le PDF sont strictement
     * exempts de boutons web (Imprimer, Télécharger PDF, Fermer, etc.).
     */
    public function test_absence_totale_boutons_navigation_dans_gabarit_impression_et_pdf(): void
    {
        $response = $this->actingAs($this->adminCentral)->get(route('admin.evaluation.examens.documents.apercu', [
            'type_document' => 'repartition_surveillance_epreuve',
            'epreuve_id' => $this->epreuveA->id,
        ]));

        $response->assertStatus(200);
        $html = $response->getContent();

        // Le document officiel ne doit contenir aucun bouton d'interface web
        $this->assertStringNotContainsString('barre-actions-navigateur', $html);
        $this->assertStringNotContainsString('<button', $html);
        $this->assertStringNotContainsString('window.close()', $html);
        $this->assertStringNotContainsString('>Télécharger PDF<', $html);

        // Vérification sur la route d'impression
        $responsePrint = $this->actingAs($this->adminCentral)->get(route('admin.evaluation.examens.documents.imprimer', [
            'type_document' => 'repartition_surveillance_epreuve',
            'epreuve_id' => $this->epreuveA->id,
            'auto' => 0,
        ]));

        $responsePrint->assertStatus(200);
        $htmlPrint = $responsePrint->getContent();
        $this->assertStringNotContainsString('barre-actions-navigateur', $htmlPrint);
        $this->assertStringNotContainsString('<button', $htmlPrint);
        $this->assertStringNotContainsString('window.close()', $htmlPrint);
    }
}
