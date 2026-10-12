<?php

namespace App\Models;

use App\Modules\Evaluation\Models\EvaluationCompostage;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Etudiant extends Model
{
    use HasFactory;

    protected $table = 'etudiants';

    protected $fillable = [
        'cin',
        'identifiant_national', // LOOP 42 : matricule national MENS réconcilié à l'import (nullable, sans unique).
        'password',
        'nom',
        'prenom',
        'email',
        'telephone',
        'date_naissance',
        'sexe',
        'faculte_id',
        'groupe_id', // Module Schedule (additif, nullable) : groupe/classe.
        'programme_id', // Phase 2 (additif, nullable) : rattachement LMD.
        'niveau_id', // Phase 2 (additif, nullable) : rattachement LMD.
        'niveau',
        'annee_academique',
        'adresse',
        'ville',
        'code_postal',
        'annee_bac',
        'mention_bac',
    ];

    /**
     * Statuts métier du dossier étudiant (enum SQL).
     */
    public const STATUTS = ['en_attente', 'valide', 'rejete'];

    protected $attributes = [
        'statut' => 'en_attente',
    ];

    /**
     * Changement EXCLUSIF de statut (audit §P3-17) : `statut` et
     * `num_inscription` ne sont plus mass-assignables — toute transition
     * passe par cette méthode validée (jamais depuis une entrée HTTP
     * brute, jamais devinable côté client).
     */
    public function definirStatut(string $statut): void
    {
        if (! in_array($statut, self::STATUTS, true)) {
            throw new \InvalidArgumentException("Statut étudiant inconnu : {$statut}.");
        }

        $this->statut = $statut;
        $this->save();
    }

    /**
     * Jamais exposé en tableau/JSON (protection contre la fuite de hachages).
     */
    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'date_naissance' => 'date',
        'password' => 'hashed',
    ];

    protected static function booted(): void
    {
        static::creating(function (Etudiant $etudiant) {
            if (empty($etudiant->num_inscription)) {
                $etudiant->num_inscription = static::genererNumInscription();
            }
        });
    }

    /**
     * Génère un numéro d'inscription unique séquentiel par année : INS-YYYY-00001
     */
    public static function genererNumInscription(): string
    {
        $annee = date('Y');
        $prefix = "INS-{$annee}-";

        // Recherche du dernier numéro enregistré pour l'année en cours
        $dernier = static::where('num_inscription', 'like', "{$prefix}%")
            ->orderByDesc('num_inscription')
            ->value('num_inscription');

        if ($dernier) {
            $numero = (int) substr($dernier, strlen($prefix)) + 1;
        } else {
            $numero = 1;
        }

        return sprintf('%s%05d', $prefix, $numero);
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class);
    }

    /**
     * Module Schedule (additif) : groupe/classe de l'étudiant.
     */
    public function groupe(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'groupe_id');
    }

    /**
     * Phase 2 : rattachement LMD (programme / niveau référentiels).
     */
    public function programmeRef(): BelongsTo
    {
        return $this->belongsTo(Programme::class, 'programme_id');
    }

    public function programme(): BelongsTo
    {
        return $this->programmeRef();
    }

    public function niveauRef(): BelongsTo
    {
        return $this->belongsTo(Niveau::class, 'niveau_id');
    }

    public function niveauRelation(): BelongsTo
    {
        return $this->niveauRef();
    }

    public function affectations(): HasMany
    {
        return $this->hasMany(AffectationGroupe::class)->orderByDesc('date_debut');
    }

    public function situations(): HasMany
    {
        return $this->hasMany(SituationEtudiant::class)->orderByDesc('date_effet');
    }

    /**
     * Suspension active (date d'effet passée, sans fin ou fin future).
     * whereDate : Eloquent stocke les attributs `date` au format
     * datetime (tronqué par MySQL, texte brut sous SQLite).
     */
    public function estSuspendu(): bool
    {
        $aujourdhui = now()->toDateString();

        // Relation déjà chargée (bulk) : même règle, zéro requête.
        if ($this->relationLoaded('situations')) {
            return $this->situations->contains(fn ($situation) => $situation->type === 'suspension'
                && $situation->date_effet?->toDateString() <= $aujourdhui
                && ($situation->date_fin === null || $situation->date_fin->toDateString() >= $aujourdhui));
        }

        return $this->situations()
            ->where('type', 'suspension')
            ->whereDate('date_effet', '<=', $aujourdhui)
            ->where(fn ($q) => $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', $aujourdhui))
            ->exists();
    }

    /* ── Relations Archives Académiques ── */

    public function dossierAcademique()
    {
        return $this->hasOne(DossierAcademique::class);
    }

    public function inscriptionsAcademiques()
    {
        return $this->hasMany(InscriptionAcademique::class);
    }

    public function notesAcademiques()
    {
        return $this->hasMany(Note::class);
    }

    public function documentsAcademiques()
    {
        return $this->hasMany(DocumentAcademique::class);
    }

    public function compostages()
    {
        return $this->hasMany(EvaluationCompostage::class);
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(DossierCorrection::class);
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(DossierVerification::class);
    }

    /**
     * CIN masquée pour les listes / résultats de recherche : « BK***456 ».
     */
    /**
     * E-mail masqué pour la modale dossier existant : « med.yassine@proton.me »
     *
     * devient « med******@proton.me ». Domaine conservé (routage),
     * local réduit aux 3 premiers caractères. Jamais l'e-mail complet.
     */
    public function getEmailMasqueAttribute(): string
    {
        $email = (string) $this->email;

        if (! str_contains($email, '@')) {
            return '******';
        }

        [$local, $domaine] = explode('@', $email, 2);

        return mb_substr($local, 0, min(3, mb_strlen($local))).'******@'.$domaine;
    }

    public function getCinMasqueeAttribute(): string
    {
        if ($this->cin === null || $this->cin === '') {
            return '—';
        }

        return mb_substr($this->cin, 0, 2).'***'.mb_substr($this->cin, -3);
    }

    public function getNomCompletAttribute(): string
    {
        return $this->prenom.' '.$this->nom;
    }

    public function getStatutBadgeAttribute(): string
    {
        return match ($this->statut) {
            'en_attente' => '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 border border-amber-200"><span class="h-1.5 w-1.5 rounded-full bg-amber-500 animate-pulse"></span>En attente</span>',
            'valide' => '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 border border-emerald-200"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>Validé</span>',
            'rejete' => '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-rose-100 text-rose-800 border border-rose-200"><span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span>Rejeté</span>',
            default => '<span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-gray-100 text-gray-800">Inconnu</span>',
        };
    }

    public function getStatutLabelAttribute(): string
    {
        return match ($this->statut) {
            'en_attente' => 'En attente',
            'valide' => 'Validé',
            'rejete' => 'Rejeté',
            default => 'Inconnu',
        };
    }
}
