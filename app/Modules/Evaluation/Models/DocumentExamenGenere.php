<?php

namespace App\Modules\Evaluation\Models;

use App\Models\User;
use App\Modules\Schedule\Models\Room;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LOOP-EXAM-PREPARATION-01 (G3) : historique des documents d'examen
 * générés — une ligne par génération d'émargement (par épreuve, par
 * salle), base de l'aperçu, de la réimpression et de la traçabilité.
 * Aucune donnée personnelle : effectif et référence de fichier seuls.
 */
class DocumentExamenGenere extends Model
{
    protected $table = 'evaluation_documents_generes';

    public const TYPES = ['emargement', 'convocation'];

    protected $fillable = [
        'epreuve_id', 'salle_id', 'type', 'genere_par', 'candidats_count', 'nom_fichier',
    ];

    public function epreuve(): BelongsTo
    {
        return $this->belongsTo(Epreuve::class);
    }

    public function salle(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function generePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'genere_par');
    }
}
