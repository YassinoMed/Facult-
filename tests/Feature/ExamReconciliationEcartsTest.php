<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\IntegrationLog;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\EvaluationReconciliationEcart;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\ExamContinuiteReconciliationService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * §2.15 — dossier d'écart de réconciliation tri-sources.
 *
 * Preuves exécutées :
 * 1. l'analyse persiste un dossier par divergence, sans doublon ;
 * 2. l'instruction puis la validation exigent DEUX personnes distinctes ;
 * 3. une source numérique modifiée invalide l'instruction (fraîcheur) ;
 * 4. un écart validé n'est jamais rouvert ni supprimé ;
 * 5. la surface HTTP applique permission et périmètre facultaire serveur.
 */
class ExamReconciliationEcartsTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private Faculte $autreFaculte;

    private Examen $examen;

    private Epreuve $epreuve;

    private ExamContinuiteReconciliationService $service;

    private User $adminScolarite;

    private User $adminScolariteTiers;

    private User $adminFaculteA;

    private User $adminFaculteB;

    private User $consultation;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true, 'modules.schedule.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'Faculté Écarts', 'code' => 'FECA', 'active' => true]);
        $this->autreFaculte = Faculte::create(['nom' => 'Faculté Écarts B', 'code' => 'FECB', 'active' => true]);

        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'P-ECA', 'nom' => 'Programme Écarts',
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
            'code' => 'UE-ECA', 'nom' => 'UE Écarts', 'credits' => 6, 'coefficient' => 2,
        ]);
        $matiere = Matiere::create([
            'code' => 'MAT-ECA', 'nom' => 'Module Écarts', 'ue_id' => $ue->id,
            'niveau' => 'L3', 'semestre' => 5, 'credits' => 4, 'actif' => true,
        ]);

        $groupe = Group::create([
            'code' => 'GRP-ECA', 'nom' => 'Groupe Écarts',
            'faculte_id' => $this->faculte->id, 'programme_id' => $programme->id,
            'niveau_id' => $niveau->id, 'niveau' => 'L3', 'capacite' => 24, 'actif' => true,
        ]);

        $salle = Room::create([
            'code' => 'SALLE-ECA', 'nom' => 'Salle Écarts', 'capacite' => 60,
            'type' => 'salle', 'actif' => true,
        ]);

        $session = SessionExamen::create([
            'annee_universitaire_id' => $annee->id, 'code' => 'S-ECA',
            'nom' => 'Session Écarts', 'type' => 'principale',
            'date_debut' => '2027-01-10', 'date_fin' => '2027-01-25', 'statut' => 'open',
        ]);

        $this->examen = Examen::create([
            'code' => 'EX-ECA', 'session_id' => $session->id,
            'annee_universitaire_id' => $annee->id,
            'programme_id' => $programme->id, 'niveau_id' => $niveau->id,
            'semestre_lmd_id' => $semestre->id,
            'libelle' => 'Examen Écarts', 'type' => 'examen', 'statut' => 'scheduled',
        ]);

        $this->epreuve = Epreuve::create([
            'code' => 'EPR-ECA', 'examen_id' => $this->examen->id, 'session_id' => $session->id,
            'annee_universitaire_id' => $annee->id, 'matiere_id' => $matiere->id,
            'groupe_id' => $groupe->id, 'salle_id' => $salle->id,
            'date' => '2027-01-15', 'heure_debut' => '09:00', 'heure_fin' => '11:00',
            'duree_minutes' => 120, 'statut' => 'scheduled', 'capacite_necessaire' => 24,
        ]);

        $this->adminScolarite = $this->creerUtilisateur('sco1.ecarts@univ.test', 'admin_scolarite');
        $this->adminScolariteTiers = $this->creerUtilisateur('sco2.ecarts@univ.test', 'admin_scolarite');
        $this->adminFaculteA = $this->creerUtilisateur('fac.a.ecarts@univ.test', 'admin_faculte', $this->faculte->id);
        $this->adminFaculteB = $this->creerUtilisateur('fac.b.ecarts@univ.test', 'admin_faculte', $this->autreFaculte->id);
        $this->consultation = $this->creerUtilisateur('lecture.ecarts@univ.test', 'consultation');

        $this->service = app(ExamContinuiteReconciliationService::class);
    }

    private function creerUtilisateur(string $email, string $role, ?int $faculteId = null): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Utilisateur '.$email,
            'email' => $email,
            'password' => 'Motdepasse@123',
            'faculte_id' => $faculteId,
        ], $role);
    }

    private function creerCandidat(string $statut, string $matricule): Candidat
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
            'epreuve_id' => $this->epreuve->id,
            'etudiant_id' => $etudiant->id,
            'statut' => $statut,
            'numero_anonyme' => 'ANON-'.$matricule,
        ]);
    }

    private function journaliserPointage(int $candidatId): IntegrationLog
    {
        return IntegrationLog::tracer(
            'evaluation',
            'internal',
            'exam.attendance.pointed',
            'completed',
            null,
            200,
            null,
            null,
            (string) Str::uuid(),
            'CAND-'.$candidatId,
        );
    }

    /**
     * Rapprochement d'une divergence : papier « present » vs serveur « absent ».
     *
     * @return array<string, mixed>
     */
    private function rapprocherDivergence(Candidat $candidat): array
    {
        $this->journaliserPointage($candidat->id);

        return $this->service->rapprocherTriSources([
            'epreuve_id' => $this->epreuve->id,
            'pointages_papier' => [
                ['candidat_id' => $candidat->id, 'statut_papier' => 'present', 'signature_presente' => true],
            ],
        ]);
    }

    private function creerEcart(Candidat $candidat): EvaluationReconciliationEcart
    {
        $resultat = $this->rapprocherDivergence($candidat);
        $persistance = $this->service->persisterEcarts($resultat, $this->adminScolarite->id);

        return $persistance['dossiers']->firstOrFail();
    }

    // ==========================================
    // Service — persistance et anti-doublon
    // ==========================================

    public function test_analyse_persiste_un_dossier_par_divergence_sans_doublon(): void
    {
        $divergent = $this->creerCandidat('absent', 'ECA-DIV');
        $concordant = $this->creerCandidat('present', 'ECA-CON');
        $this->journaliserPointage($concordant->id);

        $resultat = $this->service->rapprocherTriSources([
            'epreuve_id' => $this->epreuve->id,
            'pointages_papier' => [
                ['candidat_id' => $divergent->id, 'statut_papier' => 'present', 'signature_presente' => true],
                ['candidat_id' => $concordant->id, 'statut_papier' => 'present', 'signature_presente' => true],
            ],
        ]);

        $this->assertSame(1, $resultat['divergences_detectees']);

        $premier = $this->service->persisterEcarts($resultat, $this->adminScolarite->id);
        $second = $this->service->persisterEcarts($resultat, $this->adminScolarite->id);

        $this->assertSame(1, $premier['crees']);
        $this->assertSame(0, $premier['existants']);
        $this->assertSame(0, $second['crees']);
        $this->assertSame(1, $second['existants']);

        $this->assertDatabaseCount('evaluation_reconciliation_ecarts', 1);

        $dossier = EvaluationReconciliationEcart::firstOrFail();
        $this->assertSame($divergent->id, $dossier->candidat_id);
        $this->assertSame(EvaluationReconciliationEcart::STATUT_OUVERT, $dossier->statut);
        $this->assertSame('present', $dossier->statut_papier);
        $this->assertSame('absent', $dossier->statut_numerique);
        $this->assertSame($this->adminScolarite->id, $dossier->detecte_par);
        $this->assertNotNull($dossier->detecte_at);
        $this->assertSame(64, strlen((string) $dossier->empreinte_source));

        $this->assertSame(
            1,
            IntegrationLog::where('operation', 'exam.attendance.reconciliation_ecart_ouvert')->count()
        );
    }

    // ==========================================
    // Service — quatre yeux
    // ==========================================

    public function test_instruction_puis_validation_par_deux_personnes_distinctes_appliquent_le_statut(): void
    {
        $candidat = $this->creerCandidat('absent', 'ECA-4Y');
        $ecart = $this->creerEcart($candidat);

        $instruit = $this->service->instruireEcart(
            $ecart,
            $this->adminScolarite->id,
            'present',
            'Émargement papier signé vérifié en commission.',
            'PV-COMMISSION-12'
        );

        $this->assertTrue($instruit->estInstruit());
        $this->assertSame($this->adminScolarite->id, $instruit->instructeur_id);
        $this->assertSame('present', $instruit->statut_retenu);

        // Même personne : refus explicite, la décision reste inchangée.
        try {
            $this->service->validerEcart($instruit, $this->adminScolarite->id);
            $this->fail('L’instructeur ne doit jamais valider son propre écart.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('distinct de l’instructeur', $e->getMessage());
        }

        $this->assertSame(EvaluationReconciliationEcart::STATUT_INSTUIT, $ecart->fresh()->statut);

        $valide = $this->service->validerEcart(
            $ecart,
            $this->adminScolariteTiers->id,
            'Vérification contradictoire effectuée.'
        );

        $this->assertTrue($valide->estValide());
        $this->assertSame($this->adminScolariteTiers->id, $valide->valideur_id);
        $this->assertNotNull($valide->valide_at);

        $candidat->refresh();
        $this->assertSame('present', $candidat->statut);
        $this->assertSame($this->adminScolariteTiers->id, $candidat->decide_par);

        $audit = json_decode((string) $candidat->justification, true);
        $this->assertSame('regularisation_tri_sources_post_panne', $audit['type']);
        $this->assertSame('absent', $audit['ancien_statut']);
        $this->assertSame($this->adminScolarite->id, $audit['instructeur_id']);
        $this->assertSame($this->adminScolariteTiers->id, $audit['valideur_id']);
        $this->assertSame('PV-COMMISSION-12', $audit['reference_preuve']);

        $this->assertSame(
            1,
            IntegrationLog::where('operation', 'exam.attendance.reconciliation_ecart_valide')->count()
        );
    }

    public function test_un_ecart_valide_n_est_jamais_reouvert_ni_duplique(): void
    {
        $candidat = $this->creerCandidat('absent', 'ECA-SCEAU');
        $resultat = $this->rapprocherDivergence($candidat);
        $ecart = $this->service->persisterEcarts($resultat, $this->adminScolarite->id)['dossiers']->firstOrFail();

        $this->service->instruireEcart($ecart, $this->adminScolarite->id, 'present', 'Émargement vérifié.');
        $this->service->validerEcart($ecart, $this->adminScolariteTiers->id);

        // Rejouer la MÊME analyse (instantané identique) ne rouvre pas le
        // dossier et ne crée aucun doublon.
        $rejoue = $this->service->persisterEcarts($resultat, $this->adminScolarite->id);

        $this->assertSame(0, $rejoue['crees']);
        $this->assertSame(1, $rejoue['existants']);
        $this->assertSame(EvaluationReconciliationEcart::STATUT_VALIDE, $ecart->fresh()->statut);
        $this->assertDatabaseCount('evaluation_reconciliation_ecarts', 1);
    }

    public function test_instruction_refusee_si_la_source_numerique_a_change_depuis_la_detection(): void
    {
        $candidat = $this->creerCandidat('absent', 'ECA-FRAIS');
        $ecart = $this->creerEcart($candidat);

        // La base évolue après la détection (autre opérateur, reprise…).
        $candidat->update(['statut' => 'retard']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('a changé depuis la détection');

        $this->service->instruireEcart(
            $ecart,
            $this->adminScolarite->id,
            'present',
            'Instruction sur des faits périmés.'
        );
    }

    public function test_instruction_refusee_pour_un_acteur_sans_permission(): void
    {
        $candidat = $this->creerCandidat('absent', 'ECA-PERM');
        $ecart = $this->creerEcart($candidat);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('habilité');

        $this->service->instruireEcart(
            $ecart,
            $this->consultation->id,
            'present',
            'Tentative de lecture seule.'
        );
    }

    // ==========================================
    // HTTP — permissions et périmètre
    // ==========================================

    public function test_index_http_est_protege_et_liste_les_dossiers_du_perimetre(): void
    {
        $candidat = $this->creerCandidat('absent', 'ECA-HTTP-IDX');
        $ecart = $this->creerEcart($candidat);

        $this->get(route('admin.evaluations.reconciliation.index'))
            ->assertRedirect(route('admin.connexion.show'));

        $this->actingAs($this->consultation)
            ->get(route('admin.evaluations.reconciliation.index'))
            ->assertOk()
            ->assertSee('Dossiers d’écart')
            ->assertSee($ecart->candidat?->etudiant?->nom ?? 'Nom-ECA-HTTP-IDX');

        // Un admin_faculte hors périmètre ne peut pas cibler l'épreuve.
        $this->actingAs($this->adminFaculteB)
            ->get(route('admin.evaluations.reconciliation.index', ['epreuve_id' => $this->epreuve->id]))
            ->assertNotFound();
    }

    public function test_show_http_est_inaccessible_hors_faculte(): void
    {
        $candidat = $this->creerCandidat('absent', 'ECA-HTTP-SHOW');
        $ecart = $this->creerEcart($candidat);

        $this->actingAs($this->adminFaculteB)
            ->get(route('admin.evaluations.reconciliation.show', $ecart))
            ->assertNotFound();

        $this->actingAs($this->adminFaculteA)
            ->get(route('admin.evaluations.reconciliation.show', $ecart))
            ->assertOk()
            ->assertSee('Nature de la divergence');
    }

    public function test_analyse_http_cree_les_dossiers_sans_doublon(): void
    {
        $candidat = $this->creerCandidat('absent', 'ECA-HTTP-ANA');
        $this->journaliserPointage($candidat->id);

        $charge = [
            'epreuve_id' => $this->epreuve->id,
            'statut_papier' => [$candidat->id => 'present'],
            'signatures' => [$candidat->id => '1'],
        ];

        $this->actingAs($this->adminScolarite)
            ->post(route('admin.evaluations.reconciliation.analyser'), $charge)
            ->assertRedirect(route('admin.evaluations.reconciliation.index', ['epreuve_id' => $this->epreuve->id]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('evaluation_reconciliation_ecarts', 1);

        $this->actingAs($this->adminScolarite)
            ->post(route('admin.evaluations.reconciliation.analyser'), $charge)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('evaluation_reconciliation_ecarts', 1);
    }

    public function test_role_lecture_seule_ne_peut_pas_lancer_une_analyse(): void
    {
        $candidat = $this->creerCandidat('absent', 'ECA-HTTP-403');

        $this->actingAs($this->consultation)
            ->post(route('admin.evaluations.reconciliation.analyser'), [
                'epreuve_id' => $this->epreuve->id,
                'statut_papier' => [$candidat->id => 'present'],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('evaluation_reconciliation_ecarts', 0);
    }

    public function test_circuit_http_refuse_l_auto_validation_puis_accepte_un_tiers(): void
    {
        $candidat = $this->creerCandidat('absent', 'ECA-HTTP-4Y');
        $ecart = $this->creerEcart($candidat);

        $this->actingAs($this->adminScolarite)
            ->post(route('admin.evaluations.reconciliation.instruire', $ecart), [
                'statut_retenu' => 'present',
                'motif_instruction' => 'Émargement papier signé et contresigné.',
                'preuve_reference' => 'PV-2026-77',
            ])
            ->assertRedirect(route('admin.evaluations.reconciliation.show', $ecart))
            ->assertSessionHasNoErrors();

        $this->assertSame(EvaluationReconciliationEcart::STATUT_INSTUIT, $ecart->fresh()->statut);

        // Le même acteur tente de valider : erreur métier, aucun changement.
        $this->actingAs($this->adminScolarite)
            ->post(route('admin.evaluations.reconciliation.valider', $ecart), [
                'commentaire_validation' => 'Auto-validation interdite.',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('reconciliation');

        $this->assertSame(EvaluationReconciliationEcart::STATUT_INSTUIT, $ecart->fresh()->statut);
        $this->assertSame('absent', $candidat->fresh()->statut);

        // Une personne distincte valide : le statut est appliqué.
        $this->actingAs($this->adminScolariteTiers)
            ->post(route('admin.evaluations.reconciliation.valider', $ecart), [
                'commentaire_validation' => 'Contrôle contradictoire conforme.',
            ])
            ->assertRedirect(route('admin.evaluations.reconciliation.show', $ecart))
            ->assertSessionHasNoErrors();

        $this->assertSame(EvaluationReconciliationEcart::STATUT_VALIDE, $ecart->fresh()->statut);
        $this->assertSame('present', $candidat->fresh()->statut);
        $this->assertSame($this->adminScolariteTiers->id, $ecart->fresh()->valideur_id);
    }
}
