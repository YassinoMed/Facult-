<?php

namespace App\Contracts;

use App\Models\Etudiant;
use App\Modules\Attendance\Models\Presence;

/**
 * Contrat du module Attendance (§5/§9) : le reste de l'application
 * (widgets, API, futurs modules) dépend de cette interface, jamais
 * de l'implémentation. Binding dans ModuleServiceProvider.
 */
interface EnregistrementPresenceInterface
{
    /**
     * Crée ou met à jour le relevé (unicité étudiant/date/séance).
     */
    public function enregistrer(
        Etudiant $etudiant,
        string $date,
        string $statut,
        ?string $seance = null,
        ?string $motif = null,
        ?int $enregistrePar = null,
    ): Presence;

    /**
     * Taux de présence en % (present + retard comptent présent),
     * null si aucune séance enregistrée sur la période.
     */
    public function tauxPresence(Etudiant $etudiant, ?string $debut = null, ?string $fin = null): ?float;

    public function nombreSeances(Etudiant $etudiant, ?string $debut = null, ?string $fin = null): int;
}
