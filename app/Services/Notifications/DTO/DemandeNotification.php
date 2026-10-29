<?php

namespace App\Services\Notifications\DTO;

use App\Models\Etudiant;
use App\Models\User;
use App\Modules\Schedule\Models\Teacher;

/**
 * Objet de transfert (DTO) pour une demande de notification multicanale unifiée.
 */
class DemandeNotification
{
    /**
     * @param  Etudiant|User|Teacher  $destinataire  Cible de la notification
     * @param  string  $categorie  Catégorie de préférence (ex: 'resultats', 'inscriptions', 'demandes', 'annonces')
     * @param  string  $evenement  Type ou identifiant de l'événement (ex: 'deliberation.publiee')
     * @param  string  $titre  Titre de la notification
     * @param  string  $message  Corps du message
     * @param  string|null  $lien  Lien d'action éventuel
     * @param  array<int, string>  $canaux  Canaux cibles ('in_app', 'externe', 'outbox')
     * @param  array<string, mixed>  $donnees  Données contextuelles additionnelles
     * @param  int  $priorite  Niveau de priorité (0: normale, 1: moyenne, 2: haute, 3: urgente)
     * @param  string|null  $cleDeduplication  Clé d'idempotence pour éviter les doublons
     */
    public function __construct(
        public readonly Etudiant|User|Teacher $destinataire,
        public readonly string $categorie,
        public readonly string $evenement,
        public readonly string $titre,
        public readonly string $message,
        public readonly ?string $lien = null,
        public readonly array $canaux = ['in_app'],
        public readonly array $donnees = [],
        public readonly int $priorite = 0,
        public readonly ?string $cleDeduplication = null,
    ) {}
}
