<?php

namespace Tests\Feature;

use App\Models\DossierCorrection;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\MensReference;
use App\Models\User;
use App\Services\Verification\VerificationEngine;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * B5 (LOT 1) : workflow de résolution des divergences MENS —
 * la règle `mens.divergences` SURFACES, la RÉSOLUTION passe par le
 * workflow 4-eyes existant ; jamais d'écrasement automatique du local.
 */
class MensDivergencesWorkflowTest extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    private Etudiant $etudiant;

    private User $proposeur;

    private User $approbateur;

    protected function setUp(): void
    {
        parent::setUp();

        $faculte = Faculte::create(['nom' => 'FA', 'code' => 'FA', 'description' => 'T', 'active' => true]);
        $this->etudiant = Etudiant::create([
            'cin' => 'MN000001', 'nom' => 'Benali', 'prenom' => 'Yassine',
            'email' => uniqid('mens.', true).'@example.com', 'sexe' => 'M',
            'date_naissance' => '2004-03-15',
            'faculte_id' => $faculte->id, 'niveau' => 'L1', 'annee_academique' => '2026-2027',
            'adresse' => '1 rue', 'ville' => 'Sfax', 'code_postal' => '3000',
            'annee_bac' => '2021', 'password' => 'motdepasse1',
        ]);
        $this->proposeur = $this->scolarite('admin_scolarite');
        $this->approbateur = User::creerCompteBackOffice([
            'name' => 'Second', 'email' => 'deux.'.uniqid().'@scolarite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    private function mensNom(string $nom): void
    {
        MensReference::create([
            'cin_normalise' => 'MN000001', 'cin_original' => 'MN000001', 'date_naissance' => '2004-03-15',
            'nom' => $nom, 'prenom' => 'Yassine', 'nom_ar' => 'بن علي', 'sexe' => 'M',
            'nationalite' => 'Tunisienne', 'matricule_national' => 'MAT-1',
            'annee' => '2026-2027', 'niveau' => 'L1',
            'source_hash' => str_repeat('a', 64),
        ]);
    }

    private function anomaliesMens(): array
    {
        $resultat = app(VerificationEngine::class)->verifier($this->etudiant->fresh());

        return array_values(array_filter(
            $resultat->anomalies, fn ($a) => $a->regle === 'mens.divergences'
        ));
    }

    public function test_divergence_nom_surfacee_comme_a_verifier_sans_eversement(): void
    {
        $this->mensNom('Ben Ali');

        $anomalies = $this->anomaliesMens();
        $this->assertCount(1, $anomalies);
        $this->assertSame('avertissement', $anomalies[0]->gravite);
        $this->assertTrue(! $anomalies[0]->estBloquante());

        // Jamais d'écrasement automatique : la valeur locale est inchangée.
        $this->assertSame('Benali', $this->etudiant->fresh()->nom);
    }

    public function test_conflit_identite_deux_dossiers_meme_cin_bloquant(): void
    {
        $this->mensNom('Benali');

        // Le conflit « deux dossiers locaux, même CIN » (gravite BLOQUANT)
        // ne peut pas être produit en écriture : contrainte unique BD.
        $this->expectException(QueryException::class);

        Etudiant::create([
            'cin' => 'MN000001', 'nom' => 'Benali2', 'prenom' => 'Yassine',
            'email' => uniqid('dup.', true).'@example.com', 'sexe' => 'M',
            'date_naissance' => '2004-03-15',
            'faculte_id' => $this->etudiant->faculte_id, 'niveau' => 'L1', 'annee_academique' => '2026-2027',
            'adresse' => '1 rue', 'ville' => 'Sfax', 'code_postal' => '3000',
            'annee_bac' => '2021', 'password' => 'motdepasse1',
        ]);
    }

    public function test_resolution_par_quatre_yeux_efface_la_divergence(): void
    {
        $this->mensNom('Ben Ali');

        $this->assertNotEmpty($this->anomaliesMens());

        // Proposer la correction (valeur MENS retenue par le jury).
        $this->actingAs($this->proposeur)->post(route('admin.verification.proposer', $this->etudiant), [
            'champ' => 'nom', 'nouvelle_valeur' => 'Ben Ali',
            'motif' => 'Le MENS national fait foi sur l’identité (nom court).',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $correction = DossierCorrection::where('etudiant_id', $this->etudiant->id)
            ->where('champ', 'nom')->firstOrFail();

        // Même agent refusé (4-eyes), approbateur distinct OK.
        $this->actingAs($this->proposeur)->post(route('admin.verification.decider', $correction), [
            'decision' => 'approuver',
        ])->assertForbidden();

        $this->actingAs($this->approbateur)->post(route('admin.verification.decider', $correction), [
            'decision' => 'approuver',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($this->approbateur)->post(route('admin.verification.decider', $correction), [
            'decision' => 'appliquer',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Ben Ali', $this->etudiant->fresh()->nom);

        // Re-vérification après correction : plus d'anomalie MENS.
        $this->assertEmpty($this->anomaliesMens());
    }

    public function test_pas_de_mens_reference_aucune_anomalie(): void
    {
        $this->assertEmpty($this->anomaliesMens());
    }
}
