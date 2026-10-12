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

    public function __construct(public readonly Etudiant $etudiant) {}
}
