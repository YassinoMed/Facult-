<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Services\ReferentielStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Consolidation LOOP 3 (distinction structure/blocage) : un programme
 * actif SANS maquette active est une anomalie structurelle tant qu'il
 * est en construction, mais un blocage opérationnel dès qu'il porte
 * des consommateurs déjà bloqués par l'absence de plan actif (règle
 * existante des flux `inscrireEcue` / `genererEpreuves` — DomainException
 * explicite). Aucune condition métier inventée.
 */
class ReferentielStatsBlocageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.referentiel.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.blocage@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    private function creerProgramme(string $code): Programme
    {
        return Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => $code, 'nom' => 'Programme '.$code,
            'statut' => 'actif', 'actif' => true,
        ]);
    }

    private function creerInscription(Programme $programme): void
    {
        $etudiant = Etudiant::create([
            'cin' => '90000001', 'num_inscription' => 'INS-B1', 'nom' => 'Blocage', 'prenom' => 'Test',
            'email' => 'blocage@universite.test', 'date_naissance' => '2004-01-15',
            'sexe' => 'M', 'telephone' => '98000111', 'faculte_id' => $this->faculte->id,
            'programme_id' => $programme->id, 'niveau' => 'L1', 'annee_academique' => '2026-2027',
            'adresse' => 'Rue X', 'ville' => 'Tunis', 'code_postal' => '1000',
            'annee_bac' => '2023', 'password' => 'Secret@123',
        ]);
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $annee->id,
            'programme_id' => $programme->id, 'niveau' => 'L1', 'statut' => 'en_cours',
            'type' => 'premiere', 'regime' => 'initiale',
        ]);
    }

    public function test_programme_exploite_sans_maquette_active_est_un_blocage(): void
    {
        $programme = $this->creerProgramme('P-OPS');
        $this->creerInscription($programme);

        $anomalies = app(ReferentielStatsService::class)->anomalies(null, 50);
        $ligne = collect($anomalies)->first(fn (array $a) => $a['entite'] === 'Programme P-OPS' && str_contains($a['probleme'], 'maquette'));

        $this->assertNotNull($ligne, 'Aucune anomalie pour un programme exploité sans maquette active.');
        $this->assertSame('erreur', $ligne['gravite']);
        $this->assertStringContainsString('bloqu', $ligne['probleme']);
    }

    public function test_programme_en_construction_reste_un_avertissement(): void
    {
        $this->creerProgramme('P-DEMO');

        $anomalies = app(ReferentielStatsService::class)->anomalies(null, 50);
        $ligne = collect($anomalies)->first(fn (array $a) => $a['entite'] === 'Programme P-DEMO' && str_contains($a['probleme'], 'maquette'));

        $this->assertNotNull($ligne);
        $this->assertSame('avertissement', $ligne['gravite']);
        $this->assertSame('Aucune maquette active.', $ligne['probleme']);
    }

    public function test_programme_avec_maquette_active_sans_alerte(): void
    {
        $programme = $this->creerProgramme('P-OK');
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        Maquette::create([
            'programme_id' => $programme->id, 'annee_universitaire_id' => $annee->id,
            'version' => 1, 'statut' => 'active',
        ]);

        $anomalies = app(ReferentielStatsService::class)->anomalies(null, 50);

        $this->assertNull(collect($anomalies)->first(
            fn (array $a) => $a['entite'] === 'Programme P-OK' && str_contains($a['probleme'], 'maquette')
        ));
    }

    public function test_anomalies_scopes_faculte(): void
    {
        $faculteB = Faculte::create(['nom' => 'FDL', 'code' => 'FDL', 'active' => true]);
        $programmeAvecExploitation = Programme::create([
            'faculte_id' => $faculteB->id, 'code' => 'P-AUTRE', 'nom' => 'Programme autre',
            'statut' => 'actif', 'actif' => true,
        ]);
        $this->creerInscription($programmeAvecExploitation);

        $anomalies = app(ReferentielStatsService::class)->anomalies($this->faculte->id, 50);

        $this->assertNull(collect($anomalies)->first(fn (array $a) => str_contains($a['entite'], 'P-AUTRE')));
    }

    public function test_anomalies_requetes_groupees_sans_n_plus_1(): void
    {
        // 3 programmes dont 1 exploité : le nombre de requêtes doit
        // rester constant, pas proportionnel aux lignes.
        foreach (['P-B1', 'P-B2', 'P-B3'] as $code) {
            $this->creerProgramme($code);
        }
        $this->creerInscription(Programme::where('code', 'P-B1')->firstOrFail());

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ReferentielStatsService::class)->anomalies(null, 50);
        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(20, $total, "anomalies() doit rester plat ({$total} requêtes).");
    }
}
