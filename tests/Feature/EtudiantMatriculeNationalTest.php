<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\MensReference;
use App\Services\DataExchange\Definitions\EtudiantDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP 42 — traçabilité MENS à l'import : `persister()` réconcilie
 * `mens.matricule_national` vers `etudiants.identifiant_national`.
 * Sans MENS : colonne null, aucun comportement modifié.
 */
class EtudiantMatriculeNationalTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
    }

    private function ligneImport(string $cin): array
    {
        return [
            'ligne' => 2, 'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara',
            'faculte' => 'FST', 'sexe' => 'F',
        ];
    }

    public function test_persister_reconcilie_matricule_national_mens(): void
    {
        MensReference::create([
            'cin_normalise' => 'BKID0001', 'cin_original' => 'BKID0001',
            'nom' => 'Benali', 'prenom' => 'Sara', 'annee' => '2025-2026',
            'matricule_national' => 'MN-2025-0001', 'source_hash' => hash('sha256', 'm1'),
        ]);

        app(EtudiantDefinition::class)->persister($this->ligneImport('BKID0001'), 'creer', null);

        $this->assertSame(
            'MN-2025-0001',
            Etudiant::where('cin', 'BKID0001')->value('identifiant_national')
        );
    }

    public function test_persister_sans_mens_laisse_identifiant_nul(): void
    {
        app(EtudiantDefinition::class)->persister($this->ligneImport('BKID0002'), 'creer', null);

        $etudiant = Etudiant::where('cin', 'BKID0002')->firstOrFail();
        $this->assertNull($etudiant->identifiant_national);
        $this->assertSame('BKID0002', $etudiant->cin);
    }
}
