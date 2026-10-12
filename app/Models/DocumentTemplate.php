<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 16 §16.2 : modèle de document versionné. Toute modification
 * crée une version immuable ; un document émis référence la version
 * exacte utilisée (jamais de mutation rétroactive).
 */
class DocumentTemplate extends Model
{
    protected $table = 'document_templates';

    protected $fillable = [
        'type', 'nom', 'locale', 'moteur', 'format', 'orientation',
        'statut', 'valide_du', 'valide_au', 'faculte_id', 'version_courante_id',
    ];

    protected function casts(): array
    {
        return ['valide_du' => 'date', 'valide_au' => 'date'];
    }

    /** @return HasMany<DocumentTemplateVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentTemplateVersion::class, 'document_template_id')->orderBy('version');
    }

    public function versionCourante(): ?DocumentTemplateVersion
    {
        return $this->versions()->where('id', $this->version_courante_id)->first()
            ?? $this->versions()->latest('version')->first();
    }

    public function estActif(): bool
    {
        if ($this->statut !== 'actif') {
            return false;
        }

        $aujourdhui = today();

        return ($this->valide_du === null || $this->valide_du <= $aujourdhui)
            && ($this->valide_au === null || $this->valide_au >= $aujourdhui);
    }
}
