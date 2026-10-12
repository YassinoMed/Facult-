<?php

namespace App\Services\DataExchange\Definitions;

use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Services\Io\IoException;
use App\Support\Recherche;
use Illuminate\Http\Request;

/**
 * Définition semestres LMD (§14) : identifiant = code + niveau.
 * Jamais de vidage : UE et inscriptions pédagogiques rattachées.
 */
class SemestreDefinition implements ExportDefinitionInterface, ImportDefinitionInterface, ImportValidationAvancee
{
    public function entite(): string
    {
        return 'semestres';
    }

    public function libelle(): string
    {
        return 'Semestres';
    }

    public function colonnes(): array
    {
        return ['code', 'nom', 'niveau', 'programme', 'numero'];
    }

    public function colonnesRequises(): array
    {
        return ['code', 'niveau'];
    }

    public function aliasesColonnes(): array
    {
        return [
            'nom' => ['libelle', 'designation', 'intitule'],
            'niveau' => ['niveau_code', 'code_niveau'],
            'programme' => ['programme_code', 'parcours_code', 'parcours', 'code_programme'],
            'numero' => ['ordre'],
        ];
    }

    public function regles(): array
    {
        return [
            'code' => 'required|string|max:10',
            'nom' => 'nullable|string|max:100',
            'niveau' => 'required|string|max:10',
            'programme' => 'nullable|string|max:30',
            'numero' => 'nullable|integer|min:1|max:24',
        ];
    }

    public function cleDoublon(): array
    {
        return ['code', 'niveau'];
    }

    public function validerLigneMetier(array $ligne, ?int $auteurId): array
    {
        $erreurs = [];
        $niveauCode = $ligne['niveau'] ?? null;
        if (empty($niveauCode)) {
            $erreurs[] = [
                'champ' => 'niveau',
                'message' => 'Le niveau de rattachement du semestre est obligatoire.',
                'code' => 'NIVEAU_REQUIRED',
            ];
        }

        return $erreurs;
    }

    public function cleMetier(array $ligne): ?string
    {
        $code = mb_strtoupper(trim((string) ($ligne['code'] ?? '')));
        $niv = mb_strtoupper(trim((string) ($ligne['niveau'] ?? '')));

        return "{$niv}|{$code}";
    }

    public function trouverExistant(array $ligne): ?object
    {
        $niveau = $this->resoudreNiveau($ligne);

        if ($niveau === null) {
            return null;
        }

        return SemestreLmd::where('code', $ligne['code'])
            ->where('niveau_id', $niveau->id)->first();
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        $programmeCode = $ligne['programme'] ?? null;
        $niveauCode = mb_strtoupper(trim((string) ($ligne['niveau'] ?? '')));

        $query = Niveau::where('code', $niveauCode);
        if (! empty($programmeCode)) {
            $prog = Programme::where('code', mb_strtoupper(trim($programmeCode)))->first();
            if ($prog !== null) {
                $query->where('programme_id', $prog->id);
            }
        }

        $niveaux = $query->get();

        if ($niveaux->isEmpty()) {
            throw IoException::malforme("Niveau « {$niveauCode} » introuvable (ligne {$ligne['ligne']}).");
        }

        $donnees = [
            'nom' => $ligne['nom'] ?? $ligne['code'],
            'numero' => ! empty($ligne['numero']) ? (int) $ligne['numero'] : 1,
            'ordre' => ! empty($ligne['numero']) ? (int) $ligne['numero'] : 1,
        ];

        $totalImpactes = 0;
        foreach ($niveaux as $niv) {
            SemestreLmd::updateOrCreate(
                ['code' => $ligne['code'], 'niveau_id' => $niv->id],
                ['nom' => $donnees['nom'], 'numero' => $donnees['numero'], 'ordre' => $donnees['ordre'], 'actif' => true]
            );
            $totalImpactes++;
        }

        return "semestre {$ligne['code']} appliqué ({$totalImpactes} niveaux)";
    }

    public function modes(): array
    {
        return ['creer', 'upsert'];
    }

    public function vider(?int $auteurId): void
    {
        // Jamais de vidage : UE et inscriptions pédagogiques rattachées.
    }

    public function formats(): array
    {
        return ['csv', 'xlsx', 'json', 'pdf'];
    }

    public function requete(Request $request)
    {
        $query = SemestreLmd::with(['niveau.programme'])->orderBy('numero');

        if ($request->user()->faculteRestreinteId() !== null) {
            $query->whereHas('niveau.programme', fn ($q) => $q->where('faculte_id', $request->user()->faculteRestreinteId()));
        }

        return $query;
    }

    public function filtrer($query, Request $request)
    {
        if ($ids = $request->input('ids')) {
            $query->whereIn('semestres_lmd.id', array_map('intval', (array) $ids));
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
            'nom' => $modele->nom,
            'niveau' => $modele->niveau?->code,
            'programme' => $modele->niveau?->programme?->code,
            'numero' => $modele->numero,
        ];
    }

    private function resoudreNiveau(array $ligne): ?Niveau
    {
        $programme = Programme::where('code', $ligne['programme'] ?? '')->first();

        if ($programme === null) {
            return null;
        }

        return Niveau::where('code', $ligne['niveau'] ?? '')
            ->where('programme_id', $programme->id)->first();
    }
}
