<?php

namespace App\Modules\HubIntegration\Services;

use App\Models\IntegrationLog;
use App\Modules\HubIntegration\Models\AuditIdempotence;
use App\Modules\HubIntegration\Models\ContratConnecteur;
use App\Modules\HubIntegration\Models\RejeuIntegration;
use App\Services\DataExchange\DefinitionRegistry;
use Illuminate\Support\Collection;

/**
 * Lot 18 — Service du hub d'intégration (extension additive).
 *
 * RÈGLES DU CAHIER :
 * - AUCUNE REFONTE de l'existant : le registre et `integration_logs` sont
 *   CONSULTÉS, jamais modifiés (logs INSERT-ONLY) ;
 * - IDEMPOTENCE AUDITÉE connecteur par connecteur : verdict SAISI avec
 *   preuve — un connecteur sans audit est rapporté « non audité », jamais
 *   conforme par défaut (donnée absente ≠ conformité) ;
 * - RETRIES BORNÉS : plafond applicatif par échange, refus explicite au-delà ;
 * - SÉPARATION DES ENVIRONNEMENTS : un contrat sandbox n'est activable que
 *   si l'établissement a autorisé le bac à sable (config dédiée).
 */
final class ServiceHub
{
    public function __construct(private readonly DefinitionRegistry $registre) {}

    /**
     * Matrice honnête des connecteurs du registre : pour chacun, son contrat
     * ACTIF (ou l'état réel de ses contrats) et son DERNIER audit d'idempotence
     * (ou l'absence explicite d'audit).
     *
     * @return array{connecteurs: array<int, array<string, mixed>>, total: int}
     */
    public function matrice(): array
    {
        $cles = $this->registre::consultables();
        $contratsActifs = ContratConnecteur::query()
            ->whereIn('connecteur', $cles)
            ->where('statut', 'actif')
            ->get()
            ->keyBy('connecteur');
        $derniersContrats = ContratConnecteur::query()
            ->whereIn('connecteur', $cles)
            ->orderByDesc('id')
            ->get()
            ->groupBy('connecteur');
        $derniersAudits = AuditIdempotence::query()
            ->whereIn('connecteur', $cles)
            ->orderByDesc('audite_le')
            ->get()
            ->groupBy('connecteur');

        $lignes = [];
        foreach ($cles as $connecteur) {
            $actif = $contratsActifs->get($connecteur);
            $dernierContrat = $derniersContrats->has($connecteur)
                ? $derniersContrats->get($connecteur)->first()
                : null;
            $audit = $derniersAudits->has($connecteur)
                ? $derniersAudits->get($connecteur)->first()
                : null;

            $lignes[] = [
                'connecteur' => $connecteur,
                'contrat' => $actif?->only(['id', 'version', 'environnement', 'active_le']),
                'contrat_etat' => $actif !== null
                    ? 'actif'
                    : ($dernierContrat !== null ? $dernierContrat->statut : 'absent'),
                'audit_verdict' => $audit?->verdict,
                'audit_le' => $audit?->audite_le?->toIso8601String(),
                // Jamais de conformité par défaut : l'absence est EXPLICITE.
                'audit_etat' => $audit?->verdict ?? 'non_audite',
            ];
        }

        return ['connecteurs' => $lignes, 'total' => count($lignes)];
    }

    /**
     * Déclare un contrat versionné pour un connecteur du registre.
     *
     * @param  array<string, mixed>  $donnees
     */
    public function declarerContrat(array $donnees, ?int $auteurId): ContratConnecteur
    {
        if (! in_array($donnees['connecteur'], $this->registre::consultables(), true)) {
            throw new \InvalidArgumentException(
                'Connecteur « '.$donnees['connecteur'].' » inconnu du registre d\'échange.'
            );
        }

        if (ContratConnecteur::query()
            ->where('connecteur', $donnees['connecteur'])
            ->where('version', $donnees['version'])
            ->exists()) {
            throw new \InvalidArgumentException(
                'Le contrat « '.$donnees['connecteur'].' » version '.$donnees['version'].' existe déjà.'
            );
        }

        return ContratConnecteur::create([
            'connecteur' => $donnees['connecteur'],
            'version' => $donnees['version'],
            'schema' => $donnees['schema'] ?? null,
            'environnement' => $donnees['environnement'] ?? 'production',
            'changelog' => $donnees['changelog'] ?? null,
            'statut' => 'brouillon',
            'cree_par' => $auteurId,
        ]);
    }

    /**
     * Active un contrat brouillon (trace auteur + date). Garde sandbox :
     * un contrat de test n'est activable que si le bac à sable est autorisé.
     */
    public function activerContrat(ContratConnecteur $contrat, int $auteurId): ContratConnecteur
    {
        if ($contrat->statut !== 'brouillon') {
            throw new \InvalidArgumentException('Seul un contrat en brouillon peut être activé.');
        }

        if ($contrat->environnement === 'sandbox'
            && ! (bool) config('integrations.sandbox_autorise', false)) {
            throw new \InvalidArgumentException(
                'Contrat SANDBOX refusé : le bac à sable n\'est pas autorisé sur cet environnement.'
            );
        }

        $contrat->forceFill([
            'statut' => 'actif',
            'active_par' => $auteurId,
            'active_le' => now(),
        ])->save();

        return $contrat->refresh();
    }

    public function deprecierContrat(ContratConnecteur $contrat): ContratConnecteur
    {
        if ($contrat->statut !== 'actif') {
            throw new \InvalidArgumentException('Seul un contrat ACTIF peut être déprécié.');
        }

        $contrat->forceFill(['statut' => 'deprecie'])->save();

        return $contrat->refresh();
    }

    /**
     * Enregistre un verdict d'idempotence SAISI (jamais calculé).
     *
     * @param  array<string, mixed>  $donnees
     */
    public function enregistrerAudit(array $donnees, ?int $auteurId): AuditIdempotence
    {
        if (! in_array($donnees['connecteur'], $this->registre::consultables(), true)) {
            throw new \InvalidArgumentException(
                'Connecteur « '.$donnees['connecteur'].' » inconnu du registre d\'échange.'
            );
        }

        return AuditIdempotence::create([
            'connecteur' => $donnees['connecteur'],
            'verdict' => $donnees['verdict'],
            'preuve' => $donnees['preuve'],
            'audite_par' => $auteurId,
            'audite_le' => now(),
        ]);
    }

    /**
     * Autorise un rejeu BORNÉ d'un échange en échec.
     *
     * Garde : seuls les échanges non complétés sont rejouables ; le plafond
     * de tentatives est explicite ; chaque autorisation est motivée et
     * tracée. `integration_logs` n'est jamais modifié (INSERT-ONLY).
     */
    public function autoriserRejeu(IntegrationLog $log, string $motif, ?int $auteurId): RejeuIntegration
    {
        if ($log->status === 'completed') {
            throw new \InvalidArgumentException('Un échange complété ne peut pas être rejoué.');
        }

        $plafond = max(1, (int) config('integrations.plafond_rejeux', 3));
        $tentatives = RejeuIntegration::query()->where('integration_log_id', $log->id)->count();
        if ($tentatives >= $plafond) {
            throw new \InvalidArgumentException(
                'Rejeus épuisés pour cet échange ('.$tentatives.'/'.$plafond.') : borne explicite, jamais de boucle.'
            );
        }

        return RejeuIntegration::create([
            'integration_log_id' => $log->id,
            'tentative_numero' => $tentatives + 1,
            'motif' => $motif,
            'autorise_par' => $auteurId,
            'autorise_le' => now(),
            'statut' => 'autorise',
        ]);
    }

    /**
     * Marque un rejeu comme exécuté (la relance réelle reste à la charge du
     * connecteur ; le hub trace l'ordre ET son exécution).
     */
    public function marquerRejeuExecute(RejeuIntegration $rejeu): RejeuIntegration
    {
        if ($rejeu->statut !== 'autorise') {
            throw new \InvalidArgumentException('Ce rejeu n\'est pas en attente d\'exécution.');
        }

        $rejeu->forceFill(['statut' => 'execute'])->save();

        return $rejeu->refresh();
    }

    /**
     * Échanges du journal, filtrables (lecture seule).
     *
     * @return Collection<int, IntegrationLog>
     */
    public function logs(?string $statut, ?string $integration, int $limite = 200): Collection
    {
        return IntegrationLog::query()
            ->when($statut !== null, fn ($q) => $q->where('status', $statut))
            ->when($integration !== null, fn ($q) => $q->where('integration', $integration))
            ->orderByDesc('id')
            ->limit($limite)
            ->get();
    }
}
