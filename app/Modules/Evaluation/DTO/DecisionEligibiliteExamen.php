<?php

namespace App\Modules\Evaluation\DTO;

use App\Models\Etudiant;
use App\Modules\Evaluation\Models\Epreuve;
use Illuminate\Support\Carbon;

/**
 * Décision d’éligibilité d’un étudiant à une épreuve.
 *
 * Objet explicable : il transporte toujours la décision, la source,
 * la règle appliquée et le motif. Une absence de donnée ne vaut jamais
 * une autorisation implicite.
 */
final class DecisionEligibiliteExamen
{
    public const ELIGIBLE = 'eligible';

    public const NON_ELIGIBLE = 'non_eligible';

    public const ANOMALIE = 'anomalie';

    public const SOURCE_CONFIGURATION = 'configuration';

    public const SOURCE_SESSION = 'session';

    public const SOURCE_ETUDIANT = 'etudiant';

    public const SOURCE_INSCRIPTION_ACADEMIQUE = 'inscription_academique';

    public const SOURCE_INSCRIPTION_PEDAGOGIQUE = 'inscription_pedagogique';

    /**
     * @param  int|null  $inscriptionAcademiqueId  Identifiant de l’inscription académique utilisée
     * @param  int|null  $inscriptionPedagogiqueId  Identifiant de l’inscription pédagogique utilisée
     */
    public function __construct(
        public readonly string $decision,
        public readonly int $etudiantId,
        public readonly int $epreuveId,
        public readonly ?int $matiereId,
        public readonly ?int $inscriptionAcademiqueId,
        public readonly ?int $inscriptionPedagogiqueId,
        public readonly string $source,
        public readonly string $regle,
        public readonly ?string $motif,
        public readonly bool $donneesCompletes,
        public readonly Carbon $evalueLe,
    ) {}

    public static function eligible(
        Etudiant $etudiant,
        Epreuve $epreuve,
        int $inscriptionAcademiqueId,
        int $inscriptionPedagogiqueId,
        string $regle,
        string $motif,
    ): self {
        return new self(
            decision: self::ELIGIBLE,
            etudiantId: $etudiant->id,
            epreuveId: $epreuve->id,
            matiereId: $epreuve->matiere_id,
            inscriptionAcademiqueId: $inscriptionAcademiqueId,
            inscriptionPedagogiqueId: $inscriptionPedagogiqueId,
            source: self::SOURCE_INSCRIPTION_PEDAGOGIQUE,
            regle: $regle,
            motif: $motif,
            donneesCompletes: true,
            evalueLe: Carbon::now(),
        );
    }

    public static function nonEligible(
        Etudiant $etudiant,
        Epreuve $epreuve,
        string $source,
        string $regle,
        string $motif,
        ?int $inscriptionAcademiqueId = null,
        ?int $inscriptionPedagogiqueId = null,
    ): self {
        return new self(
            decision: self::NON_ELIGIBLE,
            etudiantId: $etudiant->id,
            epreuveId: $epreuve->id,
            matiereId: $epreuve->matiere_id,
            inscriptionAcademiqueId: $inscriptionAcademiqueId,
            inscriptionPedagogiqueId: $inscriptionPedagogiqueId,
            source: $source,
            regle: $regle,
            motif: $motif,
            donneesCompletes: true,
            evalueLe: Carbon::now(),
        );
    }

    public static function anomalie(
        Etudiant $etudiant,
        Epreuve $epreuve,
        string $source,
        string $regle,
        string $motif,
        ?int $inscriptionAcademiqueId = null,
        ?int $inscriptionPedagogiqueId = null,
    ): self {
        return new self(
            decision: self::ANOMALIE,
            etudiantId: $etudiant->id,
            epreuveId: $epreuve->id,
            matiereId: $epreuve->matiere_id,
            inscriptionAcademiqueId: $inscriptionAcademiqueId,
            inscriptionPedagogiqueId: $inscriptionPedagogiqueId,
            source: $source,
            regle: $regle,
            motif: $motif,
            donneesCompletes: false,
            evalueLe: Carbon::now(),
        );
    }

    public function estEligible(): bool
    {
        return $this->decision === self::ELIGIBLE;
    }

    public function estNonEligible(): bool
    {
        return $this->decision === self::NON_ELIGIBLE;
    }

    public function estAnomalie(): bool
    {
        return $this->decision === self::ANOMALIE;
    }

    /**
     * Représation compacte pour les rapports, sans données personnelles.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'decision' => $this->decision,
            'etudiant_id' => $this->etudiantId,
            'epreuve_id' => $this->epreuveId,
            'matiere_id' => $this->matiereId,
            'inscription_academique_id' => $this->inscriptionAcademiqueId,
            'inscription_pedagogique_id' => $this->inscriptionPedagogiqueId,
            'source' => $this->source,
            'regle' => $this->regle,
            'motif' => $this->motif,
            'donnees_completes' => $this->donneesCompletes,
            'evalue_le' => $this->evalueLe->toIso8601String(),
        ];
    }
}
