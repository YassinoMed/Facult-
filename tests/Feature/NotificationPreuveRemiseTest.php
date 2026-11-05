<?php

namespace Tests\Feature;

use App\Contracts\CanalNotificationExterneInterface;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Services\NotificationService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationPreuveRemiseTest extends TestCase
{
    use RefreshDatabase;

    private NotificationService $service;

    private Etudiant $etudiant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(NotificationService::class);

        $faculte = Faculte::create([
            'code' => 'FST',
            'nom' => 'Faculté des Sciences et Techniques',
        ]);

        $this->etudiant = Etudiant::create([
            'cin' => '61460001',
            'nom' => 'Benjelloun',
            'prenom' => 'Khadija',
            'email' => 'khadija.benjelloun@universite.ac.ma',
            'date_naissance' => '2004-05-12',
            'sexe' => 'F',
            'faculte_id' => $faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Rabat Agdal',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');
    }

    /**
     * Q114 / NOTIF-002 / STAT-001 :
     * Un acheminement externe réussi (envoyé) ne vaut JAMAIS remise confirmée.
     */
    public function test_acheminement_externe_reussi_ne_vaut_jamais_remise_confirmee(): void
    {
        $notification = $this->service->notifierEtudiant(
            $this->etudiant,
            'decision_examen',
            'Convocation officielle',
            'Votre convocation est disponible.',
            '/portail/convocation',
            ['statut_envoi' => CanalNotificationExterneInterface::STATUT_ENVOYE]
        );

        $this->assertSame(CanalNotificationExterneInterface::STATUT_ENVOYE, $notification->data['statut_envoi']);
        $this->assertFalse($this->service->estRemiseConfirmee($notification));
        $this->assertNull($notification->data['remise_confirmee'] ?? null);
    }

    /**
     * Q114 / Q120 / STAT-001 / HIST-002 :
     * La confirmation formelle de remise requiert une preuve vérifiable et est tracée.
     */
    public function test_confirmation_formelle_remise_avec_tracabilite_et_mode_preuve(): void
    {
        $notification = $this->service->notifierEtudiant(
            $this->etudiant,
            'dossier_juridique',
            'Notification d’acte',
            'Notification officielle d’acte administratif.'
        );

        $actualisee = $this->service->confirmerRemise(
            $notification,
            modePreuve: 'accuse_reception_signe',
            referencePreuve: 'AR-2026-99881',
            agentId: 1
        );

        $this->assertTrue($this->service->estRemiseConfirmee($actualisee));
        $this->assertSame('remise_confirmee', $actualisee->data['statut_remise']);
        $this->assertSame('AR-2026-99881', $actualisee->data['reference_preuve']);
        $this->assertNotEmpty($actualisee->data['historique_remise']);
        $this->assertSame('remise_confirmee', $actualisee->data['historique_remise'][0]['action']);
    }

    /**
     * Q126 / Q127 / BUS-002 / STAT-005 :
     * Le refus de validation d'une preuve exige un motif et maintient remise_non_confirmee.
     */
    public function test_refus_validation_preuve_exige_motif_et_maintient_remise_non_confirmee(): void
    {
        $notification = $this->service->notifierEtudiant(
            $this->etudiant,
            'dossier_discipline',
            'Convocation conseil',
            'Convocation au conseil de discipline.'
        );

        // Tentative sans motif => exception formelle
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Le motif du refus de validation de la preuve est obligatoire.');
        $this->service->refuserPreuveRemise($notification, '   ');
    }

    /**
     * Q126 / Q127 / BUS-002 / STAT-005 :
     * Refus motivé n'infère pas l'absence de remise et conserve les motifs.
     */
    public function test_refus_motive_preserve_statut_non_confirme_et_pieces(): void
    {
        $notification = $this->service->notifierEtudiant(
            $this->etudiant,
            'dossier_discipline',
            'Convocation conseil',
            'Convocation au conseil de discipline.'
        );

        $actualisee = $this->service->refuserPreuveRemise(
            $notification,
            motifRefus: 'Signature illisible et absence de date certaine sur l’accusé.',
            agentId: 2
        );

        $this->assertFalse($this->service->estRemiseConfirmee($actualisee));
        $this->assertSame('refusee', $actualisee->data['statut_remise']);
        $this->assertTrue($actualisee->data['remise_non_confirmee']);
        $this->assertStringContainsString('Signature illisible', $actualisee->data['motif_refus']);
        $this->assertSame('refus_preuve', $actualisee->data['historique_remise'][0]['action']);
    }

    /**
     * Q121 / Q122 / Q123 / Q124 / HIST-003 / HIST-004 :
     * Réception de preuve tardive ou contradictoire sans écrasement de l'antérieur.
     */
    public function test_enregistrement_preuve_tardive_preserve_historique_anterieur(): void
    {
        $notification = $this->service->notifierEtudiant(
            $this->etudiant,
            'acte_scolarite',
            'Avis de clôture',
            'Avis de clôture d’inscription.'
        );

        // Étape 1 : refus initial
        $this->service->refuserPreuveRemise($notification, 'Bordereau non signé', 1);

        // Étape 2 : preuve tardive concluante reçue après clôture
        $actualisee = $this->service->enregistrerPreuveTardive(
            $notification,
            sourcePreuve: 'Bordereau postal recommandé certifié',
            referencePreuve: 'LRAR-MA-99281',
            elementsVerifies: ['signature_conforme' => true, 'date_remise' => '2026-10-25'],
            preuveConcluante: true,
            agentId: 3
        );

        $this->assertTrue($this->service->estRemiseConfirmee($actualisee));
        $this->assertSame('remise_confirmee_tardive', $actualisee->data['statut_remise']);
        $this->assertCount(2, $actualisee->data['historique_remise']);
        $this->assertSame('refus_preuve', $actualisee->data['historique_remise'][0]['action']);
        $this->assertSame('enregistrement_preuve_tardive', $actualisee->data['historique_remise'][1]['action']);
    }

    /**
     * Q132 / RECOURS-001, RECOURS-002 :
     * La contestation est enregistrée avec motif, pièce et identité vérifiée ;
     * le statut de remise initial reste strictement inchangé.
     */
    public function test_q132_contestation_remise_enregistree_sans_modifier_statut(): void
    {
        $notification = $this->service->notifierEtudiant(
            $this->etudiant,
            'acte_scolarite',
            'Mise en demeure',
            'Mise en demeure de régularisation.'
        );

        $this->service->refuserPreuveRemise($notification, 'Signature absente', 1);

        $resultat = $this->service->enregistrerContestationRemise(
            $notification,
            motifContestation: 'Je n’ai jamais reçu le courrier électronique à mon adresse.',
            pieceHash: hash('sha256', 'attestation_fournisseur_telecom'),
            referenceContestation: 'CONT-2026-001',
            contestataire: $this->etudiant,
        );

        $actualisee = $resultat['notification'];
        $this->assertTrue($actualisee->data['en_contestation']);
        $this->assertSame('refusee', $actualisee->data['statut_remise']);
        $this->assertSame('refusee', $resultat['statut_remise_preserve']);
        $this->assertFalse($resultat['statut_modifie']);
        $this->assertFalse($resultat['deja_enregistree']);
        $this->assertTrue($resultat['identite_contestataire_verifiee']);
        $this->assertSame('voie_officielle_de_recours', $resultat['orientation']);
        $this->assertNotEmpty($actualisee->data['piece_contestation_hash']);
        $this->assertCount(1, $actualisee->data['contestations']);
    }

    /**
     * Q132 : une contestation identique rejouée n'est jamais dupliquée.
     */
    public function test_q132_contestation_doublon_est_idempotente(): void
    {
        $notification = $this->service->notifierEtudiant(
            $this->etudiant,
            'acte_scolarite',
            'Mise en demeure',
            'Mise en demeure de régularisation.'
        );

        $this->service->refuserPreuveRemise($notification, 'Signature absente', 1);

        $parametres = [
            'motifContestation' => 'Je conteste le refus de ma preuve de remise.',
            'pieceHash' => hash('sha256', 'preuve'),
            'referenceContestation' => 'CONT-2026-002',
            'contestataire' => $this->etudiant,
        ];

        $premiere = $this->service->enregistrerContestationRemise($notification, ...$parametres);
        $seconde = $this->service->enregistrerContestationRemise($notification, ...$parametres);

        $this->assertFalse($premiere['deja_enregistree']);
        $this->assertTrue($seconde['deja_enregistree']);
        $this->assertCount(1, $seconde['notification']->data['contestations']);
        $this->assertSame('refusee', $seconde['notification']->data['statut_remise']);
    }

    /**
     * Q132 : un étudiant ne peut pas contester la notification d'un autre dossier.
     */
    public function test_q132_contestation_d_un_autre_dossier_refusee(): void
    {
        $notification = $this->service->notifierEtudiant(
            $this->etudiant,
            'acte_scolarite',
            'Mise en demeure',
            'Mise en demeure de régularisation.'
        );

        $autreEtudiant = new Etudiant;
        $autreEtudiant->id = $this->etudiant->id + 1;

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('La notification ne relève pas du dossier du contestataire.');

        $this->service->enregistrerContestationRemise(
            $notification,
            motifContestation: 'Je conteste cette notification qui ne m’appartient pas.',
            contestataire: $autreEtudiant
        );
    }

    /**
     * Q133 / Q134 / RECOURS-003 / BUS-004 :
     * Une conclusion explicite met à jour le statut de remise, préserve
     * l'historique, identifie les conséquences possibles sans en appliquer
     * aucune sur les dossiers associés.
     */
    public function test_q133_recours_explicite_applique_la_remise_sans_muter_les_dossiers(): void
    {
        $notification = $this->service->notifierEtudiant(
            $this->etudiant,
            'admission_master',
            'Refus d’admission',
            'Votre candidature n’a pas été retenue.'
        );

        $this->service->refuserPreuveRemise($notification, 'Non délivré dans les délais', 1);

        $resultat = $this->service->appliquerRecoursRemise(
            $notification,
            decisionRecours: 'remise_confirmee',
            referenceOfficielle: 'DEC-RECOURS-2026-0042',
            autoriteId: 5
        );

        $notif = $resultat['notification'];
        $this->assertTrue($this->service->estRemiseConfirmee($notif));
        $this->assertSame('remise_confirmee_apres_recours', $resultat['statut_remise']);
        $this->assertSame('refusee', $resultat['statut_remise_avant']);
        $this->assertTrue($resultat['decision_explicite']);
        $this->assertFalse($resultat['deja_appliquee']);
        $this->assertFalse($resultat['consequences_appliquees']);
        $this->assertSame([], $resultat['dossiers_modifies']);
        $this->assertArrayHasKey('dossier_etudiant', $resultat['consequences_possibles']);
        $this->assertNotEmpty($resultat['dossiers_associes_identifies']);
        $this->assertFalse($resultat['mutations_automatiques_effectuees']);
        $this->assertStringContainsString('aucun dossier associé n\'a été muté automatiquement', $resultat['avertissement']);
        $this->assertCount(2, $notif->data['historique_remise']);
        $this->assertSame('refus_preuve', $notif->data['historique_remise'][0]['action']);
        $this->assertSame('decision_recours_appliquee', $notif->data['historique_remise'][1]['action']);
    }

    /**
     * Q133 : « confirme » est ambigu et ne peut jamais être interprété comme
     * un refus ou une confirmation de remise.
     */
    public function test_q133_decision_confirme_ambigue_rejetee(): void
    {
        $notification = $this->service->notifierEtudiant(
            $this->etudiant,
            'acte_scolarite',
            'Notification',
            'Notification officielle.'
        );

        $this->attendreDomainException(
            fn () => $this->service->appliquerRecoursRemise(
                $notification,
                decisionRecours: 'confirme',
                referenceOfficielle: 'DEC-RECOURS-2026-0043'
            ),
            'ambiguë'
        );
    }

    /**
     * Q133 : une décision de recours sans référence officielle est refusée.
     */
    public function test_q133_decision_sans_reference_officielle_rejetee(): void
    {
        $notification = $this->service->notifierEtudiant(
            $this->etudiant,
            'acte_scolarite',
            'Notification',
            'Notification officielle.'
        );

        $this->attendreDomainException(
            fn () => $this->service->appliquerRecoursRemise(
                $notification,
                decisionRecours: 'remise_confirmee',
                referenceOfficielle: '   '
            ),
            'La référence officielle de la décision de recours est obligatoire'
        );
    }

    /**
     * Q133 : une même référence officielle ne peut pas porter deux conclusions
     * contradictoires (falsification d'état).
     */
    public function test_q133_conflit_de_reference_avec_decision_differente_rejete(): void
    {
        $notification = $this->service->notifierEtudiant(
            $this->etudiant,
            'acte_scolarite',
            'Notification',
            'Notification officielle.'
        );

        $this->service->appliquerRecoursRemise(
            $notification,
            decisionRecours: 'remise_confirmee',
            referenceOfficielle: 'DEC-RECOURS-2026-0044'
        );

        $this->attendreDomainException(
            fn () => $this->service->appliquerRecoursRemise(
                $notification,
                decisionRecours: 'remise_non_confirmee',
                referenceOfficielle: 'DEC-RECOURS-2026-0044'
            ),
            'Conflit de décisions'
        );

        $this->assertSame(
            'remise_confirmee_apres_recours',
            $notification->refresh()->data['statut_remise']
        );
    }

    /**
     * Q117 / AUTH-002 :
     * La clôture administrative sans fondement réglementaire explicite est rejetée.
     */
    public function test_cloture_administrative_sans_fondement_officiel_est_bloquee(): void
    {
        $notification = $this->service->notifierEtudiant(
            $this->etudiant,
            'suivi_dossier',
            'Relance pièce',
            'Veuillez déposer votre attestation.'
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('La clôture administrative sans remise confirmée est interdite sans fondement réglementaire officiel explicite.');

        $this->service->distinguerClotureAdministrative(
            $notification,
            autoriseeParTexte: false,
            referenceReglementaire: ''
        );
    }

    /**
     * Q118 / Q119 / STAT-003 / BUS-001 :
     * Clôture administrative autorisée distingue le suivi clos et maintient l'obligation de remise.
     */
    public function test_cloture_administrative_autorisee_distingue_clos_et_remise_non_confirmee(): void
    {
        $notification = $this->service->notifierEtudiant(
            $this->etudiant,
            'suivi_dossier',
            'Relance pièce',
            'Veuillez déposer votre attestation.'
        );

        $actualisee = $this->service->distinguerClotureAdministrative(
            $notification,
            autoriseeParTexte: true,
            referenceReglementaire: 'Arrete-Etudes-2026-Art14',
            agentId: 4
        );

        $this->assertTrue($actualisee->data['cloture_administrative']);
        $this->assertSame('clos', $actualisee->data['suivi_administratif']);
        $this->assertSame('Arrete-Etudes-2026-Art14', $actualisee->data['reference_reglementaire_cloture']);
        $this->assertFalse($this->service->estRemiseConfirmee($actualisee));
        $this->assertTrue($actualisee->data['obligation_suivi_active']);
    }

    private function attendreDomainException(callable $action, string $fragment): void
    {
        try {
            $action();
            $this->fail('Une DomainException était attendue.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString($fragment, $exception->getMessage());
        }
    }
}
