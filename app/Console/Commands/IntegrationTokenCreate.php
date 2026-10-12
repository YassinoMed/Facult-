<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\ApiScopes;
use App\Support\Permissions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

use function Laravel\Prompts\info;
use function Laravel\Prompts\text;

/**
 * Crée un token d'API dédié à une intégration externe (§46-47).
 * Le token est affiché UNE SEULE fois — jamais stocké en clair,
 * jamais écrit dans les logs.
 */
class IntegrationTokenCreate extends Command
{
    protected $signature = 'integration:token-create
                            {--name= : Nom de l\'intégration (ex : scolarite-apipro)}
                            {--role=admin_scolarite : Rôle back-office associé au compte technique}
                            {--abilities=* : Scopes accordés (ex : students:read grades:read)}';

    protected $description = 'Crée un token API pour un système externe (scopes moindre privilège)';

    public function handle(): int
    {
        $nom = $this->option('name') ?: text(label: 'Nom de l\'intégration', required: true);
        $slug = Str::slug($nom);

        // Audit §P2-14 : le rôle est validé contre la matrice RBAC et
        // `super_admin` est interdit aux comptes techniques (moindre privilège).
        $role = (string) $this->option('role');

        if (! Permissions::roleValide($role)) {
            $this->error("Rôle inconnu : {$role}. Rôles valides : ".implode(', ', Permissions::ROLES));

            return self::FAILURE;
        }

        if (! in_array($role, User::ROLES_TECHNIQUES, true)) {
            $this->error("Rôle « {$role} » interdit aux comptes techniques d'intégration (moindre privilège). Rôles autorisés : ".implode(', ', User::ROLES_TECHNIQUES));

            return self::FAILURE;
        }

        $scopes = $this->option('abilities');

        if (empty($scopes)) {
            $this->warn('Aucun scope fourni. Scopes disponibles :');
            foreach (ApiScopes::SCOPES as $scope => $libelle) {
                $this->line("  - {$scope} : {$libelle}");
            }

            return self::FAILURE;
        }

        foreach ($scopes as $scope) {
            if (! ApiScopes::valide($scope)) {
                $this->error("Scope inconnu : {$scope}");

                return self::FAILURE;
            }
        }

        // Compte technique dédié à l'intégration (un compte = un système externe,
        // jamais de token global partagé). `role` n'est plus mass-assignable
        // (audit §P3-17) : attribué via la méthode validée. Si le compte
        // existe déjà avec un AUTRE rôle, on refuse plutôt que d'ignorer
        // silencieusement l'option (audit §P2-14).
        $compte = User::firstOrCreate(
            ['email' => $slug.'@integration.systeme'],
            [
                'name' => 'Intégration '.$nom,
                'password' => Hash::make(Str::random(64)),
            ],
        );

        if ($compte->wasRecentlyCreated) {
            $compte->definirRole($role);
        } elseif ($compte->role !== $role) {
            $this->error("Le compte technique « {$compte->email} » existe déjà avec le rôle « {$compte->role} » (demandé : « {$role} »). Aucun token créé.");

            return self::FAILURE;
        }

        $token = $compte->createToken('integration-'.$slug, $scopes);

        $expirationMinutes = (int) config('sanctum.expiration');
        $expireLe = $expirationMinutes > 0
            ? now()->addMinutes($expirationMinutes)->format('d/m/Y H:i')
            : 'jamais (SANCTUM_TOKEN_EXPIRATION=0)';

        info("Token créé pour « {$nom} ».");
        $this->line('Scopes accordés : '.implode(', ', $scopes));
        $this->line('Expire le : '.$expireLe.' (rotation requise avant cette date).');
        $this->newLine();
        $this->line('  <bg=black;fg=green>'.$token->plainTextToken.'</>');
        $this->newLine();
        $this->warn('Conservez ce token maintenant : il ne sera plus jamais affiché.');

        return self::SUCCESS;
    }
}
