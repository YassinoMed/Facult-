<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\ImportReport;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sécurisation de l'impression et des exports de listes :
 * `data.export` est une garde serveur, jamais un simple masquage d'UI.
 * Le cloisonnement faculté et `exports.personal` restent inchangés.
 */
class PrintExportSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $scolarite;

    private User $agent;

    private User $consultation;

    private User $adminFaculte;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private Group $groupeA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteA = Faculte::create([
            'nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true,
        ]);
        $this->faculteB = Faculte::create([
            'nom' => 'FMP', 'code' => 'FMP', 'description' => 'T', 'active' => true,
        ]);

        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.print@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'agent.print@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        $this->consultation = User::creerCompteBackOffice([
            'name' => 'Consultation', 'email' => 'cons.print@universite.test', 'password' => 'Motdepasse@123',
        ], 'consultation');

        $this->adminFaculte = User::creerCompteBackOffice([
            'name' => 'Admin faculté', 'email' => 'fac.print@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $this->groupeA = Group::create([
            'code' => 'GPRINT', 'nom' => 'Groupe impression', 'faculte_id' => $this->faculteA->id,
            'niveau' => 'L1', 'capacite' => 30, 'actif' => true,
        ]);
    }

    public function test_impression_autorisee_avec_data_export_et_filtre_valide(): void
    {
        $this->etudiant('PRT00001', ['nom' => 'CiblePrint']);
        $this->etudiant('PRT00002', ['nom' => 'AutrePrint']);

        $reponse = $this->actingAs($this->scolarite)->get(route('admin.listes.imprimer', [
            'search' => 'CiblePrint',
        ]));

        $reponse->assertOk()
            ->assertSee('CiblePrint')
            ->assertSee('window.print', false);

        $lignes = $reponse->viewData('jeu')['lignes'];
        $this->assertCount(1, $lignes);
        $this->assertSame('CiblePrint', $lignes[0]['nom']);
    }

    public function test_acces_direct_refuse_sans_data_export_et_aucune_donnee_rendue(): void
    {
        $this->etudiant('PRT00003', ['nom' => 'ConfidentielPrint']);

        $reponse = $this->actingAs($this->consultation)->get(route('admin.listes.imprimer'));

        $reponse->assertForbidden();
        $reponse->assertDontSee('ConfidentielPrint');
    }

    public function test_impression_refusee_pour_invite(): void
    {
        $this->get(route('admin.listes.imprimer'))
            ->assertRedirect(route('admin.connexion.show'));
    }

    public function test_permission_verifiee_avant_la_validation_des_filtres(): void
    {
        // Sans permission : refus immédiat, même avec un filtre invalide.
        $this->actingAs($this->consultation)
            ->get(route('admin.listes.imprimer', ['niveau' => 'INVALIDE']))
            ->assertForbidden();

        // Avec permission : la validation métier reste inchangée.
        $this->actingAs($this->scolarite)
            ->get(route('admin.listes.imprimer', ['niveau' => 'INVALIDE']))
            ->assertSessionHasErrors('niveau');
    }

    public function test_impression_respecte_le_cloisonnement_faculte(): void
    {
        $this->etudiant('PRT00004', ['nom' => 'EtudiantFacA']);
        $this->etudiant('PRT00005', ['nom' => 'EtudiantFacB', 'faculte_id' => $this->faculteB->id]);

        // Tentative de sortir du périmètre : la faculté serveur reste forcée.
        $reponse = $this->actingAs($this->adminFaculte)->get(route('admin.listes.imprimer', [
            'faculte_id' => $this->faculteB->id,
        ]));

        $reponse->assertOk()
            ->assertSee('EtudiantFacA')
            ->assertDontSee('EtudiantFacB');

        $this->assertSame($this->faculteA->id, (int) $reponse->viewData('filtres')['faculte_id']);
        $this->assertSame(['EtudiantFacA'], array_column($reponse->viewData('jeu')['lignes'], 'nom'));
    }

    public function test_impression_masque_les_donnees_personnelles_sans_exports_personal(): void
    {
        $this->etudiant('BK998877', [
            'nom' => 'MasquePrint',
            'email' => 'secret.print@universite.test',
            'telephone' => '0611223344',
        ]);

        $reponse = $this->actingAs($this->agent)->get(route('admin.listes.imprimer'));

        $reponse->assertOk()
            ->assertSee('MasquePrint')
            ->assertDontSee('BK998877')
            ->assertSee('BK***877')
            ->assertDontSee('secret.print@universite.test')
            ->assertDontSee('0611223344');
    }

    public function test_impression_affiche_les_donnees_personnelles_avec_permission(): void
    {
        $this->etudiant('BK998877', [
            'nom' => 'CompletPrint',
            'email' => 'complet.print@universite.test',
            'telephone' => '0611223344',
        ]);

        $reponse = $this->actingAs($this->scolarite)->get(route('admin.listes.imprimer'));

        $reponse->assertOk()
            ->assertSee('CompletPrint')
            ->assertSee('BK998877')
            ->assertSee('complet.print@universite.test')
            ->assertSee('0611223344');
    }

    public function test_exports_pdf_excel_csv_non_regression_et_permission(): void
    {
        $this->etudiant('PRT00006', ['nom' => 'ExportPrint']);

        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $reponse = $this->actingAs($this->scolarite)
                ->get(route('admin.listes.exporter', ['format' => $format]));
            $reponse->assertOk();

            if ($format === 'csv') {
                $this->assertStringContainsString('ExportPrint', $reponse->streamedContent());
            }

            $this->actingAs($this->consultation)
                ->get(route('admin.listes.exporter', ['format' => $format]))
                ->assertForbidden();
        }
    }

    public function test_lien_impression_masque_sans_data_export_sur_la_fiche_groupe(): void
    {
        $url = route('admin.listes.imprimer', ['groupe_id' => $this->groupeA->id]);

        $this->actingAs($this->consultation)
            ->get(route('admin.groupes.show', $this->groupeA))
            ->assertOk()
            ->assertDontSee($url, false);

        $this->actingAs($this->scolarite)
            ->get(route('admin.groupes.show', $this->groupeA))
            ->assertOk()
            ->assertSee($url, false);
    }

    public function test_historique_des_exports_exige_data_export_et_lien_masque(): void
    {
        $this->etudiant('PRT00007', ['nom' => 'HistoriquePrint']);

        $this->actingAs($this->consultation)
            ->get(route('admin.listes.exports'))
            ->assertForbidden();

        $this->actingAs($this->agent)
            ->get(route('admin.listes.exports'))
            ->assertOk();

        $url = route('admin.listes.exports');

        $this->actingAs($this->consultation)
            ->get(route('admin.listes.preview'))
            ->assertOk()
            ->assertDontSee($url, false);

        $this->actingAs($this->agent)
            ->get(route('admin.listes.preview'))
            ->assertOk()
            ->assertSee($url, false);
    }

    public function test_telechargement_export_differe_exige_data_export(): void
    {
        Storage::fake('local');

        $sansPermission = $this->rapportExport($this->consultation, 'listes/exports/sans-permission.csv');
        $avecPermission = $this->rapportExport($this->scolarite, 'listes/exports/avec-permission.csv');
        Storage::disk('local')->put('listes/exports/sans-permission.csv', 'contenu');
        Storage::disk('local')->put('listes/exports/avec-permission.csv', 'contenu');

        // Propriétaire mais permission retirée : refus serveur.
        $this->actingAs($this->consultation)
            ->get(route('admin.listes.exports.download', $sansPermission))
            ->assertForbidden();

        // Propriétaire autorisé : comportement conservé.
        $this->actingAs($this->scolarite)
            ->get(route('admin.listes.exports.download', $avecPermission))
            ->assertOk();
    }

    private function rapportExport(User $utilisateur, string $chemin): ImportReport
    {
        return ImportReport::create([
            'user_id' => $utilisateur->id,
            'integration' => 'listes',
            'direction' => 'export',
            'type' => 'listes.etudiants',
            'format' => 'csv',
            'external_reference' => 'EXP-PRINT-SECU',
            'status' => 'completed',
            'file_path' => $chemin,
            'filename' => basename($chemin),
            'expires_at' => now()->addHours(168),
        ]);
    }

    private function etudiant(string $cin, array $attributs = []): Etudiant
    {
        $etudiant = Etudiant::create(array_merge([
            'cin' => $cin,
            'nom' => 'Benali',
            'prenom' => 'Sara',
            'email' => strtolower($cin).'@print.test',
            'telephone' => '0612345678',
            'date_naissance' => '2004-01-01',
            'sexe' => 'F',
            'faculte_id' => $this->faculteA->id,
            'groupe_id' => $this->groupeA->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'X',
            'ville' => 'Casa',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ], $attributs));

        $etudiant->definirStatut('valide');
        $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1',
            'statut' => 'en_cours',
        ]);

        return $etudiant;
    }
}
