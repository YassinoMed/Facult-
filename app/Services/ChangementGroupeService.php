<?php

namespace App\Services;

use App\Models\AffectationGroupe;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\OnlineRequests\Models\RequestType;
use App\Modules\OnlineRequests\Services\OnlineRequestService;
use App\Modules\Schedule\Models\Group;
use DomainException;

/**
 * LOOP B (automatisation §9) — Changement de groupe : opération métier
 * DISTINCTE de l'affectation initiale, couche workflow MINCE construite
 * sur les primitives prouvées (`AffectationService::affecter/retirer`,
 * verrous ordonnés étudiant→groupe, `controler` 10 contraintes).
 *
 * Invariants :
 * - L'ancienne affectation est CLÔTURÉE (historique conservé), jamais
 *   supprimée avant que la nouvelle soit sécurisée (transaction atomique
 *   de `affecter`).
 * - Idempotence : une demande identique répétée (cible = groupe courant)
 *   est un refus explicite, sans doublon d'historique ni notification.
 * - Éligibilité : AUCUNE nouvelle règle — les 10 contraintes existantes
 *   de `controler` (niveau, programme, faculté, capacité, actif…) font
 *   foi, re-vérifiées sous verrous dans la transaction.
 * - Simulation SANS écriture ; notification APRÈS commit ; audit sans
 *   PII (ids et codes de groupes uniquement).
 */
final class ChangementGroupeService
{
    public const CODE_TYPE = 'changement_groupe';

    private const STATUTS_FINAUX_DEMANDE = ['rejected', 'cancelled', 'completed'];

    public function __construct(
        private readonly AffectationService $affectations,
        private readonly NotificationService $notifications,
        private readonly AuditService $audit,
        private readonly OnlineRequestService $demandes,
    ) {}

    /**
     * Simulation du changement : aucun écriture, rapport d'impact.
     *
     * @return array{
     *     possible: bool,
     *     raisons: array<int, string>,
     *     impact_capacite: array{source: array<string, mixed>, cible: array<string, mixed>}|null,
     * }
     */
    public function simuler(Etudiant $etudiant, Group $cible, AnneeUniversitaire $annee): array
    {
        $active = $this->affectationActive($etudiant);

        if ($active === null) {
            return ['possible' => false, 'raisons' => ['Aucune affectation active à changer pour cet étudiant.'], 'impact_capacite' => null];
        }

        if ((int) $active->groupe_id === (int) $cible->id) {
            return ['possible' => false, 'raisons' => ["L'étudiant est déjà affecté au groupe cible ({$cible->code}) — demande identique ignorée."], 'impact_capacite' => null];
        }

        // Contraintes existantes (dry-run de `controler`, sans écriture) :
        // seules les contraintes BLOQUANTES refusent le changement — les
        // contraintes `info` (ex. « Transfert : l'affectation active sera
        // clôturée ») décrivent l'opération attendue. Les messages portent
        // les codes métier (ETUDIANT_SUSPENDU, GROUP_INACTIVE,
        // PROGRAM_MISMATCH, LEVEL_MISMATCH, GROUP_FULL…).
        $raisons = array_map(
            fn (array $contrainte) => ($contrainte['code'] ?? 'CONTRAINTE').' : '.($contrainte['message'] ?? ''),
            array_values(array_filter(
                $this->affectations->controler($etudiant, $cible, $annee),
                fn (array $contrainte) => ($contrainte['gravite'] ?? '') === 'erreur' || ($contrainte['bloquant'] ?? true) === true
            ))
        );

        $source = Group::find($active->groupe_id);

        return [
            'possible' => $raisons === [],
            'raisons' => $raisons,
            'impact_capacite' => [
                'source' => $this->impactCapacite($source),
                'cible' => $this->impactCapacite($cible),
            ],
        ];
    }

    /**
     * Changement de groupe (décision administrative, motif obligatoire).
     *
     * @return array{effectue: bool, raison: ?string, affectation?: AffectationGroupe}
     */
    public function changer(Etudiant $etudiant, Group $cible, AnneeUniversitaire $annee, ?int $auteurId, string $motif): array
    {
        $etudiant = $etudiant->fresh();
        $active = $this->affectationActive($etudiant);

        if ($active === null) {
            return ['effectue' => false, 'raison' => 'Aucune affectation active à changer pour cet étudiant.'];
        }

        if ((int) $active->groupe_id === (int) $cible->id) {
            return ['effectue' => false, 'raison' => "L'étudiant est déjà affecté au groupe cible ({$cible->code}) — demande identique ignorée."];
        }

        $sourceId = (int) $active->groupe_id;

        // Transfert atomique : `affecter` re-vérifie les 10 contraintes sous
        // verrous ordonnés (étudiant→groupe→affectation) et clôture l'ancienne
        // affectation dans la même transaction — jamais l'inverse. Un refus
        // (DomainException du moteur, message codé) remonte sans aucune
        // écriture : l'état reste intact.
        try {
            $affectation = $this->affectations->affecter($etudiant, $cible, $annee, $auteurId, $motif);
        } catch (DomainException $e) {
            return ['effectue' => false, 'raison' => $e->getMessage()];
        }

        // Audit sans PII : ids de groupes et codes (l'affectation historisée
        // par `affecter` trace déjà le transfert ; on ajoute la décision
        // de changement avec son motif).
        $this->audit->tracer(
            $etudiant,
            'affectation.changee',
            'etudiant',
            $etudiant->id,
            ['groupe_source_id' => $sourceId],
            ['groupe_cible_id' => $cible->id, 'motif' => $motif],
            'web'
        );

        // Notification après commit (pattern projet — jamais dans la
        // transaction), dédupliquée par affectation.
        $this->notifications->notifierEtudiant(
            $etudiant,
            'affectation.changee',
            'Changement de groupe',
            'Votre groupe a été changé (décision de la scolarité). Consultez votre espace.',
            route('etudiant.dashboard'),
            [],
            1,
            'affectation.changee:'.$affectation->id
        );

        return ['effectue' => true, 'raison' => null, 'affectation' => $affectation];
    }

    private function affectationActive(Etudiant $etudiant): ?AffectationGroupe
    {
        return AffectationGroupe::where('etudiant_id', $etudiant->id)
            ->where('statut', 'active')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Voie workflow (convergent) : demande instruite puis approuvée via les
     * demandes en ligne (référence serveur, transitions verrouillées, audit,
     * SLA), application par le moteur (transfert historisé). Complète
     * `changer()` (décision administrative directe) pour les cas exigeant
     * une approbation humaine tracée. Le type `changement_groupe` est
     * administré via le CRUD des types existant (champ requis :
     * `groupe_cible_id`) : aucun seed surprise.
     */

    /**
     * Crée (puis soumet) une demande de changement de groupe. Idempotent :
     * une demande non finale identique (même étudiant, même cible) est
     * retournée telle quelle, jamais dupliquée.
     *
     * @throws DomainException
     */
    public function demander(
        Etudiant $etudiant,
        Group $cible,
        AnneeUniversitaire $annee,
        ?string $motif = null,
        ?int $faculteRestreinteId = null,
    ): OnlineRequest {
        if ($faculteRestreinteId !== null && (int) $etudiant->faculte_id !== $faculteRestreinteId) {
            throw new DomainException('Demande impossible : étudiant hors de votre périmètre faculté.');
        }

        $type = RequestType::where('code', self::CODE_TYPE)->where('active', true)->first();

        if ($type === null) {
            throw new DomainException(
                'Type de demande « changement_groupe » introuvable ou inactif : créez-le via l\'administration des types (champ requis : groupe_cible_id).'
            );
        }

        $existante = OnlineRequest::where('etudiant_id', $etudiant->id)
            ->where('request_type_id', $type->id)
            ->whereNotIn('status', self::STATUTS_FINAUX_DEMANDE)
            ->latest('id')
            ->first();

        if ($existante !== null && (int) ($existante->form_data['groupe_cible_id'] ?? 0) === (int) $cible->id) {
            return $existante;
        }

        $demande = $this->demandes->creer($etudiant, [
            'request_type_id' => $type->id,
            'annee_universitaire_id' => $annee->id,
            'objet' => "Changement de groupe vers {$cible->code}",
            'description' => $motif,
            'form_data' => [
                'groupe_cible_id' => (string) $cible->id,
                'motif' => $motif,
            ],
        ]);

        if ((int) ($demande->form_data['groupe_cible_id'] ?? 0) !== (int) $cible->id) {
            throw new DomainException(
                'Type « changement_groupe » mal configuré : déclarez le champ requis « groupe_cible_id » dans son schéma.'
            );
        }

        return $this->demandes->soumettre($demande);
    }

    /**
     * Simulation d'une demande (dry-run) : contexte résolu puis `simuler()`.
     *
     * @return array{compatible: bool, bloquants: array<int, string>, infos: array<int, string>}
     */
    public function simulerDemande(OnlineRequest $demande): array
    {
        [$etudiant, $cible, $annee] = $this->contexteDemande($demande);

        $simulation = $this->simuler($etudiant, $cible, $annee);

        if (! $simulation['possible']) {
            return ['compatible' => false, 'bloquants' => $simulation['raisons'], 'infos' => []];
        }

        return ['compatible' => true, 'bloquants' => [], 'infos' => ['Transfert simulé : l\'affectation active sera clôturée.']];
    }

    /**
     * Approbation humaine (la demande doit être instruite jusqu'à
     * `in_review` via l'UI demandes existante). Re-simule avant
     * d'approuver (anti-TOCTOU) : cible devenue incompatible → refus
     * explicite, demande inchangée, rien appliqué.
     *
     * @throws DomainException
     */
    public function approuver(OnlineRequest $demande, int $auteurId): OnlineRequest
    {
        $demande = $demande->fresh();

        if ($demande === null || $demande->status !== 'in_review') {
            throw new DomainException('Approbation impossible : la demande doit être instruite (statut in_review).');
        }

        [$etudiant, $cible, $annee] = $this->contexteDemande($demande);
        $simulation = $this->simuler($etudiant, $cible, $annee);

        if (! $simulation['possible']) {
            throw new DomainException('Approbation refusée : '.implode(' ', $simulation['raisons']));
        }

        return $this->demandes->approuver($demande);
    }

    /**
     * Application transactionnelle après approbation : moteur (transfert
     * historisé, verrous, idempotence, notifications) puis clôture de la
     * demande. Réappelable après échec (statut `approved` conservé).
     *
     * @throws DomainException
     */
    public function appliquer(OnlineRequest $demande, int $auteurId, bool $force = false, ?int $faculteRestreinteId = null): OnlineRequest
    {
        $demande = $demande->fresh();

        if ($demande === null || $demande->status !== 'approved') {
            throw new DomainException('Application impossible : la demande doit être approuvée (statut approved).');
        }

        [$etudiant, $cible, $annee] = $this->contexteDemande($demande);

        if ($faculteRestreinteId !== null && (int) $etudiant->faculte_id !== $faculteRestreinteId) {
            throw new DomainException('Application impossible : étudiant hors de votre périmètre faculté.');
        }

        $motif = $demande->form_data['motif'] ?? "Changement de groupe (demande {$demande->reference}).";

        $this->affectations->affecter($etudiant, $cible, $annee, $auteurId, (string) $motif, $force);

        return $this->demandes->terminer($demande->fresh());
    }

    /**
     * @return array{0: Etudiant, 1: Group, 2: AnneeUniversitaire}
     *
     * @throws DomainException
     */
    private function contexteDemande(OnlineRequest $demande): array
    {
        $demande->loadMissing('etudiant');

        $cibleId = (int) ($demande->form_data['groupe_cible_id'] ?? 0);
        $cible = $cibleId > 0 ? Group::find($cibleId) : null;

        if ($cible === null) {
            throw new DomainException('Demande inexploitable : groupe cible introuvable.');
        }

        $annee = $demande->annee_universitaire_id !== null
            ? AnneeUniversitaire::find($demande->annee_universitaire_id)
            : null;

        if ($annee === null) {
            throw new DomainException('Demande inexploitable : année universitaire manquante.');
        }

        return [$demande->etudiant, $cible, $annee];
    }

    /**
     * Impact capacité d'un groupe (effectif actif vs capacité).
     *
     * @return array<string, mixed>
     */
    private function impactCapacite(?Group $groupe): array
    {
        if ($groupe === null) {
            return ['groupe_id' => null, 'code' => null, 'effectif_actif' => null, 'capacite' => null];
        }

        $effectif = AffectationGroupe::where('groupe_id', $groupe->id)->where('statut', 'active')->count();

        return [
            'groupe_id' => $groupe->id,
            'code' => $groupe->code,
            'effectif_actif' => $effectif,
            'capacite' => $groupe->capacite,
        ];
    }
}
