<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Services\EcheanceDerogationService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EcheanceDerogationServiceTest extends TestCase
{
    use RefreshDatabase;

    private EcheanceDerogationService $service;

    private Etudiant $etudiant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EcheanceDerogationService::class);

        $faculte = Faculte::create([
            'code' => 'FST',
            'nom' => 'Faculté des Sciences et Techniques',
        ]);

        $this->etudiant = Etudiant::create([
            'cin' => '61460002',
            'nom' => 'El Amrani',
            'prenom' => 'Tariq',
            'email' => 'tariq.elamrani@universite.ac.ma',
            'date_naissance' => '2003-08-20',
            'sexe' => 'M',
            'faculte_id' => $faculte->id,
            'niveau' => 'L2',
            'annee_academique' => '2026-2027',
            'adresse' => 'Rabat Hassan',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2023',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');
    }

    /**
     * Q135, Q136 / DELAI-002, HIST-007 :
     * Le constat de dépassement préserve obligatoirement la date originale
     * et n'applique aucune forclusion automatique sauvage.
     */
    public function test_constat_depassement_preserve_echeance_initiale_et_refuse_forclusion_automatique(): void
    {
        $echeanceInitiale = now()->subDays(5);

        $resultat = $this->service->constaterDepassementEcheance(
            $this->etudiant,
            typeEcheance: 'depot_dossier_bourse',
            echeanceInitiale: $echeanceInitiale,
            referenceReglementaire: 'Reglement-Etudes-Art-22',
            agentId: 1
        );

        $this->assertTrue($resultat['depassement_constate']);
        $this->assertGreaterThanOrEqual(4, $resultat['jours_retard']);
        $this->assertFalse($resultat['forclusion_automatique']);
        $this->assertFalse($resultat['dossier_associe_mute']);
        $this->assertSame($echeanceInitiale->toIso8601String(), $resultat['echeance_initiale']);
    }

    /**
     * Q137 / EXC-001 :
     * Le dépôt d'une demande de dérogation exige un motif circonstancié obligatoire
     * et conserve la demande sans être réputée acceptée.
     */
    public function test_depot_demande_derogation_exige_motif_circonstancie_et_preserve_attente(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Le motif circonstancié est obligatoire pour instruire une demande de dérogation.');

        $this->service->deposerDemandeDerogation(
            $this->etudiant,
            objet: 'Prolongation délai inscription',
            motif: '   '
        );
    }

    /**
     * Q138 / AUTH-007, AUTH-008 :
     * Statuer sur une dérogation : mode fail-closed par défaut tant que le mécanisme
     * n'est pas établi, puis contrôle strict de la compétence de l'autorité.
     */
    public function test_statuer_derogation_fail_closed_puis_controle_competence_autorite(): void
    {
        $depot = $this->service->deposerDemandeDerogation(
            $this->etudiant,
            objet: 'Dérogation inscription pédagogique',
            motif: 'Hospitalisation justifiée par certificat médical certifié.',
            pieceHash: hash('sha256', 'certificat_medical_hopital_militaire'),
            autoriteDesignee: 'Doyen'
        );

        // 1. Échec fail-closed par défaut (aucun mécanisme identifié)
        try {
            $this->service->statuerDerogation(
                $this->etudiant,
                $depot['demande_id'],
                decision: 'accordee',
                autoriteQualite: 'Doyen',
                referenceActeOfficiel: 'DEC-2026-99'
            );
            $this->fail('Un mécanisme non identifié aurait dû lever une DomainException.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Aucun mécanisme officiel de dérogation n’est établi', $e->getMessage());
        }

        // 2. Activation du mécanisme avec autorité compétente établie
        config([
            'loop146.derogations.mecanisme_identifie' => true,
            'loop146.derogations.autorites_competentes' => ['Doyen', 'President_Universite'],
        ]);

        // Tentative par autorité incompétente (ex: 'Secretaire')
        try {
            $this->service->statuerDerogation(
                $this->etudiant,
                $depot['demande_id'],
                decision: 'accordee',
                autoriteQualite: 'Secretaire',
                referenceActeOfficiel: 'DEC-2026-99'
            );
            $this->fail('Une autorité incompétente aurait dû lever une DomainException.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Compétence non établie', $e->getMessage());
        }

        // Décision valide par le Doyen
        $decision = $this->service->statuerDerogation(
            $this->etudiant,
            $depot['demande_id'],
            decision: 'accordee',
            autoriteQualite: 'Doyen',
            referenceActeOfficiel: 'Arrete-Decanal-2026-441',
            agentSignataireId: 1
        );

        $this->assertSame('derogation_accordee', $decision['statut']);
        $this->assertSame('Arrete-Decanal-2026-441', $decision['reference_acte']);
        $this->assertFalse($decision['mutation_dossier_associe']);
    }

    /**
     * Q139, Q140 / BUS-006, AUTH-008 :
     * La relance préserve la stricte neutralité face au silence administratif,
     * requiert un canal institutionnel établi et n'invente aucun délai d'escalade.
     */
    public function test_relance_derogation_fail_closed_puis_canal_officiel_sans_delai_invente(): void
    {
        $depot = $this->service->deposerDemandeDerogation(
            $this->etudiant,
            objet: 'Dérogation module',
            motif: 'Chevauchement justifié avec stage académique conventionné.',
            autoriteDesignee: 'Commission_Pedagogique'
        );

        // 1. Échec sur canal non établi
        try {
            $this->service->relancerDemandeDerogation(
                $this->etudiant,
                $depot['demande_id'],
                canalRelance: 'sms_non_officiel'
            );
            $this->fail('Un canal non établi aurait dû lever une DomainException.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Aucun canal institutionnel de relance n’est établi', $e->getMessage());
        }

        // 2. Avec canal officiel configuré
        config(['loop146.derogations.canaux_relance' => ['portail', 'courrier']]);

        $relance = $this->service->relancerDemandeDerogation(
            $this->etudiant,
            $depot['demande_id'],
            canalRelance: 'portail'
        );

        $this->assertSame('en_cours_sans_reponse', $relance['statut_instruction']);
        $this->assertSame('aucune', $relance['inference_silence']);
        $this->assertFalse($relance['delai_invente']);
        $this->assertNull($relance['echeance_generee']);
        $this->assertStringContainsString('aucun délai n’est garanti', $relance['information_usager']);
    }

    /**
     * Q141 / BUS-007 :
     * Information de la personne sur l'attente prolongée : transparente, exacte,
     * sans promesse de délai ni préjuger de la décision finale.
     */
    public function test_information_attente_prolongee_est_transparente_et_ne_prejuge_rien(): void
    {
        $depot = $this->service->deposerDemandeDerogation(
            $this->etudiant,
            objet: 'Attente délibération',
            motif: 'Instruction en cours.'
        );

        $info = $this->service->informerAttenteProlongee(
            $this->etudiant,
            $depot['demande_id'],
            demarchesEntreprises: ['Relance portail effectuée le 2026-10-25'],
            reponseRecue: false
        );

        $this->assertNull($info['delai_promis']);
        $this->assertFalse($info['decision_prejugee']);
        $this->assertFalse($info['statut_modifie']);
        $this->assertStringContainsString('clarification de compétence est en attente', $info['information_usager']);
    }

    /**
     * Q142 / RECOURS-005, BUS-008 :
     * Contestation d'une attente prolongée : enregistrement, respect des voies officielles
     * et absence de mutation du dossier initial.
     */
    public function test_contestation_attente_prolongee_enregistree_sans_mutation_dossier(): void
    {
        $depot = $this->service->deposerDemandeDerogation(
            $this->etudiant,
            objet: 'Dérogation inscription tardive',
            motif: 'Attente de visa étudiant officiel.',
            autoriteDesignee: 'Doyen'
        );

        // Configuration d'une voie officielle
        config(['loop146.derogations.voies_recours' => ['Commission_Recours_Gracieux']]);

        $contestation = $this->service->enregistrerContestationAttente(
            $this->etudiant,
            $depot['demande_id'],
            motifContestation: 'Le délai d’instruction dépasse les standards habituels sans notification.'
        );

        $this->assertTrue($contestation['contestation_enregistree']);
        $this->assertFalse($contestation['statut_modifie']);
        $this->assertFalse($contestation['mutation_dossier_initial']);
        $this->assertFalse($contestation['procedure_inventee']);
        $this->assertContains('Commission_Recours_Gracieux', $contestation['voies_recours_officielles']);
    }

    /**
     * Q143 / RECOURS-004 :
     * Transmission pour réexamen sans délai inventé, vérification de la voie officielle.
     */
    public function test_transmission_reexamen_fail_closed_puis_voie_officielle_sans_delai_garanti(): void
    {
        $depot = $this->service->deposerDemandeDerogation(
            $this->etudiant,
            objet: 'Réexamen note délibération',
            motif: 'Erreur matérielle de transcription sur le procès-verbal.',
            autoriteDesignee: 'Doyen'
        );

        // 1. Échec si la voie n'est pas établie
        try {
            $this->service->transmettrePourReexamen(
                $this->etudiant,
                $depot['demande_id'],
                voieTransmission: 'Voie_Inconnue',
                referenceTransmission: 'REF-2026-01'
            );
            $this->fail('Une voie non établie aurait dû lever une DomainException.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Voie de réexamen non établie', $e->getMessage());
        }

        // 2. Avec voie officielle configurée
        config(['loop146.derogations.voies_reexamen' => ['Commission_Recours_Facultaire']]);

        $transmission = $this->service->transmettrePourReexamen(
            $this->etudiant,
            $depot['demande_id'],
            voieTransmission: 'Commission_Recours_Facultaire',
            referenceTransmission: 'Arrete-2026-Recours'
        );

        $this->assertNull($transmission['delai_fixe']);
        $this->assertNull($transmission['delai_garanti']);
        $this->assertTrue($transmission['preuve_transmission_enregistree']);
        $this->assertSame('transmis_pour_reexamen', $transmission['statut']);
    }

    /**
     * Q144 / HIST-008 :
     * Décision de réexamen insuffisamment motivée : conservation verbatim sans interprétation suppléée.
     */
    public function test_decision_reexamen_insuffisamment_motivee_preservee_verbatim_sans_extrapolation(): void
    {
        $depot = $this->service->deposerDemandeDerogation(
            $this->etudiant,
            objet: 'Réexamen décision',
            motif: 'Motif légitime.',
            autoriteDesignee: 'Doyen'
        );

        $resultat = $this->service->enregistrerDecisionInsuffisammentMotivee(
            $this->etudiant,
            $depot['demande_id'],
            decisionTexte: 'La commission maintient sa décision antérieure.',
            motifsFournis: null,
            referenceDecision: 'DEC-2026-004'
        );

        $this->assertSame('decision_enregistree_motivation_insuffisante', $resultat['statut']);
        $this->assertSame('La commission maintient sa décision antérieure.', $resultat['decision_verbatim']);
        $this->assertNull($resultat['motifs_fournis']);
        $this->assertTrue($resultat['motivation_insuffisante']);
        $this->assertFalse($resultat['motifs_completes']);
        $this->assertFalse($resultat['interpretation_supplee']);
    }

    /**
     * Q145 / STAT-007 :
     * Décision ambiguë : fige l'ambiguïté sans présumer l'issue.
     */
    public function test_decision_ambiguë_figee_sans_presumer_issue(): void
    {
        $depot = $this->service->deposerDemandeDerogation(
            $this->etudiant,
            objet: 'Réexamen décision',
            motif: 'Demande réexamen.',
            autoriteDesignee: 'Doyen'
        );

        $ambigue = $this->service->enregistrerDecisionAmbiguë(
            $this->etudiant,
            $depot['demande_id'],
            contenuDecision: 'La commission a examiné la demande sans voter d’avis explicite.',
            referenceDecision: 'PV-2026-009'
        );

        $this->assertSame('decision_recue_ambigue', $ambigue['statut']);
        $this->assertNull($ambigue['issue_presumee']);
        $this->assertFalse($ambigue['acceptation_presumee']);
        $this->assertFalse($ambigue['refus_presume']);
        $this->assertTrue($ambigue['decision_initiale_conservee']);
        $this->assertSame('demande_clarification_officielle', $ambigue['action_requise']);
    }

    /**
     * Q146 / STAT-008, BUS-004 :
     * Clarification officielle : conserve la décision initiale ambiguë consultable,
     * ne préjuge pas de l'option non confirmée et interdit toute mutation automatique.
     */
    public function test_clarification_decision_conserve_decision_initiale_sans_mutation_automatique(): void
    {
        $depot = $this->service->deposerDemandeDerogation(
            $this->etudiant,
            objet: 'Réexamen note',
            motif: 'Erreur de saisie.',
            autoriteDesignee: 'Doyen'
        );

        $clarification = $this->service->appliquerClarificationDecision(
            $this->etudiant,
            $depot['demande_id'],
            issueClarifiee: 'refus',
            referenceClarification: 'PV-Commission-Rectificatif-2026-03',
            auteurClarification: 'Secrétaire Général',
            dateClarification: now()
        );

        $this->assertSame('clarification_enregistree', $clarification['statut']);
        $this->assertNull($clarification['statut_final']); // Q146 non confirmée : statut final ouvert
        $this->assertFalse($clarification['decision_finale_appliquee']);
        $this->assertFalse($clarification['option_q146_confirmee']);
        $this->assertTrue($clarification['decision_initiale_ambigue_preservee']);
        $this->assertFalse($clarification['mutations_automatiques_effectuees']); // BUS-004
        $this->assertStringContainsString('aucun dossier associé n\'a été muté automatiquement', $clarification['avertissement']);
    }
}
