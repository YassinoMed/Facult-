<?php

namespace App\Modules\Finances\Models;

use App\Models\AnneeUniversitaire;
use App\Models\InscriptionAcademique;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LOOP-UNIREGISTRE-INSCRIPTIONS-PAIEMENTS-01 : reçu déposé pour un
 * dossier de paiement annuel.
 *
 * Un reçu est une DÉCLARATION à vérifier. Il ne devient une preuve de
 * paiement qu'après validation explicite d'un agent autorisé. Le fichier
 * est privé (`storage_path` masqué) et n'est jamais réutilisé d'une
 * inscription à l'autre : le service vérifie l'empreinte SHA-256.
 */
class RecuPaiement extends Model
{
    protected $table = 'recus_paiement';

    public const RECU_DEPOSE = 'RECU_DEPOSE';

    public const EN_VERIFICATION = 'EN_VERIFICATION';

    public const VALIDE = 'VALIDE';

    public const REFUSE = 'REFUSE';

    public const STATUTS = [
        self::RECU_DEPOSE,
        self::EN_VERIFICATION,
        self::VALIDE,
        self::REFUSE,
    ];

    protected $fillable = [
        'dossier_paiement_id',
        'inscription_academique_id',
        'annee_universitaire_id',
        'version',
        'storage_path',
        'original_filename',
        'mime_type',
        'extension',
        'size',
        'sha256',
        'montant_attendu_centimes',
        'source_montant',
        'echeance',
        'montant_declare_centimes',
        'reference_paiement',
        'date_paiement',
        'statut',
        'motif_refus',
        'commentaire',
        'depose_le',
        'pris_en_charge_par',
        'pris_en_charge_le',
        'traite_par',
        'traite_le',
        'est_valide',
    ];

    /** Le chemin privé n'est jamais exposé au client ni sérialisé. */
    protected $hidden = ['storage_path'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'size' => 'integer',
            'montant_attendu_centimes' => 'integer',
            'montant_declare_centimes' => 'integer',
            // Date pure (pas d'heure) : la comparaison anti-doublon
            // référence/date/montant doit être identique sur MySQL et SQLite.
            'date_paiement' => 'date:Y-m-d',
            'echeance' => 'date',
            'depose_le' => 'datetime',
            'pris_en_charge_le' => 'datetime',
            'traite_le' => 'datetime',
            'est_valide' => 'boolean',
        ];
    }

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(DossierPaiement::class, 'dossier_paiement_id');
    }

    public function inscription(): BelongsTo
    {
        return $this->belongsTo(InscriptionAcademique::class, 'inscription_academique_id');
    }

    public function anneeUniversitaire(): BelongsTo
    {
        return $this->belongsTo(AnneeUniversitaire::class, 'annee_universitaire_id');
    }

    public function prisEnChargePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pris_en_charge_par');
    }

    public function traitePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'traite_par');
    }

    public function estEnAttente(): bool
    {
        return in_array($this->statut, [self::RECU_DEPOSE, self::EN_VERIFICATION], true);
    }

    public function estValide(): bool
    {
        return $this->statut === self::VALIDE;
    }

    public function libelleStatut(): string
    {
        return (string) config(
            'inscriptions-paiements.statuts.'.$this->statut,
            $this->statut
        );
    }

    public function montantDeclareFormate(): string
    {
        return Facture::formater((int) $this->montant_declare_centimes);
    }

    public function montantAttenduFormate(): ?string
    {
        return $this->montant_attendu_centimes === null
            ? null
            : Facture::formater((int) $this->montant_attendu_centimes);
    }
}
