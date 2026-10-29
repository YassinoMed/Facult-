<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AiAssistant\Contracts\RechercheDocumentaire;
use App\Modules\AiAssistant\Models\AiMorceauIndex;
use App\Modules\AiAssistant\Models\AiSourceDocumentaire;
use App\Modules\AiAssistant\Services\AiChatService;
use App\Modules\AiAssistant\Services\RechercheIndexPartage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * LOT 6 — Base documentaire (RAG) : indexation gouvernée de sources
 * EXPLICITES, anti-doublon sha256, statut retire réversible, garde
 * PII, contrôle d'accès AVANT restitution, jokers LIKE neutralisés,
 * plafond de restitution, branchement bout-en-bout du chat.
 *
 * Fixtures : `tests/tmp-rag-*` (dans le projet pour confiner
 * l'indexeur aux chemins internes ; supprimées en tearDown — jamais en storage).
 */
final class AiAssistantRagTest extends TestCase
{
    use RefreshDatabase;

    private string $dossier;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.ai-assistant.active' => true]);

        $this->dossier = base_path('tests/tmp-rag-'.substr((string) md5(uniqid()), 0, 8));
        File::makeDirectory($this->dossier, 0775, true, true);

        $this->agent = User::creerCompteBackOffice([
            'name' => 'Agent RAG', 'email' => 'rag@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dossier)) {
            File::deleteDirectory($this->dossier);
        }

        parent::tearDown();
    }

    private function pointer(string $fichierRelatif): void
    {
        config(['ai-assistant.rag.sources' => ['tests' => [$fichierRelatif]]]);
    }

    private function ecrireFixture(string $nom, string $contenu): string
    {
        $chemin = $this->dossier.'/'.$nom;
        File::put($chemin, $contenu);

        return 'tests/'.basename($this->dossier).'/'.$nom;
    }

    /* ── Indexation ─────────────────────────────────────────────── */

    public function test_le_reindexeur_indexe_les_fichiers_explicites(): void
    {
        $relatif = $this->ecrireFixture('reglement.md',
            "## Règle cycle inscription\n".
            str_repeat("Le cycle d'inscription comporte les étapes suivantes. ", 40));

        $this->pointer($relatif);

        $insertions = app(RechercheDocumentaire::class)->reindexer();

        $this->assertGreaterThanOrEqual(2, $insertions,
            'Un document dépassant taille_morceau est découpé en plusieurs chunks.');
        $this->assertSame($insertions, AiMorceauIndex::count(),
            'Le compteur retourné == les chunks réellement actifs.');
        $this->assertSame(1, AiSourceDocumentaire::count());

        $registre = AiSourceDocumentaire::where('chemin', $relatif)->sole();
        $this->assertSame('actif', $registre->statut);
        $this->assertSame(hash('sha256', File::get(base_path().'/'.$relatif)), $registre->sha256);
    }

    public function test_le_reindexage_inchange_ne_provoque_aucune_ecriture(): void
    {
        $relatif = $this->ecrireFixture('reglement.md', 'Contenu stable du règlement.');
        $this->pointer($relatif);
        $recherche = app(RechercheDocumentaire::class);

        $recherche->reindexer();
        $recherche->reindexer(); // seconde passe : sha identique.

        $this->assertSame(1, AiMorceauIndex::count());
        $this->assertSame(1, AiSourceDocumentaire::count());
    }

    public function test_une_modification_retire_les_anciens_morceaux_et_monte_la_version(): void
    {
        $relatif = $this->ecrireFixture('reglement.md', 'Première version officielle du règlement.');
        $this->pointer($relatif);
        $recherche = app(RechercheDocumentaire::class);
        $recherche->reindexer();

        File::put(base_path().'/'.$relatif, 'Seconde version totalement réécrite du règlement.');
        $recherche->reindexer();

        $actifs = AiMorceauIndex::query()->get();
        $this->assertCount(1, $actifs);
        $this->assertStringContainsString('Seconde version', (string) $actifs->first()->contenu_morceau);

        $retires = AiMorceauIndex::withTrashed()->whereNotNull('deleted_at')->get();
        $this->assertGreaterThanOrEqual(1, $retires->count(), 'Les anciens morceaux sont en statut retire (soft-delete).');

        $registre = AiSourceDocumentaire::where('chemin', $relatif)->sole();
        $this->assertSame(2, (int) $registre->version_indexeur);
    }

    public function test_une_source_absente_de_la_liste_est_marquee_retiree_puis_reactivee(): void
    {
        $relatifA = $this->ecrireFixture('a.md', 'Document alpha officiel.');
        $relatifB = $this->ecrireFixture('b.md', 'Document beta officiel.');
        config(['ai-assistant.rag.sources' => ['tests' => [$relatifA, $relatifB]]]);

        $recherche = app(RechercheDocumentaire::class);
        $recherche->reindexer();

        // A sort de la liste explicite : chunks retirés + statut retire.
        config(['ai-assistant.rag.sources' => ['tests' => [$relatifB]]]);
        $recherche->reindexer();

        $this->assertSame(1, AiMorceauIndex::count(), 'Seul b.md reste actif.');
        $regA = AiSourceDocumentaire::where('chemin', $relatifA)->sole();
        $this->assertSame('retire', $regA->statut);
        $this->assertSame(0, AiMorceauIndex::query()->where('chemin_fichier', $relatifA)->count());

        // Retour dans la liste : réactivation sans réindexation forcée.
        config(['ai-assistant.rag.sources' => ['tests' => [$relatifA, $relatifB]]]);
        $recherche->reindexer();

        $regA->refresh();
        $this->assertSame('actif', $regA->statut);
        $this->assertSame(2, AiMorceauIndex::count());
    }

    public function test_un_fichier_contenant_des_pii_est_saute_entier(): void
    {
        $relatifPii = $this->ecrireFixture('fuites.md',
            'Contactez contact.etudiant@universite.tn pour le CIN: 12345678.');
        $relatifOk = $this->ecrireFixture('sain.md', 'Document sain sans données personnelles.');
        config(['ai-assistant.rag.sources' => ['tests' => [$relatifPii, $relatifOk]]]);

        app(RechercheDocumentaire::class)->reindexer();

        $this->assertSame(1, AiMorceauIndex::count(), 'Le fichier sain seul est indexé.');
        $this->assertSame('sain.md', AiMorceauIndex::sole()->titre);
        $this->assertNull(AiSourceDocumentaire::where('chemin', $relatifPii)->first(),
            'Le fichier PII n\'est même pas enregistré.');
    }

    public function test_les_fichiers_interdits_sont_exclus_meme_listes_explicitement(): void
    {
        // Un fichier SECRET.env est interdit PAR SUFFIXE, même pointé
        // explicitement dans la liste de configuration.
        $relatif = $this->ecrireFixture('secrets.env', 'AI_DISTANT_CLE=CLE_SYNTHETIQUE_TEST');
        $sain = $this->ecrireFixture('reglement.md', 'Document sain et unique.');
        config(['ai-assistant.rag.sources' => ['tests' => [$relatif, $sain]]]);

        $insertions = app(RechercheDocumentaire::class)->reindexer();

        $this->assertSame(1, $insertions);
        $fichiers = AiMorceauIndex::pluck('chemin_fichier')->all();
        $this->assertNotContains($relatif, $fichiers,
            'Un fichier se terminant par .env reste exclu, même listé à la main.');
    }

    /* ── Recherche ──────────────────────────────────────────────── */

    public function test_la_recherche_restitue_titre_chemin_et_extrait(): void
    {
        $relatif = $this->ecrireFixture('reglement.md',
            'Le délai de validation des dossiers d\'inscription est de 5 jours ouvrables.');
        $this->pointer($relatif);
        app(RechercheDocumentaire::class)->reindexer();

        $reponse = app(RechercheDocumentaire::class)->rechercher('délai de validation', 5, false);

        $this->assertCount(1, $reponse);
        $this->assertSame('reglement.md', $reponse[0]->titre);
        $this->assertSame('tests/'.basename($this->dossier).'/reglement.md', $reponse[0]->chemin);
        $this->assertStringContainsString('5 jours ouvrables', $reponse[0]->extrait);
        $this->assertLessThanOrEqual(240, mb_strlen($reponse[0]->extrait));
    }

    public function test_le_plafond_de_restitution_est_respecte(): void
    {
        config(['ai-assistant.rag.sources' => ['tests' => []]]);

        // Trois fichiers listés au mot-clé commun ; limite imposée : 2.
        $relatifs = [
            $this->ecrireFixture('a.md', 'Le mot commun apparaît ici une première fois.'),
            $this->ecrireFixture('b.md', 'Le mot commun apparaît ici aussi.'),
            $this->ecrireFixture('c.md', 'Encore le mot commun pour la première fois.'),
        ];
        config(['ai-assistant.rag.sources' => ['tests' => $relatifs]]);
        app(RechercheDocumentaire::class)->reindexer();

        $reponse = app(RechercheDocumentaire::class)->rechercher('commun', 2, false);

        $this->assertCount(2, $reponse, 'Le plafond (min(limite, chunks_rag_max)) borne la restitution.');
    }

    public function test_les_caracteres_joker_du_like_sont_neutralises(): void
    {
        $relatif = $this->ecrireFixture('reglement.md', 'Fenêtre du règlement 50x5 pourcent OFF');
        $this->pointer($relatif);
        app(RechercheDocumentaire::class)->reindexer();

        // '_' et '%' du LIKE doivent être LITTÉRAUX (jamais un joker).
        $this->assertSame([], app(RechercheDocumentaire::class)->rechercher('50_5', 5, false),
            'Aucune correspondance masquée : 50_5 ≠ 50x5 quand le joker est neutralisé.');
        $this->assertSame([], app(RechercheDocumentaire::class)->rechercher('pourc%', 5, false),
            'Le pour-cent ne doit pas rattraper pourcent.');
        $this->assertNotSame([], app(RechercheDocumentaire::class)->rechercher('50x5', 5, false));
        $this->assertNotSame([], app(RechercheDocumentaire::class)->rechercher('pourcent OFF', 5, false),
            'Une vraie phrase citée du document reste restituable.');
    }

    public function test_les_chunks_sensibles_ne_sortent_qu_avec_permission(): void
    {
        // Chunk sensible pointé E/S : visible en restreint, caché par défaut.
        $relatif = $this->ecrireFixture('sensible.md', 'Données internes techniques exclusives.');
        $this->pointer($relatif);
        app(RechercheDocumentaire::class)->reindexer();

        AiMorceauIndex::sole()->forceFill(['est_sensible' => true])->save();

        $this->assertSame([], app(RechercheDocumentaire::class)->rechercher('données internes', 5, false),
            'Chunk sensible : JAMAIS restitué sans permission.');
        $this->assertSame(1, count(app(RechercheDocumentaire::class)->rechercher('données internes', 5, true)));
    }

    public function test_les_fichiers_restreints_du_registre_sont_masques_par_defaut(): void
    {
        $relatif = $this->ecrireFixture('interne.md', 'Analyse interne réservée au mode développeur.');
        $this->pointer($relatif);
        app(RechercheDocumentaire::class)->reindexer();

        AiSourceDocumentaire::where('chemin', $relatif)->sole()->forceFill(['restreinte' => true])->save();

        $this->assertSame([], app(RechercheDocumentaire::class)->rechercher('analyse interne', 5, false),
            'Fichier restreint du registre : masqué pour le demandeur sans permission.');
        $this->assertSame(1, count(app(RechercheDocumentaire::class)->rechercher('analyse interne', 5, true)));
    }

    /* ── Focalisation : base de connaissance plateforme ─────────── */

    public function test_la_restitution_se_focalise_sur_la_base_de_connaissance_explicite(): void
    {
        $plateforme = $this->ecrireFixture('reglement.md',
            "## Délais\nLe délai de validation est de 5 jours ouvrables.");
        $this->pointer($plateforme);
        app(RechercheDocumentaire::class)->reindexer();

        // Bruit DANS le même index partagé (venu d'un autre indexeur
        // d'outillage ou d'un rapport d'ingénierie). Motifs identiques
        // : sans focalisation, la recherche mélangerait le bruit à la
        // connaissance plateforme.
        AiMorceauIndex::create([
            'source' => 'docs', 'titre' => 'icônes', 'chemin_fichier' => '.agents/skills/ui-x/icons.json',
            'index_morceau' => 1, 'contenu_morceau' => 'Le délai de validation est de 5 jours ouvrables.',
            'empreinte_sha256' => hash('sha256', 'bruit-icônes|1'), 'version_indexeur' => 1,
        ]);
        AiMorceauIndex::create([
            'source' => 'docs', 'titre' => 'rapport-boucle', 'chemin_fichier' => '.opencode/boucles/rapport.md',
            'index_morceau' => 1, 'contenu_morceau' => 'Le délai de validation est d autres jours.',
            'empreinte_sha256' => hash('sha256', 'bruit-rapport|1'), 'version_indexeur' => 1,
        ]);

        $repondu = app(RechercheDocumentaire::class)->rechercher('délai de validation', 5, false);

        // Seuls les fichiers de la liste EXPLICITE sortent : le bruit
        // outillage / engineering est ignoré de la recherche.
        $this->assertCount(1, $repondu,
            'La restitution ne considère que la base de connaissance plateforme (rag.sources).');
        $this->assertSame('reglement.md', $repondu[0]->titre);
        $this->assertSame($plateforme, $repondu[0]->chemin);

        // La focalisation reste GOUVERNÉE par la configuration : quand
        // la liste explicite déclare un chemin supplémentaire, il
        // redevient restituable (réversible, jamais dressée à la main).
        config(['ai-assistant.rag.sources' => ['tests' => [$plateforme, '.agents/skills/ui-x/icons.json']]]);
        $elargi = app(RechercheDocumentaire::class)->rechercher('délai de validation', 5, false);
        $this->assertCount(2, $elargi, 'Chemin déclaré explicitement au retour focus → restitué.');
    }

    /* ── Branchement bout-en-bout ────────────────────────────────── */

    public function test_le_chat_cite_la_source_indexee(): void
    {
        $relatif = $this->ecrireFixture('reglement.md',
            'La décision de réduction des frais d\'inscription dépend du domaine budgétaire annuel.');
        $this->pointer($relatif);
        app(RechercheDocumentaire::class)->reindexer();

        $this->assertInstanceOf(RechercheIndexPartage::class, app(RechercheDocumentaire::class),
            'La liaison par défaut est le moteur réel (Lot 6).');

        $conversation = app(AiChatService::class)->creerConversation($this->agent, 'metier');
        $reponse = app(AiChatService::class)->envoyerMessage($this->agent, $conversation, 'réduction des frais ?');

        $this->assertStringContainsString("[deterministe] D'après la source citée", $reponse->contenu,
            'Le fournisseur factice cite son contexte : le branchement RAG est actif.');
        $this->assertSame('reglement.md', ($reponse->sources[0] ?? [])['titre']);
    }
}
