<?php

namespace App\Modules\AssistantIa\Drivers;

use App\Modules\AssistantIa\Contracts\FournisseurIaInterface;
use App\Modules\AssistantIa\DTO\AppelOutil;
use App\Modules\AssistantIa\DTO\DemandeIa;
use App\Modules\AssistantIa\DTO\ReponseIa;

/**
 * Fournisseur déterministe en mémoire pour les tests et le développement hors-ligne.
 * N'effectue AUCUNE requête réseau.
 */
class FauxFournisseurIa implements FournisseurIaInterface
{
    /**
     * @var array<int, ReponseIa> Réponses programmées à dépiler
     */
    protected array $reponsesFile = [];

    /**
     * @var array<int, DemandeIa> Historique des demandes reçues
     */
    protected array $demandesRecues = [];

    protected string $reponseParDefaut = 'Réponse synthétique de test UniRegistre AI.';

    protected string $modele = 'fake-model-v1';

    protected bool $disponible = true;

    public function __construct(string $modele = 'fake-model-v1')
    {
        $this->modele = $modele;
    }

    public function nomFournisseur(): string
    {
        return 'faux';
    }

    public function nomModele(): string
    {
        return $this->modele;
    }

    public function estDisponible(): bool
    {
        return $this->disponible;
    }

    public function definirDisponibilite(bool $disponible): self
    {
        $this->disponible = $disponible;

        return $this;
    }

    public function definirReponseParDefaut(string $texte): self
    {
        $this->reponseParDefaut = $texte;

        return $this;
    }

    public function empilerReponse(ReponseIa $reponse): self
    {
        $this->reponsesFile[] = $reponse;

        return $this;
    }

    public function empilerReponseTexte(string $texte, int $tokensPrompt = 15, int $tokensReponse = 25): self
    {
        $this->reponsesFile[] = ReponseIa::texte(
            texte: $texte,
            modele: $this->modele,
            tokensPrompt: $tokensPrompt,
            tokensReponse: $tokensReponse,
            latenceMs: 5.0,
            fournisseur: 'faux'
        );

        return $this;
    }

    /**
     * @param  array<int, AppelOutil>  $appelsOutils
     */
    public function empilerReponseOutils(array $appelsOutils, string $texte = ''): self
    {
        $this->reponsesFile[] = new ReponseIa(
            texte: $texte,
            appelsOutils: $appelsOutils,
            tokensPrompt: 20,
            tokensReponse: 30,
            latenceMs: 8.0,
            fournisseur: 'faux',
            modele: $this->modele
        );

        return $this;
    }

    public function genererReponse(DemandeIa $demande): ReponseIa
    {
        $this->demandesRecues[] = $demande;

        if (! empty($this->reponsesFile)) {
            return array_shift($this->reponsesFile);
        }

        $dernierMessage = $demande->dernierMessageUtilisateur();
        $contenu = $dernierMessage ? "UniRegistre AI : Écho contrôlé de '{$dernierMessage->contenu}'" : $this->reponseParDefaut;

        return ReponseIa::texte(
            texte: $contenu,
            modele: $this->modele,
            tokensPrompt: 20,
            tokensReponse: 30,
            latenceMs: 2.5,
            fournisseur: 'faux'
        );
    }

    /**
     * @return array<int, DemandeIa>
     */
    public function demandesRecues(): array
    {
        return $this->demandesRecues;
    }

    public function derniereDemande(): ?DemandeIa
    {
        return empty($this->demandesRecues) ? null : end($this->demandesRecues);
    }

    public function reinitialiser(): void
    {
        $this->demandesRecues = [];
        $this->reponsesFile = [];
    }
}
