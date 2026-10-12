<?php

namespace App\Modules\OnlineRequests\Services;

use App\Models\Etudiant;
use App\Modules\OnlineRequests\Events\OnlineRequestApproved;
use App\Modules\OnlineRequests\Events\OnlineRequestCompleted;
use App\Modules\OnlineRequests\Events\OnlineRequestCreated;
use App\Modules\OnlineRequests\Events\OnlineRequestRejected;
use App\Modules\OnlineRequests\Events\OnlineRequestSubmitted;
use App\Modules\OnlineRequests\Events\OnlineRequestWaitingForStudent;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\OnlineRequests\Models\RequestMessage;
use App\Modules\OnlineRequests\Models\RequestType;
use App\Services\NotificationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Cycle de vie des demandes (§2/§8-9) : création, transitions
 * contrôlées, référence serveur. Le statut n'est JAMAIS mass-assigné
 * depuis le client — toute transition passe par `transitionner()`.
 */
class OnlineRequestService
{
    /**
     * @param  array{request_type_id: int, annee_universitaire_id?: ?int, objet: string, description?: ?string, form_data?: array<string, mixed>}  $donnees
     */
    public function creer(Etudiant $etudiant, array $donnees): OnlineRequest
    {
        $type = RequestType::where('active', true)->findOrFail($donnees['request_type_id']);

        $formData = $this->validerSchema($type, $donnees['form_data'] ?? []);

        /** @var OnlineRequest $demande */
        $demande = DB::transaction(function () use ($etudiant, $donnees, $formData) {
            for ($tentative = 0; $tentative < 3; $tentative++) {
                try {
                    return OnlineRequest::create([
                        'reference' => $this->genererReference(),
                        'etudiant_id' => $etudiant->id,
                        'request_type_id' => $donnees['request_type_id'],
                        'annee_universitaire_id' => $donnees['annee_universitaire_id'] ?? null,
                        'objet' => $donnees['objet'],
                        'description' => $donnees['description'] ?? null,
                        'form_data' => $formData,
                        'status' => 'draft',
                    ]);
                } catch (UniqueConstraintViolationException) {
                    continue;
                }
            }

            throw new \RuntimeException('Génération de référence impossible, réessayez.');
        });

        OnlineRequestCreated::dispatch($demande->fresh(['etudiant', 'type']));

        return $demande;
    }

    public function soumettre(OnlineRequest $demande): OnlineRequest
    {
        // Garde AVANT transition : aucun changement d'état si refusée.
        if ($demande->type->requires_attachment && $demande->pieces()->count() === 0) {
            throw ValidationException::withMessages([
                'pieces' => 'Ce type de demande exige au moins une pièce justificative.',
            ]);
        }

        $demande = $this->transitionner($demande, 'submitted', [
            'submitted_at' => now(),
            'faculte_id' => $demande->etudiant->faculte_id,
        ]);

        OnlineRequestSubmitted::dispatch($demande->fresh(['etudiant', 'type']));

        return $demande->fresh();
    }

    public function annuler(OnlineRequest $demande): OnlineRequest
    {
        return $this->transitionner($demande, 'cancelled');
    }

    public function recevoir(OnlineRequest $demande, ?int $responsable = null): OnlineRequest
    {
        return $this->transitionner($demande, 'received', ['assigned_to' => $responsable]);
    }

    public function examiner(OnlineRequest $demande): OnlineRequest
    {
        return $this->transitionner($demande, 'in_review', ['processed_at' => now()]);
    }

    public function demanderPiece(OnlineRequest $demande, string $commentaire): OnlineRequest
    {
        $demande = $this->transitionner($demande, 'waiting_student', [], ['commentaire' => $commentaire]);

        OnlineRequestWaitingForStudent::dispatch($demande->fresh(['etudiant', 'type']), $commentaire);

        return $demande;
    }

    public function approuver(OnlineRequest $demande): OnlineRequest
    {
        $demande = $this->transitionner($demande, 'approved', ['processed_at' => now()]);

        OnlineRequestApproved::dispatch($demande->fresh(['etudiant', 'type']));

        return $demande;
    }

    public function rejeter(OnlineRequest $demande, string $motif): OnlineRequest
    {
        $demande = $this->transitionner($demande, 'rejected', [
            'rejected_at' => now(),
            'rejection_reason' => $motif,
        ]);

        OnlineRequestRejected::dispatch($demande->fresh(['etudiant', 'type']));

        return $demande;
    }

    public function terminer(OnlineRequest $demande): OnlineRequest
    {
        $demande = $this->transitionner($demande, 'completed', ['completed_at' => now()]);

        OnlineRequestCompleted::dispatch($demande->fresh(['etudiant', 'type']));

        return $demande;
    }

    /**
     * @param  array<string, mixed>  $attributs
     * @param  array<string, mixed>  $contexteAudit
     */
    public function transitionner(OnlineRequest $demande, string $vers, array $attributs = [], array $contexteAudit = []): OnlineRequest
    {
        return DB::transaction(function () use ($demande, $vers, $attributs, $contexteAudit) {
            // Phase 9 §80 : verrou pessimiste — deux agents ne font
            // jamais diverger la même demande (transition perdue).
            $verrouillee = OnlineRequest::whereKey($demande->id)->lockForUpdate()->firstOrFail();

            if (! RequestWorkflowService::autorisee($verrouillee->status, $vers)) {
                throw ValidationException::withMessages([
                    'status' => "Transition « {$verrouillee->status} » → « {$vers} » interdite.",
                ]);
            }

            $verrouillee->forceFill(array_merge(['status' => $vers], $attributs))->save();

            app(AuditDemandes::class)->tracer($verrouillee->fresh(), $vers, $contexteAudit);

            return $verrouillee->fresh();
        });
    }

    /**
     * Valide les champs dynamiques contre le schéma du type (§17).
     *
     * @param  array<string, mixed>  $formData
     * @return array<string, mixed>
     */
    public function validerSchema(RequestType $type, array $formData): array
    {
        $schema = $type->form_schema ?? [];
        $regles = [];

        foreach ($schema as $champ) {
            $regle = [($champ['required'] ?? false) ? 'required' : 'nullable'];

            match ($champ['type'] ?? 'text') {
                'select' => $regle[] = 'in:'.implode(',', $champ['options'] ?? []),
                'date' => $regle[] = 'date',
                'checkbox' => $regle[] = 'boolean',
                default => $regle[] = 'string|max:500',
            };

            $regles['form_data.'.$champ['name']] = implode('|', $regle);
        }

        return Validator::make(
            ['form_data' => $formData],
            $regles,
            [],
            collect($schema)->mapWithKeys(fn ($c) => ['form_data.'.$c['name'] => $c['label'] ?? $c['name']])->all(),
        )->validate()['form_data'] ?? [];
    }

    private function genererReference(): string
    {
        return sprintf('REQ-%s-%06d', date('Y'), random_int(0, 999999));
    }

    /**
     * Message du fil de discussion (Phase 9 §61) : administration
     * (user_id) ou étudiant propriétaire (etudiant_id) — jamais les
     * deux. Contenu audité (jamais la pièce), autre partie notifiée.
     *
     * @throws ValidationException
     */
    public function posterMessage(OnlineRequest $demande, string $message, ?int $userId, ?int $etudiantId): RequestMessage
    {
        $texte = trim($message);

        if ($texte === '' || mb_strlen($texte) > 2000) {
            throw ValidationException::withMessages(['message' => 'Message requis (2000 caractères maximum).']);
        }

        if (($userId === null) === ($etudiantId === null)) {
            throw ValidationException::withMessages(['message' => 'Auteur ambigu.']);
        }

        if ($etudiantId !== null && (int) $demande->etudiant_id !== $etudiantId) {
            throw ValidationException::withMessages(['message' => 'Demande d’un autre étudiant.']);
        }

        $cree = $demande->messages()->create([
            'user_id' => $userId,
            'etudiant_id' => $etudiantId,
            'message' => $texte,
        ]);

        app(AuditDemandes::class)->tracer($demande, 'message', ['auteur' => $userId ?? "etudiant:{$etudiantId}"]);

        if ($userId !== null) {
            app(NotificationService::class)->notifierEtudiant(
                $demande->etudiant,
                'demande.message',
                'Nouveau message',
                "L’administration a répondu à votre demande {$demande->reference}.",
                route('etudiant.demandes.show', $demande),
            );
        }

        return $cree;
    }
}
