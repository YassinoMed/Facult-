<?php

namespace App\Services\DataExchange\Contracts;

/**
 * Extension optionnelle : contexte serveur d'un import (ex.
 * ['epreuve_id' => 12]). Le contexte est posé par l'appelant depuis
 * la route (jamais depuis le fichier ni le navigateur) : preview,
 * confirmation et job l'appliquent avant toute validation.
 * 100 % rétrocompatible (ignoré si non implémenté).
 */
interface ImportContextuel
{
    /**
     * @param  array<string, mixed>  $contexte
     */
    public function definirContexte(array $contexte): void;
}
