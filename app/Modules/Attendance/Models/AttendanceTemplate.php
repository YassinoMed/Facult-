<?php

namespace App\Modules\Attendance\Models;

use App\Models\Faculte;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module Attendance (§10-12) : modèle de fiche de présence.
 * Couche de présentation / configuration pour la génération des feuilles d'appel et d'examen.
 */
class AttendanceTemplate extends Model
{
    protected $table = 'attendance_templates';

    public const TYPE_CLASSIC = 'classic';

    public const TYPE_EMARGEMENT = 'emargement';

    public const TYPE_DETAILED = 'detailed';

    public const TYPE_TD = 'td';

    public const TYPE_TP = 'tp';

    public const TYPE_EXAM = 'exam';

    public const TYPE_DOUBLE_SIGNATURE = 'double_signature';

    public const TYPE_QR = 'qr';

    public const TYPE_CUSTOM = 'custom';

    public const TYPES = [
        self::TYPE_CLASSIC => 'Classique',
        self::TYPE_EMARGEMENT => 'Émargement',
        self::TYPE_DETAILED => 'Détaillée',
        self::TYPE_TD => 'Travaux Dirigés (TD)',
        self::TYPE_TP => 'Travaux Pratiques (TP)',
        self::TYPE_EXAM => 'Examen',
        self::TYPE_DOUBLE_SIGNATURE => 'Double Signature',
        self::TYPE_QR => 'QR Code',
        self::TYPE_CUSTOM => 'Personnalisée',
    ];

    public const COLONNES_DISPONIBLES = [
        'numero' => 'N°',
        'matricule' => 'Matricule',
        'nom' => 'Nom',
        'prenom' => 'Prénom',
        'cin' => 'CIN',
        'poste' => 'Poste / Machine',
        'heure_arrivee' => 'Heure d\'arrivée',
        'heure_depart' => 'Heure de départ',
        'retard' => 'Retard',
        'piece_identite' => 'Pièce vérifiée',
        'presence' => 'Présence',
        'signature' => 'Signature',
        'signature_enseignant' => 'Signature enseignant',
        'observation' => 'Observation',
        'qr_code' => 'QR Code',
    ];

    public const BLOCS_AUTORISES = [
        'header' => 'En-tête institutionnel',
        'metadata' => 'Cartouche d\'informations de séance',
        'student_table' => 'Tableau d\'émargement des étudiants',
        'statistics' => 'Statistiques & récapitulatif',
        'signature' => 'Cadre de signatures & visas',
        'qr' => 'Pointage / Sécurité QR Code',
        'footer' => 'Pied de page officiel',
    ];

    public const COLONNES_PAR_DEFAUT = [
        ['key' => 'numero', 'label' => 'N°', 'width' => '6%', 'visible' => true],
        ['key' => 'nom', 'label' => 'Nom', 'width' => '20%', 'visible' => true],
        ['key' => 'prenom', 'label' => 'Prénom', 'width' => '20%', 'visible' => true],
        ['key' => 'matricule', 'label' => 'Matricule', 'width' => '16%', 'visible' => true],
        ['key' => 'presence', 'label' => 'Présence', 'width' => '12%', 'visible' => true],
        ['key' => 'signature', 'label' => 'Signature', 'width' => '14%', 'visible' => true],
        ['key' => 'observation', 'label' => 'Observation', 'width' => '12%', 'visible' => true],
    ];

    protected $fillable = [
        'name',
        'code',
        'description',
        'type',
        'is_system',
        'is_active',
        'faculte_id',
        'configuration',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'configuration' => 'array',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeSystem(Builder $query): void
    {
        $query->where('is_system', true);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePersonnalise(Builder $query): void
    {
        $query->where('is_system', false);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function modificateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return BelongsTo<Faculte, $this>
     */
    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class, 'faculte_id');
    }

    /**
     * Vérifie si le modèle peut être supprimé.
     * Règle absolue : les modèles système (is_system = true) ne peuvent JAMAIS être supprimés.
     */
    public function peutEtreSupprime(): bool
    {
        return ! $this->is_system;
    }

    /**
     * Retourne la liste ordonnée des colonnes visibles configurées.
     *
     * @return array<int, array{key: string, label: string, width?: int|string, visible: bool}>
     */
    public function colonnesActives(): array
    {
        $colonnes = $this->configuration['columns'] ?? [];

        if (empty($colonnes)) {
            return [];
        }

        return array_values(array_filter($colonnes, fn ($col) => ($col['visible'] ?? true) === true));
    }

    /**
     * Retourne la structure des blocs par défaut selon la configuration et le type.
     *
     * @return array<int, array{type: string, position: int, enabled: bool, config: array<string, mixed>}>
     */
    public function blocsParDefaut(): array
    {
        $hasQr = $this->type === self::TYPE_QR || in_array('qr_code', array_column($this->colonnesActives(), 'key'));
        $hasStats = in_array($this->type, [self::TYPE_DETAILED, self::TYPE_EXAM], true);

        return [
            [
                'type' => 'header',
                'position' => 1,
                'enabled' => true,
                'config' => [
                    'show_institution' => true,
                    'show_faculte' => true,
                    'show_departement' => true,
                    'show_reference' => true,
                    'title_alignment' => 'right',
                ],
            ],
            [
                'type' => 'metadata',
                'position' => 2,
                'enabled' => true,
                'config' => [
                    'show_groupe' => true,
                    'show_matiere' => true,
                    'show_enseignant' => true,
                    'show_date' => true,
                    'show_horaire' => true,
                    'show_salle' => true,
                    'show_annee' => true,
                    'show_effectif' => true,
                    'layout' => 'grid_4',
                ],
            ],
            [
                'type' => 'student_table',
                'position' => 3,
                'enabled' => true,
                'config' => [
                    'font_size' => '8.5pt',
                    'row_height' => 'normal',
                    'show_matricule' => true,
                    'show_cin' => in_array('cin', array_column($this->colonnesActives(), 'key')),
                    'show_presence' => true,
                    'show_signature' => true,
                    'show_observation' => true,
                ],
            ],
            [
                'type' => 'statistics',
                'position' => 4,
                'enabled' => $hasStats,
                'config' => [
                    'show_total' => true,
                    'show_presents' => true,
                    'show_absents' => true,
                    'show_retards' => true,
                    'show_taux' => true,
                ],
            ],
            [
                'type' => 'signature',
                'position' => 5,
                'enabled' => true,
                'config' => [
                    'type' => $this->type === self::TYPE_DOUBLE_SIGNATURE ? 'double' : ($this->type === self::TYPE_EXAM ? 'exam' : 'single'),
                    'show_enseignant' => true,
                    'show_administration' => true,
                    'show_surveillants' => $this->type === self::TYPE_EXAM,
                    'mention_legale' => 'Document officiel de scolarité à conserver pour les archives académiques.',
                ],
            ],
            [
                'type' => 'qr',
                'position' => 6,
                'enabled' => $hasQr,
                'config' => [
                    'position' => 'inline',
                    'size' => 60,
                ],
            ],
            [
                'type' => 'footer',
                'position' => 7,
                'enabled' => true,
                'config' => [
                    'show_page_number' => true,
                    'show_generation_date' => true,
                    'show_legal_notice' => true,
                    'custom_notice' => '',
                ],
            ],
        ];
    }

    /**
     * Retourne la liste ordonnée des blocs configurés (ou par défaut).
     *
     * @return array<int, array{type: string, position: int, enabled: bool, config: array<string, mixed>}>
     */
    public function blocsOrdonnes(): array
    {
        $blocs = $this->configuration['blocks'] ?? null;

        if (empty($blocs) || ! is_array($blocs)) {
            $blocs = $this->blocsParDefaut();
        }

        usort($blocs, fn ($a, $b) => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));

        return array_values($blocs);
    }

    /**
     * Duplique un modèle existant en créant un nouveau modèle personnalisé.
     */
    public function dupliquer(string $nouveauNom, ?User $auteur = null): self
    {
        $codeUnique = 'custom_'.mb_substr($this->code, 0, 20).'_'.bin2hex(random_bytes(3));

        return static::create([
            'name' => $nouveauNom,
            'code' => $codeUnique,
            'description' => 'Copie personnalisée basée sur « '.$this->name.' »',
            'type' => self::TYPE_CUSTOM,
            'is_system' => false,
            'is_active' => true,
            'faculte_id' => $auteur?->faculte_id ?? $this->faculte_id,
            'configuration' => $this->configuration,
            'created_by' => $auteur?->id,
            'updated_by' => $auteur?->id,
        ]);
    }

    /**
     * Détermine l'orientation du document (portrait ou landscape).
     */
    public function orientation(): string
    {
        return $this->configuration['orientation'] ?? 'portrait';
    }

    /**
     * Format papier.
     */
    public function formatPapier(): string
    {
        return $this->configuration['paper'] ?? 'A4';
    }
}
