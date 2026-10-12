<?php

namespace Tests\Feature;

use App\Models\DocumentAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * Purge des pièces orphelines (roadmap « restes connus ») :
 * fichiers GED sans ligne en base. Dry-run par défaut — jamais de
 * suppression implicite ; racines documentaires uniquement.
 */
class PurgerOrphelinsTest extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('private');
    }

    private function etudiantAvecDocument(): DocumentAcademique
    {
        $faculte = Faculte::create(['nom' => 'FA', 'code' => 'FA', 'description' => 'T', 'active' => true]);
        $etudiant = Etudiant::create([
            'cin' => 'BK'.random_int(100000, 999999),
            'nom' => 'Purge', 'prenom' => 'Test',
            'email' => uniqid('purge.', true).'@example.com',
            'sexe' => 'M', 'faculte_id' => $faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027',
            'adresse' => '1 rue', 'ville' => 'Sfax',
            'code_postal' => '3000', 'annee_bac' => '2024',
            'password' => 'motdepasse1',
        ]);
        $auteur = $this->scolarite('admin_scolarite');

        return DocumentAcademique::create([
            'etudiant_id' => $etudiant->id, 'type_document' => 'releve',
            'nom_original' => 'releve.pdf', 'chemin' => 'archives/etudiant_'.$etudiant->id.'/courant/documents/legitime.pdf',
            'mime_type' => 'application/pdf', 'taille' => 10,
            'hash_fichier' => 'x', 'uploaded_by' => $auteur->id,
        ]);
    }

    public function test_dry_run_liste_sans_supprimer(): void
    {
        $document = $this->etudiantAvecDocument();
        Storage::disk('local')->put($document->chemin, 'document légitime');
        Storage::disk('local')->put('archives/etudiant_999/courant/documents/orphelin.pdf', 'pièce orpheline');
        Storage::disk('private')->put('student-dossiers/generated/2026/42/diplome/orphelin.pdf', 'pdf orphelin');

        $sortie = Artisan::call('documents:purger-orphelins');
        $this->assertSame(0, $sortie);

        // Rien n'est supprimé en simulation.
        $this->assertTrue(Storage::disk('local')->exists($document->chemin));
        $this->assertTrue(Storage::disk('local')->exists('archives/etudiant_999/courant/documents/orphelin.pdf'));
        $this->assertTrue(Storage::disk('private')->exists('student-dossiers/generated/2026/42/diplome/orphelin.pdf'));
    }

    public function test_execute_supprime_uniquement_les_orphelins(): void
    {
        $document = $this->etudiantAvecDocument();
        Storage::disk('local')->put($document->chemin, 'document légitime');
        Storage::disk('local')->put('archives/etudiant_999/courant/documents/orphelin.pdf', 'pièce orpheline');
        Storage::disk('private')->put('student-dossiers/generated/2026/42/diplome/orphelin.pdf', 'pdf orphelin');

        // Fichier hors racines documentaires : jamais touché.
        Storage::disk('private')->put('sauvegardes/base-test.sql', 'dump');

        Artisan::call('documents:purger-orphelins', ['--execute' => true]);

        // Légitime conservé, orphelins supprimés.
        $this->assertTrue(Storage::disk('local')->exists($document->chemin));
        $this->assertFalse(Storage::disk('local')->exists('archives/etudiant_999/courant/documents/orphelin.pdf'));
        $this->assertFalse(Storage::disk('private')->exists('student-dossiers/generated/2026/42/diplome/orphelin.pdf'));

        // Hors périmètre : intact.
        $this->assertTrue(Storage::disk('private')->exists('sauvegardes/base-test.sql'));
    }
}
