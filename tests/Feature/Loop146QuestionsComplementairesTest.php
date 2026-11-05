<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\IntegrationLog;
use App\Services\EcheanceDerogationService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP 146 — preuves complémentaires Q135 à Q146.
 *
 * Ce fichier complète `EcheanceDerogationServiceTest` (édité en parallèle)
 * sans le modifier : cas limites, idempotence, absence de fondement officiel,
 * conservation verbatim et non-application de l'option Q146 non confirmée.
 */
class Loop146QuestionsComplementairesTest extends TestCase
{
    use RefreshDatabase;

    private EcheanceDerogationService $service;

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

        $this->service = app(EcheanceDerogationService::class);

        $faculte = Faculte::create([
            'code' => 'FST',
            'nom' => 'Faculté des Sciences et Techniques',
        ]);

        $this->etudiant = Etudiant::create([
            'cin' => '61460021',
            'nom' => 'Bennani',
            'prenom' => 'Yasmine',
            'email' => 'yasmine.bennani@universite.ac.ma',
            'date_naissance' => '2003-11-04',
            'sexe' => 'F',
            'faculte_id' => $faculte->id,
            'niveau' => 'L2',
            'annee_academique' => '2026-2027',
            'adresse' => 'Rabat Agdal',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2023',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');
    }

    // ------------------------------------------------------------------
    // Q135 / Q136 — constat de dépassement
    // ------------------------------------------------------------------

    public function test_q135_sans_regle_officielle_signale_verification_sans_consequence(): void
    {
        $echeance = now()->subDays(10)->startOfSecond();

        $resultat = $this->service->constaterDepassementEcheance(
            $this->etudiant,
            typeEcheance: 'depot_dossier_bourse',
            echeanceInitiale: $echeance,
            referenceReglementaire: null,
            agentId: 7
        );

        $this->assertTrue($resultat['depassement_constate']);
        $this->assertTrue($resultat['verification_requise']);
        $this->assertFalse($resultat['regle_officielle_identifiee']);
        $this->assertFalse($resultat['consequences_appliquees']);
        $this->assertFalse($resultat['dossier_associe_mute']);
        $this->assertFalse($resultat['forclusion_automatique']);
        $this->assertSame($echeance->toIso8601String(), $resultat['echeance_initiale']);
        $this->assertStringContainsString('fondement officiel non identifié', $resultat['suites_reglementaires']);
    }

    public function test_q135_reference_vide_equivaut_a_absence_de_regle(): void
    {
        $resultat = $this->service->constaterDepassementEcheance(
            $this->etudiant,
            typeEcheance: 'depot_justificatif',
            echeanceInitiale: now()->subDay(),
            referenceReglementaire: '   '
        );

        $this->assertFalse($resultat['regle_officielle_identifiee']);
        $this->assertTrue($resultat['verification_requise']);
        $this->assertFalse($resultat['consequences_appliquees']);
    }

    public function test_q136_regle_identifiee_ne_declenche_toujours_aucune_consequence(): void
    {
        $resultat = $this->service->constaterDepassementEcheance(
            $this->etudiant,
            typeEcheance: 'depot_justificatif',
            echeanceInitiale: now()->subDays(3)->startOfSecond(),
            referenceReglementaire: 'Reglement-Etudes-Art-22'
        );

        $this->assertTrue($resultat['regle_officielle_identifiee']);
        $this->assertFalse($resultat['verification_requise']);
        $this->assertFalse($resultat['consequences_appliquees']);
        $this->assertFalse($resultat['dossier_associe_mute']);
        $this->assertStringContainsString('Reglement-Etudes-Art-22', $resultat['suites_reglementaires']);
    }

    public function test_q136_echeance_future_ne_produit_aucun_constat(): void
    {
        $resultat = $this->service->constaterDepassementEcheance(
            $this->etudiant,
            typeEcheance: 'depot_justificatif',
            echeanceInitiale: now()->addDays(5)
        );

        $this->assertFalse($resultat['depassement_constate']);
        $this->assertFalse($resultat['verification_requise']);
        $this->assertSame(0, $resultat['jours_retard']);
        $this->assertFalse($resultat['consequences_appliquees']);
    }

    public function test_q135_journal_integration_ne_contient_aucune_donnee_personnelle(): void
    {
        $this->service->constaterDepassementEcheance(
            $this->etudiant,
            typeEcheance: 'depot_justificatif',
            echeanceInitiale: now()->subDay()
        );

        $log = IntegrationLog::query()->latest('id')->firstOrFail();
        $contenu = (string) $log->contenu;

        $this->assertStringNotContainsString($this->etudiant->nom, $contenu);
        $this->assertStringNotContainsString($this->etudiant->prenom, $contenu);
        $this->assertStringNotContainsString($this->etudiant->email, $contenu);
        $this->assertStringNotContainsString((string) $this->etudiant->cin, $contenu);
    }

    // ------------------------------------------------------------------
    // Q137 / Q138 — dépôt et compétence
    // ------------------------------------------------------------------

    public function test_q137_objet_vide_rejete(): void
    {
        $this->attendreDomainException(
            fn () => $this->service->deposerDemandeDerogation(
                $this->etudiant,
                objet: '   ',
                motif: 'Motif circonstancié valable.'
            ),
            'L’objet de la demande de dérogation est obligatoire'
        );
    }

    public function test_q137_depot_par_defaut_reste_en_attente_sans_modifier_delai(): void
    {
        $depot = $this->service->deposerDemandeDerogation(
            $this->etudiant,
            objet: 'Dérogation inscription tardive',
            motif: 'Hospitalisation documentée par certificat médical.',
            pieceHash: hash('sha256', 'certificat-medical')
        );

        $this->assertFalse($depot['mecanisme_officiel_identifie']);
        $this->assertFalse($depot['competence_etablie']);
        $this->assertTrue($depot['competence_incertaine']);
        $this->assertSame('en_attente_etablissement_competence', $depot['statut']);
        $this->assertFalse($depot['delai_modifie']);
        $this->assertFalse($depot['consequences_appliquees']);
        $this->assertSame(hash('sha256', 'certificat-medical'), $depot['piece_hash']);
    }

    public function test_q138_autorite_designee_mais_non_etablie_reste_en_attente(): void
    {
        config([
            'loop146.derogations.mecanisme_identifie' => true,
            'loop146.derogations.autorites_competentes' => ['Doyen'],
        ]);

        $depot = $this->service->deposerDemandeDerogation(
            $this->etudiant,
            objet: 'Dérogation module',
            motif: 'Chevauchement avec stage conventionné.',
            autoriteDesignee: 'Secretaire'
        );

        $this->assertTrue($depot['mecanisme_officiel_identifie']);
        $this->assertFalse($depot['competence_etablie']);
        $this->assertSame('en_attente_etablissement_competence', $depot['statut']);
    }

    public function test_q138_decision_invalide_rejetee(): void
    {
        config([
            'loop146.derogations.mecanisme_identifie' => true,
            'loop146.derogations.autorites_competentes' => ['Doyen'],
        ]);

        $this->attendreDomainException(
            fn () => $this->service->statuerDerogation(
                $this->etudiant,
                demandeId: 'DEROG-1',
                decision: 'peut_etre',
                autoriteQualite: 'Doyen',
                referenceActeOfficiel: 'DEC-2026-01'
            ),
            'Décision de dérogation invalide'
        );
    }

    public function test_q138_decision_sans_reference_officielle_rejetee(): void
    {
        config([
            'loop146.derogations.mecanisme_identifie' => true,
            'loop146.derogations.autorites_competentes' => ['Doyen'],
        ]);

        $this->attendreDomainException(
            fn () => $this->service->statuerDerogation(
                $this->etudiant,
                demandeId: 'DEROG-1',
                decision: 'accordee',
                autoriteQualite: 'Doyen',
                referenceActeOfficiel: '   '
            ),
            'La référence de l’acte administratif'
        );
    }

    // ------------------------------------------------------------------
    // Q139 / Q140 — relance et silence
    // ------------------------------------------------------------------

    public function test_q139_canal_vide_rejete(): void
    {
        config(['loop146.derogations.canaux_relance' => ['portail']]);

        $this->attendreDomainException(
            fn () => $this->service->relancerDemandeDerogation(
                $this->etudiant,
                demandeId: 'DEROG-1',
                canalRelance: '   '
            ),
            'Le canal de relance est obligatoire'
        );
    }

    public function test_q139_relance_identique_rejouee_est_idempotente(): void
    {
        config(['loop146.derogations.canaux_relance' => ['portail']]);

        $premiere = $this->service->relancerDemandeDerogation(
            $this->etudiant,
            demandeId: 'DEROG-1',
            canalRelance: 'portail',
            referencePreuveRelance: 'REL-2026-001',
            cleIdempotence: 'soumission-A'
        );

        $seconde = $this->service->relancerDemandeDerogation(
            $this->etudiant,
            demandeId: 'DEROG-1',
            canalRelance: 'portail',
            referencePreuveRelance: 'REL-2026-001',
            cleIdempotence: 'soumission-A'
        );

        $this->assertFalse($premiere['deja_enregistree']);
        $this->assertTrue($seconde['deja_enregistree']);
        $this->assertSame($premiere['relance_id'], $seconde['relance_id']);
        $this->assertSame(1, HistoriqueAcademique::where('action', 'relance_demande_derogation')->count());
        $this->assertNull($seconde['echeance_generee']);
        $this->assertFalse($seconde['delai_invente']);
    }

    public function test_q139_deux_relances_distinctes_ne_sont_pas_confondues(): void
    {
        config(['loop146.derogations.canaux_relance' => ['portail', 'courrier']]);

        $premiere = $this->service->relancerDemandeDerogation(
            $this->etudiant,
            demandeId: 'DEROG-1',
            canalRelance: 'portail',
            cleIdempotence: 'soumission-A'
        );

        $seconde = $this->service->relancerDemandeDerogation(
            $this->etudiant,
            demandeId: 'DEROG-1',
            canalRelance: 'courrier',
            cleIdempotence: 'soumission-B'
        );

        $this->assertNotSame($premiere['relance_id'], $seconde['relance_id']);
        $this->assertFalse($seconde['deja_enregistree']);
        $this->assertSame(2, HistoriqueAcademique::where('action', 'relance_demande_derogation')->count());
    }

    public function test_q139_relance_sans_preuve_reste_traçable_sans_delai(): void
    {
        config(['loop146.derogations.canaux_relance' => ['portail']]);

        $relance = $this->service->relancerDemandeDerogation(
            $this->etudiant,
            demandeId: 'DEROG-1',
            canalRelance: 'portail'
        );

        $this->assertFalse($relance['preuve_relance_presente']);
        $this->assertSame('aucune', $relance['inference_silence']);
        $this->assertNull($relance['echeance_generee']);
        $this->assertSame('en_cours_sans_reponse', $relance['statut_instruction']);
    }

    public function test_q140_reponse_recue_reste_sans_prejuge_ni_delai(): void
    {
        $information = $this->service->informerAttenteProlongee(
            $this->etudiant,
            demandeId: 'DEROG-1',
            demarchesEntreprises: ['Relance portail'],
            reponseRecue: true,
            statutInstructionActuel: 'en_cours_sans_reponse'
        );

        $this->assertTrue($information['reponse_recue']);
        $this->assertNull($information['delai_promis']);
        $this->assertFalse($information['decision_prejugee']);
        $this->assertFalse($information['statut_modifie']);
        $this->assertSame('en_cours_sans_reponse', $information['statut_instruction']);
        $this->assertStringContainsString('aucune décision n’est préjugée', $information['information_usager']);

        $audit = HistoriqueAcademique::where('action', 'information_attente_prolongee')->firstOrFail();
        $valeurs = json_decode((string) $audit->nouvelle_valeur, true);
        $this->assertNull($valeurs['delai_promis']);
        $this->assertFalse($valeurs['decision_prejugee']);
        $this->assertFalse($valeurs['statut_modifie']);
    }

    public function test_q140_second_silence_apres_relance_ne_vaut_pas_decision(): void
    {
        config(['loop146.derogations.canaux_relance' => ['portail']]);

        $this->service->relancerDemandeDerogation(
            $this->etudiant,
            demandeId: 'DEROG-1',
            canalRelance: 'portail',
            cleIdempotence: 'soumission-A'
        );

        $information = $this->service->informerAttenteProlongee(
            $this->etudiant,
            demandeId: 'DEROG-1',
            demarchesEntreprises: ['Relance portail'],
            reponseRecue: false
        );

        $this->assertFalse($information['reponse_recue']);
        $this->assertNull($information['delai_promis']);
        $this->assertFalse($information['decision_prejugee']);
        $this->assertStringContainsString('Aucune réponse', $information['information_usager']);
        $this->assertStringContainsString('Aucun délai ne peut être garanti', $information['information_usager']);
    }

    // ------------------------------------------------------------------
    // Q142 — contestation d'attente
    // ------------------------------------------------------------------

    public function test_q142_motif_vide_rejete(): void
    {
        $this->attendreDomainException(
            fn () => $this->service->enregistrerContestationAttente(
                $this->etudiant,
                demandeId: 'DEROG-1',
                motifContestation: '   '
            ),
            'Le motif de la contestation d’attente prolongée est obligatoire'
        );
    }

    public function test_q142_sans_voie_configuree_absence_documentee_et_statut_preserve(): void
    {
        $contestation = $this->service->enregistrerContestationAttente(
            $this->etudiant,
            demandeId: 'DEROG-1',
            motifContestation: 'Attente prolongée sans réponse officielle.',
            statutInstructionActuel: 'en_instruction'
        );

        $this->assertSame([], $contestation['voies_recours_officielles']);
        $this->assertFalse($contestation['statut_modifie']);
        $this->assertSame('en_instruction', $contestation['statut_instruction_apres']);
        $this->assertFalse($contestation['procedure_inventee']);
        $this->assertFalse($contestation['mutation_dossier_initial']);
        $this->assertStringContainsString('absence de voie est documentée', $contestation['information_factuelle']);
    }

    public function test_q142_contestation_identique_rejouee_est_idempotente(): void
    {
        config(['loop146.derogations.voies_recours' => ['Commission_Recours_Facultaire']]);

        $premiere = $this->service->enregistrerContestationAttente(
            $this->etudiant,
            demandeId: 'DEROG-1',
            motifContestation: 'Attente prolongée sans réponse officielle.'
        );

        $seconde = $this->service->enregistrerContestationAttente(
            $this->etudiant,
            demandeId: 'DEROG-1',
            motifContestation: 'Attente prolongée sans réponse officielle.'
        );

        $this->assertFalse($premiere['deja_enregistree']);
        $this->assertTrue($seconde['deja_enregistree']);
        $this->assertSame($premiere['contestation_id'], $seconde['contestation_id']);
        $this->assertSame(1, HistoriqueAcademique::where('action', 'contestation_attente_prolongee')->count());
        $this->assertFalse($seconde['statut_modifie']);
    }

    // ------------------------------------------------------------------
    // Q143 — réexamen
    // ------------------------------------------------------------------

    public function test_q143_reference_vide_rejetee(): void
    {
        config(['loop146.derogations.voies_reexamen' => ['Commission_Recours_Facultaire']]);

        $this->attendreDomainException(
            fn () => $this->service->transmettrePourReexamen(
                $this->etudiant,
                demandeId: 'DEROG-1',
                voieTransmission: 'Commission_Recours_Facultaire',
                referenceTransmission: '   '
            ),
            'La référence de la transmission officielle est obligatoire'
        );
    }

    public function test_q143_transmission_identique_rejouee_est_idempotente(): void
    {
        config(['loop146.derogations.voies_reexamen' => ['Commission_Recours_Facultaire']]);

        $premiere = $this->service->transmettrePourReexamen(
            $this->etudiant,
            demandeId: 'DEROG-1',
            voieTransmission: 'Commission_Recours_Facultaire',
            referenceTransmission: 'TRX-2026-001'
        );

        $seconde = $this->service->transmettrePourReexamen(
            $this->etudiant,
            demandeId: 'DEROG-1',
            voieTransmission: 'Commission_Recours_Facultaire',
            referenceTransmission: 'TRX-2026-001'
        );

        $this->assertFalse($premiere['deja_enregistree']);
        $this->assertTrue($seconde['deja_enregistree']);
        $this->assertSame($premiere['transmission_id'], $seconde['transmission_id']);
        $this->assertSame(1, HistoriqueAcademique::where('action', 'transmission_reexamen')->count());
        $this->assertNull($seconde['delai_confirme']);
        $this->assertNull($seconde['delai_fixe']);
        $this->assertNull($seconde['delai_garanti']);
    }

    public function test_q143_alias_reference_officielle_voie_accepte_sans_delai(): void
    {
        config(['loop146.derogations.voies_reexamen' => ['Commission_Recours_Facultaire']]);

        $transmission = $this->service->transmettrePourReexamen(
            $this->etudiant,
            demandeId: 'DEROG-1',
            voieTransmission: 'Commission_Recours_Facultaire',
            referenceTransmission: null,
            referenceOfficielleVoie: 'REF-VOIE-2026-09'
        );

        $this->assertSame('REF-VOIE-2026-09', $transmission['reference_transmission']);
        $this->assertTrue($transmission['preuve_transmission_enregistree']);
        $this->assertFalse($transmission['decision_deduite_du_silence']);
    }

    public function test_q143_destinataire_officiel_et_horodatage_traces(): void
    {
        config(['loop146.derogations.voies_reexamen' => ['Commission_Recours_Facultaire']]);

        $transmission = $this->service->transmettrePourReexamen(
            $this->etudiant,
            demandeId: 'DEROG-1',
            voieTransmission: 'Commission_Recours_Facultaire',
            referenceTransmission: 'TRX-2026-002',
            sourceOfficielle: 'Règlement des recours, art. 7',
            destinataireOfficiel: 'Secrétariat de la commission facultaire'
        );

        $this->assertSame('Secrétariat de la commission facultaire', $transmission['destinataire_officiel']);
        $this->assertTrue($transmission['preuve_transmission_enregistree']);
        $this->assertNull($transmission['delai_confirme']);

        $audit = HistoriqueAcademique::where('action', 'transmission_reexamen')->firstOrFail();
        $donnees = json_decode((string) $audit->nouvelle_valeur, true);
        $this->assertSame('Secrétariat de la commission facultaire', $donnees['destinataire_officiel']);
        $this->assertSame('Règlement des recours, art. 7', $donnees['source_officielle']);
        $this->assertNull($donnees['delai_fixe']);
        $this->assertNotNull($donnees['transmission_at']);
    }

    // ------------------------------------------------------------------
    // Q144 / Q145 — motivation et ambiguïté
    // ------------------------------------------------------------------

    public function test_q144_decision_vide_rejetee(): void
    {
        $this->attendreDomainException(
            fn () => $this->service->enregistrerDecisionInsuffisammentMotivee(
                $this->etudiant,
                demandeId: 'DEROG-1',
                decisionTexte: '   '
            ),
            'Le texte de la décision est obligatoire'
        );
    }

    public function test_q144_texte_et_motifs_conserves_verbatim_dans_audit(): void
    {
        $texte = "  Décision du 28/10/2026 :\nla commission maintient sa position.  ";
        $motifs = '  Motif succinct.  ';

        $resultat = $this->service->enregistrerDecisionInsuffisammentMotivee(
            $this->etudiant,
            demandeId: 'DEROG-1',
            decisionTexte: $texte,
            motifsFournis: $motifs,
            referenceDecision: 'DEC-REEX-2026-07',
            signalementAutorise: false
        );

        $this->assertSame($texte, $resultat['decision_verbatim']);
        $this->assertSame($motifs, $resultat['motifs_fournis']);
        $this->assertFalse($resultat['signalement_autorise']);

        $audit = HistoriqueAcademique::where('action', 'decision_insuffisamment_motivee')->firstOrFail();
        $valeurs = json_decode((string) $audit->nouvelle_valeur, true);
        $this->assertSame($texte, $valeurs['decision_texte']);
        $this->assertSame($motifs, $valeurs['motifs_fournis']);
        $this->assertFalse($valeurs['motifs_completes']);
        $this->assertFalse($valeurs['interpretation_supplee']);
        $this->assertFalse($valeurs['signalement_autorise']);
    }

    public function test_q145_contenu_vide_rejete(): void
    {
        $this->attendreDomainException(
            fn () => $this->service->enregistrerDecisionAmbiguë(
                $this->etudiant,
                demandeId: 'DEROG-1',
                contenuDecision: '   '
            ),
            'Le contenu de la décision est obligatoire'
        );
    }

    public function test_q145_alias_reference_acte_et_contenu_integral_dans_audit(): void
    {
        $contenu = "Procès-verbal du 28/10/2026 :\n« Le dossier a été examiné. »";

        $ambigue = $this->service->enregistrerDecisionAmbiguë(
            $this->etudiant,
            demandeId: 'DEROG-1',
            contenuDecision: $contenu,
            referenceActe: 'PV-2026-010'
        );

        $this->assertSame('PV-2026-010', $ambigue['reference_decision']);
        $this->assertNull($ambigue['issue_presumee']);
        $this->assertFalse($ambigue['derogation_accordee']);
        $this->assertFalse($ambigue['derogation_refusee']);

        $audit = HistoriqueAcademique::where('action', 'decision_reexamen_ambigue')->firstOrFail();
        $valeurs = json_decode((string) $audit->nouvelle_valeur, true);
        $this->assertSame($contenu, $valeurs['contenu_decision']);
        $this->assertSame(hash('sha256', $contenu), $valeurs['contenu_hash']);
        $this->assertFalse($valeurs['acceptation_presumee']);
        $this->assertFalse($valeurs['refus_presume']);
    }

    // ------------------------------------------------------------------
    // Q146 — clarification non confirmée
    // ------------------------------------------------------------------

    public function test_q146_decision_initiale_reste_consultable_apres_clarification(): void
    {
        $contenuInitial = 'Décision initiale ambiguë conservée intégralement.';

        $this->service->enregistrerDecisionAmbiguë(
            $this->etudiant,
            demandeId: 'DEROG-1',
            contenuDecision: $contenuInitial,
            referenceActe: 'PV-AMB-2026-01'
        );

        $clarification = $this->service->appliquerClarificationDecision(
            $this->etudiant,
            demandeId: 'DEROG-1',
            issueClarifiee: 'accord',
            referenceClarification: 'CLAR-2026-01',
            auteurClarification: 'Recteur',
            dateClarification: '2026-10-28'
        );

        $this->assertSame('clarification_enregistree', $clarification['statut']);
        $this->assertNull($clarification['statut_final']);
        $this->assertFalse($clarification['decision_finale_appliquee']);
        $this->assertFalse($clarification['option_q146_confirmee']);
        $this->assertTrue($clarification['decision_initiale_ambigue_preservee']);
        $this->assertFalse($clarification['mutations_automatiques_effectuees']);

        $auditInitial = HistoriqueAcademique::where('action', 'decision_reexamen_ambigue')->firstOrFail();
        $valeursInitiales = json_decode((string) $auditInitial->nouvelle_valeur, true);
        $this->assertSame($contenuInitial, $valeursInitiales['contenu_decision']);

        $auditClarification = HistoriqueAcademique::where('action', 'clarification_decision_enregistree')->firstOrFail();
        $valeursClarification = json_decode((string) $auditClarification->nouvelle_valeur, true);
        $this->assertSame('CLAR-2026-01', $valeursClarification['reference']);
        $this->assertSame('Recteur', $valeursClarification['auteur']);
        $this->assertFalse($valeursClarification['decision_finale_appliquee']);
        $this->assertFalse($valeursClarification['option_q146_confirmee']);
    }

    public function test_q146_auteur_competent_ne_declenche_pas_l_option_finale(): void
    {
        config([
            'loop146.derogations.mecanisme_identifie' => true,
            'loop146.derogations.autorites_competentes' => ['Recteur'],
        ]);

        $clarification = $this->service->appliquerClarificationDecision(
            $this->etudiant,
            demandeId: 'DEROG-1',
            issueClarifiee: 'refus',
            referenceClarification: 'CLAR-2026-02',
            auteurClarification: 'Recteur',
            dateClarification: '2026-10-28'
        );

        $this->assertTrue($clarification['autorite_etablie']);
        $this->assertNull($clarification['statut_final']);
        $this->assertFalse($clarification['decision_finale_appliquee']);
        $this->assertFalse($clarification['option_q146_confirmee']);
        $this->assertStringContainsString('Q146 NON CONFIRMÉE', $clarification['motif_non_application']);
    }

    public function test_q146_reference_vide_rejetee(): void
    {
        $this->attendreDomainException(
            fn () => $this->service->appliquerClarificationDecision(
                $this->etudiant,
                demandeId: 'DEROG-1',
                issueClarifiee: 'accord',
                referenceClarification: '   '
            ),
            'La référence de la clarification officielle est obligatoire'
        );
    }

    public function test_q146_issue_invalide_rejetee(): void
    {
        $this->attendreDomainException(
            fn () => $this->service->appliquerClarificationDecision(
                $this->etudiant,
                demandeId: 'DEROG-1',
                issueClarifiee: 'option_c',
                referenceClarification: 'CLAR-2026-03'
            ),
            'Issue clarifiée non reconnue'
        );
    }

    public function test_q146_date_invalide_rejetee(): void
    {
        $this->attendreDomainException(
            fn () => $this->service->appliquerClarificationDecision(
                $this->etudiant,
                demandeId: 'DEROG-1',
                issueClarifiee: 'accord',
                referenceClarification: 'CLAR-2026-04',
                dateClarification: 'date-invalide'
            ),
            'La date de la clarification officielle est invalide'
        );
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
