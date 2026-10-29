<?php

namespace App\Modules\Evaluation\Services;

use App\Models\IntegrationLog;
use App\Models\User;
use App\Modules\Evaluation\Events\EvaluationChanged;
use App\Modules\Evaluation\Models\DelegationUrgence;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\EvaluationIncident;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Service de gestion des incidents, urgences du jour J et suppléance d'urgence (LOT F - §2.11, §2.12).
 *
 * Règles métier absolues :
 * 1. Incidents qualifiés : type, gravité (mineure, majeure, critique), horodatage, preuves et actions.
 * 2. Mesures d'urgence temporaires proportionnées sans modification silencieuse du planning officiel.
 * 3. Suppléance d'urgence : désignation préalable, périmètre strict, durée limitée, interdiction d'auto-validation.
 * 4. Contrôle et régularisation post-urgence obligatoires.
 */
class ExamUrgenceSuppleanceService
{
    public const GRAVITES = ['mineure', 'majeure', 'critique'];

    /**
     * Déclaration d'un incident d'épreuve avec niveau de gravité et qualification formelle.
     *
     * @param array{
     *     epreuve_id: int,
     *     type: string,
     *     gravite: string,
     *     description: string,
     *     etudiant_id?: ?int,
     *     declarant_id: int,
     *     delai_cible_minutes?: int,
     *     preuves_refs?: array<int, string>
     * } $data
     */
    public function declarerIncident(array $data): EvaluationIncident
    {
        $gravite = strtolower($data['gravite'] ?? 'mineure');
        if (! in_array($gravite, self::GRAVITES, true)) {
            throw new DomainException("Niveau de gravité invalide : « {$gravite} ». Niveaux autorisés : mineure, majeure, critique.");
        }

        if (! in_array($data['type'], EvaluationIncident::TYPES, true)) {
            throw new DomainException("Type d'incident invalide : « {$data['type']} ».");
        }

        $delaiMinutes = (int) ($data['delai_cible_minutes'] ?? ($gravite === 'critique' ? 30 : 120));
        if ($delaiMinutes < 1 || $delaiMinutes > 10080) {
            throw new DomainException('Le délai cible doit être compris entre 1 minute et 7 jours.');
        }

        $preuves = array_values(array_filter(
            array_map('strval', (array) ($data['preuves_refs'] ?? [])),
            fn (string $preuve): bool => trim($preuve) !== ''
        ));

        $epreuve = Epreuve::findOrFail($data['epreuve_id']);

        return DB::transaction(function () use ($data, $epreuve, $gravite, $delaiMinutes, $preuves) {
            $incidentUuid = (string) Str::uuid();

            $detailsTechniques = [
                'incident_id' => $incidentUuid,
                'gravite' => $gravite,
                'description_textuelle' => trim($data['description']),
                'delai_cible_minutes' => $delaiMinutes,
                'preuves_refs' => $preuves,
                'actions_prises' => [],
                'signale_le' => Carbon::now()->toIso8601String(),
            ];

            $incident = EvaluationIncident::create([
                'epreuve_id' => $epreuve->id,
                'etudiant_id' => $data['etudiant_id'] ?? null,
                'type' => $data['type'],
                'description' => json_encode($detailsTechniques, JSON_UNESCAPED_UNICODE),
                'created_by' => $data['declarant_id'],
                // Colonnes qualifiantes : exploitables sans décoder le JSON.
                'gravite' => $gravite,
                'delai_cible_at' => Carbon::now()->addMinutes($delaiMinutes),
                'preuves' => $preuves,
                'actions_prises' => [],
            ]);

            IntegrationLog::tracer(
                'evaluation',
                'internal',
                "incident.declared.{$gravite}",
                'completed',
                null,
                200,
                null,
                null,
                $incidentUuid,
                'EPR-'.$epreuve->id
            );

            EvaluationChanged::dispatch('incident.signale', $epreuve->id);

            return $incident;
        });
    }

    /**
     * Enregistre une mesure d'urgence temporaire en réponse à un incident majeur ou critique.
     * Règle absolue : la mesure d'urgence temporaire n'équivaut PAS à une modification officielle du planning.
     */
    public function consignerMesureUrgence(
        EvaluationIncident $incident,
        string $mesurePrise,
        int $responsableId,
        ?string $justification = null
    ): EvaluationIncident {
        return DB::transaction(function () use ($incident, $mesurePrise, $responsableId, $justification) {
            $incident->refresh();

            $meta = $this->extraireMetadonneesIncident($incident);

            $meta['actions_prises'][] = [
                'type' => 'mesure_urgence_temporaire',
                'action' => trim($mesurePrise),
                'justification' => $justification,
                'responsable_id' => $responsableId,
                'applique_le' => Carbon::now()->toIso8601String(),
            ];

            $incident->statut = 'en_cours';
            $incident->description = json_encode($meta, JSON_UNESCAPED_UNICODE);
            // Colonne structurée : la mesure reste lisible sans JSON.
            $incident->actions_prises = $meta['actions_prises'];
            $incident->responsable_id ??= $responsableId;
            $incident->save();

            EvaluationChanged::dispatch('incident.mesure_urgence_prise', $incident->epreuve_id);

            return $incident;
        });
    }

    /**
     * Résolution formelle et clôture motivée de l'incident.
     */
    public function resoudreIncident(
        EvaluationIncident $incident,
        string $resolutionMotivée,
        int $responsableId,
        ?array $preuvesFinales = null
    ): EvaluationIncident {
        $resolution = trim($resolutionMotivée);
        if ($resolution === '') {
            throw new DomainException("La motivation de la résolution de l'incident est obligatoire.");
        }

        return DB::transaction(function () use ($incident, $resolution, $responsableId, $preuvesFinales) {
            /** @var EvaluationIncident $incident */
            $incident = EvaluationIncident::whereKey($incident->id)->lockForUpdate()->firstOrFail();

            if ($incident->statut === 'resolu') {
                throw new DomainException('Cet incident est déjà résolu : une nouvelle résolution écraserait l’historique.');
            }

            $meta = $this->extraireMetadonneesIncident($incident);

            $resoluLe = Carbon::now();
            $meta['resolution_finale'] = [
                'constat' => $resolution,
                'responsable_id' => $responsableId,
                'preuves_finales' => $preuvesFinales ?? [],
                'resolu_le' => $resoluLe->toIso8601String(),
            ];

            $actions = (array) ($incident->actions_prises ?? []);
            $actions[] = [
                'type' => 'resolution',
                'constat' => $resolution,
                'responsable_id' => $responsableId,
                'resolu_le' => $resoluLe->toIso8601String(),
            ];

            $preuves = array_values(array_unique(array_merge(
                (array) ($incident->preuves ?? []),
                array_map('strval', (array) ($preuvesFinales ?? []))
            )));

            $incident->statut = 'resolu';
            $incident->description = json_encode($meta, JSON_UNESCAPED_UNICODE);
            $incident->actions_prises = $actions;
            $incident->preuves = $preuves;
            $incident->resolu_at = $resoluLe;
            $incident->responsable_id ??= $responsableId;
            $incident->save();

            IntegrationLog::tracer(
                'evaluation',
                'internal',
                'incident.resolved',
                'completed',
                null,
                200,
                null,
                null,
                (string) ($incident->uuid ?? Str::uuid()),
                'EPR-'.$incident->epreuve_id
            );

            EvaluationChanged::dispatch('incident.resolu', $incident->epreuve_id);

            return $incident;
        });
    }

    /**
     * Validation indépendante de la résolution d'un incident (§2.11).
     *
     * La résolution est d'abord prononcée par un responsable ; elle n'est
     * validée que par une personne distincte du déclarant et du responsable
     * du traitement. Le validateur doit être habilité côté serveur.
     */
    public function validerResolutionIncident(
        EvaluationIncident $incident,
        int $validateurId,
        ?string $commentaire = null
    ): EvaluationIncident {
        return DB::transaction(function () use ($incident, $validateurId, $commentaire) {
            /** @var EvaluationIncident $ligne */
            $ligne = EvaluationIncident::whereKey($incident->id)->lockForUpdate()->firstOrFail();

            if ($ligne->statut !== 'resolu') {
                throw new DomainException('Seule une résolution prononcée peut être validée.');
            }

            if ($ligne->estValide()) {
                throw new DomainException('Cette résolution est déjà validée : l’historique ne peut pas être réécrit.');
            }

            $acteur = User::find($validateurId);
            if ($acteur === null || ! $acteur->hasPermission('evaluations.update')) {
                throw new DomainException('Validation refusée : le validateur doit être habilité (evaluations.update).');
            }

            if ((int) $ligne->created_by === $validateurId) {
                throw new DomainException('Séparation des responsabilités : le déclarant ne peut pas valider sa propre résolution.');
            }

            $meta = $this->extraireMetadonneesIncident($ligne);

            // Le responsable de colonne peut différer du résolveur réel
            // (mesure d'urgence consignée par un tiers) : les deux sont
            // exclus de la validation.
            $responsables = array_filter([
                $ligne->responsable_id,
                $meta['resolution_finale']['responsable_id'] ?? null,
            ], fn ($id): bool => $id !== null);

            if (in_array($validateurId, array_map('intval', $responsables), true)) {
                throw new DomainException('Séparation des responsabilités : le responsable du traitement ne peut pas valider sa propre résolution.');
            }
            $meta['validation_finale'] = [
                'valide_par' => $validateurId,
                'commentaire' => $commentaire !== null ? trim($commentaire) : null,
                'valide_le' => Carbon::now()->toIso8601String(),
            ];

            $ligne->valide_par = $validateurId;
            $ligne->valide_at = Carbon::now();
            $ligne->description = json_encode($meta, JSON_UNESCAPED_UNICODE);
            $ligne->save();

            IntegrationLog::tracer(
                'evaluation',
                'internal',
                'incident.resolution_validated',
                'completed',
                null,
                200,
                null,
                null,
                (string) ($ligne->uuid ?? Str::uuid()),
                'EPR-'.$ligne->epreuve_id
            );

            EvaluationChanged::dispatch('incident.resolution_validee', $ligne->epreuve_id);

            return $ligne;
        });
    }

    /**
     * Désignation PRÉALABLE d'un mandat de suppléance d'urgence : périmètre
     * d'actions fermé, fenêtre bornée, révocable, jamais auto-attribué.
     *
     * @param array{
     *     epreuve_id: int,
     *     suppleant_id: int,
     *     titulaire_id: int,
     *     perimetre_autorise: string,
     *     actions?: array<int, string>,
     *     date_debut: string,
     *     date_fin: string,
     *     mandataire_nom: string,
     *     motif_suppleance: string
     * } $data
     */
    public function activerMandatSuppleance(array $data, int $auteurAutoriteId): DelegationUrgence
    {
        if ((int) $data['suppleant_id'] === (int) $data['titulaire_id']) {
            throw new DomainException('Le suppléant doit être une personne distincte du titulaire.');
        }

        if ((int) $data['suppleant_id'] === $auteurAutoriteId) {
            throw new DomainException(
                'Séparation des responsabilités : une autorité ne peut pas se désigner elle-même comme suppléant.'
            );
        }

        $motif = trim((string) ($data['motif_suppleance'] ?? ''));
        if (mb_strlen($motif) < 5) {
            throw new DomainException('Le motif de suppléance est obligatoire et doit être explicité (5 caractères minimum).');
        }

        foreach (['titulaire_id', 'suppleant_id'] as $acteur) {
            if (User::whereKey((int) $data[$acteur])->doesntExist()) {
                throw new DomainException("Acteur inconnu pour le mandat de suppléance ({$acteur}).");
            }
        }

        $actions = $this->resoudreActions($data);

        $debut = Carbon::parse($data['date_debut'])->startOfDay();
        $fin = Carbon::parse($data['date_fin'])->endOfDay();

        if ($fin->lessThanOrEqualTo($debut)) {
            throw new DomainException('La fenêtre de suppléance est invalide : la fin doit être postérieure au début.');
        }

        if ($debut->diffInDays($fin) > 7) {
            throw new DomainException('La suppléance d’urgence est bornée à 7 jours maximum : une délégation plus longue exige une décision institutionnelle.');
        }

        $epreuve = Epreuve::with('examen')->findOrFail($data['epreuve_id']);

        $this->exigerAutoriteHabilitee($auteurAutoriteId);

        return DB::transaction(function () use ($data, $auteurAutoriteId, $actions, $debut, $fin, $motif, $epreuve) {
            $mandat = DelegationUrgence::create([
                'delegant_id' => (int) $data['titulaire_id'],
                'delegue_id' => (int) $data['suppleant_id'],
                'faculte_id' => $epreuve->examen?->faculteId(),
                'session_id' => $epreuve->session_id,
                'actions' => $actions,
                'debut_at' => $debut,
                'fin_at' => $fin,
                'motif' => $motif,
                'statut' => DelegationUrgence::STATUT_ACTIVE,
                'created_by' => $auteurAutoriteId,
            ]);

            IntegrationLog::tracer(
                'evaluation',
                'internal',
                'governance.substitute.mandate_activated',
                'completed',
                null,
                200,
                null,
                null,
                $mandat->uuid,
                'EPR-'.$epreuve->id
            );

            EvaluationChanged::dispatch('gouvernance.mandat_suppleance_active', $epreuve->id);

            return $mandat;
        });
    }

    /**
     * Révocation explicite et motivée d'un mandat (par le délégant ou une
     * autorité habilitée, jamais par le suppléant lui-même).
     */
    public function revoquerMandat(DelegationUrgence $mandat, int $acteurId, string $motifRevocation): DelegationUrgence
    {
        $motif = trim($motifRevocation);
        if ($motif === '') {
            throw new DomainException('La révocation d’un mandat de suppléance exige un motif explicite.');
        }

        return DB::transaction(function () use ($mandat, $acteurId) {
            /** @var DelegationUrgence $verrouille */
            $verrouille = DelegationUrgence::whereKey($mandat->id)->lockForUpdate()->firstOrFail();

            if ((int) $verrouille->delegue_id === $acteurId) {
                throw new DomainException('Séparation des responsabilités : le suppléant ne peut pas révoquer son propre mandat.');
            }

            if ((int) $verrouille->delegant_id !== $acteurId) {
                $this->exigerAutoriteHabilitee($acteurId);
            }

            if ($verrouille->estRevoquee()) {
                throw new DomainException('Ce mandat de suppléance est déjà révoqué.');
            }

            $verrouille->update([
                'statut' => DelegationUrgence::STATUT_REVOQUEE,
                'revoquee_at' => now(),
                'revoquee_par' => $acteurId,
            ]);

            IntegrationLog::tracer(
                'evaluation',
                'internal',
                'governance.substitute.mandate_revoked',
                'completed',
                null,
                200,
                null,
                null,
                $verrouille->uuid,
                'EPR-'.$verrouille->session_id
            );

            return $verrouille;
        });
    }

    /**
     * Contrôle de conformité de l'action d'un suppléant : mandat actif,
     * fenêtre en vigueur, action DANS la liste fermée du mandat, et
     * interdiction absolue d'auto-validation.
     *
     * @param  array<string, mixed>  $contexte
     */
    public function verifierActionSuppleant(DelegationUrgence $mandat, int $userId, string $actionType, array $contexte): bool
    {
        /** @var DelegationUrgence $verrouille */
        $verrouille = DelegationUrgence::whereKey($mandat->id)->firstOrFail();

        if ($verrouille->estRevoquee()) {
            throw new DomainException("Le mandat de suppléance n'est plus actif : il a été révoqué.");
        }

        if (! $verrouille->estDansLaFenetre()) {
            throw new DomainException('Le mandat de suppléance est expiré ou non encore en vigueur.');
        }

        if ((int) $verrouille->delegue_id !== $userId) {
            throw new DomainException("L'utilisateur n'est pas le suppléant désigné par ce mandat.");
        }

        if (! in_array($actionType, DelegationUrgence::ACTIONS_AUTORISEES, true)) {
            throw new DomainException("Action d'urgence inconnue : « {$actionType} ».");
        }

        if (! $verrouille->actionAutorisee($actionType)) {
            throw new DomainException(
                "Action « {$actionType} » hors du périmètre du mandat : la suppléance ne dessaisit jamais le titulaire au-delà des actions explicitement déléguées."
            );
        }

        // Interdiction absolue d'auto-validation
        $acteurOriginal = (int) ($contexte['acteur_original_id'] ?? 0);
        if ($acteurOriginal !== 0 && $acteurOriginal === $userId) {
            throw new DomainException('Violation de séparation des responsabilités : un suppléant ne peut jamais valider sa propre proposition.');
        }

        IntegrationLog::tracer(
            'evaluation',
            'internal',
            'governance.substitute.action_verified',
            'completed',
            null,
            200,
            null,
            null,
            (string) Str::uuid(),
            $verrouille->uuid
        );

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function resoudreActions(array $data): array
    {
        $actions = $data['actions'] ?? null;

        if ($actions === null) {
            $perimetre = (string) ($data['perimetre_autorise'] ?? '');
            if (! array_key_exists($perimetre, DelegationUrgence::PERIMETRES)) {
                throw new DomainException("Périmètre de suppléance inconnu : « {$perimetre} ».");
            }
            $actions = DelegationUrgence::PERIMETRES[$perimetre];
        }

        if (! is_array($actions) || $actions === []) {
            throw new DomainException('Le mandat doit déléguer au moins une action d’urgence explicite.');
        }

        $actions = array_values(array_unique(array_map('strval', $actions)));

        foreach ($actions as $action) {
            if (! in_array($action, DelegationUrgence::ACTIONS_AUTORISEES, true)) {
                throw new DomainException("Action d'urgence non délégable : « {$action} ».");
            }
        }

        return $actions;
    }

    private function exigerAutoriteHabilitee(int $acteurId): void
    {
        $acteur = User::find($acteurId);

        if ($acteur === null || ! $acteur->hasPermission('evaluations.publish')) {
            throw new DomainException('Seule une autorité habilitée à publier peut désigner ou révoquer une suppléance d’urgence.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function extraireMetadonneesIncident(EvaluationIncident $incident): array
    {
        if (empty($incident->description)) {
            $meta = [
                'gravite' => 'mineure',
                'actions_prises' => [],
                'preuves_refs' => [],
            ];
        } else {
            $decode = json_decode($incident->description, true);

            $meta = is_array($decode) ? $decode : [
                'gravite' => 'mineure',
                'description_textuelle' => $incident->description,
                'actions_prises' => [],
                'preuves_refs' => [],
            ];
        }

        // Les colonnes qualifiantes font foi lorsqu'elles sont renseignées ;
        // le JSON reste lu pour les incidents historiques.
        if (! empty($incident->gravite)) {
            $meta['gravite'] = $incident->gravite;
        }
        if (! empty($incident->actions_prises)) {
            $meta['actions_prises'] = $incident->actions_prises;
        }
        if (! empty($incident->preuves)) {
            $meta['preuves_refs'] = $incident->preuves;
        }

        return $meta;
    }
}
