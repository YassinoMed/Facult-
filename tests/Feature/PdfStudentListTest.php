<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PDF listes étudiants (§2.3-2.6, §2.24) : page de garde, métadonnées,
 * orientation, émargement, en-tête répété, pagination.
 */
class PdfStudentListTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Group $groupe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité PDF', 'email' => 'sco.pdf@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->groupe = Group::create([
            'code' => 'GP1', 'nom' => 'Groupe P1', 'faculte_id' => $this->faculte->id,
            'niveau' => 'M2', 'capacite' => 30, 'actif' => true,
        ]);
        $this->creerEtudiant('PDF00001');
    }

    public function test_page_de_garde_et_metadonnees(): void
    {
        $html = view('pdf.liste', [
            'institution' => config('app.name'),
            'titreDocument' => 'LISTE DES ÉTUDIANTS PAR GROUPE',
            'reference' => 'EXP-2026-TEST01',
            'dateGeneration' => '30/09/2026 10:00',
            'contexte' => 'Programme : DSIR · Niveau : M2',
            'metadonnees' => [
                'etablissement' => config('app.name'), 'faculte' => 'FST',
                'annee' => '2026-2027', 'programme' => 'DSIR — Data',
                'niveau' => 'M2', 'utilisateur' => 'Scolarité PDF', 'total' => 27,
                'regroupement' => 'Groupe',
            ],
            'entetes' => ['matricule', 'nom'],
            'lignes' => [],
            'sections' => null,
            'signature' => false,
            'zoneSignature' => false,
            'orientation' => 'portrait',
        ])->render();

        $this->assertStringContainsString('LISTE DES ÉTUDIANTS PAR GROUPE', $html);
        $this->assertStringContainsString('EXP-2026-TEST01', $html);
        $this->assertStringContainsString('Scolarité PDF', $html);
        $this->assertStringContainsString('Regroupé par Groupe', $html);
        $this->assertStringContainsString('Page <span class="pagenum"></span> / <span class="pagecount"></span>', $html);
    }

    public function test_orientation_explicite(): void
    {
        $this->actingAs($this->admin)->get(route('admin.listes.exporter', [
            'format' => 'pdf', 'orientation' => 'paysage',
        ]))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->actingAs($this->admin)->get(route('admin.listes.exporter', [
            'format' => 'pdf', 'orientation' => 'portrait',
        ]))->assertOk();
    }

    public function test_emargement_et_zone_signature(): void
    {
        $html = view('pdf.liste', [
            'institution' => 'U', 'titreDocument' => 'T', 'reference' => 'R',
            'dateGeneration' => 'D', 'contexte' => 'C',
            'entetes' => ['matricule', 'nom'],
            'lignes' => [['matricule' => 'M1', 'nom' => 'N1']],
            'sections' => null, 'signature' => true, 'zoneSignature' => true,
            'orientation' => 'portrait',
        ])->render();

        $this->assertStringContainsString('Signature', $html);
        $this->assertStringContainsString('Responsable pédagogique', $html);
    }

    public function test_contexte_affiche_libelles_pas_ids(): void
    {
        $reponse = $this->actingAs($this->admin)->get(route('admin.listes.preview', [
            'programme_id' => null, 'groupe_id' => $this->groupe->id, 'niveau' => 'M2',
        ]));

        $reponse->assertOk();
        $this->assertStringContainsString('GP1', $reponse->viewData('contexte'));
        $this->assertStringNotContainsString('Groupe : '.$this->groupe->id, $reponse->viewData('contexte'));
    }

    private function creerEtudiant(string $cin): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => strtolower($cin).'@pdf.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'M2', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');
        $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'M2', 'statut' => 'en_cours',
        ]);

        return $etudiant;
    }
}
