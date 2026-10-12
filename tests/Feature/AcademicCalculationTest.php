<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\Semestre;
use App\Services\AcademicCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests du service de calcul académique : déterminisme, précision
 * (arithmétique en centièmes entiers), coefficients, crédits, décisions.
 */
class AcademicCalculationTest extends TestCase
{
    use RefreshDatabase;

    private AcademicCalculationService $calculs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculs = new AcademicCalculationService;
    }

    public function test_moyenne_ponderee_exacte_par_coefficients(): void
    {
        // (12×3 + 14×2) / (3+2) = 64/5 = 12.8
        $notes = collect([
            ['note' => 12.00, 'coefficient' => 3, 'credits' => 4],
            ['note' => 14.00, 'coefficient' => 2, 'credits' => 3],
        ]);

        $this->assertEqualsWithDelta(12.8, $this->calculs->moyennePonderee($notes), 0.001);
    }

    public function test_moyenne_ponderee_sans_note_est_nulle(): void
    {
        $this->assertNull($this->calculs->moyennePonderee(collect()));
    }

    public function test_precision_deux_decimales_sans_erreur_de_flottant(): void
    {
        // Cas piégeux : 3 notes à coefficient produisant un arrondi exact
        // (13.33 × 3 + 11.50 × 1 + 16.25 × 2) / 6 = (39.99 + 11.5 + 32.5)/6 = 13.998…
        $notes = collect([
            ['note' => 13.33, 'coefficient' => 3, 'credits' => 4],
            ['note' => 11.50, 'coefficient' => 1, 'credits' => 2],
            ['note' => 16.25, 'coefficient' => 2, 'credits' => 3],
        ]);

        $this->assertEquals(14.0, round($this->calculs->moyennePonderee($notes), 2));
    }

    public function test_note_de_rattrapage_remplace_la_note_normale(): void
    {
        $notes = collect([
            ['note' => 6.00, 'coefficient' => 2, 'credits' => 4, 'matiere_id' => 1, 'session' => 'normale'],
            ['note' => 11.00, 'coefficient' => 2, 'credits' => 4, 'matiere_id' => 1, 'session' => 'rattrapage'],
            ['note' => 15.00, 'coefficient' => 2, 'credits' => 3, 'matiere_id' => 2, 'session' => 'normale'],
        ]);

        $validees = $this->calculs->notesValideesParMatiere($notes);

        $this->assertCount(2, $validees);
        $this->assertEquals(11.00, (float) $validees->firstWhere('matiere_id', 1)['note']);
    }

    public function test_credits_obtenus_comptent_uniquement_les_matieres_validées(): void
    {
        $notes = collect([
            ['note' => 12.00, 'coefficient' => 2, 'credits' => 4],
            ['note' => 8.50, 'coefficient' => 2, 'credits' => 3],
            ['note' => 10.00, 'coefficient' => 1, 'credits' => 2],
        ]);

        // 4 + 2 crédits (matières >= 10), pas les 3 de la matière à 8.5
        $this->assertEquals(6, $this->calculs->creditsObtenus($notes));
    }

    public function test_decisions_academiques_par_moyenne(): void
    {
        $this->assertEquals('Validé', $this->calculs->decisionSemestre(10.00));
        $this->assertEquals('Validé', $this->calculs->decisionSemestre(15.25));
        $this->assertEquals('Rattrapage', $this->calculs->decisionSemestre(8.00));
        $this->assertEquals('Non validé', $this->calculs->decisionSemestre(5.75));
        $this->assertNull($this->calculs->decisionSemestre(null));
    }

    public function test_decision_annuelle_combine_moyenne_et_credits(): void
    {
        $this->assertEquals('Admis(e)', $this->calculs->decisionAnnuelle(12.5, 30, 30));
        $this->assertEquals('Admis(e) — dettes de crédits', $this->calculs->decisionAnnuelle(12.5, 24, 30));
        $this->assertEquals('Ajourné(e) — rattrapage', $this->calculs->decisionAnnuelle(9.0, 10, 30));
        $this->assertEquals('Ajourné(e)', $this->calculs->decisionAnnuelle(6.0, 0, 30));
    }

    public function test_recalcul_complet_d_une_inscription_en_base(): void
    {
        // Jeu de données : 2 matières L1/S1, 2 matières L1/S2
        $etudiant = Etudiant::create(['cin' => 'CALC0001',  'nom' => 'Calcul', 'prenom' => 'Precis',
            'email' => 'calc.test@example.com', 'sexe' => 'M', 'faculte_id' => $this->faculteId(),
            'niveau' => 'L1', 'annee_academique' => '2025-2026', 'adresse' => 'Rue', 'ville' => 'Rabat',
            'code_postal' => '10000', 'annee_bac' => '2024', 'password' => 'motdepasse1', ]);
        $etudiant->forceFill(['num_inscription' => 'INS-CALC-0001'])->save();

        $annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-07-15', 'active' => true,
        ]);

        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $annee->id, 'niveau' => 'L1',
        ]);

        $s1 = Semestre::create(['inscription_academique_id' => $inscription->id, 'numero' => 1]);
        $s2 = Semestre::create(['inscription_academique_id' => $inscription->id, 'numero' => 2]);

        $m11 = Matiere::create(['code' => 'M101', 'nom' => 'M1', 'coefficient' => 3, 'credits' => 5, 'niveau' => 'L1', 'semestre' => 1]);
        $m12 = Matiere::create(['code' => 'M102', 'nom' => 'M2', 'coefficient' => 1, 'credits' => 2, 'niveau' => 'L1', 'semestre' => 1]);
        $m21 = Matiere::create(['code' => 'M201', 'nom' => 'M3', 'coefficient' => 2, 'credits' => 4, 'niveau' => 'L1', 'semestre' => 2]);
        $m22 = Matiere::create(['code' => 'M202', 'nom' => 'M4', 'coefficient' => 2, 'credits' => 4, 'niveau' => 'L1', 'semestre' => 2]);

        foreach ([[$s1, $m11, 12.0], [$s1, $m12, 8.0], [$s1, $m12, 11.0]] as [$sem, $mat, $note]) {
            Note::create([
                'etudiant_id' => $etudiant->id, 'inscription_academique_id' => $inscription->id,
                'semestre_id' => $sem->id, 'matiere_id' => $mat->id,
                'note' => $note, 'coefficient' => $mat->coefficient, 'credits' => $mat->credits,
                'session' => $note === 11.0 ? 'rattrapage' : 'normale',
            ]);
        }
        foreach ([[$s2, $m21, 15.0], [$s2, $m22, 13.0]] as [$sem, $mat, $note]) {
            Note::create([
                'etudiant_id' => $etudiant->id, 'inscription_academique_id' => $inscription->id,
                'semestre_id' => $sem->id, 'matiere_id' => $mat->id,
                'note' => $note, 'coefficient' => $mat->coefficient, 'credits' => $mat->credits, 'session' => 'normale',
            ]);
        }

        $this->calculs->recalculerInscription($inscription);

        $inscription->refresh();
        $s1->refresh();
        $s2->refresh();

        // S1 : (12×3 + 11×1)/(3+1) = 47/4 = 11.75 → Validé, crédits 5+2 = 7
        $this->assertEquals(11.75, (float) $s1->moyenne);
        $this->assertEquals('Validé', $s1->decision);
        $this->assertEquals(7, $s1->credits_obtenus);
        $this->assertEquals(7, $s1->credits_total);

        // S2 : (15×2 + 13×2)/4 = 14 → Validé, 8 crédits
        $this->assertEquals(14.0, (float) $s2->moyenne);
        $this->assertEquals(8, $s2->credits_obtenus);

        // Année : (11.75×7 + 14×8) / (7+8) = (82.25 + 112)/15 = 12.95
        $this->assertEquals(12.95, (float) $inscription->moyenne_annuelle);
        $this->assertEquals(15, $inscription->credits_obtenus);
        $this->assertEquals(15, $inscription->credits_total);
        $this->assertEquals('Admis(e)', $inscription->decision);
    }

    public function test_moyenne_generale_ponderee_par_credits(): void
    {
        // Année A : 12/20, 30 crédits ; Année B : 15/20, 15 crédits
        // → (12×30 + 15×15)/(30+15) = (360+225)/45 = 13
        $inscriptions = collect([
            new InscriptionAcademique(['moyenne_annuelle' => 12, 'credits_total' => 30, 'credits_obtenus' => 30]),
            new InscriptionAcademique(['moyenne_annuelle' => 15, 'credits_total' => 15, 'credits_obtenus' => 15]),
        ]);

        $this->assertEquals(13.0, $this->calculs->moyenneGenerale($inscriptions));
    }

    public function test_synthese_agrege_le_parcours(): void
    {
        $inscriptions = collect([
            new InscriptionAcademique(['moyenne_annuelle' => 12, 'credits_total' => 30, 'credits_obtenus' => 30, 'statut' => 'valide']),
            new InscriptionAcademique(['moyenne_annuelle' => 14, 'credits_total' => 30, 'credits_obtenus' => 20, 'statut' => 'ajourne']),
        ]);

        $synthese = $this->calculs->synthese($inscriptions);

        $this->assertEquals(13.0, $synthese['moyenne_generale']);
        $this->assertEquals(50, $synthese['credits_obtenus']);
        $this->assertEquals(60, $synthese['credits_total']);
        $this->assertEquals(1, $synthese['annees_validees']);
        $this->assertEquals(2, $synthese['annees_total']);
    }

    private function faculteId(): int
    {
        return Faculte::create([
            'nom' => 'Faculté Test', 'code' => 'FTST', 'description' => 'Test', 'active' => true,
        ])->id;
    }
}
