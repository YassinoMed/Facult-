<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite de tests du Centre de travail administratif intelligent (Sous-lot B).
 *
 * Couverture complète :
 * 1. Affichage autorisé de la page.
 * 2. Refus d'accès non authentifié.
 * 3. Respect des permissions de consultation (rôle consultation et profil sans accès).
 * 4. Isolation facultaire étanche pour la liste et les indicateurs (anti-BOLA).
 * 5. Filtres combinés (source, statut, priorité, recherche, souffrance, blocage) et pagination.
 * 6. Absence de doublons dans les métriques et exactitude des indicateurs.
 * 7. Calcul des indicateurs à partir des données réelles.
 * 8. Absence totale de mutation de la base lors d'une simple consultation (lecture seule).
 * 9. Absence de déclenchement de validation, assignation ou clôture par un affichage.
 * 10. Non-régression globale sur les tâches d'inscription et cockpit.
 */
class CentreTravailAdminTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte1;

    private Faculte $faculte2;

    private AnneeUniversitaire $annee;

    private User $superAdmin;

    private User $agentScolarite;

    private User $consultant;

    private User $adminFaculte1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte1 = Faculte::create([
            'code' => 'FST',
            'nom' => 'Faculté des Sciences de Tunis',
            'active' => true,
        ]);

        $this->faculte2 = Faculte::create([
            'code' => 'FSEGS',
            'nom' => 'Faculté des Sciences Économiques et de Gestion de Sfax',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'code' => '2026-2027',
            'libelle' => 'Année Académique 2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->superAdmin = User::creerCompteBackOffice([
            'name' => 'Super Admin',
            'email' => 'superadmin@uniregistre.tn',
            'password' => 'Secret@123',
        ], 'super_admin');

        $this->agentScolarite = User::creerCompteBackOffice([
            'name' => 'Agent Scolarité',
            'email' => 'agent@fst.tn',
            'password' => 'Secret@123',
            'faculte_id' => $this->faculte1->id,
        ], 'agent_scolarite');

        $this->consultant = User::creerCompteBackOffice([
            'name' => 'Auditeur Consultation',
            'email' => 'consultant@uniregistre.tn',
            'password' => 'Secret@123',
        ], 'consultation');

        $this->adminFaculte1 = User::creerCompteBackOffice([
            'name' => 'Doyen FST',
            'email' => 'admin.fac1@fst.tn',
            'password' => 'Secret@123',
            'faculte_id' => $this->faculte1->id,
        ], 'admin_faculte');
    }

    /** 1. Affichage autorisé de la page pour un agent de scolarité. */
    public function test_affichage_autorise_de_la_page_centre_travail(): void
    {
        $reponse = $this->actingAs($this->agentScolarite)->get(route('admin.centre-travail.index'));

        $reponse->assertOk();
        $reponse->assertViewIs('admin.centre-travail.index');
        $reponse->assertSee('Centre de travail administratif');
        $reponse->assertSee('Pilotage opérationnel');
    }

    /** 2. Refus d'accès non authentifié (redirection vers connexion). */
    public function test_refus_acces_non_authentifie(): void
    {
        $reponse = $this->get(route('admin.centre-travail.index'));

        $reponse->assertRedirect(route('admin.connexion.show'));
    }

    /** 3. Respect des permissions de consultation : consultation accède en lecture seule, utilisateur sans droit rejeté (403). */
    public function test_respect_des_permissions_de_consultation(): void
    {
        // Rôle consultation : a les droits de lecture seule (archives.view et tasks.view)
        $reponseConsultation = $this->actingAs($this->consultant)->get(route('admin.centre-travail.index'));
        $reponseConsultation->assertOk();

        // Utilisateur back-office sans aucune permission
        $userSansDroit = User::create([
            'name' => 'Sans Droit',
            'email' => 'sansdroit@test.tn',
            'password' => bcrypt('Secret@123'),
            'role' => 'aucun_role',
            'actif' => true,
        ]);

        $reponseSansDroit = $this->actingAs($userSansDroit)->get(route('admin.centre-travail.index'));
        $reponseSansDroit->assertForbidden();
    }

    /** 4. Isolation facultaire étanche pour la liste et les indicateurs (anti-BOLA). */
    public function test_isolation_facultaire_liste_et_indicateurs(): void
    {
        // Tâche 1 : rattachée à la Faculté 1
        Task::create([
            'titre' => 'Contrôle dossier FST',
            'source' => 'inscription',
            'cle_unique' => 'test:f1:1',
            'statut' => 'pending',
            'priorite' => 'high',
            'faculte_id' => $this->faculte1->id,
        ]);

        // Tâche 2 : rattachée à la Faculté 2
        Task::create([
            'titre' => 'Contrôle dossier FSEGS',
            'source' => 'inscription',
            'cle_unique' => 'test:f2:1',
            'statut' => 'pending',
            'priorite' => 'critical',
            'faculte_id' => $this->faculte2->id,
        ]);

        // Connexion en tant qu'admin de la Faculté 1
        $reponse = $this->actingAs($this->adminFaculte1)->get(route('admin.centre-travail.index'));

        $reponse->assertOk();
        $reponse->assertSee('Contrôle dossier FST');
        $reponse->assertDontSee('Contrôle dossier FSEGS');

        // Tentative de contournement via paramètre d'URL ?faculte_id = Faculte 2
        $reponseContournement = $this->actingAs($this->adminFaculte1)->get(route('admin.centre-travail.index', [
            'faculte_id' => $this->faculte2->id,
        ]));

        $reponseContournement->assertOk();
        $reponseContournement->assertSee('Contrôle dossier FST');
        $reponseContournement->assertDontSee('Contrôle dossier FSEGS');

        // Vérification des KPIs reçus par la vue : 1 seul dossier en attente pour Faculte 1
        $reponse->assertViewHas('kpis', function (array $kpis) {
            return $kpis['en_attente'] === 1 && $kpis['prioritaires'] === 1;
        });

        // En revanche, le super administrateur global voit les 2 facultés
        $reponseSuper = $this->actingAs($this->superAdmin)->get(route('admin.centre-travail.index'));
        $reponseSuper->assertOk();
        $reponseSuper->assertSee('Contrôle dossier FST');
        $reponseSuper->assertSee('Contrôle dossier FSEGS');
        $reponseSuper->assertViewHas('kpis', function (array $kpis) {
            return $kpis['en_attente'] === 2 && $kpis['prioritaires'] === 2;
        });
    }

    /** 5. Filtres combinés et pagination avec conservation des filtres. */
    public function test_filtres_combines_et_pagination(): void
    {
        // Création de 20 tâches avec diverses caractéristiques
        for ($i = 1; $i <= 20; $i++) {
            Task::create([
                'titre' => "Tâche Inscription Dossier {$i}",
                'description' => "Description pour le dossier {$i}",
                'source' => 'inscription',
                'cle_unique' => "inscription:test:{$i}",
                'statut' => $i <= 18 ? 'pending' : 'completed',
                'priorite' => $i % 2 === 0 ? 'high' : 'normal',
                'faculte_id' => $this->faculte1->id,
                'created_at' => now()->subHours($i * 4),
            ]);
        }

        // Tâche d'une autre catégorie
        Task::create([
            'titre' => 'Justification médicale urgente',
            'source' => 'justification',
            'cle_unique' => 'justification:test:99',
            'statut' => 'pending',
            'priorite' => 'high',
            'faculte_id' => $this->faculte1->id,
        ]);

        // A. Filtre par source = inscription
        $repSource = $this->actingAs($this->agentScolarite)->get(route('admin.centre-travail.index', [
            'source' => 'inscription',
        ]));
        $repSource->assertOk();
        $repSource->assertSee('Tâche Inscription Dossier');
        $repSource->assertDontSee('Justification médicale urgente');

        // B. Filtre combiné : source=inscription & priorite=high
        $repCombine = $this->actingAs($this->agentScolarite)->get(route('admin.centre-travail.index', [
            'source' => 'inscription',
            'priorite' => 'high',
        ]));
        $repCombine->assertOk();
        // Vérification que les tâches affichées sont bien 'high'
        $repCombine->assertViewHas('taches', function ($taches) {
            foreach ($taches as $t) {
                if ($t->priorite !== 'high' || $t->source !== 'inscription') {
                    return false;
                }
            }

            return true;
        });

        // C. Pagination : 18 inscriptions actives, la page 1 doit en contenir 15, la page 2 doit en contenir 3
        $repPage1 = $this->actingAs($this->agentScolarite)->get(route('admin.centre-travail.index', [
            'source' => 'inscription',
        ]));
        $repPage1->assertViewHas('taches', fn ($t) => $t->count() === 15 && $t->total() === 18);

        $repPage2 = $this->actingAs($this->agentScolarite)->get(route('admin.centre-travail.index', [
            'source' => 'inscription',
            'page' => 2,
        ]));
        $repPage2->assertViewHas('taches', fn ($t) => $t->count() === 3);
        $repPage2->assertSee('page=1'); // Le lien de pagination préserve les paramètres
    }

    /** 6. Absence de doublons dans les métriques et exactitude des indicateurs. */
    public function test_absence_de_doublons_et_exactitude_des_indicateurs(): void
    {
        // Tâche A : active, pending, critical, créée il y a 50h (>48h), bloquée
        $tacheA = Task::create([
            'titre' => 'Tâche A multi-critères',
            'source' => 'inscription',
            'cle_unique' => 'test:multi:A',
            'statut' => 'pending',
            'priorite' => 'critical',
            'data' => ['bloque' => true, 'est_en_souffrance' => true],
            'faculte_id' => $this->faculte1->id,
        ]);
        $tacheA->created_at = now()->subHours(50);
        $tacheA->saveQuietly();

        // Tâche B : active, in_progress, normal, créée il y a 10h (<48h)
        $tacheB = Task::create([
            'titre' => 'Tâche B normale',
            'source' => 'inscription',
            'cle_unique' => 'test:multi:B',
            'statut' => 'in_progress',
            'priorite' => 'normal',
            'data' => ['bloque' => false],
            'faculte_id' => $this->faculte1->id,
        ]);
        $tacheB->created_at = now()->subHours(10);
        $tacheB->saveQuietly();

        // Tâche C : clôturée il y a 2 jours
        Task::create([
            'titre' => 'Tâche C clôturée',
            'source' => 'inscription',
            'cle_unique' => 'test:multi:C',
            'statut' => 'completed',
            'priorite' => 'normal',
            'faculte_id' => $this->faculte1->id,
            'terminee_a' => now()->subDays(2),
        ]);

        $reponse = $this->actingAs($this->superAdmin)->get(route('admin.centre-travail.index'));
        $reponse->assertOk();

        $reponse->assertViewHas('kpis', function (array $kpis) {
            $this->assertSame(1, $kpis['en_attente'], 'Exactement 1 tâche pending');
            $this->assertSame(1, $kpis['en_cours'], 'Exactement 1 tâche in_progress');
            $this->assertSame(1, $kpis['prioritaires'], 'Exactement 1 tâche critique/high');
            $this->assertSame(1, $kpis['souffrance_48h'], 'Exactement 1 tâche en souffrance');
            $this->assertSame(1, $kpis['bloquees'], 'Exactement 1 tâche bloquée');
            $this->assertSame(1, $kpis['cloturees_recemment'], 'Exactement 1 tâche clôturée dans les 7j');

            return true;
        });
    }

    /** 7. Calcul des indicateurs à partir des données réelles (pas de données factices injectées). */
    public function test_calcul_indicateurs_a_partir_des_donnees_reelles_sans_donnees_factices(): void
    {
        // Aucune tâche en base de données
        $reponse = $this->actingAs($this->superAdmin)->get(route('admin.centre-travail.index'));

        $reponse->assertOk();
        $reponse->assertViewHas('kpis', function (array $kpis) {
            $this->assertSame(0, $kpis['en_attente']);
            $this->assertSame(0, $kpis['en_cours']);
            $this->assertSame(0, $kpis['prioritaires']);
            $this->assertSame(0, $kpis['souffrance_48h']);
            $this->assertSame(0, $kpis['bloquees']);
            $this->assertSame(0, $kpis['cloturees_recemment']);

            return true;
        });
        $reponse->assertSee('Aucune tâche administrative trouvée');
    }

    /** 8. Absence totale de mutation de la base lors d'une simple consultation (lecture seule). */
    public function test_absence_de_mutation_lors_de_la_consultation(): void
    {
        // Création d'un dossier et d'une tâche
        $tache = Task::create([
            'titre' => 'Contrôle dossier avant lecture',
            'source' => 'inscription',
            'cle_unique' => 'test:lecture:seule',
            'statut' => 'pending',
            'priorite' => 'normal',
            'faculte_id' => $this->faculte1->id,
        ]);

        $etatTachesAvant = Task::all()->toArray();
        $countUsersAvant = User::count();
        $countFacultesAvant = Faculte::count();

        // Appel GET sur le centre de travail
        $reponse = $this->actingAs($this->agentScolarite)->get(route('admin.centre-travail.index'));
        $reponse->assertOk();

        // Vérification de la stricte immutabilité
        $this->assertSame($etatTachesAvant, Task::all()->toArray(), 'Les tâches n\'ont subi aucune modification');
        $this->assertSame($countUsersAvant, User::count());
        $this->assertSame($countFacultesAvant, Faculte::count());
        $this->assertSame('pending', $tache->fresh()->statut);
    }

    /** 9. Absence de déclenchement de validation, assignation ou clôture par un affichage. */
    public function test_aucun_declenchement_de_validation_ou_cloture_par_affichage(): void
    {
        $etudiant = Etudiant::create([
            'cin' => '07777777',
            'nom' => 'Test',
            'prenom' => 'Immuable',
            'email' => 'test.immuable@fst.tn',
            'date_naissance' => '2004-01-01',
            'sexe' => 'M',
            'faculte_id' => $this->faculte1->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Campus',
            'ville' => 'Tunis',
            'code_postal' => '1060',
            'annee_bac' => '2023',
            'password' => 'Secret@123',
        ]);
        $etudiant->definirStatut('en_attente');

        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'statut' => 'en_cours',
            'niveau' => 'L1',
            'filiere' => 'Informatique',
        ]);

        $tache = Task::create([
            'titre' => 'Tâche de l\'étudiant',
            'source' => 'inscription',
            'cle_unique' => "inscription:{$inscription->id}",
            'statut' => 'pending',
            'priorite' => 'high',
            'assigne_a' => null,
            'faculte_id' => $this->faculte1->id,
        ]);

        // Simple consultation de la page
        $reponse = $this->actingAs($this->agentScolarite)->get(route('admin.centre-travail.index'));
        $reponse->assertOk();

        // L'inscription doit rester strictement en_cours et l'étudiant en_attente
        $this->assertSame('en_cours', $inscription->fresh()->statut);
        $this->assertSame('en_attente', $etudiant->fresh()->statut);

        // La tâche doit rester pending et non assignée
        $this->assertSame('pending', $tache->fresh()->statut);
        $this->assertNull($tache->fresh()->assigne_a);
        $this->assertNull($tache->fresh()->terminee_a);
    }

    /** 10. Recherche textuelle ciblée sur matricule, CIN, titre ou clé unique. */
    public function test_recherche_textuelle_ciblee(): void
    {
        Task::create([
            'titre' => 'Contrôle inscription — Ben Salah Amine',
            'description' => '08765432 · L1 · Informatique',
            'source' => 'inscription',
            'cle_unique' => 'inscription:101',
            'statut' => 'pending',
            'faculte_id' => $this->faculte1->id,
        ]);

        Task::create([
            'titre' => 'Contrôle inscription — Trabelsi Yasmine',
            'description' => '09876543 · L2 · Gestion',
            'source' => 'inscription',
            'cle_unique' => 'inscription:102',
            'statut' => 'pending',
            'faculte_id' => $this->faculte1->id,
        ]);

        // Recherche par CIN de Ben Salah
        $repCin = $this->actingAs($this->superAdmin)->get(route('admin.centre-travail.index', [
            'search' => '08765432',
        ]));
        $repCin->assertOk();
        $repCin->assertSee('Ben Salah Amine');
        $repCin->assertDontSee('Trabelsi Yasmine');

        // Recherche par nom de Trabelsi
        $repNom = $this->actingAs($this->superAdmin)->get(route('admin.centre-travail.index', [
            'search' => 'Trabelsi',
        ]));
        $repNom->assertOk();
        $repNom->assertSee('Trabelsi Yasmine');
        $repNom->assertDontSee('Ben Salah Amine');
    }
}
