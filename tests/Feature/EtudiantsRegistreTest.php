<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\DocumentAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\IntegrationLog;
use App\Models\SituationEtudiant;
use App\Models\User;
use App\Models\VueSauvegardee;
use App\Modules\Schedule\Models\Group;
use App\Services\FicheEtudiantService;
use App\Services\PreferencesService;
use App\Services\StudentStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * LOOP 1+4 — Registre étudiants : pagination sans-groupe, sécurité, bulk, destroy.
 */
class EtudiantsRegistreTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private User $scolarite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteA = Faculte::create(['nom' => 'FA', 'code' => 'FA', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'FB', 'code' => 'FB', 'active' => true]);
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.reg@test.tn', 'password' => 'Secret123!',
        ], 'admin_scolarite');
    }

    private function dossier(string $nom, int $faculteId, array $extra = []): Etudiant
    {
        static $n = 1;

        return Etudiant::create(array_merge([
            'nom' => $nom, 'prenom' => 'P', 'email' => "r{$n}@test.tn",
            'cin' => sprintf('760000%02d', $n++), 'sexe' => 'M', 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'adr', 'ville' => 'Tunis',
            'code_postal' => '1000', 'annee_bac' => '2025', 'faculte_id' => $faculteId,
        ], $extra));
    }

    // ── LOOP 2 : fiche 360° ──
    public function test_fiche_complete_relations_chargees_requetes_bornees(): void
    {
        $etudiant = $this->dossier('Fiche', $this->faculteA->id);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $reponse = $this->actingAs($this->scolarite)->get(route('admin.etudiants.show', $etudiant));
        $requetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        $reponse->assertOk();
        fwrite(STDERR, "\n[FICHE-ETUDIANT] requêtes show : {$requetes} (SQLite test).\n");

        $modele = $reponse->viewData('etudiant');
        foreach (['faculte', 'programmeRef', 'niveauRef', 'groupe', 'situations', 'affectations', 'inscriptionsAcademiques'] as $relation) {
            $this->assertTrue($modele->relationLoaded($relation), "Relation {$relation} non chargée.");
        }
        $this->assertLessThan(60, $requetes);
    }

    // ── LOOP 1 : pagination sans-groupe ──

    public function test_sans_groupe_pagination_defaut(): void
    {
        $this->dossier('D1', $this->faculteA->id);

        $page = $this->actingAs($this->scolarite)
            ->get(route('admin.etudiants.sans-groupe'))
            ->viewData('etudiants');

        $this->assertSame(12, $page->perPage());
    }

    public function test_sans_groupe_suit_preference(): void
    {
        app(PreferencesService::class)->ecrire((int) $this->scolarite->id, ['lignes_par_page' => 5]);
        for ($i = 1; $i <= 7; $i++) {
            $this->dossier('P'.$i, $this->faculteA->id);
        }

        $page = $this->actingAs($this->scolarite)
            ->get(route('admin.etudiants.sans-groupe'))
            ->viewData('etudiants');

        $this->assertSame(5, $page->perPage());
        $this->assertSame(7, $page->total());
    }

    public function test_sans_groupe_preference_excessive_bornee(): void
    {
        app(PreferencesService::class)->ecrire((int) $this->scolarite->id, ['lignes_par_page' => 9999]);
        $this->dossier('X', $this->faculteA->id);

        $page = $this->actingAs($this->scolarite)
            ->get(route('admin.etudiants.sans-groupe'))
            ->viewData('etudiants');

        $this->assertSame(100, $page->perPage());
    }

    public function test_sans_groupe_filtre_impose_et_affectes_exclus(): void
    {
        app(PreferencesService::class)->ecrire((int) $this->scolarite->id, ['lignes_par_page' => 5]);
        for ($i = 1; $i <= 6; $i++) {
            $this->dossier('S'.$i, $this->faculteA->id);
        }
        $groupe = Group::create([
            'code' => 'G1', 'nom' => 'G1', 'niveau' => 'L1', 'faculte_id' => $this->faculteA->id,
        ]);
        $this->dossier('Affecte', $this->faculteA->id, ['groupe_id' => $groupe->id]);

        // Tentative de contournement : sans_groupe=false ignoré côté serveur.
        $page1 = $this->actingAs($this->scolarite)
            ->get(route('admin.etudiants.sans-groupe', ['sans_groupe' => false]))
            ->viewData('etudiants');

        $this->assertSame(6, $page1->total());
        foreach ($page1->items() as $item) {
            $this->assertNull($item->groupe_id);
        }

        $page2 = $this->actingAs($this->scolarite)
            ->get(route('admin.etudiants.sans-groupe', ['page' => 2]))
            ->viewData('etudiants');
        $this->assertCount(1, $page2->items());

        // Isolation : agent B ne voit pas les dossiers A même avec pref perso.
        $agentB = User::creerCompteBackOffice([
            'name' => 'B', 'email' => 'b.reg@test.tn', 'password' => 'Secret123!',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');
        app(PreferencesService::class)->ecrire((int) $agentB->id, ['lignes_par_page' => 5]);

        $vueB = $this->actingAs($agentB)
            ->get(route('admin.etudiants.sans-groupe'))
            ->viewData('etudiants');
        $this->assertSame(0, $vueB->total());
    }

    // ── PHASE 1 : ergonomie (filtres persistants, vues, bulk) ──

    public function test_filtres_persistants_page_suivante(): void
    {
        for ($i = 1; $i <= 13; $i++) {
            $this->dossier('F'.$i, $this->faculteA->id);
        }

        $reponse = $this->actingAs($this->scolarite)
            ->get(route('admin.etudiants.index', ['statut' => 'en_attente']));
        $reponse->assertOk();

        $page = $reponse->viewData('etudiants');
        $this->assertSame(13, $page->total());
        $this->assertNotNull($page->nextPageUrl());
        $this->assertStringContainsString('statut=en_attente', $page->nextPageUrl());

        // Borne anti-N+1 sur l'index filtré (13 dossiers).
        $compte = 0;
        DB::listen(function () use (&$compte): void {
            $compte++;
        });
        $this->actingAs($this->scolarite)
            ->get(route('admin.etudiants.index', ['statut' => 'en_attente']))->assertOk();
        DB::flushQueryLog();
        fwrite(STDERR, "\n[INDEX-ETUDIANTS] requêtes index filtré : {$compte} (SQLite test).\n");
        $this->assertLessThan(50, $compte);
    }

    public function test_vue_sauvegardee_defaut_appliquee_url_vide(): void
    {
        $this->dossier('VV', $this->faculteA->id, ['groupe_id' => null]);
        VueSauvegardee::create([
            'user_id' => $this->scolarite->id, 'tableau' => 'etudiants',
            'nom' => 'Rejetés', 'filtres' => ['statut' => 'rejete'], 'par_defaut' => true,
        ]);

        // URL vide → vue par défaut (0 rejeté) ; URL explicite → priorité URL.
        $defaut = $this->actingAs($this->scolarite)->get(route('admin.etudiants.index'));
        $this->assertSame(0, $defaut->viewData('etudiants')->total());

        $explicite = $this->actingAs($this->scolarite)
            ->get(route('admin.etudiants.index', ['statut' => 'en_attente']));
        $this->assertSame(1, $explicite->viewData('etudiants')->total());
    }

    public function test_bulk_masque_sans_permission(): void
    {
        $lecteur = User::creerCompteBackOffice([
            'name' => 'L', 'email' => 'l1.reg@test.tn', 'password' => 'Secret123!',
        ], 'consultation');
        $this->dossier('BU', $this->faculteA->id);

        $reponse = $this->actingAs($lecteur)->get(route('admin.etudiants.index'));
        $reponse->assertOk();
        $reponse->assertDontSee('data-bulk-id', false);
    }

    public function test_invite_redirige_sur_toutes_les_routes(): void
    {
        $etudiant = $this->dossier('G', $this->faculteA->id);

        $this->get(route('admin.etudiants.index'))->assertRedirect();
        $this->get(route('admin.etudiants.show', $etudiant))->assertRedirect();
        $this->patch(route('admin.etudiants.updateStatut', $etudiant), ['statut' => 'valide'])
            ->assertRedirect();
        $this->delete(route('admin.etudiants.destroy', $etudiant))->assertRedirect();
        $this->get(route('admin.etudiants.exportDossier', $etudiant))->assertRedirect();
    }

    public function test_consultation_lecture_ok_ecritures_403(): void
    {
        $lecteur = User::creerCompteBackOffice([
            'name' => 'L', 'email' => 'l.reg@test.tn', 'password' => 'Secret123!',
        ], 'consultation');
        $etudiant = $this->dossier('H', $this->faculteA->id);

        $this->actingAs($lecteur)->get(route('admin.etudiants.show', $etudiant))->assertOk();
        $this->actingAs($lecteur)->patch(route('admin.etudiants.updateStatut', $etudiant), ['statut' => 'valide'])
            ->assertForbidden();
        $this->actingAs($lecteur)->delete(route('admin.etudiants.destroy', $etudiant))
            ->assertForbidden();
        $this->assertSame('en_attente', $etudiant->fresh()->statut);
    }

    public function test_idor_cross_faculte_403_sans_fuite(): void
    {
        $agentB = User::creerCompteBackOffice([
            'name' => 'B', 'email' => 'bb.reg@test.tn', 'password' => 'Secret123!',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');
        $etudiantA = $this->dossier('IA', $this->faculteA->id);

        $this->actingAs($agentB)->get(route('admin.etudiants.show', $etudiantA))->assertForbidden();
        $this->actingAs($agentB)->patch(route('admin.etudiants.updateStatut', $etudiantA), ['statut' => 'valide'])
            ->assertForbidden();
        $this->actingAs($agentB)->get(route('admin.etudiants.exportDossier', $etudiantA))->assertForbidden();
        $this->actingAs($agentB)->delete(route('admin.etudiants.destroy', $etudiantA))->assertForbidden();

        $this->assertSame('en_attente', $etudiantA->fresh()->statut);
        $this->assertDatabaseHas('etudiants', ['id' => $etudiantA->id]);
    }

    public function test_fiche_inexistante_404(): void
    {
        $this->actingAs($this->scolarite)->get('/admin/etudiants/999999')->assertNotFound();
    }

    public function test_bulk_mixte_et_doublons(): void
    {
        $dejaValide = $this->dossier('BV', $this->faculteA->id);
        $dejaValide->definirStatut('valide');
        $attente = $this->dossier('BA', $this->faculteA->id);

        // Lot mixte + doublon : chacun traité une fois, idempotence du no-op.
        $reponse = $this->actingAs($this->scolarite)->post(route('admin.etudiants.bulkStatut'), [
            'ids' => [$dejaValide->id, $attente->id, $attente->id],
            'statut' => 'valide',
            'confirmation' => 'on',
        ]);

        $reponse->assertRedirect();
        $this->assertSame('valide', $dejaValide->fresh()->statut);
        $this->assertSame('valide', $attente->fresh()->statut);

        // Un seul historique par étudiant malgré le doublon.
        $this->assertSame(1, SituationEtudiant::where('etudiant_id', $attente->id)
            ->where('type', 'statut')->count());
    }

    public function test_bulk_partiellement_invalide_rien_en_base(): void
    {
        $etudiant = $this->dossier('BI', $this->faculteA->id);

        $this->actingAs($this->scolarite)->post(route('admin.etudiants.bulkStatut'), [
            'ids' => [$etudiant->id, 999999],
            'statut' => 'valide',
            'confirmation' => 'on',
        ])->assertSessionHasErrors('ids.1');

        $this->assertSame('en_attente', $etudiant->fresh()->statut);
    }

    public function test_mot_de_passe_hache_flash_unique_sans_pii_loggee(): void
    {
        $etudiant = $this->dossier('MP', $this->faculteA->id);

        $reponse = $this->actingAs($this->scolarite)
            ->post(route('admin.etudiants.reinitialiserMotDePasse', $etudiant), ['confirmation' => 'on']);

        $reponse->assertRedirect(route('admin.etudiants.show', $etudiant));
        $motDePasse = session('mot_de_passe_provisoire');
        $this->assertNotEmpty($motDePasse);
        $this->assertTrue(Hash::check($motDePasse, $etudiant->fresh()->password));

        // Flash consommé : absent de la page suivante.
        $page = $this->actingAs($this->scolarite)->get(route('admin.etudiants.show', $etudiant));
        $page->assertOk();
        $this->assertNull(session('mot_de_passe_provisoire'));

        // Audit sans PII : ni mot de passe ni email.
        $trace = IntegrationLog::where('operation', 'etudiant.mot_de_passe_reinitialise')->firstOrFail();
        $this->assertNull($trace->payload_hash);
        $this->assertSame('ETU-'.$etudiant->id, $trace->external_reference);
    }

    public function test_export_exclut_sensibles_et_trace(): void
    {
        $etudiant = $this->dossier('EX', $this->faculteA->id);

        $reponse = $this->actingAs($this->scolarite)->get(route('admin.etudiants.exportDossier', $etudiant));
        $reponse->assertOk();
        $contenu = $reponse->getContent();

        foreach (['cin', 'password', 'telephone', 'date_naissance'] as $champ) {
            $this->assertStringNotContainsString('"'.$champ.'"', $contenu);
        }
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $etudiant->id, 'action' => 'export_dossier',
        ]);
    }

    public function test_destroy_supprime_fichiers_et_lignes(): void
    {
        Storage::fake('local');
        $etudiant = $this->dossier('DEL', $this->faculteA->id);
        Storage::disk('local')->put('archives/doc1.pdf', 'contenu');
        DocumentAcademique::create([
            'etudiant_id' => $etudiant->id, 'chemin' => 'archives/doc1.pdf',
            'type_document' => 'piece_identite', 'nom_original' => 'cin.pdf',
            'mime_type' => 'application/pdf', 'taille' => 8,
            'hash_fichier' => hash('sha256', 'contenu'),
        ]);

        $this->actingAs($this->scolarite)->delete(route('admin.etudiants.destroy', $etudiant))
            ->assertRedirect(route('admin.etudiants.index'));

        $this->assertDatabaseMissing('etudiants', ['id' => $etudiant->id]);
        Storage::disk('local')->assertMissing('archives/doc1.pdf');
    }

    public function test_statistiques_et_fiche_inchangees(): void
    {
        $this->dossier('S1', $this->faculteA->id);

        $vue = $this->actingAs($this->scolarite)->get(route('admin.etudiants.index'))->viewData('stats');

        $this->assertSame(1, $vue['total']);
        $this->assertSame(1, $vue['en_attente']);
        $this->assertSame(1, $vue['sans_groupe']);
    }

    public function test_changer_idempotent_sans_redispatch(): void
    {
        $etudiant = $this->dossier('IDEM', $this->faculteA->id);
        $service = app(StudentStatusService::class);

        $service->changer($etudiant, 'valide', $this->scolarite->id);
        $service->changer($etudiant->fresh(), 'valide', $this->scolarite->id);

        // Une seule transition historisée malgré le double appel.
        $this->assertSame(1, SituationEtudiant::where('etudiant_id', $etudiant->id)
            ->where('type', 'statut')->count());
    }

    public function test_update_statut_throttle(): void
    {
        $etudiant = $this->dossier('TH', $this->faculteA->id);
        $route = route('admin.etudiants.updateStatut', $etudiant);

        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($this->scolarite)->patch($route, ['statut' => 'valide'])->assertRedirect();
        }

        $this->actingAs($this->scolarite)->patch($route, ['statut' => 'valide'])->assertTooManyRequests();
        $this->assertSame('valide', $etudiant->fresh()->statut);
    }

    public function test_destroy_trace_technique(): void
    {
        $etudiant = $this->dossier('TR', $this->faculteA->id);

        $this->actingAs($this->scolarite)->delete(route('admin.etudiants.destroy', $etudiant))
            ->assertRedirect();

        $trace = IntegrationLog::where('operation', 'etudiant.supprime')->firstOrFail();
        $this->assertSame('ETU-'.$etudiant->id, $trace->external_reference);
        $this->assertNull($trace->payload_hash);
    }

    // ── LOOP 8 Étape 5 : volumétrie représentative ──

    public function test_volumetrie_300_dossiers_index_borne(): void
    {
        for ($i = 1; $i <= 300; $i++) {
            $this->dossier('Vol'.$i, $i % 2 === 0 ? $this->faculteA->id : $this->faculteB->id);
        }

        $compte = 0;
        DB::listen(function () use (&$compte): void {
            $compte++;
        });
        $debut = microtime(true);

        $reponse = $this->actingAs($this->scolarite)->get(route('admin.etudiants.index'));

        $dureeMs = (int) ((microtime(true) - $debut) * 1000);
        DB::flushQueryLog();
        $reponse->assertOk();
        fwrite(STDERR, "\n[VOLUME-INDEX] 300 dossiers : {$dureeMs} ms, {$compte} requêtes (".config('database.default').").\n");

        $this->assertSame(300, $reponse->viewData('etudiants')->total());
        $this->assertLessThan(80, $compte);
        $this->assertLessThan(30000, $dureeMs);
    }

    public function test_diagnostic_deterministe_sans_mutation_statut(): void
    {
        $etudiant = $this->dossier('Det', $this->faculteA->id);
        $service = app(FicheEtudiantService::class);

        $premier = $service->anomalies($etudiant);
        $second = $service->anomalies($etudiant->fresh());

        // Déterminisme : deux évaluations identiques, raisons explicites.
        $this->assertSame($premier, $second);
        $this->assertNotEmpty($premier);
        foreach ($premier as $anomalie) {
            $this->assertArrayHasKey('message', $anomalie);
            $this->assertArrayHasKey('action', $anomalie);
        }

        // Le diagnostic ne mute jamais le statut officiel.
        $this->assertSame('en_attente', $etudiant->fresh()->statut);

        // Complétude bornée et justifiée.
        $completude = $service->completude($etudiant);
        $this->assertGreaterThanOrEqual(0, $completude['pourcentage']);
        $this->assertLessThanOrEqual(100, $completude['pourcentage']);
    }

    public function test_export_csv_limite_au_perimetre(): void
    {
        $this->dossier('ExpA', $this->faculteA->id);
        $this->dossier('ExpB', $this->faculteB->id);
        $agentB = User::creerCompteBackOffice([
            'name' => 'B', 'email' => 'bexp.reg@test.tn', 'password' => 'Secret123!',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');

        $reponse = $this->actingAs($agentB)->get(route('admin.donnees.export', ['etudiants', 'csv']));
        $reponse->assertOk();

        $contenu = method_exists($reponse, 'streamedContent') ? $reponse->streamedContent() : $reponse->getContent();
        $this->assertStringContainsString('ExpB', $contenu);
        $this->assertStringNotContainsString('ExpA', $contenu);
    }

    public function test_import_preview_signale_ligne_invalide_sans_ecrire(): void
    {
        $fichier = UploadedFile::fake()->createWithContent(
            'etudiants.csv',
            "nom;prenom;cin\nValide;V;91000001\nInvalideSansCin;I;\n",
            'text/csv'
        );

        $preview = $this->actingAs($this->scolarite)
            ->post('/admin/donnees/etudiants/import/preview', ['fichier' => $fichier])
            ->assertOk();

        // Rien écrit en preview.
        $this->assertSame(0, Etudiant::where('cin', '91000001')->count());

        $this->actingAs($this->scolarite)->post('/admin/donnees/etudiants/import/confirmer', [
            'token' => $preview->viewData('token'), 'mode' => 'creer',
        ])->assertOk();

        // Ligne valide importée, rapport d'erreurs pour l'autre.
        $this->assertSame(1, Etudiant::where('cin', '91000001')->count());
        $this->assertDatabaseHas('import_reports', ['type' => 'data.etudiants']);
    }

    // ── PHASE 4 : notifications bulk dédupliquées, queue opérationnelle ──

    public function test_bulk_notifie_une_fois_par_decision_memes_en_rejeu(): void
    {
        $e1 = $this->dossier('N1', $this->faculteA->id);
        $e2 = $this->dossier('N2', $this->faculteA->id);
        $payload = ['ids' => [$e1->id, $e2->id], 'statut' => 'valide', 'confirmation' => 'on'];

        $this->actingAs($this->scolarite)->post(route('admin.etudiants.bulkStatut'), $payload)
            ->assertRedirect();
        $this->assertSame(2, AppNotification::where('type', 'dossier.statut')->count());

        // Rejeu identique : no-op, zéro notification supplémentaire.
        $this->actingAs($this->scolarite)->post(route('admin.etudiants.bulkStatut'), $payload)
            ->assertRedirect();
        $this->assertSame(2, AppNotification::where('type', 'dossier.statut')->count());
    }

    // ── PHASE 3 : transitions serveur refusées ──

    public function test_statut_invalide_refuse_sans_mutation(): void
    {
        $etudiant = $this->dossier('TI', $this->faculteA->id);

        $this->actingAs($this->scolarite)->patch(route('admin.etudiants.updateStatut', $etudiant), [
            'statut' => 'archive',
        ])->assertSessionHasErrors('statut');

        $this->assertSame('en_attente', $etudiant->fresh()->statut);
        $this->assertSame(0, SituationEtudiant::where('etudiant_id', $etudiant->id)->count());
    }

    public function test_doublons_fiche_limites_au_perimetre(): void
    {
        $agentA = User::creerCompteBackOffice([
            'name' => 'A', 'email' => 'ag2.reg@test.tn', 'password' => 'Secret123!',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $etudiantA = $this->dossier('DupA', $this->faculteA->id, ['cin' => '88 000 111']);
        // Même CIN (variante espacée) dans l'autre faculté.
        $this->dossier('DupB', $this->faculteB->id, [
            'cin' => '88000111', 'email' => 'dupb.expose@test.tn',
        ]);
        // Homonyme intra-périmètre : reste visible.
        $this->dossier('DupA', $this->faculteA->id, [
            'cin' => '88 00 0111', 'email' => 'dupa.interne@test.tn',
        ]);

        $reponse = $this->actingAs($agentA)->get(route('admin.etudiants.show', $etudiantA));
        $reponse->assertOk();
        $reponse->assertDontSee('dupb.expose@test.tn', false);
        $reponse->assertSee('dupa.interne@test.tn', false);

        // Rôle global : inchangé, voit tout.
        $global = $this->actingAs($this->scolarite)->get(route('admin.etudiants.show', $etudiantA));
        $global->assertOk()->assertSee('dupb.expose@test.tn', false);
    }
}
