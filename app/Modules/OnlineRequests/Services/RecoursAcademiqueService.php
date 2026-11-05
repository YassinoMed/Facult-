<?php

namespace App\Modules\OnlineRequests\Services;

use App\Models\Etudiant;
use App\Models\User;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\OnlineRequests\Models\RequestType;
use App\Services\Workflow\Definitions\WorkflowRecoursAcademiqueDefinition;
use App\Services\Workflow\MoteurWorkflowService;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use RuntimeException;

/**
 * LOOP 45 — RECOURS ACADÉMIQUES.
 *
 * Réutilise `online_requests` (pièces, messages, événements, audit) au lieu
 * de créer une table parallèle. Le circuit possède :
 * - sa propre définition de workflow (`recours` v1) ;
 * - des permissions distinctes (recevoir / instruire / décider / appliquer) ;
 * - quatre yeux sur toute décision (l'instructeur assigné ne décide pas) ;
 * - un dépôt qui ne rejette jamais automatiquement, même hors délai ;
 * - une décision qui ne modifie jamais automatiquement une note ou une
 *   décision de délibération : l'application est manuelle et tracée.
 */
class RecoursAcademiqueService
{
    public function __construct(
        private readonly MoteurWorkflowService $workflows,
        private readonly AuditDemandes $audit,
    ) {}

    public function estActif(): bool
    {
        return config('recours_academiques.actif') === true
            && trim((string) config('recours_academiques.reference_reglementaire')) !== '';
    }

    /**
     * Dépose un recours contre une décision publiée et le soumet.
     *
     * @param  array{motif: string}  $donnees
     */
    public function deposer(Etudiant $etudiant, DecisionDeliberation $decision, array $donnees, ?User $acteur = null): OnlineRequest
    {
        $this->verifierActivation();

        if ($acteur !== null) {
            $this->verifierPermission($acteur, 'recours.deposer');
        }

        $type = $this->typeRecours();
        $decision->loadMissing('deliberation');
        $this->verifierDecisionContestable($etudiant, $decision);
        $this->verifierAbsenceDoublon($etudiant, $decision, $type);

        $motif = trim((string) ($donnees['motif'] ?? ''));

        if ($motif === '') {
            throw new InvalidArgumentException('Le motif du recours est obligatoire.');
        }

        if (mb_strlen($motif) > 2000) {
            throw new InvalidArgumentException('Le motif du recours ne peut pas dépasser 2 000 caractères.');
        }

        $recevabilite = $this->evaluerRecevabilite($decision);

        $formData = [
            'recours' => [
                'decision_id' => (int) $decision->id,
                'deliberation_id' => (int) $decision->deliberation_id,
                'decision' => (string) $decision->decision,
                'motif' => $motif,
                'reference_reglementaire' => config('recours_academiques.reference_reglementaire'),
                'depose_par_etudiant_id' => (int) $etudiant->id,
                'depose_par_utilisateur_id' => $acteur?->id,
                'depose_le' => now()->toIso8601String(),
                'recevabilite' => 'A_VERIFIER',
                'delai' => $recevabilite,
                'application' => 'AUCUNE',
                'application_automatique' => false,
            ],
        ];

        return DB::transaction(function () use ($etudiant, $decision, $type, $motif, $formData) {
            $recours = $this->creerDemande($etudiant, $decision, $type, $motif, $formData);

            $this->transitionnerEtudiant($recours, $etudiant, 'submitted', [
                'submitted_at' => now(),
                'faculte_id' => $etudiant->faculte_id,
            ], [
                'circuit' => 'recours',
                'decision_id' => (int) $decision->id,
            ]);

            return $recours->fresh();
        });
    }

    /**
     * Recevabilité : acte humain explicite, avec référence. Aucun rejet
     * automatique n'est possible, même si le délai calculé est dépassé.
     */
    public function declarerRecevable(OnlineRequest $recours, User $agent, string $referenceDecision): OnlineRequest
    {
        $this->verifierActivation();
        $this->verifierPermission($agent, 'recours.recevoir');
        $this->verifierEstRecours($recours);

        if ($recours->status !== 'submitted') {
            throw new DomainException('Seul un recours soumis peut être déclaré recevable.');
        }

        $referenceDecision = trim($referenceDecision);

        if ($referenceDecision === '') {
            throw new InvalidArgumentException('La référence de la décision de recevabilité est obligatoire.');
        }

        return DB::transaction(function () use ($recours, $agent, $referenceDecision) {
            $verrouillee = OnlineRequest::whereKey($recours->id)->lockForUpdate()->firstOrFail();

            $donnees = $verrouillee->form_data ?? [];
            $donnees['recours']['recevabilite'] = 'RECEVABLE';
            $donnees['recours']['recevabilite_par'] = (int) $agent->id;
            $donnees['recours']['recevabilite_le'] = now()->toIso8601String();
            $donnees['recours']['reference_recevabilite'] = mb_substr($referenceDecision, 0, 255);

            $verrouillee->forceFill(['form_data' => $donnees])->save();

            $this->workflows->transiter(
                WorkflowRecoursAcademiqueDefinition::DOMAINE,
                $verrouillee,
                'received',
                $agent,
                'Recevabilité déclarée : '.$referenceDecision
            );

            $this->tracerActeur($verrouillee->fresh(), $agent, 'received', [
                'reference_recevabilite' => $referenceDecision,
            ]);

            return $verrouillee->fresh();
        });
    }

    /**
     * Instruction : un agent est nommément assigné. Il ne pourra pas décider.
     */
    public function instruire(OnlineRequest $recours, User $instructeur): OnlineRequest
    {
        $this->verifierActivation();
        $this->verifierPermission($instructeur, 'recours.instruire');
        $this->verifierEstRecours($recours);

        if (! in_array($recours->status, ['received', 'waiting_student'], true)) {
            throw new DomainException('Seul un recours reçu ou en attente de pièce peut être instruit.');
        }

        if ($recours->assigned_to !== null && (int) $recours->assigned_to !== (int) $instructeur->id) {
            throw new DomainException('Ce recours est déjà assigné à un autre instructeur.');
        }

        return DB::transaction(function () use ($recours, $instructeur) {
            $verrouillee = OnlineRequest::whereKey($recours->id)->lockForUpdate()->firstOrFail();

            $donnees = $verrouillee->form_data ?? [];
            $donnees['recours']['instruction'] = [
                'par' => (int) $instructeur->id,
                'le' => now()->toIso8601String(),
                'statut' => 'EN_COURS',
            ];

            $verrouillee->forceFill([
                'assigned_to' => $instructeur->id,
                'processed_at' => $verrouillee->processed_at ?? now(),
                'form_data' => $donnees,
            ])->save();

            $this->workflows->transiter(
                WorkflowRecoursAcademiqueDefinition::DOMAINE,
                $verrouillee,
                'in_review',
                $instructeur,
                'Instruction ouverte'
            );

            $this->tracerActeur($verrouillee->fresh(), $instructeur, 'in_review');

            return $verrouillee->fresh();
        });
    }

    public function demanderPiece(OnlineRequest $recours, User $instructeur, string $message): OnlineRequest
    {
        $this->verifierActivation();
        $this->verifierPermission($instructeur, 'recours.instruire');
        $this->verifierEstRecours($recours);

        if ($recours->status !== 'in_review') {
            throw new DomainException('Une demande de pièce n’est possible qu’en cours d’instruction.');
        }

        if ($recours->assigned_to === null || (int) $recours->assigned_to !== (int) $instructeur->id) {
            throw new DomainException('Seul l’instructeur assigné peut demander une pièce.');
        }

        $message = trim($message);

        if ($message === '') {
            throw new InvalidArgumentException('Le message de demande de pièce est obligatoire.');
        }

        return DB::transaction(function () use ($recours, $instructeur, $message) {
            $verrouillee = OnlineRequest::whereKey($recours->id)->lockForUpdate()->firstOrFail();

            $donnees = $verrouillee->form_data ?? [];
            $donnees['recours']['demandes_pieces'][] = [
                'message' => mb_substr($message, 0, 2000),
                'par' => (int) $instructeur->id,
                'le' => now()->toIso8601String(),
            ];
            $verrouillee->forceFill(['form_data' => $donnees])->save();

            $this->workflows->transiter(
                WorkflowRecoursAcademiqueDefinition::DOMAINE,
                $verrouillee,
                'waiting_student',
                $instructeur,
                $message
            );

            $this->tracerActeur($verrouillee->fresh(), $instructeur, 'waiting_student', [
                'demande_piece' => true,
            ]);

            return $verrouillee->fresh();
        });
    }

    public function repondreDemandePiece(OnlineRequest $recours, Etudiant $etudiant, string $message): OnlineRequest
    {
        $this->verifierActivation();
        $this->verifierEstRecours($recours);
        $this->verifierProprietaire($recours, $etudiant);

        if ($recours->status !== 'waiting_student') {
            throw new DomainException('Aucune pièce n’est attendue sur ce recours.');
        }

        $message = trim($message);

        if ($message === '') {
            throw new InvalidArgumentException('La réponse est obligatoire.');
        }

        return DB::transaction(function () use ($recours, $etudiant, $message) {
            $verrouillee = OnlineRequest::whereKey($recours->id)->lockForUpdate()->firstOrFail();

            $donnees = $verrouillee->form_data ?? [];
            $donnees['recours']['reponses_pieces'][] = [
                'message' => mb_substr($message, 0, 2000),
                'etudiant_id' => (int) $etudiant->id,
                'le' => now()->toIso8601String(),
            ];
            $verrouillee->forceFill(['form_data' => $donnees])->save();

            $this->transitionnerEtudiant($verrouillee, $etudiant, 'in_review', [], [
                'circuit' => 'recours',
                'reponse_piece' => true,
            ]);

            return $verrouillee->fresh();
        });
    }

    /**
     * Décision : quatre yeux. L'instructeur assigné ne peut jamais décider.
     * Aucune note ni décision de délibération n'est modifiée.
     *
     * @param  string  $sens  `approuve` ou `rejete`
     */
    public function decider(OnlineRequest $recours, User $decideur, string $sens, string $motif): OnlineRequest
    {
        $this->verifierActivation();
        $this->verifierPermission($decideur, 'recours.decider');
        $this->verifierEstRecours($recours);

        if ($recours->status !== 'in_review') {
            throw new DomainException('Seul un recours en cours d’instruction peut être décidé.');
        }

        $cible = match ($sens) {
            'approuve' => 'approved',
            'rejete' => 'rejected',
            default => throw new InvalidArgumentException('Sens de décision invalide : attendu « approuve » ou « rejete ».'),
        };

        $motif = trim($motif);

        if ($motif === '') {
            throw new InvalidArgumentException('Le motif de la décision est obligatoire.');
        }

        if ($recours->assigned_to === null) {
            throw new DomainException('Aucune instruction n’a été réalisée : la décision est impossible.');
        }

        if ((int) $recours->assigned_to === (int) $decideur->id) {
            throw new DomainException('Quatre yeux : l’instructeur assigné ne peut pas décider lui-même.');
        }

        if (config('recours_academiques.application_automatique_autorisee') === true) {
            throw new DomainException('L’application automatique d’une décision de recours est interdite.');
        }

        return DB::transaction(function () use ($recours, $decideur, $cible, $motif) {
            $verrouillee = OnlineRequest::whereKey($recours->id)->lockForUpdate()->firstOrFail();

            $donnees = $verrouillee->form_data ?? [];
            $donnees['recours']['decision'] = [
                'sens' => $cible === 'approved' ? 'APPROUVE' : 'REJETE',
                'par' => (int) $decideur->id,
                'le' => now()->toIso8601String(),
                'motif' => mb_substr($motif, 0, 2000),
                'application' => 'A_APPLIQUER_MANUELLEMENT',
                'application_effectuee' => false,
            ];
            $donnees['recours']['instruction']['statut'] = 'TERMINEE';

            $attributs = [
                'processed_at' => now(),
                'form_data' => $donnees,
            ];

            if ($cible === 'rejected') {
                $attributs['rejected_at'] = now();
                $attributs['rejection_reason'] = mb_substr($motif, 0, 500);
            }

            $verrouillee->forceFill($attributs)->save();

            $this->workflows->transiter(
                WorkflowRecoursAcademiqueDefinition::DOMAINE,
                $verrouillee,
                $cible,
                $decideur,
                $motif
            );

            $this->tracerActeur($verrouillee->fresh(), $decideur, $cible, [
                'sens' => $cible === 'approved' ? 'APPROUVE' : 'REJETE',
                'quatre_yeux' => true,
            ]);

            return $verrouillee->fresh();
        });
    }

    /**
     * Enregistre l'application MANUELLE de la décision approuvée. Ne touche
     * ni aux notes, ni aux moyennes, ni aux décisions de délibération.
     */
    public function enregistrerApplication(OnlineRequest $recours, User $agent, string $reference): OnlineRequest
    {
        $this->verifierActivation();
        $this->verifierPermission($agent, 'recours.appliquer');
        $this->verifierEstRecours($recours);

        $reference = trim($reference);

        if ($reference === '') {
            throw new InvalidArgumentException('La référence de l’application est obligatoire.');
        }

        return DB::transaction(function () use ($recours, $agent, $reference) {
            $verrouillee = OnlineRequest::whereKey($recours->id)->lockForUpdate()->firstOrFail();

            if ($verrouillee->status !== 'approved') {
                throw new DomainException('Seul un recours approuvé peut faire l’objet d’une application.');
            }

            $donnees = $verrouillee->form_data ?? [];

            if (($donnees['recours']['application_effectuee'] ?? false) === true) {
                throw new DomainException('L’application de ce recours est déjà enregistrée.');
            }

            $donnees['recours']['application'] = 'APPLIQUEE_MANUELLEMENT';
            $donnees['recours']['application_effectuee'] = true;
            $donnees['recours']['reference_application'] = mb_substr($reference, 0, 255);
            $donnees['recours']['applique_par'] = (int) $agent->id;
            $donnees['recours']['applique_le'] = now()->toIso8601String();

            $verrouillee->forceFill(['form_data' => $donnees])->save();

            $this->audit->tracer($verrouillee->fresh(), 'application_manuelle', [
                'circuit' => 'recours',
                'reference_application' => $reference,
                'acteur_id' => (int) $agent->id,
            ]);

            return $verrouillee->fresh();
        });
    }

    public function cloturer(OnlineRequest $recours, User $agent): OnlineRequest
    {
        $this->verifierActivation();
        $this->verifierPermission($agent, 'recours.appliquer');
        $this->verifierEstRecours($recours);

        if ($recours->status !== 'approved') {
            throw new DomainException('Seul un recours approuvé peut être clôturé.');
        }

        $donnees = $recours->form_data ?? [];

        if (($donnees['recours']['application_effectuee'] ?? false) !== true) {
            throw new DomainException('La clôture exige une application manuelle préalablement enregistrée.');
        }

        return DB::transaction(function () use ($recours, $agent) {
            $verrouillee = OnlineRequest::whereKey($recours->id)->lockForUpdate()->firstOrFail();
            $verrouillee->forceFill(['completed_at' => now()])->save();

            $this->workflows->transiter(
                WorkflowRecoursAcademiqueDefinition::DOMAINE,
                $verrouillee,
                'completed',
                $agent,
                'Recours clôturé après application manuelle'
            );

            $this->tracerActeur($verrouillee->fresh(), $agent, 'completed', [
                'reference_application' => $verrouillee->form_data['recours']['reference_application'] ?? null,
            ]);

            return $verrouillee->fresh();
        });
    }

    public function annuler(OnlineRequest $recours, Etudiant $etudiant, string $motif): OnlineRequest
    {
        $this->verifierActivation();
        $this->verifierEstRecours($recours);
        $this->verifierProprietaire($recours, $etudiant);

        if (! in_array($recours->status, ['draft', 'submitted'], true)) {
            throw new DomainException('Un recours ne peut être annulé par l’étudiant qu’avant sa réception.');
        }

        $motif = trim($motif);

        if ($motif === '') {
            throw new InvalidArgumentException('Le motif d’annulation est obligatoire.');
        }

        return DB::transaction(function () use ($recours, $etudiant, $motif) {
            $verrouillee = OnlineRequest::whereKey($recours->id)->lockForUpdate()->firstOrFail();

            $donnees = $verrouillee->form_data ?? [];
            $donnees['recours']['annulation'] = [
                'motif' => mb_substr($motif, 0, 2000),
                'par_etudiant_id' => (int) $etudiant->id,
                'le' => now()->toIso8601String(),
            ];
            $verrouillee->forceFill(['form_data' => $donnees])->save();

            $this->transitionnerEtudiant($verrouillee, $etudiant, 'cancelled', [], [
                'circuit' => 'recours',
                'motif' => $motif,
            ]);

            return $verrouillee->fresh();
        });
    }

    /**
     * @return Collection<int, OnlineRequest>
     */
    public function pourEtudiant(Etudiant $etudiant): Collection
    {
        $type = $this->typeRecoursOuNull();

        if ($type === null) {
            return collect();
        }

        return OnlineRequest::query()
            ->where('etudiant_id', $etudiant->id)
            ->where('request_type_id', $type->id)
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Explication de la recevabilité, sans jamais transformer un doute en
     * rejet : le statut renvoyé est toujours soumis à décision humaine.
     *
     * @return array<string, mixed>
     */
    public function etatRecevabilite(OnlineRequest $recours): array
    {
        $donnees = $recours->form_data['recours'] ?? [];
        $delai = is_array($donnees['delai'] ?? null) ? $donnees['delai'] : [];

        return [
            'recevabilite' => $donnees['recevabilite'] ?? 'A_VERIFIER',
            'delai_configure' => (bool) ($delai['delai_configure'] ?? false),
            'delai_jours' => $delai['delai_jours'] ?? null,
            'date_reference' => $delai['date_reference'] ?? null,
            'date_limite' => $delai['date_limite'] ?? null,
            'dans_delai' => $delai['dans_delai'] ?? null,
            'reference_reglementaire' => $donnees['reference_reglementaire']
                ?? config('recours_academiques.reference_reglementaire'),
            'explication' => $delai['explication']
                ?? 'Recevabilité à vérifier manuellement : aucun délai officiel n’est configuré.',
        ];
    }

    private function verifierActivation(): void
    {
        if (config('recours_academiques.actif') !== true) {
            throw new DomainException('Le circuit des recours est inactif : aucune action n’est possible.');
        }

        if (trim((string) config('recours_academiques.reference_reglementaire')) === '') {
            throw new DomainException('Aucune référence réglementaire n’est configurée : le circuit reste bloqué.');
        }
    }

    private function verifierPermission(User $acteur, string $permission): void
    {
        if (Gate::forUser($acteur)->denies($permission)) {
            throw new DomainException("Permission « {$permission} » requise pour cette action.");
        }
    }

    private function verifierEstRecours(OnlineRequest $recours): void
    {
        $type = $this->typeRecours();

        if ((int) $recours->request_type_id !== (int) $type->id) {
            throw new DomainException('Cette demande n’est pas un recours académique.');
        }
    }

    private function verifierProprietaire(OnlineRequest $recours, Etudiant $etudiant): void
    {
        if ((int) $recours->etudiant_id !== (int) $etudiant->id) {
            throw new DomainException('Ce recours appartient à un autre étudiant.');
        }
    }

    private function verifierDecisionContestable(Etudiant $etudiant, DecisionDeliberation $decision): void
    {
        if ((int) $decision->etudiant_id !== (int) $etudiant->id) {
            throw new DomainException('La décision visée appartient à un autre étudiant.');
        }

        if ($decision->publie !== true) {
            throw new DomainException('La décision visée n’est pas publiée : aucun recours n’est possible.');
        }

        $deliberation = $decision->deliberation;

        if ($deliberation === null) {
            throw new DomainException('La délibération de la décision visée est introuvable.');
        }

        $publiee = in_array($deliberation->statut, ['published', 'locked', 'archived'], true)
            || $deliberation->published_at !== null;

        if (! $publiee) {
            throw new DomainException('La délibération de la décision visée n’est pas publiée.');
        }

        $types = config('recours_academiques.types_decisions_contestables', []);

        if (! is_array($types) || ! in_array((string) $decision->decision, $types, true)) {
            throw new DomainException(
                "Le type de décision « {$decision->decision} » n’est pas déclaré contestable par le texte officiel."
            );
        }
    }

    private function verifierAbsenceDoublon(Etudiant $etudiant, DecisionDeliberation $decision, RequestType $type): void
    {
        $existe = OnlineRequest::query()
            ->where('etudiant_id', $etudiant->id)
            ->where('request_type_id', $type->id)
            ->whereNotIn('status', ['rejected', 'cancelled'])
            ->get()
            ->contains(fn (OnlineRequest $demande) => (int) ($demande->form_data['recours']['decision_id'] ?? 0) === (int) $decision->id);

        if ($existe) {
            throw new DomainException('Un recours est déjà ouvert pour cette décision.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function evaluerRecevabilite(DecisionDeliberation $decision): array
    {
        $delai = config('recours_academiques.delai_depot_jours');
        $reference = $decision->deliberation?->published_at;

        if (! is_int($delai) || $delai < 0) {
            return [
                'delai_configure' => false,
                'delai_jours' => null,
                'date_reference' => $reference?->toDateString(),
                'date_limite' => null,
                'dans_delai' => null,
                'explication' => 'Aucun délai officiel n’est configuré : la recevabilité doit être vérifiée manuellement.',
            ];
        }

        if ($reference === null) {
            return [
                'delai_configure' => true,
                'delai_jours' => $delai,
                'date_reference' => null,
                'date_limite' => null,
                'dans_delai' => null,
                'explication' => 'La date de publication est inconnue : la recevabilité doit être vérifiée manuellement.',
            ];
        }

        $limite = $reference->copy()->startOfDay()->addDays($delai);
        $dansDelai = now()->startOfDay()->lessThanOrEqualTo($limite);

        return [
            'delai_configure' => true,
            'delai_jours' => $delai,
            'date_reference' => $reference->toDateString(),
            'date_limite' => $limite->toDateString(),
            'dans_delai' => $dansDelai,
            'explication' => $dansDelai
                ? "Délai calculé jusqu’au {$limite->toDateString()} : la recevabilité reste une décision humaine."
                : "Délai calculé dépassé le {$limite->toDateString()} : aucun rejet automatique, décision humaine requise.",
        ];
    }

    private function typeRecours(): RequestType
    {
        $type = $this->typeRecoursOuNull();

        if ($type === null) {
            throw new DomainException(
                'Le type de demande des recours est absent ou inactif : le circuit ne peut pas fonctionner.'
            );
        }

        return $type;
    }

    private function typeRecoursOuNull(): ?RequestType
    {
        $code = (string) config('recours_academiques.type_code', 'RECOURS_ACADEMIQUE');

        return RequestType::query()
            ->where('code', $code)
            ->where('active', true)
            ->first();
    }

    /**
     * Trace l'acte d'un agent sous le vocabulaire du module
     * (`online_request.<événement>`), en complément de l'audit du moteur
     * (workflow, version, motif). Aucune donnée personnelle du motif n'est
     * recopiée : seuls les identifiants techniques et le sens de l'acte.
     *
     * @param  array<string, mixed>  $contexte
     */
    private function tracerActeur(OnlineRequest $recours, User $acteur, string $evenement, array $contexte = []): void
    {
        $this->audit->tracer($recours, $evenement, array_merge([
            'circuit' => 'recours',
            'acteur_id' => (int) $acteur->id,
            'role' => (string) ($acteur->role ?? ''),
        ], $contexte));
    }

    private function transitionnerEtudiant(
        OnlineRequest $recours,
        Etudiant $etudiant,
        string $cible,
        array $attributs = [],
        array $contexte = []
    ): OnlineRequest {
        return DB::transaction(function () use ($recours, $etudiant, $cible, $attributs, $contexte) {
            $verrouillee = OnlineRequest::whereKey($recours->id)->lockForUpdate()->firstOrFail();

            if ((int) $verrouillee->etudiant_id !== (int) $etudiant->id) {
                throw new DomainException('Ce recours appartient à un autre étudiant.');
            }

            $definition = $this->workflows->resoudreDefinition(
                WorkflowRecoursAcademiqueDefinition::DOMAINE,
                $verrouillee
            );

            if (! $definition->transitionAutorisee($verrouillee->status, $cible)) {
                throw new DomainException(
                    "Transition interdite de « {$verrouillee->status} » vers « {$cible} » pour un recours."
                );
            }

            if (! $definition->verifierGardes($verrouillee->status, $cible, $verrouillee, null)) {
                throw new DomainException("Conditions non remplies pour la transition vers « {$cible} ».");
            }

            $verrouillee->forceFill(array_merge(['status' => $cible], $attributs))->save();

            $this->audit->tracer($verrouillee->fresh(), $cible, array_merge([
                'circuit' => 'recours',
                'acteur' => 'etudiant:'.$etudiant->id,
            ], $contexte));

            return $verrouillee->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $formData
     */
    private function creerDemande(
        Etudiant $etudiant,
        DecisionDeliberation $decision,
        RequestType $type,
        string $motif,
        array $formData
    ): OnlineRequest {
        for ($tentative = 0; $tentative < 3; $tentative++) {
            try {
                return OnlineRequest::create([
                    'reference' => $this->genererReference(),
                    'etudiant_id' => $etudiant->id,
                    'request_type_id' => $type->id,
                    'annee_universitaire_id' => $decision->deliberation?->annee_universitaire_id,
                    'objet' => mb_substr('Recours — décision '.$decision->decision.' #'.$decision->id, 0, 150),
                    'description' => $motif,
                    'form_data' => $formData,
                    'version_workflow' => (int) config('recours_academiques.workflow.version', 1),
                ]);
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        throw new RuntimeException('Génération de la référence du recours impossible, réessayez.');
    }

    private function genererReference(): string
    {
        return sprintf('REC-%s-%06d', date('Y'), random_int(0, 999999));
    }
}
