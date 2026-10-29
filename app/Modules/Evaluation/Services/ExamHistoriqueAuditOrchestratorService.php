<?php

namespace App\Modules\Evaluation\Services;

use App\Models\IntegrationLog;
use App\Modules\Evaluation\Models\DocumentExamenGenere;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Service d'orchestration pour le Menu 12 (Historique & Audit des Examens).
 *
 * Responsabilités :
 * - Consolidation multi-sources de la traçabilité des examens (§55)
 * - Traçabilité sans PII (uniquement empreintes SHA-256 et métadonnées techniques)
 * - Observabilité des opérations d'intégration, durées d'exécution et documents certifiés
 * - Filtres sécurisés sur les colonnes réelles de `integration_logs`
 */
class ExamHistoriqueAuditOrchestratorService
{
    /**
     * Audite l'historique global des examens avec filtres serveur et KPI.
     *
     * @return array{
     *     traces: LengthAwarePaginator,
     *     statsHistorique: array<string, int|float>,
     *     derniersDocuments: Collection<int, DocumentExamenGenere>,
     * }
     */
    public function auditerHistorique(
        ?int $anneeId = null,
        ?int $faculteId = null,
        ?string $statutFiltre = null,
        ?string $directionFiltre = null,
        ?string $recherche = null,
        ?string $dateDebut = null,
        ?string $dateFin = null,
        int $perPage = 25
    ): array {
        // Query de base sur les traces d'intégration d'examens et évaluations
        $baseQuery = IntegrationLog::query()
            ->where(function ($q) {
                $q->whereIn('direction', ['export', 'pdf_generation'])
                    ->orWhere('operation', 'like', '%evaluation%')
                    ->orWhere('operation', 'like', '%examen%');
            });

        // Application des filtres serveur
        if ($statutFiltre !== null && $statutFiltre !== '') {
            $baseQuery->where('status', $statutFiltre);
        }

        if ($directionFiltre !== null && $directionFiltre !== '') {
            $baseQuery->where('direction', $directionFiltre);
        }

        if ($dateDebut !== null && $dateDebut !== '') {
            $baseQuery->whereDate('created_at', '>=', $dateDebut);
        }

        if ($dateFin !== null && $dateFin !== '') {
            $baseQuery->whereDate('created_at', '<=', $dateFin);
        }

        if ($recherche !== null && $recherche !== '') {
            $baseQuery->where(function ($q) use ($recherche) {
                $q->where('operation', 'like', "%{$recherche}%")
                    ->orWhere('external_reference', 'like', "%{$recherche}%")
                    ->orWhere('integration', 'like', "%{$recherche}%");
            });
        }

        // Pagination
        $traces = (clone $baseQuery)->latest('id')->paginate($perPage)->withQueryString();

        // Calcul des métriques d'exploitation
        $totalTraces = (clone $baseQuery)->count();
        $operationsReussies = (clone $baseQuery)->where('status', 'completed')->count();
        $operationsEchouees = (clone $baseQuery)->where('status', 'failed')->count();
        $tauxSucces = $totalTraces > 0 ? round(($operationsReussies / $totalTraces) * 100, 1) : 100.0;
        $dureeMoyenne = (clone $baseQuery)->whereNotNull('duration_ms')->avg('duration_ms');

        // Récupération des documents certifiés générés
        $documentsGeneresQuery = DocumentExamenGenere::query();
        if ($faculteId !== null) {
            $documentsGeneresQuery->whereHas('epreuve', fn ($q) => $q->pourFaculteId($faculteId));
        }

        $totalDocumentsGeneres = (clone $documentsGeneresQuery)->count();
        $derniersDocuments = (clone $documentsGeneresQuery)
            ->with(['epreuve.matiere', 'salle', 'generePar'])
            ->latest('id')
            ->limit(8)
            ->get();

        $statsHistorique = [
            'total_traces' => $totalTraces,
            'operations_reussies' => $operationsReussies,
            'operations_echouees' => $operationsEchouees,
            'taux_succes' => $tauxSucces,
            'duree_moyenne_ms' => $dureeMoyenne !== null ? round((float) $dureeMoyenne, 1) : 0.0,
            'documents_generes' => $totalDocumentsGeneres,
        ];

        return [
            'traces' => $traces,
            'statsHistorique' => $statsHistorique,
            'derniersDocuments' => $derniersDocuments,
        ];
    }

    /**
     * Vérifie la conformité stricte §55 et RGPD (zéro PII dans les traces).
     */
    public function verifierConformiteRgpdZeroPii(IntegrationLog|string $traceOuPayload): bool
    {
        $chaine = is_string($traceOuPayload)
            ? $traceOuPayload
            : json_encode([
                $traceOuPayload->operation,
                $traceOuPayload->external_reference,
                $traceOuPayload->integration,
                $traceOuPayload->error_code,
            ]);

        // Détection d'adresses email nominatives en clair
        if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', (string) $chaine)) {
            return false;
        }

        // Vérification de l'empreinte si modèle fourni
        if ($traceOuPayload instanceof IntegrationLog && $traceOuPayload->payload_hash !== null) {
            // Un hash SHA-256 valide doit être exactement 64 caractères hexadécimaux
            if (! preg_match('/^[a-f0-9]{64}$/i', $traceOuPayload->payload_hash)) {
                return false;
            }
        }

        return true;
    }
}
