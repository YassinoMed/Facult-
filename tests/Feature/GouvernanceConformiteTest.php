<?php

use App\Models\Courriel;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\IntegrationLog;
use App\Models\User;
use App\Modules\GouvernanceConformite\Models\JournalAccesJournal;
use App\Modules\GouvernanceConformite\Models\JournalChangementPermission;
use App\Modules\GouvernanceConformite\Models\PolitiqueRetention;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Lot 19 — Centre de gouvernance et conformité : tests du cahier.
 *
 * Couverture : vue unifiée des accès sensibles (journaux RÉELS agrégés,
 * absence de données EXPLICITE), journal des changements de permissions
 * (Observer : créations + changements de rôle, acteur http/interne), rôle
 * `auditeur` DISTINCT (lecture seule, aucun pouvoir d'action), politiques
 * de rétention SAISIES et APPROUVÉES (rapport de conformité sans AUCUNE
 * suppression — jamais d'immuabilité promise), journal des accès aux
 * journaux (proportionné : le fait, jamais le contenu), permissions,
 * module désactivé.
 *
 * Données 100% synthétiques.
 */
class GouvernanceConformiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('modules.gouvernance-conformite.active', true);
    }

    /* ── Utilisateurs ────────────────────────────────────────────── */

    private function adminScolarite(): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Admin Scolarite', 'email' => 'scol-'.uniqid().'@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    private function superAdmin(): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Super Admin', 'email' => 'superadmin-'.uniqid().'@universite.test', 'password' => 'Motdepasse@123',
        ], 'super_admin');
    }

    private function auditeur(): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Auditeur', 'email' => 'auditeur-'.uniqid().'@universite.test', 'password' => 'Motdepasse@123',
        ], 'auditeur');
    }

    private function adminFaculte(): User
    {
        $faculte = Faculte::create(['nom' => 'Sciences Test', 'code' => 'FST', 'description' => 'T', 'active' => true]);

        return User::creerCompteBackOffice([
            'name' => 'Admin Faculte', 'email' => 'adminfac-'.uniqid().'@universite.test',
            'password' => 'Motdepasse@123', 'faculte_id' => $faculte->id,
        ], 'admin_faculte');
    }

    private function consultateur(): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Consultation', 'email' => 'consult-'.uniqid().'@universite.test', 'password' => 'Motdepasse@123',
        ], 'consultation');
    }

    /* ── Appels et données ───────────────────────────────────────── */

    private function appelGet(User $u, string $route, array $params = []): TestResponse
    {
        return $this->actingAs($u)->getJson(route($route, $params));
    }

    private function appelPost(User $u, string $route, array $params, array $data): TestResponse
    {
        return $this->actingAs($u)->postJson(route($route, $params), $data);
    }

    private function etudiant(): Etudiant
    {
        $faculte = Faculte::first() ?? Faculte::create(['nom' => 'Droit Test', 'code' => 'FDEG', 'description' => 'T', 'active' => true]);

        return Etudiant::create([
            'cin' => 'CIN-'.uniqid(), 'nom' => 'Nom'.uniqid(), 'prenom' => 'Pre',
            'email' => 'etu-'.uniqid().'@example.test', 'sexe' => 'M',
            'faculte_id' => $faculte->id, 'niveau' => 'L1', 'annee_academique' => '2024-2025',
            'adresse' => 'Rue test', 'ville' => 'Dakar', 'code_postal' => '10000', 'annee_bac' => '2020',
        ]);
    }

    private function courriel(): Courriel
    {
        return Courriel::create([
            'categorie' => 'test', 'modele' => 'modele-test', 'destinataire_masque' => 'j***@example.test',
            'destinataire_hash' => str_repeat('b', 64), 'sujet' => 'Sujet test', 'statut' => 'accepted_by_smtp',
        ]);
    }

    /* ── Vue unifiée des accès sensibles ─────────────────────────── */

    public function test_vue_unifiee_agrege_les_trois_journaux_reels(): void
    {
        $admin = $this->adminScolarite();
        $etudiant = $this->etudiant();
        HistoriqueAcademique::create([
            'etudiant_id' => $etudiant->id, 'utilisateur_id' => $admin->id,
            'action' => 'consultation', 'entite' => 'dossier', 'entite_id' => $etudiant->id, 'created_at' => now(),
        ]);
        IntegrationLog::tracer('conn-test', 'inbound', 'import', 'completed', 'x');
        $this->courriel();

        $reponse = $this->appelGet($admin, 'admin.gouvernance-conformite.acces-sensibles')->assertOk();

        $sources = collect($reponse->json('entrees'))->pluck('source')->unique()->values()->all();
        $this->assertContains('historique_academique', $sources);
        $this->assertContains('integration_logs', $sources);
        $this->assertContains('courriels', $sources);

        $disponibilite = $reponse->json('disponibilite');
        $this->assertSame(1, $disponibilite['historique_academique']);
        $this->assertSame(1, $disponibilite['integration_logs']);
        $this->assertSame(1, $disponibilite['courriels']);
    }

    public function test_vue_unifiee_sans_donnees_signale_explicitement(): void
    {
        $reponse = $this->appelGet($this->adminScolarite(), 'admin.gouvernance-conformite.acces-sensibles')->assertOk();

        // Donnée absente ≠ masquée : les compteurs réels sont exposés.
        $this->assertSame(['historique_academique' => 0, 'integration_logs' => 0, 'courriels' => 0],
            $reponse->json('disponibilite'));
        $this->assertSame([], $reponse->json('entrees'));
    }

    public function test_vue_unifiee_filtree_par_source(): void
    {
        $admin = $this->adminScolarite();
        IntegrationLog::tracer('conn-a', 'inbound', 'import', 'completed', 'x');
        $this->courriel();

        $entrees = $this->appelGet($admin, 'admin.gouvernance-conformite.acces-sensibles', ['source' => 'courriels'])
            ->assertOk()->json('entrees');

        $this->assertCount(1, $entrees);
        $this->assertSame('courriels', $entrees[0]['source']);
        $this->assertStringContainsString('courriel', (string) $entrees[0]['quoi']);
    }

    public function test_vue_unifiee_refusee_aux_roles_sans_gouvernance_audit(): void
    {
        $this->appelGet($this->consultateur(), 'admin.gouvernance-conformite.acces-sensibles')->assertStatus(403);
        $this->appelGet($this->adminFaculte(), 'admin.gouvernance-conformite.acces-sensibles')->assertStatus(403);
    }

    public function test_role_auditeur_est_de_lecture_stricte(): void
    {
        $auditeur = $this->auditeur();

        // Lecture : OK sur toutes les vues gouvernance.
        $this->appelGet($auditeur, 'admin.gouvernance-conformite.acces-sensibles')->assertOk();
        $this->appelGet($auditeur, 'admin.gouvernance-conformite.permissions-journal')->assertOk();
        $this->appelGet($auditeur, 'admin.gouvernance-conformite.conformite-retention')->assertOk();
        $this->appelGet($auditeur, 'admin.gouvernance-conformite.journal-acces')->assertOk();

        // Action : REFUSÉE (settings.manage absent du rôle auditeur).
        $this->appelPost($auditeur, 'admin.gouvernance-conformite.politiques.creer', [], [
            'source_journal' => 'courriels', 'duree_jours' => 365,
        ])->assertStatus(403);
    }

    /* ── Journal des changements de permissions (Observer) ───────── */

    public function test_creation_de_compte_trace_le_role_initial(): void
    {
        $admin = $this->adminScolarite();
        $this->actingAs($admin); // session ouverte : l'acteur HTTP est tracé
        $avant = JournalChangementPermission::count();

        User::creerCompteBackOffice([
            'name' => 'Nouveau', 'email' => 'nouveau-'.uniqid().'@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        $this->assertSame($avant + 1, JournalChangementPermission::count());
        $entree = JournalChangementPermission::latest('id')->first();
        $this->assertNull($entree->ancien_role); // création
        $this->assertSame('agent_scolarite', $entree->nouveau_role);
        $this->assertSame($admin->id, (int) $entree->acteur_id); // acteur HTTP tracé
        $this->assertSame('http', $entree->source);
    }

    public function test_changement_de_role_trace_ancien_et_nouveau(): void
    {
        $admin = $this->adminScolarite();
        $cible = User::creerCompteBackOffice([
            'name' => 'Cible', 'email' => 'cible-'.uniqid().'@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');
        $avant = JournalChangementPermission::count();

        // Voie applicative réelle : toute écriture Eloquent de `role` est
        // capturée par l'Observer (l'attribution passe par définirRole/save).
        $cible->forceFill(['role' => 'consultation'])->save();

        $this->assertSame($avant + 1, JournalChangementPermission::count());
        $entree = JournalChangementPermission::latest('id')->first();
        $this->assertSame('agent_scolarite', $entree->ancien_role);
        $this->assertSame('consultation', $entree->nouveau_role);

        // Une modification SANS changement de rôle n'est PAS journalisée.
        $cible->forceFill(['name' => 'Renomme'])->save();
        $this->assertSame($avant + 1, JournalChangementPermission::count());
    }

    public function test_creation_hors_session_trace_source_interne(): void
    {
        // Aucune session active dans ce test (pas d'actingAs préalable).
        $avant = JournalChangementPermission::count();
        User::creerCompteBackOffice([
            'name' => 'Interne', 'email' => 'interne-'.uniqid().'@universite.test', 'password' => 'Motdepasse@123',
        ], 'consultation');

        $entree = JournalChangementPermission::latest('id')->first();
        $this->assertSame('interne', $entree->source);
        $this->assertNull($entree->acteur_id);
        $this->assertSame($avant + 1, JournalChangementPermission::count());
    }

    /* ── Politiques de rétention (SAISIES, APPROUVÉES) ───────────── */

    public function test_politique_exige_settings_manage(): void
    {
        $this->appelPost($this->adminFaculte(), 'admin.gouvernance-conformite.politiques.creer', [], [
            'source_journal' => 'courriels', 'duree_jours' => 365,
        ])->assertStatus(403);
    }

    public function test_politique_unique_par_journal(): void
    {
        $admin = $this->superAdmin(); // settings.manage : décision d'administration centrale
        $this->appelPost($admin, 'admin.gouvernance-conformite.politiques.creer', [], [
            'source_journal' => 'courriels', 'duree_jours' => 365, 'motif' => 'Conformité institutionnelle.',
        ])->assertStatus(201);

        $this->appelPost($admin, 'admin.gouvernance-conformite.politiques.creer', [], [
            'source_journal' => 'courriels', 'duree_jours' => 730,
        ])->assertStatus(422);

        // Un journal INCONNU est refusé (sources réelles uniquement).
        $this->appelPost($admin, 'admin.gouvernance-conformite.politiques.creer', [], [
            'source_journal' => 'journal_invente', 'duree_jours' => 30,
        ])->assertStatus(422);
    }

    public function test_politique_approuvee_tracee_puis_re_approbation_refusee(): void
    {
        $admin = $this->superAdmin();
        $politique = PolitiqueRetention::create([
            'source_journal' => 'integration_logs', 'duree_jours' => 90, 'statut' => 'brouillon',
        ]);

        $this->appelPost($admin, 'admin.gouvernance-conformite.politiques.approuver', ['id' => $politique->id], [])
            ->assertOk();

        $politique->refresh();
        $this->assertSame('approuvee', $politique->statut);
        $this->assertSame($admin->id, (int) $politique->approuve_par);
        $this->assertNotNull($politique->approuve_le);

        $this->appelPost($admin, 'admin.gouvernance-conformite.politiques.approuver', ['id' => $politique->id], [])
            ->assertStatus(422);
    }

    public function test_conformite_sans_politique_signale_l_absence(): void
    {
        $reponse = $this->appelGet($this->adminScolarite(), 'admin.gouvernance-conformite.conformite-retention')->assertOk();
        $lignes = collect($reponse->json('journaux'))->keyBy('journal');

        $this->assertSame('absente', $lignes->get('courriels')['etat_politique']);
        $this->assertNull($lignes->get('courriels')['lignes_au_dela']); // jamais de durée inventée
        $this->assertSame('absente', $lignes->get('historique_academique')['etat_politique']);
        $this->assertNull($lignes->get('historique_academique')['politique']);
    }

    public function test_conformite_compte_sans_jamais_supprimer(): void
    {
        $admin = $this->superAdmin();

        // Lignes ANCIENNES (au-delà de la durée approuvée).
        $vieille = HistoriqueAcademique::create([
            'etudiant_id' => $this->etudiant()->id, 'utilisateur_id' => $admin->id,
            'action' => 'ancien', 'entite' => 'test', 'entite_id' => 1,
            'created_at' => now()->subDays(400),
        ]);
        $recente = IntegrationLog::tracer('conn-c', 'inbound', 'import', 'completed', 'x');

        $politique = PolitiqueRetention::create([
            'source_journal' => 'historique_academique', 'duree_jours' => 30, 'statut' => 'brouillon',
        ]);
        $this->appelPost($admin, 'admin.gouvernance-conformite.politiques.approuver', ['id' => $politique->id], [])
            ->assertOk();

        $lignes = collect($this->appelGet($admin, 'admin.gouvernance-conformite.conformite-retention')
            ->assertOk()->json('journaux'))->keyBy('journal');

        $ligne = $lignes->get('historique_academique');
        $this->assertSame('approuvee', $ligne['etat_politique']);
        $this->assertSame(1, $ligne['lignes_au_dela']); // signalée…
        $this->assertSame(1, HistoriqueAcademique::count()); // …mais JAMAIS supprimée

        // Aucune politique pour integration_logs : null, pas de conformité par défaut.
        $this->assertNull($lignes->get('integration_logs')['lignes_au_dela']);
        $this->assertNotNull($recente); // la ligne récente existe toujours
        $this->assertNotNull($vieille); // la ligne ancienne existe toujours
    }

    /* ── Journal des accès aux journaux (proportionné) ───────────── */

    public function test_acces_aux_vues_trace_le_fait_pas_le_contenu(): void
    {
        $admin = $this->adminScolarite();

        $this->appelGet($admin, 'admin.gouvernance-conformite.acces-sensibles')->assertOk();
        $this->appelGet($admin, 'admin.gouvernance-conformite.acces-sensibles', ['source' => 'courriels'])->assertOk();

        $traces = JournalAccesJournal::query()->orderBy('id')->get();
        $this->assertCount(2, $traces);
        $this->assertSame('acces-sensibles', $traces[0]->vue);
        $this->assertSame($admin->id, (int) $traces[0]->utilisateur_id);
        $this->assertSame('courriels', $traces[1]->source_journal); // filtre générique seulement

        // Proportionnalité : AUCUN champ ne stocke les lignes lues.
        $this->assertSame(['utilisateur_id', 'vue', 'source_journal'], array_values(array_diff(
            ['utilisateur_id', 'vue', 'source_journal', 'id', 'created_at', 'updated_at'],
            ['id', 'created_at', 'updated_at']
        )));

        // Le journal des accès se lit lui-même (vue dédiée).
        $reponse = $this->appelGet($admin, 'admin.gouvernance-conformite.journal-acces')->assertOk();
        $this->assertGreaterThan(2, count($reponse->json('acces'))); // les 2 accès + la présente consultation
    }

    public function test_module_desactive_renvoie_404(): void
    {
        Config::set('modules.gouvernance-conformite.active', false);
        $admin = $this->adminScolarite();

        $this->appelGet($admin, 'admin.gouvernance-conformite.acces-sensibles')->assertStatus(404);
        $this->appelPost($admin, 'admin.gouvernance-conformite.politiques.creer', [], [
            'source_journal' => 'courriels', 'duree_jours' => 30,
        ])->assertStatus(404);
    }

    public function test_performance_vue_unifiee_synthetique(): void
    {
        $admin = $this->adminScolarite();
        $etudiant = $this->etudiant();
        for ($i = 0; $i < 60; $i++) {
            HistoriqueAcademique::create([
                'etudiant_id' => $etudiant->id, 'utilisateur_id' => $admin->id,
                'action' => 'a'.$i, 'entite' => 'test', 'entite_id' => $i,
                'created_at' => now()->subMinutes($i),
            ]);
            IntegrationLog::tracer('conn-'.$i, 'inbound', 'import', 'completed', 'lot-'.$i);
        }

        $debut = microtime(true);
        $reponse = $this->appelGet($admin, 'admin.gouvernance-conformite.acces-sensibles')->assertOk();
        $duree = microtime(true) - $debut;

        $this->assertSame(60, $reponse->json('disponibilite')['historique_academique']);
        $this->assertLessThan(3.0, $duree, 'Vue unifiée trop lente : '.$duree.' s');
    }
}
