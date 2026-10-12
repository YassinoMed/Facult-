<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\RegistrationPeriod;
use App\Models\User;
use App\Modules\Referentiel\Models\Programme;
use App\Services\PeriodeInscriptionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP 02 — corrections A1/A2 (audit périodes) :
 * A1 : la prolongation ne contourne plus la politique de chevauchement
 *      « même type + même portée » (parité avec la création) ;
 * A2 : la portée `programme_id` est administrable via le formulaire
 *      (select programmes) et fonctionnelle de bout en bout.
 */
class PeriodeCorrectionLoopTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private User $scolarite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.periode-corr@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    private function creerNormale(string $ouverture, string $fermeture, array $portee = []): RegistrationPeriod
    {
        return app(PeriodeInscriptionService::class)->creerNormale([
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculte->id,
            ...$portee,
            'opened_at' => $ouverture,
            'closed_at' => $fermeture,
        ], $this->scolarite->id);
    }

    // A1 — prolongation créant un chevauchement même type + même portée : REFUSÉE.
    public function test_prolongation_creant_chevauchement_refusee(): void
    {
        $premiere = $this->creerNormale('2026-09-01', '2026-09-15');
        $seconde = $this->creerNormale('2026-09-20', '2026-09-30');

        // Prolonger la 1re jusqu'au 25 : chevauche la 2de (20→30) → refus.
        try {
            app(PeriodeInscriptionService::class)
                ->prolonger($premiere, '2026-09-25', $this->scolarite->id, 'Prolongation qui chevauche volontairement.');
            $this->fail('La prolongation en chevauchement aurait dû être refusée.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('chevauche', $e->getMessage());
            $this->assertStringContainsString('#'.$seconde->id, $e->getMessage());
        }

        // Rien n'a bougé : fermeture d'origine conservée (transaction).
        $this->assertSame(
            '2026-09-15',
            $premiere->fresh()->closed_at->format('Y-m-d'),
        );
    }

    // A1 — prolongation SANS chevauchement : toujours acceptée (non-régression).
    public function test_prolongation_sans_chevauchement_acceptee(): void
    {
        $premiere = $this->creerNormale('2026-09-01', '2026-09-15');
        $this->creerNormale('2026-09-20', '2026-09-30');

        // Jusqu'au 18 : avant le début de la seconde → OK.
        $prolongee = app(PeriodeInscriptionService::class)
            ->prolonger($premiere, '2026-09-18', $this->scolarite->id, 'Prolongation légitime sans conflit.');

        $this->assertSame('2026-09-18', $prolongee->fresh()->closed_at->format('Y-m-d'));
    }

    // A1 — s'ignorer soi-même : une période ne chevauche jamais elle-même.
    public function test_prolongation_ne_se_compte_pas_elle_meme(): void
    {
        $unique = $this->creerNormale('2026-09-01', '2026-09-15');

        $prolongee = app(PeriodeInscriptionService::class)
            ->prolonger($unique, '2026-10-15', $this->scolarite->id, 'Extension large, aucune autre période.');

        $this->assertSame('2026-10-15', $prolongee->fresh()->closed_at->format('Y-m-d'));
    }

    // A1 — normale × exception : complémentaires, prolongation d'une exception
    // recouvrant une normale reste autorisée (règle inchangée).
    public function test_prolongation_exception_sur_normale_autorisee(): void
    {
        $this->creerNormale('2026-09-01', '2026-09-30');

        $exception = app(PeriodeInscriptionService::class)->creerException([
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculte->id,
            'opened_at' => '2026-09-10', 'closed_at' => '2026-09-12',
            'motif' => 'Ouverture ponctuelle pour dossier bloqué.',
        ], $this->scolarite->id);

        // Prolonger l'exception au cœur de la normale : différent type → OK.
        $prolongee = app(PeriodeInscriptionService::class)
            ->prolonger($exception, '2026-09-20', $this->scolarite->id, 'Extension de l’exception.');

        $this->assertSame('2026-09-20', $prolongee->fresh()->closed_at->format('Y-m-d'));
    }

    // A2 — portée programme_id administrable : le select est rendu, la création
    // persiste la portée, et l'évaluation la respecte (dedans = ouvert, dehors = fermé).
    public function test_portee_programme_administrable_et_fonctionnelle(): void
    {
        $programmeCible = Programme::create([
            'code' => 'L-INFO', 'nom' => 'Licence Info', 'faculte_id' => $this->faculte->id,
            'cycle' => 'licence', 'duree_annees' => 3, 'actif' => true,
        ]);
        $programmeAutre = Programme::create([
            'code' => 'L-DROI', 'nom' => 'Licence Droit', 'faculte_id' => $this->faculte->id,
            'cycle' => 'licence', 'duree_annees' => 3, 'actif' => true,
        ]);

        // Le formulaire rend le select avec les programmes.
        $contenu = (string) $this->actingAs($this->scolarite)
            ->get(route('admin.periodes.index'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('name="programme_id"', $contenu);
        $this->assertStringContainsString('L-INFO', $contenu);

        // Création via le formulaire avec portée programme.
        $this->actingAs($this->scolarite)
            ->post(route('admin.periodes.store'), [
                'annee_universitaire_id' => $this->annee->id,
                'type_periode' => 'normale',
                'programme_id' => $programmeCible->id,
                'opened_at' => '2026-09-01',
                'closed_at' => '2026-09-30',
            ])
            ->assertRedirect(route('admin.periodes.index'));

        $periode = RegistrationPeriod::where('programme_id', $programmeCible->id)->firstOrFail();
        $this->assertSame($programmeCible->id, (int) $periode->programme_id);

        // Évaluation : étudiant du programme ciblé = OUVERT, autre programme = FERMÉ.
        Carbon::setTestNow('2026-09-15 10:00');
        try {
            $dedans = new Etudiant([
                'faculte_id' => $this->faculte->id, 'programme_id' => $programmeCible->id, 'id' => 100001,
            ]);
            $dehors = new Etudiant([
                'faculte_id' => $this->faculte->id, 'programme_id' => $programmeAutre->id, 'id' => 100002,
            ]);

            $service = app(PeriodeInscriptionService::class);
            $this->assertSame('NORMAL_OPEN', $service->evaluer($dedans, $this->annee)['code']);
            $this->assertSame('REGISTRATION_PERIOD_CLOSED', $service->evaluer($dehors, $this->annee)['code']);
        } finally {
            Carbon::setTestNow();
        }
    }

    // A2 — deux normales de PROGRAMMES différents : jamais en conflit
    // (portées distinctes = compatibles, création et prolongation OK).
    public function test_deux_normales_programmes_differents_coexistent(): void
    {
        $info = Programme::create([
            'code' => 'L-INFO', 'nom' => 'Info', 'faculte_id' => $this->faculte->id,
            'cycle' => 'licence', 'duree_annees' => 3, 'actif' => true,
        ]);
        $droit = Programme::create([
            'code' => 'L-DROI', 'nom' => 'Droit', 'faculte_id' => $this->faculte->id,
            'cycle' => 'licence', 'duree_annees' => 3, 'actif' => true,
        ]);

        $infoPeriode = $this->creerNormale('2026-09-01', '2026-09-15', ['programme_id' => $info->id]);

        // Mêmes dates, programme différent : création OK (pas de conflit).
        $droitPeriode = $this->creerNormale('2026-09-01', '2026-09-15', ['programme_id' => $droit->id]);
        $this->assertNotNull($droitPeriode);

        // Prolonger l'une au-delà de l'autre : programmes distincts → OK.
        $prolongee = app(PeriodeInscriptionService::class)
            ->prolonger($infoPeriode, '2026-09-25', $this->scolarite->id, 'Prolongation Info uniquement.');
        $this->assertSame('2026-09-25', $prolongee->fresh()->closed_at->format('Y-m-d'));
    }

    // A1+HTTP — le contrôleur renvoie bien l'erreur métier à l'utilisateur
    // (dates futures : la validation du contrôleur exige after:today).
    public function test_prolongation_chevauchement_via_http_refusee(): void
    {
        $premiere = $this->creerNormale(now()->addDays(10)->format('Y-m-d'), now()->addDays(20)->format('Y-m-d'));
        $this->creerNormale(now()->addDays(25)->format('Y-m-d'), now()->addDays(40)->format('Y-m-d'));

        $this->actingAs($this->scolarite)
            ->post(route('admin.periodes.prolonger', $premiere), [
                'closed_at' => now()->addDays(30)->format('Y-m-d'),
                'motif' => 'Tentative de chevauchement via le formulaire.',
            ])
            ->assertSessionHasErrors(['periode']);

        $this->assertSame(
            now()->addDays(20)->format('Y-m-d'),
            $premiere->fresh()->closed_at->format('Y-m-d'),
        );
    }
}
