<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\OnlineRequests\Models\RequestType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOPs 15/16 — FAQ officielle + demande d'aide humaine :
 * 15 : page publique lecture seule, réponses sourcées depuis les règles
 *      réelles (config approuvée), aucune donnée de dossier, aucune
 *      action métier, questions non couvertes orientées scolarité.
 * 16 : moteur unique OnlineRequests (pas d'agenda inventé) ; portes
 *      d'entrée d'assistance depuis le contexte d'inscription (FAQ,
 *      wizard, mon dossier) + création bout-en-bout par l'étudiant.
 */
class FaqAideScolariteTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.student-dossiers.active' => true]);
        config(['modules.online-requests.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
    }

    private function creerEtudiant(string $cin = 'CIN-FAQ1'): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Nom', 'prenom' => 'Prenom',
            'email' => 'etu'.uniqid().'@test.ma', 'date_naissance' => '2004-01-01',
            'sexe' => 'M', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'rue', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('en_attente');

        return $etudiant->fresh();
    }

    // ── LOOP 15 : FAQ publique ──────────────────────────────────────────────

    public function test_faq_publique_rendue_invite_avec_sources_et_contact(): void
    {
        $reponse = $this->get(route('inscription.faq'));

        $reponse->assertOk()
            ->assertViewIs('inscription.faq')
            ->assertSee('Questions fréquentes')
            // Contenus config rendus via {{ }} : assertions en échappement Blade.
            ->assertSee("Quand puis-je m'inscrire ?")
            ->assertSee("Page d'inscription — bannière de période")
            // Questions non couvertes → orientation scolarité (contact configuré).
            ->assertSee("Votre question n'est pas traitée ici ?", false)
            ->assertSee(config('inscriptions.contact_scolarite.email'));
    }

    public function test_faq_est_lecture_seule_aucune_action_metier(): void
    {
        // GET only : aucune route d'écriture, aucun modèle manipulé.
        $this->post('/inscription/faq')->assertStatus(405);
        $this->get('/inscription/faq')->assertOk();
    }

    public function test_faq_cta_assistance_visible_uniquement_connecte(): void
    {
        $etudiant = $this->creerEtudiant();

        // Invité : contact scolarité, pas de CTA demande (pas d'identité).
        $invite = $this->get(route('inscription.faq'))->assertOk();
        $this->assertStringNotContainsString("Déposer une demande d'assistance", $invite->getContent());

        // Étudiant connecté + module actif : porte d'entrée assistance.
        $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('inscription.faq'))
            ->assertOk()
            ->assertSee("Déposer une demande d'assistance", false)
            ->assertSee(route('etudiant.demandes.create'));
    }

    // ── LOOP 15 : contact unique dans le wizard ─────────────────────────────

    public function test_wizard_affiche_contact_configure_et_lien_faq(): void
    {
        config(['inscriptions.contact_scolarite.email' => 'sco-test@universite.ac.ma']);

        $this->get(route('inscription.create'))
            ->assertOk()
            ->assertSee('sco-test@universite.ac.ma')
            ->assertSee('FAQ officielle')
            ->assertSee(route('inscription.faq'));
    }

    // ── LOOP 16 : porte d'entrée depuis le dossier étudiant ─────────────────

    public function test_mon_dossier_affiche_porte_assistance(): void
    {
        $etudiant = $this->creerEtudiant();

        $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.dossier.show'))
            ->assertOk()
            ->assertSee('Demander assistance')
            ->assertSee(route('etudiant.demandes.create'));
    }

    public function test_mon_dossier_masque_porte_si_module_inactif(): void
    {
        config(['modules.online-requests.active' => false]);
        $etudiant = $this->creerEtudiant();

        $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.dossier.show'))
            ->assertOk()
            ->assertDontSee('Demander assistance');
    }

    // ── LOOP 16 : création bout-en-bout (confirmation après enregistrement) ─

    public function test_demande_assistance_bout_en_bout_depuis_contexte(): void
    {
        $etudiant = $this->creerEtudiant('CIN-AIDE1');
        $typeLibre = RequestType::where('code', 'DEMANDE_LIBRE')->where('active', true)->firstOrFail();

        // Le formulaire propose le type d'assistance (libellé du type actif).
        $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.demandes.create'))
            ->assertOk()
            ->assertSee('Demande personnalisée');

        // Enregistrement serveur (référence générée) PUIS confirmation.
        $reponse = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->post(route('etudiant.demandes.store'), [
                'request_type_id' => $typeLibre->id,
                'objet' => 'Aide pour ma pièce rejetée',
                'description' => 'Je ne comprends pas le motif du rejet de ma pièce.',
                'confirmation' => '1',
            ]);

        $demande = OnlineRequest::where('etudiant_id', $etudiant->id)->firstOrFail();
        $reponse->assertRedirect(route('etudiant.demandes.show', $demande));
        $reponse->assertSessionHas('success');
        $this->assertNotEmpty($demande->reference);
    }

    // ── LOOP 15 approfondi : confiance, suggestions, état d'erreur ──────────
    // Les templates Alpine `x-if` sont servis bruts dans le HTML : les
    // marqueurs prouvent la présence des 3 comportements (même convention
    // que le reste de la suite ; l'exécution JS relève de la revue).

    public function test_carte_repondu_distingue_confiance_haute_et_moyenne(): void
    {
        $contenu = (string) $this->get(route('inscription.faq'))->assertOk()->getContent();

        // Confiance haute : badge vérifié (comportement historique conservé).
        $this->assertStringContainsString('Source officielle vérifiée', $contenu);

        // Confiance moyenne : présentée comme INCERTAINE (§cahier « Réponses
        // sans source officielle présentées comme incertaines »).
        $this->assertStringContainsString('Réponse indicative', $contenu);
        $this->assertStringContainsString("resultat.confiance !== 'haute'", $contenu);
        $this->assertStringContainsString('pertinence moyenne', $contenu);
    }

    public function test_suggestions_officielles_cliquables_sous_la_carte(): void
    {
        $contenu = (string) $this->get(route('inscription.faq'))->assertOk()->getContent();

        // Les 3 questions approuvées du service sont rejouables sans ressaisie.
        $this->assertStringContainsString('resultat.suggestions', $contenu);
        $this->assertStringContainsString('poserExemple(suggestion)', $contenu);
        $this->assertStringContainsString('Voir aussi', $contenu);
    }

    public function test_etat_erreur_explicite_avec_cap_scolarite(): void
    {
        $contenu = (string) $this->get(route('inscription.faq'))->assertOk()->getContent();

        // 422/429/5xx/réseau : jamais de spinner silencieux — erreur
        // explicite + contact scolarité (état `erreur` du composant).
        $this->assertStringContainsString('Service momentanément indisponible', $contenu);
        $this->assertStringContainsString('erreur && !chargement', $contenu);
        $this->assertStringContainsString((string) config('inscriptions.contact_scolarite.email'), $contenu);
    }

    public function test_assistant_endpoint_repond_confiance_moyenne_au_seuil_bas(): void
    {
        // Preuve serveur du distinguo : une question à cheval sur deux
        // entrées obtient `repondu` + `confiance: moyenne` (jamais
        // présentée comme certaine côté vue grâce au test précédent).
        $reponse = $this->postJson(route('inscription.faq.assistant'), [
            'question' => 'transfert dossier',
        ])->assertOk();

        $this->assertSame('repondu', $reponse->json('statut'));
        $this->assertSame('moyenne', $reponse->json('confiance'));
        $this->assertNotEmpty($reponse->json('source_libelle'));
        $this->assertCount(3, $reponse->json('suggestions'));
    }
}
