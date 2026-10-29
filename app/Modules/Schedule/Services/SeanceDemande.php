<?php

namespace App\Modules\Schedule\Services;

use Illuminate\Contracts\Support\Arrayable;

/**
 * PHASE 4 (Smart Timetables) — Représentation d'une séance unitaire à placer
 * par le moteur de génération automatique.
 */
class SeanceDemande implements Arrayable
{
    /**
     * @param  string  $id  Identifiant unique de la demande de séance.
     * @param  int  $matiereId  Matière concernée.
     * @param  int|null  $enseignementId  Enseignement de rattachement.
     * @param  string  $type  Type de cours (course, td, tp, etc.).
     * @param  string  $titre  Libellé de la séance.
     * @param  int  $dureeMinutes  Durée requise (ex: 90 min pour 1h30).
     * @param  int|null  $teacherId  Enseignant imposé ou null si à allouer.
     * @param  array<int, int>  $coEnseignantsIds  Co-enseignants requis.
     * @param  int|null  $roomId  Salle imposée ou null si à allouer.
     * @param  int|null  $sousGroupeId  Sous-groupe éventuel (TP).
     * @param  array<int, string>  $equipementsRequis  Équipements obligatoires.
     * @param  string  $semaineParite  toutes, paire, impaire.
     * @param  array<int, int>|null  $semainesSpecifiques  Semaines spécifiques.
     * @param  int  $priorite  Degré de priorité (1 à 10).
     * @param  string|null  $typeSalleRequis  Type de salle (amphi, td, tp).
     */
    public function __construct(
        public readonly string $id,
        public readonly int $matiereId,
        public readonly ?int $enseignementId = null,
        public readonly string $type = 'course',
        public readonly string $titre = '',
        public readonly int $dureeMinutes = 90,
        public ?int $teacherId = null,
        public array $coEnseignantsIds = [],
        public ?int $roomId = null,
        public readonly ?int $sousGroupeId = null,
        public array $equipementsRequis = [],
        public readonly string $semaineParite = 'toutes',
        public readonly ?array $semainesSpecifiques = null,
        public readonly int $priorite = 5,
        public readonly ?string $typeSalleRequis = null,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'matiere_id' => $this->matiereId,
            'enseignement_id' => $this->enseignementId,
            'type' => $this->type,
            'titre' => $this->titre,
            'duree_minutes' => $this->dureeMinutes,
            'teacher_id' => $this->teacherId,
            'co_enseignants_ids' => $this->coEnseignantsIds,
            'room_id' => $this->roomId,
            'sous_groupe_id' => $this->sousGroupeId,
            'equipements_requis' => $this->equipementsRequis,
            'semaine_parite' => $this->semaineParite,
            'semaines_specifiques' => $this->semainesSpecifiques,
            'priorite' => $this->priorite,
            'type_salle_requis' => $this->typeSalleRequis,
        ];
    }
}
