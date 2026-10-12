<?php

namespace Tests\Feature;

use App\Models\AffectationGroupe;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use App\Services\Affectations\AssignmentSimulationService;
use Database\Seeders\FsegsDatasetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dataset FSEGS : structure, intégrité, idempotence, moteur (config
 * réduite pour la vitesse ; mêmes règles métier que le volume réel).
 */
class FsegsDatasetTest extends TestCase
{
    use RefreshDatabase;

    private function petiteConfig(): void
    {
        config(['fsegs-dataset.charge' => 1]);
        config(['fsegs-dataset.cohortes' => [
            ['formation' => 'GESTION', 'niveau' => 'L1', 'groupes' => 2, 'etudiants' => 40, 'cap_min' => 25, 'cap_max' => 26, 'prefixe' => 'GESTION-L1-G'],
            ['formation' => 'IG', 'niveau' => 'L1', 'groupes' => 1, 'etudiants' => 15, 'cap_min' => 20, 'cap_max' => 20, 'prefixe' => 'IG-L1-G'],
        ]]);
    }

    public function test_structure_et_cohérence(): void
    {
        $this->petiteConfig();
        $this->seed(FsegsDatasetSeeder::class);

        $faculte = Faculte::where('code', 'FSEGS')->firstOrFail();

        $this->assertSame(55, Etudiant::where('cin', 'like', 'FSEG%')->count());
        $this->assertSame(3, Group::where('faculte_id', $faculte->id)->where('code', 'like', '%-L1-G%')->count());
        $this->assertTrue(Group::where('code', 'GESTION-L1-G01')->exists());

        // Inscriptions : une par étudiant sur 2026-2027 (hors TEST-001).
        $this->assertGreaterThanOrEqual(50, InscriptionAcademique::count());

        // Affectations via moteur : 15 % + 5 % verrouillées, capacités OK.
        $this->assertGreaterThan(0, AffectationGroupe::where('statut', 'active')->count());
        $this->assertGreaterThan(0, AffectationGroupe::where('verrouille', true)->count());

        foreach (Group::where('faculte_id', $faculte->id)->get() as $groupe) {
            $this->assertLessThanOrEqual(
                (int) $groupe->capacite, $groupe->effectifActuel(), "Capacité dépassée : {$groupe->code}"
            );
        }

        // Scénarios spéciaux identifiables.
        $this->assertSame(12, Etudiant::where('cin', 'like', 'TEST-AFFECT-%')->count());
    }

    public function test_validation_commande_ok(): void
    {
        $this->petiteConfig();
        $this->seed(FsegsDatasetSeeder::class);

        $this->artisan('fsegs:validate-dataset')->assertSuccessful();
    }

    public function test_idempotent_sans_doublons(): void
    {
        $this->petiteConfig();
        $this->seed(FsegsDatasetSeeder::class);

        $etudiants = Etudiant::count();
        $groupes = Group::count();
        $affectations = AffectationGroupe::count();

        $this->seed(FsegsDatasetSeeder::class);

        $this->assertSame($etudiants, Etudiant::count());
        $this->assertSame($groupes, Group::count());
        $this->assertSame($affectations, AffectationGroupe::count());
    }

    public function test_moteur_sur_dataset_sans_écriture(): void
    {
        $this->petiteConfig();
        $this->seed(FsegsDatasetSeeder::class);

        $annee = AnneeUniversitaire::where('libelle', '2026-2027')->firstOrFail();
        $groupes = Group::where('code', 'like', 'GESTION-L1-%')->pluck('id')->all();
        $etudiants = Etudiant::where('cin', 'like', 'FSEG%')->where('niveau', 'L1')->limit(30)->pluck('id')->all();

        $avant = AffectationGroupe::count();
        $auteur = User::creerCompteBackOffice(['name' => 'S', 'email' => 's.fseg@u.test', 'password' => 'Motdepasse@123'], 'admin_scolarite');
        $simulation = app(AssignmentSimulationService::class)->simuler([
            'annee_id' => $annee->id, 'groupe_ids' => $groupes, 'etudiant_ids' => $etudiants,
        ], $auteur->id, null);

        $this->assertSame($avant, AffectationGroupe::count());
        $this->assertGreaterThan(0, $simulation->affectes);
        $this->assertSame('simulee', $simulation->statut);
    }
}
