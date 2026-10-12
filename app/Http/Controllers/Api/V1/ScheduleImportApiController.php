<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Schedule\Import\ImportResult;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\ScheduleImport;
use App\Modules\Schedule\Services\ScheduleImportService;
use App\Services\Io\IoException;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * API d'import des emplois du temps (§13-17) : stateless (fichier
 * re-transmis à la confirmation — aucune session), dry-run intégré,
 * transaction unique, scope dédié + throttle.
 */
class ScheduleImportApiController extends Controller
{
    public function __construct(
        private readonly ScheduleImportService $imports,
    ) {}

    /**
     * Analyse (+ dry-run) : parse, normalise, valide, conflits — sans
     * jamais écrire. `dry_run=true` (défaut) ou import direct avec
     * `mode` + `confirmation` explicite.
     */
    public function preview(Request $request): JsonResponse
    {
        $valide = $request->validate([
            'fichier' => 'required|file|max:10240|mimes:csv,json,xml,xlsx',
            'dry_run' => 'sometimes|boolean',
        ]);

        try {
            ['format' => $format, 'resultat' => $resultat] = $this->imports->analyser(
                $request->file('fichier'), []
            );
        } catch (IoException|DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'valid' => ! $resultat->bloque(),
            'format' => $format,
            'total_rows' => $resultat->total(),
            'valid_rows' => count($resultat->valides),
            'warnings' => count($resultat->avertissements),
            'errors' => count($resultat->erreurs),
            'conflicts' => $this->conflits($resultat),
            'detail_erreurs' => array_slice($resultat->erreurs, 0, 50),
        ], 200);
    }

    /**
     * Confirmation : fichier re-transmis + re-validé (anti-TOCTOU),
     * remplacement soumis à `confirmation: true`.
     */
    public function confirmer(Request $request): JsonResponse
    {
        $valide = $request->validate([
            'fichier' => 'required|file|max:10240|mimes:csv,json,xml,xlsx',
            'mode' => 'required|in:creer,remplacer',
            'confirmation' => 'exclude_unless:mode,remplacer|required|accepted',
        ]);

        try {
            ['normalise' => $normalise, 'resultat' => $resultat] = $this->imports->analyser(
                $request->file('fichier'), []
            );

            if ($resultat->bloque()) {
                return response()->json([
                    'message' => 'Le fichier contient des erreurs bloquantes.',
                    'detail_erreurs' => array_slice($resultat->erreurs, 0, 50),
                ], 422);
            }

            $this->verifierPerimetre($request, $normalise['entete']);

            [$planning, $importes] = DB::transaction(fn () => $this->imports->importerLignes(
                $normalise['entete'],
                $resultat->valides,
                $valide['mode'],
                (int) $request->user()->id,
                (bool) ($valide['confirmation'] ?? false),
                'API — '.$request->file('fichier')->getClientOriginalName(),
            ));

            $rapport = ScheduleImport::create([
                'schedule_id' => $planning->id,
                'imported_by' => $request->user()->id,
                'filename' => mb_substr($request->file('fichier')->getClientOriginalName(), 0, 255),
                'format' => $this->imports->detecterFormatPublique($request->file('fichier')),
                'integration' => 'api',
                'status' => 'completed',
                'total_rows' => $resultat->total(),
                'successful_rows' => $importes,
            ]);

            $this->imports->apresImport($planning->id, $valide['mode']);
        } catch (IoException|DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Import terminé.',
            'planning_id' => $planning->id,
            'rapport_id' => $rapport->id,
            'importes' => $importes,
        ], 200);
    }

    /**
     * @return array<int, string>
     */
    private function conflits(ImportResult $resultat): array
    {
        $conflits = [];
        foreach ($resultat->erreurs as $erreur) {
            if ($erreur['champ'] === 'conflit') {
                $conflits[] = "Ligne {$erreur['ligne']} : {$erreur['message']}";
            }
        }

        return array_slice($conflits, 0, 50);
    }

    /**
     * @param  array<string, mixed>  $entete
     */
    private function verifierPerimetre(Request $request, array $entete): void
    {
        $groupe = Group::where('code', $entete['group'] ?? '')->first();

        if ($groupe !== null && ! $request->user()->peutAccederFaculte((int) $groupe->faculte_id)) {
            throw IoException::malforme('groupe hors de votre périmètre.');
        }
    }
}
