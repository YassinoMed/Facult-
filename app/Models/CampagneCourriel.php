<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Modèle de campagne de courriels (LOOP-MAIL-CAMPAIGNS-01).
 * Gestion du cycle de vie, machine à états stricte, idempotence,
 * agrégation des statistiques et traçabilité des lots.
 */
class CampagneCourriel extends Model
{
    protected $table = 'campagnes_courriel';

    public const STATUT_BROUILLON = 'brouillon';

    public const STATUT_PLANIFIEE = 'planifiee';

    public const STATUT_EN_ATTENTE = 'en_attente';

    public const STATUT_EN_COURS = 'en_cours';

    public const STATUT_TERMINEE = 'terminee';

    public const STATUT_TERMINEE_ERREURS = 'terminee_avec_erreurs';

    public const STATUT_ANNULEE = 'annulee';

    public const STATUT_ECHOUEE = 'echouee';

    public const STATUTS = [
        self::STATUT_BROUILLON,
        self::STATUT_PLANIFIEE,
        self::STATUT_EN_ATTENTE,
        self::STATUT_EN_COURS,
        self::STATUT_TERMINEE,
        self::STATUT_TERMINEE_ERREURS,
        self::STATUT_ANNULEE,
        self::STATUT_ECHOUEE,
    ];

    public const CIBLAGE_INDIVIDUEL = 'individuel';

    public const CIBLAGE_LISTE_FIXE = 'liste_fixe';

    public const CIBLAGE_DYNAMIQUE_ETUDIANTS = 'dynamique_etudiants';

    public const CIBLAGE_DYNAMIQUE_ENSEIGNANTS = 'dynamique_enseignants';

    public const CIBLAGE_GROUPE_ENREGISTRE = 'groupe_enregistre';

    public const CIBLAGES = [
        self::CIBLAGE_INDIVIDUEL,
        self::CIBLAGE_LISTE_FIXE,
        self::CIBLAGE_DYNAMIQUE_ETUDIANTS,
        self::CIBLAGE_DYNAMIQUE_ENSEIGNANTS,
        self::CIBLAGE_GROUPE_ENREGISTRE,
    ];

    /**
     * Matrice des transitions d'états valides.
     */
    private const TRANSITIONS = [
        self::STATUT_BROUILLON => [
            self::STATUT_BROUILLON,
            self::STATUT_PLANIFIEE,
            self::STATUT_EN_ATTENTE,
            self::STATUT_ANNULEE,
        ],
        self::STATUT_PLANIFIEE => [
            self::STATUT_BROUILLON,
            self::STATUT_EN_ATTENTE,
            self::STATUT_ANNULEE,
        ],
        self::STATUT_EN_ATTENTE => [
            self::STATUT_EN_COURS,
            self::STATUT_ANNULEE,
            self::STATUT_ECHOUEE,
        ],
        self::STATUT_EN_COURS => [
            self::STATUT_TERMINEE,
            self::STATUT_TERMINEE_ERREURS,
            self::STATUT_ANNULEE,
            self::STATUT_ECHOUEE,
        ],
        self::STATUT_TERMINEE_ERREURS => [
            self::STATUT_EN_ATTENTE, // En cas de relance sélective
            self::STATUT_ANNULEE,
        ],
        self::STATUT_ECHOUEE => [
            self::STATUT_EN_ATTENTE, // En cas de réessai complet
            self::STATUT_ANNULEE,
        ],
        self::STATUT_ANNULEE => [],
        self::STATUT_TERMINEE => [],
    ];

    protected $fillable = [
        'titre',
        'sujet',
        'type_ciblage',
        'modele_id',
        'modele_version',
        'contenu_texte',
        'contenu_html',
        'filtres',
        'groupe_enregistre_id',
        'statut',
        'cle_idempotence',
        'planifiee_a',
        'commencee_a',
        'terminee_a',
        'total_destinataires',
        'total_envoyes',
        'total_echecs',
        'total_ignores',
        'taille_lot',
        'auteur_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'filtres' => 'array',
            'meta' => 'array',
            'planifiee_a' => 'datetime',
            'commencee_a' => 'datetime',
            'terminee_a' => 'datetime',
            'total_destinataires' => 'integer',
            'total_envoyes' => 'integer',
            'total_echecs' => 'integer',
            'total_ignores' => 'integer',
            'taille_lot' => 'integer',
        ];
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }

    public function modele(): BelongsTo
    {
        return $this->belongsTo(ModeleCourriel::class, 'modele_id');
    }

    public function groupeEnregistre(): BelongsTo
    {
        return $this->belongsTo(GroupeDestinataires::class, 'groupe_enregistre_id');
    }

    public function destinataires(): HasMany
    {
        return $this->hasMany(CampagneCourrielDestinataire::class, 'campagne_id');
    }

    public function transitionnerVers(string $nouveauStatut): self
    {
        if (! in_array($nouveauStatut, self::STATUTS, true)) {
            throw new DomainException("Statut invalide : « {$nouveauStatut} ».");
        }

        if ($this->statut === $nouveauStatut) {
            return $this;
        }

        $autorises = self::TRANSITIONS[$this->statut] ?? [];
        if (! in_array($nouveauStatut, $autorises, true)) {
            throw new DomainException("Transition interdite de « {$this->statut} » vers « {$nouveauStatut} ».");
        }

        $champs = ['statut' => $nouveauStatut];

        if ($nouveauStatut === self::STATUT_EN_COURS && $this->commencee_a === null) {
            $champs['commencee_a'] = now();
        }

        if (in_array($nouveauStatut, [self::STATUT_TERMINEE, self::STATUT_TERMINEE_ERREURS, self::STATUT_ECHOUEE, self::STATUT_ANNULEE], true)) {
            $champs['terminee_a'] = now();
        }

        $this->update($champs);

        return $this->fresh();
    }

    public function peutEtreModifiee(): bool
    {
        return in_array($this->statut, [self::STATUT_BROUILLON, self::STATUT_PLANIFIEE], true);
    }

    public function peutEtreAnnulee(): bool
    {
        return in_array($this->statut, [self::STATUT_PLANIFIEE, self::STATUT_EN_ATTENTE, self::STATUT_EN_COURS], true);
    }

    public function peutEtreEnvoyee(): bool
    {
        return in_array($this->statut, [self::STATUT_BROUILLON, self::STATUT_PLANIFIEE], true);
    }

    public function peutEtreRelancee(): bool
    {
        return in_array($this->statut, [self::STATUT_TERMINEE_ERREURS, self::STATUT_ECHOUEE], true)
            && $this->total_echecs > 0;
    }

    public function recalculerCompteurs(): self
    {
        $stats = $this->destinataires()
            ->selectRaw('statut, count(*) as total')
            ->groupBy('statut')
            ->pluck('total', 'statut')
            ->all();

        $total = array_sum($stats);
        $envoyes = (int) ($stats[CampagneCourrielDestinataire::STATUT_ENVOYE] ?? 0);
        $echecs = (int) ($stats[CampagneCourrielDestinataire::STATUT_ECHEC] ?? 0);
        $ignores = (int) ($stats[CampagneCourrielDestinataire::STATUT_IGNORE] ?? 0);

        $this->update([
            'total_destinataires' => $total,
            'total_envoyes' => $envoyes,
            'total_echecs' => $echecs,
            'total_ignores' => $ignores,
        ]);

        return $this->fresh();
    }

    public static function genererCleIdempotence(): string
    {
        return 'camp-mail-'.Str::uuid()->toString();
    }
}
