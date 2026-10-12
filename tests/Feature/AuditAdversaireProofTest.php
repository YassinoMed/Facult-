<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AuditAdversaireProofTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_enseignant_homonyme_sans_email_ne_doit_pas_500(): void
    {
        Teacher::create(['nom' => 'Ben Salah', 'prenom' => 'Ahmed', 'email' => 'a.b1@univ.test']);
        Teacher::create(['nom' => 'Ben Salah', 'prenom' => 'Ahmed', 'email' => 'a.b2@univ.test']);

        $user = User::factory()->create(['role' => 'super_admin']);
        $csv = "nom;prenom;email\nBen Salah;Ahmed;\n";
        $fichier = UploadedFile::fake()->createWithContent('enseignants.csv', $csv);

        $reponse = $this->actingAs($user)->post(route('admin.donnees.import.preview', 'enseignants'), [
            'fichier' => $fichier,
        ]);

        $this->assertNotEquals(500, $reponse->status(),
            'Le moteur doit renvoyer une erreur métier, jamais un 500.');
    }

    public function test_preview_enseignant_statut_inconnu_ne_doit_pas_500(): void
    {
        $user = User::factory()->create(['role' => 'super_admin']);
        $csv = "nom;prenom;statut\nDoe;John;BIZARRE\n";
        $fichier = UploadedFile::fake()->createWithContent('enseignants.csv', $csv);

        $reponse = $this->actingAs($user)->post(route('admin.donnees.import.preview', 'enseignants'), [
            'fichier' => $fichier,
        ]);

        $this->assertNotEquals(500, $reponse->status());
    }
}
