<?php

namespace App\Events;

use App\Models\Etudiant;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Point d'extension (§6) : le CORE annonce l'inscription, les modules
 * (notifications, statistiques, intégrations) réagissent via leurs
 * listeners — InscriptionController ne les connaît pas.
 */
class StudentRegistered
{
    use Dispatchable;

    /**
     * Les listeners écrivent en base (dossier GED, anomalies, notification) :
     * hors transaction ouverte le dispatch reste immédiat, mais tout
     * déplacement futur du dispatch dans une transaction sera protégé
     * (aucune écriture en cas de rollback).
     */
    public $afterCommit = true;

    public function __construct(public readonly Etudiant $etudiant) {}
}
