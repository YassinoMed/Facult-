<?php

namespace App\Modules\Evaluation\Rules;

use App\Modules\Evaluation\Models\Epreuve;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Borne serveur de la saisie des notes (durcissement « max saisie »).
 *
 * La note est TOUJOURS recontrôlée côté serveur contre le barème de
 * l'épreuve (`note_max`) : le `max` HTML est une aide, jamais une
 * autorité. Cette règle refuse :
 * - un barème absent ou non strictement positif (impossible de juger) ;
 * - une valeur non numérique ou non finie (INF/NAN via notation
 *   exponentielle) ;
 * - une valeur hors de [0, note_max] ;
 * - une précision supérieure à deux décimales (la colonne SQL
 *   `decimal(5,2)` arrondirait silencieusement la note).
 *
 * Le service `SaisieNoteService` rejoue les mêmes contrôles : cette
 * règle est une première barrière, jamais la seule.
 */
final class NoteDansBareme implements ValidationRule
{
    public function __construct(private readonly ?Epreuve $epreuve = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_numeric($value)) {
            $fail('La note doit être un nombre.');

            return;
        }

        $nombre = (float) $value;

        if (! is_finite($nombre)) {
            $fail('La note doit être un nombre fini.');

            return;
        }

        $noteMax = $this->noteMax();

        if ($noteMax === null) {
            $fail('Barème de l’épreuve indisponible : la saisie des notes est bloquée.');

            return;
        }

        if ($nombre < 0 || $nombre > $noteMax) {
            $fail('La note doit être comprise entre 0 et '.$this->formater($noteMax).'.');

            return;
        }

        if (abs($nombre - round($nombre, 2)) > 1e-9) {
            $fail('La note doit comporter au maximum deux décimales.');
        }
    }

    private function noteMax(): ?float
    {
        if ($this->epreuve === null || $this->epreuve->note_max === null) {
            return null;
        }

        $noteMax = (float) $this->epreuve->note_max;

        return is_finite($noteMax) && $noteMax > 0 ? $noteMax : null;
    }

    private function formater(float $valeur): string
    {
        return rtrim(rtrim(number_format($valeur, 2, ',', ''), '0'), ',');
    }
}
