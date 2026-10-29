<?php

namespace App\Modules\AssistantIa\DTO;

/**
 * Objet de transfert représentant le verdict de la politique centrale de routage IA.
 */
class DecisionRoutageIa
{
    public const LOCAL = 'local';

    public const DISTANT_AUTORISE = 'distant_autorise';

    public const CONSENTEMENT_REQUIS = 'consentement_requis';

    public const REFUSE = 'refuse';

    public const INDISPONIBLE = 'indisponible';

    /**
     * @param  array<string, mixed>|null  $defiConsentement
     * @param  array<int, string>  $categoriesSensibles
     * @param  array<string, mixed>  $metadonnees
     */
    public function __construct(
        public string $decision,
        public string $fournisseur,
        public string $modele,
        public string $motif,
        public bool $autoriseExecution,
        public ?array $defiConsentement = null,
        public bool $donneesSensiblesDetectees = false,
        public array $categoriesSensibles = [],
        public string $correlationId = '',
        public array $metadonnees = []
    ) {
        if ($this->correlationId === '') {
            $this->correlationId = 'req_'.bin2hex(random_bytes(8));
        }
    }

    public static function local(string $modele, string $motif = 'Routage local souverain par défaut', string $fournisseur = 'local'): self
    {
        return new self(
            decision: self::LOCAL,
            fournisseur: $fournisseur,
            modele: $modele,
            motif: $motif,
            autoriseExecution: true
        );
    }

    public static function distantAutorise(string $modele, string $motif, array $metadonnees = [], string $fournisseur = 'distant'): self
    {
        return new self(
            decision: self::DISTANT_AUTORISE,
            fournisseur: $fournisseur,
            modele: $modele,
            motif: $motif,
            autoriseExecution: true,
            metadonnees: $metadonnees
        );
    }

    public static function consentementRequis(string $modele, array $defiConsentement, string $motif = 'Consentement explicite de l\'utilisateur requis pour transfert externe', string $fournisseur = 'distant'): self
    {
        return new self(
            decision: self::CONSENTEMENT_REQUIS,
            fournisseur: $fournisseur,
            modele: $modele,
            motif: $motif,
            autoriseExecution: false,
            defiConsentement: $defiConsentement
        );
    }

    public static function refuse(string $motif, array $categoriesSensibles = [], string $fournisseur = 'distant'): self
    {
        return new self(
            decision: self::REFUSE,
            fournisseur: $fournisseur,
            modele: 'aucun',
            motif: $motif,
            autoriseExecution: false,
            donneesSensiblesDetectees: ! empty($categoriesSensibles),
            categoriesSensibles: $categoriesSensibles
        );
    }

    public static function indisponible(string $fournisseur, string $motif): self
    {
        return new self(
            decision: self::INDISPONIBLE,
            fournisseur: $fournisseur,
            modele: 'aucun',
            motif: $motif,
            autoriseExecution: false
        );
    }

    public function estExecutable(): bool
    {
        return $this->autoriseExecution && in_array($this->decision, [self::LOCAL, self::DISTANT_AUTORISE], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function versTableau(): array
    {
        return [
            'decision' => $this->decision,
            'fournisseur' => $this->fournisseur,
            'modele' => $this->modele,
            'motif' => $this->motif,
            'autorise_execution' => $this->autoriseExecution,
            'correlation_id' => $this->correlationId,
            'defi_consentement' => $this->defiConsentement,
            'donnees_sensibles_detectees' => $this->donneesSensiblesDetectees,
            'categories_sensibles' => $this->categoriesSensibles,
        ];
    }
}
