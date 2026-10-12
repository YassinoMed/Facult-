<?php

namespace Tests\Unit;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\Semestre;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Services\NormalisationService;
use App\Services\Verification\Anomalie;
use App\Services\Verification\Rules\AffectationGroupeRule;
use App\Services\Verification\Rules\ChronologieRule;
use App\Services\Verification\Rules\DocumentsRequiredRule;
use App\Services\Verification\Rules\IdentiteCoherenceRule;
use App\Services\Verification\Rules\IdentiteCompleteRule;
use App\Services\Verification\Rules\InscriptionCoherenceRule;
use App\Services\Verification\Rules\ParcoursLmdRule;
use App\Services\Verification\Rules\ResultatsCoherenceRule;
use App\Services\Verification\VerificationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerificationRulesTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    protected function setUp(): void
    {
        parent::setUp();
        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences',
            'code' => 'FST',
            'active' => true,
        ]);
    }

    private function creerEtudiantDeBase(array $attributs = []): Etudiant
    {
        $etudiant = Etudiant::create(array_merge([
            'nom' => 'Ben Ali',
            'prenom' => 'Sami',
            'email' => 'sami@test.tn',
            'cin' => '12345678',
            'sexe' => 'M',
            'date_naissance' => '2004-05-15',
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Avenue Habib Bourguiba',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2023',
            'password' => 'Etudiant@123',
            'faculte_id' => $this->faculte->id,
        ], $attributs));

        if (isset($attributs['statut']) && $attributs['statut'] !== 'en_attente') {
            $etudiant->definirStatut($attributs['statut']);
        }

        return $etudiant;
    }

    public function test_r1_identite_complete_detecte_nom_ou_prenom_manquant(): void
    {
        $etudiant = new Etudiant([
            'nom' => null,
            'prenom' => 'Sami',
            'email' => 'sami@test.tn',
            'cin' => '12345678',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'statut' => 'en_attente',
        ]);

        $rule = new IdentiteCompleteRule;
        $anomalies = $rule->verifier($etudiant);

        $this->assertNotEmpty($anomalies);
        $this->assertTrue(collect($anomalies)->contains(fn ($a) => $a->estBloquante() && $a->champ === 'nom'));
    }

    public function test_r2_identite_coherence_detecte_cin_ou_email_invalide(): void
    {
        $etudiant = $this->creerEtudiantDeBase([
            'email' => 'email-non-valide',
            'cin' => '123', // format invalide pour la Tunisie (8 chiffres)
        ]);

        $rule = new IdentiteCoherenceRule(app(NormalisationService::class));
        $anomalies = $rule->verifier($etudiant);

        $this->assertNotEmpty($anomalies);
        $this->assertTrue(collect($anomalies)->contains(fn ($a) => $a->champ === 'cin'));
        $this->assertTrue(collect($anomalies)->contains(fn ($a) => $a->champ === 'email'));
    }

    public function test_r3_inscription_coherence_detecte_etudiant_valide_sans_inscription(): void
    {
        $etudiant = $this->creerEtudiantDeBase();
        $etudiant->definirStatut('valide');

        $rule = new InscriptionCoherenceRule;
        $anomalies = $rule->verifier($etudiant);

        $this->assertNotEmpty($anomalies);
        $this->assertTrue(collect($anomalies)->contains(fn ($a) => $a->estBloquante() && str_contains($a->message, 'sans aucune inscription')));
    }

    public function test_r4_parcours_lmd_detecte_l2_sans_historique_l1(): void
    {
        $programme = Programme::create([
            'code' => 'INFO',
            'nom' => 'Informatique',
            'faculte_id' => $this->faculte->id,
        ]);
        $l2 = Niveau::create(['code' => 'L2', 'nom' => 'Licence 2', 'ordre' => 2, 'programme_id' => $programme->id]);

        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $etudiant = $this->creerEtudiantDeBase([
            'programme_id' => $programme->id,
            'niveau_id' => $l2->id,
        ]);
        $etudiant->definirStatut('valide');

        InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $annee->id,
            'programme_id' => $programme->id,
            'niveau_id' => $l2->id,
            'niveau' => 'L2',
            'statut' => 'valide',
        ]);

        $rule = new ParcoursLmdRule;
        $anomalies = $rule->verifier($etudiant);

        $this->assertNotEmpty($anomalies);
        $this->assertTrue(collect($anomalies)->contains(fn ($a) => str_contains($a->message, 'L1 préalable')));
    }

    public function test_r5_documents_required_detecte_absence_pieces_officielles(): void
    {
        $etudiant = $this->creerEtudiantDeBase();
        $etudiant->definirStatut('valide');

        $rule = new DocumentsRequiredRule;
        $anomalies = $rule->verifier($etudiant);

        $this->assertNotEmpty($anomalies);
        $this->assertTrue(collect($anomalies)->contains(fn ($a) => str_contains($a->message, 'document') || str_contains($a->message, 'pièce')));
    }

    public function test_r7_chronologie_detecte_date_future_ou_age_improbable(): void
    {
        $etudiant = $this->creerEtudiantDeBase([
            'date_naissance' => now()->addYear()->format('Y-m-d'),
        ]);

        $rule = new ChronologieRule;
        $anomalies = $rule->verifier($etudiant);

        $this->assertNotEmpty($anomalies);
        $this->assertTrue(collect($anomalies)->contains(fn ($a) => $a->estBloquante() && $a->champ === 'date_naissance'));
    }

    public function test_r8_affectation_groupe_detecte_incoherence_programme(): void
    {
        $progA = Programme::create(['code' => 'INFO', 'nom' => 'Informatique', 'faculte_id' => $this->faculte->id]);
        $progB = Programme::create(['code' => 'GEST', 'nom' => 'Gestion', 'faculte_id' => $this->faculte->id]);

        $groupeB = Group::create([
            'code' => 'G-GEST-1',
            'nom' => 'Groupe Gestion',
            'programme_id' => $progB->id,
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'capacite' => 30,
        ]);

        $etudiant = $this->creerEtudiantDeBase([
            'programme_id' => $progA->id,
            'groupe_id' => $groupeB->id,
        ]);
        $etudiant->definirStatut('valide');

        $rule = new AffectationGroupeRule;
        $anomalies = $rule->verifier($etudiant);

        $this->assertNotEmpty($anomalies);
        $this->assertTrue(collect($anomalies)->contains(fn ($a) => $a->champ === 'groupe_id'));
    }

    public function test_r10_resultats_coherence_detecte_notes_hors_bornes(): void
    {
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $etudiant = $this->creerEtudiantDeBase();
        $etudiant->definirStatut('valide');

        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $annee->id,
            'niveau' => 'L1',
            'statut' => 'valide',
        ]);

        $semestre = Semestre::create([
            'inscription_academique_id' => $inscription->id,
            'numero' => 1,
        ]);

        $matiere = Matiere::create([
            'code' => 'MAT101',
            'nom' => 'Mathématiques',
            'niveau' => 'L1',
            'semestre' => 1,
            'coefficient' => 1.0,
            'credits' => 3,
        ]);

        // Donnée historiquement invalide (pré-CHECK D-004) : le contrôle
        // informatif doit la détecter. PRAGMA test-only, SQLite uniquement
        // (les suites tournent en sqlite ; MySQL l'interdirait aussi).
        if (\DB::getDriverName() === 'sqlite') {
            \DB::statement('PRAGMA ignore_check_constraints=ON');
        }

        Note::create([
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'semestre_id' => $semestre->id,
            'matiere_id' => $matiere->id,
            'note' => 25.5, // Note impossible (> 20)
            'coefficient' => 1.0,
            'credits' => 3,
            'session' => 'normale',
        ]);

        if (\DB::getDriverName() === 'sqlite') {
            \DB::statement('PRAGMA ignore_check_constraints=OFF');
        }

        $rule = new ResultatsCoherenceRule;
        $anomalies = $rule->verifier($etudiant);

        $this->assertNotEmpty($anomalies);
        $this->assertTrue(collect($anomalies)->contains(fn ($a) => $a->estBloquante() && $a->champ === 'note'));
    }

    public function test_verification_result_calcul_score_et_format_standard(): void
    {
        $anomalies = [
            new Anomalie(
                regle: 'identite.complete',
                categorie: 'identite',
                gravite: 'bloquant',
                message: 'Nom manquant',
                justification: 'Nom est null',
                recommandation: 'Renseigner le nom',
                champ: 'nom'
            ),
            new Anomalie(
                regle: 'documents.requis',
                categorie: 'document',
                gravite: 'avertissement',
                message: 'CIN manquant',
                justification: 'Document non déposé',
                recommandation: 'Déposer le CIN',
            ),
        ];

        $resultat = new VerificationResult(
            verdict: VerificationResult::calculerVerdict($anomalies),
            anomalies: $anomalies,
            reglesExecutees: ['identite.complete', 'documents.requis', 'parcours.lmd'],
            dureeMs: 12.5,
            etudiantId: 42
        );

        $this->assertSame(VerificationResult::BLOQUANT, $resultat->verdict);
        $this->assertTrue($resultat->estBloquant());
        $this->assertLessThanOrEqual(49, $resultat->score);
        $this->assertSame(3, $resultat->rulesChecked);
        $this->assertSame(1, $resultat->rulesPassed);

        $array = $resultat->toArray();
        $this->assertSame('BLOQUANT', $array['verdict']);
        $this->assertSame(42, $array['student_id']);
        $this->assertCount(2, $array['anomalies']);
        $this->assertContains('Renseigner le nom', $array['recommendations']);
    }
}
