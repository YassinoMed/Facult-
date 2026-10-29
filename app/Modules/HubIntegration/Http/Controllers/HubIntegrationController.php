<?php

namespace App\Modules\HubIntegration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\IntegrationLog;
use App\Modules\HubIntegration\Models\AuditIdempotence;
use App\Modules\HubIntegration\Models\ContratConnecteur;
use App\Modules\HubIntegration\Models\RejeuIntegration;
use App\Modules\HubIntegration\Services\ServiceHub;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Lot 18 — Hub d'intégration : contrats versionnés par connecteur, audits
 * d'idempotence SAISIS, rejeu borné et autorisé-audité, séparation des
 * environnements de test.
 *
 * Contrôles (jamais le navigateur) :
 * - permissions RÉUTILISÉES : `import.view` (lecture), `import_template.manage`
 *   (contrats), `import.validate` (audits), `import.execute` (rejeu) ;
 * - `integration_logs` INSERT-ONLY : aucune route ne modifie le journal ;
 * - le registre d'échange et le pipeline existants ne sont PAS refondus
 *   (règle du cahier : extension additive uniquement).
 */
final class HubIntegrationController extends Controller
{
    public function __construct(private readonly ServiceHub $service) {}

    /**
     * Matrice honnête : contrat et audit de CHAQUE connecteur du registre,
     * l'absence étant TOUJOURS explicite (jamais conforme par défaut).
     */
    public function connecteurs(Request $requete): JsonResponse
    {
        $this->verifierPermission($requete, 'import.view');

        return response()->json($this->service->matrice());
    }

    public function contratCreer(Request $requete): JsonResponse
    {
        $this->verifierPermission($requete, 'import_template.manage');

        $donnees = $requete->validate([
            'connecteur' => ['required', 'string', 'max:100'],
            'version' => ['required', 'string', 'max:20'],
            'schema' => ['nullable', 'array'],
            'environnement' => ['nullable', 'string', Rule::in(ContratConnecteur::ENVIRONNEMENTS)],
            'changelog' => ['nullable', 'string', 'max:4000'],
        ]);

        try {
            $contrat = $this->service->declarerContrat($donnees, $requete->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['erreur' => $e->getMessage()], 422);
        }

        return response()->json(['contrat' => $contrat], 201);
    }

    public function contratActiver(Request $requete, int $id): JsonResponse
    {
        $this->verifierPermission($requete, 'import_template.manage');

        $contrat = ContratConnecteur::query()->findOrFail($id);

        try {
            $contrat = $this->service->activerContrat($contrat, (int) $requete->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['erreur' => $e->getMessage()], 422);
        }

        return response()->json(['contrat' => $contrat]);
    }

    public function contratDeprecier(Request $requete, int $id): JsonResponse
    {
        $this->verifierPermission($requete, 'import_template.manage');

        $contrat = ContratConnecteur::query()->findOrFail($id);

        try {
            $contrat = $this->service->deprecierContrat($contrat);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['erreur' => $e->getMessage()], 422);
        }

        return response()->json(['contrat' => $contrat]);
    }

    public function auditEnregistrer(Request $requete): JsonResponse
    {
        $this->verifierPermission($requete, 'import.validate');

        $donnees = $requete->validate([
            'connecteur' => ['required', 'string', 'max:100'],
            'verdict' => ['required', 'string', Rule::in(AuditIdempotence::VERDICTS)],
            'preuve' => ['required', 'string', 'min:10'], // preuve datée OBLIGATOIRE
        ]);

        try {
            $audit = $this->service->enregistrerAudit($donnees, $requete->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['erreur' => $e->getMessage()], 422);
        }

        return response()->json(['audit' => $audit], 201);
    }

    public function logsIndex(Request $requete): JsonResponse
    {
        $this->verifierPermission($requete, 'import.view');

        $donnees = $requete->validate([
            'status' => ['nullable', 'string', 'max:40'],
            'integration' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json([
            'logs' => $this->service->logs($donnees['status'] ?? null, $donnees['integration'] ?? null),
        ]);
    }

    public function rejeuAutoriser(Request $requete, int $id): JsonResponse
    {
        $this->verifierPermission($requete, 'import.execute');

        $log = IntegrationLog::query()->findOrFail($id);

        $donnees = $requete->validate([
            'motif' => ['required', 'string', 'min:10'], // autorisation motivée
        ]);

        try {
            $rejeu = $this->service->autoriserRejeu($log, $donnees['motif'], $requete->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['erreur' => $e->getMessage()], 422);
        }

        return response()->json(['rejeu' => $rejeu], 201);
    }

    public function rejeuExecuter(Request $requete, int $id): JsonResponse
    {
        $this->verifierPermission($requete, 'import.execute');

        $rejeu = RejeuIntegration::query()->findOrFail($id);

        try {
            $rejeu = $this->service->marquerRejeuExecute($rejeu);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['erreur' => $e->getMessage()], 422);
        }

        return response()->json(['rejeu' => $rejeu]);
    }

    private function verifierPermission(Request $requete, string $permission): void
    {
        abort_unless($requete->user()?->can($permission) === true, 403, "Permission « {$permission} » requise.");
    }
}
