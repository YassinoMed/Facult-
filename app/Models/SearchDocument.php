<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 18 §18.1 : entrée d'index (scope/visibilité embarqués pour un
 * filtrage sécurité dans la requête).
 */
class SearchDocument extends Model
{
    protected $table = 'search_documents';

    protected $fillable = [
        'type_entite', 'entite_id', 'titre', 'sous_titre', 'corps_normalise',
        'mots_cles', 'identifiants', 'locale', 'faculte_id', 'programme_code',
        'visibilite', 'permission_requise', 'priorite', 'entite_maj_le',
    ];

    protected function casts(): array
    {
        return ['entite_maj_le' => 'datetime'];
    }

    public function lienAdmin(): ?string
    {
        return match ($this->type_entite) {
            'etudiant' => route('admin.etudiants.show', $this->entite_id, false),
            'demande' => route('admin.demandes.show', $this->entite_id, false),
            'document' => route('admin.documents-center.show', $this->entite_id, false),
            default => null,
        };
    }
}
