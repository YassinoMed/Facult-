<?php

namespace Tests\Feature;

use App\Models\DossierCorrection;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Services\SuiviDossierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests fonctionnels pour LOOP 10 :
 * Suivi du dossier étudiant, chronologie d'instruction, isolation IDOR et anti-énumération.
 */
class InscriptionSuiviDossierTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private Faculte $faculte;

    private Programme $programme;

    private Etudiant $etudiantA;

    private Etudiant $etudiantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = User::factory()->create();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences de Rabat',
            'code' => 'FSR',
            'active' => true,
        ]);

        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'L-INFO',
            'nom' => 'Licence Informatique',
            'statut' => Programme::STATUT_ACTIF,
            'capacite' => 100,
        ]);

        Niveau::create([
            'programme_id' => $this->programme->id,
            'code' => 'L1',
            'nom' => 'Licence 1',
            'ordre' => 1,
            'actif' => true,
        ]);

        $this->etudiantA = Etudiant::create([
            'cin' => 'KA100001',
            'nom' => 'Benali',
            'prenom' => 'Karim',
            'email' => 'karim.benali@test.ma',
            'telephone' => '+212611111111',
            'date_naissance' => '2005-04-12',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Rabat Agdal',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2025',
            'password' => bcrypt('MotDePasse123!'),
        ]);

        $this->etudiantB = Etudiant::create([
            'cin' => 'BK200002',
            'nom' => 'Mansouri',
            'prenom' => 'Yasmine',
            'email' => 'yasmine.mansouri@test.ma',
            'telephone' => '+212622222222',
            'date_naissance' => '2005-08-20',
            'sexe' => 'F',
            'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Casablanca Maarif',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2025',
            'password' => bcrypt('MotDePasse123!'),
        ]);
    }

    /**
     * Test 1 : Chaque statut a un libellé compréhensible et conforme à l'état de la base.
     */
    public function test_statuts_ont_des_libelles_comprehensibles_et_coherents(): void
    {
        $service = app(SuiviDossierService::class);

        // État initial : en_attente
        $this->assertSame('en_attente', $this->etudiantA->statut);
        $this->assertSame('En attente de validation académique', $service->libelleStatut($this->etudiantA->statut));

        // État validé
        $this->etudiantA->definirStatut('valide');
        $this->assertSame('Inscription validée', $service->libelleStatut($this->etudiantA->statut));

        // État rejeté
        $this->etudiantA->definirStatut('rejete');
        $this->assertSame('Dossier non retenu', $service->libelleStatut($this->etudiantA->statut));

        // État avec correction active demandée
        $this->etudiantA->definirStatut('en_attente');
        DossierCorrection::create([
            'etudiant_id' => $this->etudiantA->id,
            'propose_par' => $this->agent->id,
            'champ' => 'adresse',
            'ancienne_valeur' => 'Rabat Agdal',
            'nouvelle_valeur' => 'Rabat Hassan',
            'motif' => 'Préciser le numéro de rue',
            'statut' => 'proposee',
        ]);

        $synthese = $service->synthese($this->etudiantA);
        $this->assertTrue($synthese['a_des_corrections']);
        $this->assertSame('Complément d\'information requis', $synthese['statut_libelle']);
    }

    /**
     * Test 2 : Protection IDOR stricte — un étudiant ne peut consulter que son propre dossier.
     */
    public function test_etudiant_authentifie_ne_peut_consulter_que_son_propre_dossier(): void
    {
        // Connexion en session en tant qu'étudiant A
        $reponse = $this->withSession(['etudiant_auth_id' => $this->etudiantA->id])
            ->get(route('etudiant.suivi'));

        $reponse->assertOk();
        $reponse->assertSee($this->etudiantA->nom_complet);
        $reponse->assertSee($this->etudiantA->num_inscription);
        $reponse->assertDontSee($this->etudiantB->nom_complet);
        $reponse->assertDontSee($this->etudiantB->num_inscription);

        // Connexion en tant qu'étudiant B
        $reponseB = $this->withSession(['etudiant_auth_id' => $this->etudiantB->id])
            ->get(route('etudiant.suivi'));

        $reponseB->assertOk();
        $reponseB->assertSee($this->etudiantB->nom_complet);
        $reponseB->assertSee($this->etudiantB->num_inscription);
        $reponseB->assertDontSee($this->etudiantA->nom_complet);
    }

    /**
     * Test 3 : Accès au suivi protégé sans session redirige vers la connexion.
     */
    public function test_acces_suivi_authentifie_sans_session_redirige_vers_connexion(): void
    {
        $reponse = $this->get(route('etudiant.suivi'));
        $reponse->assertRedirect(route('etudiant.connexion.show'));
    }

    /**
     * Test 4 : Demande de complément explique précisément la prochaine action et les motifs.
     */
    public function test_demande_de_complement_explique_action_et_motifs(): void
    {
        DossierCorrection::create([
            'etudiant_id' => $this->etudiantA->id,
            'propose_par' => $this->agent->id,
            'champ' => 'code_postal',
            'ancienne_valeur' => '10000',
            'nouvelle_valeur' => '10090',
            'motif' => 'Code postal non conforme à la commune déclarée',
            'statut' => 'proposee',
        ]);

        $service = app(SuiviDossierService::class);
        $synthese = $service->synthese($this->etudiantA);

        $this->assertTrue($synthese['a_des_corrections']);
        $this->assertSame('action_requise', $synthese['prochaine_action']['type']);
        $this->assertStringContainsString('Code postal non conforme', $synthese['corrections_demandees'][0]['motif']);

        $reponse = $this->withSession(['etudiant_auth_id' => $this->etudiantA->id])
            ->get(route('etudiant.suivi'));

        $reponse->assertOk();
        $reponse->assertSee('Complément requis');
        $reponse->assertSee('Code postal non conforme à la commune déclarée');
    }

    /**
     * Test 5 : Historique et chronologie d'instruction cohérents avec les jalons.
     */
    public function test_chronologie_instruction_coherente_avec_les_jalons(): void
    {
        $service = app(SuiviDossierService::class);
        $chronologie = $service->construireChronologie($this->etudiantA, [], []);

        $cles = array_column($chronologie, 'cle');
        $this->assertSame(['depot', 'verification', 'instruction', 'decision'], $cles);

        // Le premier jalon 'depot' est complete
        $this->assertSame('complete', $chronologie[0]['statut']);
        $this->assertSame('Dépôt de la candidature', $chronologie[0]['titre']);

        // Jalon de décision finale à venir tant que en_attente
        $this->assertSame('a_venir', $chronologie[3]['statut']);

        // Si l'étudiant est validé, le jalon décision devient valide
        $this->etudiantA->definirStatut('valide');
        $chronoValidee = $service->construireChronologie($this->etudiantA, [], []);
        $this->assertSame('valide', $chronoValidee[3]['statut']);
    }

    /**
     * Test 6 : Consultation publique sécurisée avec référence et CIN exacts réussit.
     */
    public function test_consultation_publique_reussie_avec_second_facteur_valide(): void
    {
        $reponse = $this->post(route('inscription.suivi.rechercher'), [
            'reference' => $this->etudiantA->num_inscription,
            'secondaire' => $this->etudiantA->cin,
        ]);

        $reponse->assertOk();
        $reponse->assertSee($this->etudiantA->nom_complet);
        $reponse->assertSee($this->etudiantA->num_inscription);
        $reponse->assertSee('En attente de validation académique');
    }

    /**
     * Test 7 : Consultation publique sécurisée avec référence et date de naissance réussit.
     */
    public function test_consultation_publique_reussie_avec_date_de_naissance(): void
    {
        $reponse = $this->post(route('inscription.suivi.rechercher'), [
            'reference' => $this->etudiantA->num_inscription,
            'secondaire' => '12/04/2005',
        ]);

        $reponse->assertOk();
        $reponse->assertSee($this->etudiantA->nom_complet);
        $reponse->assertSee($this->etudiantA->num_inscription);
    }

    /**
     * Test 8 : Référence inconnue ou second facteur erroné donne un comportement neutre anti-énumération.
     */
    public function test_reference_inconnue_reponse_neutre_sans_fuite_de_donnees(): void
    {
        // 1. Référence complètement inventée
        $reponseInconnue = $this->post(route('inscription.suivi.rechercher'), [
            'reference' => 'INS-2025-99999',
            'secondaire' => 'KA100001',
        ]);

        $reponseInconnue->assertOk();
        $reponseInconnue->assertSee('Aucun dossier correspondant n’a été trouvé avec ces références');
        $reponseInconnue->assertDontSee($this->etudiantA->nom_complet);

        // 2. Référence existante mais mauvais CIN (attaque par force brute / énumération)
        $reponseMauvaisCin = $this->post(route('inscription.suivi.rechercher'), [
            'reference' => $this->etudiantA->num_inscription,
            'secondaire' => 'FAUXCIN01',
        ]);

        $reponseMauvaisCin->assertOk();
        // Le message d'erreur est EXACTEMENT identique, aucune confirmation de l'existence du dossier
        $reponseMauvaisCin->assertSee('Aucun dossier correspondant n’a été trouvé avec ces références');
        $reponseMauvaisCin->assertDontSee($this->etudiantA->nom_complet);
    }

    /**
     * Test 9 : Récépissé proposé en téléchargement dès que le dossier est validé.
     */
    public function test_dossier_valide_propose_recepisse_officiel(): void
    {
        $this->etudiantA->definirStatut('valide');

        $reponse = $this->withSession(['etudiant_auth_id' => $this->etudiantA->id])
            ->get(route('etudiant.suivi'));

        $reponse->assertOk();
        $reponse->assertSee('Inscription validée');
        $reponse->assertSee('Télécharger le récépissé officiel');
    }

    /**
     * Test 10 : Branche JSON embarquée (popup d'accueil, sans navigation) :
     * même validation, même service, même neutralité anti-énumération.
     */
    public function test_branche_json_embarquee_popup_sans_navigation(): void
    {
        // Succès : enveloppe JSON avec la synthèse publique (données masquées uniquement).
        $reponse = $this->postJson(route('inscription.suivi.rechercher'), [
            'reference' => $this->etudiantA->num_inscription,
            'secondaire' => $this->etudiantA->cin,
        ]);

        $reponse->assertOk()->assertJsonPath('ok', true);
        $reponse->assertJsonPath('suivi.reference', $this->etudiantA->num_inscription);
        $reponse->assertJsonPath('suivi.etudiant.nom_complet', $this->etudiantA->nom_complet);

        // Aucune donnée brute sensible dans l'enveloppe JSON.
        $contenu = $reponse->getContent();
        $this->assertStringNotContainsString('karim.benali@test.ma', $contenu);
        $this->assertStringNotContainsString('KA100001', $contenu);

        // Échec neutre : même enveloppe, aucun oracle d'existence.
        $reponseInconnue = $this->postJson(route('inscription.suivi.rechercher'), [
            'reference' => 'INS-2025-99999',
            'secondaire' => 'KA100001',
        ]);

        $reponseInconnue->assertOk()->assertJsonPath('ok', false);
        $reponseInconnue->assertJsonStructure(['ok', 'message']);
    }
}
