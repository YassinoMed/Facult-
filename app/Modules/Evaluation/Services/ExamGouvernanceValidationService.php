<?php

namespace App\Modules\Evaluation\Services;

use App\Models\IntegrationLog;
use App\Models\User;
use App\Modules\Evaluation\Events\EvaluationChanged;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\PlanningEvenement;
use App\Modules\Evaluation\Models\PlanningValidation;
use App\Modules\Schedule\Services\ExamScheduleEngineService;
use App\Support\Permissions;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gouvernance du planning d'examens (§2.7, §2.8, §3.3, §3.5).
 *
 * Le circuit est PERSISTÉ et APPEND-ONLY :
 *   soumis → certifié → approuvé (2 personnes distinctes) → publié
 *   ↘ rejeté (motif obligatoire, retour au préparateur)
 *   ↘ escalade (bloque la publication jusqu'à résolution motivée)
 *
 * Invariants vérifiés côté serveur, à chaque appel :
 * 1. Le préparateur ne certifie, n'approuve ni ne publie sa propre version.
 * 2. Le certificateur et les deux approbateurs sont des personnes distinctes.
 * 3. Un rejet exige un motif et conserve la version rejetée.
 * 4. Une escalade non résolue bloque toute publication.
 * 5. La publication vérifie l'empreinte certifiée : tout changement de
 *    planning postérieur à la certification est refusé.
 * 6. Après le gel institutionnel, seule une dérogation motivée portée par
 *    un acteur habilité (`evaluations.publish`) est acceptée.
 * 7. Chaque transition écrit une ligne d'audit immuable.
 */
class ExamGouvernanceValidationService
{
    public function __construct(
        private ExamPublicationOrchestratorService $publicationService,
        private ExamScheduleEngineService $scheduleEngine,
        private ExamenService $examenService,
    ) {}

    /**
     * Soumet une NOUVELLE version du planning au circuit de validation.
     */
    public function soumettrePourValidation(
        Examen $examen,
        int $preparateurId,
        ?string $dateGel = null,
        ?string $justificationExceptionnelle = null,
    ): PlanningValidation {
        return DB::transaction(function () use ($examen, $preparateurId, $dateGel, $justificationExceptionnelle) {
            /** @var Examen $verrouille */
            $verrouille = Examen::whereKey($examen->id)->lockForUpdate()->firstOrFail();

            if (! in_array($verrouille->statut, ['draft', 'planned', 'scheduled'], true)) {
                throw new DomainException(
                    "Soumission impossible depuis le statut « {$verrouille->statut} ». Utilisez une révision de version."
                );
            }

            $diagnostic = $this->auditer($verrouille);
            if (! $diagnostic['conforme']) {
                throw new DomainException(
                    'Soumission impossible : contraintes bloquantes non résolues ('
                    .implode(' ; ', $diagnostic['conflits']).').'
                );
            }

            $gel = $this->resoudreDateGel($dateGel);

            $exceptionnel = false;
            $motifExceptionnel = null;

            if ($gel !== null && Carbon::now()->isAfter($gel)) {
                $this->autoriserDerogation($preparateurId, $justificationExceptionnelle, $gel);
                $exceptionnel = true;
                $motifExceptionnel = trim((string) $justificationExceptionnelle);
            }

            $version = ((int) PlanningValidation::query()
                ->where('examen_id', $verrouille->id)
                ->max('version')) + 1;

            $validation = PlanningValidation::create([
                'examen_id' => $verrouille->id,
                'version' => $version,
                'statut' => PlanningValidation::STATUT_SOUMIS,
                'empreinte_planning' => $this->empreinte($verrouille),
                'date_gel_appliquee' => $gel,
                'prepare_par' => $preparateurId,
                'soumis_at' => now(),
                'exceptionnel' => $exceptionnel,
                'motif_exceptionnel' => $motifExceptionnel,
            ]);

            $this->journaliser($validation, PlanningEvenement::ACTION_SOUMIS, $preparateurId, 'preparateur', $exceptionnel ? $motifExceptionnel : null, [
                'statut' => null,
            ], [
                'statut' => $validation->statut,
                'version' => $version,
                'empreinte_planning' => $validation->empreinte_planning,
            ]);

            $this->tracer('exam.governance.submitted', 'EXAM-'.$verrouille->id);
            EvaluationChanged::dispatch('examen.soumis_validation', $verrouille->id);

            return $validation;
        });
    }

    /**
     * Certification technique de conformité (personne distincte du préparateur).
     */
    public function certifierConformite(PlanningValidation $validation, int $certificateurId): PlanningValidation
    {
        return DB::transaction(function () use ($validation, $certificateurId) {
            /** @var PlanningValidation $v */
            $v = PlanningValidation::whereKey($validation->id)->lockForUpdate()->firstOrFail();

            $this->exigerStatut($v, [PlanningValidation::STATUT_SOUMIS], 'certifier');
            $this->exigerPersonneDistincte($v->prepare_par, $certificateurId, 'Le préparateur ne peut pas certifier sa propre proposition.');
            $this->exigerRoleSepare('certificateur', $v->prepare_par, $certificateurId, 'le certificateur doit exercer un rôle différent de celui du préparateur.');

            $diagnostic = $this->auditer($v->examen);
            if (! $diagnostic['conforme']) {
                throw new DomainException('Certification impossible : anomalies ou conflits subsistants ('.implode(' ; ', $diagnostic['conflits']).').');
            }

            $avant = $this->instantane($v);

            $v->forceFill([
                'statut' => PlanningValidation::STATUT_CERTIFIE,
                'certifie_par' => $certificateurId,
                'certifie_at' => now(),
                'empreinte_planning' => $this->empreinte($v->examen),
            ])->save();

            $this->journaliser($v, PlanningEvenement::ACTION_CERTIFIE, $certificateurId, 'certificateur', null, $avant, $this->instantane($v));
            EvaluationChanged::dispatch('examen.certifie', $v->examen_id);

            return $v;
        });
    }

    /**
     * Première approbation métier.
     */
    public function approuverPremierNiveau(PlanningValidation $validation, int $approbateurId, ?string $commentaire = null): PlanningValidation
    {
        return DB::transaction(function () use ($validation, $approbateurId, $commentaire) {
            /** @var PlanningValidation $v */
            $v = PlanningValidation::whereKey($validation->id)->lockForUpdate()->firstOrFail();

            $this->exigerStatut($v, [PlanningValidation::STATUT_CERTIFIE], 'approuver');
            $this->exigerPersonneDistincte($v->prepare_par, $approbateurId, "L'approbateur ne peut pas être le préparateur.");
            $this->exigerPersonneDistincte($v->certifie_par, $approbateurId, "L'approbateur ne peut pas être le certificateur.");
            $this->exigerRoleSepare('approbateur', $v->prepare_par, $approbateurId, "l'approbateur doit exercer un rôle différent de celui du préparateur.");
            $this->exigerRoleSepare('approbateur', $v->certifie_par, $approbateurId, "l'approbateur doit exercer un rôle différent de celui du certificateur.");

            if ($v->approbateur_1 !== null) {
                throw new DomainException('La première approbation est déjà enregistrée pour cette version.');
            }

            $avant = $this->instantane($v);

            $v->forceFill([
                'approbateur_1' => $approbateurId,
                'approuve_1_at' => now(),
            ])->save();

            $this->journaliser($v, PlanningEvenement::ACTION_APPROUVE_1, $approbateurId, 'approbateur', $commentaire, $avant, $this->instantane($v));
            EvaluationChanged::dispatch('examen.approbation_1', $v->examen_id);

            return $v;
        });
    }

    /**
     * Seconde approbation : personne distincte du préparateur, du
     * certificateur et du premier approbateur.
     */
    public function approuverSecondNiveau(PlanningValidation $validation, int $approbateurId, ?string $commentaire = null): PlanningValidation
    {
        return DB::transaction(function () use ($validation, $approbateurId, $commentaire) {
            /** @var PlanningValidation $v */
            $v = PlanningValidation::whereKey($validation->id)->lockForUpdate()->firstOrFail();

            $this->exigerStatut($v, [PlanningValidation::STATUT_CERTIFIE], 'approuver');

            if ($v->approbateur_1 === null) {
                throw new DomainException('La première approbation est requise avant le second niveau.');
            }

            $this->exigerPersonneDistincte($v->prepare_par, $approbateurId, 'Le second approbateur ne peut pas être le préparateur.');
            $this->exigerPersonneDistincte($v->certifie_par, $approbateurId, 'Le second approbateur ne peut pas être le certificateur.');
            $this->exigerPersonneDistincte($v->approbateur_1, $approbateurId, 'Double validation requise : le second approbateur doit être distinct du premier.');
            $this->exigerRoleSepare('approbateur', $v->prepare_par, $approbateurId, 'le second approbateur doit exercer un rôle différent de celui du préparateur.');
            $this->exigerRoleSepare('approbateur', $v->certifie_par, $approbateurId, 'le second approbateur doit exercer un rôle différent de celui du certificateur.');

            // Le planning certifié ne doit pas avoir changé depuis la certification.
            $empreinteCourante = $this->empreinte($v->examen);
            if ($v->empreinte_planning !== null && ! hash_equals($v->empreinte_planning, $empreinteCourante)) {
                throw new DomainException(
                    'Le planning a changé après certification : l’approbation est refusée, une nouvelle version est requise.'
                );
            }

            $avant = $this->instantane($v);

            $v->forceFill([
                'approbateur_2' => $approbateurId,
                'approuve_2_at' => now(),
                'statut' => PlanningValidation::STATUT_APPROUVE,
            ])->save();

            $this->journaliser($v, PlanningEvenement::ACTION_APPROUVE_2, $approbateurId, 'approbateur', $commentaire, $avant, $this->instantane($v));
            $this->journaliser($v, PlanningEvenement::ACTION_APPROUVE, $approbateurId, 'approbateur', $commentaire, $avant, $this->instantane($v));
            EvaluationChanged::dispatch('examen.approbation_2', $v->examen_id);

            return $v;
        });
    }

    /**
     * Refus motivé : la version est conservée, l'examen retourne au
     * préparateur, aucune publication n'est possible sur cette version.
     */
    public function refuserPlanning(PlanningValidation $validation, int $auteurId, string $motifRefus): PlanningValidation
    {
        $motif = trim($motifRefus);
        if ($motif === '') {
            throw new DomainException('Le motif de refus est obligatoire et doit être explicité.');
        }

        return DB::transaction(function () use ($validation, $auteurId, $motif) {
            /** @var PlanningValidation $v */
            $v = PlanningValidation::whereKey($validation->id)->lockForUpdate()->firstOrFail();

            $this->exigerStatut($v, [
                PlanningValidation::STATUT_SOUMIS,
                PlanningValidation::STATUT_CERTIFIE,
            ], 'refuser');

            $avant = $this->instantane($v);

            $v->forceFill([
                'statut' => PlanningValidation::STATUT_REJETE,
                'rejete_par' => $auteurId,
                'rejete_at' => now(),
                'motif_rejet' => $motif,
            ])->save();

            $examen = $v->examen;
            if ($examen !== null && ! in_array($examen->statut, ['published', 'locked', 'archived'], true)) {
                $examen->update(['statut' => 'draft']);
            }

            $this->journaliser($v, PlanningEvenement::ACTION_REJETE, $auteurId, 'approbateur', $motif, $avant, $this->instantane($v));
            $this->tracer('exam.governance.rejected', 'EXAM-'.$v->examen_id);
            EvaluationChanged::dispatch('examen.refuse', $v->examen_id);

            return $v;
        });
    }

    /**
     * Escalade hiérarchique : bloque la publication jusqu'à résolution.
     */
    public function escaladerDesaccord(PlanningValidation $validation, int $declarantId, string $motifEscalade): PlanningValidation
    {
        $motif = trim($motifEscalade);
        if ($motif === '') {
            throw new DomainException("Le motif d'escalade hiérarchique est obligatoire.");
        }

        return DB::transaction(function () use ($validation, $declarantId, $motif) {
            /** @var PlanningValidation $v */
            $v = PlanningValidation::whereKey($validation->id)->lockForUpdate()->firstOrFail();

            $this->exigerStatut($v, [
                PlanningValidation::STATUT_SOUMIS,
                PlanningValidation::STATUT_CERTIFIE,
                PlanningValidation::STATUT_APPROUVE,
            ], 'escalader');

            $avant = $this->instantane($v);

            $v->forceFill([
                'statut' => PlanningValidation::STATUT_ESCALADE,
                'escalade_par' => $declarantId,
                'escalade_at' => now(),
                'motif_escalade' => $motif,
                'escalade_resolue' => false,
            ])->save();

            $this->journaliser($v, PlanningEvenement::ACTION_ESCALADE, $declarantId, 'declarant', $motif, $avant, $this->instantane($v));
            EvaluationChanged::dispatch('examen.escalade_desaccord', $v->examen_id);

            return $v;
        });
    }

    /**
     * Résolution motivée de l'escalade par une autorité distincte.
     */
    public function resoudreEscalade(PlanningValidation $validation, int $autoriteId, string $decisionMotivee): PlanningValidation
    {
        $decision = trim($decisionMotivee);
        if ($decision === '') {
            throw new DomainException("La décision motivée de l'autorité est obligatoire.");
        }

        return DB::transaction(function () use ($validation, $autoriteId, $decision) {
            /** @var PlanningValidation $v */
            $v = PlanningValidation::whereKey($validation->id)->lockForUpdate()->firstOrFail();

            $this->exigerStatut($v, [PlanningValidation::STATUT_ESCALADE], 'résoudre l’escalade');

            if ($v->escalade_resolue) {
                throw new DomainException('Cette escalade est déjà résolue.');
            }

            $this->exigerPersonneDistincte($v->escalade_par, $autoriteId, "L'autorité ne peut pas résoudre sa propre escalade.");
            $this->exigerPersonneDistincte($v->prepare_par, $autoriteId, 'Le préparateur ne peut pas arbitrer sa propre version.');
            $this->exigerRoleSepare('publicateur', $v->escalade_par, $autoriteId, "l'autorité doit exercer un rôle différent de celui du déclarant de l'escalade.");
            $this->exigerRoleSepare('publicateur', $v->prepare_par, $autoriteId, "l'autorité doit exercer un rôle différent de celui du préparateur.");

            $avant = $this->instantane($v);

            $statutRestaure = $v->estApprouvee()
                ? PlanningValidation::STATUT_APPROUVE
                : ($v->certifie_par !== null ? PlanningValidation::STATUT_CERTIFIE : PlanningValidation::STATUT_SOUMIS);

            $v->forceFill([
                'statut' => $statutRestaure,
                'escalade_resolue' => true,
                'escalade_resolue_par' => $autoriteId,
                'escalade_resolue_at' => now(),
                'resolution_escalade' => $decision,
            ])->save();

            $this->journaliser($v, PlanningEvenement::ACTION_ESCALADE_RESOLUE, $autoriteId, 'autorite', $decision, $avant, $this->instantane($v));
            EvaluationChanged::dispatch('examen.escalade_resolue', $v->examen_id);

            return $v;
        });
    }

    /**
     * Publication officielle : exige toutes les validations, vérifie
     * l'empreinte certifiée et le gel institutionnel.
     */
    public function publierExamenOfficiel(
        PlanningValidation $validation,
        int $editeurId,
        ?string $justificationExceptionnelle = null,
    ): Examen {
        return DB::transaction(function () use ($validation, $editeurId, $justificationExceptionnelle) {
            /** @var PlanningValidation $v */
            $v = PlanningValidation::whereKey($validation->id)->lockForUpdate()->firstOrFail();

            $this->exigerStatut($v, [PlanningValidation::STATUT_APPROUVE], 'publier');

            if ($v->approbateur_1 === null || $v->approbateur_2 === null) {
                throw new DomainException('Publication impossible : la double validation par deux approbateurs distincts est obligatoire.');
            }

            if ($v->estBloqueeParEscalade()) {
                throw new DomainException("Publication impossible tant que le désaccord en cours d'escalade n'est pas résolu.");
            }

            $this->exigerPersonneDistincte($v->prepare_par, $editeurId, 'Le préparateur ne peut pas publier sa propre version.');
            $this->exigerRoleSepare('publicateur', $v->prepare_par, $editeurId, 'le publicateur doit exercer un rôle différent de celui du préparateur.');
            $this->exigerRoleSepare('publicateur', $v->certifie_par, $editeurId, 'le publicateur doit exercer un rôle différent de celui du certificateur.');
            $this->exigerRoleSepare('publicateur', $v->approbateur_1, $editeurId, 'le publicateur doit exercer un rôle différent de celui du premier approbateur.');
            $this->exigerRoleSepare('publicateur', $v->approbateur_2, $editeurId, 'le publicateur doit exercer un rôle différent de celui du second approbateur.');

            $empreinteCourante = $this->empreinte($v->examen);
            if ($v->empreinte_planning === null || ! hash_equals($v->empreinte_planning, $empreinteCourante)) {
                throw new DomainException('Publication refusée : le planning ne correspond plus à la version certifiée.');
            }

            $diagnostic = $this->auditer($v->examen);
            if (! $diagnostic['conforme']) {
                throw new DomainException('Publication impossible : contraintes bloquantes ('.implode(' ; ', $diagnostic['conflits']).').');
            }

            $exceptionnel = false;
            $motifExceptionnel = null;

            if ($v->date_gel_appliquee !== null && Carbon::now()->isAfter($v->date_gel_appliquee)) {
                $this->autoriserDerogation($editeurId, $justificationExceptionnelle, $v->date_gel_appliquee);
                $exceptionnel = true;
                $motifExceptionnel = trim((string) $justificationExceptionnelle);
            }

            $avant = $this->instantane($v);

            $examenPublie = $this->publicationService->publierExamen($v->examen, $editeurId);

            $v->forceFill([
                'statut' => PlanningValidation::STATUT_PUBLIE,
                'publie_par' => $editeurId,
                'publie_at' => now(),
                'exceptionnel' => $exceptionnel,
                'motif_exceptionnel' => $motifExceptionnel,
            ])->save();

            $this->journaliser($v, PlanningEvenement::ACTION_PUBLIE, $editeurId, 'publicateur', $motifExceptionnel, $avant, $this->instantane($v));
            $this->tracer('exam.governance.published', 'EXAM-'.$v->examen_id);

            return $examenPublie;
        });
    }

    /**
     * Ouvre une nouvelle version après publication : la version publiée
     * reste intacte et continue de faire foi jusqu'à validation complète
     * de la suivante.
     */
    public function creerNouvelleVersionPostGel(Examen $examen, int $auteurId, string $motifModification): PlanningValidation
    {
        $motif = trim($motifModification);
        if ($motif === '') {
            throw new DomainException('Le motif de révision de planning est obligatoire.');
        }

        return DB::transaction(function () use ($examen, $auteurId, $motif) {
            /** @var Examen $verrouille */
            $verrouille = Examen::whereKey($examen->id)->lockForUpdate()->firstOrFail();

            $dernierePubliee = PlanningValidation::query()
                ->where('examen_id', $verrouille->id)
                ->where('statut', PlanningValidation::STATUT_PUBLIE)
                ->orderByDesc('version')
                ->first();

            if ($dernierePubliee === null) {
                throw new DomainException('Aucune version publiée à réviser : soumettez d’abord une version initiale.');
            }

            $user = User::find($auteurId);
            if ($user === null || ! $user->hasPermission('evaluations.publish')) {
                throw new DomainException('La révision d’un planning publié exige une autorisation de publication.');
            }

            $diagnostic = $this->auditer($verrouille);
            if (! $diagnostic['conforme']) {
                throw new DomainException('Révision impossible : contraintes bloquantes ('.implode(' ; ', $diagnostic['conflits']).').');
            }

            $version = ((int) PlanningValidation::query()
                ->where('examen_id', $verrouille->id)
                ->max('version')) + 1;

            $validation = PlanningValidation::create([
                'examen_id' => $verrouille->id,
                'version' => $version,
                'statut' => PlanningValidation::STATUT_SOUMIS,
                'empreinte_planning' => $this->empreinte($verrouille),
                'date_gel_appliquee' => $this->resoudreDateGel(null),
                'prepare_par' => $auteurId,
                'soumis_at' => now(),
            ]);

            $this->journaliser($validation, PlanningEvenement::ACTION_VERSION_CREEE, $auteurId, 'preparateur', $motif, [
                'version_publiee' => $dernierePubliee->version,
            ], [
                'nouvelle_version' => $version,
                'empreinte_planning' => $validation->empreinte_planning,
            ]);

            EvaluationChanged::dispatch('examen.nouvelle_version', $verrouille->id);

            return $validation;
        });
    }

    public function derniereValidation(Examen $examen): ?PlanningValidation
    {
        return PlanningValidation::query()
            ->where('examen_id', $examen->id)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * @return array{conforme: bool, conflits: array<int, string>}
     */
    private function auditer(Examen $examen): array
    {
        $audit = $this->scheduleEngine->auditerSessionExamens($examen);

        return [
            'conforme' => (bool) ($audit['conforme'] ?? false),
            'conflits' => array_values($audit['conflits_bloquants'] ?? []),
        ];
    }

    private function empreinte(Examen $examen): string
    {
        return $this->publicationService->calculerEmpreintePublication($examen->fresh());
    }

    private function resoudreDateGel(?string $dateGel): ?Carbon
    {
        $valeur = $dateGel ?? config('modules.evaluation.date_gel');

        return $valeur === null || $valeur === '' ? null : Carbon::parse($valeur);
    }

    private function autoriserDerogation(int $acteurId, ?string $justification, Carbon $gel): void
    {
        if (trim((string) $justification) === '') {
            throw new DomainException(
                "La date de gel institutionnelle ({$gel->toDateString()}) est dépassée : une justification exceptionnelle motivée est obligatoire."
            );
        }

        $user = User::find($acteurId);
        if ($user === null || ! $user->hasPermission('evaluations.publish')) {
            throw new DomainException('Une dérogation post-gel exige un acteur habilité à publier.');
        }
    }

    private function exigerStatut(PlanningValidation $validation, array $statuts, string $action): void
    {
        if (! in_array($validation->statut, $statuts, true)) {
            throw new DomainException(
                "Action « {$action} » impossible depuis le statut « {$validation->statut} »."
            );
        }
    }

    private function exigerPersonneDistincte(?int $referenceId, int $acteurId, string $message): void
    {
        if ($referenceId !== null && (int) $referenceId === $acteurId) {
            throw new DomainException('Séparation des responsabilités : '.$message);
        }
    }

    /**
     * §2.7 — séparation des pouvoirs PAR RÔLE, en complément de la
     * séparation par personne. Désactivée par défaut : la matrice livrée ne
     * permet pas une attribution distincte (voir
     * `ExamSeparationRolesDiagnosticService` et
     * `php artisan examens:separation-roles`).
     *
     * Quand elle est active :
     *  - le rôle de l'acteur doit porter la permission de l'étape ;
     *  - il doit différer du rôle de l'étape de référence.
     *
     * Le compte technique (`super_admin`) reste utilisable comme voie de
     * secours : il porte toutes les permissions et ne peut donc, par
     * construction, rien séparer. Son usage est déjà journalisé par l'audit
     * de gouvernance ; l'exempter évite de rendre le circuit inopérant en
     * situation d'incident.
     */
    private function exigerRoleSepare(string $etape, ?int $referenceId, int $acteurId, string $message): void
    {
        if (! config('modules.evaluation.separation_pouvoirs_par_role', false)) {
            return;
        }

        if ($referenceId === null) {
            return;
        }

        $roleActeur = $this->roleDe($acteurId);

        if ($roleActeur === null || $roleActeur === '') {
            throw new DomainException('Séparation des pouvoirs par rôle : acteur sans rôle identifié.');
        }

        if ($roleActeur === ExamSeparationRolesDiagnosticService::ROLE_TECHNIQUE) {
            return;
        }

        $permission = ExamSeparationRolesDiagnosticService::ETAPES[$etape] ?? null;
        if ($permission !== null && ! in_array($permission, Permissions::pourRole($roleActeur), true)) {
            throw new DomainException(
                "Séparation des pouvoirs par rôle : le rôle « {$roleActeur} » ne porte pas la permission « {$permission} » requise pour l'étape « {$etape} »."
            );
        }

        if ($this->roleDe($referenceId) === $roleActeur) {
            throw new DomainException('Séparation des pouvoirs par rôle : '.$message);
        }
    }

    private function roleDe(?int $utilisateurId): ?string
    {
        if ($utilisateurId === null) {
            return null;
        }

        $role = User::query()->whereKey($utilisateurId)->value('role');

        return $role === null ? null : (string) $role;
    }

    /**
     * @return array<string, mixed>
     */
    private function instantane(PlanningValidation $validation): array
    {
        return [
            'statut' => $validation->statut,
            'version' => $validation->version,
            'prepare_par' => $validation->prepare_par,
            'certifie_par' => $validation->certifie_par,
            'approbateur_1' => $validation->approbateur_1,
            'approbateur_2' => $validation->approbateur_2,
            'publie_par' => $validation->publie_par,
            'empreinte_planning' => $validation->empreinte_planning,
        ];
    }

    /**
     * @param  array<string, mixed>  $avant
     * @param  array<string, mixed>  $apres
     */
    private function journaliser(
        PlanningValidation $validation,
        string $action,
        ?int $acteurId,
        ?string $role,
        ?string $motif,
        array $avant,
        array $apres,
    ): void {
        PlanningEvenement::create([
            'planning_validation_id' => $validation->id,
            'examen_id' => $validation->examen_id,
            'version' => $validation->version,
            'action' => $action,
            'acteur_id' => $acteurId,
            'role_contexte' => $role,
            'motif' => $motif,
            'empreinte_planning' => $validation->empreinte_planning,
            'avant' => $avant,
            'apres' => $apres,
            'created_at' => now(),
        ]);
    }

    private function tracer(string $action, string $correlationId): void
    {
        IntegrationLog::tracer(
            'evaluation',
            'internal',
            $action,
            'completed',
            null,
            200,
            null,
            null,
            (string) Str::uuid(),
            $correlationId
        );
    }
}
