<?php

namespace Tests\Concerns;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Helpers partagés par les suites du module Archives Académiques.
 */
trait ActAsScolarite
{
    /**
     * Authentifie un membre du personnel de scolarite avec un rôle donné.
     */
    protected function scolarite(string $role = 'super_admin'): User
    {
        $user = User::creerCompteBackOffice([
            'name' => ucfirst($role).' Test',
            'email' => str_replace('_', '.', uniqid($role.'.', true)).'@scolarite.test',
            'password' => Hash::make('Motdepasse@123'),
        ], $role);

        $this->actingAs($user);

        return $user;
    }

    /**
     * Simule une session étudiant (espace étudiant, résolution par session).
     */
    protected function etudiantConnecte(Etudiant $etudiant): Etudiant
    {
        session([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_auth_prenom' => $etudiant->prenom,
            'etudiant_auth_nom' => $etudiant->nom,
        ]);

        return $etudiant;
    }

    /**
     * Crée un étudiant complet (avec faculté) pour les jeux de données.
     * Code de faculté unique : plusieurs appels par test possible.
     */
    protected function etudiantAvecDossier(): Etudiant
    {
        $faculte = Faculte::create([
            'nom' => 'Faculté des Sciences et Techniques',
            'code' => 'FST-'.strtoupper(substr(md5(uniqid('', true)), 0, 4)),
            'description' => 'Sciences et technologies',
            'active' => true,
        ]);

        $etudiantCree = Etudiant::create(['cin' => 'BK'.random_int(100000, 999999),
            'nom' => 'TestEtudiant',
            'prenom' => 'Archive',
            'email' => uniqid('archive.', true).'@example.com',
            'sexe' => 'M',
            'faculte_id' => $faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => '1 Rue du Test',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2024',
            'password' => 'motdepasse1', ]);
        $etudiantCree->forceFill(['num_inscription' => 'INS-TEST-'.random_int(10000, 99999)])->save();
        $etudiantCree->definirStatut('valide');

        return $etudiantCree;
    }
}
