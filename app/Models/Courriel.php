<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Journal technique d'un courriel (LOOP-MAIL-ENTERPRISE-01) : supervision
 * et audit, JAMAIS de contenu sensible (ni corps, ni OTP, ni jeton, ni
 * adresse en clair — seule une forme masquée à l'affichage et un
 * condensat SHA-256 pour la déduplication sont conservés).
 */
class Courriel extends Model
{
    protected $table = 'courriels';

    public const STATUT_EN_ATTENTE = 'pending';

    public const STATUT_EN_COURS = 'processing';

    public const STATUT_ACCEPTE_SMTP = 'accepted_by_smtp';

    public const STATUT_REESSAI = 'retrying';

    public const STATUT_ECHEC = 'failed';

    public const STATUT_ANNULE = 'cancelled';

    public const STATUTS = [
        self::STATUT_EN_ATTENTE,
        self::STATUT_EN_COURS,
        self::STATUT_ACCEPTE_SMTP,
        self::STATUT_REESSAI,
        self::STATUT_ECHEC,
        self::STATUT_ANNULE,
    ];

    public const STATUTS_FINAUX = [
        self::STATUT_ACCEPTE_SMTP,
        self::STATUT_ECHEC,
        self::STATUT_ANNULE,
    ];

    protected $fillable = [
        'cle_idempotence',
        'categorie',
        'modele',
        'version_modele',
        'statut',
        'tentatives',
        'categorie_erreur',
        'destinataire_masque',
        'destinataire_hash',
        'sujet',
        'correlation_id',
        'reference_metier',
        'acteur_admin_id',
        'envoye_le',
        'donnees',
    ];

    protected function casts(): array
    {
        return [
            'tentatives' => 'integer',
            'acteur_admin_id' => 'integer',
            'envoye_le' => 'datetime',
            'donnees' => 'array',
        ];
    }

    /**
     * Forme masquée d'une adresse pour l'affichage et le journal
     * (même convention que les masquages existants du projet).
     */
    public static function masquerDestinataire(string $adresse): string
    {
        $adresse = mb_strtolower(trim($adresse));
        $position = mb_strpos($adresse, '@');

        if ($position === false || $position < 2) {
            return '***';
        }

        return mb_substr($adresse, 0, 3).'******'.mb_substr($adresse, $position);
    }

    /**
     * Condensat de déduplication/recherche (aucune adresse en clair).
     */
    public static function condensatDestinataire(string $adresse): string
    {
        return hash('sha256', mb_strtolower(trim($adresse)));
    }

    public function estFinal(): bool
    {
        return in_array($this->statut, self::STATUTS_FINAUX, true);
    }

    public function peutEtreReessaye(): bool
    {
        return $this->statut === self::STATUT_ECHEC;
    }
}
