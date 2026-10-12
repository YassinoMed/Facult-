<?php

namespace App\Services\Recherche;

use App\Models\SearchDocument;
use App\Models\User;

/**
 * Phase 18 §18.1/§18.2/§18.5 : moteur SGBD natif sur `search_documents`.
 * Filtrage permission+périmètre DANS la requête (jamais après coup) :
 * compteurs, facettes, suggestions et snippets ne voient que
 * l'autorisé. Repli identifiants exacts en base si l'index est en
 * retard ou en panne (§18.5 cas 7-8).
 */
final class DbSearchEngine implements SearchEngine
{
    public function estDisponible(): bool
    {
        try {
            SearchDocument::query()->limit(1)->count();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function chercher(User $utilisateur, string $requete, int $limite = 20): array
    {
        $requete = trim(mb_substr($requete, 0, 200));

        if (mb_strlen($requete) < 2 && ! $this->estIdentifiant($requete)) {
            return [];
        }

        $requete = SearchNormalizer::echapperOperateurs($requete);
        $normalise = SearchNormalizer::normaliser($requete);
        $mots = array_values(array_filter(explode(' ', $normalise)));
        $variantes = [];

        foreach ($mots as $mot) {
            $variantes = array_merge($variantes, SearchNormalizer::variantes($mot));
        }

        $variantes = array_values(array_unique(array_merge($variantes, [$normalise])));

        $query = SearchDocument::query()->limit($limite);

        // Sécurité DANS la requête : périmètre faculté + permission.
        $faculteId = $utilisateur->faculte_id ?? null;

        if ($faculteId !== null && ! $utilisateur->hasPermission('configuration.manage')) {
            $query->where(function ($q) use ($faculteId) {
                $q->whereNull('faculte_id')->orWhere('faculte_id', $faculteId);
            });
        }

        // Visibilité : les éléments exigeant une permission que
        // l'utilisateur n'a pas sont exclus ici même (pas de
        // « 1 résultat masqué » — §18.5 cas 11).
        $query->where(function ($q) use ($utilisateur) {
            $q->whereNull('permission_requise');
            foreach (['archives.view', 'notes.view', 'demandes.view', 'document.view', 'document.revoke', 'search.sensitive'] as $permission) {
                if ($utilisateur->hasPermission($permission)) {
                    $q->orWhere('permission_requise', $permission);
                }
            }
        });

        $query->where(function ($q) use ($variantes, $requete) {
            // Identifiant exact prioritaire (CIN 8 chiffres, n°
            // étudiant, REQ-/ATT-, email, téléphone).
            $q->where('identifiants', 'like', '%'.$requete.'%');

            foreach ($variantes as $variante) {
                $q->orWhere('titre', 'like', '%'.$variante.'%')
                    ->orWhere('sous_titre', 'like', '%'.$variante.'%')
                    ->orWhere('corps_normalise', 'like', '%'.$variante.'%')
                    ->orWhere('mots_cles', 'like', '%'.$variante.'%');
            }
        });

        // Classement : identifiant exact > titre > contenu ; boost
        // récence via entite_maj_le.
        $query->orderByRaw('CASE WHEN identifiants LIKE ? THEN 0 ELSE 1 END', ['%'.$requete.'%'])
            ->orderByDesc('priorite')
            ->orderByDesc('entite_maj_le');

        return $query->get()->map(fn (SearchDocument $d) => [
            'type_entite' => $d->type_entite,
            'entite_id' => $d->entite_id,
            'titre' => $d->titre,
            'sous_titre' => $d->sous_titre,
            'lien' => $d->lienAdmin(),
        ])->all();
    }

    private function estIdentifiant(string $requete): bool
    {
        return preg_match('/^(\d{6,10}|REQ-\d{4}-\d+|ATT-\d{4}-\d+|[A-Z]{2,4}-\d+)$/i', trim($requete)) === 1;
    }
}
