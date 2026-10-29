<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\IntegrationLog;
use App\Models\Matiere;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\ExamContinuiteReconciliationService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * §2.15 — réconciliation tri-sources : papier, numérique, journal horodaté.
 *
 * Ces preuves portent sur la CORRECTION du rapprochement, pas sur une
 * réécriture du périmètre : les écarts bloquent toujours la validation, et
 * les sources muettes sont désormais distinguées des divergences.
 */
class ExamReconciliationTriSourcesTest extends TestCase
{
    use RefreshDatabase;

    private Examen $examen;

    private Epreuve $epreuve;

    private Epreuve $autreEpreuve;

    private ExamContinuiteReconciliationService $service;

    private Faculte $faculte;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true, 'modules.schedule.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'Faculté Réconciliation', 'code' => 'FREC', 'active' => true]);
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'P-REC', 'nom' => 'Programme Réconciliation',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niveau = Niveau::create([
            'programme_id' => $programme->id, 'code' => 'L3', 'nom' => 'Licence 3', 'ordre' => 3,
        ]);
        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id, 'code' => 'S5', 'nom' => 'S5', 'numero' => 5, 'ordre' => 5,
        ]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $programme->id,
            'code' => 'UE-REC', 'nom' => 'UE Réconciliation', 'credits' => 6, 'coefficient' => 2,
        ]);
        $matiere = Matiere::create([
            'code' => 'MAT-REC', 'nom' => 'Module Réconciliation', 'ue_id' => $ue->id,
            'niveau' => 'L3', 'semestre' => 5, 'credits' => 4, 'actif' => true,
        ]);

        $groupe = Group::create([
            'code' => 'GRP-REC', 'nom' => 'Groupe Réconciliation',
            'faculte_id' => $this->faculte->id, 'programme_id' => $programme->id,
            'niveau_id' => $niveau->id, 'niveau' => 'L3', 'capacite' => 24, 'actif' => true,
        ]);

        $salle = Room::create([
            'code' => 'SALLE-REC', 'nom' => 'Salle Réconciliation', 'capacite' => 60,
            'type' => 'salle', 'actif' => true,
        ]);

        $session = SessionExamen::create([
            'annee_universitaire_id' => $annee->id, 'code' => 'S-REC',
            'nom' => 'Session Réconciliation', 'type' => 'principale',
            'date_debut' => '2027-01-10', 'date_fin' => '2027-01-25', 'statut' => 'open',
        ]);

        $this->examen = Examen::create([
            'code' => 'EX-REC', 'session_id' => $session->id,
            'annee_universitaire_id' => $annee->id,
            'programme_id' => $programme->id, 'niveau_id' => $niveau->id,
            'semestre_lmd_id' => $semestre->id,
            'libelle' => 'Examen Réconciliation', 'type' => 'examen', 'statut' => 'scheduled',
        ]);

        $this->epreuve = Epreuve::create([
            'code' => 'EPR-REC', 'examen_id' => $this->examen->id, 'session_id' => $session->id,
            'annee_universitaire_id' => $annee->id, 'matiere_id' => $matiere->id,
            'groupe_id' => $groupe->id, 'salle_id' => $salle->id,
            'date' => '2027-01-15', 'heure_debut' => '09:00', 'heure_fin' => '11:00',
            'duree_minutes' => 120, 'statut' => 'scheduled', 'capacite_necessaire' => 24,
        ]);

        $this->autreEpreuve = Epreuve::create([
            'code' => 'EPR-REC-B', 'examen_id' => $this->examen->id, 'session_id' => $session->id,
            'annee_universitaire_id' => $annee->id, 'matiere_id' => $matiere->id,
            'groupe_id' => $groupe->id, 'salle_id' => $salle->id,
            'date' => '2027-01-16', 'heure_debut' => '09:00', 'heure_fin' => '11:00',
            'duree_minutes' => 120, 'statut' => 'scheduled', 'capacite_necessaire' => 24,
        ]);

        $this->service = app(ExamContinuiteReconciliationService::class);
    }

    private function creerCandidat(Epreuve $epreuve, string $statut, string $matricule): Candidat
    {
        $etudiant = Etudiant::create([
            'num_inscription' => $matricule,
            'email' => strtolower($matricule).'@etudiant.universite.test',
            'nom' => 'Nom-'.$matricule,
            'prenom' => 'Prenom-'.$matricule,
            'cin' => 'CIN-'.$matricule,
            'date_naissance' => '2004-05-10',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L3',
            'annee_academique' => '2026-2027',
            'adresse' => 'Campus Universitaire',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2024',
        ]);

        return Candidat::create([
            'epreuve_id' => $epreuve->id,
            'etudiant_id' => $etudiant->id,
            'statut' => $statut,
            'numero_anonyme' => 'ANON-'.$matricule,
        ]);
    }

    private function journaliserPointage(int $candidatId, string $operation = 'exam.attendance.pointed'): IntegrationLog
    {
        return IntegrationLog::tracer(
            'evaluation',
            'internal',
            $operation,
            'completed',
            null,
            200,
            null,
            null,
            (string) Str::uuid(),
            'CAND-'.$candidatId,
        );
    }

    /** Concordance papier/numérique mais journal muet : réserve, pas blocage. */
    public function test_concordance_sans_trace_journal_est_une_reserve_non_un_blocage(): void
    {
        $candidat = $this->creerCandidat($this->epreuve, 'present', 'REC-1');

        $resultat = $this->service->rapprocherTriSources([
            'epreuve_id' => $this->epreuve->id,
            'pointages_papier' => [
                ['candidat_id' => $candidat->id, 'statut_papier' => 'present', 'signature_presente' => true],
            ],
        ]);

        $this->assertFalse($resultat['validation_bloquee']);
        $this->assertSame(0, $resultat['divergences_detectees']);
        $this->assertSame(1, $resultat['concordances']);
        $this->assertFalse($resultat['reconciliation_complete']);
        $this->assertSame(1, $resultat['reserves_detectees']);
        $this->assertSame('trace_journal_absente', $resultat['reserves'][0]['type']);
        $this->assertSame(['papier', 'numerique', 'journal'], $resultat['sources_comparees']);
    }

    /** Les trois sources présentes et concordantes : réconciliation complète. */
    public function test_trois_sources_concordantes_rendent_la_reconciliation_complete(): void
    {
        $candidat = $this->creerCandidat($this->epreuve, 'present', 'REC-2');

        $this->journaliserPointage($candidat->id);

        $resultat = $this->service->rapprocherTriSources([
            'epreuve_id' => $this->epreuve->id,
            'pointages_papier' => [
                ['candidat_id' => $candidat->id, 'statut_papier' => 'present', 'signature_presente' => true],
            ],
        ]);

        $this->assertTrue($resultat['reconciliation_complete']);
        $this->assertSame(0, $resultat['reserves_detectees']);
        $this->assertSame(0, $resultat['divergences_detectees']);
    }

    /** Présence papier sans signature : réserve explicite, jamais divergence. */
    public function test_presence_papier_sans_signature_est_une_reserve(): void
    {
        $candidat = $this->creerCandidat($this->epreuve, 'present', 'REC-3');

        $this->journaliserPointage($candidat->id);

        $resultat = $this->service->rapprocherTriSources([
            'epreuve_id' => $this->epreuve->id,
            'pointages_papier' => [
                ['candidat_id' => $candidat->id, 'statut_papier' => 'present', 'signature_presente' => false],
            ],
        ]);

        $this->assertFalse($resultat['validation_bloquee']);
        $this->assertFalse($resultat['reconciliation_complete']);
        $this->assertSame('signature_absente', $resultat['reserves'][0]['type']);
    }

    /**
     * Le journal est borné au candidat exact : la trace de « CAND-11 » ne doit
     * jamais être attribuée à « CAND-1 » (collision de préfixe), ni provenir
     * d'une autre épreuve.
     */
    public function test_le_journal_n_est_pas_attribue_par_collision_de_prefixe(): void
    {
        // Identifiants réels : le candidat de l'épreuve réconciliée doit
        // porter un identifiant qui soit un PRÉFIXE de celui d'un candidat
        // d'une autre épreuve (1 et 11), sinon le défaut ne serait pas
        // exerçable.
        $candidat = $this->creerCandidat($this->epreuve, 'present', 'REC-PREFIX-1');

        $onzieme = null;
        for ($i = 2; $i <= 11; $i++) {
            $autre = $this->creerCandidat($this->autreEpreuve, 'present', 'REC-B-'.$i);
            $onzieme = $autre;
        }

        $this->assertSame(1, $candidat->id);
        $this->assertSame(11, $onzieme->id);

        // Trace horodatée du candidat #11 de l'AUTRE épreuve uniquement.
        $this->journaliserPointage($onzieme->id);

        $resultat = $this->service->rapprocherTriSources([
            'epreuve_id' => $this->epreuve->id,
            'pointages_papier' => [
                ['candidat_id' => $candidat->id, 'statut_papier' => 'present', 'signature_presente' => true],
            ],
        ]);

        // Aucune trace ne doit être attribuée : la réserve est bien présente.
        $this->assertSame(1, $resultat['reserves_detectees']);
        $this->assertSame('trace_journal_absente', $resultat['reserves'][0]['type']);
        $this->assertSame($candidat->id, $resultat['reserves'][0]['candidat_id']);

        // Et un écart éventuel n'hérite pas non plus de cette trace.
        $resultatDivergent = $this->service->rapprocherTriSources([
            'epreuve_id' => $this->epreuve->id,
            'pointages_papier' => [
                ['candidat_id' => $candidat->id, 'statut_papier' => 'absent', 'signature_presente' => true],
            ],
        ]);

        $this->assertTrue($resultatDivergent['validation_bloquee']);
        $this->assertNull($resultatDivergent['ecarts'][0]['journal_horodate']);
    }

    /** Une divergence reste bloquante, quel que soit le journal. */
    public function test_divergence_papier_numerique_reste_bloquante(): void
    {
        $candidat = $this->creerCandidat($this->epreuve, 'absent', 'REC-6');

        $this->journaliserPointage($candidat->id);

        $resultat = $this->service->rapprocherTriSources([
            'epreuve_id' => $this->epreuve->id,
            'pointages_papier' => [
                ['candidat_id' => $candidat->id, 'statut_papier' => 'present', 'signature_presente' => true],
            ],
        ]);

        $this->assertTrue($resultat['validation_bloquee']);
        $this->assertFalse($resultat['reconciliation_complete']);
        $this->assertSame(1, $resultat['divergences_detectees']);
        $this->assertNotNull($resultat['ecarts'][0]['journal_horodate']);
    }
}
