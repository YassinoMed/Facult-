<?php

namespace App\Modules\GouvernanceConformite\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\GouvernanceConformite\Models\JournalAccesJournal;
use App\Modules\GouvernanceConformite\Models\PolitiqueRetention;
use App\Modules\GouvernanceConformite\Services\ServiceGouvernance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Lot 19 — Centre de gouvernance et conformité.
 *
 * Vues : accès sensibles UNIFIÉS (journaux réels), journal des changements
 * de permissions, conformité des rétentions (politiques APPROUVÉES,
 * aucune suppression), journal des accès aux journaux (proportionné).
 *
 * Contrôles (jamais le navigateur) :
 * - lecture : `gouvernance.audit` (admin_scolarite, super_admin, rôle
 *   `auditeur` DISTINCT — lecture seule, aucun pouvoir d'action) ;
 * - politiques de rétention : `settings.manage` (approbation tracée) ;
 * - chaque consultation d'une vue est journalisée (le FAIT, jamais le
 *   contenu lu — réserve éthique du cahier).
 */
final class GouvernanceConformiteController extends Controller
{
    public function __construct(private readonly ServiceGouvernance $service) {}

    public function accesSensibles(Request $requete): JsonResponse
    {
        $this->verifierPermission($requete, 'gouvernance.audit');

        $donnees = $requete->validate([
            'source' => ['nullable', 'string', Rule::in(ServiceGouvernance::JOURNAUX)],
        ]);

        $source = $donnees['source'] ?? null;
        $this->service->tracerAcces((int) $requete->user()?->id, 'acces-sensibles', $source);

        return response()->json($this->service->accesSensibles($source));
    }

    public function changementsPermissions(Request $requete): JsonResponse
    {
        $this->verifierPermission($requete, 'gouvernance.audit');

        $this->service->tracerAcces((int) $requete->user()?->id, 'permissions-journal', null);

        return response()->json([
            'changements' => $this->service->changementsPermissions(),
        ]);
    }

    public function conformiteRetention(Request $requete): JsonResponse
    {
        $this->verifierPermission($requete, 'gouvernance.audit');

        $this->service->tracerAcces((int) $requete->user()?->id, 'conformite-retention', null);

        return response()->json([
            'journaux' => $this->service->rapportRetention(),
        ]);
    }

    public function politiqueCreer(Request $requete): JsonResponse
    {
        $this->verifierPermission($requete, 'settings.manage');

        $donnees = $requete->validate([
            'source_journal' => ['required', 'string', Rule::in(ServiceGouvernance::JOURNAUX)],
            'duree_jours' => ['required', 'integer', 'min:1', 'max:36500'],
            'motif' => ['nullable', 'string', 'max:4000'],
        ]);

        if (PolitiqueRetention::query()->where('source_journal', $donnees['source_journal'])->exists()) {
            return response()->json([
                'erreur' => 'Une politique existe déjà pour ce journal (UNE politique par journal, jamais de double).',
            ], 422);
        }

        $politique = PolitiqueRetention::create([
            'source_journal' => $donnees['source_journal'],
            'duree_jours' => $donnees['duree_jours'],
            'motif' => $donnees['motif'] ?? null,
            'statut' => 'brouillon',
        ]);

        return response()->json(['politique' => $politique], 201);
    }

    public function politiqueApprouver(Request $requete, int $id): JsonResponse
    {
        $this->verifierPermission($requete, 'settings.manage');

        $politique = PolitiqueRetention::query()->findOrFail($id);

        if ($politique->statut !== 'brouillon') {
            return response()->json(['erreur' => 'Seule une politique en brouillon peut être approuvée.'], 422);
        }

        $politique->forceFill([
            'statut' => 'approuvee',
            'approuve_par' => $requete->user()?->id,
            'approuve_le' => now(),
        ])->save();

        return response()->json(['politique' => $politique->refresh()]);
    }

    public function journalAcces(Request $requete): JsonResponse
    {
        $this->verifierPermission($requete, 'gouvernance.audit');

        $this->service->tracerAcces((int) $requete->user()?->id, 'journal-acces', null);

        return response()->json([
            'acces' => JournalAccesJournal::query()->orderByDesc('id')->limit(200)->get(),
            'rappel' => 'Proportionnalité : seul le FAIT de consulter est tracé, jamais le contenu lu.',
        ]);
    }

    private function verifierPermission(Request $requete, string $permission): void
    {
        abort_unless($requete->user()?->can($permission) === true, 403, "Permission « {$permission} » requise.");
    }
}
