<?php

namespace App\Models;

use App\Modules\Schedule\Models\Teacher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle de document personnel / officiel d'un enseignant (§5).
 * Stocké sur disque privé (storage/app/private), accès strictement vérifié anti-IDOR.
 */
class TeacherDocument extends Model
{
    protected $table = 'teacher_documents';

    protected $fillable = [
        'teacher_id',
        'faculte_id',
        'titre',
        'categorie',
        'nom_fichier',
        'chemin',
        'mime_type',
        'taille',
        'description',
        'publie_par',
    ];

    public const CATEGORIES = [
        'administratif' => 'Document administratif',
        'pedagogique' => 'Document pédagogique',
        'convocation' => 'Convocation officielle',
        'attestation' => 'Attestation & Décision',
        'decision' => 'Décision administrative',
        'autre' => 'Autre document',
    ];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class, 'faculte_id');
    }

    public function publiePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'publie_par');
    }

    public function categorieLibelle(): string
    {
        return self::CATEGORIES[$this->categorie] ?? ucfirst($this->categorie);
    }

    public function tailleFormatee(): string
    {
        if ($this->taille <= 0) {
            return '0 o';
        }
        $unites = ['o', 'Ko', 'Mo', 'Go'];
        $puissance = min((int) floor(log($this->taille, 1024)), count($unites) - 1);
        $valeur = $this->taille / (1024 ** $puissance);

        return round($valeur, 1).' '.$unites[$puissance];
    }
}
