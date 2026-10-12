<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LOOP vérificateur §15 : correction assistée avec validation.
 * Cycle : proposee → approuvee → appliquee (écriture + re-vérif),
 * ou proposee → rejetee. 4-eyes : approbateur ≠ proposeur.
 */
class DossierCorrection extends Model
{
    protected $table = 'dossier_corrections';

    public const STATUTS = ['proposee', 'approuvee', 'rejetee', 'appliquee'];

    /** Champs corrigeables : colonnes réelles uniquement (pas de
     *  lieu_naissance/nationalite : absents du schéma). */
    public const CHAMPS = [
        'nom', 'prenom', 'email', 'telephone', 'cin', 'sexe',
        'date_naissance', 'adresse', 'groupe_id', 'statut',
    ];

    /** Marqueur de cible : correction d'INSCRIPTION (payload JSON). */
    public const CHAMP_INSCRIPTION = 'correction_inscription';

    protected $fillable = [
        'etudiant_id', 'inscription_academique_id', 'champ', 'ancienne_valeur', 'nouvelle_valeur',
        'regle', 'motif', 'statut', 'propose_par', 'decide_par',
        'motif_decision', 'decide_le', 'appliquee_le',
    ];

    protected function casts(): array
    {
        return ['decide_le' => 'datetime', 'appliquee_le' => 'datetime'];
    }

    /** @return BelongsTo<Etudiant, $this> */
    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    public function inscriptionAcademique(): BelongsTo
    {
        return $this->belongsTo(InscriptionAcademique::class, 'inscription_academique_id');
    }

    public function estPourInscription(): bool
    {
        return $this->inscription_academique_id !== null;
    }
}
