<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Services\EcheanceDerogationService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * LOOP 146 — consultation consolidée et intégration des cycles Q132 → Q146.
 *
 * Ce fichier couvre le modèle de lecture `consulterDossierDerogations` (ajouté
 * sans test) et les enchaînements réels entre contestation, décision, ambiguïté
 * et clarification. Il ne modifie aucune règle métier : il vérifie que les
 * statuts affichés restent fidèles aux événements et qu'aucune option Q146
 * n'est présumée.
 */
class Loop146ConsultationEtIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private EcheanceDerogationService $derogations;

    private NotificationService $notifications;

    private Etudiant $etudiant;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'loop146.derogations.mecanisme_identifie' => false,
            'loop146.derogations.autorites_competentes' => [],
            'loop146.derogations.canaux_relance' => [],
            'loop146.derogations.voies_reexamen' => [],
            'loop146.derogations.voies_recours' => [],
        ]);

        $this->derogations = app(EcheanceDerogationService::class);
        $this->notifications = app(NotificationService::class);

        $faculte = Faculte::create([
            'code' => 'FST',
            'nom' => 'Faculté des Sciences et Techniques',
        ]);

        $this->etudiant = Etudiant::create([
            'cin' => '61460031',
            'nom' => 'El Amrani',
            'prenom' => 'Nadia',
            'email' => 'nadia.elamrani@universite.ac.ma',
            'date_naissance' => '2003-06-18',
            'sexe' => 'F',
            'faculte_id' => $faculte->id,
            'niveau' => 'L3',
            'annee_academique' => '2026-2027',
            'adresse' => 'Rabat Hassan',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2023',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');
    }

    // ------------------------------------------------------------------
    // Q146 — consultation après ambiguïté et clarification
    // ------------------------------------------------------------------

    public function test_q146_consultation_ne_presume_ni_accord_ni_refus_apres_clarification(): void
    {
        $demandeId = $this->deposerDemande('DEROG-CONS-1');

        $this->derogations->enregistrerDecisionAmbiguë(
            $this->etudiant,
            demandeId: $demandeId,
            contenuDecision: 'Décision initiale dont le dispositif reste ambigu.',
            referenceActe: 'PV-AMB-2026-11'
        );

        $this->derogations->appliquerClarificationDecision(
            $this->etudiant,
            demandeId: $demandeId,
            issueClarifiee: 'refus',
            referenceClarification: 'CLAR-2026-11',
            auteurClarification: 'Recteur',
            dateClarification: '2026-10-29'
        );

        $dossier = $this->consulterDemande($demandeId);

        $this->assertTrue($dossier['clarification_recue']);
        $this->assertTrue($dossier['ambigu']);
        $this->assertSame('clarification_enregistree', $dossier['statut']);
        $this->assertNull($dossier['statut_final']);
        $this->assertFalse($dossier['decision_finale_appliquee']);
        $this->assertFalse($dossier['refuse'], 'Q146 : la clarification « refus » ne vaut pas refus appliqué.');
        $this->assertFalse($dossier['accepte']);
        $this->assertStringContainsString('statut final non appliqué', $dossier['statut_affichage']);

        // La décision ambiguë initiale reste consultable dans l'historique.
        $actions = array_column($dossier['evenements'], 'action');
        $this->assertContains('decision_reexamen_ambigue', $actions);
        $this->assertContains('clarification_decision_enregistree', $actions);
    }

    public function test_q146_deux_clarifications_successives_laissent_le_statut_final_ouvert(): void
    {
        $demandeId = $this->deposerDemande('DEROG-CONS-2');

        $this->derogations->enregistrerDecisionAmbiguë(
            $this->etudiant,
            demandeId: $demandeId,
            contenuDecision: 'Décision ambiguë à clarifier.',
            referenceActe: 'PV-AMB-2026-12'
        );

        $this->derogations->appliquerClarificationDecision(
            $this->etudiant,
            demandeId: $demandeId,
            issueClarifiee: 'accord',
            referenceClarification: 'CLAR-2026-12-A',
            dateClarification: '2026-10-29'
        );

        $this->derogations->appliquerClarificationDecision(
            $this->etudiant,
            demandeId: $demandeId,
            issueClarifiee: 'refus',
            referenceClarification: 'CLAR-2026-12-B',
            dateClarification: '2026-10-30'
        );

        $dossier = $this->consulterDemande($demandeId);

        $this->assertTrue($dossier['clarification_recue']);
        $this->assertSame('clarification_enregistree', $dossier['statut']);
        $this->assertNull($dossier['statut_final']);
        $this->assertFalse($dossier['decision_finale_appliquee']);
        $this->assertFalse($dossier['refuse']);
        $this->assertFalse($dossier['accepte']);

        $clarifications = array_values(array_filter(
            $dossier['evenements'],
            static fn (array $evenement): bool => $evenement['action'] === 'clarification_decision_enregistree'
        ));
        $this->assertCount(2, $clarifications);
    }

    public function test_q146_la_consultation_reflete_l_evenement_le_plus_recent(): void
    {
        $demandeId = $this->deposerDemande('DEROG-CONS-3');

        $this->derogations->enregistrerDecisionAmbiguë(
            $this->etudiant,
            demandeId: $demandeId,
            contenuDecision: 'Décision ambiguë.',
            referenceActe: 'PV-AMB-2026-13'
        );

        $this->derogations->appliquerClarificationDecision(
            $this->etudiant,
            demandeId: $demandeId,
            issueClarifiee: 'accord',
            referenceClarification: 'CLAR-2026-13',
            dateClarification: '2026-10-30'
        );

        $dossier = $this->consulterDemande($demandeId);

        // Si l'ordre était inversé, l'état « ambigu » (plus ancien) écraserait
        // la clarification (plus récente) et le statut affiché serait faux.
        $this->assertSame('clarification_enregistree', $dossier['statut']);
        $this->assertStringContainsString('Clarification reçue', $dossier['statut_affichage']);
    }

    // ------------------------------------------------------------------
    // Q142 — contestation de l'attente prolongée
    // ------------------------------------------------------------------

    public function test_q142_contestation_ne_modifie_pas_le_statut_du_dossier(): void
    {
        $demandeId = $this->deposerDemande('DEROG-CONS-4');

        $this->derogations->enregistrerContestationAttente(
            $this->etudiant,
            demandeId: $demandeId,
            motifContestation: 'Je conteste la durée anormale de traitement de ma demande.'
        );

        $dossier = $this->consulterDemande($demandeId);

        $this->assertTrue($dossier['conteste']);
        $this->assertSame('en_attente', $dossier['statut']);
        $this->assertNull($dossier['statut_final']);
        $this->assertFalse($dossier['decision_finale_appliquee']);
        $this->assertFalse($dossier['refuse']);
        $this->assertStringContainsString('contestation enregistrée', $dossier['statut_affichage']);
    }

    public function test_q142_contestation_apres_decision_ne_surcharge_pas_la_decision(): void
    {
        $demandeId = $this->deposerDemande('DEROG-CONS-5');

        config([
            'loop146.derogations.mecanisme_identifie' => true,
            'loop146.derogations.autorites_competentes' => ['Doyen'],
        ]);

        $this->derogations->statuerDerogation(
            $this->etudiant,
            demandeId: $demandeId,
            decision: 'refusee',
            autoriteQualite: 'Doyen',
            referenceActeOfficiel: 'ACTE-2026-77'
        );

        $this->derogations->enregistrerContestationAttente(
            $this->etudiant,
            demandeId: $demandeId,
            motifContestation: 'Je conteste cette décision et demande son réexamen.'
        );

        $dossier = $this->consulterDemande($demandeId);

        $this->assertTrue($dossier['conteste']);
        $this->assertTrue($dossier['refuse']);
        $this->assertSame('refusee', $dossier['statut']);
        $this->assertSame('Dérogation refusée', $dossier['statut_affichage']);
    }

    // ------------------------------------------------------------------
    // Q135 — le constat de dépassement ne corrige jamais l'échéance
    // ------------------------------------------------------------------

    public function test_q135_consultation_conserve_l_echeance_initiale_et_signale_le_risque(): void
    {
        $echeance = now()->subDays(12)->startOfSecond();

        $this->derogations->constaterDepassementEcheance(
            $this->etudiant,
            typeEcheance: 'depot_dossier_bourse',
            echeanceInitiale: $echeance,
            referenceReglementaire: null,
            agentId: 9
        );

        $dossier = $this->derogations->consulterDossierDerogations($this->etudiant);

        $this->assertCount(1, $dossier['depassements']);
        $constat = $dossier['depassements'][0];

        $this->assertSame($echeance->toIso8601String(), $constat['date_echeance']);
        $this->assertSame('depot_dossier_bourse', $constat['code_echeance']);
        $this->assertTrue($constat['risque_signalise']);
        $this->assertFalse($constat['regle_certifiee']);
        $this->assertSame([], $dossier['demandes']);
    }

    // ------------------------------------------------------------------
    // Q132 → Q133 — contestation puis décision de recours
    // ------------------------------------------------------------------

    public function test_q133_cycle_contestation_puis_recours_conserve_l_historique(): void
    {
        $notification = $this->notifications->notifierEtudiant(
            $this->etudiant,
            'admission_master',
            'Refus d’admission',
            'Votre candidature n’a pas été retenue.'
        );

        $this->notifications->refuserPreuveRemise($notification, 'Non délivré dans les délais', 1);

        $contestation = $this->notifications->enregistrerContestationRemise(
            $notification,
            motifContestation: 'La preuve de remise a bien été déposée dans les délais.',
            referenceContestation: 'CONT-2026-31',
            contestataire: $this->etudiant
        );

        $this->assertFalse($contestation['deja_enregistree']);
        $this->assertFalse($contestation['statut_modifie']);
        $this->assertSame('refusee', $contestation['notification']->data['statut_remise']);
        $this->assertTrue($contestation['notification']->data['en_contestation']);

        $recours = $this->notifications->appliquerRecoursRemise(
            $contestation['notification'],
            decisionRecours: 'remise_confirmee',
            referenceOfficielle: 'DEC-RECOURS-2026-0031',
            autoriteId: 5
        );

        $notif = $recours['notification'];
        $this->assertTrue($this->notifications->estRemiseConfirmee($notif));
        $this->assertSame('remise_confirmee_apres_recours', $recours['statut_remise']);
        $this->assertFalse($notif->data['en_contestation']);
        $this->assertFalse($recours['consequences_appliquees']);
        $this->assertSame([], $recours['dossiers_modifies']);

        $actions = array_column($notif->data['historique_remise'], 'action');
        $this->assertContains('contestation_enregistree', $actions);
        $this->assertContains('decision_recours_appliquee', $actions);
    }

    // ------------------------------------------------------------------
    // Q132 — barrières HTTP : quota et type de pièce
    // ------------------------------------------------------------------

    public function test_q132_http_le_onzieme_envoi_est_refuse_par_le_quota(): void
    {
        $notification = $this->preparerNotificationRefusee();

        $charge = ['motif_contestation' => 'Je conteste le refus de ma preuve de remise.'];

        for ($i = 1; $i <= 10; $i++) {
            $this->post(route('etudiant.notifications.contester', $notification), $charge)
                ->assertRedirect(route('etudiant.notifications.index'));
        }

        $this->post(route('etudiant.notifications.contester', $notification), $charge)
            ->assertStatus(429);
    }

    public function test_q132_http_piece_de_type_interdit_rejetee_sans_trace(): void
    {
        $notification = $this->preparerNotificationRefusee();

        $reponse = $this->post(route('etudiant.notifications.contester', $notification), [
            'motif_contestation' => 'Je joins un justificatif non autorisé.',
            'piece_contestation' => UploadedFile::fake()->create('preuve.txt', 12, 'text/plain'),
        ]);

        $reponse->assertSessionHasErrors('piece_contestation');

        $actualisee = $notification->refresh();
        $this->assertSame('refusee', $actualisee->data['statut_remise']);
        $this->assertArrayNotHasKey('contestations', $actualisee->data);
        $this->assertArrayNotHasKey('en_contestation', $actualisee->data);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function deposerDemande(string $objet): string
    {
        $depot = $this->derogations->deposerDemandeDerogation(
            $this->etudiant,
            objet: $objet,
            motif: 'Circonstances documentées justifiant la demande.',
            pieceHash: hash('sha256', $objet)
        );

        return $depot['demande_id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function consulterDemande(string $demandeId): array
    {
        $dossier = $this->derogations->consulterDossierDerogations($this->etudiant);

        foreach ($dossier['demandes'] as $demande) {
            if ($demande['demande_id'] === $demandeId) {
                return $demande;
            }
        }

        $this->fail("La demande {$demandeId} est absente de la consultation consolidée.");
    }

    private function preparerNotificationRefusee(): AppNotification
    {
        $notification = $this->notifications->notifierEtudiant(
            $this->etudiant,
            'acte_scolarite',
            'Refus de preuve',
            'Votre preuve de remise a été refusée.'
        );

        $this->notifications->refuserPreuveRemise($notification, 'Signature illisible', 1);

        $this->withSession([
            'etudiant_auth_id' => $this->etudiant->id,
            'etudiant_mdp_version' => $this->etudiant->mot_de_passe_version,
        ]);

        return $notification;
    }
}
