<?php

namespace App\Services\DataExchange\Definitions;

use App\Models\MensReference;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\Io\IoException;
use App\Services\NormalisationService;

/**
 * Référentiel MENS (mission inscription intelligente) : import contrôlé
 * du fichier officiel dans `mens_references` via le pipeline
 * DataExchange (upload → validation → preview → import → rapport).
 * Clé = [CIN normalisé, année] ; réimport idempotent (upsert).
 */
class MensImportDefinition implements ImportDefinitionInterface
{
    public function entite(): string
    {
        return 'referentiel-mens';
    }

    public function libelle(): string
    {
        return 'Référentiel MENS';
    }

    public function colonnes(): array
    {
        return [
            'cin', 'date_naissance', 'nom', 'prenom', 'nom_ar', 'prenom_ar',
            'sexe', 'nationalite', 'matricule_national', 'etablissement',
            'annee', 'programme_code', 'niveau',
        ];
    }

    public function colonnesRequises(): array
    {
        return ['cin', 'nom', 'prenom', 'annee'];
    }

    public function regles(): array
    {
        return [
            'cin' => ['required', 'string', 'max:30', NormalisationService::REGLE_CIN],
            'date_naissance' => 'nullable|date',
            'nom' => 'required|string|max:100',
            'prenom' => 'required|string|max:100',
            'nom_ar' => 'nullable|string|max:100',
            'prenom_ar' => 'nullable|string|max:100',
            'sexe' => 'nullable|in:M,F',
            'nationalite' => 'nullable|string|max:50',
            'matricule_national' => 'nullable|string|max:30',
            'etablissement' => 'nullable|string|max:150',
            'annee' => 'required|string|max:20',
            'programme_code' => 'nullable|string|max:30',
            'niveau' => 'nullable|in:L1,L2,L3,M1,M2,D1,D2,D3',
        ];
    }

    public function cleDoublon(): array
    {
        return ['cin', 'annee'];
    }

    public function trouverExistant(array $ligne): ?object
    {
        return MensReference::where('cin_normalise', MensReference::normaliserCin((string) $ligne['cin']))
            ->where('annee', $ligne['annee'])
            ->first();
    }

    /**
     * @param  array<string, mixed>  $ligne
     *
     * @throws IoException
     */
    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        $cinNormalise = MensReference::normaliserCin((string) $ligne['cin']);

        if ($cinNormalise === '') {
            throw IoException::malforme('CIN vide après normalisation.');
        }

        $donnees = [
            'cin_original' => mb_substr(trim((string) $ligne['cin']), 0, 30),
            'date_naissance' => $ligne['date_naissance'] ?? null,
            'nom' => $ligne['nom'],
            'prenom' => $ligne['prenom'],
            'nom_ar' => $ligne['nom_ar'] ?? null,
            'prenom_ar' => $ligne['prenom_ar'] ?? null,
            'sexe' => $ligne['sexe'] ?? null,
            'nationalite' => $ligne['nationalite'] ?? null,
            'matricule_national' => $ligne['matricule_national'] ?? null,
            'etablissement' => $ligne['etablissement'] ?? null,
            'programme_code' => $ligne['programme_code'] ?? null,
            'niveau' => $ligne['niveau'] ?? null,
            'imported_by' => $auteurId,
            'imported_at' => now(),
        ];

        $donnees['source_hash'] = MensReference::empreinte(
            ['cin' => $cinNormalise, 'annee' => $ligne['annee']] + array_filter($donnees, fn ($v) => $v !== null)
        );

        $existant = $this->trouverExistant($ligne);

        if ($existant !== null) {
            if ($mode === 'creer') {
                throw IoException::malforme("Référence {$cinNormalise} ({$ligne['annee']}) déjà importée.");
            }

            $existant->update($donnees);

            return "mise à jour : {$cinNormalise}";
        }

        MensReference::create([
            'cin_normalise' => $cinNormalise, 'annee' => $ligne['annee'],
        ] + $donnees);

        return "créée : {$cinNormalise}";
    }

    public function modes(): array
    {
        return ['creer', 'upsert'];
    }

    public function vider(?int $auteurId): void
    {
        // Jamais de vidage : traçabilité des campagnes (lignes historisées par année).
    }
}
