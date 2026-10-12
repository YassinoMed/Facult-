<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Enseignement\Models\SousGroupe;
use App\Modules\Schedule\Models\Group;
use App\Services\AffectationService;
use App\Services\Exports\ListeEtudiantsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PDF regroupé (§2.1-2.2, §2.24) : sections génériques, effectifs,
 * totaux, multi-niveaux via clés combinables.
 */
class PdfGroupingTest extends TestCase
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
            'name' => 'Scolarité', 'email' => 'sco.grp@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
    }

    public function test_sections_par_groupe_avec_effectifs(): void
    {
        $g1 = $this->creerGroupe('GG1');
        $g2 = $this->creerGroupe('GG2');
        $this->creerEtudiant('GRP00001', ['groupe_id' => $g1->id]);
        $this->creerEtudiant('GRP00002', ['groupe_id' => $g1->id]);
        $this->creerEtudiant('GRP00003', ['groupe_id' => $g2->id]);

        $service = app(ListeEtudiantsService::class);
        $resultat = $service->sectionsRegroupees($service->requete([], $this->admin), $this->admin, null, 'groupe');

        $this->assertSame(3, $resultat['total']);
        $this->assertCount(2, $resultat['sections']);
        $this->assertSame('GG1', $resultat['sections'][0]['titre']);
        $this->assertSame(2, $resultat['sections'][0]['effectif']);
        $this->assertSame(1, $resultat['sections'][1]['effectif']);
    }

    public function test_alias_parcours_et_ecue_normalises(): void
    {
        $this->assertSame('programme', ListeEtudiantsService::normaliserRegroupement('parcours'));
        $this->assertSame('matiere', ListeEtudiantsService::normaliserRegroupement('ecue'));
        $this->assertSame('matiere', ListeEtudiantsService::normaliserRegroupement('module'));
        $this->assertNull(ListeEtudiantsService::normaliserRegroupement('inconnu'));
        $this->assertNull(ListeEtudiantsService::normaliserRegroupement(null));
    }

    public function test_sections_sous_groupe_statut_niveau(): void
    {
        $groupe = $this->creerGroupe('GH1');
        $sg = SousGroupe::create([
            'groupe_id' => $groupe->id, 'code' => 'GH1-TD1', 'nom' => 'TD1', 'type' => 'td', 'actif' => true,
        ]);
        $avec = $this->creerEtudiant('GRP00004', ['groupe_id' => $groupe->id]);
        $this->creerEtudiant('GRP00005', ['groupe_id' => $groupe->id]);
        app(AffectationService::class)->affecter($avec, $groupe, $this->annee, $this->admin->id, 'a', false, $sg->id);
        $second = Etudiant::where('cin', 'GRP00005')->firstOrFail();
        app(AffectationService::class)->affecter($second, $groupe, $this->annee, $this->admin->id, 'b');

        $service = app(ListeEtudiantsService::class);

        $parSg = $service->sectionsRegroupees($service->requete([], $this->admin), $this->admin, null, 'sous_groupe');
        $titres = array_column($parSg['sections'], 'titre');
        $this->assertContains('GH1-TD1', $titres);

        $parNiveau = $service->sectionsRegroupees($service->requete([], $this->admin), $this->admin, null, 'niveau');
        $this->assertSame('L1', $parNiveau['sections'][0]['titre']);

        $parStatut = $service->sectionsRegroupees($service->requete([], $this->admin), $this->admin, null, 'statut');
        $this->assertSame('valide', $parStatut['sections'][0]['titre']);
    }

    public function test_pdf_regroupe_telechargeable(): void
    {
        $groupe = $this->creerGroupe('GI1');
        $this->creerEtudiant('GRP00006', ['groupe_id' => $groupe->id]);

        $this->actingAs($this->admin)->get(route('admin.listes.exporter', [
            'format' => 'pdf', 'regroupement' => 'groupe',
        ]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_regroupement_invalide_ignore(): void
    {
        $this->creerEtudiant('GRP00007', []);

        $this->actingAs($this->admin)->get(route('admin.listes.exporter', [
            'format' => 'csv', 'regroupement' => 'inconnu',
        ]))->assertOk();
    }

    private function creerGroupe(string $code): Group
    {
        return Group::create([
            'code' => $code, 'nom' => $code, 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'capacite' => 30, 'actif' => true,
        ]);
    }

    private function creerEtudiant(string $cin, array $attributs = []): Etudiant
    {
        $etudiant = Etudiant::create(array_merge([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => strtolower($cin).'@grp.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ], $attributs));
        $etudiant->definirStatut('valide');
        $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
        ]);

        return $etudiant;
    }
}
