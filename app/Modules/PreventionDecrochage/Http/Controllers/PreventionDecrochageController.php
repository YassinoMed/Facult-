<?php

namespace App\Modules\PreventionDecrochage\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\PreventionDecrochage\Models\AlerteDecrochage;
use App\Modules\PreventionDecrochage\Models\RegleDecrochage;
use App\Modules\PreventionDecrochage\Models\SuiviDecrochage;
use App\Modules\PreventionDecrochage\Services\ServiceAlertes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Lot 15 — Prévention du décrochage : catalogue de règles expliquées et
 * approuvées, scan de détection, flux humain d'examen et de clôture,
 * mesures d'accompagnement séparées des résultats officiels.
 *
 * Contrôles (jamais le navigateur) :
 * - permissions `decrochage.view` / `decrochage.regles` / `decrochage.traiter`
 *   (registre additive, pattern assistant_ia.*) ;
 * - périmètre facultaire forcé côté serveur (primitives User ;
 *   même règle que ServiceObservabilite et le Lot 14) ;
 * - AUCUNE écriture hors du module (attendre des notes/statuts interdit).
 */
final class PreventionDecrochageController extends Controller
{
    public function __construct(private readonly ServiceAlertes $service) {}

    public function reglesIndex(Request $requete): JsonResponse
    {
        $this->verifierPermission($requete, 'decrochage.view');

        $regles = RegleDecrochage::query()->orderBy('code')->get([
            'id', 'code', 'nom', 'type', 'mesure', 'seuil', 'seuil_mesure', 'version',
            'statut', 'raison_explicable', 'approuve_par', 'approuve_le', 'created_at',
        ]);

        return response()->json(['version_regles' => '1.0', 'regles' => $regles]);
    }

    public function reglesCreer(Request $requete): JsonResponse
    {
        $this->verifierPermission($requete, 'decrochage.regles');

        $donnees = $requete->validate([
            'code' => ['required', 'string', 'max:60', 'unique:regles_decrochage,code'],
            'nom' => ['required', 'string', 'max:150'],
            'type' => ['required', 'string', Rule::in(RegleDecrochage::TYPES)],
            'mesure' => ['required', 'string', 'max:40'],
            'seuil' => ['required', 'numeric', 'min:0'],
            // Seuil de la mesure sous-jacente : OBLIGATOIRE pour `notes`
            // (note < seuil_mesure compte), ignoré pour `presences`.
            'seuil_mesure' => ['nullable', 'numeric', 'min:0', 'required_if:type,notes'],
            'raison_explicable' => ['required', 'string', 'min:10'],
        ]);

        $regle = RegleDecrochage::create([
            ...$donnees,
            'seuil_mesure' => $donnees['type'] === 'notes' ? (float) $donnees['seuil_mesure'] : null,
            'statut' => 'brouillon',
            'version' => 1,
            'cree_par' => $requete->user()?->id,
        ]);

        return response()->json(['regle' => $regle], 201);
    }

    public function regleApprouver(Request $requete, int $id): JsonResponse
    {
        $this->verifierPermission($requete, 'decrochage.regles');

        $regle = RegleDecrochage::query()->findOrFail($id);

        if (! is_string($regle->raison_explicable) || strlen($regle->raison_explicable) < 10) {
            return response()->json(['erreur' => 'Une règle sans justification écrite ne peut pas être approuvée.'], 422);
        }

        if ($regle->statut !== 'brouillon') {
            return response()->json(['erreur' => 'Seule une règle en brouillon peut être approuvée.'], 422);
        }

        $regle->forceFill([
            'statut' => 'approuvee',
            'approuve_par' => $requete->user()?->id,
            'approuve_le' => now(),
        ])->save();

        return response()->json(['regle' => $regle->refresh()]);
    }

    public function regleDesactiver(Request $requete, int $id): JsonResponse
    {
        $this->verifierPermission($requete, 'decrochage.regles');

        $regle = RegleDecrochage::query()->findOrFail($id);

        if ($regle->statut !== 'approuvee') {
            return response()->json(['erreur' => 'Seule une règle approuvée peut être désactivée.'], 422);
        }

        $regle->forceFill([
            'statut' => 'desactivee',
            'desactivee_par' => $requete->user()?->id,
        ])->save();

        return response()->json(['regle' => $regle->refresh()]);
    }

    /**
     * Scan : n'exécute QUE des règles approvées ; résumé explicite.
     */
    public function detecter(Request $requete, ServiceAlertes $service): JsonResponse
    {
        $this->verifierPermission($requete, 'decrochage.traiter');

        $donnees = $requete->validate([
            'faculte_id' => ['nullable', 'integer', 'exists:facultes,id'],
            'annee_universitaire_id' => ['nullable', 'integer', 'exists:annees_universitaires,id'],
        ]);

        $perimetre = $this->perimetre($requete, isset($donnees['faculte_id']) ? (int) $donnees['faculte_id'] : null);

        $resume = $service->detecter($perimetre, $donnees['annee_universitaire_id'] ?? null);

        return response()->json([...$resume, 'perimetre' => [
            'faculte_id' => $perimetre,
            'annee_universitaire_id' => $donnees['annee_universitaire_id'] ?? null,
            'note' => 'Le scan n\'agit que sur les étudiants présents dans les sources ; une donnée absente ne crée jamais un signal.',
        ]]);
    }

    public function alertesIndex(Request $requete): JsonResponse
    {
        $this->verifierPermission($requete, 'decrochage.view');

        $donnees = $requete->validate([
            'statut' => ['nullable', 'string', Rule::in(AlerteDecrochage::STATUTS)],
        ]);

        $perimetre = $this->perimetre($requete, $requete->integer('faculte_id') ?: null);

        $alertes = AlerteDecrochage::query()
            ->with(['regle:id,code,type,mesure,seuil', 'etudiant:id,nom,prenom'])
            ->join('etudiants', 'etudiants.id', '=', 'alertes_decrochage.etudiant_id')
            ->when($perimetre !== null, fn ($q) => $q->where('etudiants.faculte_id', $perimetre))
            ->when(! empty($donnees['statut']), fn ($q) => $q->where('alertes_decrochage.statut', $donnees['statut']))
            ->orderByDesc('alertes_decrochage.id')
            ->limit(200)
            ->get(['alertes_decrochage.*']);

        return response()->json(['alertes' => $alertes]);
    }

    /**
     * Flux humain : examen d'une alerte (aucune écriture hors module).
     */
    public function examiner(Request $requete, int $id): JsonResponse
    {
        $this->verifierPermission($requete, 'decrochage.traiter');

        $alerte = AlerteDecrochage::query()->findOrFail($id);
        $this->garantirPerimetreAlerte($requete, $alerte);

        if ($alerte->statut !== 'generee') {
            return response()->json(['erreur' => 'Seule une alerte générée peut être examinée.'], 422);
        }

        $donnees = $requete->validate([
            'commentaire_examen' => ['nullable', 'string', 'max:2000'],
        ]);

        $alerte->forceFill([
            'statut' => 'examinee',
            'commentaire_examen' => $donnees['commentaire_examen'] ?? null,
            'examen_par' => $requete->user()?->id,
            'examine_le' => now(),
        ])->save();

        return response()->json(['alerte' => $alerte->refresh()]);
    }

    /**
     * Flux humain : clôture tracée. La politique de rétention n'étant pas
     * confirmée, `retention_a` reste NULL (jamais inventée).
     */
    public function cloturer(Request $requete, int $id): JsonResponse
    {
        $this->verifierPermission($requete, 'decrochage.traiter');

        $alerte = AlerteDecrochage::query()->findOrFail($id);
        $this->garantirPerimetreAlerte($requete, $alerte);

        if (! in_array($alerte->statut, ['generee', 'examinee'], true)) {
            return response()->json(['erreur' => 'Une alerte déjà clôturée ne peut pas être re-clôturée.'], 422);
        }

        $donnees = $requete->validate([
            'motif_cloture' => ['required', 'string', 'max:2000'],
        ]);

        $alerte->forceFill([
            'statut' => 'cloturee',
            'motif_cloture' => $donnees['motif_cloture'],
            'cloturee_par' => $requete->user()?->id,
            'cloturee_le' => now(),
            'retention_a' => null, // politique de rétention NON confirmée
        ])->save();

        return response()->json(['alerte' => $alerte->refresh()]);
    }

    /**
     * Mesure d'accompagnement : dossier humanisé séparé des résultats.
     */
    public function ajouterSuivi(Request $requete, int $id): JsonResponse
    {
        $this->verifierPermission($requete, 'decrochage.traiter');

        $alerte = AlerteDecrochage::query()->findOrFail($id);
        $this->garantirPerimetreAlerte($requete, $alerte);

        $donnees = $requete->validate([
            'mesure' => ['required', 'string', 'max:2000'],
        ]);

        $suivi = SuiviDecrochage::create([
            'alerte_id' => $alerte->id,
            'mesure' => $donnees['mesure'],
            'auteur_id' => $requete->user()?->id,
        ]);

        return response()->json(['suivi' => $suivi], 201);
    }

    public function suivisIndex(Request $requete, int $id): JsonResponse
    {
        $this->verifierPermission($requete, 'decrochage.view');

        $alerte = AlerteDecrochage::query()->findOrFail($id);
        $this->garantirPerimetreAlerte($requete, $alerte);

        $suivis = SuiviDecrochage::query()->where('alerte_id', $alerte->id)->orderBy('id')->get();

        return response()->json(['suivis' => $suivis]);
    }

    /* ── Contrôles internes ─────────────────────────────────────── */

    /**
     * Périmètre facultaire : règle appliquée par l'agent.
     */
    private function perimetre(Request $requete, ?int $demandee): ?int
    {
        $utilisateur = $requete->user();
        abort_unless($utilisateur instanceof User, 403, 'Utilisateur manquant.');

        if ($demandee !== null && ! $utilisateur->peutAccederFaculte($demandee)) {
            abort(403, 'Prévention du décrochage : faculté hors périmètre.');
        }

        return $utilisateur->faculteRestreinteId() ?? $demandee;
    }

    /**
     * Une alerte n'est accessible que si SON étudiant est dans le périmètre
     * de l'agent (anti-IDOR sur les données de suivi).
     */
    private function garantirPerimetreAlerte(Request $requete, AlerteDecrochage $alerte): void
    {
        $utilisateur = $requete->user();
        abort_unless($utilisateur instanceof User, 403, 'Utilisateur manquant.');

        $etudiant = $alerte->etudiant;
        abort_unless($etudiant !== null, 404, 'Étudiant introuvable pour cette alerte.');

        if ($utilisateur->faculteRestreinteId() !== null
            && $etudiant->faculte_id !== $utilisateur->faculteRestreinteId()) {
            abort(403, 'Prévention du décrochage : alerte hors périmètre.');
        }
    }

    private function verifierPermission(Request $requete, string $permission): void
    {
        abort_unless($requete->user()?->can($permission) === true, 403, "Permission « {$permission} » requise.");
    }
}
