<?php

namespace App\Services\DataExchange\Definitions;

use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Schedule\Models\Room;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Support\Recherche;
use Illuminate\Http\Request;

class AffectationSalleDefinition implements ExportDefinitionInterface, ImportDefinitionInterface, ImportValidationAvancee
{
    public function entite(): string
    {
        return 'affectations-salles';
    }

    public function libelle(): string
    {
        return 'Affectations aux salles';
    }

    public function colonnes(): array
    {
        return ['enseignement_code', 'salle_code'];
    }

    public function colonnesRequises(): array
    {
        return ['enseignement_code', 'salle_code'];
    }

    public function aliasesColonnes(): array
    {
        return [
            'enseignement_code' => ['code_enseignement', 'enseignement'],
            'salle_code' => ['code_salle', 'salle', 'room_code'],
        ];
    }

    public function regles(): array
    {
        return [
            'enseignement_code' => ['required', 'string', 'max:50'],
            'salle_code' => ['required', 'string', 'max:50'],
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

        $salle = Room::where('code', trim((string) $ligne['salle_code']))->first();
        if ($salle === null) {
            $erreurs[] = [
                'champ' => 'salle_code',
                'message' => "La salle « {$ligne['salle_code']} » est introuvable.",
                'code' => 'ROOM_NOT_FOUND',
            ];
        }

        return $erreurs;
    }

    public function cleMetier(array $ligne): ?string
    {
        return trim((string) ($ligne['enseignement_code'] ?? '')).'|'.trim((string) ($ligne['salle_code'] ?? ''));
    }

    public function cleDoublon(): array
    {
        return ['enseignement_code', 'salle_code'];
    }

    public function trouverExistant(array $ligne): ?object
    {
        $ens = Enseignement::where('code', trim((string) ($ligne['enseignement_code'] ?? '')))->first();
        $salle = Room::where('code', trim((string) ($ligne['salle_code'] ?? '')))->first();

        if ($ens === null || $salle === null) {
            return null;
        }

        return $ens->salles()->where('rooms.id', $salle->id)->first();
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        $ens = Enseignement::where('code', trim((string) $ligne['enseignement_code']))->first();
        $salle = Room::where('code', trim((string) $ligne['salle_code']))->first();

        if ($ens === null || $salle === null) {
            throw new \DomainException('Enseignement ou salle introuvable.');
        }

        $ens->salles()->syncWithoutDetaching([$salle->id]);

        return "affectation enseignement-salle : {$salle->code}";
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
        return Enseignement::with('salles')->has('salles')->orderBy('code');
    }

    public function filtrer($query, Request $request)
    {
        if ($search = trim($request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $query->where('code', 'like', $motif);
        }

        return $query;
    }

    public function ligne($modele): array
    {
        return [
            'enseignement_code' => $modele->code,
            'salle_code' => $modele->salles->pluck('code')->implode(', '),
        ];
    }
}
