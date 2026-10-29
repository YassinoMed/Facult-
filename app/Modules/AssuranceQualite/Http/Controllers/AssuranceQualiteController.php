<?php

namespace App\Modules\AssuranceQualite\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\AssuranceQualite\Models\ConstatAccreditation;
use App\Modules\AssuranceQualite\Models\CritereAccreditation;
use App\Modules\AssuranceQualite\Models\PlanActionAccreditation;
use App\Modules\AssuranceQualite\Models\PreuveConstatAccreditation;
use App\Modules\AssuranceQualite\Models\RapportAccreditation;
use App\Modules\AssuranceQualite\Models\ReferentielAccreditation;
use App\Modules\AssuranceQualite\Models\VersionReferentielAccreditation;
use App\Modules\AssuranceQualite\Services\ServiceAccreditation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Lot 16 — Assurance qualité et accréditation.
 *
 * Chaîne du cahier : catalogue versionné (référentiels/critères SAISIS,
 * jamais seedés) → constats humains → preuves typées vers artefacts RÉELS →
 * plans d'action → rapport (complétude EXIGÉE, totaux FIGÉS) → approbation
 * (approbateur ≠ soumetteur) → export sous permissions.
 *
 * Contrôles (jamais le navigateur) :
 * - permissions RÉUTILISÉES : `qualite.view` (lecture), `qualite.manage`
 *   (gestion), `export.create` (export) — AUCUNE clé nouvelle ;
 * - périmètre facultaire : primitives `User::peutAccederFaculte` /
 *   `User::faculteRestreinteId` (même règle que Lots 14/15) ;
 * - interdits du cahier : inventer des critères (catalogue vide),
 *   présenter un rapport incomplet comme conforme (complétude + totaux).
 */
final class AssuranceQualiteController extends Controller
{
    public function __construct(private readonly ServiceAccreditation $service) {}

    /* ── Catalogue versionné ─────────────────────────────────── */

    public function referentielsIndex(Request $requete): JsonResponse
    {
        $this->verifierPermission($requete, 'qualite.view');

        $referentiels = ReferentielAccreditation::query()
            ->withCount('versions')
            ->orderBy('code')
            ->get(['id', 'code', 'nom', 'organisme', 'description', 'created_at']);

        return response()->json(['referentiels' => $referentiels]);
    }

    public function referentielCreer(Request $requete): JsonResponse
    {
        $this->verifierPermission($requete, 'qualite.manage');

        $donnees = $requete->validate([
            'code' => ['required', 'string', 'max:60', 'unique:referentiels_accreditation,code'],
            'nom' => ['required', 'string', 'max:150'],
            'organisme' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:4000'],
        ]);

        $referentiel = ReferentielAccreditation::create([
            ...$donnees,
            'description' => $donnees['description'] ?? null,
            'cree_par' => $requete->user()?->id,
        ]);

        return response()->json(['referentiel' => $referentiel], 201);
    }

    public function versionCreer(Request $requete, int $id): JsonResponse
    {
        $this->verifierPermission($requete, 'qualite.manage');

        $referentiel = ReferentielAccreditation::query()->findOrFail($id);
        $numero = (int) $referentiel->versions()->max('numero') + 1;

        $version = VersionReferentielAccreditation::create([
            'referentiel_id' => $referentiel->id,
            'numero' => $numero,
            'statut' => 'brouillon',
            'cree_par' => $requete->user()?->id,
        ]);

        return response()->json(['version' => $version], 201);
    }

    public function versionDetail(Request $requete, int $id): JsonResponse
    {
        $this->verifierPermission($requete, 'qualite.view');

        $version = VersionReferentielAccreditation::query()->with('referentiel:id,code,nom,organisme')->findOrFail($id);

        $criteres = $version->criteres()
            ->orderBy('code')
            ->get(['id', 'code', 'libelle', 'description', 'faculte_id']);

        return response()->json([
            'version' => $version,
            'criteres' => $criteres,
            'interdit_rappel' => 'Les critères sont SAISIS par l\'administration (aucun critère inventé par le système).',
        ]);
    }

    public function critereCreer(Request $requete, int $id): JsonResponse
    {
        $this->verifierPermission($requete, 'qualite.manage');

        $version = VersionReferentielAccreditation::query()->findOrFail($id);

        if ($version->estFigee()) {
            return response()->json([
                'erreur' => 'La version est FIGÉE ('.$version->statut.') : aucun critère ne peut plus être ajouté.',
            ], 422);
        }

        $donnees = $requete->validate([
            'code' => ['required', 'string', 'max:60'],
            'libelle' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:4000'],
            'faculte_id' => ['nullable', 'integer', 'exists:facultes,id'],
        ]);

        if (($donnees['faculte_id'] ?? null) !== null && ! $requete->user()?->peutAccederFaculte((int) $donnees['faculte_id'])) {
            abort(403, 'Assurance qualité : faculté hors périmètre.');
        }

        // UNIQUE (version, code) : jamais d'écrasement silencieux.
        if ($version->criteres()->where('code', $donnees['code'])->exists()) {
            return response()->json(['erreur' => 'Ce code de critère existe déjà dans la version.'], 422);
        }

        $critere = CritereAccreditation::create([
            'version_referentiel_id' => $version->id,
            'code' => $donnees['code'],
            'libelle' => $donnees['libelle'],
            'description' => $donnees['description'] ?? null,
            'faculte_id' => $donnees['faculte_id'] ?? null,
        ]);

        return response()->json(['critere' => $critere], 201);
    }

    public function versionApprouver(Request $requete, int $id): JsonResponse
    {
        $this->verifierPermission($requete, 'qualite.manage');

        $version = VersionReferentielAccreditation::query()->findOrFail($id);

        if ($version->statut !== 'brouillon') {
            return response()->json(['erreur' => 'Seule une version en brouillon peut être approuvée.'], 422);
        }

        $donnees = $requete->validate([
            'motif_figage' => ['required', 'string', 'min:10'],
        ]);

        $version->forceFill([
            'statut' => 'approuvee',
            'motif_figage' => $donnees['motif_figage'],
            'approuve_par' => $requete->user()?->id,
            'approuve_le' => now(),
        ])->save();

        return response()->json(['version' => $version->refresh()]);
    }

    public function versionArchiver(Request $requete, int $id): JsonResponse
    {
        $this->verifierPermission($requete, 'qualite.manage');

        $version = VersionReferentielAccreditation::query()->findOrFail($id);

        if ($version->statut !== 'approuvee') {
            return response()->json(['erreur' => 'Seule une version approuvée peut être archivée.'], 422);
        }

        $version->forceFill(['statut' => 'archivee'])->save();

        return response()->json(['version' => $version->refresh()]);
    }

    /* ── Chaîne constat → preuve → plan d'action ──────────────── */

    public function constatEnregistrer(Request $requete, int $id): JsonResponse
    {
        $this->verifierPermission($requete, 'qualite.manage');

        $critere = CritereAccreditation::query()->with('version')->findOrFail($id);

        // Une version archivée n'est plus évaluable (brouillon/approuvée : oui).
        if ($critere->version->statut === 'archivee') {
            return response()->json(['erreur' => 'La version est archivée : plus évaluable.'], 422);
        }

        $donnees = $requete->validate([
            'faculte_id' => ['nullable', 'integer', 'exists:facultes,id'],
            'evaluation' => ['required', 'string', Rule::in(ConstatAccreditation::EVALUATIONS)],
            'commentaire' => ['nullable', 'string', 'max:4000'],
        ]);

        $faculteId = $donnees['faculte_id'] ?? null;

        if ($faculteId !== null && ! $requete->user()?->peutAccederFaculte((int) $faculteId)) {
            abort(403, 'Assurance qualité : faculté hors périmètre.');
        }

        // Cohérence : un critère à portée faculté F n'est évalué que sur F.
        if ($critere->faculte_id !== null && (int) ($faculteId ?? 0) !== (int) $critere->faculte_id) {
            $message = 'Ce critère est à portée de la faculté #'.$critere->faculte_id.'.';

            return response()->json(['erreur' => $message], 422);
        }

        $perimetre = ConstatAccreditation::perimetrePour($faculteId !== null ? (int) $faculteId : null);

        $constat = ConstatAccreditation::updateOrCreate(
            ['critere_id' => $critere->id, 'perimetre' => $perimetre],
            [
                'faculte_id' => $faculteId,
                'evaluation' => $donnees['evaluation'],
                'commentaire' => $donnees['commentaire'] ?? null,
                'evaluateur_id' => $requete->user()?->id,
                'evalue_le' => now(),
            ]
        );

        return response()->json(['constat' => $constat->refresh()]);
    }

    public function preuveAjouter(Request $requete, int $id): JsonResponse
    {
        $this->verifierPermission($requete, 'qualite.manage');

        $constat = ConstatAccreditation::query()->findOrFail($id);

        $donnees = $requete->validate([
            'type' => ['required', 'string', Rule::in($this->service->typesPreuve())],
            'cible_id' => ['required', 'integer'],
        ]);

        // Existence RÉELLE de l'artefact : jamais de lien mort accepté.
        $libelle = $this->service->resoudreCiblePreuve($donnees['type'], (int) $donnees['cible_id']);
        if ($libelle === null) {
            return response()->json([
                'erreur' => 'La cible de preuve n\'existe pas (type « '.$donnees['type'].' », id '.$donnees['cible_id'].').',
            ], 422);
        }

        // Idempotent : UNIQUE (constat, type, cible) — re-lier la même
        // preuve retourne l'existant au lieu de créer un doublon.
        $preuve = PreuveConstatAccreditation::firstOrCreate(
            ['constat_id' => $constat->id, 'type' => $donnees['type'], 'cible_id' => (int) $donnees['cible_id']],
            ['cible_libelle' => $libelle, 'ajoute_par' => $requete->user()?->id],
        );

        return response()->json(['preuve' => $preuve], 201);
    }

    public function planActionCreer(Request $requete, int $id): JsonResponse
    {
        $this->verifierPermission($requete, 'qualite.manage');

        $constat = ConstatAccreditation::query()->findOrFail($id);

        $donnees = $requete->validate([
            'mesure' => ['required', 'string', 'max:4000'],
            'responsable_id' => ['nullable', 'integer', 'exists:users,id'],
            'echeance' => ['nullable', 'date'],
        ]);

        $plan = PlanActionAccreditation::create([
            'constat_id' => $constat->id,
            'mesure' => $donnees['mesure'],
            'responsable_id' => $donnees['responsable_id'] ?? null,
            'echeance' => $donnees['echeance'] ?? null,
            'statut' => 'ouvert',
        ]);

        return response()->json(['plan' => $plan], 201);
    }

    public function planActionCloturer(Request $requete, int $id): JsonResponse
    {
        $this->verifierPermission($requete, 'qualite.manage');

        $plan = PlanActionAccreditation::query()->findOrFail($id);

        if ($plan->statut !== 'ouvert') {
            return response()->json(['erreur' => 'Ce plan d\'action est déjà clôturé.'], 422);
        }

        $plan->forceFill([
            'statut' => 'termine',
            'cloture_par' => $requete->user()?->id,
            'cloture_le' => now(),
        ])->save();

        return response()->json(['plan' => $plan->refresh()]);
    }

    /* ── Rapport : soumission, approbation, détail, export ───── */

    public function rapportSoumettre(Request $requete): JsonResponse
    {
        $this->verifierPermission($requete, 'qualite.manage');

        $donnees = $requete->validate([
            'version_referentiel_id' => ['required', 'integer', 'exists:versions_referentiel_accreditation,id'],
            'faculte_id' => ['nullable', 'integer', 'exists:facultes,id'],
        ]);

        $faculteId = $donnees['faculte_id'] ?? null;

        if ($faculteId !== null && ! $requete->user()?->peutAccederFaculte((int) $faculteId)) {
            abort(403, 'Assurance qualité : faculté hors périmètre.');
        }

        $version = VersionReferentielAccreditation::query()->findOrFail($donnees['version_referentiel_id']);

        if ($version->statut !== 'approuvee') {
            return response()->json(['erreur' => 'Seule une version APPROUVÉE (figée) peut être rapportée.'], 422);
        }

        $perimetre = RapportAccreditation::perimetrePour($faculteId);
        $rapport = RapportAccreditation::query()
            ->where('version_referentiel_id', $version->id)
            ->where('perimetre', $perimetre)
            ->first();

        if ($rapport !== null && $rapport->statut === 'approuve') {
            return response()->json(['erreur' => 'Ce rapport est déjà APPROUVÉ : il ne peut plus être soumis.'], 422);
        }

        $rapport ??= new RapportAccreditation([
            'version_referentiel_id' => $version->id,
            'perimetre' => $perimetre,
            'faculte_id' => $faculteId,
        ]);

        try {
            $rapport->soumis_par = $requete->user()?->id;
            $rapport = $this->service->soumettre($rapport);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['erreur' => $e->getMessage()], 422);
        }

        return response()->json(['rapport' => $rapport->refresh()]);
    }

    public function rapportsIndex(Request $requete): JsonResponse
    {
        $this->verifierPermission($requete, 'qualite.view');

        $rapports = $this->rapportsVisibles($requete)
            ->with('version.referentiel:id,code,nom')
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'version_referentiel_id', 'faculte_id', 'statut', 'perimetre',
                'soumis_le', 'soumis_par', 'approuve_le', 'approuve_par',
                'total_criteres', 'total_conformes', 'total_partiels',
                'total_non_conformes', 'total_non_evalues']);

        return response()->json(['rapports' => $rapports]);
    }

    public function rapportDetail(Request $requete, int $id): JsonResponse
    {
        $this->verifierPermission($requete, 'qualite.view');

        $rapport = $this->rapportsVisibles($requete)->with('version.referentiel:id,code,nom,organisme')->findOrFail($id);

        $criteres = $this->service->criteresDuPerimetre($rapport->version, $rapport->faculte_id);
        $constats = ConstatAccreditation::query()
            ->where('perimetre', $rapport->perimetre)
            ->whereIn('critere_id', $criteres->pluck('id'))
            ->with('preuves:id,constat_id,type,cible_id,cible_libelle', 'plansAction:id,constat_id,mesure,statut,echeance')
            ->get()
            ->keyBy('critere_id');

        return response()->json([
            'rapport' => $rapport,
            'lignes' => $criteres->map(fn ($c) => [
                'code' => $c->code,
                'libelle' => $c->libelle,
                'evaluation' => $constats->get($c->id)?->evaluation,
                'constat_id' => $constats->get($c->id)?->id,
            ])->values(),
            'rappel' => 'Un critère `non_evalue` est compté NON ÉVALUÉ — jamais comme conforme.',
        ]);
    }

    public function rapportApprouver(Request $requete, int $id): JsonResponse
    {
        $this->verifierPermission($requete, 'qualite.manage');

        $rapport = RapportAccreditation::query()->findOrFail($id);

        if ($rapport->statut !== 'soumis') {
            return response()->json(['erreur' => 'Seul un rapport SOUMIS peut être approuvé.'], 422);
        }

        // Séparation des pouvoirs : le soumetteur n'approuve jamais son rapport.
        if ((int) $rapport->soumis_par === (int) $requete->user()?->id) {
            return response()->json(['erreur' => 'Le soumetteur ne peut pas approuver son propre rapport.'], 422);
        }

        $donnees = $requete->validate([
            'commentaire_approbation' => ['nullable', 'string', 'max:4000'],
        ]);

        $rapport->forceFill([
            'statut' => 'approuve',
            'approuve_par' => $requete->user()?->id,
            'approuve_le' => now(),
            'commentaire_approbation' => $donnees['commentaire_approbation'] ?? null,
        ])->save();

        return response()->json(['rapport' => $rapport->refresh()]);
    }

    public function rapportRejeter(Request $requete, int $id): JsonResponse
    {
        $this->verifierPermission($requete, 'qualite.manage');

        $rapport = RapportAccreditation::query()->findOrFail($id);

        if ($rapport->statut !== 'soumis') {
            return response()->json(['erreur' => 'Seul un rapport SOUMIS peut être rejeté.'], 422);
        }

        if ((int) $rapport->soumis_par === (int) $requete->user()?->id) {
            return response()->json(['erreur' => 'Le soumetteur ne peut pas rejeter son propre rapport.'], 422);
        }

        $donnees = $requete->validate([
            'motif_rejet' => ['required', 'string', 'min:10'],
        ]);

        $rapport->forceFill([
            'statut' => 'rejete',
            'approuve_par' => $requete->user()?->id,
            'approuve_le' => now(),
            'motif_rejet' => $donnees['motif_rejet'],
        ])->save();

        return response()->json(['rapport' => $rapport->refresh()]);
    }

    /**
     * Export sous permissions DOUBLES : lecture qualité (`qualite.view`)
     * demandée par le route + création d'export (`export.create`) exigée
     * ici — les deux clés sont RÉUTILISÉES, aucune inventée.
     */
    public function rapportExport(Request $requete, int $id): Response
    {
        $this->verifierPermission($requete, 'export.create');

        $rapport = $this->rapportsVisibles($requete)->findOrFail($id);
        $criteres = $this->service->criteresDuPerimetre($rapport->version, $rapport->faculte_id);
        $constats = ConstatAccreditation::query()
            ->where('perimetre', $rapport->perimetre)
            ->whereIn('critere_id', $criteres->pluck('id'))
            ->get()->keyBy('critere_id');

        $lignes = $criteres->map(fn ($c) => [
            'code' => $c->code,
            'libelle' => $c->libelle,
            'evaluation' => $constats->get($c->id)?->evaluation,
        ])->values()->all();

        $contenu = json_encode([
            'rapport' => $rapport->only(array_merge(['id', 'statut', 'perimetre'], RapportAccreditation::TOTAUX)),
            'lignes' => $lignes,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return response()->make($contenu, 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="rapport-accreditation-'.$rapport->id.'.json"',
        ]);
    }

    /* ── Contrôles internes ─────────────────────────────────────── */

    /**
     * Rapports visibles : périmètre forcé serveur — un agent à faculté
     * restreinte ne voit QUE les rapports de SA faculté (les rapports
     * globaux restent réservés aux rôles globaux).
     */
    private function rapportsVisibles(Request $requete)
    {
        $utilisateur = $requete->user();
        $restreinte = $utilisateur?->faculteRestreinteId();

        return RapportAccreditation::query()
            ->when($restreinte !== null, fn ($q) => $q->where('rapports_accreditation.faculte_id', $restreinte));
    }

    private function verifierPermission(Request $requete, string $permission): void
    {
        abort_unless($requete->user()?->can($permission) === true, 403, "Permission « {$permission} » requise.");
    }
}
