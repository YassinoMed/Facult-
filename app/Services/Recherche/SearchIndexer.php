<?php

namespace App\Services\Recherche;

use App\Models\Etudiant;
use App\Models\SearchDocument;

/**
 * Phase 18 §18.1 : indexation incrémentale (jobs en file) +
 * réindexation complète en streaming + détection de dérive.
 */
final class SearchIndexer
{
    public function indexerEtudiant(Etudiant $etudiant): void
    {
        $etudiant->loadMissing('faculte');

        SearchDocument::updateOrCreate(
            ['type_entite' => 'etudiant', 'entite_id' => $etudiant->id],
            [
                'titre' => trim($etudiant->prenom.' '.$etudiant->nom),
                'sous_titre' => 'N° '.($etudiant->num_inscription ?? '—'),
                'corps_normalise' => SearchNormalizer::normaliser(
                    implode(' ', array_filter([$etudiant->nom, $etudiant->prenom, $etudiant->nom_ar, $etudiant->prenom_ar]))
                ),
                'mots_cles' => (string) ($etudiant->programmeRef?->nom ?? ''),
                // CIN indexée en exact uniquement, affichée masquée (§18.2).
                'identifiants' => implode(' ', array_filter([
                    $etudiant->num_inscription, $etudiant->cin, $etudiant->email,
                ])),
                'faculte_id' => $etudiant->faculte_id,
                'permission_requise' => 'archives.view',
                'priorite' => 10,
                'entite_maj_le' => $etudiant->updated_at,
            ]
        );
    }

    public function retirer(string $type, int $id): void
    {
        SearchDocument::where('type_entite', $type)->where('entite_id', $id)->delete();
    }

    /**
     * Réindexation complète en streaming (curseur, jamais de
     * chargement complet). Retourne le nombre indexé.
     */
    public function reindexer(?string $entite = null): int
    {
        $total = 0;

        if ($entite === null || $entite === 'etudiants') {
            Etudiant::query()->orderBy('id')->chunkById(500, function ($etudiants) use (&$total) {
                foreach ($etudiants as $etudiant) {
                    $this->indexerEtudiant($etudiant);
                    $total++;
                }
            });
        }

        return $total;
    }

    /**
     * Dérive index vs base : entrées orphelines + entités non indexées.
     *
     * @return array{orphelins: int, manquants: int}
     */
    public function mesurerDerive(): array
    {
        $idsIndex = SearchDocument::where('type_entite', 'etudiant')->pluck('entite_id');
        $idsBase = Etudiant::pluck('id');

        return [
            'orphelins' => $idsIndex->diff($idsBase)->count(),
            'manquants' => $idsBase->diff($idsIndex)->count(),
        ];
    }

    public function purgerAnonymise(Etudiant $etudiant): void
    {
        // Rétention : l'anonymisation purge les champs indexés (§18.5/5).
        SearchDocument::where('type_entite', 'etudiant')->where('entite_id', $etudiant->id)->update([
            'titre' => 'Anonymisé', 'sous_titre' => null,
            'corps_normalise' => null, 'mots_cles' => null, 'identifiants' => null,
        ]);
    }
}
