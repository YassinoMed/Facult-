<?php

namespace App\Services\DataExchange\Definitions;

use App\Models\AnneeUniversitaire;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\TypeEvaluation;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\MaquetteUe;
use App\Modules\Referentiel\Models\MaquetteUeEcue;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Services\Io\IoException;

/**
 * Import des contextes ECUE d'un plan d'études (LOOP B6) : alimente une
 * maquette BROUILLON (créée si absente pour programme×année) sans jamais
 * approuver, activer ou verrouiller quoi que ce soit — le workflow
 * (`MaquetteService`, permissions `maquettes.approve`) reste seul
 * maître. Mêmes gardes que `definirEcues` : UE du programme (INV-1),
 * ECUE de l'UE, semestre pivot = semestre de l'UE (INV-5), régime
 * whitelisté, périmètre faculté. Idempotent : clé unique
 * (maquette_ue, matiere) → réimport = mise à jour.
 */
class MaquetteImportDefinition implements ImportDefinitionInterface, ImportValidationAvancee
{
    /**
     * Caches par import (même instance réutilisée sur toutes les
     * lignes) : zéro requête par ligne au-delà de la première.
     *
     * @var array<string, mixed>
     */
    private array $cache = [];

    public function entite(): string
    {
        return 'maquettes-plan';
    }

    public function libelle(): string
    {
        return 'Maquettes — contextes ECUE du plan';
    }

    public function colonnes(): array
    {
        return ['programme', 'annee', 'ue', 'ecue', 'faculte', 'ordre', 'coefficient', 'credits',
            'volume_cm', 'volume_td', 'volume_tp', 'type_evaluation', 'regime', 'groupe_choix'];
    }

    public function colonnesRequises(): array
    {
        // L'année est exigée : jamais de période d'application devinée.
        return ['programme', 'annee', 'ue', 'ecue'];
    }

    public function aliasesColonnes(): array
    {
        return [
            'programme' => ['parcours_code', 'code_parcours', 'parcours', 'code_programme'],
            'annee' => ['annee_universitaire', 'annee_scolaire', 'libelle_annee'],
            'ue' => ['ue_code', 'code_ue'],
            'ecue' => ['matiere', 'code_matiere', 'matiere_code'],
            'faculte' => ['code_faculte', 'faculte_code', 'etablissement'],
            'volume_cm' => ['volume_horaire', 'volume', 'volume_heures'],
            'type_evaluation' => ['type', 'type_cours'],
            'groupe_choix' => ['groupe', 'choix'],
        ];
    }

    public function regles(): array
    {
        return [
            'programme' => 'required|string|max:30',
            'annee' => 'required|string|max:20',
            'ue' => 'required|string|max:30',
            'ecue' => 'required|string|max:30',
            'faculte' => 'nullable|string|max:20',
            'ordre' => 'nullable|integer|min:0|max:65000',
            'coefficient' => 'nullable|numeric|min:0|max:99',
            'credits' => 'nullable|integer|min:0|max:60',
            'volume_cm' => 'nullable|integer|min:0|max:999',
            'volume_td' => 'nullable|integer|min:0|max:999',
            'volume_tp' => 'nullable|integer|min:0|max:999',
            'type_evaluation' => 'nullable|string|max:30',
            'regime' => 'nullable|string|max:30',
            'groupe_choix' => 'nullable|string|max:30',
        ];
    }

    public function cleDoublon(): array
    {
        return ['programme', 'annee', 'ue', 'ecue'];
    }

    public function validerLigneMetier(array $ligne, ?int $auteurId): array
    {
        $erreurs = [];
        $contexte = $this->resoudre($ligne);

        if ($contexte['programme'] === null) {
            $erreurs[] = [
                'champ' => 'programme',
                'message' => 'Programme « '.($ligne['programme'] ?? '').' » introuvable'
                    .(! empty($ligne['faculte']) ? ' pour la faculté « '.($ligne['faculte'] ?? '').' »' : '').'.',
                'code' => 'PROGRAMME_NOT_FOUND',
            ];

            return $erreurs;
        }

        if ($contexte['programme']->statut === Programme::STATUT_ARCHIVE) {
            $erreurs[] = [
                'champ' => 'programme',
                'message' => 'Programme « '.($ligne['programme'] ?? '').' » archivé.',
                'code' => 'PROGRAMME_ARCHIVE',
            ];
        }

        if ($auteurId !== null) {
            $auteur = User::find($auteurId);

            if ($auteur !== null && ! $auteur->peutAccederFaculte((int) $contexte['programme']->faculte_id)) {
                $erreurs[] = [
                    'champ' => 'programme',
                    'message' => 'Programme « '.($ligne['programme'] ?? '').' » hors de votre périmètre.',
                    'code' => 'HORS_PERIMETRE',
                ];
            }
        }

        if ($contexte['annee'] === null) {
            $erreurs[] = [
                'champ' => 'annee',
                'message' => 'Année universitaire « '.($ligne['annee'] ?? '').' » introuvable.',
                'code' => 'ANNEE_INTROUVABLE',
            ];
        }

        if ($contexte['ue'] === null || (int) $contexte['ue']->programme_id !== (int) $contexte['programme']->id) {
            $erreurs[] = [
                'champ' => 'ue',
                'message' => 'UE « '.($ligne['ue'] ?? '').' » introuvable dans le programme « '.($ligne['programme'] ?? '').' ».',
                'code' => 'UE_HORS_PROGRAMME',
            ];
        }

        if ($contexte['matiere'] === null) {
            $erreurs[] = [
                'champ' => 'ecue',
                'message' => 'ECUE « '.($ligne['ecue'] ?? '').' » introuvable.',
                'code' => 'ECUE_INTROUVABLE',
            ];
        } elseif ($contexte['ue'] !== null && (int) $contexte['matiere']->ue_id !== (int) $contexte['ue']->id) {
            $erreurs[] = [
                'champ' => 'ecue',
                'message' => "ECUE « {$contexte['matiere']->code} » hors de l'UE « {$contexte['ue']->code} ».",
                'code' => 'ECUE_HORS_UE',
            ];
        }

        $regime = trim((string) ($ligne['regime'] ?? ''));
        if ($regime !== '' && ! in_array($regime, ['obligatoire', 'optionnelle', 'conditionnelle'], true)) {
            $erreurs[] = [
                'champ' => 'regime',
                'message' => "Régime « {$regime} » inconnu (obligatoire, optionnelle, conditionnelle).",
                'code' => 'REGIME_INCONNU',
            ];
        }

        $typeEvaluation = trim((string) ($ligne['type_evaluation'] ?? ''));
        if ($typeEvaluation !== '' && ! in_array($typeEvaluation, $this->codesEvaluation(), true)) {
            $erreurs[] = [
                'champ' => 'type_evaluation',
                'message' => "Type d’évaluation « {$typeEvaluation} » inconnu ou inactif.",
                'code' => 'TYPE_EVALUATION_INCONNU',
            ];
        }

        // Plan existant non-brouillon : verrouillé, jamais muté par
        // l'import (l'humain crée une version, comme sur le web).
        $maquette = $this->maquetteCible($contexte);
        if ($maquette !== null && $maquette->estVerrouillee()) {
            $erreurs[] = [
                'champ' => 'ue',
                'message' => "Maquette v{$maquette->version} verrouillée ({$maquette->statut}) : créez une nouvelle version.",
                'code' => 'MAQUETTE_VERROUILLEE',
            ];
        }

        return $erreurs;
    }

    public function cleMetier(array $ligne): ?string
    {
        return implode('|', [
            mb_strtoupper(trim((string) ($ligne['programme'] ?? ''))),
            mb_strtoupper(trim((string) ($ligne['annee'] ?? ''))),
            mb_strtoupper(trim((string) ($ligne['ue'] ?? ''))),
            mb_strtoupper(trim((string) ($ligne['ecue'] ?? ''))),
            mb_strtoupper(trim((string) ($ligne['faculte'] ?? ''))),
        ]);
    }

    public function trouverExistant(array $ligne): ?object
    {
        $contexte = $this->resoudre($ligne);

        if ($contexte['programme'] === null || $contexte['annee'] === null
            || $contexte['ue'] === null || $contexte['matiere'] === null) {
            return null;
        }

        $maquette = $this->maquetteCible($contexte);

        if ($maquette === null) {
            return null;
        }

        return MaquetteUeEcue::whereHas('maquetteUe', fn ($q) => $q
            ->where('maquette_id', $maquette->id)
            ->where('ue_id', $contexte['ue']->id))
            ->where('matiere_id', $contexte['matiere']->id)
            ->first();
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        $contexte = $this->resoudre($ligne, true);

        $programme = $contexte['programme'];
        $annee = $contexte['annee'];
        $ue = $contexte['ue'];
        $matiere = $contexte['matiere'];

        if ($programme === null) {
            throw IoException::malforme("Programme « {$ligne['programme']} » introuvable (ligne {$ligne['ligne']}).");
        }

        $auteur = $auteurId !== null ? User::find($auteurId) : null;
        if ($auteur !== null && ! $auteur->peutAccederFaculte((int) $programme->faculte_id)) {
            throw IoException::malforme("Programme « {$ligne['programme']} » hors de votre périmètre (ligne {$ligne['ligne']}).");
        }

        if ($programme->statut === Programme::STATUT_ARCHIVE) {
            throw IoException::malforme("Programme « {$ligne['programme']} » archivé (ligne {$ligne['ligne']}).");
        }

        if ($annee === null) {
            throw IoException::malforme("Année universitaire « {$ligne['annee']} » introuvable (ligne {$ligne['ligne']}).");
        }

        if ($ue === null || (int) $ue->programme_id !== (int) $programme->id) {
            throw IoException::malforme("UE « {$ligne['ue']} » introuvable dans le programme (ligne {$ligne['ligne']}).");
        }

        if ($matiere === null) {
            throw IoException::malforme("ECUE « {$ligne['ecue']} » introuvable (ligne {$ligne['ligne']}).");
        }

        if ((int) $matiere->ue_id !== (int) $ue->id) {
            throw IoException::malforme("ECUE « {$matiere->code} » hors de l'UE « {$ue->code} » (ligne {$ligne['ligne']}).");
        }

        $regime = trim((string) ($ligne['regime'] ?? ''));
        if ($regime !== '' && ! in_array($regime, ['obligatoire', 'optionnelle', 'conditionnelle'], true)) {
            throw IoException::malforme("Régime « {$regime} » inconnu (ligne {$ligne['ligne']}).");
        }

        // Brouillon réutilisé ou créé (version suivante) — jamais
        // d'approbation ni d'activation ici (règle absolue n°10).
        $maquette = $this->maquetteCible($contexte);

        if ($maquette !== null && $maquette->estVerrouillee()) {
            throw IoException::malforme("Maquette v{$maquette->version} verrouillée : créez une nouvelle version (ligne {$ligne['ligne']}).");
        }

        if ($maquette === null) {
            $version = (Maquette::where('programme_id', $programme->id)
                ->where('annee_universitaire_id', $annee->id)
                ->max('version') ?? 0) + 1;

            $maquette = Maquette::create([
                'programme_id' => $programme->id,
                'annee_universitaire_id' => $annee->id,
                'version' => $version,
                'statut' => 'draft',
                'auteur_id' => $auteurId,
                'commentaire' => 'Créée par import maquettes-plan.',
            ]);
        }

        // INV-5 : le semestre du pivot est celui de l'UE.
        $pivot = MaquetteUe::firstOrCreate(
            ['maquette_id' => $maquette->id, 'ue_id' => $ue->id],
            ['semestre_id' => $ue->semestre_id]
        );

        MaquetteUeEcue::updateOrCreate(
            ['maquette_ue_id' => $pivot->id, 'matiere_id' => $matiere->id],
            [
                'ordre' => (int) ($ligne['ordre'] ?? $matiere->ordre ?? 0),
                'coefficient' => $ligne['coefficient'] ?? $matiere->coefficient ?? 1,
                'credits' => (int) ($ligne['credits'] ?? $matiere->credits ?? 0),
                'volume_cm' => (int) ($ligne['volume_cm'] ?? $matiere->volume_cm ?? 0),
                'volume_td' => (int) ($ligne['volume_td'] ?? $matiere->volume_td ?? 0),
                'volume_tp' => (int) ($ligne['volume_tp'] ?? $matiere->volume_tp ?? 0),
                'type_evaluation' => $ligne['type_evaluation'] ?? $matiere->type_evaluation,
                'regime' => $regime !== '' ? $regime : 'obligatoire',
                'obligatoire' => $regime === '' || $regime === 'obligatoire',
                'groupe_choix' => $ligne['groupe_choix'] ?? null,
            ]
        );

        return "contexte {$matiere->code} (maquette v{$maquette->version})";
    }

    public function modes(): array
    {
        return ['creer', 'upsert'];
    }

    public function vider(?int $auteurId): void
    {
        // Jamais de vidage de plans d'études.
    }

    public function formats(): array
    {
        return ['csv', 'xlsx', 'ods', 'json'];
    }

    /**
     * Résolution groupée (caches par import) : programme (canonique
     * A4), année, UE du programme, ECUE.
     *
     * @return array{programme: ?Programme, annee: mixed, ue: ?UniteEnseignement, matiere: ?Matiere}
     */
    private function resoudre(array $ligne, bool $recharger = false): array
    {
        $cle = ($ligne['programme'] ?? '').'|'.($ligne['faculte'] ?? '').'|'
            .($ligne['annee'] ?? '').'|'.($ligne['ue'] ?? '').'|'.($ligne['ecue'] ?? '');

        if (! $recharger && array_key_exists($cle, $this->cache)) {
            return $this->cache[$cle];
        }

        $programme = Programme::resoudreParCode($ligne['programme'] ?? null, $ligne['faculte'] ?? null);
        $annee = AnneeUniversitaire::where('libelle', trim((string) ($ligne['annee'] ?? '')))->first();

        $ue = null;
        if ($programme !== null && trim((string) ($ligne['ue'] ?? '')) !== '') {
            $ue = UniteEnseignement::where('code', trim((string) $ligne['ue']))
                ->where('programme_id', $programme->id)
                ->first();
        }

        $matiere = Matiere::resoudreParCode($ligne['ecue'] ?? null);

        return $this->cache[$cle] = compact('programme', 'annee', 'ue', 'matiere');
    }

    /**
     * Maquette cible : dernière version du programme pour l'année
     * (brouillon réutilisé, verrouillée refusée en validation).
     */
    private function maquetteCible(array $contexte): ?Maquette
    {
        if ($contexte['programme'] === null || $contexte['annee'] === null) {
            return null;
        }

        return Maquette::where('programme_id', $contexte['programme']->id)
            ->where('annee_universitaire_id', $contexte['annee']->id)
            ->orderByDesc('version')
            ->first();
    }

    /** @var array<int, string>|null */
    private ?array $codesEvaluation = null;

    /**
     * @return array<int, string>
     */
    private function codesEvaluation(): array
    {
        return $this->codesEvaluation ??= TypeEvaluation::where('actif', true)->pluck('code')->all();
    }
}
