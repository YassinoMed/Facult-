<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\RegistrationPeriod;
use App\Models\User;
use App\Services\PeriodeInscriptionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsApiClient;
use Tests\TestCase;

/**
 * MISSION fiabilisation : libellé, statuts calculés, chevauchements,
 * mémoïsation, API état, filtres/compteurs admin.
 */
class PeriodeInscriptionMissionTest extends TestCase
{
    use ActAsApiClient;
    use RefreshDatabase;

    private User $scolarite;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.mis@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
    }

    public function test_statuts_calcules_affichage_sans_regle(): void
    {
        Carbon::setTestNow('2026-09-15 10:00');

        try {
            // Portées disjointes (niveaux distincts) : pas de conflit.
            $planifiee = $this->creer(['niveau' => 'L1', 'opened_at' => '2026-10-01 08:00', 'closed_at' => '2026-10-31 23:59']);
            $ouverte = $this->creer(['niveau' => 'L2', 'opened_at' => '2026-09-01 08:00', 'closed_at' => '2026-09-30 23:59']);
            $bientot = $this->creer(['niveau' => 'L3', 'opened_at' => '2026-09-01 08:00', 'closed_at' => '2026-09-16 09:00']);
            $fermee = $this->creer(['niveau' => 'M1', 'opened_at' => '2026-08-01 08:00', 'closed_at' => '2026-08-31 23:59']);

            $this->assertSame('SCHEDULED', $planifiee->statutCalcule());
            $this->assertSame('OPEN', $ouverte->statutCalcule());
            $this->assertSame('CLOSING_SOON', $bientot->statutCalcule());
            $this->assertSame('CLOSED', $fermee->statutCalcule());

            $revoquee = app(PeriodeInscriptionService::class)->revoquer($fermee, $this->scolarite->id, 'Motif administratif sérieux.');
            $this->assertSame('CANCELLED', $revoquee->statutCalcule());

            // CLOSING_SOON ne change pas les règles : toujours autorisé.
            $etudiant = $this->creerEtudiant('MIS00001');
            $etudiant->update(['niveau' => 'L2']);
            $this->assertSame('NORMAL_OPEN', app(PeriodeInscriptionService::class)->evaluer($etudiant->fresh(), $this->annee)['code']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_chevauchement_meme_type_meme_portee_refuse(): void
    {
        $this->creer(['opened_at' => '2026-09-01 08:00', 'closed_at' => '2026-09-30 23:59']);

        try {
            app(PeriodeInscriptionService::class)->creerNormale([
                'annee_universitaire_id' => $this->annee->id, 'faculte_id' => $this->faculte->id,
                'opened_at' => '2026-09-15 08:00', 'closed_at' => '2026-10-15 23:59',
            ], $this->scolarite->id);
            $this->fail('Chevauchement incompatible aurait dû être refusé.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('chevauche', $e->getMessage());
        }

        // Portée disjointe (autre faculté) : autorisé.
        $autre = Faculte::create(['nom' => 'FLSH', 'code' => 'FLSH', 'description' => 'T', 'active' => true]);
        $ok = app(PeriodeInscriptionService::class)->creerNormale([
            'annee_universitaire_id' => $this->annee->id, 'faculte_id' => $autre->id,
            'opened_at' => '2026-09-15 08:00', 'closed_at' => '2026-10-15 23:59',
        ], $this->scolarite->id);
        $this->assertNotNull($ok->id);

        // Normale × exception : complémentaire, toujours autorisé.
        $exception = app(PeriodeInscriptionService::class)->creerException([
            'annee_universitaire_id' => $this->annee->id, 'faculte_id' => $this->faculte->id,
            'opened_at' => '2026-09-15 08:00', 'closed_at' => '2026-10-15 23:59',
            'motif' => 'Complément officiel documenté.',
        ], $this->scolarite->id);
        $this->assertNotNull($exception->id);

        // Deux exceptions même portée qui se recouvrent : refusé.
        try {
            app(PeriodeInscriptionService::class)->creerException([
                'annee_universitaire_id' => $this->annee->id, 'faculte_id' => $this->faculte->id,
                'opened_at' => '2026-09-20 08:00', 'closed_at' => '2026-10-20 23:59',
                'motif' => 'Doublon à refuser.',
            ], $this->scolarite->id);
            $this->fail('Double exception aurait dû être refusée.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('chevauche', $e->getMessage());
        }
    }

    public function test_memoisation_requete_et_invalidation(): void
    {
        $this->creer(['opened_at' => '2026-09-01 08:00', 'closed_at' => '2026-09-30 23:59']);
        $etudiant = $this->creerEtudiant('MIS00002');
        $service = app(PeriodeInscriptionService::class);

        Carbon::setTestNow('2026-09-15 10:00');

        try {
            $premier = $service->evaluer($etudiant, $this->annee);
            $second = $service->evaluer($etudiant, $this->annee);
            $this->assertSame($premier, $second);

            // Écriture → invalidation immédiate, nouvel état visible.
            $service->revoquer(RegistrationPeriod::firstOrFail(), $this->scolarite->id, 'Fermeture de test officielle.');
            $this->assertSame('REGISTRATION_PERIOD_CLOSED', $service->evaluer($etudiant, $this->annee)['code']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_api_etat_meme_source_et_bola(): void
    {
        $this->creer(['opened_at' => '2026-09-01 08:00', 'closed_at' => '2026-09-30 23:59']);
        $etudiant = $this->creerEtudiant('MIS00003');

        [$client, $token] = $this->clientApi(['archives:read']);

        Carbon::setTestNow('2026-09-15 10:00');

        try {
            $this->getApi(route('api.v1.students.registration-period', $etudiant), $token)
                ->assertOk()
                ->assertJsonPath('data.status', 'OPEN')
                ->assertJsonPath('data.is_open', true);
        } finally {
            Carbon::setTestNow();
        }

        // BOLA : étudiant d'une autre faculté → 404.
        $autre = Faculte::create(['nom' => 'FLSH', 'code' => 'FLSH', 'description' => 'T', 'active' => true]);
        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.mis@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $autre->id,
        ], 'admin_faculte');
        $tokenFac = $adminFaculte->createToken('integration-test', ['archives:read'])->plainTextToken;
        // Requête HTTP fraîche : purge la garde mise en cache par l'appel précédent.
        $this->resetAuthGuards();
        $this->getApi(route('api.v1.students.registration-period', $etudiant), $tokenFac)->assertNotFound();
    }

    public function test_admin_filtres_compteurs_libelle(): void
    {
        $this->creer(['opened_at' => '2026-09-01 08:00', 'closed_at' => '2026-09-30 23:59', 'nom' => 'Inscriptions principales']);
        Carbon::setTestNow('2026-09-15 10:00');

        try {
            $contenu = (string) $this->actingAs($this->scolarite)->get(route('admin.periodes.index'))->assertOk()->getContent();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertStringContainsString('Inscriptions principales', $contenu);
        $this->assertStringContainsString('Ouvertes', $contenu);

        $filtre = (string) $this->actingAs($this->scolarite)
            ->get(route('admin.periodes.index', ['type_periode' => 'exception']))->assertOk()->getContent();
        $this->assertStringNotContainsString('Inscriptions principales', $filtre);
    }

    /** @param array<string, mixed> $surcharge */
    private function creer(array $surcharge): RegistrationPeriod
    {
        return app(PeriodeInscriptionService::class)->creerNormale(array_merge([
            'annee_universitaire_id' => $this->annee->id, 'faculte_id' => $this->faculte->id,
        ], $surcharge), $this->scolarite->id);
    }

    private function creerEtudiant(string $cin): Etudiant
    {
        return Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara', 'email' => strtolower($cin).'@mis.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
    }
}
