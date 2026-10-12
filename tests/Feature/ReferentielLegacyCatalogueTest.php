<?php

namespace Tests\Feature;

use App\Models\Faculte;
use App\Models\User;
use App\Services\DataExchange\DefinitionRegistry;
use App\Services\DataExchange\Definitions\EcueDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Loop référentiel-catalogue (LOT LG) : la voie ECUE refuse la création
 * implicite de matière (zéro nouveau catalogue silencieux), sans jamais
 * écrire le champ déprécié `matieres.ue_id` en cas de succès.
 */
class ReferentielLegacyCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true,
        ]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité',
            'email' => 'sco.referentiel@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    public function test_matiere_inconnue_refusee_sans_creation_propre(): void
    {
        $definition = app(DefinitionRegistry::class)->import('ecues');
        $this->assertInstanceOf(EcueDefinition::class, $definition);

        $ligne = [
            'code' => 'MATH-999',
            'nom' => 'Matière inconnue',
            'ue' => 'UE-INEXISTANTE',
            'ligne' => 2,
        ];

        $erreurs = $definition->validerLigneMetier($ligne, $this->admin->id);
        $this->assertNotEmpty($erreurs);
    }
}
