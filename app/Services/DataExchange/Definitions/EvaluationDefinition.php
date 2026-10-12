<?php

namespace App\Services\DataExchange\Definitions;

use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Support\Recherche;
use Illuminate\Http\Request;

class EvaluationDefinition implements ExportDefinitionInterface, ImportDefinitionInterface, ImportValidationAvancee
{
    public function entite(): string
    {
        return 'evaluations';
    }

    public function libelle(): string
    {
        return 'Évaluations';
    }

    public function colonnes(): array
    {
        return ['code', 'enseignement_code', 'type', 'session', 'coefficient', 'date_evaluation', 'statut'];
    }

    public function colonnesRequises(): array
    {
        return ['code', 'enseignement_code'];
    }

    public function aliasesColonnes(): array
    {
        return [
            'enseignement_code' => ['enseignement', 'code_enseignement'],
            'session' => ['session_code', 'code_session'],
            'date_evaluation' => ['date', 'date_examen'],
            'type' => ['type_evaluation', 'type_examen'],
        ];
    }

    public function regles(): array
    {
        return [
            'code' => ['required', 'string', 'max:50'],
            'enseignement_code' => ['required', 'string', 'max:50'],
            'type' => ['nullable', 'string', 'max:50'],
            'session' => ['nullable', 'string', 'max:50'],
            'coefficient' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'date_evaluation' => ['nullable', 'date'],
            'statut' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function validerLigneMetier(array $ligne, ?int $auteurId): array
    {
        $erreurs = [];
        $ens = Enseignement::where('code', trim((string) $ligne['enseignement_code']))->first();
        if ($ens === null) {
            $erreurs[] = [
                'champ' => 'enseignement_code',
                'message' => "L'enseignement « {$ligne['enseignement_code']} » est introuvable.",
                'code' => 'ENSEIGNEMENT_NOT_FOUND',
            ];
        }

        return $erreurs;
    }

    public function cleMetier(array $ligne): ?string
    {
        return mb_strtoupper(trim((string) ($ligne['code'] ?? '')));
    }

    public function cleDoublon(): array
    {
        return ['code'];
    }

    public function trouverExistant(array $ligne): ?object
    {
        return Examen::where('code', mb_strtoupper(trim((string) $ligne['code'])))->first();
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        $code = mb_strtoupper(trim((string) $ligne['code']));
        $ens = Enseignement::where('code', trim((string) $ligne['enseignement_code']))->first();

        if ($ens === null) {
            throw new \DomainException("Enseignement {$ligne['enseignement_code']} introuvable.");
        }

        $sessionCode = ! empty($ligne['session']) ? mb_strtoupper(trim((string) $ligne['session'])) : 'PRINCIPALE';
        $session = SessionExamen::firstOrCreate([
            'code' => $sessionCode,
            'annee_universitaire_id' => $ens->annee_universitaire_id,
        ], [
            'nom' => "Session {$sessionCode}",
            'type' => str_contains($sessionCode, 'RATT') ? 'rattrapage' : 'principale',
            'statut' => 'ouverte',
            'date_debut' => ! empty($ligne['date_evaluation']) ? $ligne['date_evaluation'] : now()->toDateString(),
            'date_fin' => ! empty($ligne['date_evaluation']) ? $ligne['date_evaluation'] : now()->addMonths(1)->toDateString(),
        ]);

        $typeBrut = mb_strtolower(trim((string) ($ligne['type'] ?? 'examen')));
        $type = match (true) {
            str_contains($typeBrut, 'cont') => 'controle',
            str_contains($typeBrut, 'part') => 'partiel',
            str_contains($typeBrut, 'ratt') => 'rattrapage',
            str_contains($typeBrut, 'oral') => 'oral',
            str_contains($typeBrut, 'proj') => 'projet',
            default => 'examen',
        };

        $statutBrut = mb_strtolower(trim((string) ($ligne['statut'] ?? 'planned')));
        $statut = match (true) {
            str_contains($statutBrut, 'plan') => 'planned',
            str_contains($statutBrut, 'prog') || str_contains($statutBrut, 'sched') => 'scheduled',
            str_contains($statutBrut, 'pub') => 'published',
            str_contains($statutBrut, 'term') || str_contains($statutBrut, 'comp') => 'completed',
            default => 'draft',
        };

        $examen = Examen::updateOrCreate([
            'code' => $code,
        ], [
            'libelle' => $code,
            'session_id' => $session->id,
            'annee_universitaire_id' => $ens->annee_universitaire_id,
            'programme_id' => $ens->programme_id,
            'semestre_lmd_id' => $ens->semestre_id,
            'type' => $type,
            'statut' => $statut,
            'description' => "Évaluation {$code} pour enseignement {$ens->code}",
        ]);

        if (! empty($ligne['date_evaluation'])) {
            Epreuve::updateOrCreate([
                'code' => $code,
            ], [
                'examen_id' => $examen->id,
                'session_id' => $session->id,
                'annee_universitaire_id' => $ens->annee_universitaire_id,
                'matiere_id' => $ens->matiere_id,
                'date' => $ligne['date_evaluation'],
                'heure_debut' => '08:00:00',
                'heure_fin' => '10:00:00',
                'duree_minutes' => 120,
                'coefficient' => ! empty($ligne['coefficient']) ? (float) $ligne['coefficient'] : 1.0,
                'type_evaluation' => $type,
                'statut' => 'scheduled',
            ]);
        }

        return "évaluation créée : {$code}";
    }

    public function modes(): array
    {
        return ['creer', 'upsert'];
    }

    public function vider(?int $auteurId): void {}

    public function formats(): array
    {
        return ['csv', 'xlsx', 'json', 'pdf'];
    }

    public function requete(Request $request)
    {
        return Examen::with(['session', 'anneeUniversitaire', 'programme'])->orderBy('code');
    }

    public function filtrer($query, Request $request)
    {
        if ($search = trim($request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $query->where(fn ($q) => $q->where('code', 'like', $motif)->orWhere('libelle', 'like', $motif));
        }

        return $query;
    }

    public function ligne($modele): array
    {
        return [
            'code' => $modele->code,
            'enseignement_code' => $modele->description,
            'type' => $modele->type,
            'session' => $modele->session?->code,
            'coefficient' => '1.0',
            'date_evaluation' => $modele->published_at?->format('Y-m-d'),
            'statut' => $modele->statut,
        ];
    }
}
