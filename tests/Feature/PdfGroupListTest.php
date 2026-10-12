<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use App\Services\AffectationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PDF groupes (§2.14-2.16, §2.24) : export DataExchange réutilisé +
 * PDF « groupes + étudiants » via regroupement générique.
 */
class PdfGroupListTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.grpdf@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
    }

    public function test_export_groupes_dataexchange_pdf(): void
    {
        Group::create([
            'code' => 'PGL1', 'nom' => 'PGL1', 'faculte_id' => $this->faculte->id,
            'niveau' => 'M2', 'capacite' => 30, 'actif' => true,
        ]);

        $this->actingAs($this->admin)->get(route('admin.donnees.export', [
            'entite' => 'groupes', 'format' => 'pdf',
        ]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_pdf_groupes_avec_etudiants(): void
    {
        $g1 = Group::create(['code' => 'PGL-G01', 'nom' => 'G01', 'faculte_id' => $this->faculte->id, 'niveau' => 'M2', 'capacite' => 30, 'actif' => true]);
        $g2 = Group::create(['code' => 'PGL-G02', 'nom' => 'G02', 'faculte_id' => $this->faculte->id, 'niveau' => 'M2', 'capacite' => 30, 'actif' => true]);
        $e1 = $this->creerEtudiant('PGL00001');
        $e2 = $this->creerEtudiant('PGL00002');
        app(AffectationService::class)->affecter($e1, $g1, $this->annee, $this->admin->id, 'a');
        app(AffectationService::class)->affecter($e2, $g2, $this->annee, $this->admin->id, 'b');

        $reponse = $this->actingAs($this->admin)->get(route('admin.listes.exporter', [
            'format' => 'pdf', 'regroupement' => 'groupe', 'titre' => 'LISTE DES GROUPES AVEC ÉTUDIANTS',
        ]));

        $reponse->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $html = view('pdf.liste', [
            'institution' => config('app.name'), 'titreDocument' => 'LISTE DES GROUPES AVEC ÉTUDIANTS',
            'reference' => 'R', 'dateGeneration' => 'D', 'contexte' => 'C',
            'metadonnees' => ['total' => 2, 'regroupement' => 'Groupe', 'utilisateur' => 'U'],
            'entetes' => ['matricule', 'nom'],
            'lignes' => [],
            'sections' => [
                ['titre' => 'PGL-G01', 'effectif' => 1, 'lignes' => [['matricule' => 'M1', 'nom' => 'N1']]],
                ['titre' => 'PGL-G02', 'effectif' => 1, 'lignes' => [['matricule' => 'M2', 'nom' => 'N2']]],
            ],
            'signature' => false, 'zoneSignature' => false, 'orientation' => 'portrait',
        ])->render();

        $this->assertStringContainsString('PGL-G01', $html);
        $this->assertStringContainsString('PGL-G02', $html);
        $this->assertStringContainsString('Effectif : 1', $html);
    }

    private function creerEtudiant(string $cin): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => strtolower($cin).'@grpdf.test',
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
