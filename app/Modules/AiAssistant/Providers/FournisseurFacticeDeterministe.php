<?php

namespace App\Modules\AiAssistant\Providers;

use App\Modules\AiAssistant\Contracts\AiFournisseur;
use App\Modules\AiAssistant\Data\AiDemande;
use App\Modules\AiAssistant\Data\AiReponse;

/**
 * Fournisseur factice DÉTERMINISTE (Lot 2) — réservé aux tests et à la
 * démonstration hors réseau. Aucun appel HTTP, aucun état, aucune clé.
 *
 * Une réponse forcée peut être injectée par les TESTS DE SÉCURITÉ
 * (Lot 12) : elle suit le parcours habituel de la passerelle pour
 * prouver que les contrôles serveur résistent à un contenu hostile du
 * modèle. Elle n'EST JAMAIS une instruction pour l'orchestrateur.
 */
final class FournisseurFacticeDeterministe implements AiFournisseur
{
    public function __construct(
        private ?string $reponseForcee = null,
        private array $forceSources = [],
    ) {}

    public function nom(): string
    {
        return 'factice';
    }

    public function modele(): string
    {
        return 'factice-deterministe';
    }

    public function generer(AiDemande $demande): AiReponse
    {
        $sources = array_map(
            fn (array $chunk) => ['titre' => (string) $chunk['titre'], 'chemin' => (string) $chunk['chemin']],
            $demande->contexte
        );

        if ($this->reponseForcee !== null) {
            return new AiReponse(
                texte: $this->reponseForcee,
                fournisseur: $this->nom(),
                modele: $this->modele(),
                latenceMs: 1,
                jetonsEntree: 1,
                jetonsSortie: 2,
                sources: $this->forceSources !== [] ? $this->forceSources : $sources,
                metadonnees: ['mode' => $demande->mode],
            );
        }

        // Réponse par défaut : la base renvoie Uniquement des extraits des
        // chunks fournis dans le contexte, JAMAIS de règle inventée.
        $premierExtrait = $demande->contexte !== []
            ? (string) $demande->contexte[0]['extrait']
            : null;

        $texte = $premierExtrait !== null
            ? "[deterministe] D'après la source citée : {$premierExtrait}"
            : '[deterministe] Aucune source pertinente. Réponse refusée plutôt qu\'inventée.';

        return new AiReponse(
            texte: $texte,
            fournisseur: $this->nom(),
            modele: $this->modele(),
            latenceMs: 3,
            jetonsEntree: 10,
            jetonsSortie: 20,
            sources: $sources,
            metadonnees: ['mode' => $demande->mode],
        );
    }
}
