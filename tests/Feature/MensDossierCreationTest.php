<?php

namespace Tests\Feature;

use App\Events\StudentRegistered;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\MensReference;
use App\Models\User;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\StudentDossiers\Models\StudentDossier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nouveaux entrants : provisionnement du dossier GED à l'inscription
 * publique + création du dossier facultaire depuis MENS (Cas C/D/G).
 * Compose les services existants, sans second moteur.
 */
class MensDossierCreationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $fst;

    private Faculte $fmp;

    private Programme $programme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fst = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->fmp = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'M', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.creer@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->programme = Programme::create(['faculte_id' => $this->fst->id, 'code' => 'LMI', 'nom' => 'Licence MI']);
    }

    private function creerEtudiant(string $cin, ?int $faculteId = null): Etudiant
    {
        static $compteur = 700;
        $compteur++;

        return Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara', 'email' => "creer{$compteur}@a.test",
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $faculteId ?? $this->fst->id,
            'niveau' => 'L1', 'annee_academique' => '2025-2026', 'adresse' => 'X',
            'ville' => 'V', 'code_postal' => '1', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
    }

    private function creerMens(string $cin, ?string $programmeCode = 'LMI', array $extra = []): MensReference
    {
        return MensReference::create(array_merge([
            'cin_normalise' => $cin, 'cin_original' => $cin, 'date_naissance' => '2004-01-01',
            'nom' => 'Benali', 'prenom' => 'Sara', 'sexe' => 'F', 'annee' => '2025-2026',
            'programme_code' => $programmeCode, 'niveau' => 'L1', 'source_hash' => hash('sha256', $cin),
        ], $extra));
    }

    private function champsDossier(): array
    {
        return [
            'email' => 'nouveau@universite.test', 'adresse' => 'Rue X',
            'ville' => 'Tunis', 'code_postal' => '1000', 'annee_bac' => '2024',
        ];
    }

    public function test_inscription_publique_provisionne_dossier_et_audit(): void
    {
        $etudiant = $this->creerEtudiant('BKMD0001');

        StudentRegistered::dispatch($etudiant);

        $dossier = StudentDossier::where('etudiant_id', $etudiant->id)->firstOrFail();
        $this->assertStringStartsWith('DOS-', $dossier->reference);
        $this->assertDatabaseHas('historique_academique', [
            'action' => 'inscription.dossier_cree',
            'etudiant_id' => $etudiant->id,
            'entite_id' => $dossier->id,
        ]);
    }

    public function test_provisionnement_idempotent_double_evenement(): void
    {
        $etudiant = $this->creerEtudiant('BKMD0002');

        StudentRegistered::dispatch($etudiant);
        StudentRegistered::dispatch($etudiant);

        $this->assertSame(1, StudentDossier::where('etudiant_id', $etudiant->id)->count());
    }

    public function test_mens_sans_dossier_cree_etudiant_et_dossier(): void
    {
        $reference = $this->creerMens('BKMD0010');

        $reponse = $this->actingAs($this->admin)->post(
            route('admin.mens.creer-dossier', $reference), $this->champsDossier()
        );

        $reponse->assertRedirect();
        $etudiant = Etudiant::where('cin', 'BKMD0010')->firstOrFail();
        $this->assertSame('Benali', $etudiant->nom);
        $this->assertSame($this->fst->id, $etudiant->faculte_id);
        $this->assertSame($this->programme->id, $etudiant->programme_id);
        $this->assertSame('en_attente', $etudiant->statut);
        StudentDossier::where('etudiant_id', $etudiant->id)->firstOrFail();
        $this->assertDatabaseHas('historique_academique', [
            'action' => 'inscription.dossier_cree_mens',
            'etudiant_id' => $etudiant->id,
        ]);
    }

    public function test_mens_repost_idempotent_sans_doublon(): void
    {
        $reference = $this->creerMens('BKMD0011');
        $url = route('admin.mens.creer-dossier', $reference);

        $this->actingAs($this->admin)->post($url, $this->champsDossier())->assertRedirect();
        $this->actingAs($this->admin)->post($url, array_merge($this->champsDossier(), [
            'email' => 'autre@universite.test',
        ]))->assertRedirect();

        $this->assertSame(1, Etudiant::where('cin', 'BKMD0011')->count());
    }

    public function test_mens_dossier_existant_redirige_sans_creer(): void
    {
        $this->creerEtudiant('BKMD0012');
        $reference = $this->creerMens('BKMD0012');

        $reponse = $this->actingAs($this->admin)->post(
            route('admin.mens.creer-dossier', $reference), $this->champsDossier()
        );

        $reponse->assertRedirect();
        $this->assertSame(1, Etudiant::where('cin', 'BKMD0012')->count());
    }

    public function test_mens_conflit_cin_bloque_422(): void
    {
        // Même CIN normalisée sur deux dossiers (variante espacée).
        $this->creerEtudiant('BKMD0013');
        $this->creerEtudiant('BKMD 0013');
        $reference = $this->creerMens('BKMD0013');

        $this->actingAs($this->admin)->post(
            route('admin.mens.creer-dossier', $reference), $this->champsDossier()
        )->assertStatus(422);
    }

    public function test_mens_hors_perimetre_404(): void
    {
        $reference = $this->creerMens('BKMD0014', 'MED');
        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.creer@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->fst->id,
        ], 'admin_faculte');

        $this->actingAs($adminFaculte)->post(
            route('admin.mens.creer-dossier', $reference), $this->champsDossier()
        )->assertNotFound();
    }

    public function test_mens_sans_permission_403(): void
    {
        $reference = $this->creerMens('BKMD0015');
        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'agent.creer@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        $this->actingAs($agent)->post(
            route('admin.mens.creer-dossier', $reference), $this->champsDossier()
        )->assertForbidden();
    }

    public function test_mens_champs_requis_422(): void
    {
        $reference = $this->creerMens('BKMD0016');

        $this->actingAs($this->admin)->postJson(
            route('admin.mens.creer-dossier', $reference), []
        )->assertStatus(422);
    }

    public function test_audit_sans_pii(): void
    {
        $reference = $this->creerMens('BKMD0017');
        $this->actingAs($this->admin)->post(route('admin.mens.creer-dossier', $reference), $this->champsDossier());

        $entree = HistoriqueAcademique::where('action', 'inscription.dossier_cree_mens')->firstOrFail();
        $contenu = json_encode([$entree->ancienne_valeur, $entree->nouvelle_valeur]);
        $this->assertStringNotContainsString('BKMD0017', $contenu);
        $this->assertStringNotContainsString('Benali', $contenu);
    }
}
