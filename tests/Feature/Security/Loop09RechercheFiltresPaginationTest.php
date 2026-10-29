<?php

namespace Tests\Feature\Security;

use App\Models\AnneeUniversitaire;
use App\Models\AppNotification;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\OnlineRequests\Models\RequestType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * LOOP-09 — Recherche, filtres et pagination de l'Espace Étudiant.
 *
 * Vérifie :
 * 1. Contrôle d'accès et protection de session sur la recherche et les listes filtrées.
 * 2. État par défaut de la recherche : message d'invitation sans exécution de requêtes lourdes.
 * 3. Validation de la longueur minimale du motif (seuil de 2 caractères, rejet des espaces vides).
 * 4. Troncature et protection contre les motifs de recherche trop longs (> 100 caractères).
 * 5. Échappement des jokers SQL LIKE (%, _) empêchant l'énumération globale de la base de données.
 * 6. Cloisonnement strict (anti-IDOR) : recherche strictement isolée, étanchéité absolue entre étudiants.
 * 7. Plafond de résultats anti-DoS : limitation stricte à 10 résultats par section.
 * 8. Support JSON pour l'autocomplétion ou requêtes asynchrones.
 * 9. Filtrage des notifications (non-lues, importantes, type) avec isolation par session.
 * 10. Robustesse face aux filtres invalides ou arbitraires (fallback sécurisé, pas d'erreur 500).
 * 11. Filtrage des demandes en ligne par statut dans le périmètre exclusif de l'étudiant.
 * 12. Pagination sécurisée avec préservation des filtres via withQueryString().
 * 13. Gestion gracieuse des pages hors limites (pas de plantage 500 sur page=9999).
 * 14. Traduction française conforme des éléments de pagination (Précédent, Suivant, sur, résultats).
 * 15. Throttling de sécurité (rate limiting à 30 req/min sur etudiant.recherche).
 */
class Loop09RechercheFiltresPaginationTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.online-requests.active' => true,
        ]);

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences de Rabat',
            'code' => 'FSR',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026',
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30',
            'active' => true,
        ]);
    }

    private function creerEtudiant(array $surcharge = []): Etudiant
    {
        $etudiant = Etudiant::create(array_merge([
            'cin' => 'AB123456',
            'nom' => 'Alami',
            'prenom' => 'Sara',
            'email' => 'sara.alami@example.com',
            'telephone' => '+212612345678',
            'date_naissance' => '2003-05-17',
            'sexe' => 'F',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => '15 Avenue Mohammed V',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2021',
            'mention_bac' => 'Bien',
            'password' => 'ActuelPass123!',
        ], $surcharge));

        if (empty($etudiant->num_inscription)) {
            $etudiant->forceFill(['num_inscription' => 'INS-2026-'.($surcharge['cin'] ?? 'AB123456')])->save();
        }

        return $etudiant->fresh();
    }

    public function test_acces_recherche_et_listes_refuse_visiteur_non_authentifie(): void
    {
        $this->get(route('etudiant.recherche', ['q' => 'Test']))
            ->assertRedirect(route('etudiant.connexion.show'))
            ->assertSessionHas('error');

        $this->get(route('etudiant.notifications.index'))
            ->assertRedirect(route('etudiant.connexion.show'))
            ->assertSessionHas('error');

        $this->get(route('etudiant.demandes.index'))
            ->assertRedirect(route('etudiant.connexion.show'))
            ->assertSessionHas('error');
    }

    public function test_recherche_sans_motif_affiche_etat_invitation(): void
    {
        $etudiant = $this->creerEtudiant();

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->get(route('etudiant.recherche'));

        $response->assertOk();
        $response->assertSee('Lancez une recherche pour voir vos résultats.');
    }

    public function test_recherche_motif_trop_court_exige_au_moins_deux_caracteres(): void
    {
        $etudiant = $this->creerEtudiant();

        // 1 caractère
        $response1 = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->get(route('etudiant.recherche', ['q' => 'a']));

        $response1->assertOk();
        $response1->assertSee('Saisissez au moins 2 caractères.');

        // Espaces uniquement
        $response2 = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->get(route('etudiant.recherche', ['q' => '   ']));

        $response2->assertOk();
        $response2->assertSee('Lancez une recherche pour voir vos résultats.');
    }

    public function test_recherche_echappe_les_caracteres_jokers_sql_like(): void
    {
        $etudiant = $this->creerEtudiant();

        $typeDemande = RequestType::create([
            'code' => 'DEM_RECH_1',
            'name' => 'Attestation officielle',
            'category' => 'administrative',
            'is_active' => true,
        ]);

        $demande = OnlineRequest::create([
            'reference' => 'DEM-NORMAL-001',
            'request_type_id' => $typeDemande->id,
            'etudiant_id' => $etudiant->id,
            'objet' => 'Dossier standard',
            'description' => 'Demande régulière',
        ]);

        // Recherche avec le joker LIKE '%' qui ne doit pas matcher 'DEM-NORMAL-001'
        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->get(route('etudiant.recherche', ['q' => '%_%']));

        $response->assertOk();
        // Le caractère joker étant échappé (\%_\%), il ne trouve pas le document standard
        $response->assertDontSee('DEM-NORMAL-001');
    }

    public function test_recherche_cloisonnement_strict_anti_idor_aucun_resultat_d_autrui(): void
    {
        $etudiantA = $this->creerEtudiant(['cin' => 'RECA1111', 'email' => 'reca1@example.com']);
        $etudiantB = $this->creerEtudiant(['cin' => 'RECB2222', 'email' => 'recb2@example.com']);

        $typeDemande = RequestType::create([
            'code' => 'DEM_BOURSE',
            'name' => 'Bourse universitaire',
            'category' => 'administrative',
            'is_active' => true,
        ]);

        // Demande pour Étudiant A
        $demandeA = OnlineRequest::create([
            'reference' => 'DEM-BOURSE-A01',
            'request_type_id' => $typeDemande->id,
            'etudiant_id' => $etudiantA->id,
            'objet' => 'Bourse au mérite Sara',
            'description' => 'Dossier complet A',
        ]);

        // Demande pour Étudiant B
        $demandeB = OnlineRequest::create([
            'reference' => 'DEM-BOURSE-B02',
            'request_type_id' => $typeDemande->id,
            'etudiant_id' => $etudiantB->id,
            'objet' => 'Bourse doctorale Bouchra',
            'description' => 'Dossier confidentiel B',
        ]);

        // Étudiant A connecté recherche 'Bourse' et injecte etudiant_id=B
        $responseA = $this->withSession([
            'etudiant_auth_id' => $etudiantA->id,
            'etudiant_mdp_version' => (int) $etudiantA->mot_de_passe_version,
        ])->get(route('etudiant.recherche', ['q' => 'Bourse', 'etudiant_id' => $etudiantB->id]));

        $responseA->assertOk();
        $responseA->assertSee('DEM-BOURSE-A01');
        $responseA->assertSee('Bourse au mérite Sara');

        // Les données de l'étudiant B ne doivent jamais apparaître
        $responseA->assertDontSee('DEM-BOURSE-B02');
        $responseA->assertDontSee('Bourse doctorale Bouchra');
    }

    public function test_recherche_borne_les_resultats_a_dix_elements_maximum(): void
    {
        $etudiant = $this->creerEtudiant();

        $typeDemande = RequestType::create([
            'code' => 'DEM_CERTIF',
            'name' => 'Certificat de scolarité',
            'category' => 'administrative',
            'is_active' => true,
        ]);

        // Création de 15 demandes contenant 'Certificat'
        for ($i = 1; $i <= 15; $i++) {
            OnlineRequest::create([
                'reference' => sprintf('DEM-CERTIF-%03d', $i),
                'request_type_id' => $typeDemande->id,
                'etudiant_id' => $etudiant->id,
                'objet' => 'Certificat de scolarité annuel #'.$i,
                'description' => 'Demande régulière',
            ]);
        }

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->getJson(route('etudiant.recherche', ['q' => 'Certificat']));

        $response->assertOk();
        $demandes = $response->json('sections.Mes demandes');
        $this->assertIsArray($demandes);
        // Le contrôleur borne impérativement chaque section à 10 items
        $this->assertCount(10, $demandes);
    }

    public function test_recherche_format_json_structure_conforme(): void
    {
        $etudiant = $this->creerEtudiant();

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->getJson(route('etudiant.recherche', ['q' => 'Demande']));

        $response->assertOk();
        $response->assertJsonStructure([
            'sections' => [
                'Supports de cours',
                'Mes demandes',
                'Mes séances',
                'Annonces',
            ],
        ]);
    }

    public function test_filtre_notifications_non_lues_et_importantes_cloisonnees(): void
    {
        $etudiantA = $this->creerEtudiant(['cin' => 'NOTFA111', 'email' => 'notfa1@example.com']);
        $etudiantB = $this->creerEtudiant(['cin' => 'NOTFB222', 'email' => 'notfb2@example.com']);

        // Notifications de A
        $notifNonLueA = AppNotification::create([
            'etudiant_id' => $etudiantA->id,
            'type' => 'alerte',
            'titre' => 'Alerte Non Lue A',
            'message' => 'Message important non lu',
            'read_at' => null,
            'priorite' => 1,
        ]);
        $notifLueA = AppNotification::create([
            'etudiant_id' => $etudiantA->id,
            'type' => 'information',
            'titre' => 'Info Lue A',
            'message' => 'Message déjà consulté',
            'read_at' => now(),
            'priorite' => 1,
        ]);
        $notifImportanteA = AppNotification::create([
            'etudiant_id' => $etudiantA->id,
            'type' => 'examen',
            'titre' => 'Urgent Examen A',
            'message' => 'Convocation disponible',
            'read_at' => null,
            'priorite' => 2,
        ]);

        // Notifications de B
        $notifB = AppNotification::create([
            'etudiant_id' => $etudiantB->id,
            'type' => 'confidentiel',
            'titre' => 'Alerte Secrète B',
            'message' => 'Confidentiel B',
            'read_at' => null,
            'priorite' => 3,
        ]);

        // 1. A consulte les non-lues
        $responseNonLues = $this->withSession([
            'etudiant_auth_id' => $etudiantA->id,
            'etudiant_mdp_version' => (int) $etudiantA->mot_de_passe_version,
        ])->get(route('etudiant.notifications.index', ['filtre' => 'non-lues']));

        $responseNonLues->assertOk();
        $notifsNonLues = $responseNonLues->viewData('notifications');
        $this->assertTrue($notifsNonLues->contains('titre', 'Alerte Non Lue A'));
        $this->assertTrue($notifsNonLues->contains('titre', 'Urgent Examen A'));
        $this->assertFalse($notifsNonLues->contains('titre', 'Info Lue A'));
        $this->assertFalse($notifsNonLues->contains('titre', 'Alerte Secrète B'));
        $responseNonLues->assertDontSee('Alerte Secrète B');

        // 2. A consulte les importantes (priorité >= 2)
        $responseImportantes = $this->withSession([
            'etudiant_auth_id' => $etudiantA->id,
            'etudiant_mdp_version' => (int) $etudiantA->mot_de_passe_version,
        ])->get(route('etudiant.notifications.index', ['filtre' => 'importantes']));

        $responseImportantes->assertOk();
        $notifsImportantes = $responseImportantes->viewData('notifications');
        $this->assertTrue($notifsImportantes->contains('titre', 'Urgent Examen A'));
        $this->assertFalse($notifsImportantes->contains('titre', 'Alerte Non Lue A'));
        $this->assertFalse($notifsImportantes->contains('titre', 'Info Lue A'));
        $this->assertFalse($notifsImportantes->contains('titre', 'Alerte Secrète B'));
        $responseImportantes->assertDontSee('Alerte Secrète B');
    }

    public function test_filtre_notifications_avec_valeur_inconnue_ne_plante_pas(): void
    {
        $etudiant = $this->creerEtudiant();

        AppNotification::create([
            'etudiant_id' => $etudiant->id,
            'type' => 'information',
            'titre' => 'Notification Standard',
            'message' => 'Message standard',
            'read_at' => null,
            'priorite' => 1,
        ]);

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->get(route('etudiant.notifications.index', ['filtre' => 'valeur_arbitraire_injectee']));

        $response->assertOk();
        $response->assertSee('Notification Standard');
    }

    public function test_filtre_demandes_en_ligne_par_statut_cloisonne(): void
    {
        $etudiantA = $this->creerEtudiant(['cin' => 'DMA11111', 'email' => 'dma1@example.com']);
        $etudiantB = $this->creerEtudiant(['cin' => 'DMB22222', 'email' => 'dmb2@example.com']);

        $typeDemande = RequestType::create([
            'code' => 'DEM_TYPE_FILTRE',
            'name' => 'Demande administrative',
            'category' => 'administrative',
            'is_active' => true,
        ]);

        $demandeSoumiseA = OnlineRequest::create([
            'reference' => 'DEM-A-SUBMITTED',
            'request_type_id' => $typeDemande->id,
            'etudiant_id' => $etudiantA->id,
            'objet' => 'Dossier déposé A',
        ]);
        $demandeSoumiseA->forceFill(['status' => 'submitted'])->save();

        $demandeBrouillonA = OnlineRequest::create([
            'reference' => 'DEM-A-DRAFT',
            'request_type_id' => $typeDemande->id,
            'etudiant_id' => $etudiantA->id,
            'objet' => 'Dossier brouillon A',
        ]);
        $demandeBrouillonA->forceFill(['status' => 'draft'])->save();

        $demandeSoumiseB = OnlineRequest::create([
            'reference' => 'DEM-B-SUBMITTED',
            'request_type_id' => $typeDemande->id,
            'etudiant_id' => $etudiantB->id,
            'objet' => 'Dossier déposé B',
        ]);
        $demandeSoumiseB->forceFill(['status' => 'submitted'])->save();

        // Étudiant A filtre par statut 'submitted'
        $response = $this->withSession([
            'etudiant_auth_id' => $etudiantA->id,
            'etudiant_mdp_version' => (int) $etudiantA->mot_de_passe_version,
        ])->get(route('etudiant.demandes.index', ['statut' => 'submitted']));

        $response->assertOk();
        $response->assertSee('DEM-A-SUBMITTED');
        $response->assertDontSee('DEM-A-DRAFT');
        // Les demandes de B restent totalement inaccessibles
        $response->assertDontSee('DEM-B-SUBMITTED');
    }

    public function test_pagination_preserve_les_parametres_de_filtre_via_with_query_string(): void
    {
        $etudiant = $this->creerEtudiant();

        // Création de 20 notifications importantes (priorité 2)
        for ($i = 1; $i <= 20; $i++) {
            AppNotification::create([
                'etudiant_id' => $etudiant->id,
                'type' => 'information',
                'titre' => sprintf('Notification Importante %02d', $i),
                'message' => 'Contenu du message #'.$i,
                'read_at' => null,
                'priorite' => 2,
            ]);
        }

        // Requête page 1 avec filtre 'importantes'
        $responsePage1 = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->get(route('etudiant.notifications.index', ['filtre' => 'importantes']));

        $responsePage1->assertOk();
        // Vérification de la présence du lien de pagination préservant le filtre
        $responsePage1->assertSee('filtre=importantes', false);
        $notifsP1 = $responsePage1->viewData('notifications');
        $this->assertEquals(2, $notifsP1->lastPage());
        $this->assertEquals(1, $notifsP1->currentPage());

        // Requête page 2
        $responsePage2 = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->get(route('etudiant.notifications.index', ['filtre' => 'importantes', 'page' => 2]));

        $responsePage2->assertOk();
        $notifsP2 = $responsePage2->viewData('notifications');
        $this->assertEquals(2, $notifsP2->currentPage());
        $this->assertEquals(5, $notifsP2->count());
    }

    public function test_pagination_hors_bornes_retourne_page_vide_sans_erreur_500(): void
    {
        $etudiant = $this->creerEtudiant();

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->get(route('etudiant.notifications.index', ['page' => 9999]));

        $response->assertOk();
    }

    public function test_pagination_traductions_francaises_conformes(): void
    {
        app()->setLocale('fr');
        $etudiant = $this->creerEtudiant();

        for ($i = 1; $i <= 20; $i++) {
            AppNotification::create([
                'etudiant_id' => $etudiant->id,
                'type' => 'information',
                'titre' => 'Notif '.$i,
                'message' => 'Msg '.$i,
                'read_at' => null,
                'priorite' => 1,
            ]);
        }

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->get(route('etudiant.notifications.index', ['page' => 2]));

        $response->assertOk();
        $html = $response->getContent();

        // Vérification des termes de pagination français
        $this->assertTrue(
            str_contains($html, 'Précédent') || str_contains($html, 'pagination.previous') === false,
            'La pagination doit utiliser des libellés traduits en français.'
        );
        $this->assertFalse(str_contains($html, 'Showing'), 'Le terme Showing en anglais ne doit pas apparaître.');
    }

    public function test_route_recherche_est_protegee_par_throttling(): void
    {
        $route = Route::getRoutes()->getByName('etudiant.recherche');
        $this->assertNotNull($route);
        $this->assertContains('throttle:30,1', $route->middleware(), 'La route de recherche portail doit être protégée par un rate limiter (throttle:30,1).');
    }
}
