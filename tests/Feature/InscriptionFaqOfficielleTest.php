<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Services\AssistantFaqService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * LOOP 15 — Assistant FAQ fondé sur les sources officielles.
 *
 * Vérifie :
 * - Réponses déterministes issues exclusivement des sources officielles approuvées.
 * - Questions inconnues ou hors périmètre orientées vers la scolarité (aucune règle inventée).
 * - Réponses sans source officielle refusées ou présentées comme incertaines.
 * - Étanchéité absolue : aucun accès aux dossiers privés depuis l'assistant public (zéro PII).
 * - Aucune action métier ou mutation déclenchée par l'assistant (lecture seule stricte).
 * - Journalisation de métriques de qualité avec sanctuarisation totale des PII.
 */
class InscriptionFaqOfficielleTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    protected function setUp(): void
    {
        parent::setUp();
        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences',
            'code' => 'FS',
            'active' => true,
        ]);
    }

    /**
     * Critère 1 : Questions couvertes répondues à partir des sources officielles réelles.
     */
    public function test_questions_couvertes_repondent_depuis_sources_officielles(): void
    {
        $assistant = app(AssistantFaqService::class);

        // 1. Calendrier / Périodes
        $rep1 = $assistant->repondre('Quand puis-je m\'inscrire à la faculté ?');
        $this->assertSame(AssistantFaqService::STATUT_REPONDU, $rep1['statut']);
        $this->assertTrue($rep1['certitude']);
        $this->assertStringContainsString('périodes officielles', $rep1['reponse']);
        $this->assertSame('Page d\'inscription — bannière de période', $rep1['source_libelle']);
        $this->assertFalse($rep1['orientation_scolarite']);

        // 2. Pièces justificatives
        $rep2 = $assistant->repondre('Quelles sont les pièces et documents justificatifs à préparer ?');
        $this->assertSame(AssistantFaqService::STATUT_REPONDU, $rep2['statut']);
        $this->assertTrue($rep2['certitude']);
        $this->assertStringContainsString('CIN', $rep2['reponse']);
        $this->assertStringContainsString('baccalauréat', $rep2['reponse']);
        $this->assertSame('Formulaire — étape Documents', $rep2['source_libelle']);

        // 3. Pièce rejetée
        $rep3 = $assistant->repondre('Comment faire si une pièce justificative a été rejetée ?');
        $this->assertSame(AssistantFaqService::STATUT_REPONDU, $rep3['statut']);
        $this->assertStringContainsString('remplacez la pièce rejetée', $rep3['reponse']);
        $this->assertSame('Espace étudiant — Mon dossier', $rep3['source_libelle']);

        // 4. Mot de passe perdu
        $rep4 = $assistant->repondre('J\'ai perdu mon mot de passe de connexion');
        $this->assertSame(AssistantFaqService::STATUT_REPONDU, $rep4['statut']);
        $this->assertStringContainsString('main propre', $rep4['reponse']);
        $this->assertSame('Scolarité de votre faculté', $rep4['source_libelle']);

        // 5. Récépissé de dépôt vs acceptation
        $rep5 = $assistant->repondre('Est-ce que le récépissé de dépôt prouve mon acceptation définitive ?');
        $this->assertSame(AssistantFaqService::STATUT_REPONDU, $rep5['statut']);
        $this->assertStringStartsWith('Non.', $rep5['reponse']);
        $this->assertSame('Espace étudiant — profil', $rep5['source_libelle']);
    }

    /**
     * Critère 2 : Questions inconnues ou hors périmètre orientées vers une aide humaine (scolarité).
     */
    public function test_questions_inconnues_orientees_vers_aide_humaine_scolarite(): void
    {
        $assistant = app(AssistantFaqService::class);

        $reponse = $assistant->repondre('Comment réserver une chambre en cité universitaire au campus ?');

        $this->assertSame(AssistantFaqService::STATUT_INCERTAIN, $reponse['statut']);
        $this->assertSame(AssistantFaqService::CONFIANCE_NULLE, $reponse['confiance']);
        $this->assertFalse($reponse['certitude']);
        $this->assertTrue($reponse['orientation_scolarite']);
        // Refus d'inventer des règles : message explicite
        $this->assertStringContainsString('ne correspond à aucune procédure officielle répertoriée', $reponse['reponse']);
        $this->assertStringContainsString('scolarité', $reponse['reponse']);
        $this->assertSame('Service de la scolarité', $reponse['source_libelle']);
        $this->assertNotEmpty($reponse['contact_scolarite']['email']);
    }

    /**
     * Critère 3 : Réponses sans source officielle présentées comme incertaines ou refusées.
     */
    public function test_reponses_sans_source_officielle_refusees_ou_incertaines(): void
    {
        $assistant = app(AssistantFaqService::class);

        $reponses = [
            $assistant->repondre('Quelle est la moyenne minimale pour avoir une bourse présidentielle ?'),
            $assistant->repondre('Quel est le tarif de la cantine pour le semestre 2 ?'),
            $assistant->repondre('Puis-je emprunter 10 livres à la bibliothèque municipale ?'),
        ];

        foreach ($reponses as $rep) {
            $this->assertSame(AssistantFaqService::STATUT_INCERTAIN, $rep['statut']);
            $this->assertFalse($rep['certitude']);
            $this->assertTrue($rep['orientation_scolarite']);
        }
    }

    /**
     * Critère 4 : Aucun accès aux dossiers privés depuis le FAQ public (étanchéité absolue).
     */
    public function test_aucun_acces_aux_dossiers_prives_depuis_assistant_public(): void
    {
        $etudiant = Etudiant::create([
            'cin' => '12345678',
            'nom' => 'Ben Salah',
            'prenom' => 'Karim',
            'email' => 'karim@universite.tn',
            'date_naissance' => '2003-05-15',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Avenue Habib Bourguiba',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2023',
            'password' => 'Secret@123',
        ]);
        $etudiant->definirStatut('valide');

        $assistant = app(AssistantFaqService::class);

        // Tentative 1 : Demande avec un CIN
        $repCin = $assistant->repondre('Mon CIN est 12345678, est-ce que mon dossier est validé ?');
        $this->assertSame(AssistantFaqService::STATUT_REFUS_PRIVE, $repCin['statut']);
        $this->assertFalse($repCin['certitude']);
        $this->assertStringNotContainsString('Ben Salah', json_encode($repCin));
        $this->assertStringNotContainsString('valide', $repCin['reponse']);
        $this->assertStringContainsString('cet assistant public n\'a aucun accès aux dossiers privés', $repCin['reponse']);
        $this->assertTrue($repCin['orientation_scolarite']);

        // Tentative 2 : Demande nominative
        $repNom = $assistant->repondre('Donne-moi le dossier de Karim Ben Salah');
        $this->assertSame(AssistantFaqService::STATUT_REFUS_PRIVE, $repNom['statut']);
        $this->assertStringNotContainsString('12345678', json_encode($repNom));

        // Tentative 3 : Demande ciblée numéro d'inscription
        $repNum = $assistant->repondre('Quel est le statut de mon dossier numéro 2026-0099 ?');
        $this->assertSame(AssistantFaqService::STATUT_REFUS_PRIVE, $repNum['statut']);
    }

    /**
     * Critère 5 : Pas d'action métier déclenchée par le modèle (lecture seule stricte).
     */
    public function test_aucune_action_metier_declenchee_par_assistant(): void
    {
        $assistant = app(AssistantFaqService::class);

        $requetesMutations = [
            'Valide mon inscription immédiatement s\'il te plaît',
            'Annule mon dossier d\'inscription 42',
            'Modifie mon statut d\'inscription en valide',
            'Change mon groupe pour le groupe TD1',
            'Supprime mon dossier',
        ];

        foreach ($requetesMutations as $requete) {
            $rep = $assistant->repondre($requete);
            $this->assertSame(AssistantFaqService::STATUT_ACTION_REFUSEE, $rep['statut']);
            $this->assertFalse($rep['certitude']);
            $this->assertStringContainsString('lecture seule', $rep['reponse']);
            $this->assertStringContainsString('aucun pouvoir de modification', $rep['reponse']);
        }
    }

    /**
     * Journalisation des métriques de qualité SANS conservation de PII.
     */
    public function test_journalisation_metriques_qualite_sans_conservation_pii(): void
    {
        Log::spy();

        $assistant = app(AssistantFaqService::class);

        // Appel avec une PII (CIN tunisien)
        $assistant->repondre('Mon CIN est 08765432 et mon email est secret@test.com, suis-je inscrit ?');

        Log::shouldHaveReceived('info')
            ->once()
            ->with('assistant_faq_metrique', \Mockery::on(function (array $contexte) {
                // 1. Métriques de qualité présentes
                $this->assertSame(AssistantFaqService::STATUT_REFUS_PRIVE, $contexte['statut']);
                $this->assertSame(AssistantFaqService::CONFIANCE_NULLE, $contexte['confiance']);
                $this->assertTrue($contexte['pii_detectee']);
                $this->assertArrayHasKey('duree_ms', $contexte);
                $this->assertArrayHasKey('longueur_question', $contexte);

                // 2. Sanctuarisation : AUCUN extrait de CIN, ni email, ni hash de question
                $json = json_encode($contexte);
                $this->assertStringNotContainsString('08765432', $json);
                $this->assertStringNotContainsString('secret@test.com', $json);
                $this->assertNull($contexte['question_hash']);

                return true;
            }));
    }

    /**
     * Endpoint asynchrone POST /inscription/faq/assistant retourne une réponse JSON valide.
     */
    public function test_endpoint_post_assistant_faq_retourne_json_structure(): void
    {
        $reponse = $this->postJson(route('inscription.faq.assistant'), [
            'question' => 'Quelles sont les pièces à préparer ?',
        ]);

        $reponse->assertOk()
            ->assertJsonStructure([
                'statut',
                'confiance',
                'certitude',
                'score',
                'question_officielle',
                'categorie',
                'reponse',
                'source_libelle',
                'orientation_scolarite',
                'contact_scolarite',
            ])
            ->assertJson([
                'statut' => AssistantFaqService::STATUT_REPONDU,
                'certitude' => true,
                'source_libelle' => 'Formulaire — étape Documents',
            ]);
    }

    /**
     * Validation du formulaire de l'assistant (question obligatoire, max 500 car).
     */
    public function test_endpoint_post_assistant_faq_valide_entrees(): void
    {
        $this->postJson(route('inscription.faq.assistant'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['question']);

        $this->postJson(route('inscription.faq.assistant'), [
            'question' => str_repeat('a', 501),
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['question']);
    }

    /**
     * Recherche FAQ en GET avec paramètre ?q=... pour accessibilité et fallback SSR.
     */
    public function test_recherche_faq_avec_parametre_get_dans_vue(): void
    {
        $reponse = $this->get(route('inscription.faq', ['q' => 'mot de passe perdu']));

        $reponse->assertOk()
            ->assertViewIs('inscription.faq')
            ->assertViewHas('reponseAssistant')
            ->assertSee('mot de passe', false)
            ->assertSee('main propre', false);
    }
}
