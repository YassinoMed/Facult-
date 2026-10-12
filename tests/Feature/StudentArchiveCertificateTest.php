<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DocumentAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\StudentArchive\Services\StudentArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Certificats projetés (§23, §25) : attestations/certificats GED,
 * chaque pièce pointe vers sa source (aucune copie).
 */
class StudentArchiveCertificateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Etudiant $etudiant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.student-archive.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.cert@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->etudiant = Etudiant::create([
            'cin' => 'CRT00001', 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'crt@crt.test', 'date_naissance' => '2004-01-01', 'sexe' => 'F',
            'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'annee_academique' => '2026-2027',
            'adresse' => 'X', 'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');
        $this->etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
        ]);

        Storage::disk('local')->put('archives/cert.pdf', '%PDF-1.4 cert');
        DocumentAcademique::create([
            'etudiant_id' => $this->etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'type_document' => 'certificat',
            'nom_original' => 'certificat-scolarite.pdf',
            'chemin' => 'archives/cert.pdf',
            'mime_type' => 'application/pdf',
            'taille' => 12,
            'hash_fichier' => hash('sha256', 'cert'),
            'uploaded_by' => $this->admin->id,
        ]);
    }

    public function test_certificats_projetes_avec_source(): void
    {
        $certificats = app(StudentArchiveService::class)->certificates($this->etudiant, $this->admin);

        $this->assertCount(1, $certificats);
        $this->assertSame('ged', $certificats[0]['source']);
        $this->assertSame('certificat', $certificats[0]['type']);
        $this->assertNotNull($certificats[0]['telechargement']);
    }

    public function test_diplome_non_certificat(): void
    {
        Storage::disk('local')->put('archives/d.pdf', '%PDF-1.4 d');
        DocumentAcademique::create([
            'etudiant_id' => $this->etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'type_document' => 'diplome',
            'nom_original' => 'diplome.pdf',
            'chemin' => 'archives/d.pdf',
            'mime_type' => 'application/pdf',
            'taille' => 10,
            'hash_fichier' => hash('sha256', 'd'),
            'uploaded_by' => $this->admin->id,
        ]);

        $certificats = app(StudentArchiveService::class)->certificates($this->etudiant, $this->admin);

        $this->assertCount(1, $certificats);
        $this->assertSame('certificat-scolarite.pdf', $certificats[0]['titre']);
    }
}
