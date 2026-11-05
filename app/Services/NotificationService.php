<?php

namespace App\Services;

use App\Contracts\CanalNotificationExterneInterface;
use App\Jobs\AcheminerNotificationExterneJob;
use App\Models\AppNotification;
use App\Models\Etudiant;
use App\Models\IntegrationLog;
use App\Models\User;
use App\Modules\Schedule\Models\Teacher;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;

/**
 * Centre de notifications (§4-6) : création typée pour users,
 * étudiants et enseignants, compteurs non-lues, marquage. Les modules
 * appellent ce service (jamais de table manipulée en direct hors tests).
 */
class NotificationService
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  string|null  $cleDedup  Clé d'idempotence (LOOP 12) : si une
     *                                 notification de même type + même clé
     *                                 existe déjà pour cet étudiant, elle
     *                                 est RETOURNÉE sans réinsérer (un
     *                                 événement rejoué ne duplique jamais).
     *                                 Null = comportement historique.
     */
    public function notifierEtudiant(
        Etudiant $etudiant,
        string $type,
        string $titre,
        string $message,
        ?string $lien = null,
        array $data = [],
        int $priorite = 0,
        ?string $cleDedup = null,
    ): AppNotification {
        if ($cleDedup !== null) {
            /** @var AppNotification|null $existante */
            $existante = AppNotification::query()
                ->where('etudiant_id', $etudiant->id)
                ->where('type', $type)
                ->where('data->cle_dedup', $cleDedup)
                ->first();

            if ($existante !== null) {
                return $existante;
            }

            $data['cle_dedup'] = $cleDedup;
        }

        if (! isset($data['statut_envoi'])) {
            $data['statut_envoi'] = 'en_attente';
        }

        $notification = AppNotification::create([
            'etudiant_id' => $etudiant->id,
            'type' => $type,
            'titre' => mb_substr($titre, 0, 150),
            'message' => mb_substr($message, 0, 500),
            'lien' => $lien !== null ? mb_substr($lien, 0, 255) : null,
            'data' => $data,
            'priorite' => max(0, min(3, $priorite)),
        ]);

        $this->acheminerExterne($notification);

        return $notification;
    }

    /**
     * Acheminement asynchrone sécurisé vers le canal externe (LOOP 12).
     * Fail-soft garanti : ne lève aucune exception vers l'appelant.
     */
    public function acheminerExterne(AppNotification $notification): void
    {
        try {
            AcheminerNotificationExterneJob::dispatch($notification->id);
        } catch (\Throwable) {
            // Fail-soft : la notification en base reste intègre
        }
    }

    /**
     * Reprise d'un envoi externe en échec (LOOP 12).
     */
    public function retenterEnvoiExterne(AppNotification $notification): void
    {
        $data = $notification->data ?? [];
        $data['statut_envoi'] = 'en_attente';
        $data['erreur'] = null;
        $notification->update(['data' => $data]);

        $this->acheminerExterne($notification);
    }

    /**
     * LOT 02 — outbox durable et scan de reprise.
     * La colonne `data.statut_envoi` de app_notifications EST l'outbox :
     * elle est écrite en base AVANT le dispatch du job (pattern
     * transactional outbox), donc aucun envoi ne peut être perdu par une
     * panne de la file. Ce scan reprend :
     *   - `echec` / `echec_definitif` : canal déjà en échec (3 tries du job
     *     épuisées dans le second cas) ;
     *   - `en_attente` oublié (staleness) : job jamais consommé (file
     *     indisponible) ;
     * et ignore STRICTEMENT les `envoye` (idempotence, jamais de doublon
     * d'acheminement chez le destinataire).
     * Sémantique au-moins-une-fois : le canal doit tolérer une reprise.
     * Fraîchement mis à jour = on attend le prochain passage (l'échec vient
     * de se reproduire), ce qui borne le débit par staleness.
     *
     * @return int Nombre d'envois repris (replanifiés).
     */
    public function reprendreEchecsExterieurs(?int $limite = null, ?int $minutesStale = null): int
    {
        $limite ??= (int) config('communication.reprise.limite_par_scan', 200);
        $minutesStale ??= (int) config('communication.reprise.staleness_minutes', 60);
        $ancienneteMaxHeures = (int) config('communication.reprise.anciennete_max_heures', 48);

        /** @var Collection<int, AppNotification> $cibles */
        $cibles = AppNotification::query()
            ->whereIn('data->statut_envoi', [
                CanalNotificationExterneInterface::STATUT_ECHEC,
                CanalNotificationExterneInterface::STATUT_ECHEC_DEFINITIF,
                CanalNotificationExterneInterface::STATUT_EN_ATTENTE,
            ])
            ->where('updated_at', '<', now()->subMinutes($minutesStale))
            // Fenêtre bornée : au-delà de l'ancienneté max, l'échec devient
            // une affaire d'opérations manuelles — jamais de martèlement
            // automatique d'une passerelle morte.
            ->where('updated_at', '>=', now()->subHours($ancienneteMaxHeures))
            ->orderBy('updated_at')
            ->limit($limite)
            ->get();

        foreach ($cibles as $notification) {
            $this->retenterEnvoiExterne($notification);
        }

        return $cibles->count();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function notifierUser(
        User $user,
        string $type,
        string $titre,
        string $message,
        ?string $lien = null,
        array $data = [],
        int $priorite = 0,
    ): AppNotification {
        return AppNotification::create([
            'user_id' => $user->id,
            'type' => $type,
            'titre' => mb_substr($titre, 0, 150),
            'message' => mb_substr($message, 0, 500),
            'lien' => $lien !== null ? mb_substr($lien, 0, 255) : null,
            'data' => $data,
            'priorite' => max(0, min(3, $priorite)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function notifierEnseignant(
        Teacher $enseignant,
        string $type,
        string $titre,
        string $message,
        ?string $lien = null,
        array $data = [],
        int $priorite = 0,
    ): AppNotification {
        return AppNotification::create([
            'teacher_id' => $enseignant->id,
            'type' => $type,
            'titre' => mb_substr($titre, 0, 150),
            'message' => mb_substr($message, 0, 500),
            'lien' => $lien !== null ? mb_substr($lien, 0, 255) : null,
            'data' => $data,
            'priorite' => max(0, min(3, $priorite)),
        ]);
    }

    /**
     * Diffusion de masse (annonces, alertes) : insertion en lots,
     * jamais de boucle N+1 de create().
     *
     * @param  iterable<int>  $etudiantIds
     * @param  array<string, mixed>  $data
     */
    public function notifierPlusieursEtudiants(
        iterable $etudiantIds,
        string $type,
        string $titre,
        string $message,
        ?string $lien = null,
        array $data = [],
        int $priorite = 0,
    ): int {
        return $this->notifierPlusieurs('etudiant_id', $etudiantIds, $type, $titre, $message, $lien, $data, $priorite);
    }

    /**
     * @param  iterable<int>  $userIds
     * @param  array<string, mixed>  $data
     */
    public function notifierPlusieursUsers(
        iterable $userIds,
        string $type,
        string $titre,
        string $message,
        ?string $lien = null,
        array $data = [],
        int $priorite = 0,
    ): int {
        return $this->notifierPlusieurs('user_id', $userIds, $type, $titre, $message, $lien, $data, $priorite);
    }

    /**
     * @param  iterable<int>  $enseignantIds
     * @param  array<string, mixed>  $data
     */
    public function notifierPlusieursEnseignants(
        iterable $enseignantIds,
        string $type,
        string $titre,
        string $message,
        ?string $lien = null,
        array $data = [],
        int $priorite = 0,
    ): int {
        return $this->notifierPlusieurs('teacher_id', $enseignantIds, $type, $titre, $message, $lien, $data, $priorite);
    }

    /**
     * Purge des notifications lues anciennes (données personnelles).
     */
    public function purgerLues(int $jours = 180): int
    {
        return AppNotification::whereNotNull('read_at')
            ->where('read_at', '<', now()->subDays($jours))
            ->delete();
    }

    public function nonLuesEtudiant(int $etudiantId): int
    {
        return AppNotification::where('etudiant_id', $etudiantId)->whereNull('read_at')->count();
    }

    public function nonLuesUser(int $userId): int
    {
        return AppNotification::where('user_id', $userId)->whereNull('read_at')->count();
    }

    public function nonLuesEnseignant(int $enseignantId): int
    {
        return AppNotification::where('teacher_id', $enseignantId)->whereNull('read_at')->count();
    }

    public function toutMarquerLuEtudiant(int $etudiantId): int
    {
        return AppNotification::where('etudiant_id', $etudiantId)->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function toutMarquerLuUser(int $userId): int
    {
        return AppNotification::where('user_id', $userId)->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function toutMarquerLuEnseignant(int $enseignantId): int
    {
        return AppNotification::where('teacher_id', $enseignantId)->whereNull('read_at')->update(['read_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function notifierPlusieurs(
        string $colonne,
        iterable $ids,
        string $type,
        string $titre,
        string $message,
        ?string $lien,
        array $data,
        int $priorite,
    ): int {
        $titre = mb_substr($titre, 0, 150);
        $message = mb_substr($message, 0, 500);
        $lien = $lien !== null ? mb_substr($lien, 0, 255) : null;
        $priorite = max(0, min(3, $priorite));
        $maintenant = now();

        $lot = [];
        $total = 0;

        foreach ($ids as $id) {
            $lot[] = [
                $colonne => (int) $id,
                'type' => $type,
                'titre' => $titre,
                'message' => $message,
                'lien' => $lien,
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'priorite' => $priorite,
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ];

            if (count($lot) >= 500) {
                AppNotification::insert($lot);
                $total += count($lot);
                $lot = [];
            }
        }

        if ($lot !== []) {
            AppNotification::insert($lot);
            $total += count($lot);
        }

        return $total;
    }

    /**
     * Phase G / LOOP 146 (Q114, NOTIF-002, STAT-001) :
     * Indique si la remise effective de la notification est formellement confirmée.
     * Règle absolue : l'acheminement externe (envoyé) ne vaut JAMAIS remise confirmée.
     */
    public function estRemiseConfirmee(AppNotification $notification): bool
    {
        return (bool) ($notification->data['remise_confirmee'] ?? false);
    }

    /**
     * Phase G / LOOP 146 (Q114, Q120, STAT-001, HIST-002) :
     * Enregistre la confirmation formelle de remise de la notification sur preuve vérifiable.
     */
    public function confirmerRemise(
        AppNotification $notification,
        string $modePreuve,
        ?string $referencePreuve = null,
        ?int $agentId = null,
        array $metadonnees = [],
    ): AppNotification {
        $data = $notification->data ?? [];
        $data['remise_confirmee'] = true;
        $data['statut_remise'] = 'remise_confirmee';
        $data['remise_at'] = now()->toIso8601String();
        $data['mode_preuve'] = $modePreuve;
        $data['reference_preuve'] = $referencePreuve;
        $data['agent_validation_id'] = $agentId;
        $data['remise_metadonnees'] = $metadonnees;

        $historique = $data['historique_remise'] ?? [];
        $historique[] = [
            'action' => 'remise_confirmee',
            'timestamp' => now()->toIso8601String(),
            'agent_id' => $agentId,
            'mode_preuve' => $modePreuve,
            'reference' => $referencePreuve,
        ];
        $data['historique_remise'] = $historique;

        $notification->update(['data' => $data]);

        IntegrationLog::tracer(
            integration: 'notifications',
            direction: 'internal',
            operation: 'remise.confirmee',
            externalReference: 'NOTIF-'.$notification->id,
            contenu: json_encode(['notif_id' => $notification->id, 'mode' => $modePreuve])
        );

        return $notification;
    }

    /**
     * Phase G / LOOP 146 (Q126, Q127, BUS-002, STAT-005) :
     * Enregistre le refus de validation d'une preuve de remise avec motif obligatoire.
     * Règle absolue : le refus ne présume pas que la remise n'a jamais eu lieu ;
     * le statut reste remise_non_confirmee et toutes les pièces sont préservées.
     *
     * @throws DomainException si le motif de refus est absent
     */
    public function refuserPreuveRemise(
        AppNotification $notification,
        string $motifRefus,
        ?int $agentId = null,
    ): AppNotification {
        $motifNettoye = trim($motifRefus);
        if ($motifNettoye === '') {
            throw new DomainException('Le motif du refus de validation de la preuve est obligatoire.');
        }

        $data = $notification->data ?? [];
        $data['remise_confirmee'] = false;
        $data['statut_remise'] = 'refusee';
        $data['remise_non_confirmee'] = true;
        $data['motif_refus'] = $motifNettoye;
        $data['refus_at'] = now()->toIso8601String();
        $data['refus_agent_id'] = $agentId;

        $historique = $data['historique_remise'] ?? [];
        $historique[] = [
            'action' => 'refus_preuve',
            'timestamp' => now()->toIso8601String(),
            'agent_id' => $agentId,
            'motif' => $motifNettoye,
        ];
        $data['historique_remise'] = $historique;

        $notification->update(['data' => $data]);

        IntegrationLog::tracer(
            integration: 'notifications',
            direction: 'internal',
            operation: 'remise.preuve_refusee',
            externalReference: 'NOTIF-'.$notification->id,
            contenu: json_encode(['notif_id' => $notification->id, 'motif' => $motifNettoye])
        );

        return $notification;
    }

    /**
     * Phase G / LOOP 146 (Q121, Q122, Q123, Q124, HIST-003, HIST-004, STAT-004) :
     * Enregistre une preuve tardive ou contradictoire sans écraser l'historique antérieur.
     */
    public function enregistrerPreuveTardive(
        AppNotification $notification,
        string $sourcePreuve,
        string $referencePreuve,
        array $elementsVerifies,
        bool $preuveConcluante,
        ?int $agentId = null,
    ): AppNotification {
        $data = $notification->data ?? [];

        $preuvesTardives = $data['preuves_tardives'] ?? [];
        $preuvesTardives[] = [
            'date_reception' => now()->toIso8601String(),
            'source' => $sourcePreuve,
            'reference' => $referencePreuve,
            'elements' => $elementsVerifies,
            'concluante' => $preuveConcluante,
            'agent_id' => $agentId,
        ];
        $data['preuves_tardives'] = $preuvesTardives;

        if ($preuveConcluante) {
            $data['remise_confirmee'] = true;
            $data['statut_remise'] = 'remise_confirmee_tardive';
            $data['remise_at'] = now()->toIso8601String();
        } else {
            $data['remise_confirmee'] = false;
            $data['statut_remise'] = 'remise_non_confirmee';
        }

        $historique = $data['historique_remise'] ?? [];
        $historique[] = [
            'action' => 'enregistrement_preuve_tardive',
            'timestamp' => now()->toIso8601String(),
            'agent_id' => $agentId,
            'concluante' => $preuveConcluante,
            'reference' => $referencePreuve,
        ];
        $data['historique_remise'] = $historique;

        $notification->update(['data' => $data]);

        return $notification;
    }

    /**
     * Q132 / RECOURS-001, RECOURS-002 :
     * Enregistre une contestation du refus de validation d'une preuve de remise.
     * Règle absolue : la preuve, la décision initiale et le statut de remise sont
     * préservés ; la contestation n'a aucun effet métier automatique et oriente
     * vers la voie officielle de recours.
     *
     * @return array{
     *     notification: AppNotification,
     *     contestation_id: string,
     *     deja_enregistree: bool,
     *     statut_remise_preserve: ?string,
     *     statut_modifie: bool,
     *     identite_contestataire_verifiee: bool,
     *     orientation: string,
     *     information_usager: string
     * }
     */
    public function enregistrerContestationRemise(
        AppNotification $notification,
        string $motifContestation,
        ?string $pieceHash = null,
        ?string $referenceContestation = null,
        ?Etudiant $contestataire = null,
    ): array {
        $motifNettoye = trim($motifContestation);
        if ($motifNettoye === '') {
            throw new DomainException('Le motif de la contestation est obligatoire.');
        }

        if ($contestataire !== null && (int) $notification->etudiant_id !== (int) $contestataire->id) {
            throw new AuthorizationException('La notification ne relève pas du dossier du contestataire.');
        }

        $reference = $referenceContestation !== null ? trim($referenceContestation) : null;
        $contestationId = 'CONT-'.hash('sha256', $notification->id.'|'.$motifNettoye.'|'.($pieceHash ?? '').'|'.($reference ?? ''));

        $data = $notification->data ?? [];
        $contestations = is_array($data['contestations'] ?? null) ? $data['contestations'] : [];

        foreach ($contestations as $existante) {
            if (($existante['contestation_id'] ?? null) === $contestationId) {
                return [
                    'notification' => $notification,
                    'contestation_id' => $contestationId,
                    'deja_enregistree' => true,
                    'statut_remise_preserve' => $data['statut_remise'] ?? null,
                    'statut_modifie' => false,
                    'identite_contestataire_verifiee' => $contestataire !== null,
                    'orientation' => 'voie_officielle_de_recours',
                    'information_usager' => 'Cette contestation est déjà enregistrée. Le statut de remise reste inchangé.',
                ];
            }
        }

        $statutAvant = $data['statut_remise'] ?? null;
        if (! array_key_exists('statut_remise_avant_contestation', $data)) {
            $data['statut_remise_avant_contestation'] = $statutAvant;
        }

        $entree = [
            'contestation_id' => $contestationId,
            'action' => 'contestation_enregistree',
            'timestamp' => now()->toIso8601String(),
            'motif' => $motifNettoye,
            'piece_hash' => $pieceHash,
            'reference' => $reference,
            'statut_remise_avant' => $statutAvant,
            'statut_remise_apres' => $statutAvant,
            'statut_modifie' => false,
            'identite_contestataire_verifiee' => $contestataire !== null,
        ];

        $contestations[] = $entree;
        $data['contestations'] = $contestations;
        $data['en_contestation'] = true;
        $data['contestation_ouverte'] = true;
        $data['orientation_recours'] = 'voie_officielle_de_recours';
        $data['motif_contestation'] = $motifNettoye;
        $data['piece_contestation_hash'] = $pieceHash;
        $data['contestation_at'] = $entree['timestamp'];
        // Q132 : `statut_remise` n'est JAMAIS modifié par une contestation.

        $historique = is_array($data['historique_remise'] ?? null) ? $data['historique_remise'] : [];
        $historique[] = $entree;
        $data['historique_remise'] = $historique;

        $notification->update(['data' => $data]);

        if ($notification->etudiant_id !== null) {
            $etudiant = $contestataire ?? Etudiant::find($notification->etudiant_id);
            if ($etudiant !== null) {
                $this->audit->tracer(
                    $etudiant,
                    'contestation_remise_enregistree',
                    'notification',
                    $notification->id,
                    null,
                    [
                        'contestation_id' => $contestationId,
                        'reference' => $reference,
                        'statut_remise_preserve' => $statutAvant,
                        'statut_modifie' => false,
                    ]
                );
            }
        }

        return [
            'notification' => $notification->refresh(),
            'contestation_id' => $contestationId,
            'deja_enregistree' => false,
            'statut_remise_preserve' => $statutAvant,
            'statut_modifie' => false,
            'identite_contestataire_verifiee' => $contestataire !== null,
            'orientation' => 'voie_officielle_de_recours',
            'information_usager' => 'Votre contestation est enregistrée. La décision initiale est conservée et aucun statut n’est modifié automatiquement. La contestation doit être portée par la voie officielle de recours compétente.',
        ];
    }

    /**
     * Q133, Q134 / RECOURS-003, STAT-006, BUS-004 :
     * Enregistre une décision officielle de recours dont la conclusion sur la
     * remise est explicite. Met à jour le statut de remise selon cette seule
     * conclusion, identifie les dossiers associés, sans aucune mutation
     * automatique de ces dossiers.
     *
     * @return array{
     *     notification: AppNotification,
     *     statut_remise: string,
     *     statut_remise_avant: ?string,
     *     decision_explicite: bool,
     *     deja_appliquee: bool,
     *     dossiers_associes_identifies: array<int, string>,
     *     consequences_possibles: array<string, string>,
     *     consequences_appliquees: bool,
     *     dossiers_modifies: array<int, string>,
     *     mutations_automatiques_effectuees: bool,
     *     avertissement: string
     * }
     */
    public function appliquerRecoursRemise(
        AppNotification $notification,
        string $decisionRecours,
        ?string $referenceOfficielle = null,
        ?int $autoriteId = null,
    ): array {
        $decision = trim($decisionRecours);
        $decisionsAutorisees = ['remise_confirmee', 'remise_non_confirmee'];

        if (! in_array($decision, $decisionsAutorisees, true)) {
            $message = $decision === 'confirme'
                ? 'La décision « confirme » est ambiguë : elle ne précise pas si la remise est confirmée ou refusée. Une conclusion explicite est obligatoire.'
                : "Décision de recours non autorisée : '{$decisionRecours}'. Conclusions explicites attendues : remise_confirmee ou remise_non_confirmee.";
            throw new DomainException($message);
        }

        $reference = $referenceOfficielle !== null ? trim($referenceOfficielle) : '';
        if ($reference === '') {
            throw new DomainException('La référence officielle de la décision de recours est obligatoire.');
        }

        $data = $notification->data ?? [];

        if (($data['recours_reference'] ?? null) === $reference) {
            if (($data['decision_recours'] ?? null) === $decision) {
                return $this->resultatRecours(
                    $notification,
                    $data,
                    $data['statut_remise_avant_recours'] ?? null,
                    dejaAppliquee: true
                );
            }

            throw new DomainException('Conflit de décisions : cette référence officielle a déjà été appliquée avec une conclusion différente.');
        }

        $statutAvant = $data['statut_remise'] ?? null;
        if (! array_key_exists('statut_remise_avant_recours', $data)) {
            $data['statut_remise_avant_recours'] = $statutAvant;
        }

        $data['en_contestation'] = false;
        $data['contestation_ouverte'] = false;
        $data['recours_applique'] = true;
        $data['decision_recours'] = $decision;
        $data['recours_reference'] = $reference;
        $data['reference_officielle_recours'] = $reference;
        $data['autorite_recours_id'] = $autoriteId;
        $data['recours_at'] = now()->toIso8601String();

        if ($decision === 'remise_confirmee') {
            $data['remise_confirmee'] = true;
            $data['statut_remise'] = 'remise_confirmee_apres_recours';
        } else {
            $data['remise_confirmee'] = false;
            $data['statut_remise'] = 'remise_non_confirmee_apres_recours';
        }

        $historique = is_array($data['historique_remise'] ?? null) ? $data['historique_remise'] : [];
        $historique[] = [
            'action' => 'decision_recours_appliquee',
            'timestamp' => now()->toIso8601String(),
            'decision' => $decision,
            'reference' => $reference,
            'autorite_id' => $autoriteId,
            'statut_remise_avant' => $statutAvant,
            'statut_remise_apres' => $data['statut_remise'],
            'consequences_appliquees' => false,
        ];
        $data['historique_remise'] = $historique;

        $notification->update(['data' => $data]);

        // Identification en lecture seule des dossiers corrélés (Q133 / Q134)
        $dossiersIdentifies = [];
        if ($notification->etudiant_id !== null) {
            $dossiersIdentifies[] = 'dossier_etudiant_#'.$notification->etudiant_id;
            $dossiersIdentifies[] = 'inscriptions_pedagogiques_actives';
            $dossiersIdentifies[] = 'convocations_examens_session';
        }

        if ($notification->etudiant_id !== null) {
            $etudiant = Etudiant::find($notification->etudiant_id);
            if ($etudiant !== null) {
                $this->audit->tracer(
                    $etudiant,
                    'recours_remise_applique',
                    'notification',
                    $notification->id,
                    ['statut_remise' => $statutAvant],
                    [
                        'decision' => $decision,
                        'reference' => $reference,
                        'statut_remise' => $data['statut_remise'],
                        'consequences_appliquees' => false,
                        'dossiers_modifies' => [],
                    ]
                );
            }
        }

        return $this->resultatRecours($notification, $data, $statutAvant, dejaAppliquee: false);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     notification: AppNotification,
     *     statut_remise: string,
     *     statut_remise_avant: ?string,
     *     decision_explicite: bool,
     *     deja_appliquee: bool,
     *     dossiers_associes_identifies: array<int, string>,
     *     consequences_possibles: array<string, string>,
     *     consequences_appliquees: bool,
     *     dossiers_modifies: array<int, string>,
     *     mutations_automatiques_effectuees: bool,
     *     avertissement: string
     * }
     */
    private function resultatRecours(
        AppNotification $notification,
        array $data,
        ?string $statutAvant,
        bool $dejaAppliquee,
    ): array {
        $dossiersIdentifies = [];
        if ($notification->etudiant_id !== null) {
            $dossiersIdentifies[] = 'dossier_etudiant_#'.$notification->etudiant_id;
            $dossiersIdentifies[] = 'inscriptions_pedagogiques_actives';
            $dossiersIdentifies[] = 'convocations_examens_session';
        }

        return [
            'notification' => $notification->refresh(),
            'statut_remise' => (string) ($data['statut_remise'] ?? ''),
            'statut_remise_avant' => $statutAvant,
            'decision_explicite' => true,
            'deja_appliquee' => $dejaAppliquee,
            'dossiers_associes_identifies' => $dossiersIdentifies,
            'consequences_possibles' => [
                'dossier_etudiant' => 'Mise à jour éventuelle du dossier après instruction administrative explicite.',
                'inscriptions_pedagogiques' => 'Réexamen éventuel des inscriptions actives, sans modification automatique.',
                'convocations_examens' => 'Réexamen éventuel des convocations, sans modification automatique.',
            ],
            'consequences_appliquees' => false, // Q134 : aucune conséquence sans fondement officiel suffisant
            'dossiers_modifies' => [],
            'mutations_automatiques_effectuees' => false,
            'avertissement' => "Conformément à la règle BUS-004, aucun dossier associé n'a été muté automatiquement. Une instruction administrative explicite est requise.",
        ];
    }

    /**
     * Phase G / LOOP 146 (Q117, Q118, Q119, AUTH-002, STAT-003, BUS-001) :
     * Clôture administrative avec distinction obligatoire de la remise.
     *
     * @throws DomainException si non autorisée par un texte officiel
     */
    public function distinguerClotureAdministrative(
        AppNotification $notification,
        bool $autoriseeParTexte,
        string $referenceReglementaire,
        ?int $agentId = null,
    ): AppNotification {
        if (! $autoriseeParTexte || trim($referenceReglementaire) === '') {
            throw new DomainException('La clôture administrative sans remise confirmée est interdite sans fondement réglementaire officiel explicite.');
        }

        $data = $notification->data ?? [];
        $data['cloture_administrative'] = true;
        $data['suivi_administratif'] = 'clos';
        $data['reference_reglementaire_cloture'] = trim($referenceReglementaire);
        $data['cloture_agent_id'] = $agentId;
        $data['cloture_at'] = now()->toIso8601String();

        // Si la remise n'était pas confirmée, elle le demeure formellement (Q118)
        if (! ($data['remise_confirmee'] ?? false)) {
            $data['remise_confirmee'] = false;
            $data['obligation_suivi_active'] = true; // Q119 : le suivi de remise reste soumis à obligation
        }

        $historique = $data['historique_remise'] ?? [];
        $historique[] = [
            'action' => 'cloture_administrative',
            'timestamp' => now()->toIso8601String(),
            'reference' => trim($referenceReglementaire),
            'agent_id' => $agentId,
        ];
        $data['historique_remise'] = $historique;

        $notification->update(['data' => $data]);

        return $notification;
    }
}
