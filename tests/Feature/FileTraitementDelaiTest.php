<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Services\FileTraitementService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase C — Délai moyen : sémantique verrouillée (définition métier exacte).
 */
class FileTraitementDelaiTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private User $scolarite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteA = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'FMT', 'code' => 'FMT', 'active' => true]);
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.del@test.tn', 'password' => 'Secret123!',
        ], 'admin_scolarite');
    }

    private function dossier(int $faculteId, ?Carbon $creeLe, string $statut = 'en_attente'): Etudiant
    {
        static $n = 1;

        $e = Etudiant::create([
            'nom' => 'NomD'.$n, 'prenom' => 'P', 'email' => "d{$n}@test.tn",
            'cin' => sprintf('730000%02d', $n++), 'sexe' => 'M', 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'adr', 'ville' => 'Tunis',
            'code_postal' => '1000', 'annee_bac' => '2025', 'faculte_id' => $faculteId,
        ]);
        if ($statut !== 'en_attente') {
            $e->definirStatut($statut);
        }
        if ($creeLe !== null) {
            $e->timestamps = false;
            $e->created_at = $creeLe;
            $e->save();
        }

        return $e;
    }

    private function kpis(?int $faculteId = null): array
    {
        return app(FileTraitementService::class)->kpis($this->scolarite, $faculteId);
    }

    public function test_delai_zero_sans_dossier_en_attente(): void
    {
        $this->dossier($this->faculteA->id, Carbon::now()->subDay(), 'valide');

        $kpis = $this->kpis($this->faculteA->id);

        $this->assertSame(0, $kpis['delai_moyen_heures']);
        $this->assertSame(0.0, $kpis['delai_moyen_jours']);
    }

    public function test_delai_un_seul_dossier(): void
    {
        $this->dossier($this->faculteA->id, Carbon::now()->subHours(30));

        $kpis = $this->kpis($this->faculteA->id);

        $this->assertSame(30, $kpis['delai_moyen_heures']);
        $this->assertSame(1.3, $kpis['delai_moyen_jours']);
    }

    public function test_delai_moyenne_plusieurs_durees(): void
    {
        $this->dossier($this->faculteA->id, Carbon::now()->subHours(10));
        $this->dossier($this->faculteA->id, Carbon::now()->subHours(50));
        $this->dossier($this->faculteA->id, Carbon::now()->subHours(72));

        $kpis = $this->kpis($this->faculteA->id);

        // (10+50+72)/3 = 44.
        $this->assertSame(44, $kpis['delai_moyen_heures']);
    }

    public function test_delai_ignore_termines_et_isole_facultes(): void
    {
        $this->dossier($this->faculteA->id, Carbon::now()->subHours(100), 'valide');
        $this->dossier($this->faculteA->id, Carbon::now()->subHours(20));
        $this->dossier($this->faculteB->id, Carbon::now()->subHours(200));

        $kpisA = $this->kpis($this->faculteA->id);
        $kpisB = $this->kpis($this->faculteB->id);

        $this->assertSame(20, $kpisA['delai_moyen_heures']);
        $this->assertSame(200, $kpisB['delai_moyen_heures']);
    }

    public function test_delai_tolerance_created_at_nul(): void
    {
        $e = $this->dossier($this->faculteA->id, Carbon::now()->subHours(24));
        $e->timestamps = false;
        $e->created_at = null;
        $e->save();
        $this->dossier($this->faculteA->id, Carbon::now()->subHours(24));

        // Ligne nulle = 0h mais comptée au dénominateur : (0+24)/2 = 12.
        $kpis = $this->kpis($this->faculteA->id);

        $this->assertSame(12, $kpis['delai_moyen_heures']);
    }

    public function test_delai_sans_hydratation_complete(): void
    {
        $this->dossier($this->faculteA->id, Carbon::now()->subHours(5));
        $this->dossier($this->faculteA->id, Carbon::now()->subHours(9));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $kpis = $this->kpis($this->faculteA->id);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(7, $kpis['delai_moyen_heures']);

        // Aucune requête ne doit charger toutes les colonnes des dossiers pour ce calcul.
        foreach ($log as $entree) {
            $sql = strtolower((string) ($entree['query'] ?? ''));
            if (str_contains($sql, 'from "etudiants"') && ! str_contains($sql, 'count')) {
                $this->assertStringNotContainsString('select *', $sql);
            }
        }
    }
}
