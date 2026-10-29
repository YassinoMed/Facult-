<?php

namespace Tests\Feature\Security;

use App\Models\AnneeUniversitaire;
use App\Models\DossierCorrection;
use App\Models\DossierVerification;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Modules\StudentDossiers\Models\StudentDossier;
use App\Services\SuiviDossierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP-10 — Suivi de dossier d'inscription :
 *
 * Vérifie :
 * 1. Accès sécurisé à l'espace de suivi privé (redirection des visiteurs non authentifiés).
 * 2. Cloisonnement strict anti-IDOR : l'étudiant connecté consulte uniquement son propre dossier.
 * 3. Neutralisation de toute tentative d'usurpation via paramètres GET/POST (?etudiant_id, ?id).
 * 4. Révocation immédiate de la session de suivi si mot_de_passe_version est obsolète.
 * 5. État réel sans déclaration de validation prématurée (statut en_attente, basse priorité, décision à venir).
 * 6. Action prioritaire de téléchargement du récépissé officiel dès que le statut est validé.
 * 7. Message clair et explicite en cas de candidature refusée (statut rejete), sans faux espoir ni action active.
 * 8. Bascule en action requise haute priorité lorsqu'une correction administrative est demandée (DossierCorrection).
 * 9. Bascule en action requise haute priorité lorsqu'une pièce justificative est rejetée (StudentDocument).
 * 10. Chronologie d'instruction complète et cohérente avec les 5 jalons métier (dépôt, vérification, complément, instruction, décision).
 * 11. Affectation pédagogique (groupe) : affichée uniquement après attribution réelle en base, aucune affectation anticipée.
 * 12. Consultation publique sécurisée à double facteur (numéro d'inscription officiel + CIN).
 * 13. Consultation publique réussie avec date de naissance normalisée (formats français DD/MM/YYYY et ISO YYYY-MM-DD).
 * 14. Anti-énumération absolue en consultation publique : réponses strictement identiques en cas de référence inexistante ou mauvais second facteur.
 * 15. Support de la consultation publique JSON pour popup embarqué avec masquage systématique des PII (email_masque, cin_masquee).
 * 16. Protection anti-force brute par rate limiting sur la recherche publique (10 requêtes / minute).
 * 17. Validation stricte des données d'entrée en recherche publique (champs obligatoires).
 */
class Loop10SuiviDossierInscriptionTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private Programme $programme;

    private Niveau $niveau;

    private AnneeUniversitaire $annee;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.student-dossiers.active' => true,
        ]);

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
            'capacite' => 120,
        ]);

        $this->niveau = Niveau::create([
            'programme_id' => $this->programme->id,
            'code' => 'L1',
            'nom' => 'Licence 1ère année',
            'ordre' => 1,
            'actif' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026',
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30',
        ]);
    }

    private function creerEtudiant(array $attributs = []): Etudiant
    {
        static $compteur = 1;

        $statut = $attributs['statut'] ?? 'en_attente';
        unset($attributs['statut']);

        $defauts = [
            'cin' => sprintf('SUIV%04d', $compteur),
            'nom' => 'El Alami',
            'prenom' => 'Yassine '.$compteur,
            'email' => sprintf('etudiant.suivi.%d@example.com', $compteur),
            'telephone' => sprintf('+2126000000%02d', $compteur),
            'date_naissance' => '2005-04-12',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Avenue Allal Ben Abdellah',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2025',
            'mot_de_passe_version' => 1,
            'password' => bcrypt('Secret12345!'),
        ];

        $compteur++;

        $etudiant = Etudiant::create(array_merge($defauts, $attributs));
        if ($statut !== 'en_attente') {
            $etudiant->definirStatut($statut);
        }

        return $etudiant;
    }

    public function test_visiteur_non_authentifie_redirige_depuis_suivi_prive(): void
    {
        $response = $this->get(route('etudiant.suivi'));

        $response->assertRedirect(route('etudiant.connexion.show'));
    }

    public function test_etudiant_authentifie_accede_a_son_propre_suivi_dossier(): void
    {
        $etudiant = $this->creerEtudiant();

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->get(route('etudiant.suivi'));

        $response->assertOk();
        $response->assertSee('Suivi de mon dossier');
        $response->assertSee($etudiant->num_inscription);
        $response->assertSee($etudiant->nom_complet);
        $response->assertSee($this->faculte->nom);
        $response->assertSee($this->programme->nom);
    }

    public function test_suivi_prive_est_cloisonne_anti_idor_aucun_parametre_client_ne_peut_usurper(): void
    {
        $etudiantA = $this->creerEtudiant(['cin' => 'IDORA001', 'email' => 'etudiantA@example.com']);
        $etudiantB = $this->creerEtudiant(['cin' => 'IDORB002', 'email' => 'etudiantB@example.com']);

        // A tente d'usurper le dossier de B via query params
        $response = $this->withSession([
            'etudiant_auth_id' => $etudiantA->id,
            'etudiant_mdp_version' => (int) $etudiantA->mot_de_passe_version,
        ])->get(route('etudiant.suivi', [
            'id' => $etudiantB->id,
            'etudiant_id' => $etudiantB->id,
        ]));

        $response->assertOk();
        // A voit uniquement ses données
        $response->assertSee($etudiantA->nom_complet);
        $response->assertSee($etudiantA->num_inscription);
        // Les données de B sont introuvables
        $response->assertDontSee($etudiantB->nom_complet);
        $response->assertDontSee($etudiantB->num_inscription);
    }

    public function test_session_invalidee_si_version_mot_de_passe_obsolete(): void
    {
        $etudiant = $this->creerEtudiant(['mot_de_passe_version' => 3]);

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => 2, // version antérieure obsolète
        ])->get(route('etudiant.suivi'));

        $response->assertRedirect(route('etudiant.connexion.show'));
        $this->assertNull(session('etudiant_auth_id'));
    }

    public function test_statut_en_cours_instruction_neutre_sans_declaration_validee_prematuree(): void
    {
        $etudiant = $this->creerEtudiant(['statut' => 'en_attente']);

        $service = app(SuiviDossierService::class);
        $synthese = $service->synthese($etudiant);

        $this->assertSame('en_attente', $synthese['statut_brut']);
        $this->assertSame('En attente de validation académique', $synthese['statut_libelle']);
        $this->assertSame('information', $synthese['prochaine_action']['type']);
        $this->assertSame('basse', $synthese['prochaine_action']['priorite']);
        $this->assertNull($synthese['prochaine_action']['lien']);

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->get(route('etudiant.suivi'));

        $response->assertOk();
        $response->assertSee('En attente de validation académique');
        $response->assertDontSee('Télécharger le récépissé officiel');
    }

    public function test_statut_valide_declenche_action_telechargement_recepisse(): void
    {
        $etudiant = $this->creerEtudiant(['statut' => 'valide']);

        $service = app(SuiviDossierService::class);
        $synthese = $service->synthese($etudiant);

        $this->assertSame('valide', $synthese['statut_brut']);
        $this->assertSame('Inscription validée', $synthese['statut_libelle']);
        $this->assertSame('succes', $synthese['prochaine_action']['type']);
        $this->assertSame(route('etudiant.recepisse'), $synthese['prochaine_action']['lien']);

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->get(route('etudiant.suivi'));

        $response->assertOk();
        $response->assertSee('Inscription validée');
        $response->assertSee('Télécharger le récépissé officiel');
    }

    public function test_statut_rejete_affiche_message_officiel_sans_action_active(): void
    {
        $etudiant = $this->creerEtudiant(['statut' => 'rejete']);

        $service = app(SuiviDossierService::class);
        $synthese = $service->synthese($etudiant);

        $this->assertSame('rejete', $synthese['statut_brut']);
        $this->assertSame('Dossier non retenu', $synthese['statut_libelle']);
        $this->assertSame('rejet', $synthese['prochaine_action']['type']);
        $this->assertNull($synthese['prochaine_action']['lien']);

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->get(route('etudiant.suivi'));

        $response->assertOk();
        $response->assertSee('Dossier non retenu');
        $response->assertDontSee('Télécharger le récépissé officiel');
    }

    public function test_demande_de_correction_administrative_bascule_en_action_requise_prioritaire(): void
    {
        $etudiant = $this->creerEtudiant(['statut' => 'en_attente']);

        DossierCorrection::create([
            'etudiant_id' => $etudiant->id,
            'propose_par' => $this->agent->id,
            'champ' => 'code_postal',
            'ancienne_valeur' => '10000',
            'nouvelle_valeur' => '10090',
            'motif' => 'Code postal non conforme à la ville',
            'statut' => 'proposee',
        ]);

        $service = app(SuiviDossierService::class);
        $synthese = $service->synthese($etudiant);

        $this->assertTrue($synthese['a_des_corrections']);
        $this->assertSame('Complément d\'information requis', $synthese['statut_libelle']);
        $this->assertSame('action_requise', $synthese['prochaine_action']['type']);
        $this->assertSame('haute', $synthese['prochaine_action']['priorite']);
        $this->assertSame(route('etudiant.corrections.index'), $synthese['prochaine_action']['lien']);

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->get(route('etudiant.suivi'));

        $response->assertOk();
        $response->assertSee('Complément');
        $response->assertSee('Code postal non conforme à la ville');
        $response->assertSee('Accéder au formulaire de correction et téléversement');
    }

    public function test_piece_justificative_rejetee_bascule_en_action_requise_prioritaire(): void
    {
        $etudiant = $this->creerEtudiant(['statut' => 'en_attente']);

        $dossier = StudentDossier::create([
            'etudiant_id' => $etudiant->id,
            'reference' => 'DOS-'.$etudiant->num_inscription,
            'status' => 'open',
        ]);

        StudentDocument::create([
            'student_dossier_id' => $dossier->id,
            'category' => 'identite',
            'document_type' => 'cin',
            'title' => 'Carte Nationale d\'Identité',
            'original_filename' => 'cin_recto.jpg',
            'storage_path' => 'student-dossiers/dummy.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'size' => 204800,
            'sha256' => hash('sha256', 'dummy'),
            'status' => 'rejected',
            'rejected_reason' => 'Image illisible ou floue',
        ]);

        $service = app(SuiviDossierService::class);
        $synthese = $service->synthese($etudiant);

        $this->assertTrue($synthese['a_des_corrections']);
        $this->assertSame('Complément d\'information requis', $synthese['statut_libelle']);
        $this->assertCount(1, $synthese['pieces_a_corriger']);
        $this->assertSame('Image illisible ou floue', $synthese['pieces_a_corriger'][0]['motif']);

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->get(route('etudiant.suivi'));

        $response->assertOk();
        $response->assertSee('Complément');
        $response->assertSee('Image illisible ou floue');
    }

    public function test_chronologie_complete_reflete_fidelement_les_cinq_jalons_metier(): void
    {
        $etudiant = $this->creerEtudiant(['statut' => 'en_attente']);
        $service = app(SuiviDossierService::class);

        // 1. Initialement sans vérification enregistrée
        $chrono1 = $service->construireChronologie($etudiant, [], []);
        $cles = array_column($chrono1, 'cle');
        $this->assertSame(['depot', 'verification', 'instruction', 'decision'], $cles);
        $this->assertSame('complete', $chrono1[0]['statut']); // depot
        $this->assertSame('en_cours', $chrono1[1]['statut']); // verification
        $this->assertSame('a_venir', $chrono1[2]['statut']);  // instruction
        $this->assertSame('a_venir', $chrono1[3]['statut']);  // decision

        // 2. Avec vérification enregistrée
        DossierVerification::create([
            'etudiant_id' => $etudiant->id,
            'verdict' => 'valide',
            'score' => 100,
        ]);

        $chrono2 = $service->construireChronologie($etudiant, [], []);
        $this->assertSame('complete', $chrono2[1]['statut']); // verification
        $this->assertSame('en_cours', $chrono2[2]['statut']); // instruction

        // 3. Avec correction demandée : étape complément intercalée et instruction suspendue
        $corrections = [[
            'id' => 1,
            'champ' => 'adresse',
            'champ_libelle' => 'Adresse',
            'ancienne_valeur' => 'A',
            'nouvelle_valeur' => 'B',
            'motif' => 'Préciser la rue',
            'date' => '26/10/2026',
        ]];
        $chrono3 = $service->construireChronologie($etudiant, $corrections, []);
        $cles3 = array_column($chrono3, 'cle');
        $this->assertSame(['depot', 'verification', 'complement', 'instruction', 'decision'], $cles3);
        $this->assertSame('action_requise', $chrono3[2]['statut']); // complement
        $this->assertSame('suspendue', $chrono3[3]['statut']);      // instruction
    }

    public function test_groupe_pedagogique_non_affiche_par_anticipation_et_affiche_apres_affectation_reelle(): void
    {
        $etudiant = $this->creerEtudiant(['statut' => 'en_attente']);
        $service = app(SuiviDossierService::class);

        // Avant validation et sans groupe : nul
        $this->assertNull($service->resumerGroupe($etudiant));

        // Après validation mais sans groupe affecté : en_attente
        $etudiant->definirStatut('valide');
        $groupeEnAttente = $service->resumerGroupe($etudiant);
        $this->assertNotNull($groupeEnAttente);
        $this->assertSame('en_attente', $groupeEnAttente['statut']);
        $this->assertNull($groupeEnAttente['code']);

        // Après affectation réelle en base
        $groupe = Group::create([
            'code' => 'GRP-INFO-L1-A',
            'nom' => 'Groupe L1 Informatique Section A',
            'programme_id' => $this->programme->id,
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'capacite' => 30,
        ]);
        $etudiant->update(['groupe_id' => $groupe->id]);
        $etudiant->refresh();

        $groupeAffecte = $service->resumerGroupe($etudiant);
        $this->assertNotNull($groupeAffecte);
        $this->assertSame('affecte', $groupeAffecte['statut']);
        $this->assertSame('GRP-INFO-L1-A', $groupeAffecte['code']);

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->get(route('etudiant.suivi'));

        $response->assertOk();
        $response->assertSee('GRP-INFO-L1-A');
        $response->assertSee('Affectation confirmée');
    }

    public function test_consultation_publique_reussie_avec_reference_et_cin(): void
    {
        $etudiant = $this->creerEtudiant(['cin' => 'PUB12345', 'email' => 'public.etud@example.com']);

        $response = $this->post(route('inscription.suivi.rechercher'), [
            'reference' => $etudiant->num_inscription,
            'secondaire' => 'PUB12345',
        ]);

        $response->assertOk();
        // Masquage PII dans les données exposées
        $suiviData = $response->viewData('suivi');
        $this->assertNotNull($suiviData);
        $this->assertSame($etudiant->cin_masquee, $suiviData['etudiant']['cin_masquee']);
        $this->assertStringNotContainsString('PUB12345', $suiviData['etudiant']['cin_masquee']);
        $this->assertStringNotContainsString('public.etud@example.com', $suiviData['etudiant']['email_masque']);
        $response->assertDontSee('public.etud@example.com');
    }

    public function test_consultation_publique_reussie_avec_date_de_naissance_francaise_ou_iso(): void
    {
        $etudiant = $this->creerEtudiant([
            'cin' => 'DATEN123',
            'date_naissance' => '2005-04-12',
        ]);

        // 1. Format français DD/MM/YYYY
        $responseFr = $this->post(route('inscription.suivi.rechercher'), [
            'reference' => $etudiant->num_inscription,
            'secondaire' => '12/04/2005',
        ]);
        $responseFr->assertOk();
        $responseFr->assertSee($etudiant->nom_complet);

        // 2. Format ISO YYYY-MM-DD
        $responseIso = $this->post(route('inscription.suivi.rechercher'), [
            'reference' => $etudiant->num_inscription,
            'secondaire' => '2005-04-12',
        ]);
        $responseIso->assertOk();
        $responseIso->assertSee($etudiant->nom_complet);
    }

    public function test_consultation_publique_anti_enumeration_reponse_strictement_identique(): void
    {
        $etudiant = $this->creerEtudiant(['cin' => 'EXIST888']);
        $messageErreur = 'Aucun dossier correspondant n’a été trouvé avec ces références';

        // Cas A : Référence totalement inexistante
        $responseInconnue = $this->post(route('inscription.suivi.rechercher'), [
            'reference' => 'INS-2025-99999',
            'secondaire' => 'EXIST888',
        ]);
        $responseInconnue->assertOk();
        $responseInconnue->assertSee($messageErreur);
        $responseInconnue->assertDontSee($etudiant->nom_complet);

        // Cas B : Référence existante mais mauvais CIN (attaque par devinette)
        $responseMauvaisCin = $this->post(route('inscription.suivi.rechercher'), [
            'reference' => $etudiant->num_inscription,
            'secondaire' => 'FAUXCIN00',
        ]);
        $responseMauvaisCin->assertOk();
        $responseMauvaisCin->assertSee($messageErreur);
        $responseMauvaisCin->assertDontSee($etudiant->nom_complet);

        // Le contenu d'erreur est rigoureusement identique
        $this->assertSame(
            $responseInconnue->viewData('erreurNeutre'),
            $responseMauvaisCin->viewData('erreurNeutre')
        );
    }

    public function test_consultation_publique_reponse_json_embarquee_sanitisee(): void
    {
        $etudiant = $this->creerEtudiant([
            'cin' => 'JSN99999',
            'email' => 'confidentiel.json@example.com',
        ]);

        $response = $this->postJson(route('inscription.suivi.rechercher'), [
            'reference' => $etudiant->num_inscription,
            'secondaire' => 'JSN99999',
        ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('suivi.reference', $etudiant->num_inscription);
        $response->assertJsonPath('suivi.etudiant.nom_complet', $etudiant->nom_complet);

        // Vérification de la non-divulgation des PII brutes
        $contenu = $response->getContent();
        $this->assertStringNotContainsString('confidentiel.json@example.com', $contenu);
        $this->assertStringNotContainsString('JSN99999', $contenu);
        $this->assertStringNotContainsString('Secret12345!', $contenu);
    }

    public function test_consultation_publique_rate_limiting_dix_requetes_par_minute(): void
    {
        // Envoi de 10 requêtes autorisées
        for ($i = 0; $i < 10; $i++) {
            $this->post(route('inscription.suivi.rechercher'), [
                'reference' => 'INS-2025-00001',
                'secondaire' => 'TEST'.$i,
            ]);
        }

        // 11ème requête bloquée par le middleware throttle:10,1
        $responseBloquee = $this->post(route('inscription.suivi.rechercher'), [
            'reference' => 'INS-2025-00001',
            'secondaire' => 'TEST11',
        ]);

        $this->assertSame(429, $responseBloquee->getStatusCode());
    }

    public function test_consultation_publique_validation_des_champs_obligatoires(): void
    {
        $response = $this->post(route('inscription.suivi.rechercher'), [
            'reference' => '',
            'secondaire' => '',
        ]);

        $response->assertSessionHasErrors(['reference', 'secondaire']);
    }
}
