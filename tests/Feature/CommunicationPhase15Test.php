<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\AppNotification;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\NotificationPreference;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Schedule\Models\Group;
use App\Services\AudienceResolver;
use App\Services\CommunicationDispatcher;
use App\Services\PreferenceNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 15 — Communication centralisée (§15.1-15.4) : audience
 * scopée, gabarits versionnés, préférences, dispatcher dédupliqué.
 * Textes contractuels préservés, sans duplication.
 */
class CommunicationPhase15Test extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculteA;

    private Faculte $faculteB;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculteA = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.ph15@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    private function creerEtudiant(string $cin, int $faculteId): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => strtolower($cin).'@ph15.test', 'date_naissance' => '2004-01-01',
            'sexe' => 'F', 'faculte_id' => $faculteId, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');

        return $etudiant->refresh();
    }

    public function test_audience_scope_expediteur(): void
    {
        $cibleA = $this->creerEtudiant('BKPH1501', $this->faculteA->id);
        $this->creerEtudiant('BKPH1502', $this->faculteB->id);

        $resolveur = app(AudienceResolver::class);

        // Globale : tout le monde.
        $this->assertSame(2, $resolveur->resoudre([], null)['total']);

        // Expéditeur restreint : jamais hors périmètre, même demandé.
        $adminA = User::creerCompteBackOffice([
            'name' => 'Fac A', 'email' => 'faca.ph15@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $resolution = $resolveur->resoudre(['faculte_id' => $this->faculteB->id], $adminA);
        $this->assertSame(0, $resolution['total']);

        $resolution = $resolveur->resoudre([], $adminA);
        $this->assertSame(1, $resolution['total']);
        $this->assertSame(['Sara Benali'], $resolution['echantillon']);

        // Exclusion SAUF + doublons retirés.
        $resolution = $resolveur->resoudre([
            'etudiant_ids' => [$cibleA->id, $cibleA->id],
            'exclude_etudiant_ids' => [$cibleA->id],
        ], null);
        $this->assertSame(0, $resolution['total']);

        // Aperçu : effectif + échantillon, sans PII en masse.
        $apercu = $resolveur->previsualiser([], null);
        $this->assertSame(2, $apercu['total']);
        $this->assertCount(2, $apercu['echantillon']);
    }

    public function test_audience_epreuve_candidats(): void
    {
        $etudiant = $this->creerEtudiant('BKPH1503', $this->faculteA->id);

        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $session = SessionExamen::create([
            'annee_universitaire_id' => $annee->id, 'code' => 'S1', 'nom' => 'Principale',
            'type' => 'principale', 'date_debut' => '2027-01-05', 'date_fin' => '2027-01-20',
            'statut' => 'open', 'created_by' => $this->admin->id,
        ]);
        $examen = Examen::create([
            'session_id' => $session->id, 'annee_universitaire_id' => $annee->id,
            'libelle' => 'Examen', 'type' => 'examen', 'statut' => 'scheduled',
        ]);
        $matiere = Matiere::create([
            'code' => 'INF101', 'nom' => 'Info', 'niveau' => 'L1', 'semestre' => 1, 'credits' => 4,
        ]);
        $groupe = Group::create([
            'code' => 'G1', 'nom' => 'Groupe 1', 'faculte_id' => $this->faculteA->id,
            'niveau' => 'L1', 'actif' => true,
        ]);
        $epreuve = Epreuve::create([
            'examen_id' => $examen->id, 'session_id' => $session->id,
            'annee_universitaire_id' => $annee->id, 'matiere_id' => $matiere->id,
            'groupe_id' => $groupe->id, 'date' => '2027-01-06',
            'heure_debut' => '08:00', 'heure_fin' => '10:00', 'statut' => 'scheduled',
        ]);
        Candidat::create(['epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'present']);

        $resolution = app(AudienceResolver::class)->resoudre(['epreuve_id' => $epreuve->id], null);

        $this->assertSame(1, $resolution['total']);
    }

    public function test_gabarits_rendu_et_version(): void
    {
        $service = app(PreferenceNotificationService::class);

        // Variables échappées, manquante → vide.
        $rendu = $service->rendre('inscription.valide', ['annee' => '2026-2027', 'statut' => 'valide']);
        $this->assertSame('Inscription validée', $rendu['titre']);
        $this->assertStringContainsString('2026-2027', $rendu['message']);

        $rendu = $service->rendre('inscription.valide', ['annee' => '<b>X</b>']);
        $this->assertStringNotContainsString('<b>', $rendu['message']);
        $this->assertStringContainsString('&lt;b&gt;', $rendu['message']);

        $this->assertNull($service->rendre('code.inexistant'));

        // CRUD : modification de texte = nouvelle version.
        $gabarit = NotificationTemplate::where('code', 'inscription.valide')->firstOrFail();
        $this->actingAs($this->admin)->patch(route('admin.notifications.templates.update', $gabarit), [
            'titre' => 'Inscription validée', 'message' => 'Nouveau texte {annee}.', 'actif' => '1',
        ])->assertRedirect();
        $this->assertSame(2, $gabarit->fresh()->version);

        // Sans changement de texte : version conservée.
        $this->actingAs($this->admin)->patch(route('admin.notifications.templates.update', $gabarit->fresh()), [
            'titre' => 'Inscription validée', 'message' => 'Nouveau texte {annee}.',
        ])->assertRedirect();
        $this->assertSame(2, $gabarit->fresh()->version);
    }

    public function test_preferences_optout_sauf_obligatoire(): void
    {
        $etudiant = $this->creerEtudiant('BKPH1504', $this->faculteA->id);
        $service = app(PreferenceNotificationService::class);

        // Défaut : tout actif.
        $this->assertTrue($service->notifiable($etudiant->id, null, 'demandes'));

        $service->definir($etudiant->id, null, 'demandes', false);
        $this->assertFalse($service->notifiable($etudiant->id, null, 'demandes'));

        // Obligatoire : opt-out ignoré.
        $service->definir($etudiant->id, null, 'resultats', false);
        $this->assertTrue($service->notifiable($etudiant->id, null, 'resultats'));

        try {
            $service->definir($etudiant->id, null, 'canal_inexistant', false);
            $this->fail('Catégorie inconnue aurait dû être refusée.');
        } catch (\InvalidArgumentException) {
            $this->assertTrue(true);
        }
    }

    public function test_dispatcher_dedup_et_regle_inactive(): void
    {
        $etudiant = $this->creerEtudiant('BKPH1505', $this->faculteA->id);
        $dispatcher = app(CommunicationDispatcher::class);

        $premiere = $dispatcher->dispatcher('inscription.valide', $etudiant,
            ['annee' => '2026-2027', 'statut' => 'valide'], route('etudiant.dashboard'));
        $this->assertNotNull($premiere);
        $this->assertSame('Inscription validée', $premiere->titre);

        // Rejeu < 60 min, même lien, non lue : dédupliqué.
        $this->assertNull($dispatcher->dispatcher('inscription.valide', $etudiant,
            ['annee' => '2026-2027', 'statut' => 'valide'], route('etudiant.dashboard')));
        $this->assertSame(1, AppNotification::where('type', 'inscription.valide')->count());

        // Règle désactivée : aucun envoi (clés à points : fusion
        // du tableau parent, pas de notation pointée).
        $evenements = config('communication.evenements', []);
        $evenements['inscription.valide']['actif'] = false;
        config(['communication.evenements' => $evenements]);
        $this->assertNull($dispatcher->dispatcher('inscription.valide', $etudiant,
            ['annee' => '2026-2027', 'statut' => 'valide'], route('etudiant.dashboard', [], false).'?bis=1'));
    }

    public function test_dispatcher_masse_filtre_optout(): void
    {
        $cible = $this->creerEtudiant('BKPH1506', $this->faculteA->id);
        $exclu = $this->creerEtudiant('BKPH1507', $this->faculteA->id);

        NotificationPreference::create([
            'etudiant_id' => $exclu->id, 'categorie' => 'inscriptions', 'actif' => false,
        ]);

        $nombre = app(CommunicationDispatcher::class)->dispatcherPlusieurs(
            'inscription.valide', [$cible->id, $exclu->id, $cible->id],
            ['annee' => '2026-2027', 'statut' => 'valide'], route('etudiant.dashboard'),
        );

        // Doublon retiré + opt-out respecté : 1 seul envoi.
        $this->assertSame(1, $nombre);
        $this->assertTrue(AppNotification::where('etudiant_id', $cible->id)->exists());
        $this->assertFalse(AppNotification::where('etudiant_id', $exclu->id)->exists());
    }
}
