<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\AppNotification;
use App\Models\DossierCorrection;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\MensReference;
use App\Models\RegistrationPeriod;
use App\Models\SituationEtudiant;
use App\Models\User;
use App\Services\StudentStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * LOOPs 10-13, 18, 22 : notifications dossier (statut + rejet pièce),
 * récépissé PDF, checklist documentaire, ancienneté file admin, métriques
 * d'expérience, trappe mot de passe perdu, anti-doublon CIN normalisée.
 */
class ExperienceInscriptionLoopTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private User $scolarite;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.student-dossiers.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.xp@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    private function creerEtudiant(string $cin = 'CIN001'): Etudiant
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

    // LOOP 12 — changement de statut notifie l'étudiant (fail-soft).
    public function test_changement_statut_notifie_etudiant(): void
    {
        $etudiant = $this->creerEtudiant();
        $this->assertSame(0, AppNotification::where('etudiant_id', $etudiant->id)->count());

        app(StudentStatusService::class)->changer($etudiant, 'valide', $this->scolarite->id, 'Dossier conforme.');

        $notification = AppNotification::where('etudiant_id', $etudiant->id)
            ->where('type', 'dossier.statut')->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('Validé', $notification->message);
        $this->assertStringContainsString('Dossier conforme.', $notification->message);
        $this->assertSame(1, $notification->priorite); // validation ≠ rejet
    }

    public function test_rejet_statut_priorite_haute(): void
    {
        $etudiant = $this->creerEtudiant();
        $etudiant->definirStatut('valide');

        app(StudentStatusService::class)->changer($etudiant, 'rejete', $this->scolarite->id, 'Pièces frauduleuses.');

        $notification = AppNotification::where('etudiant_id', $etudiant->id)
            ->where('type', 'dossier.statut')->first();

        $this->assertNotNull($notification);
        $this->assertSame(2, $notification->priorite);
        $this->assertStringContainsString('Rejeté', $notification->message);
    }

    // LOOP 13 — récépissé PDF : session-scopé, preuve de soumission.
    public function test_recepisse_pdf_session_scope(): void
    {
        $etudiant = $this->creerEtudiant('CIN-REC');

        // Invité → redirection connexion.
        $this->get(route('etudiant.recepisse'))->assertRedirect(route('etudiant.connexion.show'));

        $reponse = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.recepisse'));

        $reponse->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="recepisse-inscription-'.$etudiant->num_inscription.'.pdf"');

        $contenu = $reponse->getContent();
        $this->assertStringContainsString('%PDF', $contenu);
    }

    // LOOP 4 — checklist documentaire rendue quand la matrice impose des pièces.
    public function test_checklist_dossier_etudiant(): void
    {
        config(['inscriptions.documents_requis' => ['cin', 'photo']]);
        $etudiant = $this->creerEtudiant('CIN-CHK');

        // Matrice non vide, aucune pièce déposée → 2 manquantes.
        $reponse = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.dossier.show'))
            ->assertOk()
            ->assertSee('Pièces obligatoires')
            ->assertSee('cin')
            ->assertSee('photo')
            ->assertSee('Non fournie');

        // Matrice vide → section absente (rien imposé, jamais d'invention).
        config(['inscriptions.documents_requis' => []]);
        config(['inscriptions.documents_requis_par_type' => []]);
        $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.dossier.show'))
            ->assertOk()
            ->assertDontSee('Pièces obligatoires');
    }

    // LOOP 18 — filtre ancienneté de la file.
    public function test_filtre_anciennete_file_etudiants(): void
    {
        $ancien = $this->creerEtudiant('CIN-OLD');
        DB::table('etudiants')->where('id', $ancien->id)
            ->update(['created_at' => now()->subDays(30)]);

        $recent = $this->creerEtudiant('CIN-NEW');
        DB::table('etudiants')->where('id', $recent->id)
            ->update(['created_at' => now()->subDays(1)]);

        $contenu = (string) $this->actingAs($this->scolarite)
            ->get(route('admin.etudiants.index', ['depose_depuis' => 7]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($ancien->fresh()->num_inscription, $contenu);
        $this->assertStringNotContainsString($recent->fresh()->num_inscription, $contenu);
    }

    // LOOP 22 — métriques d'expérience : délai + taux de correction (formules testées).
    public function test_metriques_experience_statistiques(): void
    {
        $etudiant = $this->creerEtudiant('CIN-MET');
        $etudiant->update(['created_at' => now()->subDays(10)]);
        $etudiant->definirStatut('valide');
        SituationEtudiant::where('etudiant_id', $etudiant->id)
            ->where('nouvelle_valeur', 'valide')
            ->update(['date_effet' => now()->subDays(3)->toDateString()]);

        DossierCorrection::create([
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => null,
            'champ' => 'adresse',
            'ancienne_valeur' => 'ancienne',
            'nouvelle_valeur' => 'nouvelle',
            'motif' => 'Correction adresse',
            'statut' => 'appliquee',
            'propose_par' => $this->scolarite->id,
            'decide_par' => $this->scolarite->id,
        ]);
        DossierCorrection::create([
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => null,
            'champ' => 'adresse',
            'ancienne_valeur' => 'ancienne',
            'nouvelle_valeur' => 'rejetée',
            'motif' => 'Hors périmètre',
            'statut' => 'rejetee',
            'propose_par' => $this->scolarite->id,
            'decide_par' => $this->scolarite->id,
        ]);
        $contenu = (string) $this->actingAs($this->scolarite)
            ->get(route('admin.statistiques.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Délai moyen traitement inscriptions', $contenu);
        $this->assertStringContainsString('7', $contenu); // 10 - 3 = 7 jours
        $this->assertStringContainsString('Taux de correction appliquée', $contenu);
        $this->assertStringContainsString('50', $contenu); // 1/2 appliquées
    }

    // LOOP 10 — trappe mot de passe perdu : scolarité réinitialise, une fois affiché.
    public function test_reinitialisation_mot_de_passe_etudiant(): void
    {
        $etudiant = $this->creerEtudiant('CIN-RST');

        $this->actingAs($this->scolarite)
            ->post(route('admin.etudiants.reinitialiserMotDePasse', $etudiant), ['confirmation' => true])
            ->assertRedirect(route('admin.etudiants.show', $etudiant))
            ->assertSessionHas('mot_de_passe_provisoire');

        // Ancien mot de passe invalide, le nouveau fonctionne.
        $nouveau = session('mot_de_passe_provisoire');
        $this->assertNotSame('Etudiant@123', $nouveau);
        $this->assertTrue(Hash::check($nouveau, $etudiant->fresh()->password));

        $this->post(route('etudiant.connexion.login'), [
            'identifiant' => $etudiant->email, 'password' => 'Etudiant@123',
        ])->assertSessionHasErrors(['identifiant']);

        $this->post(route('etudiant.connexion.login'), [
            'identifiant' => $etudiant->email, 'password' => $nouveau,
        ])->assertRedirect(route('etudiant.profil.show'));

        // Sans permission → 403.
        $consultation = User::creerCompteBackOffice([
            'name' => 'Lecture', 'email' => 'lec.xp@universite.test', 'password' => 'Motdepasse@123',
        ], 'consultation');
        $this->actingAs($consultation)
            ->post(route('admin.etudiants.reinitialiserMotDePasse', $etudiant), ['confirmation' => true])
            ->assertForbidden();
    }

    // LOOP 07 — CIN normalisée : « 12 345 678 » ne contourne pas « 12345678 ».
    public function test_cin_normalisee_anti_doublon_public(): void
    {
        $this->creerEtudiant('12345678');

        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        RegistrationPeriod::create([
            'annee_universitaire_id' => $annee->id,
            'type_periode' => 'normale',
            'opened_at' => now()->subDay(), 'closed_at' => now()->addDay(), 'statut' => 'active',
        ]);

        // MENS référence pour la CIN espacée : l'identification passe
        // (MENS_SEUL), c'est la garde normalisée qui doit refuser.
        MensReference::create([
            'cin_normalise' => '12 345 678', 'cin_original' => '12 345 678',
            'date_naissance' => '2003-01-01', 'nom' => 'Doublon', 'prenom' => 'Test',
            'sexe' => 'F', 'nationalite' => 'Tunisienne', 'annee' => '2026-2027',
            'source_hash' => str_repeat('b', 64),
        ]);

        $reponse = $this->post('/inscription', [
            'cin' => '12 345 678',
            'date_naissance' => '2003-01-01',
            'nom' => 'Test', 'prenom' => 'Doublon',
            'email' => 'doublon.cin@universite.test', 'sexe' => 'F',
            'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => '12 rue',
            'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123', 'password_confirmation' => 'Etudiant@123',
            'confirmation' => true,
        ]);

        $reponse->assertSessionHasErrors('cin');
        $this->assertSame(1, Etudiant::count());
    }

    // LOOP 10 — libellés humains de l'historique (plus de code technique brut).
    public function test_libelles_historique_lisibles(): void
    {
        $etudiant = $this->creerEtudiant('CIN-HIST');

        HistoriqueAcademique::create([
            'etudiant_id' => $etudiant->id, 'utilisateur_id' => $this->scolarite->id,
            'action' => 'student_document.rejected', 'entite' => 'student_document', 'entite_id' => 1,
            'nouvelle_valeur' => json_encode(['statut' => 'rejected']),
        ]);

        $trace = HistoriqueAcademique::where('action', 'student_document.rejected')->first();

        $this->assertSame('Pièce du dossier rejetée par la scolarité', $trace->action_libelle);
    }
}
