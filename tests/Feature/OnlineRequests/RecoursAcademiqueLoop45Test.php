<?php

namespace Tests\Feature\OnlineRequests;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Note;
use App\Models\User;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\OnlineRequests\Models\RequestType;
use App\Modules\OnlineRequests\Services\OnlineRequestService;
use App\Modules\OnlineRequests\Services\RecoursAcademiqueService;
use App\Services\Workflow\Definitions\WorkflowRecoursAcademiqueDefinition;
use App\Support\Permissions;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * LOOP 45 — RECOURS ACADÉMIQUES.
 *
 * Vérifie le circuit complet sur le support `online_requests` :
 * - fail-closed tant que le texte officiel et les types contestables ne sont
 *   pas fournis (aucun délai, aucun type, aucune référence inventés) ;
 * - dépôt étudiant sur une décision publiée, sans rejet automatique même
 *   hors délai ;
 * - recevabilité et instruction humaines, permissions séparées ;
 * - quatre yeux sur toute décision ;
 * - aucune écriture automatique dans les notes ou les décisions ;
 * - application manuelle puis clôture, annulation étudiante bornée ;
 * - le transitionneur générique ne peut jamais faire évoluer un recours.
 */
class RecoursAcademiqueLoop45Test extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private Etudiant $etudiant;

    private Etudiant $autreEtudiant;

    private User $agentScolarite;

    private User $directionEtudes;

    private User $superAdmin;

    private AnneeUniversitaire $annee;

    private Deliberation $deliberation;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.online-requests.active' => true]);

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences et Techniques',
            'code' => 'FST-R45',
            'active' => true,
        ]);

        $this->etudiant = $this->creerEtudiant('R4500001', 'etudiant.recours@univ.test');
        $this->autreEtudiant = $this->creerEtudiant('R4500002', 'autre.recours@univ.test');

        $this->agentScolarite = User::creerCompteBackOffice([
            'name' => 'Agent Scolarité Recours',
            'email' => 'agent.recours@univ.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->directionEtudes = User::creerCompteBackOffice([
            'name' => 'Direction des Études',
            'email' => 'direction.recours@univ.test',
            'password' => 'Motdepasse@123',
        ], 'direction_etudes');

        $this->superAdmin = User::creerCompteBackOffice([
            'name' => 'Super Administrateur',
            'email' => 'super.recours@univ.test',
            'password' => 'Motdepasse@123',
        ], 'super_admin');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026',
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30',
            'active' => true,
        ]);

        $this->deliberation = Deliberation::create([
            'annee_universitaire_id' => $this->annee->id,
            'statut' => 'published',
            'published_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function creerEtudiant(string $cin, string $email): Etudiant
    {
        return Etudiant::create([
            'cin' => $cin,
            'nom' => 'Test',
            'prenom' => 'Recours',
            'email' => $email,
            'telephone' => '+212600000000',
            'date_naissance' => '2004-03-15',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => '12 Rue de la Liberté',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'mention_bac' => 'Bien',
        ]);
    }

    private function configurerCircuit(array $surcharges = []): void
    {
        config(array_merge([
            'recours_academiques.actif' => true,
            'recours_academiques.reference_reglementaire' => 'TEXTE-TEST-RECOURS-2026',
            'recours_academiques.delai_depot_jours' => null,
            'recours_academiques.types_decisions_contestables' => ['ajourne'],
            'recours_academiques.application_automatique_autorisee' => false,
        ], $surcharges));
    }

    private function creerTypeRecours(bool $actif = true): RequestType
    {
        return RequestType::create([
            'code' => (string) config('recours_academiques.type_code'),
            'name' => 'Recours académique',
            'description' => 'Support des recours contre une décision publiée.',
            'active' => $actif,
            'requires_attachment' => false,
            'generates_document' => false,
            'processing_days' => 3,
        ]);
    }

    private function creerDecision(
        Etudiant $etudiant,
        string $decision = 'ajourne',
        bool $publie = true,
        ?Deliberation $deliberation = null
    ): DecisionDeliberation {
        return DecisionDeliberation::create([
            'deliberation_id' => ($deliberation ?? $this->deliberation)->id,
            'etudiant_id' => $etudiant->id,
            'credits_obtenus' => 12,
            'credits_total' => 30,
            'ues_validees' => 2,
            'ues_total' => 5,
            'decision' => $decision,
            'publie' => $publie,
        ]);
    }

    private function service(): RecoursAcademiqueService
    {
        return app(RecoursAcademiqueService::class);
    }

    /**
     * Circuit actif, type présent, décision publiée et contestable.
     */
    private function preparerDepot(array $surcharges = []): DecisionDeliberation
    {
        $this->configurerCircuit($surcharges);
        $this->creerTypeRecours();

        return $this->creerDecision($this->etudiant);
    }

    private function deposer(DecisionDeliberation $decision, string $motif = 'Erreur de délibération constatée.'): OnlineRequest
    {
        return $this->service()->deposer($this->etudiant, $decision, ['motif' => $motif]);
    }

    /**
     * Recours conduit jusqu'à l'instruction ouverte (sans décision).
     */
    private function recoursEnInstruction(
        User $instructeur,
        array $surcharges = []
    ): OnlineRequest {
        $decision = $this->preparerDepot($surcharges);
        $recours = $this->deposer($decision);

        $this->service()->declarerRecevable($recours, $this->agentScolarite, 'RECEVABILITE-2026-001');

        return $this->service()->instruire($recours->fresh(), $instructeur);
    }

    // ---------------------------------------------------------------------
    // 1. Fail-closed : aucune règle officielle inventée
    // ---------------------------------------------------------------------

    public function test_circuit_inactif_par_defaut_refuse_tout_depot(): void
    {
        $this->creerTypeRecours();
        $decision = $this->creerDecision($this->etudiant);

        try {
            $this->deposer($decision);
            $this->fail('Le circuit inactif doit refuser le dépôt.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('circuit des recours est inactif', $exception->getMessage());
        }

        $this->assertSame(0, OnlineRequest::count());
    }

    public function test_reference_reglementaire_absente_bloque_meme_circuit_actif(): void
    {
        $this->configurerCircuit(['recours_academiques.reference_reglementaire' => null]);
        $this->creerTypeRecours();
        $decision = $this->creerDecision($this->etudiant);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Aucune référence réglementaire');

        $this->deposer($decision);
    }

    public function test_type_de_demande_absent_bloque_le_circuit(): void
    {
        $this->configurerCircuit();
        $decision = $this->creerDecision($this->etudiant);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('type de demande des recours est absent');

        $this->deposer($decision);
    }

    public function test_type_de_demande_inactif_bloque_le_circuit(): void
    {
        $this->configurerCircuit();
        $this->creerTypeRecours(false);
        $decision = $this->creerDecision($this->etudiant);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('absent ou inactif');

        $this->deposer($decision);
    }

    public function test_aucun_type_de_decision_n_est_conteste_par_defaut(): void
    {
        $this->configurerCircuit(['recours_academiques.types_decisions_contestables' => []]);
        $this->creerTypeRecours();
        $decision = $this->creerDecision($this->etudiant);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('n’est pas déclaré contestable');

        $this->deposer($decision);
    }

    public function test_decision_non_publiee_refusee(): void
    {
        $this->configurerCircuit();
        $this->creerTypeRecours();
        $decision = $this->creerDecision($this->etudiant, 'ajourne', false);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('n’est pas publiée');

        $this->deposer($decision);
    }

    public function test_deliberation_non_publiee_refusee(): void
    {
        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $this->annee->id,
            'statut' => 'draft',
        ]);

        $this->configurerCircuit();
        $this->creerTypeRecours();
        $decision = $this->creerDecision($this->etudiant, 'ajourne', true, $deliberation);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('délibération de la décision visée n’est pas publiée');

        $this->deposer($decision);
    }

    public function test_decision_d_un_autre_etudiant_refusee(): void
    {
        $this->configurerCircuit();
        $this->creerTypeRecours();
        $decision = $this->creerDecision($this->autreEtudiant);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('appartient à un autre étudiant');

        $this->deposer($decision);
    }

    // ---------------------------------------------------------------------
    // 2. Dépôt : délai explicatif, jamais un rejet
    // ---------------------------------------------------------------------

    public function test_depot_valide_cree_un_recours_soumis_et_trace(): void
    {
        $decision = $this->preparerDepot();

        $recours = $this->deposer($decision);

        $this->assertSame('submitted', $recours->status);
        $this->assertSame(WorkflowRecoursAcademiqueDefinition::VERSION, (int) $recours->version_workflow);
        $this->assertStringStartsWith('REC-', (string) $recours->reference);
        $this->assertSame((int) $decision->id, (int) $recours->form_data['recours']['decision_id']);
        $this->assertSame('A_VERIFIER', $recours->form_data['recours']['recevabilite']);
        $this->assertFalse($recours->form_data['recours']['application_automatique']);

        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $this->etudiant->id,
            'action' => 'online_request.submitted',
        ]);
    }

    public function test_doublon_de_recours_pour_la_meme_decision_refuse(): void
    {
        $decision = $this->preparerDepot();
        $this->deposer($decision);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('déjà ouvert');

        $this->deposer($decision);
    }

    public function test_depot_hors_delai_reste_a_verifier_sans_rejet_automatique(): void
    {
        $this->deliberation->forceFill(['published_at' => now()->subDays(90)])->save();

        $decision = $this->preparerDepot(['recours_academiques.delai_depot_jours' => 30]);
        $recours = $this->deposer($decision);

        $this->assertSame('submitted', $recours->status);
        $this->assertSame('A_VERIFIER', $recours->form_data['recours']['recevabilite']);
        $this->assertFalse($recours->form_data['recours']['delai']['dans_delai']);
        $this->assertStringContainsString('aucun rejet automatique', $recours->form_data['recours']['delai']['explication']);
        $this->assertDatabaseMissing('online_requests', ['status' => 'rejected']);
    }

    public function test_depot_dans_le_delai_signale_dans_delai(): void
    {
        $decision = $this->preparerDepot(['recours_academiques.delai_depot_jours' => 30]);
        $recours = $this->deposer($decision);

        $this->assertTrue($recours->form_data['recours']['delai']['dans_delai']);
        $this->assertSame('A_VERIFIER', $recours->form_data['recours']['recevabilite']);
    }

    public function test_etat_recevabilite_explique_l_absence_de_delai_officiel(): void
    {
        $decision = $this->preparerDepot();
        $recours = $this->deposer($decision);

        $etat = $this->service()->etatRecevabilite($recours);

        $this->assertFalse($etat['delai_configure']);
        $this->assertNull($etat['delai_jours']);
        $this->assertNull($etat['dans_delai']);
        $this->assertSame('A_VERIFIER', $etat['recevabilite']);
        $this->assertStringContainsString('Aucun délai officiel', $etat['explication']);
    }

    public function test_motif_vide_refuse(): void
    {
        $decision = $this->preparerDepot();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('motif du recours est obligatoire');

        $this->deposer($decision, '   ');
    }

    // ---------------------------------------------------------------------
    // 3. Recevabilité et instruction humaines
    // ---------------------------------------------------------------------

    public function test_recevabilite_exige_la_permission_dediee(): void
    {
        $decision = $this->preparerDepot();
        $recours = $this->deposer($decision);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Permission « recours.recevoir » requise');

        $this->service()->declarerRecevable($recours, $this->directionEtudes, 'REF-001');
    }

    public function test_recevabilite_exige_une_reference_humaine(): void
    {
        $decision = $this->preparerDepot();
        $recours = $this->deposer($decision);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('référence de la décision de recevabilité est obligatoire');

        $this->service()->declarerRecevable($recours, $this->agentScolarite, '  ');
    }

    public function test_declaration_de_recevabilite_tracee_et_transition_received(): void
    {
        $decision = $this->preparerDepot();
        $recours = $this->deposer($decision);

        $recours = $this->service()->declarerRecevable($recours, $this->agentScolarite, 'RECEVABILITE-2026-001');

        $this->assertSame('received', $recours->status);
        $this->assertSame('RECEVABLE', $recours->form_data['recours']['recevabilite']);
        $this->assertSame((int) $this->agentScolarite->id, (int) $recours->form_data['recours']['recevabilite_par']);
        $this->assertSame('RECEVABILITE-2026-001', $recours->form_data['recours']['reference_recevabilite']);
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $this->etudiant->id,
            'action' => 'online_request.received',
        ]);
    }

    public function test_instruction_assigne_nominalement_l_instructeur(): void
    {
        $recours = $this->recoursEnInstruction($this->agentScolarite);

        $this->assertSame('in_review', $recours->status);
        $this->assertSame((int) $this->agentScolarite->id, (int) $recours->assigned_to);
        $this->assertSame((int) $this->agentScolarite->id, (int) $recours->form_data['recours']['instruction']['par']);
        $this->assertSame('EN_COURS', $recours->form_data['recours']['instruction']['statut']);
    }

    public function test_instruction_exige_la_permission_dediee(): void
    {
        $decision = $this->preparerDepot();
        $recours = $this->deposer($decision);
        $recours = $this->service()->declarerRecevable($recours, $this->agentScolarite, 'REF-001');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Permission « recours.instruire » requise');

        $this->service()->instruire($recours, $this->directionEtudes);
    }

    public function test_un_second_instructeur_ne_supplante_pas_l_assignation(): void
    {
        $recours = $this->recoursEnInstruction($this->agentScolarite);

        // Une pièce est demandée : le dossier reste assigné au même agent.
        $recours = $this->service()->demanderPiece($recours, $this->agentScolarite, 'Merci de joindre le relevé.');

        $second = User::creerCompteBackOffice([
            'name' => 'Second Instructeur',
            'email' => 'second.instructeur@univ.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('déjà assigné à un autre instructeur');

        $this->service()->instruire($recours, $second);
    }

    // ---------------------------------------------------------------------
    // 4. Décision : quatre yeux et zéro écriture académique
    // ---------------------------------------------------------------------

    public function test_l_instructeur_assigne_ne_peut_pas_decider_lui_meme(): void
    {
        // super_admin porte à la fois instruire et decider : seul le
        // quatre yeux peut l'arrêter.
        $recours = $this->recoursEnInstruction($this->superAdmin);

        try {
            $this->service()->decider($recours, $this->superAdmin, 'approuve', 'Favorable.');
            $this->fail('Le quatre yeux doit interdire à l’instructeur de décider.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Quatre yeux', $exception->getMessage());
        }

        $this->assertSame('in_review', $recours->fresh()->status);
    }

    public function test_decider_exige_la_permission_dediee(): void
    {
        $recours = $this->recoursEnInstruction($this->agentScolarite);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Permission « recours.decider » requise');

        $this->service()->decider($recours, $this->agentScolarite, 'approuve', 'Favorable.');
    }

    public function test_decision_sans_instruction_prealable_refusee(): void
    {
        $decision = $this->preparerDepot();
        $recours = $this->deposer($decision);
        $recours = $this->service()->declarerRecevable($recours, $this->agentScolarite, 'REF-001');

        // Statut forcé en instruction sans assignation : la décision reste
        // impossible (aucune instruction réelle).
        $recours->forceFill(['status' => 'in_review'])->save();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Aucune instruction n’a été réalisée');

        $this->service()->decider($recours->fresh(), $this->directionEtudes, 'approuve', 'Favorable.');
    }

    public function test_decision_approuvee_par_un_second_agent_sans_toucher_aux_resultats(): void
    {
        $decision = $this->preparerDepot();
        $recours = $this->deposer($decision);
        $recours = $this->service()->declarerRecevable($recours, $this->agentScolarite, 'REF-001');
        $recours = $this->service()->instruire($recours, $this->agentScolarite);

        // Instantané des résultats officiels avant décision.
        $decisionAvant = DecisionDeliberation::findOrFail($decision->id)->toArray();
        $notesAvant = Note::count();

        $recours = $this->service()->decider($recours, $this->directionEtudes, 'approuve', 'Irrégularité constatée.');

        $this->assertSame('approved', $recours->status);
        $this->assertSame('APPROUVE', $recours->form_data['recours']['decision']['sens']);
        $this->assertSame('A_APPLIQUER_MANUELLEMENT', $recours->form_data['recours']['decision']['application']);
        $this->assertFalse($recours->form_data['recours']['decision']['application_effectuee']);
        $this->assertSame('TERMINEE', $recours->form_data['recours']['instruction']['statut']);

        // Aucune écriture automatique : décision et notes inchangées.
        $this->assertSame($decisionAvant, DecisionDeliberation::findOrFail($decision->id)->toArray());
        $this->assertSame($notesAvant, Note::count());
    }

    public function test_decision_rejetee_est_tracee_et_permet_un_nouveau_depot(): void
    {
        $decision = $this->preparerDepot();
        $recours = $this->deposer($decision);
        $recours = $this->service()->declarerRecevable($recours, $this->agentScolarite, 'REF-001');
        $recours = $this->service()->instruire($recours, $this->agentScolarite);

        $recours = $this->service()->decider($recours, $this->directionEtudes, 'rejete', 'Recours non fondé.');

        $this->assertSame('rejected', $recours->status);
        $this->assertSame('REJETE', $recours->form_data['recours']['decision']['sens']);
        $this->assertNotNull($recours->rejected_at);
        $this->assertSame('Recours non fondé.', $recours->rejection_reason);

        // Un rejet ne ferme pas définitivement la voie de recours : un
        // nouveau dépôt reste possible sur la même décision.
        $nouveau = $this->deposer($decision, 'Éléments nouveaux.');
        $this->assertSame('submitted', $nouveau->status);
        $this->assertNotSame($recours->id, $nouveau->id);
    }

    public function test_application_automatique_configuree_est_refusee(): void
    {
        $recours = $this->recoursEnInstruction(
            $this->agentScolarite,
            ['recours_academiques.application_automatique_autorisee' => true]
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('application automatique d’une décision de recours est interdite');

        $this->service()->decider($recours, $this->directionEtudes, 'approuve', 'Favorable.');
    }

    // ---------------------------------------------------------------------
    // 5. Application manuelle et clôture
    // ---------------------------------------------------------------------

    public function test_cloture_exige_une_application_manuelle_avec_reference(): void
    {
        $recours = $this->recoursEnInstruction($this->agentScolarite);
        $recours = $this->service()->decider($recours, $this->directionEtudes, 'approuve', 'Favorable.');

        try {
            $this->service()->cloturer($recours, $this->agentScolarite);
            $this->fail('La clôture sans application doit être refusée.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('application manuelle préalablement enregistrée', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('référence de l’application est obligatoire');

        $this->service()->enregistrerApplication($recours, $this->agentScolarite, ' ');
    }

    public function test_application_manuelle_puis_cloture(): void
    {
        $recours = $this->recoursEnInstruction($this->agentScolarite);
        $recours = $this->service()->decider($recours, $this->directionEtudes, 'approuve', 'Favorable.');

        $recours = $this->service()->enregistrerApplication($recours, $this->agentScolarite, 'PV-RECTIFICATIF-2026-07');

        $this->assertTrue($recours->form_data['recours']['application_effectuee']);
        $this->assertSame('APPLIQUEE_MANUELLEMENT', $recours->form_data['recours']['application']);
        $this->assertSame('PV-RECTIFICATIF-2026-07', $recours->form_data['recours']['reference_application']);
        $this->assertSame('approved', $recours->status);

        $recours = $this->service()->cloturer($recours, $this->agentScolarite);

        $this->assertSame('completed', $recours->status);
        $this->assertNotNull($recours->completed_at);
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $this->etudiant->id,
            'action' => 'online_request.application_manuelle',
        ]);
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $this->etudiant->id,
            'action' => 'online_request.completed',
        ]);
    }

    public function test_double_application_refusee(): void
    {
        $recours = $this->recoursEnInstruction($this->agentScolarite);
        $recours = $this->service()->decider($recours, $this->directionEtudes, 'approuve', 'Favorable.');
        $this->service()->enregistrerApplication($recours, $this->agentScolarite, 'PV-001');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('déjà enregistrée');

        $this->service()->enregistrerApplication($recours->fresh(), $this->agentScolarite, 'PV-002');
    }

    // ---------------------------------------------------------------------
    // 6. Annulation étudiante bornée
    // ---------------------------------------------------------------------

    public function test_annulation_etudiante_avant_reception(): void
    {
        $decision = $this->preparerDepot();
        $recours = $this->deposer($decision);

        $recours = $this->service()->annuler($recours, $this->etudiant, 'Désistement.');

        $this->assertSame('cancelled', $recours->status);
        $this->assertSame('Désistement.', $recours->form_data['recours']['annulation']['motif']);
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $this->etudiant->id,
            'action' => 'online_request.cancelled',
        ]);
    }

    public function test_annulation_impossible_apres_reception(): void
    {
        $decision = $this->preparerDepot();
        $recours = $this->deposer($decision);
        $recours = $this->service()->declarerRecevable($recours, $this->agentScolarite, 'REF-001');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('qu’avant sa réception');

        $this->service()->annuler($recours, $this->etudiant, 'Trop tard.');
    }

    public function test_annulation_par_un_autre_etudiant_refusee(): void
    {
        $decision = $this->preparerDepot();
        $recours = $this->deposer($decision);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('appartient à un autre étudiant');

        $this->service()->annuler($recours, $this->autreEtudiant, 'Je tente.');
    }

    // ---------------------------------------------------------------------
    // 7. Isolation, transitionneur générique et cohérence
    // ---------------------------------------------------------------------

    public function test_consultation_limitee_aux_recours_de_l_etudiant(): void
    {
        $decision = $this->preparerDepot();
        $this->deposer($decision);

        // Une demande ordinaire du même étudiant n'apparaît pas.
        $typeOrdinaire = RequestType::where('code', 'ATTESTATION_SCOLARITE')->firstOrFail();
        OnlineRequest::create([
            'reference' => 'REQ-ORDINAIRE-001',
            'etudiant_id' => $this->etudiant->id,
            'request_type_id' => $typeOrdinaire->id,
            'objet' => 'Attestation',
            'status' => 'draft',
            'version_workflow' => 1,
        ]);

        $this->assertCount(1, $this->service()->pourEtudiant($this->etudiant));
        $this->assertCount(0, $this->service()->pourEtudiant($this->autreEtudiant));
    }

    public function test_le_transitionneur_generique_ne_peut_pas_faire_evoluer_un_recours(): void
    {
        $decision = $this->preparerDepot();
        $recours = $this->deposer($decision);

        $generique = app(OnlineRequestService::class);

        try {
            $generique->transitionner($recours, 'received');
            $this->fail('Le transitionneur générique doit refuser un recours.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('circuit des recours', $exception->getMessage());
        }

        try {
            $generique->approuver($recours, $this->agentScolarite);
            $this->fail('L’approbation générique doit refuser un recours.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('circuit des recours', $exception->getMessage());
        }

        $this->assertSame('submitted', $recours->fresh()->status);
    }

    public function test_le_garde_generique_s_applique_meme_circuit_desactive(): void
    {
        $decision = $this->preparerDepot();
        $recours = $this->deposer($decision);

        config(['recours_academiques.actif' => false]);

        $this->expectException(ValidationException::class);

        app(OnlineRequestService::class)->transitionner($recours, 'received');
    }

    public function test_permissions_separees_entre_instruction_et_decision(): void
    {
        $agent = Permissions::pourRole('admin_scolarite');
        $direction = Permissions::pourRole('direction_etudes');

        $this->assertContains('recours.recevoir', $agent);
        $this->assertContains('recours.instruire', $agent);
        $this->assertContains('recours.appliquer', $agent);
        $this->assertNotContains('recours.decider', $agent);

        $this->assertContains('recours.decider', $direction);
        $this->assertNotContains('recours.instruire', $direction);
        $this->assertNotContains('recours.recevoir', $direction);
    }

    public function test_definition_du_workflow_n_invente_aucun_delai_et_interdit_les_sauts(): void
    {
        $definition = new WorkflowRecoursAcademiqueDefinition;

        foreach ($definition->etats() as $source) {
            foreach ($definition->etats() as $cible) {
                $this->assertNull($definition->delaiMaxHeures($source, $cible));
            }
        }

        $this->assertFalse($definition->transitionAutorisee('submitted', 'approved'));
        $this->assertFalse($definition->transitionAutorisee('received', 'approved'));
        $this->assertFalse($definition->transitionAutorisee('submitted', 'completed'));
        $this->assertTrue($definition->transitionAutorisee('in_review', 'approved'));
        $this->assertTrue($definition->requiertQuatreYeux('in_review', 'approved'));
        $this->assertTrue($definition->requiertQuatreYeux('in_review', 'rejected'));
        $this->assertSame('recours.decider', $definition->permissionRequise('in_review', 'approved'));
        $this->assertSame('recours.appliquer', $definition->permissionRequise('approved', 'completed'));
    }

    public function test_aucune_ecriture_dans_les_tables_academiques_pendant_le_circuit(): void
    {
        $decision = $this->preparerDepot();
        $avant = [
            'notes' => Note::count(),
            'decisions' => DecisionDeliberation::count(),
            'deliberations' => Deliberation::count(),
        ];

        $recours = $this->deposer($decision);
        $recours = $this->service()->declarerRecevable($recours, $this->agentScolarite, 'REF-001');
        $recours = $this->service()->instruire($recours, $this->agentScolarite);
        $recours = $this->service()->decider($recours, $this->directionEtudes, 'approuve', 'Favorable.');
        $recours = $this->service()->enregistrerApplication($recours, $this->agentScolarite, 'PV-001');
        $this->service()->cloturer($recours, $this->agentScolarite);

        $apres = [
            'notes' => Note::count(),
            'decisions' => DecisionDeliberation::count(),
            'deliberations' => Deliberation::count(),
        ];

        $this->assertSame($avant, $apres);
        $this->assertSame('completed', $recours->fresh()->status);

        // Les traces du circuit vivent dans l'audit, jamais dans les notes.
        $this->assertSame(0, DB::table('notes')->count());
    }
}
