<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 16 §16.2 : signataire habilité (période de validité).
 */
class DocumentSignatory extends Model
{
    protected $table = 'document_signatories';

    protected $fillable = ['nom', 'titre', 'image_path', 'valide_du', 'valide_au', 'actif'];

    protected function casts(): array
    {
        return ['valide_du' => 'date', 'valide_au' => 'date', 'actif' => 'boolean'];
    }

    public function enVigueur(?\DateTimeInterface $date = null): bool
    {
        if (! $this->actif) {
            return false;
        }

        $jour = $date ? Carbon::parse($date)->startOfDay() : today();

        return ($this->valide_du === null || $this->valide_du <= $jour)
            && ($this->valide_au === null || $this->valide_au >= $jour);
    }
}
