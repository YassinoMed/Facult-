<?php

namespace App\Modules\StudentDossiers\Services;

use App\Models\Etudiant;
use App\Modules\StudentDossiers\Models\StudentDossier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Dossiers permanents (§1/§9) : un seul dossier par étudiant, référence
 * unique, création idempotente (contrainte UNIQUE en dernier rempart).
 */
class StudentDossierService
{
    /**
     * Retourne le dossier existant ou le crée (référence unique).
     */
    public function obtenirOuCreer(Etudiant $etudiant): StudentDossier
    {
        $existant = StudentDossier::where('etudiant_id', $etudiant->id)->first();

        if ($existant !== null) {
            return $existant;
        }

        return DB::transaction(function () use ($etudiant) {
            for ($tentative = 0; $tentative < 3; $tentative++) {
                try {
                    return StudentDossier::create([
                        'etudiant_id' => $etudiant->id,
                        'reference' => $this->genererReference(),
                        'status' => 'open',
                    ]);
                } catch (UniqueConstraintViolationException) {
                    continue;
                }
            }

            // Dernier rempart : un dossier existe forcément à ce stade.
            return StudentDossier::where('etudiant_id', $etudiant->id)->firstOrFail();
        });
    }

    private function genererReference(): string
    {
        return sprintf('DOS-%s-%06d', date('Y'), random_int(0, 999999));
    }
}
