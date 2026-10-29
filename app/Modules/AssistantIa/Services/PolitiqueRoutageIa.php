<?php

namespace App\Modules\AssistantIa\Services;

use App\Models\User;
use App\Modules\AssistantIa\Contracts\FournisseurIaInterface;
use App\Modules\AssistantIa\DTO\DecisionRoutageIa;

/**
 * Politique centrale de routage IA (LOOP 17 — hybrid gateway, local-first).
 *
 * Décisions explicites : LOCAL, DISTANT_AUTORISE, CONSENTEMENT_REQUIS,
 * REFUSE, INDISPONIBLE. Principes :
 * - LOCAL par défaut ; le fournisseur « faux » (simulateur de tests)
 *   est traité comme local ;
 * - le distant exige une triple garde : autorisation organisationnelle
 *   (`assistant-ia.routage.distant_autorise`), permission utilisateur
 *   dédiée, contenu non sensible (minimisation LOT 4) ;
 * - le consentement est émis et vérifié PAR LE SERVEUR, lié à
 *   l'empreinte de la requête (LOT 5) — un paramètre client ne suffit
 *   jamais, un consentement ne peut jamais lever une interdiction ;
 * - aucune bascule silencieuse : un local indisponible reste local
 *   (l'indisponibilité est signalée, jamais contournée) ;
 * - la journalisation trace les décisions sans jamais enregistrer le
 *   contenu des requêtes (LOT 7).
 */
class PolitiqueRoutageIa
{
    public const LOCAL = 'LOCAL';

    public const DISTANT_AUTORISE = 'DISTANT_AUTORISE';

    public const CONSENTEMENT_REQUIS = 'CONSENTEMENT_REQUIS';

    public const REFUSE = 'REFUSE';

    public const INDISPONIBLE = 'INDISPONIBLE';

    /** Fournisseurs traités comme locaux (aucun transfert hors de l'organisation). */
    protected const FOURNISSEURS_LOCAUX = ['local', 'faux'];

    protected ?GestionnaireConsentementIa $gestionnaireConsentement = null;

    public function __construct(
        ?GestionnaireConsentementIa $gestionnaireConsentement = null,
        protected ?array $config = null
    ) {
        $this->gestionnaireConsentement = $gestionnaireConsentement ?? app(GestionnaireConsentementIa::class);
        $this->config = $config ?? config('assistant-ia', []);
    }

    /**
     * Évalue une demande d'inférence et rend une décision de routage explicite et justifiée sous forme de DTO typé.
     *
     * @param  array<int, mixed>  $sourcesRag
     * @param  array<string, mixed>  $optionsClient
     */
    public function evaluer(
        User $utilisateur,
        string $prompt,
        ?string $fournisseurDemande = null,
        ?string $jetonConsentement = null,
        array $sourcesRag = [],
        ?FournisseurIaInterface $fournisseurLocal = null,
        ?FournisseurIaInterface $fournisseurDistant = null,
        array $optionsClient = []
    ): DecisionRoutageIa {
        $fournisseurParDefaut = (string) ($this->config['fournisseur'] ?? 'faux');
        $demandeCible = $fournisseurDemande ?? $fournisseurParDefaut;

        // 1. Branchement LOCAL (ou FAUX pour l'environnement de test)
        if ($demandeCible === 'local' || $demandeCible === 'faux') {
            $nomModele = (string) ($this->config['fournisseurs'][$demandeCible]['modele'] ?? 'local-model');

            // Si le fournisseur local est injecté, tester sa disponibilité effective
            if ($fournisseurLocal !== null && $demandeCible === 'local') {
                $disponible = $fournisseurLocal->estDisponible();
                if (! $disponible) {
                    JournalisationIa::avertissement('Fournisseur local indisponible lors du routage. Repli distant refusé par politique.');

                    return DecisionRoutageIa::indisponible(
                        fournisseur: 'local',
                        motif: "Le service d'inférence locale (Ollama / vLLM) est actuellement inaccessible. Par politique de souveraineté des données, aucun basculement automatique vers un service distant n'est effectué."
                    );
                }
            }

            return DecisionRoutageIa::local(
                modele: $nomModele,
                motif: 'Routage local souverain par défaut. Les données demeurent strictement sur l\'infrastructure interne.',
                fournisseur: $demandeCible
            );
        }

        // 2. Branchement DISTANT, GROQCLOUD & COLAB (Requiert autorisation, habilitation, sensibilité vérifiée et consentement explicite)
        if ($demandeCible === 'distant' || $demandeCible === 'groq' || $demandeCible === 'colab') {
            $cible = $demandeCible;
            $configCible = $this->config['fournisseurs'][$cible] ?? [];
            $estActif = (bool) ($configCible['actif'] ?? false);
            $defautModele = match ($cible) {
                'groq' => 'llama-3.3-70b-versatile',
                'colab' => 'qwen2.5-coder-7b-instruct',
                default => 'gpt-4o-mini',
            };
            $modeleCible = (string) ($configCible['modele'] ?? $defautModele);
            $nomAffiche = match ($cible) {
                'groq' => 'GroqCloud',
                'colab' => 'Google Colab',
                default => 'distant',
            };

            // 2.1. Vérification de l'activation globale du fournisseur distant/cloud
            if (! $estActif) {
                return DecisionRoutageIa::refuse(
                    motif: "Le recours au fournisseur d'IA externe {$nomAffiche} est désactivé par la politique de sécurité de l'établissement.",
                    fournisseur: $cible
                );
            }

            // 2.2. Vérification des habilitations de l'utilisateur
            $permDistant = (string) config('assistant-ia.routage.permission_distant', 'assistant_ia.fournisseur_distant');
            $aPermission = $utilisateur->hasPermission($permDistant) || $utilisateur->hasPermission('assistant_ia.distant') || $utilisateur->estSuperAdmin();
            if (! $aPermission) {
                JournalisationIa::alerteSecurite("Tentative d'accès au fournisseur {$nomAffiche} sans habilitation pour l'utilisateur #{$utilisateur->id}");

                return DecisionRoutageIa::refuse(
                    motif: "Vous ne disposez pas de l'habilitation requise ('{$permDistant}') pour solliciter le fournisseur d'IA externe {$nomAffiche}.",
                    fournisseur: $cible
                );
            }

            // 2.3. Détection et classification de la sensibilité des données
            $evaluationSensibilite = FiltreSecuriteIa::evaluerSensibiliteDonnees($prompt, $sourcesRag);
            if ($evaluationSensibilite['estDistantInterdit']) {
                JournalisationIa::alerteSecurite("Requête contenant des données sensibles bloquée avant transfert vers {$nomAffiche}", [
                    'utilisateur_id' => $utilisateur->id,
                    'fournisseur' => $cible,
                    'categories' => $evaluationSensibilite['categories'],
                    'motifs' => $evaluationSensibilite['motifs'],
                ]);

                return DecisionRoutageIa::refuse(
                    motif: $evaluationSensibilite['explication'],
                    categoriesSensibles: $evaluationSensibilite['categories'],
                    fournisseur: $cible
                );
            }

            // 2.4. Limites de volume de contexte pour le fournisseur externe
            $maxCaracteres = (int) ($configCible['max_caracteres_contexte'] ?? 32000);
            if (mb_strlen($prompt) > $maxCaracteres) {
                return DecisionRoutageIa::refuse(
                    motif: 'Le volume de la requête ('.mb_strlen($prompt)." caractères) dépasse le plafond autorisé ({$maxCaracteres} caractères) pour un transfert vers {$nomAffiche}.",
                    fournisseur: $cible
                );
            }

            // 2.5. Tentative de contournement via paramètre client non vérifiable (ex: allow_remote=true)
            if (! empty($optionsClient['allow_remote']) && empty($jetonConsentement)) {
                JournalisationIa::alerteSecurite("Tentative de contournement du consentement via paramètre client 'allow_remote'", [
                    'utilisateur_id' => $utilisateur->id,
                    'fournisseur' => $cible,
                ]);
            }

            // 2.6. Vérification du consentement explicite signé
            $validationConsentement = $this->gestionnaireConsentement->validerConsentement(
                jeton: $jetonConsentement,
                utilisateur: $utilisateur,
                fournisseur: $cible,
                prompt: $prompt,
                sources: $sourcesRag
            );

            if (! $validationConsentement['valide']) {
                $defi = $this->gestionnaireConsentement->genererDefiConsentement(
                    utilisateur: $utilisateur,
                    fournisseur: $cible,
                    modele: $modeleCible,
                    prompt: $prompt,
                    sources: $sourcesRag,
                    motif: (string) ($optionsClient['motif_transfert'] ?? "Assistance {$nomAffiche} demandée par l'utilisateur")
                );

                return DecisionRoutageIa::consentementRequis(
                    modele: $modeleCible,
                    defiConsentement: $defi,
                    motif: $validationConsentement['motif_rejet'] ?? 'Consentement explicite obligatoire avant toute transmission externe.',
                    fournisseur: $cible
                );
            }

            // 2.7. Vérification de disponibilité du fournisseur distant
            if ($fournisseurDistant !== null && ! $fournisseurDistant->estDisponible()) {
                return DecisionRoutageIa::indisponible(
                    fournisseur: $cible,
                    motif: "Le fournisseur d'IA {$nomAffiche} est actuellement injoignable ou en erreur réseau."
                );
            }

            return DecisionRoutageIa::distantAutorise(
                modele: $modeleCible,
                motif: 'Transfert externe validé par consentement explicite vérifié (Jeton valide).',
                metadonnees: [
                    'consentement_payload' => $validationConsentement['payload'],
                ],
                fournisseur: $cible
            );
        }

        // 3. Tout autre fournisseur inconnu
        return DecisionRoutageIa::refuse(
            motif: "Fournisseur d'IA '{$demandeCible}' non supporté ou non autorisé.",
            fournisseur: $demandeCible
        );
    }
}
