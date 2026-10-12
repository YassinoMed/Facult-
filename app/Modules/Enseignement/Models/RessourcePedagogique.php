<?php

namespace App\Modules\Enseignement\Models;

use App\Models\Etudiant;
use App\Models\Matiere;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Support de cours (LOOP 06/14) : publié par un enseignant pour une ECUE
 * dont il a la charge, ciblant un groupe (NULL = tous les groupes).
 * Stockage privé, cycle serveur brouillon → publié → archivé.
 */
class RessourcePedagogique extends Model
{
    protected $table = 'ressources_pedagogiques';

    public const STATUTS = ['brouillon', 'publie', 'archive'];

    public const TRANSITIONS = [
        'brouillon' => ['publie'],
        'publie' => ['archive', 'brouillon'],
        'archive' => ['brouillon'],
    ];

    /** Extensions acceptées (cohérence MIME réel vérifiée à l'upload). */
    public const EXTENSIONS_AUTORISEES = [
        'pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx',
        'txt', 'md', 'csv', 'zip', 'png', 'jpg', 'jpeg',
    ];

    public const TAILLE_MAX_OCTETS = 20 * 1024 * 1024;

    protected $fillable = [
        'teacher_id', 'matiere_id', 'groupe_id', 'titre', 'description',
        'nom_fichier', 'chemin', 'mime', 'taille', 'statut',
    ];

    public function enseignant(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    public function matiere(): BelongsTo
    {
        return $this->belongsTo(Matiere::class);
    }

    public function groupe(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function estPubliee(): bool
    {
        return $this->statut === 'publie';
    }

    public function peutTransiterVers(string $cible): bool
    {
        return in_array($cible, self::TRANSITIONS[$this->statut] ?? [], true);
    }

    /**
     * Supports visibles pour un étudiant : statut `publie` ET (ciblage
     * sur son groupe OU tous-groupes d'une matière de l'un de ses
     * programmes inscrits). Fail-closed : sans groupe ni programme
     * inscrit, rien n'est visible.
     */
    public function scopeVisiblesPourEtudiant(Builder $query, Etudiant $etudiant): void
    {
        $programmes = $etudiant->inscriptionsAcademiques()->pluck('programme_id')->filter()->unique()->values()->all();

        $query->where('statut', 'publie');

        if ($etudiant->groupe_id === null && $programmes === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function ($q) use ($etudiant, $programmes) {
            if ($etudiant->groupe_id !== null) {
                $q->where('groupe_id', $etudiant->groupe_id);
            }

            if ($programmes !== []) {
                $q->orWhere(fn ($tous) => $tous
                    ->whereNull('groupe_id')
                    ->whereHas('matiere.ue.programme', fn ($p) => $p->whereIn('id', $programmes)));
            }
        });
    }
}
