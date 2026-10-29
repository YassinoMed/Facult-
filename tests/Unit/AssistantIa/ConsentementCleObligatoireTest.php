<?php

namespace Tests\Unit\AssistantIa;

use App\Models\User;
use App\Modules\AssistantIa\Exceptions\SecuriteIaException;
use App\Modules\AssistantIa\Services\GestionnaireConsentementIa;
use Tests\TestCase;

/**
 * LOOP-08 — le consentement IA ne doit jamais reposer sur un secret constant.
 *
 * Preuve négative : sans APP_KEY, un jeton forgé avec l'ancien secret de
 * repli `uniregistre-hybrid-gateway-secret` doit être refusé, et aucun défi
 * ne doit pouvoir être signé.
 */
class ConsentementCleObligatoireTest extends TestCase
{
    public function test_jeton_signe_avec_le_secret_de_repli_est_refuse_sans_app_key(): void
    {
        config(['app.key' => '']);

        $utilisateur = new User;
        $utilisateur->id = 42;

        $prompt = 'Expliquez le fonctionnement des crédits ECTS.';
        $sources = [];

        $payload = [
            'user_id' => 42,
            'fournisseur' => 'groq',
            'modele' => 'llama-3.3-70b-versatile',
            'empreinte' => GestionnaireConsentementIa::calculerEmpreinteRequete($prompt, $sources),
            'motif' => 'Recherche pédagogique avancée',
            'categories' => [],
            'nb_caracteres' => mb_strlen($prompt),
            'cree_a' => now()->timestamp,
            'expire_a' => now()->addMinutes(5)->timestamp,
        ];

        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $payloadJson, 'uniregistre-hybrid-gateway-secret');
        $jeton = rtrim(strtr(base64_encode($payloadJson), '+/', '-_'), '=').'.'.$signature;

        $this->expectException(SecuriteIaException::class);

        (new GestionnaireConsentementIa)->validerConsentement(
            jeton: $jeton,
            utilisateur: $utilisateur,
            fournisseur: 'groq',
            prompt: $prompt,
            sources: $sources
        );
    }

    public function test_defi_de_consentement_refuse_sans_app_key(): void
    {
        config(['app.key' => '']);

        $utilisateur = new User;
        $utilisateur->id = 42;

        $this->expectException(SecuriteIaException::class);

        (new GestionnaireConsentementIa)->genererDefiConsentement(
            utilisateur: $utilisateur,
            fournisseur: 'groq',
            modele: 'llama-3.3-70b-versatile',
            prompt: 'Expliquez le fonctionnement des crédits ECTS.',
        );
    }
}
