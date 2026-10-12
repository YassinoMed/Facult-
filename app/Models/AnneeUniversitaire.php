<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class AnneeUniversitaire extends Model
{
    protected $table = 'annees_universitaires';

    protected $fillable = [
        'libelle',
        'date_debut',
        'date_fin',
        'active',
    ];

    protected $casts = [
        'date_debut' => 'date',
        'date_fin' => 'date',
        'active' => 'boolean',
    ];

    protected static function booted(): void
    {
        // LOT 2 §11 : la garde d'unicité DB (`active_unique` = 'Y'
        // ssi active) est synchronisée à chaque écriture modèle —
        // les mises à jour massives doivent la poser explicitement.
        static::saving(function (self $annee) {
            $annee->active_unique = $annee->active ? 'Y' : null;
        });
    }

    public function inscriptions(): HasMany
    {
        return $this->hasMany(InscriptionAcademique::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(DocumentAcademique::class);
    }

    /**
     * L'année universitaire active (courante) du système.
     * Convention référentiel (§11) : `libelle` = code, `active` = courante.
     */
    public static function active(): ?self
    {
        return static::where('active', true)->first();
    }

    /**
     * Définit l'année courante — une seule à la fois, transactionnelle.
     * Les lignes sont verrouillées (`lockForUpdate`) pour sérialiser
     * deux activations concurrentes ; la contrainte unique
     * `active_unique` reste le dernier rempart (concurrence réelle).
     *
     * @throws \DomainException
     */
    public function definirCourante(): void
    {
        try {
            DB::transaction(function () {
                static::query()->lockForUpdate()->get();
                static::where('id', '!=', $this->id)->update(['active' => false, 'active_unique' => null]);
                $this->update(['active' => true]);
            });
        } catch (QueryException $e) {
            throw new \DomainException('Activation impossible : une autre année vient d’être activée simultanément.');
        }
    }

    public function precedente(): ?self
    {
        return static::where('date_fin', '<', $this->date_debut)->orderByDesc('date_fin')->first();
    }

    public function suivante(): ?self
    {
        return static::where('date_debut', '>', $this->date_fin)->orderBy('date_debut')->first();
    }
}
