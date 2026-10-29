<?php

namespace App\Modules\AssistantIa\Contracts;

use App\Modules\AssistantIa\DTO\DemandeIa;
use App\Modules\AssistantIa\DTO\ReponseIa;

/**
 * Contrat d'un fournisseur d'intelligence artificielle pour UniRegistre AI.
 * Permet l'interchangeabilité transparente entre fournisseurs distants (API)
 * et locaux (Ollama / vLLM / LM-Studio).
 */
interface FournisseurIaInterface
{
    /**
     * Envoie une demande structurée au modèle et retourne la réponse normalisée.
     */
    public function genererReponse(DemandeIa $demande): ReponseIa;

    /**
     * Vérifie la disponibilité et la santé de la connexion au fournisseur.
     */
    public function estDisponible(): bool;

    /**
     * Identifiant canonique du fournisseur (ex: 'faux', 'distant_rest', 'local_ollama').
     */
    public function nomFournisseur(): string;

    /**
     * Modèle courant utilisé par l'adaptateur.
     */
    public function nomModele(): string;
}
