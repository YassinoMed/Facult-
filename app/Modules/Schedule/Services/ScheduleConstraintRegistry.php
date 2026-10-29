<?php

namespace App\Modules\Schedule\Services;

use App\Modules\Schedule\Models\TimetableConstraint;
use Illuminate\Support\Collection;

/**
 * PHASE 3 (Smart Timetables) — Registre centralisé des contraintes académiques.
 * Fournit les définitions de contraintes par défaut, gère la surcharge
 * par faculté, les activations/désactivations et les paramètres d'évaluation.
 */
class ScheduleConstraintRegistry
{
    /**
     * Dictionnaire canonique des contraintes système dures et souples.
     *
     * @return array<string, array<string, mixed>>
     */
    public function definitionsParDefaut(): array
    {
        return [
            // --- CONTRAINTES DURES (HARD) ---
            TimetableConstraint::HARD_TEACHER_CONFLICT => [
                'nom' => "Chevauchement d'horaire enseignant",
                'categorie' => TimetableConstraint::CATEGORIE_HARD,
                'description' => "Un enseignant (ou co-enseignant) ne peut dispenser qu'un seul cours simultanément.",
                'actif' => true,
                'poids' => 100,
                'parametres' => [],
            ],
            TimetableConstraint::HARD_ROOM_CONFLICT => [
                'nom' => "Chevauchement d'occupation de salle",
                'categorie' => TimetableConstraint::CATEGORIE_HARD,
                'description' => "Une salle physique ne peut accueillir qu'une seule séance compatible à la même heure.",
                'actif' => true,
                'poids' => 100,
                'parametres' => [],
            ],
            TimetableConstraint::HARD_GROUP_CONFLICT => [
                'nom' => 'Chevauchement de cours pour un groupe',
                'categorie' => TimetableConstraint::CATEGORIE_HARD,
                'description' => "Un groupe d'étudiants (ou sous-groupe) ne peut assister qu'à un seul enseignement simultanément.",
                'actif' => true,
                'poids' => 100,
                'parametres' => [],
            ],
            TimetableConstraint::HARD_ROOM_CAPACITY => [
                'nom' => 'Dépassement de la capacité de la salle',
                'categorie' => TimetableConstraint::CATEGORIE_HARD,
                'description' => "L'effectif du groupe ne peut excéder la capacité maximale autorisée de la salle.",
                'actif' => true,
                'poids' => 100,
                'parametres' => [],
            ],
            TimetableConstraint::HARD_TEACHER_UNAVAILABLE => [
                'nom' => "Indisponibilité déclarée d'un enseignant",
                'categorie' => TimetableConstraint::CATEGORIE_HARD,
                'description' => "Aucun cours ne peut être programmé sur un créneau d'indisponibilité officielle de l'enseignant.",
                'actif' => true,
                'poids' => 100,
                'parametres' => [],
            ],
            TimetableConstraint::HARD_ROOM_UNAVAILABLE => [
                'nom' => 'Salle indisponible ou fermée',
                'categorie' => TimetableConstraint::CATEGORIE_HARD,
                'description' => 'Aucune séance ne peut être planifiée dans une salle déclarée fermée ou en maintenance.',
                'actif' => true,
                'poids' => 100,
                'parametres' => [],
            ],
            TimetableConstraint::HARD_MISSING_EQUIPMENT => [
                'nom' => 'Équipements pédagogiques indispensables manquants',
                'categorie' => TimetableConstraint::CATEGORIE_HARD,
                'description' => 'La salle affectée doit comporter tous les équipements matériels requis par la séance.',
                'actif' => true,
                'poids' => 100,
                'parametres' => [],
            ],
            TimetableConstraint::HARD_TEACHER_QUALIFICATION => [
                'nom' => "Enseignant non qualifié pour l'enseignement",
                'categorie' => TimetableConstraint::CATEGORIE_HARD,
                'description' => "L'enseignant doit posséder les qualifications ou habilitations requises pour la matière.",
                'actif' => true,
                'poids' => 100,
                'parametres' => [],
            ],
            TimetableConstraint::HARD_ORPHAN_SLOT => [
                'nom' => 'Séance sans enseignant affecté',
                'categorie' => TimetableConstraint::CATEGORIE_HARD,
                'description' => 'Tout créneau horaire validé doit comporter au moins un enseignant responsable.',
                'actif' => true,
                'poids' => 100,
                'parametres' => [],
            ],
            TimetableConstraint::HARD_INSTITUTIONAL_PAUSE => [
                'nom' => 'Séance sur pause institutionnelle obligatoire',
                'categorie' => TimetableConstraint::CATEGORIE_HARD,
                'description' => 'Interdiction de planifier des cours pendant les pauses institutionnelles obligatoires.',
                'actif' => true,
                'poids' => 100,
                'parametres' => [],
            ],
            TimetableConstraint::HARD_ROOM_OPENING_HOURS => [
                'nom' => "Séance hors horaires d'ouverture de la salle",
                'categorie' => TimetableConstraint::CATEGORIE_HARD,
                'description' => "La séance doit s'inscrire strictement dans la plage d'ouverture du bâtiment et de la salle.",
                'actif' => true,
                'poids' => 100,
                'parametres' => [],
            ],

            // --- CONTRAINTES SOUPLES (SOFT) ---
            TimetableConstraint::SOFT_STUDENT_IDLE_GAPS => [
                'nom' => "Minimisation des temps d'attente (trous) étudiants",
                'categorie' => TimetableConstraint::CATEGORIE_SOFT,
                'description' => 'Évite les fenêtres creuses inutiles entre les cours dans la journée des étudiants.',
                'actif' => true,
                'poids' => 10,
                'parametres' => [
                    'seuil_pause_minutes' => 30,
                    'penalite_par_30m' => 5,
                ],
            ],
            TimetableConstraint::SOFT_TEACHER_IDLE_GAPS => [
                'nom' => "Minimisation des temps d'attente (trous) enseignants",
                'categorie' => TimetableConstraint::CATEGORIE_SOFT,
                'description' => "Limite les heures d'attente isolées entre deux cours dispensés par un même enseignant.",
                'actif' => true,
                'poids' => 8,
                'parametres' => [
                    'seuil_pause_minutes' => 30,
                    'penalite_par_30m' => 4,
                ],
            ],
            TimetableConstraint::SOFT_MAX_CONSECUTIVE_HOURS => [
                'nom' => "Plafond d'heures consécutives sans pause",
                'categorie' => TimetableConstraint::CATEGORIE_SOFT,
                'description' => 'Prévient la fatigue cognitive en plafonnant les cours ininterrompus sans pause.',
                'actif' => true,
                'poids' => 15,
                'parametres' => [
                    'max_heures' => 4,
                    'penalite_par_heure_supp' => 10,
                ],
            ],
            TimetableConstraint::SOFT_MAX_DAILY_HOURS => [
                'nom' => "Plafond de charge quotidienne d'heures",
                'categorie' => TimetableConstraint::CATEGORIE_SOFT,
                'description' => "Limite le nombre total d'heures programmées au cours d'une même journée.",
                'actif' => true,
                'poids' => 12,
                'parametres' => [
                    'max_heures_etudiant' => 8,
                    'max_heures_enseignant' => 8,
                    'penalite_par_heure_supp' => 10,
                ],
            ],
            TimetableConstraint::SOFT_TEACHER_PREFERENCE => [
                'nom' => 'Respect des préférences horaires des enseignants',
                'categorie' => TimetableConstraint::CATEGORIE_SOFT,
                'description' => "Favorise la programmation sur les créneaux déclarés comme souhaités par l'enseignant.",
                'actif' => true,
                'poids' => 10,
                'parametres' => [
                    'penalite_cours_hors_souhait' => 8,
                ],
            ],
            TimetableConstraint::SOFT_BUILDING_TRANSIT => [
                'nom' => 'Délai de battement pour transit inter-bâtiments',
                'categorie' => TimetableConstraint::CATEGORIE_SOFT,
                'description' => "Garantit un temps de trajet suffisant lors d'un changement de bâtiment successif.",
                'actif' => true,
                'poids' => 10,
                'parametres' => [
                    'temps_transit_min' => 15,
                    'penalite_transit_insuffisant' => 15,
                ],
            ],
            TimetableConstraint::SOFT_ROOM_OVERSIZED => [
                'nom' => 'Prévention des salles surdimensionnées',
                'categorie' => TimetableConstraint::CATEGORIE_SOFT,
                'description' => "Pénalise l'occupation d'un grand amphithéâtre par un groupe à effectif très réduit.",
                'actif' => true,
                'poids' => 5,
                'parametres' => [
                    'ratio_seuil' => 2.5,
                    'seuil_capacite_min' => 60,
                    'penalite_surdimensionnement' => 5,
                ],
            ],
            TimetableConstraint::SOFT_DAILY_EQUILIBRIUM => [
                'nom' => 'Équilibre hebdomadaire de la charge journalière',
                'categorie' => TimetableConstraint::CATEGORIE_SOFT,
                'description' => 'Répartit harmonieusement les cours sur la semaine pour éviter les jours surchargés ou vides.',
                'actif' => true,
                'poids' => 8,
                'parametres' => [
                    'ecart_max_tolere_heures' => 4,
                    'penalite_par_heure_ecart' => 5,
                ],
            ],
        ];
    }

    /**
     * Initialise les contraintes en base pour une faculté si absentes.
     *
     * @return Collection<int, TimetableConstraint>
     */
    public function initialiserPourFaculte(?int $faculteId = null): Collection
    {
        $defs = $this->definitionsParDefaut();
        $creees = collect();

        foreach ($defs as $code => $def) {
            $constraint = TimetableConstraint::firstOrCreate(
                ['faculte_id' => $faculteId, 'code' => $code],
                [
                    'nom' => $def['nom'],
                    'categorie' => $def['categorie'],
                    'description' => $def['description'],
                    'actif' => $def['actif'],
                    'poids' => $def['poids'],
                    'parametres' => $def['parametres'],
                ]
            );
            $creees->push($constraint);
        }

        return $creees;
    }

    /**
     * Récupère la collection des contraintes pour une faculté donnée,
     * fusionnée avec les définitions par défaut si non enregistrées en base.
     *
     * @return Collection<string, TimetableConstraint> Indexé par code
     */
    public function obtenirContraintes(?int $faculteId = null): Collection
    {
        $enBase = TimetableConstraint::pourFaculte($faculteId)->get()->keyBy('code');
        $defs = $this->definitionsParDefaut();
        $resultat = collect();

        foreach ($defs as $code => $def) {
            if ($enBase->has($code)) {
                $resultat->put($code, $enBase->get($code));
            } else {
                // Modèle virtuel en mémoire avec les valeurs par défaut
                $virtuel = new TimetableConstraint([
                    'faculte_id' => $faculteId,
                    'code' => $code,
                    'nom' => $def['nom'],
                    'categorie' => $def['categorie'],
                    'description' => $def['description'],
                    'actif' => $def['actif'],
                    'poids' => $def['poids'],
                    'parametres' => $def['parametres'],
                ]);
                $resultat->put($code, $virtuel);
            }
        }

        return $resultat;
    }

    public function estActive(string $code, ?int $faculteId = null): bool
    {
        $contrainte = $this->obtenirContraintes($faculteId)->get($code);

        return $contrainte?->actif ?? true;
    }

    public function getPoids(string $code, ?int $faculteId = null): int
    {
        $contrainte = $this->obtenirContraintes($faculteId)->get($code);

        return $contrainte?->poids ?? 10;
    }

    /**
     * @return array<string, mixed>
     */
    public function getParametres(string $code, ?int $faculteId = null): array
    {
        $contrainte = $this->obtenirContraintes($faculteId)->get($code);
        $def = $this->definitionsParDefaut()[$code]['parametres'] ?? [];

        return array_merge($def, $contrainte?->parametres ?? []);
    }

    public function activerContrainte(string $code, ?int $faculteId = null): TimetableConstraint
    {
        return $this->modifierContrainte($code, ['actif' => true], $faculteId);
    }

    public function desactiverContrainte(string $code, ?int $faculteId = null): TimetableConstraint
    {
        return $this->modifierContrainte($code, ['actif' => false], $faculteId);
    }

    public function definirPoids(string $code, int $poids, ?int $faculteId = null): TimetableConstraint
    {
        $poids = max(1, min(100, $poids));

        return $this->modifierContrainte($code, ['poids' => $poids], $faculteId);
    }

    /**
     * @param  array<string, mixed>  $parametres
     */
    public function mettreAJourParametres(string $code, array $parametres, ?int $faculteId = null): TimetableConstraint
    {
        return $this->modifierContrainte($code, ['parametres' => $parametres], $faculteId);
    }

    /**
     * @param  array<string, mixed>  $attributs
     */
    private function modifierContrainte(string $code, array $attributs, ?int $faculteId = null): TimetableConstraint
    {
        $def = $this->definitionsParDefaut()[$code] ?? null;
        if ($def === null) {
            throw new \InvalidArgumentException("Code de contrainte « {$code} » inconnu.");
        }

        $contrainte = TimetableConstraint::firstOrNew([
            'faculte_id' => $faculteId,
            'code' => $code,
        ]);

        if (! $contrainte->exists) {
            $contrainte->nom = $def['nom'];
            $contrainte->categorie = $def['categorie'];
            $contrainte->description = $def['description'];
            $contrainte->actif = $def['actif'];
            $contrainte->poids = $def['poids'];
            $contrainte->parametres = $def['parametres'];
        }

        $contrainte->fill($attributs);
        $contrainte->save();

        return $contrainte;
    }
}
