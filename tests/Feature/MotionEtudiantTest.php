<?php

namespace Tests\Feature;

use App\Models\DossierAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Portée de mouvement des fenêtres étudiantes.
 *
 * Toutes les pages authentifiées `etudiant.*` (sauf le profil, qui possède
 * son module dédié, et les PDF) doivent exposer `data-motion="etudiant"` pour
 * charger `resources/js/etudiant.js`. Les autres pages restent en `app`
 * (sobre) ou `cinematic` (parcours publics).
 */
class MotionEtudiantTest extends TestCase
{
    use RefreshDatabase;

    private function creerEtudiant(): Etudiant
    {
        $faculte = Faculte::create([
            'nom' => 'Faculté des Sciences et Techniques',
            'code' => 'FST',
            'description' => 'Sciences et technologies',
            'active' => true,
        ]);

        $etudiant = Etudiant::create([
            'cin' => 'BK910001',
            'nom' => 'Motion',
            'prenom' => 'Etudiant',
            'email' => 'motion.etudiant@example.com',
            'telephone' => '+212600000001',
            'date_naissance' => '2004-03-15',
            'sexe' => 'M',
            'faculte_id' => $faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '12 Rue de la Liberté',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'mention_bac' => 'Bien',
            'password' => 'motdepasse1',
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-2026-BK910001'])->save();
        $etudiant->definirStatut('valide');

        DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-MOTION-01',
            'date_archivage' => now()->toDateString(),
        ]);

        return $etudiant->fresh();
    }

    private function connecter(Etudiant $etudiant): void
    {
        session([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_auth_prenom' => $etudiant->prenom,
            'etudiant_auth_nom' => $etudiant->nom,
        ]);
    }

    public function test_les_fenetres_etudiantes_chargent_le_mouvement_dedie(): void
    {
        $etudiant = $this->creerEtudiant();
        $this->connecter($etudiant);

        $routes = [
            'etudiant.dashboard',
            'etudiant.notes.index',
            'etudiant.documents.index',
            'etudiant.archives.show',
            'etudiant.suivi',
            'etudiant.corrections.index',
            'etudiant.profil.edit',
        ];

        foreach ($routes as $route) {
            $this->get(route($route))
                ->assertOk()
                ->assertSee('data-motion="etudiant"', false);
        }
    }

    public function test_le_profil_garde_son_module_de_mouvement_dedie(): void
    {
        $etudiant = $this->creerEtudiant();
        $this->connecter($etudiant);

        $this->get(route('etudiant.profil.show'))
            ->assertOk()
            ->assertSee('data-motion="app"', false)
            ->assertSee('data-profil', false);
    }

    public function test_la_connexion_et_l_accueil_ne_prennent_pas_la_portee_etudiante(): void
    {
        $this->get(route('etudiant.connexion.show'))
            ->assertOk()
            ->assertSee('data-motion="app"', false);

        $this->get(route('accueil'))
            ->assertOk()
            ->assertSee('data-motion="cinematic"', false);
    }
}
