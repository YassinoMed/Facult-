<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\AppNotification;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Parametre;
use App\Models\User;
use App\Modules\Announcements\Models\Annonce;
use App\Modules\Attendance\Models\Presence;
use App\Modules\Attendance\Services\AssiduiteService;
use App\Modules\Calendar\Models\EvenementCalendrier;
use App\Modules\Calendar\Services\AgendaService;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\OnlineRequests\Models\RequestType;
use App\Modules\OnlineRequests\Services\OnlineRequestService;
use App\Modules\OnlineRequests\Services\SlaService;
use App\Services\NotificationService;
use App\Services\ParametreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Phases 5-6 — Services & administration : annonces, calendrier,
 * justifications, SLA, notifications batch, paramètres, utilisateurs,
 * recherche, audit, statistiques. Extensions testées contre les
 * modules existants, sans doublons.
 */
class ServicesTransversesTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.announcements.active' => true,
            'modules.calendar.active' => true,
            'modules.attendance.active' => true,
            'modules.online-requests.active' => true,
        ]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->superAdmin = User::creerCompteBackOffice([
            'name' => 'Super', 'email' => 'super.trans@universite.test', 'password' => 'Motdepasse@123',
        ], 'super_admin');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
    }

    public function test_annonces_desactivees_repondent_404(): void
    {
        config(['modules.announcements.active' => false]);

        $this->actingAs($this->superAdmin)->get(route('admin.annonces.index'))->assertNotFound();
    }

    public function test_publication_annonce_diffuse_aux_etudiants_cibles(): void
    {
        $cible = $this->creerEtudiant('EE100001', 'cible.trans@universite.test');
        $horsPerimetre = $this->creerEtudiant('EE100002', 'autre.trans@universite.test');

        $autreFaculte = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'T', 'active' => true]);
        $horsPerimetre->update(['faculte_id' => $autreFaculte->id]);

        $annonce = Annonce::create([
            'titre' => 'Rentrée', 'contenu' => 'Bienvenue.', 'type' => 'information',
            'statut' => 'draft', 'audience' => 'etudiants', 'faculte_id' => $this->faculte->id,
            'created_by' => $this->superAdmin->id,
        ]);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.annonces.publier', $annonce))
            ->assertSessionHasNoErrors();

        $this->assertSame('published', $annonce->fresh()->statut);
        $this->assertDatabaseHas('notifications', ['etudiant_id' => $cible->id, 'type' => 'annonce.publiee']);
        $this->assertDatabaseMissing('notifications', ['etudiant_id' => $horsPerimetre->id, 'type' => 'annonce.publiee']);
    }

    public function test_calendrier_agenda_agrege_evenements_et_sessions(): void
    {
        $etudiant = $this->creerEtudiant('EE100003', 'agenda.trans@universite.test');

        EvenementCalendrier::create([
            'annee_universitaire_id' => $this->annee->id, 'titre' => 'Rentrée',
            'type' => 'rentree', 'date_debut' => '2026-10-05', 'audience' => 'tous', 'statut' => 'publie',
            'created_by' => $this->superAdmin->id,
        ]);

        config(['modules.evaluation.active' => true]);
        SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id, 'code' => 'S1', 'nom' => 'Session S1',
            'type' => 'principale', 'date_debut' => '2026-10-10', 'date_fin' => '2026-10-20',
            'statut' => 'open', 'created_by' => $this->superAdmin->id,
        ]);

        $agenda = app(AgendaService::class)
            ->agendaPourEtudiant($etudiant, '2026-10-01', '2026-10-31');

        $this->assertCount(2, $agenda);
        $this->assertSame('2026-10-05', $agenda[0]['date']);
    }

    public function test_justification_workflow_complet(): void
    {
        $etudiant = $this->creerEtudiant('EE100004', 'justif.trans@universite.test');
        $presence = Presence::create([
            'etudiant_id' => $etudiant->id, 'date' => '2026-10-06', 'seance' => '08:00-10:00', 'statut' => 'absent',
        ]);

        $service = app(AssiduiteService::class);
        $justification = $service->deposerJustification($presence, $etudiant, 'Rendez-vous médical');

        $this->assertSame('pending', $justification->statut);

        // Double dépôt refusé.
        $this->expectException(HttpException::class);
        $service->deposerJustification($presence, $etudiant, 'Autre motif');
    }

    public function test_justification_acceptee_bascule_en_excuse(): void
    {
        $etudiant = $this->creerEtudiant('EE100005', 'justif2.trans@universite.test');
        $presence = Presence::create([
            'etudiant_id' => $etudiant->id, 'date' => '2026-10-06', 'seance' => '08:00-10:00', 'statut' => 'absent',
        ]);

        $service = app(AssiduiteService::class);
        $justification = $service->deposerJustification($presence, $etudiant, 'Motif');
        $service->validerJustification($justification, $this->superAdmin->id, true);

        $this->assertSame('excuse', $presence->fresh()->statut);
        $this->assertDatabaseHas('notifications', ['etudiant_id' => $etudiant->id, 'type' => 'absence.justifiee']);

        $stats = $service->statistiques($etudiant);
        $this->assertSame(0, $stats['injustifiees']);
    }

    public function test_sla_pause_attente_etudiant_et_escalade(): void
    {
        $etudiant = $this->creerEtudiant('EE100006', 'sla.trans@universite.test');
        $type = RequestType::create([
            'code' => 'ATT', 'name' => 'Attestation', 'active' => true, 'processing_days' => 1,
        ]);

        $service = app(OnlineRequestService::class);
        $demande = $service->creer($etudiant, [
            'request_type_id' => $type->id, 'objet' => 'Attestation', 'form_data' => [],
        ]);
        $service->soumettre($demande);
        $demande->forceFill(['submitted_at' => now()->subDays(5)])->save();

        $sla = app(SlaService::class);
        $this->assertTrue($sla->estEnRetardImputable($demande->fresh()));

        // En attente de pièce : pause SLA, pas d'escalade imputable.
        $demande->forceFill(['status' => 'waiting_student'])->save();
        $this->assertTrue($sla->estEnPause($demande->fresh()));
        $this->assertFalse($sla->estEnRetardImputable($demande->fresh()));

        // Escalade : l'assigné est notifié.
        $demande->forceFill(['status' => 'in_review', 'assigned_to' => $this->superAdmin->id])->save();
        $resultat = $sla->verifierEtEscalader();

        $this->assertSame(1, $resultat['escaladees']);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->superAdmin->id, 'type' => 'demande.sla']);
    }

    public function test_notifications_batch_priorite_et_purge(): void
    {
        $e1 = $this->creerEtudiant('EE100007', 'n1.trans@universite.test');
        $e2 = $this->creerEtudiant('EE100008', 'n2.trans@universite.test');

        $total = app(NotificationService::class)->notifierPlusieursEtudiants(
            [$e1->id, $e2->id], 'annonce.publiee', 'Titre', 'Message', null, [], 2
        );

        $this->assertSame(2, $total);
        $this->assertSame(2, AppNotification::where('priorite', 2)->count());

        AppNotification::where('etudiant_id', $e1->id)->update(['read_at' => now()->subDays(200)]);
        $this->assertSame(1, app(NotificationService::class)->purgerLues(180));
    }

    public function test_parametres_reserves_super_admin_et_audites(): void
    {
        Parametre::create(['cle' => 'test.cle', 'valeur' => '1', 'type' => 'entier', 'categorie' => 'interface']);

        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'agent.trans@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        $this->actingAs($agent)->get(route('admin.parametres.index'))->assertForbidden();

        $this->actingAs($this->superAdmin)
            ->patch(route('admin.parametres.update', Parametre::where('cle', 'test.cle')->first()), ['valeur' => '42'])
            ->assertSessionHasNoErrors();

        $this->assertSame(42, app(ParametreService::class)->get('test.cle'));
    }

    public function test_administrateurs_gardes_anti_verrouillage(): void
    {
        $autre = User::creerCompteBackOffice([
            'name' => 'Autre', 'email' => 'autre.trans@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        // Rôle propre : interdit.
        $this->actingAs($this->superAdmin)
            ->put(route('admin.administrateurs.update', $this->superAdmin), [
                'name' => 'Super', 'role' => 'consultation',
            ])->assertForbidden();

        // Dernier super-admin : suppression interdite.
        $this->actingAs($this->superAdmin)
            ->delete(route('admin.administrateurs.destroy', $this->superAdmin))
            ->assertForbidden();

        // Compte normal : suppression OK.
        $this->actingAs($this->superAdmin)
            ->delete(route('admin.administrateurs.destroy', $autre))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('users', ['id' => $autre->id]);

        // Reset mot de passe.
        $cible = User::creerCompteBackOffice([
            'name' => 'Cible', 'email' => 'cible.trans@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        $this->actingAs($this->superAdmin)
            ->post(route('admin.administrateurs.reinitialiser', $cible), [
                'password' => 'Nouveau@Motdepasse123', 'password_confirmation' => 'Nouveau@Motdepasse123',
            ])->assertSessionHasNoErrors();
    }

    public function test_recherche_cloisonnee_et_sections(): void
    {
        $this->creerEtudiant('CIN00001', 'rech.trans@universite.test');

        // Super-admin : sections Utilisateurs + Demandes accessibles.
        $reponse = $this->actingAs($this->superAdmin)->get(route('admin.recherche', ['q' => 'trans']));
        $reponse->assertOk();
        $this->assertArrayHasKey('Utilisateurs', $reponse->viewData('sections'));

        // Admin faculté : aucun étudiant hors périmètre.
        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.trans@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculte->id,
        ], 'admin_faculte');

        $autreFaculte = Faculte::create(['nom' => 'FMD', 'code' => 'FMD', 'description' => 'T', 'active' => true]);
        $horsPerimetre = $this->creerEtudiant('CIN00002', 'hors.trans@universite.test');
        $horsPerimetre->update(['faculte_id' => $autreFaculte->id]);

        $reponse = $this->actingAs($adminFaculte)->get(route('admin.recherche', ['q' => 'CIN00002']));
        $titres = collect($reponse->viewData('sections')['Étudiants']['resultats'] ?? [])->pluck('titre');
        $this->assertNotContains('Benali Sara', $titres->all());
        $this->assertArrayNotHasKey('Utilisateurs', $reponse->viewData('sections'));
    }

    public function test_audit_export_et_statistiques_scopes(): void
    {
        $this->actingAs($this->superAdmin)->get(route('admin.audit.export'))->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->actingAs($this->superAdmin)->get(route('admin.statistiques.index'))->assertOk();
        $this->actingAs($this->superAdmin)->get(route('admin.statistiques.export'))->assertOk();

        $agent = User::creerCompteBackOffice([
            'name' => 'Agent2', 'email' => 'agent2.trans@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        // Permission accordée en lecture, export refusé (moindre privilège).
        $this->actingAs($agent)->get(route('admin.statistiques.index'))->assertOk();
        $this->actingAs($agent)->get(route('admin.statistiques.export'))->assertForbidden();
    }

    private function creerEtudiant(string $cin, string $email): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara', 'email' => $email,
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => '12 rue des Tests',
            'ville' => 'Casablanca', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');

        return $etudiant;
    }
}
