<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Services\InscriptionService;
use App\Services\Io\StudentsImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2 — Population (§16) : CIN nullable, doublons, notifs, exports.
 */
class PopulationPhase2Test extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.pop2@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    public function test_etudiant_etranger_sans_cin(): void
    {
        $a = Etudiant::create([
            'cin' => null, 'nom' => 'Benali', 'prenom' => 'Sara', 'email' => 'a@etranger.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $b = Etudiant::create([
            'cin' => null, 'nom' => 'Alami', 'prenom' => 'Omar', 'email' => 'b@etranger.test',
            'date_naissance' => '2003-05-05', 'sexe' => 'M', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'Y', 'ville' => 'Rabat',
            'code_postal' => '10000', 'annee_bac' => '2023', 'password' => 'Etudiant@123',
        ]);

        $this->assertNull($a->fresh()->cin);
        $this->assertNull($b->fresh()->cin);
        $this->assertSame('—', $a->fresh()->cin_masquee);
    }

    public function test_doublon_email_sans_cin(): void
    {
        $lignes = [
            ['cin' => '', 'nom' => 'Benali', 'prenom' => 'Sara', 'email' => 'doublon@etranger.test', 'sexe' => 'F', 'faculte' => 'FST', 'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024'],
            ['cin' => '', 'nom' => 'Alami', 'prenom' => 'Omar', 'email' => 'doublon@etranger.test', 'sexe' => 'M', 'faculte' => 'FST', 'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'Y', 'ville' => 'Rabat', 'code_postal' => '10000', 'annee_bac' => '2023'],
        ];

        $service = app(StudentsImporter::class);
        $preview = $service->valider($lignes);

        $this->assertSame(1, $preview->valides());
        $this->assertSame(1, $preview->doublons());

        $service->importer($lignes, 'atomique');

        $this->assertSame(1, Etudiant::where('email', 'doublon@etranger.test')->count());
        $this->assertNull(Etudiant::where('email', 'doublon@etranger.test')->first()->cin);
    }

    public function test_notifications_transitions(): void
    {
        $etudiant = Etudiant::create([
            'cin' => 'NOTIF001', 'nom' => 'Benali', 'prenom' => 'Sara', 'email' => 'notif@pop.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);

        app(InscriptionService::class)->transiter(
            $etudiant->inscriptionsAcademiques()->create([
                'annee_universitaire_id' => AnneeUniversitaire::create([
                    'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
                ])->id, 'niveau' => 'L1', 'statut' => 'en_cours',
            ]),
            'valide', $this->admin->id
        );

        $this->assertDatabaseHas('notifications', [
            'etudiant_id' => $etudiant->id, 'type' => 'inscription.valide',
        ]);
    }

    public function test_export_sans_cin_neutre(): void
    {
        Etudiant::create([
            'cin' => null, 'nom' => 'Benali', 'prenom' => 'Sara', 'email' => 'exp@etranger.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);

        $csv = $this->actingAs($this->admin)->get(route('admin.listes.exporter', [
            'format' => 'csv', 'colonnes' => ['matricule', 'nom', 'cin'],
        ]))->assertOk()->streamedContent();

        $this->assertStringNotContainsString('***', $csv);
    }
}
