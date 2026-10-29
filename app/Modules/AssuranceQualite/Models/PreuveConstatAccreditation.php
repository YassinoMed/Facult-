<?php

namespace App\Modules\AssuranceQualite\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lot 16 — Preuve reliée à un constat.
 *
 * Lien TYPE vers un artefact RÉEL du dépôt, dont l'EXISTENCE est vérifiée
 * à l'ajout (contrôleur) :
 * - `document`  → coffre StudentDossiers (`student_documents`) ;
 * - `anomalie`  → socle qualité (`anomalies_qualite`) ;
 * - `journal`   → journaux (`historique_academique`).
 *
 * `cible_libelle` est une COPIE DATÉE du libellé au moment de l'ajout :
 * si l'artefact source disparaît, la preuve reste lisible et traçable.
 */
class PreuveConstatAccreditation extends Model
{
    protected $table = 'preuves_constat_accreditation';

    public const TYPES = ['document', 'anomalie', 'journal'];

    /** @var array<int, string> */
    protected $fillable = [
        'constat_id', 'type', 'cible_id', 'cible_libelle', 'ajoute_par',
    ];

    public function constat(): BelongsTo
    {
        return $this->belongsTo(ConstatAccreditation::class, 'constat_id');
    }

    public function ajoutePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ajoute_par');
    }
}
