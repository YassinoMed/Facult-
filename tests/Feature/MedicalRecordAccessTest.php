<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DocumentAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\StudentArchive\Models\StudentSensitiveRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Dossier médical (§12, §34) : accès restreint, non-divulgation totale
 * (HTML/JSON/timeline/search/notifs), audit.
 */
class MedicalRecordAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private Etudiant $etudiant;

    private StudentSensitiveRecord $dossier;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.student-archive.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.med@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->etudiant = Etudiant::create([
            'cin' => 'MED00001', 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'med@med.test', 'date_naissance' => '2004-01-01', 'sexe' => 'F',
            'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'annee_academique' => '2026-2027',
            'adresse' => 'X', 'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');
        $this->etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
        ]);

        Storage::disk('local')->put('archives/certmed.pdf', '%PDF-1.4 certmed');
        $document = DocumentAcademique::create([
            'etudiant_id' => $this->etudiant->id,
            'annee_universitaire_id' => $annee->id,
            'type_document' => 'certificat',
            'nom_original' => 'certificat-medical.pdf',
            'chemin' => 'archives/certmed.pdf',
            'mime_type' => 'application/pdf',
            'taille' => 18,
            'hash_fichier' => hash('sha256', 'certmed'),
            'uploaded_by' => $this->admin->id,
            'niveau_confidentialite' => 'confidentiel',
        ]);

        $this->dossier = StudentSensitiveRecord::create([
            'etudiant_id' => $this->etudiant->id, 'type' => 'medical',
            'titre' => 'Aménagement session juin',
            'description' => 'Certificat reçu le 12/05, aménagement validé.',
            'statut' => 'en_cours', 'niveau' => 'confidentiel',
            'date_debut' => '2026-05-12', 'date_fin' => '2026-06-30',
            'document_source' => 'ged', 'document_id' => $document->id,
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_onglet_confidentiel_masque_sans_permission(): void
    {
        config(['modules.student-archive.active' => true]);

        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'agent.med@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        $html = $this->actingAs($agent)->get(route('admin.etudiants.archive', $this->etudiant))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('Confidentiel', $html);
        $this->assertStringNotContainsString('Aménagement session juin', $html);
    }

    public function test_onglet_confidentiel_404_sans_permission(): void
    {
        $agent = User::creerCompteBackOffice([
            'name' => 'Agent2', 'email' => 'agent2.med@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        $this->actingAs($agent)->get(route('admin.etudiants.archive', [
            $this->etudiant, 'onglet' => 'confidentiel',
        ]))->assertNotFound();
    }

    public function test_admin_voit_et_telecharge(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.etudiants.archive', [
            $this->etudiant, 'onglet' => 'confidentiel',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('Aménagement session juin', $html);
        $this->assertStringContainsString('Dossier confidentiel', $html);

        $this->actingAs($this->admin)->get(route('admin.etudiants.archive.sensible.download', [
            $this->etudiant, $this->dossier,
        ]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_timeline_standard_sans_medical(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.etudiants.archive', [
            $this->etudiant, 'onglet' => 'timeline',
        ]))->assertOk()->getContent();

        $this->assertStringNotContainsString('Aménagement session juin', $html);
    }

    public function test_creation_cloture_auditees(): void
    {
        $this->actingAs($this->admin)->post(route('admin.etudiants.archive.sensible.store', $this->etudiant), [
            'type' => 'medical', 'titre' => 'Suivi aménagement',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->patch(route('admin.etudiants.archive.sensible.update', [
            $this->etudiant, $this->dossier,
        ]), ['statut' => 'cloture'])->assertSessionHasNoErrors();

        $this->assertSame('cloture', $this->dossier->fresh()->statut);
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $this->etudiant->id, 'action' => 'sensitive_create',
        ]);
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $this->etudiant->id, 'action' => 'sensitive_update',
        ]);
    }
}
