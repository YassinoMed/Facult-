<?php

namespace App\Services\DataExchange\Definitions;

use App\Models\Matiere;
use App\Modules\Evaluation\Models\TypeEvaluation;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Services\Io\IoException;
use App\Support\Recherche;
use Illuminate\Http\Request;

/**
 * Définition ECUE (§21-22) : opère sur Matiere (AUCUNE entité dupliquée),
 * rattachement UE + volumes + évaluation. Export : ECUE rattachées.
 */
class EcueDefinition implements ExportDefinitionInterface, ImportDefinitionInterface, ImportValidationAvancee
{
    /**
     * Caches par import (même instance réutilisée sur toutes les
     * lignes) : zéro requête par ligne au-delà de la première.
     *
     * @var array<string, ?UniteEnseignement>
     */
    private array $ueResolues = [];

    /** @var array<int, string>|null */
    private ?array $codesEvaluation = null;

    public function entite(): string
    {
        return 'ecues';
    }

    public function libelle(): string
    {
        return 'ECUE';
    }

    public function colonnes(): array
    {
        return ['code', 'ue', 'nom', 'credits', 'coefficient', 'volume_cm', 'volume_td', 'volume_tp', 'type_evaluation'];
    }

    public function colonnesRequises(): array
    {
        return ['code'];
    }

    public function aliasesColonnes(): array
    {
        return [
            'nom' => ['libelle', 'designation', 'intitule'],
            'ue' => ['ue_code', 'code_ue'],
            'volume_cm' => ['volume_horaire', 'volume', 'volume_heures'],
            'type_evaluation' => ['type', 'type_cours'],
        ];
    }

    public function regles(): array
    {
        return [
            'code' => 'required|string|max:20',
            'ue' => 'nullable|string|max:30',
            'nom' => 'nullable|string|max:150',
            'credits' => 'nullable|integer|min:0|max:60',
            'coefficient' => 'nullable|numeric|min:0|max:99',
            'volume_cm' => 'nullable|integer|min:0|max:999',
            'volume_td' => 'nullable|integer|min:0|max:999',
            'volume_tp' => 'nullable|integer|min:0|max:999',
            'type_evaluation' => 'nullable|string|max:30',
        ];
    }

    public function cleDoublon(): array
    {
        return ['code'];
    }

    public function validerLigneMetier(array $ligne, ?int $auteurId): array
    {
        $erreurs = [];

        // LOT 2 §15 : le type d'évaluation doit exister au référentiel
        // configurable (actif) — jamais de valeur libre silencieuse.
        $typeEvaluation = trim((string) ($ligne['type_evaluation'] ?? ''));

        if ($typeEvaluation !== '' && ! in_array($typeEvaluation, $this->codesEvaluation(), true)) {
            $erreurs[] = [
                'champ' => 'type_evaluation',
                'message' => "Type d’évaluation « {$typeEvaluation} » inconnu ou inactif (référentiel des types d’évaluation).",
                'code' => 'TYPE_EVALUATION_INCONNU',
            ];
        }

        // §18 : UE introuvable/ambiguë + programme archivé refusés dès
        // la prévisualisation (mêmes codes qu'à la confirmation).
        $codeUe = trim((string) ($ligne['ue'] ?? ''));

        if ($codeUe !== '') {
            $ue = $this->resoudreUe($codeUe);

            if ($ue === null) {
                $erreurs[] = [
                    'champ' => 'ue',
                    'message' => "UE « {$codeUe} » introuvable ou ambiguë.",
                    'code' => 'UE_INTROUVABLE',
                ];
            } elseif ($ue->programme !== null && $ue->programme->statut === Programme::STATUT_ARCHIVE) {
                $erreurs[] = [
                    'champ' => 'ue',
                    'message' => "UE « {$codeUe} » rattachée à un programme archivé.",
                    'code' => 'PROGRAMME_ARCHIVE',
                ];
            }
        }

        return $erreurs;
    }

    /**
     * @return array<int, string>
     */
    private function codesEvaluation(): array
    {
        return $this->codesEvaluation ??= TypeEvaluation::where('actif', true)->pluck('code')->all();
    }

    public function cleMetier(array $ligne): ?string
    {
        return Matiere::normaliserCode($ligne['code'] ?? null);
    }

    public function resoudreUe(?string $code): ?UniteEnseignement
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        $cle = mb_strtoupper(trim($code));

        if (! array_key_exists($cle, $this->ueResolues)) {
            $candidats = UniteEnseignement::where('code', trim($code))->with('programme')->get();
            $this->ueResolues[$cle] = $candidats->count() === 1 ? $candidats->first() : null;
        }

        return $this->ueResolues[$cle];
    }

    public function trouverExistant(array $ligne): ?object
    {
        // INV-9 : résolution canonique par code normalisé.
        return Matiere::resoudreParCode($ligne['code'] ?? null);
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        $ue = null;

        if (($ligne['ue'] ?? null) !== null && trim((string) $ligne['ue']) !== '') {
            $ue = $this->resoudreUe($ligne['ue']);

            if ($ue === null) {
                throw IoException::malforme("UE « {$ligne['ue']} » introuvable ou ambiguë (ligne {$ligne['ligne']}).");
            }

            if ($ue->programme !== null && $ue->programme->statut === Programme::STATUT_ARCHIVE) {
                throw IoException::malforme("UE « {$ligne['ue']} » rattachée à un programme archivé (ligne {$ligne['ligne']}).");
            }
        }

        $existant = $this->trouverExistant($ligne);

        $donnees = [
            'credits' => $ligne['credits'] !== null ? (int) $ligne['credits'] : 2,
            'coefficient' => $ligne['coefficient'] !== null ? (float) $ligne['coefficient'] : 1,
            'volume_cm' => $ligne['volume_cm'] !== null ? (int) $ligne['volume_cm'] : 0,
            'volume_td' => $ligne['volume_td'] !== null ? (int) $ligne['volume_td'] : 0,
            'volume_tp' => $ligne['volume_tp'] !== null ? (int) $ligne['volume_tp'] : 0,
            'type_evaluation' => $ligne['type_evaluation'],
        ];

        if ($ue !== null) {
            $donnees['ue_id'] = $ue->id;
        }

        if ($ligne['nom'] !== null) {
            $donnees['nom'] = $ligne['nom'];
        }

        if ($existant !== null) {
            $existant->update($donnees);

            return "mise à jour : {$ligne['code']}";
        }

        Matiere::create(['code' => Matiere::normaliserCode($ligne['code']), 'nom' => $ligne['nom'] ?? $ligne['code'],
            'niveau' => 'L1', 'semestre' => 1, 'actif' => true] + $donnees);

        return "créée : {$ligne['code']}";
    }

    public function modes(): array
    {
        return ['creer', 'upsert'];
    }

    public function vider(?int $auteurId): void
    {
        // Jamais de vidage : notes rattachées.
    }

    public function formats(): array
    {
        return ['csv', 'xlsx', 'json', 'pdf'];
    }

    public function requete(Request $request)
    {
        $query = Matiere::with('ue')->whereNotNull('ue_id')->orderBy('code');

        if ($request->user()->faculteRestreinteId() !== null) {
            $query->whereHas('ue.programme', fn ($q) => $q->where('faculte_id', $request->user()->faculteRestreinteId()));
        }

        return $query;
    }

    public function filtrer($query, Request $request)
    {
        if ($ids = $request->input('ids')) {
            $query->whereIn('matieres.id', array_map('intval', (array) $ids));
        }

        if ($search = trim($request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $query->where(fn ($w) => $w
                ->where('code', 'like', $motif)
                ->orWhere('nom', 'like', $motif));
        }

        return $query;
    }

    public function ligne($modele): array
    {
        return [
            'code' => $modele->code,
            'ue' => $modele->ue?->code,
            'nom' => $modele->nom,
            'credits' => $modele->credits,
            'coefficient' => $modele->coefficient,
            'volume_cm' => $modele->volume_cm,
            'volume_td' => $modele->volume_td,
            'volume_tp' => $modele->volume_tp,
            'type_evaluation' => $modele->type_evaluation,
        ];
    }
}
