<?php

namespace Tests\Feature;

use App\Modules\Schedule\Models\Teacher;
use App\Services\DataExchange\Definitions\TeacherDefinition;
use App\Services\Io\IoException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP 44 — homonymes enseignants à l'import : sans email, aucun
 * rapprochement automatique (fail-closed). Avec email ou sans
 * homonyme : comportement inchangé.
 */
class TeacherHomonymeImportTest extends TestCase
{
    use RefreshDatabase;

    private TeacherDefinition $definition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->definition = app(TeacherDefinition::class);
        Teacher::create(['nom' => 'Ben Salah', 'prenom' => 'Ahmed', 'email' => 'a.b1@universite.test']);
        Teacher::create(['nom' => 'Ben Salah', 'prenom' => 'Ahmed', 'email' => 'a.b2@universite.test']);
    }

    private function ligne(array $surcharge = []): array
    {
        return array_merge([
            'ligne' => 2, 'nom' => 'Ben Salah', 'prenom' => 'Ahmed', 'email' => null,
        ], $surcharge);
    }

    public function test_sans_email_homonymes_signales_en_validation(): void
    {
        $erreurs = $this->definition->validerLigneMetier($this->ligne(), null);

        $this->assertNotEmpty($erreurs);
        $this->assertStringContainsString('email', strtolower(implode(' ', $erreurs)));
    }

    public function test_sans_email_homonymes_jamais_fusionnes(): void
    {
        try {
            $this->definition->persister($this->ligne(), 'upsert', null);
            $this->fail('Fusion sur nom+prénom interdite sans email.');
        } catch (IoException $e) {
            $this->assertStringContainsString('email', strtolower($e->getMessage()));
        }

        $this->assertSame(2, Teacher::where('nom', 'Ben Salah')->count());
    }

    public function test_sans_email_sans_homonyme_creation_autorisee(): void
    {
        $message = $this->definition->persister($this->ligne(['nom' => 'Trabelsi']), 'creer', null);

        $this->assertStringContainsString('créé', $message);
        $this->assertSame(1, Teacher::where('nom', 'Trabelsi')->count());
    }

    public function test_avec_email_rapprochement_exact_conserve(): void
    {
        $message = $this->definition->persister(
            $this->ligne(['email' => 'a.b1@universite.test']), 'upsert', null
        );

        $this->assertStringContainsString('jour', $message);
        $this->assertSame(2, Teacher::where('nom', 'Ben Salah')->count());
    }
}
