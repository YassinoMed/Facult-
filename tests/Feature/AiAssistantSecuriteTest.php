<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AiAssistant\Exceptions\ActionRefuseeException;
use App\Modules\AiAssistant\Exceptions\AiAssistantException;
use App\Modules\AiAssistant\Exceptions\FournisseurIndisponibleException;
use App\Modules\AiAssistant\Exceptions\OutilRefuseException;
use App\Modules\AiAssistant\Exceptions\PlafondAtteintException;
use App\Modules\AiAssistant\Support\EchecDoux;
use App\Modules\AiAssistant\Support\PermissionsAi;
use App\Modules\AiAssistant\Support\TraceurIa;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * LOT 3 — Sécurité et autorisations (partie composants ; les
 * vérifications HTTP bout-en-bout suivent avec les routes du LOT 4 et
 * la suite IA du LOT 12).
 *
 * Points couverts ici :
 * - AUCUNE Gate IA n'est ouverte à l'anonyme ;
 * - les erreurs IA ne coulent jamais d'autres modules (fail-soft
 *   calibré, sans détail interne) ;
 * - le journal IA n'accepte que les champs de la whitelist (aucune
 *   PII, aucun contenu de prompt).
 */
final class AiAssistantSecuriteTest extends TestCase
{
    /* ── Identité / permissions ────────────────────────────────── */

    public function test_aucune_gate_ia_n_est_ouverte_au_visiteur_anonyme(): void
    {
        foreach (PermissionsAi::TOUTES as $permission) {
            $this->assertFalse(Gate::forUser(null)->allows($permission), $permission);
        }
    }

    public function test_les_9_gates_ia_sont_distinctes_et_deroulees_par_role(): void
    {
        $utilisateur = new User;
        $utilisateur->forceFill(['role' => 'admin_faculte']);

        $this->assertTrue(Gate::forUser($utilisateur)->allows(PermissionsAi::ACTION_APPROUVER));
        $this->assertFalse(Gate::forUser($utilisateur)->allows(PermissionsAi::DEVELOPPEUR));
        $this->assertFalse(Gate::forUser($utilisateur)->allows(PermissionsAi::ADMIN_CONFIGURER));
    }

    /* ── Fail-soft HTTP calibré ────────────────────────────────── */

    public function test_les_erreurs_ia_sont_converties_en_reponses_http_calibrees(): void
    {
        [$codeOutil, $reponseOutil] = EchecDoux::versReponse(
            new OutilRefuseException('inventer_reglement', 'permission absente')
        );
        $this->assertSame(403, $codeOutil);
        $this->assertSame('ai.outil.refuse', $reponseOutil['erreur']);

        [$codeAction, $reponseAction] = EchecDoux::versReponse(
            new ActionRefuseeException('appliquer_patch', 'politique absente')
        );
        $this->assertSame(403, $codeAction, 'Action sensible refusée = 403 (droits/politique).');

        $this->assertSame(429, EchecDoux::versReponse(new PlafondAtteintException('appels_par_heure'))[0]);
        $this->assertSame(502, EchecDoux::versReponse(new FournisseurIndisponibleException('distant_compat'))[0]);
        $this->assertSame(400, EchecDoux::versReponse(new AiAssistantException('incohérence'))[0]);
    }

    public function test_l_erreur_inconnue_ne_fuite_aucun_detail(): void
    {
        $secretPretendu = 'sk-VERY-SECRET-FIPS-9999';

        [$code, $reponse] = EchecDoux::versReponse(new \RuntimeException("fuite potentielle : {$secretPretendu}"));

        $this->assertSame(500, $code);
        $this->assertStringNotContainsString($secretPretendu, json_encode($reponse));
        $this->assertSame('Une erreur interne est survenue.', $reponse['message']);
    }

    /* ── Journal sans PII ─────────────────────────────────────── */

    public function test_le_traceur_ia_n_ecrit_que_les_champs_autorises(): void
    {
        Log::spy();

        TraceurIa::evenement('generer.reponse', [
            'fournisseur' => 'factice',
            'modele' => 'factice-deterministe',
            'latence_ms' => 42,
            'jetons' => 57,
            'chunks' => 3,
            'outil' => 'lire_fichier_extrait',
            // Champs interdits (PII / contenu) — doivent disparaître :
            'prompt' => 'mon CIN est XX99999',
            'reponse' => 'contenu du modèle',
            'cin' => 'CIN123456',
            'question' => 'Comment obtenir une réduction des frais ?',
        ]);

        Log::shouldHaveReceived('info')->once()->withArgs(function (string $message, array $contexte) {
            $this->assertArrayHasKey('fournisseur', $contexte);
            $this->assertArrayHasKey('latence_ms', $contexte);
            $this->assertArrayHasKey('chunks', $contexte,
                'Le compteur d\'ancrage documentaire est un champ autorisé (aucun contenu).');
            $this->assertArrayNotHasKey('prompt', $contexte, 'Interdit dans les journaux IA.');
            $this->assertArrayNotHasKey('reponse', $contexte);
            $this->assertArrayNotHasKey('cin', $contexte);
            $this->assertArrayNotHasKey('question', $contexte);

            return str_starts_with($message, '[ai] ');
        });
    }
}
