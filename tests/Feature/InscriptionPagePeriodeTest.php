<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\MensReference;
use App\Models\RegistrationPeriod;
use App\Models\User;
use App\Services\PeriodeInscriptionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP page inscription étudiant (E2E §22) : ADMIN configure → page
 * consomme le service → UI adaptée → backend revalide à la soumission
 * → validation → affectation automatique.
 */
class InscriptionPagePeriodeTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private ?User $scolariteMemo = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
    }

    public function test_page_ouverte_formulaire_actif(): void
    {
        $this->creerNormale('2026-09-01 08:00', '2026-09-30 23:59');
        Carbon::setTestNow('2026-09-15 10:00');

        try {
            $contenu = (string) $this->get(route('inscription.create'))->assertOk()->getContent();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertStringContainsString('Inscriptions ouvertes', $contenu);
        $this->assertStringContainsString('Confirmer mon inscription', $contenu);
        $this->assertStringContainsString(':disabled="!periode.interactif"', $contenu);
    }

    public function test_page_fermee_banniere_et_donnees_serveur(): void
    {
        $this->creerNormale('2026-09-01 08:00', '2026-09-30 23:59');
        Carbon::setTestNow('2026-10-05 10:00');

        try {
            $contenu = (string) $this->get(route('inscription.create'))->assertOk()->getContent();
            $etat = $this->getJson(route('inscription.periode-etat', [
                'faculte_id' => $this->faculte->id, 'annee' => '2026-2027',
            ]))->assertOk()->json();
        } finally {
            Carbon::setTestNow();
        }

        // Bannière serveur initiale + formulaire toujours visible (désactivé en JS).
        $this->assertStringContainsString('Inscriptions fermées', $contenu);
        $this->assertStringContainsString('Confirmer mon inscription', $contenu);
        $this->assertSame('CLOSED', $etat['statut']);
        $this->assertFalse($etat['interactif']);
    }

    public function test_exception_ouverte_badge_et_post_autorise(): void
    {
        $this->creerNormale('2026-09-01 08:00', '2026-09-30 23:59');
        $scolarite = $this->scolarite();
        app(PeriodeInscriptionService::class)->creerException([
            'annee_universitaire_id' => $this->annee->id, 'faculte_id' => $this->faculte->id,
            'opened_at' => '2026-10-05 08:00', 'closed_at' => '2026-10-07 23:59',
            'motif' => 'Rattrapage administratif officiel.',
        ], $scolarite->id);

        Carbon::setTestNow('2026-10-06 10:00');

        try {
            $etat = $this->getJson(route('inscription.periode-etat', [
                'faculte_id' => $this->faculte->id, 'annee' => '2026-2027',
            ]))->assertOk()->json();
            $this->assertSame('EXCEPTION_OPEN', $etat['statut']);
            $this->assertTrue($etat['interactif']);

            MensReference::create([
                'cin_normalise' => '08000001', 'cin_original' => '08000001',
                'date_naissance' => '2004-03-15', 'nom' => 'Alami', 'prenom' => 'Mehdi',
                'annee' => '2026-2027', 'source_hash' => hash('sha256', '080000012004-03-15'),
            ]);
            $this->post(route('inscription.store'), $this->payload('08000001', 'exp.test@example.com'))
                ->assertRedirect(route('inscription.success'));
        } finally {
            Carbon::setTestNow();
        }

        $this->assertDatabaseHas('etudiants', ['cin' => '08000001']);
    }

    public function test_post_hors_periode_refuse_rien_cree(): void
    {
        $this->creerNormale('2026-09-01 08:00', '2026-09-30 23:59');
        Carbon::setTestNow('2026-10-05 10:00');

        try {
            $this->post(route('inscription.store'), $this->payload('08000002', 'clos.test@example.com'))
                ->assertRedirect()
                ->assertSessionHasErrors('periode');
        } finally {
            Carbon::setTestNow();
        }

        $this->assertDatabaseMissing('etudiants', ['cin' => '08000002']);
    }

    public function test_post_apres_expiration_refuse(): void
    {
        $this->creerNormale('2026-09-01 08:00', '2026-09-30 23:59');

        // Page ouverte à 09:59, soumission après fermeture.
        Carbon::setTestNow('2026-10-01 00:01');

        try {
            $this->post(route('inscription.store'), $this->payload('08000003', 'expire.test@example.com'))
                ->assertRedirect()
                ->assertSessionHasErrors('periode');
        } finally {
            Carbon::setTestNow();
        }

        $this->assertDatabaseMissing('etudiants', ['cin' => '08000003']);
    }

    public function test_exception_individuelle_par_cin_isolee(): void
    {
        $this->creerNormale('2026-09-01 08:00', '2026-09-30 23:59');
        $scolarite = $this->scolarite();

        $cible = Etudiant::create([
            'cin' => '41000001', 'nom' => 'Benali', 'prenom' => 'Sara', 'email' => 'ind@per.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);

        app(PeriodeInscriptionService::class)->creerException([
            'annee_universitaire_id' => $this->annee->id, 'etudiant_id' => $cible->id,
            'opened_at' => '2026-10-05 08:00', 'closed_at' => '2026-10-07 23:59',
            'motif' => 'Dossier individuel à régulariser.',
        ], $scolarite->id);

        Carbon::setTestNow('2026-10-06 10:00');

        try {
            $pourLui = $this->getJson(route('inscription.periode-etat', [
                'faculte_id' => $this->faculte->id, 'annee' => '2026-2027', 'cin' => '41000001',
            ]))->assertOk()->json();
            $pourAutre = $this->getJson(route('inscription.periode-etat', [
                'faculte_id' => $this->faculte->id, 'annee' => '2026-2027', 'cin' => '41000099',
            ]))->assertOk()->json();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame('EXCEPTION_OPEN', $pourLui['statut']);
        $this->assertTrue($pourLui['interactif']);
        $this->assertSame('CLOSED', $pourAutre['statut']);
        $this->assertFalse($pourAutre['interactif']);
    }

    public function test_exception_faculte_hors_perimetre(): void
    {
        $autre = Faculte::create(['nom' => 'FLSH', 'code' => 'FLSH', 'description' => 'T', 'active' => true]);
        $this->creerNormale('2026-09-01 08:00', '2026-09-30 23:59');
        app(PeriodeInscriptionService::class)->creerException([
            'annee_universitaire_id' => $this->annee->id, 'faculte_id' => $this->faculte->id,
            'opened_at' => '2026-10-05 08:00', 'closed_at' => '2026-10-07 23:59',
            'motif' => 'Faculté FST uniquement.',
        ], $this->scolarite()->id);

        Carbon::setTestNow('2026-10-06 10:00');

        try {
            $etat = $this->getJson(route('inscription.periode-etat', [
                'faculte_id' => $autre->id, 'annee' => '2026-2027',
            ]))->assertOk()->json();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame('CLOSED', $etat['statut']);
    }

    public function test_double_post_meme_cin_sans_doublon(): void
    {
        app(PeriodeInscriptionService::class)->creerNormale([
            'annee_universitaire_id' => $this->annee->id,
            'opened_at' => '2020-01-01 00:00', 'closed_at' => '2030-12-31 23:59',
        ], null);

        MensReference::create([
            'cin_normalise' => '08000004', 'cin_original' => '08000004',
            'date_naissance' => '2004-03-15', 'nom' => 'Alami', 'prenom' => 'Mehdi',
            'annee' => '2026-2027', 'source_hash' => hash('sha256', '080000042004-03-15'),
        ]);

        $payload = $this->payload('08000004', 'double.test@example.com');

        $this->post(route('inscription.store'), $payload)->assertRedirect(route('inscription.success'));
        $this->post(route('inscription.store'), $payload)->assertSessionHasErrors(['cin', 'email']);
        $this->assertSame(1, Etudiant::where('cin', '08000004')->count());
    }

    public function test_sans_configuration_formulaire_desactive_et_post_refuse(): void
    {
        $contenu = (string) $this->get(route('inscription.create'))->assertOk()->getContent();

        $this->assertStringContainsString('Inscriptions fermées', $contenu);
        // État initial JSON (@js échappe en \u0022) : NOT_CONFIGURED, non interactif.
        $this->assertStringContainsString('NOT_CONFIGURED', $contenu);
        $this->assertStringContainsString('interactif', $contenu);

        $etat = $this->getJson(route('inscription.periode-etat', [
            'faculte_id' => $this->faculte->id, 'annee' => '2026-2027',
        ]))->assertOk()->json();
        $this->assertSame('NOT_CONFIGURED', $etat['statut']);
        $this->assertFalse($etat['interactif']);

        $this->post(route('inscription.store'), $this->payload('08000005', 'noc.test@example.com'))
            ->assertRedirect()
            ->assertSessionHasErrors('periode');
        $this->assertDatabaseMissing('etudiants', ['cin' => '08000005']);
    }

    public function test_boutons_suivant_desactives_si_ferme_etape3_libre(): void
    {
        $this->creerNormale('2026-09-01 08:00', '2026-09-30 23:59');
        Carbon::setTestNow('2026-10-05 10:00');

        try {
            $contenu = (string) $this->get(route('inscription.create'))->assertOk()->getContent();
        } finally {
            Carbon::setTestNow();
        }

        // Suivant étapes 1/2/4 + submit verrouillés ; étape 3 (contexte) libre.
        $this->assertGreaterThanOrEqual(3, substr_count($contenu, '@click="etapeSuivante()" :disabled="!periode.interactif"'));
        $this->assertStringContainsString('@periode-actualisee.window', $contenu);
        // Réalignement auto (page chargée avant l'ouverture admin).
        $this->assertStringContainsString('setInterval(() => this.actualiserPeriode(), 60000)', $contenu);
        $this->assertStringContainsString('visibilitychange', $contenu);
        // Submit verrouillé aussi sans JS (état serveur initial).
        $this->assertMatchesRegularExpression('/<button type="submit"[^>]*disabled/', $contenu);
    }

    public function test_endpoint_admin_scope_404_hors_perimetre(): void
    {
        $autre = Faculte::create(['nom' => 'FLSH', 'code' => 'FLSH', 'description' => 'T', 'active' => true]);
        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.scope@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $autre->id,
        ], 'admin_faculte');

        $etudiant = Etudiant::create([
            'cin' => '42000001', 'nom' => 'Benali', 'prenom' => 'Sara', 'email' => 'sco@per.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);

        $this->actingAs($adminFaculte)->getJson(route('admin.periodes.etat', [
            'etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $this->annee->id,
        ]))->assertNotFound();
    }

    private function creerNormale(string $ouverture, string $fermeture): void
    {
        app(PeriodeInscriptionService::class)->creerNormale([
            'annee_universitaire_id' => $this->annee->id, 'faculte_id' => $this->faculte->id,
            'opened_at' => $ouverture, 'closed_at' => $fermeture,
        ], $this->scolarite()->id);
    }

    public function test_bornes_inclusives_regle_metier(): void
    {
        $this->creerNormale('2026-09-01 08:00', '2026-09-30 23:59');
        $etudiant = $this->creerEtudiantBrut('BOR00001');
        $service = app(PeriodeInscriptionService::class);

        $cas = [
            '2026-08-31 23:59' => 'REGISTRATION_PERIOD_CLOSED',
            '2026-09-01 07:00' => 'NORMAL_OPEN',
            '2026-09-15 12:00' => 'NORMAL_OPEN',
            '2026-09-30 22:59' => 'NORMAL_OPEN',
            '2026-09-30 23:00' => 'REGISTRATION_PERIOD_CLOSED',
            '2026-10-01 00:00' => 'REGISTRATION_PERIOD_CLOSED',
        ];

        foreach ($cas as $quand => $attendu) {
            Carbon::setTestNow($quand);

            try {
                $this->assertSame($attendu, $service->evaluer($etudiant, $this->annee)['code'], "à {$quand}");
            } finally {
                Carbon::setTestNow();
            }
        }
    }

    public function test_annee_incorrecte_jamais_confondue(): void
    {
        $this->creerNormale('2026-09-01 08:00', '2026-09-30 23:59');
        $etudiant = $this->creerEtudiantBrut('AN000001');
        AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-06-30', 'active' => false,
        ]);
        Carbon::setTestNow('2026-09-15 10:00');

        try {
            // La période 2026-2027 n'ouvre jamais pour une autre année.
            $this->assertSame('NORMAL_OPEN', app(PeriodeInscriptionService::class)->evaluer($etudiant, $this->annee)['code']);

            $etatAutreAnnee = $this->getJson(route('inscription.periode-etat', [
                'faculte_id' => $this->faculte->id, 'annee' => '2025-2026',
            ]))->assertOk()->json();
            $this->assertSame('NOT_CONFIGURED', $etatAutreAnnee['statut']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_e2e_cycle_complet_admin_fermeture_exception_expiration(): void
    {
        $service = app(PeriodeInscriptionService::class);
        $scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.e2e@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        // ADMIN crée la période → page OUVERTE, formulaire actif.
        $service->creerNormale([
            'annee_universitaire_id' => $this->annee->id, 'faculte_id' => $this->faculte->id,
            'opened_at' => '2026-09-01 08:00', 'closed_at' => '2026-09-30 23:59',
        ], $scolarite->id);

        Carbon::setTestNow('2026-09-15 10:00');
        $ouvert = $this->getJson(route('inscription.periode-etat', [
            'faculte_id' => $this->faculte->id, 'annee' => '2026-2027',
        ]))->assertOk()->json();
        $this->assertSame('OPEN', $ouvert['statut']);
        $this->assertTrue($ouvert['interactif']);

        // ADMIN ferme (révocation) → refresh → CLOSED, POST refusé.
        $normale = RegistrationPeriod::where('type_periode', 'normale')->firstOrFail();
        $service->revoquer($normale, $scolarite->id, 'Fermeture anticipée officielle.');
        $ferme = $this->getJson(route('inscription.periode-etat', [
            'faculte_id' => $this->faculte->id, 'annee' => '2026-2027',
        ]))->assertOk()->json();
        $this->assertSame('CLOSED', $ferme['statut']);
        $this->post(route('inscription.store'), $this->payload('08000006', 'e2e.test@example.com'))
            ->assertSessionHasErrors('periode');
        $this->assertDatabaseMissing('etudiants', ['cin' => '08000006']);

        // ADMIN crée l'exception → EXCEPTION_OPEN, POST autorisé.
        $service->creerException([
            'annee_universitaire_id' => $this->annee->id, 'faculte_id' => $this->faculte->id,
            'opened_at' => '2026-10-05 08:00', 'closed_at' => '2026-10-07 23:59',
            'motif' => 'Session de rattrapage officielle.',
        ], $scolarite->id);
        Carbon::setTestNow('2026-10-06 10:00');
        $exception = $this->getJson(route('inscription.periode-etat', [
            'faculte_id' => $this->faculte->id, 'annee' => '2026-2027',
        ]))->assertOk()->json();
        $this->assertSame('EXCEPTION_OPEN', $exception['statut']);
        MensReference::create([
            'cin_normalise' => '08000007', 'cin_original' => '08000007',
            'date_naissance' => '2004-03-15', 'nom' => 'Alami', 'prenom' => 'Mehdi',
            'annee' => '2026-2027', 'source_hash' => hash('sha256', '080000072004-03-15'),
        ]);
        $this->post(route('inscription.store'), $this->payload('08000007', 'e2e2.test@example.com'))
            ->assertRedirect(route('inscription.success'));

        // Expiration → CLOSED à nouveau.
        Carbon::setTestNow('2026-10-08 10:00');
        $expire = $this->getJson(route('inscription.periode-etat', [
            'faculte_id' => $this->faculte->id, 'annee' => '2026-2027',
        ]))->assertOk()->json();
        $this->assertSame('CLOSED', $expire['statut']);
        $this->assertFalse($expire['interactif']);
        Carbon::setTestNow();
    }

    private function creerEtudiantBrut(string $cin): Etudiant
    {
        return Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara', 'email' => strtolower($cin).'@t.t',
            'date_naissance' => '2000-01-01', 'sexe' => 'M', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'V',
            'code_postal' => '1', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
    }

    public function test_ecran_identification_avant_formulaire(): void
    {
        $contenu = (string) $this->get(route('inscription.create'))->assertOk()->getContent();

        $this->assertStringContainsString('ident-cin', $contenu);
        $this->assertStringContainsString('ident-naissance', $contenu);
        $this->assertStringContainsString('Vérifier mon identité', $contenu);
        $this->assertMatchesRegularExpression('/[Cc]onnectez-vous|Se connecter/', $contenu);
        $this->assertStringContainsString(route('etudiant.connexion.show'), $contenu);
    }

    public function test_store_recontrole_existant_refuse(): void
    {
        $this->creerEtudiantBrut('GAT00001');

        $payload = $this->payload('GAT00001', 'gate.test@example.com');
        $payload['date_naissance'] = '2000-01-01';

        $this->post(route('inscription.store'), $payload)
            ->assertRedirect()
            ->assertSessionHasErrors('cin');
        $this->assertSame(1, Etudiant::where('cin', 'GAT00001')->count());
    }

    private function scolarite(): User
    {
        return $this->scolariteMemo ??= User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.page@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    /** @return array<string, mixed> */
    private function payload(string $cin, string $email): array
    {
        return [
            'cin' => $cin, 'nom' => 'Alami', 'prenom' => 'Mehdi', 'email' => $email,
            'telephone' => '+212612345678', 'date_naissance' => '2004-03-15', 'sexe' => 'M',
            'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'annee_academique' => '2026-2027',
            'adresse' => '12 Rue de la Liberté', 'ville' => 'Casablanca', 'code_postal' => '20000',
            'annee_bac' => '2024', 'mention_bac' => 'Bien',
            'password' => 'Etudiant@123', 'password_confirmation' => 'Etudiant@123',
        ];
    }
}
