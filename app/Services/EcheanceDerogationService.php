<?php

namespace App\Services;

use App\Models\Etudiant;
use App\Models\HistoriqueAcademique;
use App\Models\IntegrationLog;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Service de gestion des échéances réglementaires, dérogations administratives
 * et décisions de réexamen (LOOP 146 — Q132 à Q146).
 *
 * Règles absolues :
 * - DELAI-002 / HIST-007 : l'échéance originale est immuable ; un dépassement ne l'écrase jamais.
 * - Q135 / Q136 : le constat du dépassement est distinct de ses conséquences ; aucune
 *   conséquence n'est appliquée sans règle officielle expresse.
 * - Q137 / Q138 : aucune autorité n'est réputée compétente par défaut. La compétence
 *   provient exclusivement de `config/loop146.php`, vide tant qu'aucun texte officiel
 *   n'a été produit. À défaut, le service échoue de manière sûre (fail-closed).
 * - BUS-006 : le silence prolongé de l'administration ne vaut ni accord ni refus tacite.
 * - Q139 / Q140 : aucune relance hors canal institutionnel existant, aucun délai inventé.
 * - Q143 : aucune transmission sans référence officielle et sans voie officielle établie.
 * - Q144 : les décisions et motifs sont conservés verbatim, jamais interprétés ni complétés.
 * - Q145 : une décision ambiguë est conservée dans son intégralité, sans présumer l'issue.
 * - Q146 : NON CONFIRMÉE. Une clarification est enregistrée sans appliquer aucune des
 *   options A/B/C et sans fixer de statut final.
 */
class EcheanceDerogationService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Q135, Q136 / DELAI-002, DELAI-003, HIST-007 :
     * Enregistre un constat de dépassement d'échéance sans modifier l'échéance
     * initiale et sans appliquer de conséquence automatique.
     *
     * @return array{
     *     etudiant_id: int,
     *     type_echeance: string,
     *     echeance_initiale: string,
     *     date_constat: string,
     *     depassement_constate: bool,
     *     forclusion_automatique: bool,
     *     jours_retard: int,
     *     regle_officielle_identifiee: bool,
     *     verification_requise: bool,
     *     consequences_appliquees: bool,
     *     dossier_associe_mute: bool,
     *     suites_reglementaires: string
     * }
     */
    public function constaterDepassementEcheance(
        Etudiant $etudiant,
        string $typeEcheance,
        Carbon|string $echeanceInitiale,
        ?string $referenceReglementaire = null,
        ?int $agentId = null,
    ): array {
        $dateInitiale = $echeanceInitiale instanceof Carbon
            ? $echeanceInitiale
            : Carbon::parse($echeanceInitiale);

        $reference = $referenceReglementaire !== null ? trim($referenceReglementaire) : null;
        $regleIdentifiee = $reference !== null && $reference !== '';

        $maintenant = now();
        $estDepassee = $maintenant->greaterThan($dateInitiale);
        $retardJours = $estDepassee ? (int) abs($dateInitiale->diffInDays($maintenant)) : 0;

        // Q135 : le risque est signalé, jamais corrigé silencieusement.
        $verificationRequise = $estDepassee && ! $regleIdentifiee;

        // Trace immuable scellée dans l'historique sans jamais altérer la date originale (HIST-007)
        $this->audit->tracer(
            $etudiant,
            'constat_depassement_echeance',
            'echeance',
            null,
            ['echeance_initiale' => $dateInitiale->toIso8601String()],
            [
                'type' => $typeEcheance,
                'constat_at' => $maintenant->toIso8601String(),
                'depasse' => $estDepassee,
                'jours_retard' => $retardJours,
                'reference_reglement' => $reference,
                'regle_officielle_identifiee' => $regleIdentifiee,
                'verification_requise' => $verificationRequise,
                'consequences_appliquees' => false,
                'dossier_associe_mute' => false,
                'agent_id' => $agentId,
            ]
        );

        IntegrationLog::tracer(
            integration: 'scolarite',
            direction: 'internal',
            operation: 'echeance.constat_depassement',
            externalReference: 'ETU-'.$etudiant->id,
            contenu: json_encode([
                'type' => $typeEcheance,
                'depassement' => $estDepassee,
                'jours' => $retardJours,
                'verification_requise' => $verificationRequise,
            ])
        );

        return [
            'etudiant_id' => $etudiant->id,
            'type_echeance' => $typeEcheance,
            'echeance_initiale' => $dateInitiale->toIso8601String(),
            'date_constat' => $maintenant->toIso8601String(),
            'depassement_constate' => $estDepassee,
            'forclusion_automatique' => false, // Interdiction de forclusion automatique sauvage (DELAI-003)
            'jours_retard' => $retardJours,
            'regle_officielle_identifiee' => $regleIdentifiee,
            'verification_requise' => $verificationRequise,
            'consequences_appliquees' => false, // Q136 : constat != conséquence
            'dossier_associe_mute' => false,
            'suites_reglementaires' => $regleIdentifiee
                ? "Conséquences à appliquer uniquement si expressément prévues par : {$reference}."
                : 'Aucune conséquence appliquée : fondement officiel non identifié, vérification requise.',
        ];
    }

    /**
     * Q137, Q138 / EXC-001, AUTH-007, AUTH-008 :
     * Réceptionne une demande de dérogation, conserve les preuves et documente
     * l'incertitude de compétence sans accepter ni refuser la demande.
     *
     * @return array{
     *     demande_id: string,
     *     etudiant_id: int,
     *     objet: string,
     *     statut: string,
     *     mecanisme_officiel_identifie: bool,
     *     competence_etablie: bool,
     *     competence_incertaine: bool,
     *     delai_modifie: bool,
     *     consequences_appliquees: bool,
     *     piece_hash: ?string,
     *     date_depot: string
     * }
     */
    public function deposerDemandeDerogation(
        Etudiant $etudiant,
        string $objet,
        string $motif,
        ?string $pieceHash = null,
        ?string $autoriteDesignee = null,
    ): array {
        $objetNettoye = trim($objet);
        if ($objetNettoye === '') {
            throw new DomainException('L’objet de la demande de dérogation est obligatoire.');
        }

        $motifNettoye = trim($motif);
        if ($motifNettoye === '') {
            throw new DomainException('Le motif circonstancié est obligatoire pour instruire une demande de dérogation.');
        }

        $mecanismeIdentifie = $this->mecanismeIdentifie();
        $autorite = $autoriteDesignee !== null ? trim($autoriteDesignee) : null;
        $autoriteReconnue = $autorite !== null
            && $autorite !== ''
            && in_array($autorite, $this->autoritesCompetentes(), true);

        // Q138 : la compétence n'est établie que si le mécanisme ET l'autorité le sont.
        $competenceEtablie = $mecanismeIdentifie && $autoriteReconnue;

        $demandeId = 'DEROG-'.$etudiant->id.'-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8));
        $statut = $competenceEtablie ? 'en_instruction' : 'en_attente_etablissement_competence';

        $this->audit->tracer(
            $etudiant,
            'depot_demande_derogation',
            'derogation',
            null,
            null,
            [
                'demande_id' => $demandeId,
                'objet' => $objetNettoye,
                'motif' => $motifNettoye,
                'piece_hash' => $pieceHash,
                'autorite' => $autorite,
                'mecanisme_officiel_identifie' => $mecanismeIdentifie,
                'competence_etablie' => $competenceEtablie,
                'competence_incertaine' => ! $competenceEtablie,
                'statut' => $statut,
                'delai_modifie' => false,
                'consequences_appliquees' => false,
            ]
        );

        return [
            'demande_id' => $demandeId,
            'etudiant_id' => $etudiant->id,
            'objet' => $objetNettoye,
            'statut' => $statut,
            'mecanisme_officiel_identifie' => $mecanismeIdentifie,
            'competence_etablie' => $competenceEtablie,
            'competence_incertaine' => ! $competenceEtablie,
            'delai_modifie' => false,
            'consequences_appliquees' => false,
            'piece_hash' => $pieceHash,
            'date_depot' => now()->toIso8601String(),
        ];
    }

    /**
     * Q139, Q140 / BUS-006, BUS-007 :
     * Enregistre une relance par un canal institutionnel existant, de façon
     * idempotente, sans inventer d'échéance ni déduire une décision du silence.
     *
     *
     * @return array{
     *     demande_id: string,
     *     relance_id: string,
     *     deja_enregistree: bool,
     *     canal: string,
     *     canal_officiel_etabli: bool,
     *     preuve_relance_presente: bool,
     *     statut_instruction: string,
     *     inference_silence: string,
     *     delai_invente: bool,
     *     echeance_generee: ?string,
     *     information_usager: string,
     *     date_relance: string
     * }
     *
     * @throws DomainException si le canal n'est pas un mécanisme institutionnel établi
     */
    public function relancerDemandeDerogation(
        Etudiant $etudiant,
        string $demandeId,
        string $canalRelance,
        ?string $referencePreuveRelance = null,
        ?string $cleIdempotence = null,
    ): array {
        $demande = trim($demandeId);
        if ($demande === '') {
            throw new DomainException('L’identifiant de la demande est obligatoire.');
        }

        $canal = trim($canalRelance);
        if ($canal === '') {
            throw new DomainException('Le canal de relance est obligatoire.');
        }

        if (! in_array($canal, $this->canauxRelance(), true)) {
            throw new DomainException("Aucun canal institutionnel de relance n’est établi pour « {$canal} » : la relance ne peut pas être enregistrée.");
        }

        $preuve = $referencePreuveRelance !== null ? trim($referencePreuveRelance) : null;
        $empreinte = $cleIdempotence !== null && trim($cleIdempotence) !== ''
            ? trim($cleIdempotence)
            : now()->format('Y-m-d');

        $relanceId = 'REL-'.hash('sha256', $etudiant->id.'|'.$demande.'|'.$canal.'|'.$empreinte);

        if ($this->evenementExiste($etudiant, 'relance_demande_derogation', 'relance_id', $relanceId)) {
            return [
                'demande_id' => $demande,
                'relance_id' => $relanceId,
                'deja_enregistree' => true,
                'canal' => $canal,
                'canal_officiel_etabli' => true,
                'preuve_relance_presente' => $preuve !== null && $preuve !== '',
                'statut_instruction' => 'en_cours_sans_reponse',
                'inference_silence' => 'aucune',
                'delai_invente' => false,
                'echeance_generee' => null,
                'information_usager' => 'Cette relance a déjà été enregistrée. Le dossier demeure en instruction ; aucun délai n’est garanti et le silence ne vaut ni accord ni refus.',
                'date_relance' => now()->toIso8601String(),
            ];
        }

        $this->audit->tracer(
            $etudiant,
            'relance_demande_derogation',
            'derogation',
            null,
            null,
            [
                'demande_id' => $demande,
                'relance_id' => $relanceId,
                'canal' => $canal,
                'preuve_relance' => $preuve,
                'preuve_relance_presente' => $preuve !== null && $preuve !== '',
                'relance_at' => now()->toIso8601String(),
                'delai_invente' => false,
                'echeance_generee' => null,
            ]
        );

        return [
            'demande_id' => $demande,
            'relance_id' => $relanceId,
            'deja_enregistree' => false,
            'canal' => $canal,
            'canal_officiel_etabli' => true,
            'preuve_relance_presente' => $preuve !== null && $preuve !== '',
            'statut_instruction' => 'en_cours_sans_reponse',
            'inference_silence' => 'aucune', // Neutralité absolue du silence administratif
            'delai_invente' => false,
            'echeance_generee' => null,
            'information_usager' => 'Votre relance est enregistrée. Le dossier demeure en instruction : aucun délai n’est garanti et le silence de l’administration ne vaut ni accord ni refus.',
            'date_relance' => now()->toIso8601String(),
        ];
    }

    /**
     * Q141 / BUS-006 :
     * Informe factuellement la personne de l'attente prolongée, sans promettre
     * de délai et sans préjuger la décision.
     */
    public function informerAttenteProlongee(
        Etudiant $etudiant,
        string $demandeId,
        array $demarchesEntreprises = [],
        bool $reponseRecue = false,
        string $statutInstructionActuel = 'en_attente_etablissement_competence',
    ): array {
        $demande = trim($demandeId);
        if ($demande === '') {
            throw new DomainException('L’identifiant de la demande est obligatoire.');
        }

        $demarches = [];
        foreach ($demarchesEntreprises as $demarche) {
            if (is_string($demarche) && trim($demarche) !== '') {
                $demarches[] = trim($demarche);
            }
        }

        $etat = $demarches === [] ? 'aucune démarche enregistrée' : implode(', ', $demarches);
        $message = $reponseRecue
            ? 'Une réponse a été reçue à ce jour. Elle demeure en cours d’examen : aucune décision n’est préjugée et aucun délai ne peut être garanti.'
            : "La clarification de compétence est en attente. Démarches entreprises : {$etat}. Aucune réponse n’a été reçue à ce jour. Aucun délai ne peut être garanti et aucune décision n’est préjugée.";

        $templateVersion = 'q141-v1';
        $cleDedup = 'q141:'.$demande.':'.hash('sha256', json_encode([
            'demarches' => $demarches,
            'reponse_recue' => $reponseRecue,
            'statut' => $statutInstructionActuel,
        ], JSON_UNESCAPED_UNICODE));

        // Q141 : l'information est d'abord journalisée (preuve de l'acte),
        // puis transmise par le système central de notifications. Une panne
        // de notification ne doit jamais faire perdre l'information tracée.
        $this->audit->tracer(
            $etudiant,
            'information_attente_prolongee',
            'derogation',
            null,
            null,
            [
                'demande_id' => $demande,
                'demarches_entreprises' => $demarches,
                'reponse_recue' => $reponseRecue,
                'delai_promis' => null,
                'decision_prejugee' => false,
                'statut_instruction' => $statutInstructionActuel,
                'statut_modifie' => false,
                'template_version' => $templateVersion,
                'cle_dedup' => $cleDedup,
            ]
        );

        $notificationId = null;
        $preuveEnvoi = 'enregistree';
        try {
            $notification = $this->notifications->notifierEtudiant(
                $etudiant,
                'derogation_attente_prolongee',
                'Suivi de votre demande de dérogation',
                $message,
                route('etudiant.derogations.index'),
                [
                    'demande_id' => $demande,
                    'statut_instruction' => $statutInstructionActuel,
                    'delai_promis' => null,
                    'decision_prejugee' => false,
                    'template_version' => $templateVersion,
                ],
                1,
                $cleDedup,
            );
            $notificationId = $notification->id;
        } catch (Throwable) {
            // Fail-soft : le message reste disponible et l'acte est déjà tracé.
            $preuveEnvoi = 'echec';
        }

        return [
            'demande_id' => $demande,
            'reponse_recue' => $reponseRecue,
            'demarches_entreprises' => $demarches,
            'information_usager' => $message,
            'delai_promis' => null,
            'decision_prejugee' => false,
            'statut_instruction' => $statutInstructionActuel,
            'statut_modifie' => false,
            'notification_id' => $notificationId,
            'preuve_envoi' => $preuveEnvoi,
            'template_version' => $templateVersion,
        ];
    }

    /**
     * Q142 / RECOURS-005, BUS-008 :
     * Enregistre une contestation d'attente prolongée, sans inventer de voie de
     * recours et sans modifier automatiquement le statut du dossier.
     */
    public function enregistrerContestationAttente(
        Etudiant $etudiant,
        string $demandeId,
        string $motifContestation,
        string $statutInstructionActuel = 'en_attente_etablissement_competence',
    ): array {
        $demande = trim($demandeId);
        if ($demande === '') {
            throw new DomainException('L’identifiant de la demande est obligatoire.');
        }

        $motifNettoye = trim($motifContestation);
        if ($motifNettoye === '') {
            throw new DomainException('Le motif de la contestation d’attente prolongée est obligatoire.');
        }

        $contestationId = 'CATP-'.hash('sha256', $etudiant->id.'|'.$demande.'|'.$motifNettoye.'|'.now()->format('Y-m-d'));

        if ($this->evenementExiste($etudiant, 'contestation_attente_prolongee', 'contestation_id', $contestationId)) {
            return [
                'demande_id' => $demande,
                'contestation_id' => $contestationId,
                'contestation_enregistree' => true,
                'deja_enregistree' => true,
                'motif_contestation' => $motifNettoye,
                'statut_instruction_avant' => $statutInstructionActuel,
                'statut_instruction_apres' => $statutInstructionActuel,
                'statut_modifie' => false,
                'information_factuelle' => 'Cette contestation a déjà été enregistrée. Le dossier demeure dans son état antérieur, sans modification automatique.',
                'voies_recours_officielles' => $this->voiesRecours(),
                'mutation_dossier_initial' => false,
                'procedure_inventee' => false,
            ];
        }

        $this->audit->tracer(
            $etudiant,
            'contestation_attente_prolongee',
            'derogation',
            null,
            null,
            [
                'demande_id' => $demande,
                'contestation_id' => $contestationId,
                'motif' => $motifNettoye,
                'statut_instruction_avant' => $statutInstructionActuel,
                'statut_instruction_apres' => $statutInstructionActuel,
                'statut_modifie' => false,
                'voies_recours_officielles' => $this->voiesRecours(),
                'procedure_inventee' => false,
                'date_contestation' => now()->toIso8601String(),
            ]
        );

        $voies = $this->voiesRecours();

        return [
            'demande_id' => $demande,
            'contestation_id' => $contestationId,
            'contestation_enregistree' => true,
            'deja_enregistree' => false,
            'motif_contestation' => $motifNettoye,
            'statut_instruction_avant' => $statutInstructionActuel,
            'statut_instruction_apres' => $statutInstructionActuel,
            'statut_modifie' => false, // Q142 : aucune mutation automatique
            'information_factuelle' => $voies === []
                ? 'Votre contestation est enregistrée. Aucune voie de recours officielle n’est établie à ce jour ; le dossier demeure dans son état antérieur et l’absence de voie est documentée.'
                : 'Votre contestation est enregistrée. Les voies de recours officielles établies vous sont communiquées ; le dossier demeure dans son état antérieur jusqu’à une décision autorisée.',
            'voies_recours_officielles' => $voies,
            'mutation_dossier_initial' => false,
            'procedure_inventee' => false,
        ];
    }

    /**
     * Q139, Q140 / AUTH-007, AUTH-008 :
     * Statut officiel sur une dérogation rendu par une autorité dont la
     * compétence est officiellement établie. Fail-closed par défaut.
     *
     * @throws DomainException si le mécanisme ou l'autorité ne sont pas établis
     */
    public function statuerDerogation(
        Etudiant $etudiant,
        string $demandeId,
        string $decision,
        string $autoriteQualite,
        string $referenceActeOfficiel,
        ?int $agentSignataireId = null,
    ): array {
        $demande = trim($demandeId);
        if ($demande === '') {
            throw new DomainException('L’identifiant de la demande est obligatoire.');
        }

        $decisionNettoyee = trim($decision);
        if (! in_array($decisionNettoyee, ['accordee', 'refusee'], true)) {
            throw new DomainException("Décision de dérogation invalide : '{$decision}'.");
        }

        if (! $this->mecanismeIdentifie()) {
            throw new DomainException('Aucun mécanisme officiel de dérogation n’est établi : aucune décision ne peut être rendue.');
        }

        $autorite = trim($autoriteQualite);
        $autorites = $this->autoritesCompetentes();
        if ($autorites === [] || ! in_array($autorite, $autorites, true)) {
            throw new DomainException("Compétence non établie pour l’autorité « {$autorite} » : aucune décision ne peut être rendue.");
        }

        $reference = trim($referenceActeOfficiel);
        if ($reference === '') {
            throw new DomainException('La référence de l’acte administratif ou de la délibération officielle est obligatoire.');
        }

        $this->audit->tracer(
            $etudiant,
            'decision_derogation',
            'derogation',
            null,
            ['statut' => 'en_instruction'],
            [
                'demande_id' => $demande,
                'decision' => $decisionNettoyee,
                'autorite' => $autorite,
                'competence_etablie' => true,
                'reference_acte' => $reference,
                'signataire_id' => $agentSignataireId,
                'date_decision' => now()->toIso8601String(),
            ]
        );

        return [
            'demande_id' => $demande,
            'decision' => $decisionNettoyee,
            'autorite' => $autorite,
            'competence_etablie' => true,
            'reference_acte' => $reference,
            'statut' => $decisionNettoyee === 'accordee' ? 'derogation_accordee' : 'derogation_rejetee',
            'mutation_dossier_associe' => false,
            'consequences_appliquees' => false,
            'date_decision' => now()->toIso8601String(),
        ];
    }

    /**
     * Q143 / RECOURS-004 :
     * Transmet une demande par une voie de réexamen officiellement établie,
     * avec référence de transmission, sans fixer de délai ni déduire une
     * décision du silence.
     */
    public function transmettrePourReexamen(
        Etudiant $etudiant,
        string $demandeId,
        string $voieTransmission,
        ?string $referenceTransmission = null,
        ?string $sourceOfficielle = null,
        ?string $referenceOfficielleVoie = null,
        ?string $destinataireOfficiel = null,
    ): array {
        $demande = trim($demandeId);
        if ($demande === '') {
            throw new DomainException('L’identifiant de la demande est obligatoire.');
        }

        $voie = trim($voieTransmission);
        if (! in_array($voie, $this->voiesReexamen(), true)) {
            throw new DomainException("Voie de réexamen non établie pour « {$voie} » : la transmission ne peut pas être enregistrée.");
        }

        $reference = $referenceTransmission !== null ? trim($referenceTransmission) : '';
        if ($reference === '' && $referenceOfficielleVoie !== null) {
            $reference = trim($referenceOfficielleVoie);
        }
        if ($reference === '') {
            throw new DomainException('La référence de la transmission officielle est obligatoire.');
        }

        $source = $sourceOfficielle !== null ? trim($sourceOfficielle) : null;
        $destinataire = $destinataireOfficiel !== null ? trim($destinataireOfficiel) : null;
        $transmissionId = 'TRX-'.hash('sha256', $etudiant->id.'|'.$demande.'|'.$reference);

        if ($this->evenementExiste($etudiant, 'transmission_reexamen', 'transmission_id', $transmissionId)) {
            return [
                'demande_id' => $demande,
                'transmission_id' => $transmissionId,
                'deja_enregistree' => true,
                'voie_transmission' => $voie,
                'reference_transmission' => $reference,
                'source_officielle' => $source,
                'destinataire_officiel' => $destinataire,
                'statut' => 'transmis_pour_reexamen',
                'delai_confirme' => null,
                'delai_fixe' => null,
                'delai_garanti' => null,
                'preuve_transmission_enregistree' => true,
                'decision_deduite_du_silence' => false,
                'information_usager' => 'La transmission a déjà été enregistrée. Aucun délai de traitement n’est confirmé à ce jour.',
            ];
        }

        $this->audit->tracer(
            $etudiant,
            'transmission_reexamen',
            'reexamen',
            null,
            null,
            [
                'demande_id' => $demande,
                'transmission_id' => $transmissionId,
                'voie' => $voie,
                'reference_transmission' => $reference,
                'source_officielle' => $source,
                'destinataire_officiel' => $destinataire,
                'delai_fixe' => null, // Règle RECOURS-004 : aucun délai inventé
                'decision_deduite_du_silence' => false,
                'transmission_at' => now()->toIso8601String(),
            ]
        );

        return [
            'demande_id' => $demande,
            'transmission_id' => $transmissionId,
            'deja_enregistree' => false,
            'voie_transmission' => $voie,
            'reference_transmission' => $reference,
            'source_officielle' => $source,
            'destinataire_officiel' => $destinataire,
            'statut' => 'transmis_pour_reexamen',
            'delai_confirme' => null,
            'delai_fixe' => null,
            'delai_garanti' => null,
            'preuve_transmission_enregistree' => true,
            'decision_deduite_du_silence' => false,
            'information_usager' => 'Le dossier a été transmis pour réexamen par la voie officielle. Aucun délai de traitement n’est confirmé à ce jour : le suivi s’effectue sans échéance inventée et le silence ne vaut pas décision.',
        ];
    }

    /**
     * Q144 / HIST-008 :
     * Enregistre une décision de réexamen insuffisamment motivée en conservant
     * le texte et les motifs verbatim, sans interpréter ni compléter.
     */
    public function enregistrerDecisionInsuffisammentMotivee(
        Etudiant $etudiant,
        string $demandeId,
        string $decisionTexte,
        ?string $motifsFournis = null,
        ?string $referenceDecision = null,
        bool $signalementAutorise = true,
    ): array {
        $demande = trim($demandeId);
        if ($demande === '') {
            throw new DomainException('L’identifiant de la demande est obligatoire.');
        }

        if (trim($decisionTexte) === '') {
            throw new DomainException('Le texte de la décision est obligatoire.');
        }

        $reference = $referenceDecision !== null ? trim($referenceDecision) : null;

        $this->audit->tracer(
            $etudiant,
            'decision_insuffisamment_motivee',
            'reexamen',
            null,
            null,
            [
                'demande_id' => $demande,
                'reference_decision' => $reference,
                'decision_texte' => $decisionTexte, // verbatim, sans troncature ni réécriture
                'motifs_fournis' => $motifsFournis, // verbatim, y compris null
                'motivation_insuffisante' => true,
                'motifs_completes' => false,
                'interpretation_supplee' => false,
                'signalement_autorise' => $signalementAutorise,
                'date_enregistrement' => now()->toIso8601String(),
            ]
        );

        return [
            'demande_id' => $demande,
            'reference_decision' => $reference,
            'statut' => 'decision_enregistree_motivation_insuffisante',
            'decision_verbatim' => $decisionTexte,
            'motifs_fournis' => $motifsFournis,
            'motifs_preserves' => true,
            'motivation_insuffisante' => true,
            'motifs_completes' => false,
            'interpretation_supplee' => false, // Interdiction absolue de suppléer des motifs
            'signalement_autorise' => $signalementAutorise,
            'date_enregistrement' => now()->toIso8601String(),
        ];
    }

    /**
     * Q145 / STAT-007 :
     * Enregistre une décision ambiguë dans son intégralité, sans présumer
     * l'acceptation ni le refus.
     */
    public function enregistrerDecisionAmbiguë(
        Etudiant $etudiant,
        string $demandeId,
        string $contenuDecision,
        ?string $referenceDecision = null,
        ?string $referenceActe = null,
    ): array {
        $demande = trim($demandeId);
        if ($demande === '') {
            throw new DomainException('L’identifiant de la demande est obligatoire.');
        }

        if (trim($contenuDecision) === '') {
            throw new DomainException('Le contenu de la décision est obligatoire.');
        }

        $reference = $referenceDecision ?? $referenceActe;
        $reference = $reference !== null ? trim($reference) : null;

        $this->audit->tracer(
            $etudiant,
            'decision_reexamen_ambigue',
            'reexamen',
            null,
            null,
            [
                'demande_id' => $demande,
                'reference_decision' => $reference,
                'dispositif_ambigu' => true,
                'contenu_decision' => $contenuDecision, // original conservé, pas seulement l'empreinte
                'contenu_hash' => hash('sha256', $contenuDecision),
                'acceptation_presumee' => false,
                'refus_presume' => false,
                'constat_at' => now()->toIso8601String(),
            ]
        );

        return [
            'demande_id' => $demande,
            'reference_decision' => $reference,
            'statut' => 'decision_recue_ambigue',
            'issue_presumee' => null, // Interdiction de préjuger d'un accord ou rejet
            'acceptation_presumee' => false,
            'refus_presume' => false,
            'derogation_accordee' => false,
            'derogation_refusee' => false,
            'contenu_original_preserve' => true,
            'decision_initiale_conservee' => true,
            'action_requise' => 'demande_clarification_officielle',
        ];
    }

    /**
     * Q146 / STAT-008, BUS-004 (NON CONFIRMÉE) :
     * Enregistre une clarification officielle sans choisir l'une des options
     * A/B/C, sans fixer de statut final et sans muter les dossiers associés.
     */
    public function appliquerClarificationDecision(
        Etudiant $etudiant,
        string $demandeId,
        string $issueClarifiee,
        string $referenceClarification,
        ?string $auteurClarification = null,
        Carbon|string|null $dateClarification = null,
    ): array {
        $demande = trim($demandeId);
        if ($demande === '') {
            throw new DomainException('L’identifiant de la demande est obligatoire.');
        }

        $issue = trim($issueClarifiee);
        if (! in_array($issue, ['accord', 'refus'], true)) {
            throw new DomainException("Issue clarifiée non reconnue : '{$issueClarifiee}'.");
        }

        $reference = trim($referenceClarification);
        if ($reference === '') {
            throw new DomainException('La référence de la clarification officielle est obligatoire.');
        }

        $auteur = $auteurClarification !== null ? trim($auteurClarification) : null;

        try {
            $date = $dateClarification === null
                ? now()
                : ($dateClarification instanceof Carbon ? $dateClarification : Carbon::parse($dateClarification));
        } catch (Throwable $exception) {
            throw new DomainException('La date de la clarification officielle est invalide.', 0, $exception);
        }

        $autoriteEtablie = $auteur !== null
            && $auteur !== ''
            && in_array($auteur, $this->autoritesCompetentes(), true);

        $this->audit->tracer(
            $etudiant,
            'clarification_decision_enregistree',
            'reexamen',
            null,
            ['statut' => 'decision_recue_ambigue'],
            [
                'demande_id' => $demande,
                'issue_declaree' => $issue,
                'reference' => $reference,
                'auteur' => $auteur,
                'autorite_etablie' => $autoriteEtablie,
                'date_clarification' => $date->toIso8601String(),
                'statut_apres' => 'clarification_enregistree',
                'decision_finale_appliquee' => false,
                'option_q146_confirmee' => false,
                'decision_initiale_ambigue_preservee' => true,
                'mutations_automatiques_effectuees' => false,
            ]
        );

        return [
            'demande_id' => $demande,
            'statut' => 'clarification_enregistree',
            'statut_final' => null,
            'decision_finale_appliquee' => false,
            'option_q146_confirmee' => false,
            'issue_declaree' => $issue,
            'reference' => $reference,
            'auteur' => $auteur,
            'autorite_etablie' => $autoriteEtablie,
            'date_clarification' => $date->toIso8601String(),
            'decision_initiale_ambigue_preservee' => true,
            'mutations_automatiques_effectuees' => false, // BUS-004 : zéro cascade automatique
            'motif_non_application' => 'Q146 NON CONFIRMÉE : aucune des options A/B/C ne peut être appliquée sans décision explicite. La clarification est conservée, le statut final reste ouvert.',
            'avertissement' => "Conformément à la règle BUS-004, aucun dossier associé n'a été muté automatiquement.",
        ];
    }

    private function mecanismeIdentifie(): bool
    {
        return (bool) config('loop146.derogations.mecanisme_identifie', false);
    }

    /** @return array<int, string> */
    private function autoritesCompetentes(): array
    {
        return $this->valeursConfig('loop146.derogations.autorites_competentes');
    }

    /** @return array<int, string> */
    private function canauxRelance(): array
    {
        return $this->valeursConfig('loop146.derogations.canaux_relance');
    }

    /** @return array<int, string> */
    private function voiesReexamen(): array
    {
        return $this->valeursConfig('loop146.derogations.voies_reexamen');
    }

    /** @return array<int, string> */
    private function voiesRecours(): array
    {
        return $this->valeursConfig('loop146.derogations.voies_recours');
    }

    /**
     * @return array<int, string>
     */
    private function valeursConfig(string $cle): array
    {
        $valeurs = config($cle, []);
        if (! is_array($valeurs)) {
            return [];
        }

        $resultat = [];
        foreach ($valeurs as $valeur) {
            if (is_string($valeur) && trim($valeur) !== '') {
                $resultat[] = trim($valeur);
            }
        }

        return array_values(array_unique($resultat));
    }

    /**
     * Idempotence fondée sur le journal d'audit canonique (aucun second journal).
     */
    private function evenementExiste(Etudiant $etudiant, string $action, string $cle, string $valeur): bool
    {
        $entrees = HistoriqueAcademique::query()
            ->where('etudiant_id', $etudiant->id)
            ->where('action', $action)
            ->latest('id')
            ->limit(100)
            ->get();

        foreach ($entrees as $entree) {
            $donnees = json_decode((string) $entree->nouvelle_valeur, true);
            if (is_array($donnees) && ($donnees[$cle] ?? null) === $valeur) {
                return true;
            }
        }

        return false;
    }

    /**
     * Q141, Q142, Phase 4 : Consultation des demandes et échéances d'un étudiant (lecture seule).
     * Distingue « en_attente », « refuse », « ambigu » et « conteste » conformément aux règles consolidées.
     *
     * @return array{
     *     depassements: array<int, array<string, mixed>>,
     *     demandes: array<int, array<string, mixed>>,
     *     voies_recours: array<int, string>
     * }
     */
    public function consulterDossierDerogations(Etudiant $etudiant): array
    {
        $actions = [
            'constat_depassement_echeance',
            'depot_demande_derogation',
            'decision_derogation',
            'relance_demande_derogation',
            'information_attente_prolongee',
            'contestation_attente_prolongee',
            'transmission_reexamen',
            'decision_insuffisamment_motivee',
            'decision_reexamen_ambigue',
            'clarification_decision_enregistree',
        ];

        // Ordre chronologique : l'état affiché doit refléter l'événement le plus
        // récent, sans qu'une écriture ancienne écrase une décision postérieure.
        $entrees = HistoriqueAcademique::query()
            ->where('etudiant_id', $etudiant->id)
            ->whereIn('action', $actions)
            ->oldest('id')
            ->get();

        $depassements = [];
        $demandes = [];

        foreach ($entrees as $entree) {
            $donnees = json_decode((string) $entree->nouvelle_valeur, true) ?? [];
            if ($entree->action === 'constat_depassement_echeance') {
                $anciennes = json_decode((string) $entree->ancienne_valeur, true) ?? [];
                $depassements[] = [
                    'id' => $entree->id,
                    'code_echeance' => $donnees['type'] ?? ($donnees['code_echeance'] ?? 'echeance_generale'),
                    'date_echeance' => $anciennes['echeance_initiale'] ?? ($donnees['date_echeance_initiale'] ?? null),
                    'date_constat' => $donnees['constat_at'] ?? $entree->created_at?->toIso8601String(),
                    'risque_signalise' => $donnees['verification_requise'] ?? true,
                    'regle_certifiee' => $donnees['regle_officielle_identifiee'] ?? false,
                ];
            } else {
                $demandeId = $donnees['demande_id'] ?? null;
                if ($demandeId) {
                    if (! isset($demandes[$demandeId])) {
                        $demandes[$demandeId] = [
                            'demande_id' => $demandeId,
                            'statut' => 'en_attente',
                            'statut_affichage' => 'En attente',
                            'statut_final' => null,
                            'decision_finale_appliquee' => false,
                            'motif' => $donnees['motif'] ?? ($donnees['motif_initial'] ?? null),
                            'date_depot' => $donnees['date_depot'] ?? $entree->created_at?->toIso8601String(),
                            'evenements' => [],
                            'conteste' => false,
                            'ambigu' => false,
                            'clarification_recue' => false,
                            'refuse' => false,
                            'accepte' => false,
                        ];
                    }

                    $demandes[$demandeId]['evenements'][] = [
                        'action' => $entree->action,
                        'date' => $entree->created_at?->toIso8601String(),
                        'details' => $donnees,
                    ];

                    if ($entree->action === 'contestation_attente_prolongee') {
                        $demandes[$demandeId]['conteste'] = true;
                        // Q142 : la contestation ne modifie pas le statut du dossier.
                        if ($demandes[$demandeId]['statut'] === 'en_attente') {
                            $demandes[$demandeId]['statut_affichage'] = 'En attente — contestation enregistrée';
                        }
                    } elseif ($entree->action === 'decision_reexamen_ambigue') {
                        $demandes[$demandeId]['ambigu'] = true;
                        $demandes[$demandeId]['statut'] = 'ambigu';
                        $demandes[$demandeId]['statut_affichage'] = 'Décision ambiguë reçue — statut final non appliqué';
                        $demandes[$demandeId]['statut_final'] = null;
                        $demandes[$demandeId]['decision_finale_appliquee'] = false;
                    } elseif ($entree->action === 'clarification_decision_enregistree') {
                        // Q146 : la clarification est conservée, mais aucune option
                        // A/B/C n'étant confirmée, aucun statut final n'est appliqué.
                        $demandes[$demandeId]['clarification_recue'] = true;
                        $demandes[$demandeId]['statut'] = 'clarification_enregistree';
                        $demandes[$demandeId]['statut_affichage'] = 'Clarification reçue — statut final non appliqué';
                        $demandes[$demandeId]['statut_final'] = null;
                        $demandes[$demandeId]['decision_finale_appliquee'] = false;
                    } elseif ($entree->action === 'decision_derogation') {
                        $statut = $donnees['decision'] ?? 'en_attente';
                        $demandes[$demandeId]['statut'] = $statut;
                        if ($statut === 'accordee') {
                            $demandes[$demandeId]['accepte'] = true;
                            $demandes[$demandeId]['statut_affichage'] = 'Dérogation accordée';
                        } elseif ($statut === 'refusee') {
                            $demandes[$demandeId]['refuse'] = true;
                            $demandes[$demandeId]['statut_affichage'] = 'Dérogation refusée';
                        }
                    }
                }
            }
        }

        return [
            'depassements' => $depassements,
            'demandes' => array_values($demandes),
            'voies_recours' => $this->voiesRecours(),
        ];
    }
}
