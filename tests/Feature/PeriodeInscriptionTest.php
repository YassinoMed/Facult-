<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\RegistrationPeriod;
use App\Models\User;
use App\Services\ArchiveService;
use App\Services\InscriptionService;
use App\Services\PeriodeInscriptionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP périodes d'inscription (§22-24) : garde serveur, priorité
 * normale → exception → REFUS, exceptions scopées + individuelles à
 * usage unique, jamais de contournement silencieux.
 */
class PeriodeInscriptionTest extends TestCase
{
    use RefreshDatabase;

    private User $scolarite;

    private Faculte $faculte;

    private Faculte $autreFaculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->autreFaculte = Faculte::create(['nom' => 'FLSH', 'code' => 'FLSH', 'description' => 'T', 'active' => true]);
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.per@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
    }

    public function test_sans_periode_transition_autorisee(): void
    {
        $etudiant = $this->creerEtudiant('PER00001');

        $evaluation = app(PeriodeInscriptionService::class)->evaluer($etudiant, $this->annee, 'reinscription');

        $this->assertTrue($evaluation['autorisee']);
        $this->assertSame('SANS_PERIODE', $evaluation['code']);
    }

    public function test_etat_admin_sans_periode_n_affiche_pas_ferme(): void
    {
        // Aucune période sur l'année : côté admin le formulaire reste
        // interactif (régime transitoire) — le bandeau ne doit donc pas
        // annoncer « Inscriptions fermées ».
        $etudiant = $this->creerEtudiant('PER00009');

        $etat = app(PeriodeInscriptionService::class)->etat($etudiant, $this->annee, 'reinscription');

        $this->assertTrue($etat['interactif']);
        $this->assertStringNotContainsString('fermées', $etat['titre']);
        $this->assertStringContainsString('transition', mb_strtolower($etat['titre'].$etat['message']));
    }

    public function test_periode_normale_ouverte_avant_apres(): void
    {
        $this->creerNormale('2026-09-01 08:00', '2026-09-30 23:59');
        $etudiant = $this->creerEtudiant('PER00002');

        Carbon::setTestNow('2026-09-15 10:00');
        $this->assertSame('NORMAL_OPEN', app(PeriodeInscriptionService::class)->evaluer($etudiant, $this->annee)['code']);

        Carbon::setTestNow('2026-08-15 10:00');
        $this->assertSame('REGISTRATION_PERIOD_CLOSED', app(PeriodeInscriptionService::class)->evaluer($etudiant, $this->annee)['code']);

        Carbon::setTestNow('2026-10-05 10:00');
        $this->assertSame('REGISTRATION_PERIOD_CLOSED', app(PeriodeInscriptionService::class)->evaluer($etudiant, $this->annee)['code']);

        Carbon::setTestNow();
    }

    public function test_cas24_sans_exception_inscription_refusee_rien_cree(): void
    {
        $this->creerNormale('2026-09-01 08:00', '2026-09-30 23:59');
        $etudiant = $this->creerEtudiant('PER00003', avecInscription: false);
        Carbon::setTestNow('2026-10-05 10:00');

        try {
            app(InscriptionService::class)->reinscrire($etudiant, $this->annee, $this->scolarite->id);
            $this->fail('Inscription hors période aurait dû être refusée.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('REGISTRATION_PERIOD_CLOSED', $e->getMessage());
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(0, InscriptionAcademique::where('etudiant_id', $etudiant->id)->count());
    }

    public function test_cas23_exception_puis_validation_puis_affectation(): void
    {
        $this->creerNormale('2026-09-01 08:00', '2026-09-30 23:59');
        $etudiant = $this->creerEtudiant('PER00004', avecInscription: false);

        app(PeriodeInscriptionService::class)->creerException([
            'annee_universitaire_id' => $this->annee->id, 'faculte_id' => $this->faculte->id,
            'etudiant_id' => $etudiant->id,
            'opened_at' => '2026-10-05 08:00', 'closed_at' => '2026-10-07 23:59',
            'motif' => 'Absence administrative justifiée, demande validée.',
        ], $this->scolarite->id);

        Carbon::setTestNow('2026-10-05 10:00');

        try {
            $inscription = app(InscriptionService::class)->reinscrire($etudiant, $this->annee, $this->scolarite->id);
            app(InscriptionService::class)->transiter($inscription, 'valide', $this->scolarite->id);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame('valide', $inscription->fresh()->statut);
        $this->assertSame('utilisee', RegistrationPeriod::where('etudiant_id', $etudiant->id)->firstOrFail()->statut);
        $this->assertDatabaseHas('historique_academique', ['action' => 'creation_inscription']);
    }

    public function test_exception_expiree_revoquee_individuelle_isolee(): void
    {
        $this->creerNormale('2026-09-01 08:00', '2026-09-30 23:59');
        $cible = $this->creerEtudiant('PER00005', avecInscription: false);
        $voisin = $this->creerEtudiant('PER00006', avecInscription: false);

        $exception = app(PeriodeInscriptionService::class)->creerException([
            'annee_universitaire_id' => $this->annee->id, 'etudiant_id' => $cible->id,
            'opened_at' => '2026-10-05 08:00', 'closed_at' => '2026-10-07 23:59',
            'motif' => 'Cas individuel documenté.',
        ], $this->scolarite->id);

        Carbon::setTestNow('2026-10-06 10:00');

        try {
            // Voisin non couvert : refusé (pas d'ouverture collective).
            $this->assertSame('REGISTRATION_PERIOD_CLOSED',
                app(PeriodeInscriptionService::class)->evaluer($voisin, $this->annee)['code']);
            $this->assertSame('EXCEPTION_OPEN',
                app(PeriodeInscriptionService::class)->evaluer($cible, $this->annee)['code']);

            // Expirée : refusée.
            Carbon::setTestNow('2026-10-08 10:00');
            $this->assertSame('REGISTRATION_PERIOD_CLOSED',
                app(PeriodeInscriptionService::class)->evaluer($cible, $this->annee)['code']);

            // Révoquée : refusée même dans la fenêtre.
            Carbon::setTestNow('2026-10-06 10:00');
            app(PeriodeInscriptionService::class)->revoquer($exception, $this->scolarite->id, 'Motif administratif sérieux.');
            $this->assertSame('revoquee', $exception->fresh()->statut);
            $this->assertSame('REGISTRATION_PERIOD_CLOSED',
                app(PeriodeInscriptionService::class)->evaluer($cible, $this->annee)['code']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_portees_formation_faculte_type(): void
    {
        $this->creerNormale('2026-09-01 08:00', '2026-09-30 23:59', ['type_inscription' => 'reinscription']);
        $etudiant = $this->creerEtudiant('PER00007');

        Carbon::setTestNow('2026-09-15 10:00');

        try {
            $this->assertSame('NORMAL_OPEN',
                app(PeriodeInscriptionService::class)->evaluer($etudiant, $this->annee, 'reinscription')['code']);
            $this->assertSame('REGISTRATION_PERIOD_CLOSED',
                app(PeriodeInscriptionService::class)->evaluer($etudiant, $this->annee, 'premiere')['code']);

            // Autre faculté : hors périmètre.
            $etudiantEtranger = $this->creerEtudiant('PER00008');
            $etudiantEtranger->update(['faculte_id' => $this->autreFaculte->id]);
            $this->assertSame('REGISTRATION_PERIOD_CLOSED',
                app(PeriodeInscriptionService::class)->evaluer($etudiantEtranger->fresh(), $this->annee, 'reinscription')['code']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_motif_obligatoire_et_non_autorise(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('motif obligatoire');

        app(PeriodeInscriptionService::class)->creerException([
            'annee_universitaire_id' => $this->annee->id,
            'opened_at' => '2026-10-05 08:00', 'closed_at' => '2026-10-07 23:59',
        ], $this->scolarite->id);
    }

    public function test_admin_non_autorise_403_et_idor(): void
    {
        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'agent.per@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        $this->actingAs($agent)->get(route('admin.periodes.index'))->assertForbidden();
        $this->actingAs($agent)->post(route('admin.periodes.store'), [
            'annee_universitaire_id' => $this->annee->id, 'type_periode' => 'normale',
            'opened_at' => '2026-09-01 08:00', 'closed_at' => '2026-09-30 23:59',
        ])->assertForbidden();

        // IDOR inter-facultés : période d'une autre faculté invisible au scoped.
        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.per@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->autreFaculte->id,
        ], 'admin_faculte');

        $etudiant = $this->creerEtudiant('PER00009', avecInscription: false);
        $this->actingAs($adminFaculte)->post(route('admin.etudiants.reinscriptions.store', $etudiant), [
            'annee_universitaire_id' => $this->annee->id, 'confirmer' => true,
        ])->assertNotFound();
        $this->assertSame(0, InscriptionAcademique::where('etudiant_id', $etudiant->id)->count());
    }

    public function test_double_soumission_concurrence_idempotente(): void
    {
        $etudiant = $this->creerEtudiant('PER00010', avecInscription: false);

        app(ArchiveService::class)->creerInscription($etudiant, [
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1', 'type' => 'premiere',
        ]);

        try {
            app(ArchiveService::class)->creerInscription($etudiant, [
                'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1', 'type' => 'premiere',
            ]);
            $this->fail('Doublon aurait dû être refusé.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('déjà une inscription', $e->getMessage());
        }

        $this->assertSame(1, InscriptionAcademique::where('etudiant_id', $etudiant->id)->count());
    }

    public function test_prolongation_auditee_sans_reecriture(): void
    {
        $normale = $this->creerNormale('2026-09-01 08:00', '2026-09-30 23:59');

        $prolongee = app(PeriodeInscriptionService::class)->prolonger(
            $normale, '2026-10-05 23:59', $this->scolarite->id, 'Décision de prolongation officielle.'
        );

        $this->assertSame('2026-10-05 22:59', $prolongee->closed_at->format('Y-m-d H:i'));
        $this->assertSame('05/10/2026 23:59', app(PeriodeInscriptionService::class)->formatHeure($prolongee->closed_at));
        $this->assertSame('01/09/2026 08:00', app(PeriodeInscriptionService::class)->formatHeure($prolongee->opened_at));
        $this->assertSame(1, RegistrationPeriod::where('type_periode', 'normale')->count());
    }

    public function test_ui_reinscription_fermee_fieldset_desactive(): void
    {
        $this->creerNormale('2026-09-01 08:00', '2026-09-30 23:59');
        $etudiant = $this->creerEtudiant('PER00011', avecInscription: false);
        $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->autreAnnee()->id, 'niveau' => 'L1', 'statut' => 'valide',
        ]);
        Carbon::setTestNow('2026-10-05 10:00');

        try {
            $contenu = (string) $this->actingAs($this->scolarite)
                ->get(route('admin.etudiants.reinscriptions.creer', [$etudiant, 'annee_universitaire_id' => $this->annee->id]))
                ->assertOk()->getContent();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertStringContainsString('Inscriptions fermées', $contenu);
        $this->assertStringContainsString('<fieldset', $contenu);
        $this->assertStringContainsString('disabled', $contenu);
        $this->assertStringContainsString('🔒 Inscriptions fermées', $contenu);
        // Dossier reste lisible (lecture seule, pas de champ).
        $this->assertStringContainsString($etudiant->num_inscription, $contenu);
    }

    public function test_ui_reinscription_ouverte_formulaire_actif(): void
    {
        $this->creerNormale('2026-09-01 08:00', '2026-09-30 23:59');
        $etudiant = $this->creerEtudiant('PER00012', avecInscription: false);
        Carbon::setTestNow('2026-09-15 10:00');

        try {
            $contenu = (string) $this->actingAs($this->scolarite)
                ->get(route('admin.etudiants.reinscriptions.creer', $etudiant))
                ->assertOk()->getContent();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertStringContainsString('Inscriptions ouvertes', $contenu);
        $this->assertStringContainsString('Confirmer la réinscription', $contenu);
    }

    public function test_ui_endpoint_etat_et_soumission_expiree_refusee(): void
    {
        $this->creerNormale('2026-09-01 08:00', '2026-09-30 23:59');
        $etudiant = $this->creerEtudiant('PER00013', avecInscription: false);
        $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->autreAnnee()->id, 'niveau' => 'L1', 'statut' => 'valide',
        ]);

        // Endpoint : état serveur exposé au frontend.
        Carbon::setTestNow('2026-09-15 10:00');
        $ouvert = $this->actingAs($this->scolarite)->getJson(route('admin.periodes.etat', [
            'etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $this->annee->id,
        ]))->assertOk()->json();
        $this->assertSame('OPEN', $ouvert['statut']);
        $this->assertTrue($ouvert['interactif']);

        // Page ouverte à 09:59, période fermée à 10:00, soumission 10:05 → REFUS.
        Carbon::setTestNow('2026-10-01 00:01');
        $ferme = $this->actingAs($this->scolarite)->getJson(route('admin.periodes.etat', [
            'etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $this->annee->id,
        ]))->assertOk()->json();
        $this->assertSame('CLOSED', $ferme['statut']);
        $this->assertFalse($ferme['interactif']);

        Carbon::setTestNow('2026-10-05 10:05');

        try {
            $this->actingAs($this->scolarite)->post(route('admin.etudiants.reinscriptions.store', $etudiant), [
                'annee_universitaire_id' => $this->annee->id, 'confirmer' => true,
            ])->assertRedirect()->assertSessionHasErrors('inscription');
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(0, InscriptionAcademique::where('etudiant_id', $etudiant->id)->where('annee_universitaire_id', $this->annee->id)->count());
    }

    private function autreAnnee(): AnneeUniversitaire
    {
        return AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-06-30', 'active' => false,
        ]);
    }

    private function creerNormale(string $ouverture, string $fermeture, array $portee = []): RegistrationPeriod
    {
        return app(PeriodeInscriptionService::class)->creerNormale(array_merge([
            'annee_universitaire_id' => $this->annee->id, 'faculte_id' => $this->faculte->id,
            'opened_at' => $ouverture, 'closed_at' => $fermeture,
        ], $portee), $this->scolarite->id);
    }

    private function creerEtudiant(string $cin, bool $avecInscription = true): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara', 'email' => strtolower($cin).'@per.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');

        if ($avecInscription) {
            $etudiant->inscriptionsAcademiques()->create([
                'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
            ]);
        }

        return $etudiant;
    }
}
