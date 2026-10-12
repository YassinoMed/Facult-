<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DossierAcademique;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\Semestre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * Inscriptions académiques : création (transaction inscription + S1/S2),
 * unicité par année, mise à jour, suppression en cascade, journalisation.
 */
class AcademicRegistrationTest extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    private function annee(): AnneeUniversitaire
    {
        return AnneeUniversitaire::firstOrCreate(
            ['libelle' => '2025-2026'],
            ['date_debut' => '2025-09-01', 'date_fin' => '2026-07-15', 'active' => true],
        );
    }

    private function dossier(): DossierAcademique
    {
        $etudiant = $this->etudiantAvecDossier();

        return DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-REG-1',
            'date_archivage' => now()->toDateString(),
        ]);
    }

    public function test_creation_cree_inscription_et_ses_deux_semestres(): void
    {
        $this->scolarite('admin_scolarite');
        $dossier = $this->dossier();
        $annee = $this->annee();

        $response = $this->post(route('admin.archives.inscriptions.store', $dossier), [
            'annee_universitaire_id' => $annee->id,
            'niveau' => 'L1',
            'filiere' => 'Sciences & Techniques',
            'groupe' => 'G1',
        ]);

        $inscription = InscriptionAcademique::where('etudiant_id', $dossier->etudiant_id)->first();

        $response->assertRedirect(route('admin.archives.show', $dossier));
        $this->assertNotNull($inscription);
        $this->assertEquals('en_cours', $inscription->statut);

        // Les deux semestres sont créés dans la même transaction
        $this->assertEquals([1, 2], $inscription->semestres()->orderBy('numero')->pluck('numero')->all());

        $this->assertDatabaseHas('historique_academique', [
            'action' => HistoriqueAcademique::ACTION_CREATION_INSCRIPTION,
        ]);
    }

    public function test_double_inscription_pour_la_meme_annee_refusee(): void
    {
        $this->scolarite('super_admin');
        $dossier = $this->dossier();
        $annee = $this->annee();

        InscriptionAcademique::create([
            'etudiant_id' => $dossier->etudiant_id,
            'annee_universitaire_id' => $annee->id,
            'niveau' => 'L1',
        ]);

        $this->post(route('admin.archives.inscriptions.store', $dossier), [
            'annee_universitaire_id' => $annee->id,
            'niveau' => 'L2',
        ])->assertSessionHasErrors('annee_universitaire_id');

        $this->assertDatabaseCount('inscriptions_academiques', 1);
    }

    public function test_validation_stricte_de_la_creation(): void
    {
        $this->scolarite('super_admin');
        $dossier = $this->dossier();

        $this->post(route('admin.archives.inscriptions.store', $dossier), [
            'niveau' => 'XYZ', // niveau invalide
        ])->assertSessionHasErrors(['annee_universitaire_id', 'niveau']);

        $this->assertDatabaseCount('inscriptions_academiques', 0);
    }

    public function test_mise_a_jour_et_journalisation(): void
    {
        $this->scolarite('admin_scolarite');
        $dossier = $this->dossier();
        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $dossier->etudiant_id,
            'annee_universitaire_id' => $this->annee()->id,
            'niveau' => 'L1',
        ]);

        $this->patch(route('admin.archives.inscriptions.update', $inscription), [
            'niveau' => 'L1',
            'statut' => 'valide',
            'filiere' => 'Sciences Économiques',
        ])->assertRedirect();

        $this->assertDatabaseHas('inscriptions_academiques', [
            'id' => $inscription->id,
            'statut' => 'valide',
            'filiere' => 'Sciences Économiques',
        ]);

        $this->assertDatabaseHas('historique_academique', [
            'action' => HistoriqueAcademique::ACTION_MODIFICATION_INSCRIPTION,
        ]);
    }

    public function test_moyennes_ne_sont_pas_modifiables_directement(): void
    {
        $this->scolarite('super_admin');
        $dossier = $this->dossier();
        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $dossier->etudiant_id,
            'annee_universitaire_id' => $this->annee()->id,
            'niveau' => 'L1',
        ]);

        // Tentative d'injection directe : moyenne_annuelle et credits
        // ne sont pas dans les règles validées → ignorés
        $this->patch(route('admin.archives.inscriptions.update', $inscription), [
            'niveau' => 'L1',
            'statut' => 'valide',
            'moyenne_annuelle' => 20,
            'credits_obtenus' => 999,
        ]);

        $this->assertDatabaseHas('inscriptions_academiques', [
            'id' => $inscription->id,
            'moyenne_annuelle' => null,
            'credits_obtenus' => 0,
        ]);
    }

    public function test_suppression_en_cascade_semestres_et_journalisation(): void
    {
        $this->scolarite('super_admin');
        $dossier = $this->dossier();
        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $dossier->etudiant_id,
            'annee_universitaire_id' => $this->annee()->id,
            'niveau' => 'L1',
        ]);
        $semestre = Semestre::create(['inscription_academique_id' => $inscription->id, 'numero' => 1]);

        $this->delete(route('admin.archives.inscriptions.destroy', $inscription))
            ->assertRedirect(route('admin.archives.show', $dossier));

        $this->assertDatabaseMissing('inscriptions_academiques', ['id' => $inscription->id]);
        $this->assertDatabaseMissing('semestres', ['id' => $semestre->id]);
        $this->assertDatabaseHas('historique_academique', [
            'action' => HistoriqueAcademique::ACTION_SUPPRESSION_INSCRIPTION,
        ]);
    }
}
