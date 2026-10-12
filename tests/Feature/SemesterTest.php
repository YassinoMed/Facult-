<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DossierAcademique;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\Semestre;
use App\Services\ArchiveService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * Semestres : création automatique, unicité, relations avec les notes,
 * recalcul des champs (moyenne / crédits / décision).
 */
class SemesterTest extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    public function test_chaque_inscription_possede_exactement_deux_semestres_uniques(): void
    {
        $this->scolarite('super_admin');
        $etudiant = $this->etudiantAvecDossier();
        DossierAcademique::create([
            'etudiant_id' => $etudiant->id, 'matricule' => 'MAT-SEM-1', 'date_archivage' => now()->toDateString(),
        ]);
        $annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-07-15',
        ]);

        // Création via le service : l'inscription arrive avec S1 et S2 (transaction)
        $inscription = app(ArchiveService::class)->creerInscription($etudiant, [
            'annee_universitaire_id' => $annee->id,
            'niveau' => 'L2',
        ]);

        $semestres = $inscription->semestres()->get();

        $this->assertCount(2, $semestres);
        $this->assertEquals([1, 2], $semestres->pluck('numero')->sort()->values()->all());

        // Unicité renforcée en base : impossible d'ajouter un doublon de S1
        $this->expectException(UniqueConstraintViolationException::class);
        Semestre::create(['inscription_academique_id' => $inscription->id, 'numero' => 1]);
    }

    public function test_semestre_recupere_moyenne_et_decision_apres_note(): void
    {
        $this->scolarite('admin_scolarite');
        $etudiant = $this->etudiantAvecDossier();
        DossierAcademique::create([
            'etudiant_id' => $etudiant->id, 'matricule' => 'MAT-SEM-2', 'date_archivage' => now()->toDateString(),
        ]);
        $annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-07-15',
        ]);
        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $annee->id, 'niveau' => 'L1',
        ]);
        $semestre = Semestre::create(['inscription_academique_id' => $inscription->id, 'numero' => 1]);
        $matiere = Matiere::create([
            'code' => 'SEM101', 'nom' => 'Matière Semestre', 'coefficient' => 2, 'credits' => 4,
            'niveau' => 'L1', 'semestre' => 1,
        ]);

        $this->post(route('admin.archives.notes.store', $inscription), [
            'semestre_numero' => 1,
            'matiere_id' => $matiere->id,
            'note' => 14.5,
            'session' => 'normale',
        ])->assertRedirect();

        $semestre->refresh();

        $this->assertEquals(14.5, (float) $semestre->moyenne);
        $this->assertEquals(4, $semestre->credits_obtenus);
        $this->assertEquals(4, $semestre->credits_total);
        $this->assertEquals('Validé', $semestre->decision);
    }

    public function test_les_notes_sont_rattachees_au_bon_semestre(): void
    {
        $this->scolarite('super_admin');
        $etudiant = $this->etudiantAvecDossier();
        DossierAcademique::create([
            'etudiant_id' => $etudiant->id, 'matricule' => 'MAT-SEM-3', 'date_archivage' => now()->toDateString(),
        ]);
        $annee = AnneeUniversitaire::create([
            'libelle' => '2024-2025', 'date_debut' => '2024-09-01', 'date_fin' => '2025-07-15',
        ]);
        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $annee->id, 'niveau' => 'L1',
        ]);
        $s1 = Semestre::create(['inscription_academique_id' => $inscription->id, 'numero' => 1]);
        $s2 = Semestre::create(['inscription_academique_id' => $inscription->id, 'numero' => 2]);
        $m1 = Matiere::create(['code' => 'S101', 'nom' => 'M S1', 'coefficient' => 2, 'credits' => 3, 'niveau' => 'L1', 'semestre' => 1]);
        $m2 = Matiere::create(['code' => 'S201', 'nom' => 'M S2', 'coefficient' => 2, 'credits' => 3, 'niveau' => 'L1', 'semestre' => 2]);

        $this->post(route('admin.archives.notes.store', $inscription), [
            'semestre_numero' => 1, 'matiere_id' => $m1->id, 'note' => 12, 'session' => 'normale',
        ]);
        $this->post(route('admin.archives.notes.store', $inscription), [
            'semestre_numero' => 2, 'matiere_id' => $m2->id, 'note' => 16, 'session' => 'normale',
        ]);

        $this->assertEquals(1, Note::where('semestre_id', $s1->id)->count());
        $this->assertEquals(1, Note::where('semestre_id', $s2->id)->count());
        $this->assertDatabaseHas('notes', ['semestre_id' => $s2->id, 'matiere_id' => $m2->id, 'note' => 16]);
    }
}
