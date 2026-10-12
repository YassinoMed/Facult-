<?php

namespace App\Services\DataExchange\Definitions;

use App\Models\Matiere;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Support\Recherche;
use Illuminate\Http\Request;

/**
 * Définition matières (§5/§26) : identifiant = code unique.
 */
class MatiereDefinition implements ExportDefinitionInterface, ImportDefinitionInterface, ImportValidationAvancee
{
    public function entite(): string
    {
        return 'matieres';
    }

    public function libelle(): string
    {
        return 'Matières';
    }

    public function colonnes(): array
    {
        return ['code', 'nom', 'coefficient', 'credits', 'niveau', 'semestre'];
    }

    public function colonnesRequises(): array
    {
        return ['code'];
    }

    public function aliasesColonnes(): array
    {
        return [
            'nom' => ['libelle', 'designation', 'intitule', 'titre'],
        ];
    }

    public function regles(): array
    {
        return [
            'code' => 'required|string|max:20',
            'nom' => 'nullable|string|max:150',
            'coefficient' => 'nullable|numeric|min:0|max:99',
            'credits' => 'nullable|integer|min:0|max:60',
            'niveau' => 'nullable|string|max:10',
            'semestre' => 'nullable|string|max:10',
        ];
    }

    public function cleDoublon(): array
    {
        return ['code'];
    }

    public function validerLigneMetier(array $ligne, ?int $auteurId): array
    {
        $erreurs = [];

        // INV-10 (audit 08/10/2026) : niveau contrôlé, jamais de valeur
        // libre silencieuse dans le catalogue.
        $niveau = mb_strtoupper(trim((string) ($ligne['niveau'] ?? '')));
        $niveauxAdmis = ['L1', 'L2', 'L3', 'M1', 'M2', 'D1', 'D2', 'D3'];

        if ($niveau !== '' && ! in_array($niveau, $niveauxAdmis, true)) {
            $erreurs[] = [
                'champ' => 'niveau',
                'message' => "Niveau « {$niveau} » inconnu (L1, L2, L3, M1, M2, D1, D2, D3).",
                'code' => 'NIVEAU_INCONNU',
            ];
        }

        return $erreurs;
    }

    public function cleMetier(array $ligne): ?string
    {
        return Matiere::normaliserCode($ligne['code'] ?? null);
    }

    public function trouverExistant(array $ligne): ?object
    {
        // INV-9 : résolution canonique par code normalisé (jamais brute).
        return Matiere::resoudreParCode($ligne['code'] ?? null);
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        $donnees = [
            'nom' => $ligne['nom'],
            'coefficient' => $ligne['coefficient'] !== null ? (float) $ligne['coefficient'] : 1,
            'credits' => $ligne['credits'] !== null ? (int) $ligne['credits'] : 0,
            'niveau' => $ligne['niveau'] ?? 'L1',
            'semestre' => $ligne['semestre'] !== null ? (int) $ligne['semestre'] : 1,
        ];

        $existant = $this->trouverExistant($ligne);

        if ($existant !== null) {
            $existant->update($donnees);

            return "mise à jour : {$ligne['code']}";
        }

        Matiere::create(['code' => Matiere::normaliserCode($ligne['code'])] + $donnees);

        return "créée : {$ligne['code']}";
    }

    public function modes(): array
    {
        return ['creer', 'upsert'];
    }

    public function vider(?int $auteurId): void
    {
        // Jamais de vidage : notes et créneaux rattachés.
    }

    public function formats(): array
    {
        return ['csv', 'xlsx', 'json', 'pdf'];
    }

    public function requete(Request $request)
    {
        // INV-6 : périmètre faculté (transversales + ECUE de la faculté).
        return Matiere::pourFaculte($request->user()->faculteRestreinteId())->orderBy('code');
    }

    public function filtrer($query, Request $request)
    {
        if ($ids = $request->input('ids')) {
            $query->whereIn('id', array_map('intval', (array) $ids));
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
            'coefficient' => $modele->coefficient,
            'credits' => $modele->credits,
            'niveau' => $modele->niveau,
            'semestre' => $modele->semestre,
        ];
    }
}
