<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\User;
use App\Modules\StudentArchive\Models\StudentSensitiveRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit sensible (§23, §34) : chaque accès tracé (qui/quand/quoi/IP),
 * JAMAIS de contenu médical/disciplinaire dans les logs.
 */
class SensitiveAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Etudiant $etudiant;

    private StudentSensitiveRecord $dossier;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.student-archive.active' => true]);

        $faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.saud@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->etudiant = Etudiant::create([
            'cin' => 'SAUD00001', 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'saud@saud.test', 'date_naissance' => '2004-01-01', 'sexe' => 'F',
            'faculte_id' => $faculte->id, 'niveau' => 'L1', 'annee_academique' => '2026-2027',
            'adresse' => 'X', 'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');
        $this->etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
        ]);

        $this->dossier = StudentSensitiveRecord::create([
            'etudiant_id' => $this->etudiant->id, 'type' => 'medical',
            'titre' => 'Dossier suivi', 'description' => 'Faits administratifs.',
            'statut' => 'ouvert', 'created_by' => $this->admin->id,
        ]);
    }

    public function test_cycle_complet_trace_sans_contenu(): void
    {
        $this->actingAs($this->admin)->get(route('admin.etudiants.archive', [
            $this->etudiant, 'onglet' => 'confidentiel',
        ]))->assertOk();

        $this->actingAs($this->admin)->patch(route('admin.etudiants.archive.sensible.update', [
            $this->etudiant, $this->dossier,
        ]), ['statut' => 'en_cours'])->assertSessionHasNoErrors();

        $entrees = HistoriqueAcademique::where('etudiant_id', $this->etudiant->id)
            ->whereIn('action', [
                HistoriqueAcademique::ACTION_SENSITIVE_VIEW,
                HistoriqueAcademique::ACTION_SENSITIVE_UPDATE,
            ])->get();

        $this->assertCount(2, $entrees);

        foreach ($entrees as $entree) {
            $this->assertNotNull($entree->utilisateur_id);
            $this->assertNotNull($entree->adresse_ip);
            $serialise = json_encode([$entree->ancienne_valeur, $entree->nouvelle_valeur]);
            $this->assertStringNotContainsString('Faits administratifs', (string) $serialise);
        }
    }

    public function test_refus_non_trace_comme_acces(): void
    {
        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'agent.saud@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        $this->actingAs($agent)->get(route('admin.etudiants.archive', [
            $this->etudiant, 'onglet' => 'confidentiel',
        ]))->assertNotFound();

        $this->assertDatabaseMissing('historique_academique', [
            'etudiant_id' => $this->etudiant->id,
            'action' => HistoriqueAcademique::ACTION_SENSITIVE_VIEW,
        ]);
    }
}
