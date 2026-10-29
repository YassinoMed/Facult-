<?php

namespace App\Modules\Evaluation\Models;

use App\Models\Etudiant;
use App\Models\Note;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class NoteEpreuve extends Model
{
    protected $table = 'evaluation_notes';

    /** LOT 05 — mémoire par worker du contrôle de schéma (fail-soft). */
    private static ?bool $colonneValideAtMemoisee = null;

    public const STATUTS = ['draft', 'entered', 'submitted', 'validated', 'locked', 'correction_demandee'];

    /** Transitions serveur (Phase 10, correction §14.8). */
    public const TRANSITIONS = [
        'draft' => ['entered'],
        'entered' => ['submitted', 'validated'],
        'submitted' => ['validated'],
        'validated' => ['validated', 'locked', 'correction_demandee'],
        'locked' => ['correction_demandee'],
        'correction_demandee' => ['entered', 'validated'],
    ];

    protected $fillable = [
        'epreuve_id', 'etudiant_id', 'candidat_id', 'note_id',
        'valeur', 'note_max', 'coefficient', 'statut', 'commentaire',
        'saisi_par', 'valide_par', 'valide_at', 'verrouillee_at',
    ];

    protected function casts(): array
    {
        return [
            'valeur' => 'decimal:2',
            'note_max' => 'decimal:2',
            'coefficient' => 'decimal:2',
            'valide_at' => 'datetime',
            'verrouillee_at' => 'datetime',
        ];
    }

    public function epreuve(): BelongsTo
    {
        return $this->belongsTo(Epreuve::class, 'epreuve_id');
    }

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    public function candidat(): BelongsTo
    {
        return $this->belongsTo(Candidat::class, 'candidat_id');
    }

    /** Note officielle du cœur, synchronisée à la validation. */
    public function noteOfficielle(): BelongsTo
    {
        return $this->belongsTo(Note::class, 'note_id');
    }

    public function saisiPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par');
    }

    public function validePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valide_par');
    }

    public function historique(): HasMany
    {
        return $this->hasMany(NoteEpreuveHistorique::class, 'evaluation_note_id');
    }

    public function estVerrouillee(): bool
    {
        return $this->verrouillee_at !== null || in_array($this->statut, ['locked'], true);
    }

    public function peutTransiterVers(string $cible): bool
    {
        return in_array($cible, self::TRANSITIONS[$this->statut] ?? [], true);
    }

    /**
     * LOT 05 — auditabilité : la colonne `valide_at` provient d'une migration
     * additive (2026_10_24_100000). Tant qu'elle n'est pas appliquée dans un
     * environnement, écriture et lecture se replient proprement (comportement
     * antérieur : aucun horodatage, drapeau d'audit inactif) — jamais d'erreur
     * SQL à la validation.
     */
    public static function existeColonneValideAt(): bool
    {
        if (self::$colonneValideAtMemoisee === null) {
            self::$colonneValideAtMemoisee = Schema::hasColumn((new self)->getTable(), 'valide_at');
        }

        return self::$colonneValideAtMemoisee;
    }
}
