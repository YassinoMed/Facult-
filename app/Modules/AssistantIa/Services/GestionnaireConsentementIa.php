<?php

namespace App\Modules\AssistantIa\Services;

use App\Models\User;
use App\Modules\AssistantIa\Exceptions\SecuriteIaException;

/**
 * Gestionnaire de consentement explicite pour les transferts vers les modèles d'IA distants.
 * Scelle la décision de l'utilisateur par signature cryptographique HMAC anti-altération.
 */
class GestionnaireConsentementIa
{
    public const DUREE_VALIDITE_SECONDES = 300; // 5 minutes

    /**
     * Clé de signature du consentement : APP_KEY est obligatoire.
     *
     * Aucun secret de repli constant ne doit être utilisé : il rendrait les
     * jetons forgeables par quiconque connaît le dépôt.
     *
     * @throws SecuriteIaException Si la clé d'application est absente
     */
    private static function cleSignature(): string
    {
        $cle = (string) config('app.key');

        if ($cle === '') {
            JournalisationIa::alerteSecurite("Clé d'application absente : signature de consentement IA impossible");

            throw new SecuriteIaException(
                "La clé d'application (APP_KEY) est absente : impossible de signer ou de vérifier un consentement IA. Contactez l'administrateur."
            );
        }

        return $cle;
    }

    /**
     * Calcule l'empreinte cryptographique unifiée d'une requête et de ses sources documentaires.
     *
     * @param  array<int, mixed>  $sources
     */
    public static function calculerEmpreinteRequete(string $prompt, array $sources = []): string
    {
        $sourcesNormalisees = array_map(function ($s) {
            if (is_array($s)) {
                return [
                    'titre' => $s['titre'] ?? '',
                    'chemin' => $s['chemin'] ?? '',
                    'extrait' => mb_substr((string) ($s['extrait'] ?? ''), 0, 100),
                ];
            }

            return (string) $s;
        }, $sources);

        return hash('sha256', trim($prompt).'|'.json_encode($sourcesNormalisees, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Génère un défi de consentement signé à présenter à l'utilisateur avant tout appel distant.
     *
     * @param  array<int, mixed>  $sources
     * @return array{jeton: string, fournisseur: string, modele: string, motif: string, categories: array<int, string>, nb_caracteres: int, expire_a: int, resume_transfert: string}
     *
     * @throws SecuriteIaException Si la requête contient des données formellement prohibées pour l'extérieur
     */
    public function genererDefiConsentement(
        User $utilisateur,
        string $fournisseur,
        string $modele,
        string $prompt,
        array $sources = [],
        string $motif = 'Assistance IA avancée'
    ): array {
        // 1. Contrôle préalable de sensibilité
        $evaluation = FiltreSecuriteIa::evaluerSensibiliteDonnees($prompt, $sources);
        if ($evaluation['estDistantInterdit']) {
            JournalisationIa::alerteSecurite("Tentative de génération de consentement pour données interdites à l'extérieur", [
                'utilisateur_id' => $utilisateur->id,
                'categories' => $evaluation['categories'],
                'motifs' => $evaluation['motifs'],
            ]);

            throw new SecuriteIaException($evaluation['explication']);
        }

        $empreinte = self::calculerEmpreinteRequete($prompt, $sources);
        $expiration = now()->addSeconds((int) config('assistant-ia.routage.duree_validite_consentement_secondes', self::DUREE_VALIDITE_SECONDES))->timestamp;

        $payload = [
            'user_id' => $utilisateur->id,
            'fournisseur' => $fournisseur,
            'modele' => $modele,
            'empreinte' => $empreinte,
            'motif' => $motif,
            'categories' => $evaluation['categories'],
            'nb_caracteres' => mb_strlen($prompt),
            'cree_a' => now()->timestamp,
            'expire_a' => $expiration,
        ];

        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $payloadJson, self::cleSignature());

        $jeton = rtrim(strtr(base64_encode($payloadJson), '+/', '-_'), '=').'.'.$signature;

        return [
            'jeton' => $jeton,
            'fournisseur' => $fournisseur,
            'modele' => $modele,
            'motif' => $motif,
            'categories' => $evaluation['categories'],
            'nb_caracteres' => mb_strlen($prompt),
            'expire_a' => $expiration,
            'resume_transfert' => "Transmission vers {$fournisseur} ({$modele}) pour : {$motif}. Volume estimé : ".mb_strlen($prompt).' caractères.',
        ];
    }

    /**
     * Valide l'authenticité, la fraîcheur et la conformité du consentement explicite reçu.
     *
     * @param  array<int, mixed>  $sources
     * @return array{valide: bool, motif_rejet: ?string, payload: ?array<string, mixed>}
     */
    public function validerConsentement(
        ?string $jeton,
        User $utilisateur,
        string $fournisseur,
        string $prompt,
        array $sources = []
    ): array {
        if (empty($jeton) || ! str_contains($jeton, '.')) {
            return [
                'valide' => false,
                'motif_rejet' => 'Jeton de consentement absent ou format invalide.',
                'payload' => null,
            ];
        }

        [$segmentPayload, $signatureRecue] = explode('.', $jeton, 2);

        $payloadJson = base64_decode(strtr($segmentPayload, '-_', '+/'));
        if (! $payloadJson) {
            return [
                'valide' => false,
                'motif_rejet' => 'Structure de données du consentement corrompue.',
                'payload' => null,
            ];
        }

        $cleSecrete = self::cleSignature();
        $signatureAttendue = hash_hmac('sha256', $payloadJson, $cleSecrete);

        if (! hash_equals($signatureAttendue, $signatureRecue)) {
            JournalisationIa::alerteSecurite('Tentative de falsification de jeton de consentement IA', [
                'utilisateur_id' => $utilisateur->id,
                'fournisseur' => $fournisseur,
            ]);

            return [
                'valide' => false,
                'motif_rejet' => 'Signature de consentement invalide : altération détectée.',
                'payload' => null,
            ];
        }

        try {
            $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return [
                'valide' => false,
                'motif_rejet' => 'Données de consentement JSON malformées.',
                'payload' => null,
            ];
        }

        // 1. Vérification de l'utilisateur
        if ((int) ($payload['user_id'] ?? 0) !== $utilisateur->id) {
            return [
                'valide' => false,
                'motif_rejet' => 'Le consentement appartient à un autre utilisateur.',
                'payload' => null,
            ];
        }

        // 2. Vérification du fournisseur
        if (($payload['fournisseur'] ?? '') !== $fournisseur) {
            return [
                'valide' => false,
                'motif_rejet' => "Le consentement a été accordé pour le fournisseur '{$payload['fournisseur']}' et non '{$fournisseur}'.",
                'payload' => null,
            ];
        }

        // 3. Vérification d'expiration
        $expireA = (int) ($payload['expire_a'] ?? 0);
        if (now()->timestamp > $expireA) {
            return [
                'valide' => false,
                'motif_rejet' => 'Le consentement accordé a expiré. Une nouvelle validation est requise.',
                'payload' => null,
            ];
        }

        // 4. Vérification de l'empreinte de la requête (anti-altération et anti-rejeu sur un prompt différent)
        $empreinteActuelle = self::calculerEmpreinteRequete($prompt, $sources);
        if (! hash_equals($payload['empreinte'] ?? '', $empreinteActuelle)) {
            return [
                'valide' => false,
                'motif_rejet' => 'Le prompt ou les documents ont été modifiés après l\'accord de consentement.',
                'payload' => null,
            ];
        }

        return [
            'valide' => true,
            'motif_rejet' => null,
            'payload' => $payload,
        ];
    }
}
