<?php

namespace App\Modules\AssistantIa\Tools\Metier;

use App\Models\User;
use App\Modules\AssistantIa\Contracts\OutilIaDonneesAcademiquesInterface;
use App\Modules\AssistantIa\Contracts\OutilIaInterface;
use App\Modules\AssistantIa\DTO\ResultatOutil;
use App\Modules\AssistantIa\Exceptions\SecuriteIaException;
use App\Modules\AssistantIa\Services\FiltreSecuriteIa;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Services\Acquis\ExplicationDecisionAcademiqueService;

/**
 * Outil métier AssistantIa : explication d'une décision académique publiée.
 *
 * Strictement en lecture seule :
 * - aucune note, décision, inscription ou délibération n'est modifiée ;
 * - aucune recommandation, sanction, orientation ou mesure corrective ;
 * - aucune donnée identifiante en clair (matricule masqué, pas de nom/CIN/email) ;
 * - périmètre facultaire contrôlé côté serveur (anti-IDOR/BOLA).
 */
class ExpliquerDecisionAcademiqueTool implements OutilIaDonneesAcademiquesInterface, OutilIaInterface
{
    public function __construct(
        protected ?ExplicationDecisionAcademiqueService $service = null
    ) {
        $this->service = $service ?? app(ExplicationDecisionAcademiqueService::class);
    }

    public function nom(): string
    {
        return 'expliquer_decision_academique';
    }

    public function description(): string
    {
        return 'Explique une décision académique publiée en confrontant l\'acte officiel du jury à la lecture du moteur d\'acquis (crédits, UE, références déclarées). Outil strictement en lecture seule : aucune recommandation, aucune sanction, aucune modification.';
    }

    public function mode(): string
    {
        return 'metier';
    }

    public function permissionRequise(): string
    {
        return 'assistant_ia.mode_metier';
    }

    public function schemaParametres(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'decision_id' => [
                    'type' => 'integer',
                    'description' => 'Identifiant numérique de la décision académique publiée (evaluation_decisions).',
                ],
            ],
            'required' => ['decision_id'],
        ];
    }

    public function estSensible(): bool
    {
        return false;
    }

    public function executer(array $parametres, User $utilisateur): ResultatOutil
    {
        $decisionId = (int) ($parametres['decision_id'] ?? 0);
        if ($decisionId <= 0) {
            return ResultatOutil::echec('L\'identifiant de la décision est obligatoire et doit être un entier positif.');
        }

        $decision = DecisionDeliberation::with(['etudiant', 'deliberation', 'inscriptionAcademique'])
            ->find($decisionId);

        if ($decision === null) {
            return ResultatOutil::echec("Décision #{$decisionId} introuvable.");
        }

        // Défense en profondeur : le registre vérifie déjà la permission, l'outil la revalide.
        if (! $utilisateur->hasPermission('assistant_ia.mode_metier') && ! $utilisateur->estSuperAdmin()) {
            throw new SecuriteIaException('Vous ne disposez pas de l\'habilitation requise pour expliquer une décision académique.');
        }

        // Périmètre facultaire : la faculté de l'étudiant, à défaut celle de la délibération.
        $faculteCible = $decision->etudiant?->faculte_id ?? $decision->deliberation?->faculteId();
        FiltreSecuriteIa::verifierPerimetreFaculte($utilisateur, $faculteCible === null ? null : (int) $faculteCible);

        $explication = $this->service->expliquer($decision);

        $donnees = $explication;
        $donnees['outil'] = $this->nom();
        $donnees['anonymisation'] = [
            'matricule_masque' => true,
            'nom_expose' => false,
            'cin_expose' => false,
            'email_expose' => false,
            'telephone_expose' => false,
        ];

        if (! (bool) ($explication['explicable'] ?? false)) {
            return ResultatOutil::echec(
                $this->messageRefus((string) ($explication['motif_indisponibilite'] ?? 'INDISPONIBLE')),
                $donnees
            );
        }

        return ResultatOutil::succes(
            $donnees,
            $this->messageSucces($decisionId, $explication),
            $this->sourcesDepuisCitations($explication['citations'] ?? [])
        );
    }

    private function messageRefus(string $motif): string
    {
        return match ($motif) {
            'DECISION_NON_PUBLIEE' => 'Explication indisponible : la décision n\'est pas publiée. Aucun résultat non officiel n\'est exposé.',
            'DELIBERATION_INTROUVABLE' => 'Explication indisponible : la délibération rattachée à la décision est introuvable.',
            'DELIBERATION_NON_PUBLIEE' => 'Explication indisponible : la délibération n\'est pas dans un statut publié.',
            'INSCRIPTION_NON_RATTACHEE' => 'Explication indisponible : la décision n\'est rattachée à aucune inscription académique.',
            'INSCRIPTION_INCOHERENTE' => 'Explication indisponible : l\'inscription rattachée appartient à un autre étudiant.',
            'ETUDIANT_INTROUVABLE' => 'Explication indisponible : l\'étudiant rattaché à la décision est introuvable.',
            'PROGRAMME_INDETERMINE' => 'Explication indisponible : aucun programme d\'études n\'est rattaché au dossier.',
            'REGIME_INDETERMINE' => 'Explication indisponible : le régime réglementaire du programme n\'est pas établi de manière certaine.',
            default => 'Explication indisponible : '.$motif.'.',
        };
    }

    /**
     * @param  array<string, mixed>  $explication
     */
    private function messageSucces(int $decisionId, array $explication): string
    {
        $statut = (string) ($explication['concordance']['statut'] ?? 'NON_COMPARABLE');
        $matricule = (string) ($explication['etudiant']['matricule_masque'] ?? 'matricule masqué');

        $qualification = match ($statut) {
            'CONCORDANTE' => 'les faits comparables concordent avec la lecture du moteur',
            'ECART_MESURE' => 'un écart mesuré est constaté, sans conséquence automatique',
            default => 'aucun fait n\'est directement comparable',
        };

        return "Décision #{$decisionId} ({$matricule}) : {$qualification}. Lecture strictement explicative, en lecture seule, sans recommandation ni sanction.";
    }

    /**
     * @param  array<int, array<string, mixed>>  $citations
     * @return array<int, array{titre: string, url_ou_chemin: string, extrait: string, source: string}>
     */
    private function sourcesDepuisCitations(array $citations): array
    {
        return array_map(fn ($citation) => [
            'titre' => (string) ($citation['reference'] ?? 'Source interne'),
            'url_ou_chemin' => 'interne:'.(string) ($citation['reference'] ?? 'source'),
            'extrait' => (string) ($citation['extrait'] ?? ''),
            'source' => (string) ($citation['origine'] ?? 'interne'),
        ], array_values($citations));
    }
}
