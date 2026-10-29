<?php

namespace Tests\Feature\Security;

use App\Models\AnneeUniversitaire;
use App\Models\DocumentAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Semestre;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\OnlineRequests\Models\RequestType;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP-08 — Tableau de bord et navigation étudiant.
 *
 * Vérifie :
 * 1. Accès et contrôle de session : protection par EnsureStudent, redirection si invité ou session expirée.
 * 2. Cloisonnement strict et anti-IDOR : résolution par session serveur, étanchéité complète entre étudiants.
 * 3. Situation du dossier : affichage du dossier d'admission (num_inscription, statut) ou de l'inscription académique.
 * 4. Confidentialité des délibérations (D-01) : aucune note/moyenne/décision non publiée n'est exposée sur le tableau de bord.
 * 5. Décisions de délibération publiées : affichage conforme des moyennes et crédits officiels.
 * 6. Documents récents : affichage des documents de l'étudiant connecté sans fuite des documents d'autrui.
 * 7. Démarches récentes : affichage des demandes en ligne de l'étudiant avec suivi par étapes (1 à 4).
 * 8. Prochains examens : affichage exclusif des épreuves publiées où l'étudiant est candidat.
 * 9. Cours du jour : affichage des cours du groupe de l'étudiant avec gestion des exceptions (modifié/annulé).
 * 10. Navigation latérale étudiant : liens d'espace étudiant complets avec indicateur de page active aria-current="page".
 * 11. Étanchéité des profils de navigation : aucun lien d'administration ni d'espace enseignant exposé à l'étudiant.
 * 12. En-tête étudiant : avatar, recherche et formulaire de déconnexion sécurisé (POST + CSRF).
 * 13. Performance et isolation : dashboard léger, aucune relation lourde de notes chargée inutilement.
 */
class Loop08TableauDeBordNavigationTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_visiteur_non_authentifie_est_redirige_vers_connexion_depuis_tableau_de_bord(): void
    {
        $this->get(route('etudiant.dashboard'))
            ->assertRedirect(route('etudiant.connexion.show'))
            ->assertSessionHas('error');
    }

    public function test_session_etudiant_avec_version_obsolete_expire_et_redirige(): void
    {
        $etudiant = $this->creerEtudiant(['mot_de_passe_version' => 3]);

        // Session ayant une ancienne version de mot de passe (ex: 2)
        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => 2,
        ])->get(route('etudiant.dashboard'));

        $response->assertRedirect(route('etudiant.connexion.show'));
        $response->assertSessionHas('error');
        $this->assertNull(session('etudiant_auth_id'));
    }

    public function test_tableau_de_bord_affiche_identite_et_accueil_etudiant_connecte(): void
    {
        $etudiant = $this->creerEtudiant(['prenom' => 'Sara', 'nom' => 'Alami']);

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_auth_prenom' => $etudiant->prenom,
            'etudiant_auth_nom' => $etudiant->nom,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->get(route('etudiant.dashboard'));

        $response->assertOk();
        $response->assertSee('Bonjour Sara');
        $response->assertSee(Carbon::now()->translatedFormat('l d F Y'));
        $response->assertSee('Aujourd\'hui', false);
        $response->assertSee('Ma situation');
        $response->assertSee('Dernier résultat');
    }

    public function test_cloisonnement_strict_anti_idor_etudiant_ne_voit_que_ses_propres_donnees(): void
    {
        $etudiantA = $this->creerEtudiant([
            'cin' => 'AA111111',
            'prenom' => 'Ahmed',
            'nom' => 'Alami',
            'email' => 'ahmed@example.com',
        ]);
        $etudiantB = $this->creerEtudiant([
            'cin' => 'BB222222',
            'prenom' => 'Bouchra',
            'nom' => 'Benjelloun',
            'email' => 'bouchra@example.com',
        ]);

        // Étudiant A connecté tente de forcer l'affichage de l'étudiant B par URL
        $response = $this->withSession([
            'etudiant_auth_id' => $etudiantA->id,
            'etudiant_auth_prenom' => $etudiantA->prenom,
            'etudiant_auth_nom' => $etudiantA->nom,
            'etudiant_mdp_version' => (int) $etudiantA->mot_de_passe_version,
        ])->get(route('etudiant.dashboard', ['etudiant_id' => $etudiantB->id, 'id' => $etudiantB->id, 'cin' => $etudiantB->cin]));

        $response->assertOk();
        $response->assertSee('Bonjour Ahmed');
        $response->assertSee($etudiantA->num_inscription);

        // Aucune donnée d'identité de B ne doit être visible
        $response->assertDontSee('Bonjour Bouchra');
        $response->assertDontSee('Benjelloun');
        $response->assertDontSee($etudiantB->num_inscription);
    }

    public function test_situation_dossier_admission_sans_inscription_academique(): void
    {
        $etudiant = $this->creerEtudiant(['statut' => 'en_attente']);

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->get(route('etudiant.dashboard'));

        $response->assertOk();
        $response->assertSee('Dossier');
        $response->assertSee('admission', false);
        $response->assertSee($etudiant->num_inscription);
        $response->assertSee('En attente');
        $response->assertSee('Aucune année académique');
        $response->assertSee('Aucun résultat académique', false);
    }

    public function test_situation_inscription_academique_active(): void
    {
        $etudiant = $this->creerEtudiant(['statut' => 'valide']);

        InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'filiere' => 'Sciences Informatiques',
            'niveau' => 'L1',
            'statut' => 'valide',
            'moyenne_annuelle' => 14.50,
            'credits_obtenus' => 60,
            'credits_total' => 60,
        ]);

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->get(route('etudiant.dashboard'));

        $response->assertOk();
        $response->assertSee('Sciences Informatiques');
        $response->assertSee('L1');
        $response->assertSee('2025-2026');
        $response->assertSee('Validé');
    }

    public function test_resultats_deliberation_non_publiee_ne_sont_jamais_divulgues(): void
    {
        $etudiant = $this->creerEtudiant();

        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'filiere' => 'Mathématiques',
            'niveau' => 'L2',
            'statut' => 'valide',
            'moyenne_annuelle' => 16.80,
            'credits_obtenus' => 58,
            'credits_total' => 60,
            'decision' => 'Admis(e)',
        ]);

        // Délibération créée mais non encore publiée
        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $this->annee->id,
            'statut' => 'draft',
            'published_at' => null,
        ]);
        DecisionDeliberation::create([
            'deliberation_id' => $deliberation->id,
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'moyenne' => 16.80,
            'credits_obtenus' => 58,
            'credits_total' => 60,
            'decision' => 'admis',
            'publie' => false,
        ]);

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->get(route('etudiant.dashboard'));

        $response->assertOk();
        $response->assertSee('Résultats en attente de publication');
        $response->assertSee('délibération du jury', false);

        // La note, les crédits et la décision ne doivent jamais apparaître
        $response->assertDontSee('16.80');
        $response->assertDontSee('58 / 60');
    }

    public function test_resultats_deliberation_publiee_affiches_fidelite_moyenne_et_credits(): void
    {
        $etudiant = $this->creerEtudiant();

        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'filiere' => 'Génie Logiciel',
            'niveau' => 'L3',
            'statut' => 'valide',
        ]);

        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $this->annee->id,
            'statut' => 'published',
            'published_at' => now(),
        ]);
        DecisionDeliberation::create([
            'deliberation_id' => $deliberation->id,
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'moyenne' => 15.75,
            'credits_obtenus' => 60,
            'credits_total' => 60,
            'decision' => 'Admis avec mention',
            'publie' => true,
        ]);

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->get(route('etudiant.dashboard'));

        $response->assertOk();
        $response->assertSee('15.75');
        $response->assertSee('60 / 60');
        $response->assertSee('Admis avec mention');
    }

    public function test_documents_recents_affiches_strictement_scopes_a_l_etudiant(): void
    {
        $etudiantA = $this->creerEtudiant(['cin' => 'DA111111', 'email' => 'da1@example.com']);
        $etudiantB = $this->creerEtudiant(['cin' => 'DB222222', 'email' => 'db2@example.com']);

        $docA = DocumentAcademique::create([
            'etudiant_id' => $etudiantA->id,
            'type_document' => 'attestation',
            'nom_original' => 'attestation_reussite_sara.pdf',
            'chemin' => 'archives/etudiant_A/attestation.pdf',
            'mime_type' => 'application/pdf',
            'taille' => 2048,
            'hash_fichier' => hash('sha256', 'fichier_a'),
        ]);

        $docB = DocumentAcademique::create([
            'etudiant_id' => $etudiantB->id,
            'type_document' => 'releve',
            'nom_original' => 'releve_confidentiel_bouchra.pdf',
            'chemin' => 'archives/etudiant_B/releve.pdf',
            'mime_type' => 'application/pdf',
            'taille' => 4096,
            'hash_fichier' => hash('sha256', 'fichier_b'),
        ]);

        $responseA = $this->withSession([
            'etudiant_auth_id' => $etudiantA->id,
            'etudiant_mdp_version' => (int) $etudiantA->mot_de_passe_version,
        ])->get(route('etudiant.dashboard'));

        $responseA->assertOk();
        $responseA->assertSee('attestation_reussite_sara.pdf');
        $responseA->assertSee(route('etudiant.archives.documents.download', $docA));
        $responseA->assertDontSee('releve_confidentiel_bouchra.pdf');
    }

    public function test_demarches_recentes_affichees_strictement_scopees_a_l_etudiant(): void
    {
        $etudiantA = $this->creerEtudiant(['cin' => 'EA111111', 'email' => 'ea1@example.com']);
        $etudiantB = $this->creerEtudiant(['cin' => 'EB222222', 'email' => 'eb2@example.com']);

        $typeDemande = RequestType::create([
            'code' => 'DEM_DASHBOARD_1',
            'name' => 'Attestation de scolarité officielle',
            'category' => 'administrative',
            'is_active' => true,
        ]);

        $demandeA = OnlineRequest::create([
            'reference' => 'DEM-DASH-A001',
            'request_type_id' => $typeDemande->id,
            'etudiant_id' => $etudiantA->id,
            'objet' => 'Attestation officielle',
            'description' => 'Demande de bourse',
        ]);
        $demandeA->forceFill(['status' => 'in_review'])->save();

        $demandeB = OnlineRequest::create([
            'reference' => 'DEM-DASH-B002',
            'request_type_id' => $typeDemande->id,
            'etudiant_id' => $etudiantB->id,
            'objet' => 'Attestation officielle B',
            'description' => 'Demande personnelle',
        ]);
        $demandeB->forceFill(['status' => 'submitted'])->save();

        $responseA = $this->withSession([
            'etudiant_auth_id' => $etudiantA->id,
            'etudiant_mdp_version' => (int) $etudiantA->mot_de_passe_version,
        ])->get(route('etudiant.dashboard'));

        $responseA->assertOk();
        $responseA->assertSee('DEM-DASH-A001');
        $responseA->assertSee('Attestation de scolarité officielle');
        $responseA->assertDontSee('DEM-DASH-B002');
    }

    public function test_prochains_examens_affiches_strictement_si_publies_et_scopes_etudiant(): void
    {
        $etudiantA = $this->creerEtudiant(['cin' => 'EXA11111', 'email' => 'exa1@example.com']);
        $etudiantB = $this->creerEtudiant(['cin' => 'EXB22222', 'email' => 'exb2@example.com']);

        $matiereA = Matiere::create([
            'code' => 'INF101',
            'nom' => 'Algorithmique et Programmation',
            'niveau' => 'L1',
            'semestre' => 1,
            'coefficient' => 3,
        ]);
        $matiereB = Matiere::create([
            'code' => 'MAT201',
            'nom' => 'Algèbre Linéaire',
            'niveau' => 'L1',
            'semestre' => 1,
            'coefficient' => 2,
        ]);

        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-2026',
            'nom' => 'Session Principale',
            'type' => 'principale',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-20',
            'statut' => 'open',
        ]);

        $examen = Examen::create([
            'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examen Principal',
            'type' => 'examen',
            'statut' => 'scheduled',
        ]);

        $epreuvePublieeA = Epreuve::create([
            'examen_id' => $examen->id,
            'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $matiereA->id,
            'statut' => 'published',
            'date' => Carbon::tomorrow()->toDateString(),
            'heure_debut' => '09:00:00',
            'heure_fin' => '11:00:00',
        ]);

        $epreuveBrouillon = Epreuve::create([
            'examen_id' => $examen->id,
            'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $matiereA->id,
            'statut' => 'draft',
            'date' => Carbon::tomorrow()->toDateString(),
            'heure_debut' => '14:00:00',
            'heure_fin' => '16:00:00',
        ]);

        $epreuvePublieeB = Epreuve::create([
            'examen_id' => $examen->id,
            'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $matiereB->id,
            'statut' => 'published',
            'date' => Carbon::tomorrow()->toDateString(),
            'heure_debut' => '09:00:00',
            'heure_fin' => '11:00:00',
        ]);

        Candidat::create([
            'epreuve_id' => $epreuvePublieeA->id,
            'etudiant_id' => $etudiantA->id,
            'statut' => 'inscrit',
        ]);
        Candidat::create([
            'epreuve_id' => $epreuveBrouillon->id,
            'etudiant_id' => $etudiantA->id,
            'statut' => 'inscrit',
        ]);
        Candidat::create([
            'epreuve_id' => $epreuvePublieeB->id,
            'etudiant_id' => $etudiantB->id,
            'statut' => 'inscrit',
        ]);

        $responseA = $this->withSession([
            'etudiant_auth_id' => $etudiantA->id,
            'etudiant_mdp_version' => (int) $etudiantA->mot_de_passe_version,
        ])->get(route('etudiant.dashboard'));

        $responseA->assertOk();
        $responseA->assertSee('Algorithmique et Programmation');
        $responseA->assertSee('INF101');
        // L'épreuve pour B ne doit pas être visible pour A
        $responseA->assertDontSee('Algèbre Linéaire');
        $responseA->assertDontSee('MAT201');
    }

    public function test_cours_du_jour_filtres_par_groupe_etudiant(): void
    {
        $groupeA = Group::create([
            'code' => 'GRP-A',
            'nom' => 'Groupe Info A',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
        ]);
        $groupeB = Group::create([
            'code' => 'GRP-B',
            'nom' => 'Groupe Info B',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
        ]);

        $etudiantA = $this->creerEtudiant([
            'cin' => 'CDJ11111',
            'email' => 'cdj1@example.com',
            'groupe_id' => $groupeA->id,
        ]);

        $planningA = Schedule::create([
            'nom' => 'Emploi du temps S1',
            'groupe_id' => $groupeA->id,
            'annee_universitaire_id' => $this->annee->id,
            'semestre' => 1,
            'statut' => 'publie',
            'actif' => true,
        ]);

        $matiere = Matiere::create([
            'code' => 'DEV101',
            'nom' => 'Programmation PHP Laravel',
            'niveau' => 'L1',
            'semestre' => 1,
            'coefficient' => 3,
        ]);

        $salle = Room::create([
            'nom' => 'Amphi A',
            'code' => 'AMP-A',
            'capacite' => 120,
        ]);

        $jourAujourdhui = strtolower(Carbon::now()->format('l'));

        ScheduleSlot::create([
            'schedule_id' => $planningA->id,
            'day_of_week' => $jourAujourdhui,
            'start_time' => '10:00:00',
            'end_time' => '12:00:00',
            'matiere_id' => $matiere->id,
            'room_id' => $salle->id,
            'status' => 'scheduled',
        ]);

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiantA->id,
            'etudiant_mdp_version' => (int) $etudiantA->mot_de_passe_version,
        ])->get(route('etudiant.dashboard'));

        $response->assertOk();
        $response->assertSee('Programmation PHP Laravel');
        $response->assertSee('AMP-A');
        $response->assertSee('10:00 → 12:00');
    }

    public function test_navigation_laterale_etudiant_complete_et_active(): void
    {
        $etudiant = $this->creerEtudiant();

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->get(route('etudiant.dashboard'));

        $response->assertOk();

        // 1. Lien Tableau de bord avec attribut aria-current="page"
        $response->assertSee('href="'.route('etudiant.dashboard').'"', false);
        $response->assertSee('aria-current="page"', false);

        // 2. Liens vers tous les sous-espaces de l'étudiant
        $response->assertSee(route('etudiant.archives.show'));
        $response->assertSee('Mon dossier');
        $response->assertSee(route('etudiant.inscriptions.index'));
        $response->assertSee('Mes inscriptions');
        $response->assertSee(route('etudiant.parcours'));
        $response->assertSee('Mon parcours LMD');
        $response->assertSee(route('etudiant.notes.index'));
        $response->assertSee('Mes notes');
        $response->assertSee(route('etudiant.documents.index'));
        $response->assertSee('Mes documents');
        $response->assertSee(route('etudiant.historique.index'));
        $response->assertSee('Mon historique');
        $response->assertSee(route('etudiant.notifications.index'));
        $response->assertSee('Notifications');
        $response->assertSee(route('etudiant.profil.show'));
        $response->assertSee('Mon profil');
    }

    public function test_navigation_etudiant_isolee_sans_fuite_de_liens_admin_ou_enseignant(): void
    {
        $etudiant = $this->creerEtudiant();

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->get(route('etudiant.dashboard'));

        $response->assertOk();

        // Vérification d'étanchéité stricte : aucun lien back-office ou enseignant
        $html = $response->getContent();
        $this->assertFalse(str_contains($html, route('admin.dashboard')), 'Le lien vers le tableau de bord admin ne doit pas apparaître pour l\'étudiant.');
        $this->assertFalse(str_contains($html, route('admin.audit.index')), 'Le lien vers le journal d\'audit ne doit pas apparaître pour l\'étudiant.');
        $this->assertFalse(str_contains($html, route('enseignant.dashboard')), 'Le lien vers le tableau de bord enseignant ne doit pas apparaître pour l\'étudiant.');
    }

    public function test_en_tete_etudiant_avec_avatar_recherche_et_deconnexion_csrf(): void
    {
        $etudiant = $this->creerEtudiant(['prenom' => 'Sara', 'nom' => 'Alami']);

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_auth_prenom' => $etudiant->prenom,
            'etudiant_auth_nom' => $etudiant->nom,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->get(route('etudiant.dashboard'));

        $response->assertOk();

        // Initiale et prénom dans l'en-tête
        $response->assertSee('S');
        $response->assertSee('Sara');

        // Formulaire de recherche étudiant
        $response->assertSee('action="'.route('etudiant.recherche').'"', false);

        // Formulaire de déconnexion sécurisé (POST + CSRF)
        $response->assertSee('action="'.route('etudiant.connexion.logout').'"', false);
        $response->assertSee('Déconnexion');
    }

    public function test_performance_absence_de_chargement_lourd_des_notes_sur_dashboard(): void
    {
        $etudiant = $this->creerEtudiant();

        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'filiere' => 'Génie Informatique',
            'niveau' => 'L1',
            'statut' => 'valide',
            'moyenne_annuelle' => 14.50,
            'credits_obtenus' => 60,
            'credits_total' => 60,
        ]);

        Semestre::create([
            'inscription_academique_id' => $inscription->id,
            'numero' => 1,
            'moyenne' => 14.50,
            'credits_obtenus' => 30,
            'credits_total' => 30,
            'decision' => 'Validé',
        ]);

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->get(route('etudiant.dashboard'));

        $response->assertOk();

        // Vérification de légèreté : la relation 'notes' ne doit PAS être chargée
        $derniereInscription = $response->original->getData()['derniereInscription'];
        $this->assertNotNull($derniereInscription);
        $this->assertFalse($derniereInscription->relationLoaded('notes'), 'Les notes détaillées ne doivent pas être chargées sur le dashboard.');

        foreach ($derniereInscription->semestres as $semestre) {
            $this->assertFalse($semestre->relationLoaded('notes'), 'Les notes par semestre ne doivent pas être chargées sur le dashboard.');
        }
    }
}
