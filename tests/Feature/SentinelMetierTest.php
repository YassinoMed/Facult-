<?php

namespace Tests\Feature;

use App\Models\AffectationGroupe;
use App\Models\AnneeUniversitaire;
use App\Models\DatabaseBackup;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use App\Modules\Sentinel\Models\SentinelIncident;
use App\Modules\Sentinel\Services\SentinelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Platform Sentinel — incrément 2 : contrôles métier d'intégrité
 * (données réelles, incidents agrégés, sains = silence).
 */
class SentinelMetierTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private int $compteur = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        User::creerCompteBackOffice([
            'name' => 'S', 'email' => 's@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    private function creerEtudiant(array $extra = []): Etudiant
    {
        $this->compteur++;

        $etudiant = Etudiant::create(array_merge([
            'cin' => 'SENTI'.$this->compteur, 'num_inscription' => 'MAT-SENTI'.$this->compteur,
            'nom' => 'Nom'.$this->compteur, 'prenom' => 'Prenom',
            'email' => "senti{$this->compteur}@universite.test",
            'sexe' => 'M', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027',
            'adresse' => 'R', 'ville' => 'Tunis', 'code_postal' => '1000',
            'annee_bac' => '2023', 'password' => 'Secret@123',
        ], $extra));
        $etudiant->definirStatut('valide');
        $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1', 'statut' => 'valide',
        ]);

        return $etudiant;
    }

    private function groupe(string $code, string $niveau, ?int $capacite = 30): Group
    {
        return Group::create([
            'code' => $code, 'nom' => $code, 'faculte_id' => $this->faculte->id,
            'niveau' => $niveau, 'capacite' => $capacite, 'actif' => true,
        ]);
    }

    public function test_sans_groupe_signale_puis_silence_apres_affectation(): void
    {
        $etudiant = $this->creerEtudiant();

        $resultat = app(SentinelService::class)->executer('AFFECT_SANS_GROUPE');

        $this->assertSame(1, $resultat['incidents']);
        $incident = SentinelIncident::where('code', 'AFFECT_SANS_GROUPE')->first();
        $this->assertSame('avertissement', $incident->gravite);
        $this->assertStringContainsString('1 étudiant(s)', $incident->titre);

        $groupe = $this->groupe('L1-G1', 'L1');
        AffectationGroupe::create([
            'etudiant_id' => $etudiant->id, 'groupe_id' => $groupe->id,
            'annee_universitaire_id' => $this->annee->id,
            'date_debut' => now()->toDateString(), 'statut' => 'active',
        ]);
        $etudiant->update(['groupe_id' => $groupe->id]);

        $resultat = app(SentinelService::class)->executer('AFFECT_SANS_GROUPE');

        $this->assertSame(0, $resultat['incidents']);
    }

    public function test_niveau_incompatible_signale(): void
    {
        $etudiant = $this->creerEtudiant();
        $groupe = $this->groupe('L2-G1', 'L2');
        AffectationGroupe::create([
            'etudiant_id' => $etudiant->id, 'groupe_id' => $groupe->id,
            'annee_universitaire_id' => $this->annee->id,
            'date_debut' => now()->toDateString(), 'statut' => 'active',
        ]);

        app(SentinelService::class)->executer('AFFECT_NIVEAU');

        $incident = SentinelIncident::where('code', 'AFFECT_NIVEAU')->first();
        $this->assertNotNull($incident);
        $this->assertSame('erreur', $incident->gravite);
        $this->assertStringContainsString('L2-G1', $incident->diagnostic);
    }

    public function test_surcapacite_signalee_complet_normal_silencieux(): void
    {
        $complet = $this->groupe('L1-FULL', 'L1', 1);
        $sature = $this->groupe('L1-OVER', 'L1', 1);

        foreach ([$this->creerEtudiant(), $this->creerEtudiant()] as $i => $etudiant) {
            $groupe = $i === 0 ? $complet : $sature;
            AffectationGroupe::create([
                'etudiant_id' => $etudiant->id, 'groupe_id' => $groupe->id,
                'annee_universitaire_id' => $this->annee->id,
                'date_debut' => now()->toDateString(), 'statut' => 'active',
            ]);
        }

        // Second étudiant dans le groupe cap 1 → surcapacité 2/1.
        $troisieme = $this->creerEtudiant();
        AffectationGroupe::create([
            'etudiant_id' => $troisieme->id, 'groupe_id' => $sature->id,
            'annee_universitaire_id' => $this->annee->id,
            'date_debut' => now()->toDateString(), 'statut' => 'active',
        ]);

        app(SentinelService::class)->executer('GROUPE_SURCAPACITE');

        $incident = SentinelIncident::where('code', 'GROUPE_SURCAPACITE')->first();
        $this->assertNotNull($incident);
        $this->assertStringContainsString('L1-OVER', $incident->diagnostic);
        $this->assertStringNotContainsString('L1-FULL', $incident->diagnostic);
    }

    public function test_ronde_complete_saine_sans_incident(): void
    {
        User::creerCompteBackOffice([
            'name' => 'Super', 'email' => 'super.sain@universite.test', 'password' => 'Motdepasse@123',
        ], 'super_admin');
        DatabaseBackup::create([
            'uuid' => (string) Str::uuid(), 'filename' => 'db_sain.sql',
            'disk' => 'local', 'path' => sys_get_temp_dir().'/db_sain.sql', 'database_name' => 'test',
            'connexion' => 'mysql', 'status' => 'SUCCESS',
            'started_at' => now(), 'completed_at' => now(),
        ]);

        $resultat = app(SentinelService::class)->executer();

        $this->assertSame([], $resultat['erreurs']);
        $this->assertSame(count((array) config('sentinel.checks')), $resultat['executes']);
        $this->assertSame(0, SentinelIncident::count());
    }
}
