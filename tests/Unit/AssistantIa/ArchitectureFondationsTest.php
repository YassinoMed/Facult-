<?php

namespace Tests\Unit\AssistantIa;

use App\Models\User;
use App\Modules\AssistantIa\Contracts\OutilIaInterface;
use App\Modules\AssistantIa\DTO\AppelOutil;
use App\Modules\AssistantIa\DTO\MessageIa;
use App\Modules\AssistantIa\DTO\ReponseIa;
use App\Modules\AssistantIa\DTO\ResultatOutil;
use App\Modules\AssistantIa\Exceptions\AssistantIaException;
use App\Modules\AssistantIa\Exceptions\OutilNonAutoriseException;
use App\Modules\AssistantIa\Exceptions\QuotasDepassesException;
use App\Modules\AssistantIa\Exceptions\SecuriteIaException;
use App\Modules\AssistantIa\Services\JournalisationIa;
use App\Modules\AssistantIa\Services\RegistreOutils;
use Tests\TestCase;

class ArchitectureFondationsTest extends TestCase
{
    public function test_dto_messages_et_reponse_sont_correctement_instancies(): void
    {
        $messageUser = MessageIa::utilisateur('Comment inscrire un étudiant ?');
        $this->assertSame('user', $messageUser->role);
        $this->assertSame('Comment inscrire un étudiant ?', $messageUser->contenu);

        $messageAssistant = MessageIa::assistant('Voici la procédure.');
        $this->assertSame('assistant', $messageAssistant->role);

        $appel = new AppelOutil('call_123', 'verifier_dossier', ['cin' => '12345678']);
        $this->assertSame('verifier_dossier', $appel->nomOutil);
        $this->assertSame(['cin' => '12345678'], $appel->arguments);

        $resultat = ResultatOutil::succes(['statut' => 'complet'], 'Dossier validé');
        $this->assertTrue($resultat->succes);
        $this->assertSame('Dossier validé', $resultat->message);

        $reponse = ReponseIa::texte('Explication terminée', 'test-model', 10, 20);
        $this->assertSame('Explication terminée', $reponse->texte);
        $this->assertSame(30, $reponse->totalTokens());
    }

    public function test_exceptions_sont_typees_et_preservent_les_codes(): void
    {
        $eSecurite = new SecuriteIaException('Violation détectée');
        $this->assertInstanceOf(AssistantIaException::class, $eSecurite);
        $this->assertSame(403, $eSecurite->getCode());

        $eOutil = new OutilNonAutoriseException('supprimer_tout', 'Interdit en production');
        $this->assertInstanceOf(SecuriteIaException::class, $eOutil);
        $this->assertSame(403, $eOutil->getCode());
        $this->assertStringContainsString('supprimer_tout', $eOutil->getMessage());

        $eQuota = new QuotasDepassesException('Trop de requêtes');
        $this->assertSame(429, $eQuota->getCode());
    }

    public function test_registre_outils_filtre_par_mode_et_permissions_serveur(): void
    {
        $registre = new RegistreOutils;

        $outilDev = new class implements OutilIaInterface
        {
            public function nom(): string
            {
                return 'inspecter_code';
            }

            public function description(): string
            {
                return 'Inspecte le code';
            }

            public function mode(): string
            {
                return 'developpeur';
            }

            public function permissionRequise(): string
            {
                return 'mode_developpeur';
            }

            public function schemaParametres(): array
            {
                return ['type' => 'object'];
            }

            public function estSensible(): bool
            {
                return false;
            }

            public function executer(array $parametres, User $utilisateur): ResultatOutil
            {
                return ResultatOutil::succes(['code' => 'ok']);
            }
        };

        $outilMetier = new class implements OutilIaInterface
        {
            public function nom(): string
            {
                return 'verifier_dossier';
            }

            public function description(): string
            {
                return 'Vérifie dossier';
            }

            public function mode(): string
            {
                return 'metier';
            }

            public function permissionRequise(): string
            {
                return 'mode_metier';
            }

            public function schemaParametres(): array
            {
                return ['type' => 'object'];
            }

            public function estSensible(): bool
            {
                return false;
            }

            public function executer(array $parametres, User $utilisateur): ResultatOutil
            {
                return ResultatOutil::succes(['dossier' => 'valide']);
            }
        };

        $registre->enregistrer($outilDev);
        $registre->enregistrer($outilMetier);

        $this->assertTrue($registre->existe('inspecter_code'));
        $this->assertTrue($registre->existe('verifier_dossier'));
        $this->assertFalse($registre->existe('outil_inexistant'));

        // Utilisateur super_admin a tous les droits
        $superAdmin = new User;
        $superAdmin->role = 'super_admin';
        $superAdmin->id = 1;

        $outilsDev = $registre->pourUtilisateurEtMode($superAdmin, 'developpeur');
        $this->assertArrayHasKey('inspecter_code', $outilsDev);
        $this->assertArrayNotHasKey('verifier_dossier', $outilsDev);

        $outilsMetier = $registre->pourUtilisateurEtMode($superAdmin, 'metier');
        $this->assertArrayHasKey('verifier_dossier', $outilsMetier);
        $this->assertArrayNotHasKey('inspecter_code', $outilsMetier);

        // Utilisateur agent standard sans permission mode_developpeur
        $agent = new User;
        $agent->role = 'consultation';
        $agent->id = 2;
        $outilsAgentDev = $registre->pourUtilisateurEtMode($agent, 'developpeur');
        $this->assertEmpty($outilsAgentDev);
    }

    public function test_registre_outils_empeche_execution_non_autorisee(): void
    {
        $registre = new RegistreOutils;
        $outil = new class implements OutilIaInterface
        {
            public function nom(): string
            {
                return 'action_critique';
            }

            public function description(): string
            {
                return 'Action critique';
            }

            public function mode(): string
            {
                return 'metier';
            }

            public function permissionRequise(): string
            {
                return 'permission_inexistante_xyz';
            }

            public function schemaParametres(): array
            {
                return [];
            }

            public function estSensible(): bool
            {
                return true;
            }

            public function executer(array $parametres, User $utilisateur): ResultatOutil
            {
                return ResultatOutil::succes(['execute' => true]);
            }
        };

        $registre->enregistrer($outil);

        $simpleUser = new User;
        $simpleUser->role = 'consultation';
        $simpleUser->id = 3;

        $this->expectException(OutilNonAutoriseException::class);
        $registre->executerOutil('action_critique', [], $simpleUser, 'metier');
    }

    public function test_journalisation_ia_masque_les_donnees_sensibles_et_pii(): void
    {
        $texte = 'Requête pour CIN 09876543 avec email test.user@univ.tn et clé sk-abcdef123456789012345678';
        $masque = JournalisationIa::masquerDonneesSensibles($texte);

        $this->assertStringNotContainsString('09876543', $masque);
        $this->assertStringContainsString('CIN_***', $masque);
        $this->assertStringNotContainsString('test.user@univ.tn', $masque);
        $this->assertStringNotContainsString('sk-abcdef123456789012345678', $masque);
        $this->assertStringContainsString('[CLÉ_API_MASQUÉE]', $masque);

        $contexte = [
            'api_key' => 'secret_value_123',
            'utilisateur' => 'Admin',
            'donnees' => [
                'mot_de_passe' => 'P@ssword1',
                'cin' => '11223344',
                'normal' => 'valeur sans risque',
            ],
        ];

        $contexteNettoye = JournalisationIa::nettoyerContexte($contexte);
        $this->assertSame('[MASQUÉ]', $contexteNettoye['api_key']);
        $this->assertSame('[MASQUÉ]', $contexteNettoye['donnees']['mot_de_passe']);
        $this->assertSame('[MASQUÉ]', $contexteNettoye['donnees']['cin']);
        $this->assertSame('valeur sans risque', $contexteNettoye['donnees']['normal']);
    }

    public function test_configuration_ia_est_chargee_avec_valeurs_par_defaut_securisees(): void
    {
        $config = config('assistant-ia');
        $this->assertIsArray($config);
        $this->assertTrue($config['actif']);
        $this->assertSame('faux', $config['fournisseur']);
        $this->assertArrayHasKey('faux', $config['fournisseurs']);
        $this->assertArrayHasKey('distant', $config['fournisseurs']);
        $this->assertArrayHasKey('local', $config['fournisseurs']);
        $this->assertContains('.env', $config['rag']['motifs_strictement_exclus']);
    }
}
