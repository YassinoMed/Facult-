<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Destinataire individuel au sein d'une campagne de courriels (LOOP-MAIL-CAMPAIGNS-01).
 * Traçabilité individuelle, hachage anti-doublon et journalisation technique expurgée.
 */
class CampagneCourrielDestinataire extends Model
{
    protected $table = 'campagne_courriel_destinataires';

    public const STATUT_EN_ATTENTE = 'en_attente';

    public const STATUT_EN_COURS = 'en_cours';

    public const STATUT_ENVOYE = 'envoye';

    public const STATUT_ECHEC = 'echec';

    public const STATUT_ANNULE = 'annule';

    public const STATUT_IGNORE = 'ignore';

    public const STATUTS = [
        self::STATUT_EN_ATTENTE,
        self::STATUT_EN_COURS,
        self::STATUT_ENVOYE,
        self::STATUT_ECHEC,
        self::STATUT_ANNULE,
        self::STATUT_IGNORE,
    ];

    public const TYPE_ETUDIANT = 'etudiant';

    public const TYPE_ENSEIGNANT = 'enseignant';

    public const TYPE_EXTERNE = 'externe';

    protected $fillable = [
        'campagne_id',
        'type_destinataire',
        'reference_id',
        'nom_complet',
        'email',
        'email_hash',
        'statut',
        'tentatives',
        'derniere_erreur',
        'courriel_id',
        'correlation_id',
        'envoye_a',
        'donnees',
    ];

    protected function casts(): array
    {
        return [
            'tentatives' => 'integer',
            'envoye_a' => 'datetime',
            'donnees' => 'array',
        ];
    }

    public function campagne(): BelongsTo
    {
        return $this->belongsTo(CampagneCourriel::class, 'campagne_id');
    }

    public function courriel(): BelongsTo
    {
        return $this->belongsTo(Courriel::class, 'courriel_id');
    }

    /**
     * Masquage de l'adresse pour affichage conforme aux règles de vie privée.
     */
    public function emailMasque(): string
    {
        return Courriel::masquerDestinataire($this->email);
    }

    /**
     * Calcule le hash SHA-256 canonique d'une adresse email.
     */
    public static function hacherEmail(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }
}
