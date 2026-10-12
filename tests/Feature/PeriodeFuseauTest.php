<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Services\PeriodeInscriptionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lot 3 — fuseau Tunis + bornes : saisie locale, stockage UTC,
 * affichage Tunis, limites exactes.
 */
class PeriodeFuseauTest extends TestCase
{
    use RefreshDatabase;

    private User $scolarite;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Etudiant $etudiant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.fus@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->etudiant = Etudiant::create([
            'cin' => 'FUS00001', 'nom' => 'Benali', 'prenom' => 'Sara', 'email' => 'fus@tunis.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Tunis',
            'code_postal' => '1000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
    }

    public function test_date_simple_couvre_journee_tunis(): void
    {
        $service = app(PeriodeInscriptionService::class);
        $periode = $service->creerNormale([
            'annee_universitaire_id' => $this->annee->id,
            'opened_at' => '2026-09-11', 'closed_at' => '2026-09-11',
        ], $this->scolarite->id);

        // Stockage UTC : 10/09 23:00 → 11/09 22:59:59.
        $this->assertSame('2026-09-10 23:00:00', $periode->opened_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-11 22:59:59', $periode->closed_at->format('Y-m-d H:i:s'));

        // 23:59:59 Tunis (22:59:59 UTC) : OUVERT.
        Carbon::setTestNow('2026-09-11 22:59:59');

        try {
            $this->assertSame('NORMAL_OPEN', $service->evaluer($this->etudiant, $this->annee)['code']);
        } finally {
            Carbon::setTestNow();
        }

        // 00:00:00 lendemain Tunis (23:00 UTC) : FERMÉ.
        Carbon::setTestNow('2026-09-11 23:00:00');

        try {
            $this->assertSame('REGISTRATION_PERIOD_CLOSED', $service->evaluer($this->etudiant, $this->annee)['code']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_23h30_utc_est_00h30_tunis(): void
    {
        $service = app(PeriodeInscriptionService::class);
        $service->creerNormale([
            'annee_universitaire_id' => $this->annee->id,
            'opened_at' => '2026-09-11 08:00', 'closed_at' => '2026-09-11 23:59',
        ], $this->scolarite->id);

        // 23:30 UTC = 00:30 Tunis le 12/09 : FERMÉ.
        Carbon::setTestNow('2026-09-11 23:30:00');

        try {
            $this->assertSame('REGISTRATION_PERIOD_CLOSED', $service->evaluer($this->etudiant, $this->annee)['code']);
        } finally {
            Carbon::setTestNow();
        }

        // 22:00 UTC = 23:00 Tunis le 11/09 : OUVERT.
        Carbon::setTestNow('2026-09-11 22:00:00');

        try {
            $this->assertSame('NORMAL_OPEN', $service->evaluer($this->etudiant, $this->annee)['code']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_affichage_heure_tunis_et_chevauchement(): void
    {
        $service = app(PeriodeInscriptionService::class);
        $periode = $service->creerNormale([
            'annee_universitaire_id' => $this->annee->id,
            'opened_at' => '2026-09-11 08:00', 'closed_at' => '2026-09-11 23:59',
        ], $this->scolarite->id);

        $this->assertSame('11/09/2026 08:00', $service->formatHeure($periode->opened_at));

        // Chevauchement détecté après conversion (même portée).
        try {
            $service->creerNormale([
                'annee_universitaire_id' => $this->annee->id,
                'opened_at' => '2026-09-11 12:00', 'closed_at' => '2026-09-12 12:00',
            ], $this->scolarite->id);
            $this->fail('Chevauchement aurait dû être refusé.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('chevauche', $e->getMessage());
        }

        // Exception complémentaire : autorisée.
        $exception = $service->creerException([
            'annee_universitaire_id' => $this->annee->id,
            'opened_at' => '2026-09-11 12:00', 'closed_at' => '2026-09-12 12:00',
            'motif' => 'Complément officiel de test.',
        ], $this->scolarite->id);
        $this->assertNotNull($exception->id);
    }
}
