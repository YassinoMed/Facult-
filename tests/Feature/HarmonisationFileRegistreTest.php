<?php

namespace Tests\Feature;

use App\Jobs\GeneratePopulationExport;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\ImportReport;
use App\Models\User;
use App\Models\VueSauvegardee;
use App\Modules\Enseignement\Models\SousGroupe;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Modules\StudentDossiers\Contracts\PdfGeneratorInterface;
use App\Services\Exports\ListeEtudiantsService;
use App\Services\FileTraitementService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\TestCase;

/**
 * LOOP HARMONISATION — cohérence file de traitement / registre étudiants :
 * filtre d'ancienneté de dépôt dans tous les exports, réinitialisation,
 * compteur de filtres, permissions visuelles et libellés de recherche.
 */
class HarmonisationFileRegistreTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private User $scolarite;

    private User $agentFaculteA;

    private User $consultation;

    private Programme $programmeA;

    private Group $groupeA;

    private SousGroupe $sousGroupeA;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->faculteA = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'FMT', 'code' => 'FMT', 'active' => true]);

        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.harmo@test.tn', 'password' => 'Secret123!',
        ], 'admin_scolarite');
        $this->agentFaculteA = User::creerCompteBackOffice([
            'name' => 'Agent A', 'email' => 'agent.a@test.tn', 'password' => 'Secret123!',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');
        $this->consultation = User::creerCompteBackOffice([
            'name' => 'Lecteur', 'email' => 'lecteur.harmo@test.tn', 'password' => 'Secret123!',
        ], 'consultation');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->programmeA = Programme::create([
            'faculte_id' => $this->faculteA->id, 'code' => 'LIC-H', 'nom' => 'Licence H',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $this->groupeA = Group::create([
            'code' => 'GH1', 'nom' => 'GH1', 'faculte_id' => $this->faculteA->id,
            'niveau' => 'L1', 'capacite' => 30, 'actif' => true,
        ]);
        $this->sousGroupeA = SousGroupe::create([
            'groupe_id' => $this->groupeA->id, 'code' => 'GH1-TD1', 'nom' => 'TD1',
            'type' => 'td', 'actif' => true,
        ]);
    }

    private function dossier(string $nom, int $faculteId, array $extra = []): Etudiant
    {
        static $n = 1;

        return Etudiant::create(array_merge([
            'nom' => $nom, 'prenom' => 'P', 'email' => "h{$n}@test.tn",
            'cin' => sprintf('710000%02d', $n++), 'sexe' => 'M', 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'adr', 'ville' => 'Tunis',
            'code_postal' => '1000', 'annee_bac' => '2025', 'faculte_id' => $faculteId,
        ], $extra));
    }

    private function dater(Etudiant $etudiant, Carbon $date): void
    {
        DB::table('etudiants')->where('id', $etudiant->id)->update(['created_at' => $date]);
    }

    private function noms($response): array
    {
        return $response->viewData('etudiants')->getCollection()->pluck('nom')->all();
    }

    public function test_depose_depuis_identique_liste_preview_et_csv(): void
    {
        $this->travelTo(Carbon::parse('2026-10-22 12:00:00'));

        try {
            $ancien = $this->dossier('AncienDossier', $this->faculteA->id);
            $limite = $this->dossier('LimiteDossier', $this->faculteA->id);
            $recent = $this->dossier('RecentDossier', $this->faculteA->id);
            $this->dater($ancien, now()->subDays(5));
            $this->dater($limite, now()->subDays(2));
            $this->dater($recent, now()->subDays(2)->addMinute());

            $liste = $this->actingAs($this->scolarite)
                ->get(route('admin.etudiants.index', ['depose_depuis' => 2]))
                ->assertOk();
            $noms = $this->noms($liste);
            $this->assertContains('AncienDossier', $noms);
            $this->assertContains('LimiteDossier', $noms);
            $this->assertNotContains('RecentDossier', $noms);

            $preview = $this->actingAs($this->scolarite)
                ->get(route('admin.listes.preview', ['depose_depuis' => 2]))
                ->assertOk();
            $this->assertSame(2, $preview->viewData('total'));

            $csv = $this->actingAs($this->scolarite)
                ->get(route('admin.listes.exporter', ['format' => 'csv', 'depose_depuis' => 2]))
                ->assertOk();
            $contenu = $csv->streamedContent();
            $this->assertStringContainsString('AncienDossier', $contenu);
            $this->assertStringContainsString('LimiteDossier', $contenu);
            $this->assertStringNotContainsString('RecentDossier', $contenu);
        } finally {
            $this->travelBack();
        }
    }

    public function test_depose_depuis_pdf_impression_et_xlsx(): void
    {
        $this->travelTo(Carbon::parse('2026-10-22 12:00:00'));

        try {
            $ancien = $this->dossier('PdfAncien', $this->faculteA->id);
            $recent = $this->dossier('PdfRecent', $this->faculteA->id);
            $this->dater($ancien, now()->subDays(4));
            $this->dater($recent, now()->subDay());

            // PDF : le générateur reçoit le HTML de la population filtrée.
            $pdf = new class implements PdfGeneratorInterface
            {
                public string $html = '';

                public function generer(string $html, array $options = []): string
                {
                    $this->html = $html;

                    return '%PDF-1.4 fake';
                }
            };
            $this->app->instance(PdfGeneratorInterface::class, $pdf);

            $this->actingAs($this->scolarite)
                ->get(route('admin.listes.exporter', ['format' => 'pdf', 'depose_depuis' => 2]))
                ->assertOk()
                ->streamedContent();

            $this->assertStringContainsString('PdfAncien', $pdf->html);
            $this->assertStringNotContainsString('PdfRecent', $pdf->html);

            // Impression : même moteur, même filtre.
            $this->actingAs($this->scolarite)
                ->get(route('admin.listes.imprimer', ['depose_depuis' => 2]))
                ->assertOk()
                ->assertSee('PdfAncien')
                ->assertDontSee('PdfRecent');

            // XLSX : relecture OpenSpout pour vérifier les lignes réellement écrites.
            $xlsx = $this->actingAs($this->scolarite)
                ->get(route('admin.listes.exporter', ['format' => 'xlsx', 'depose_depuis' => 2]))
                ->assertOk();
            $chemin = tempnam(sys_get_temp_dir(), 'harmo_xlsx_').'.xlsx';
            file_put_contents($chemin, $xlsx->streamedContent());

            $lignes = [];
            $reader = new XlsxReader(new XlsxOptions);
            $reader->open($chemin);
            try {
                foreach ($reader->getSheetIterator() as $feuille) {
                    foreach ($feuille->getRowIterator() as $ligne) {
                        $lignes[] = implode('|', array_map('strval', $ligne->toArray()));
                    }
                }
            } finally {
                $reader->close();
                @unlink($chemin);
            }

            $contenuXlsx = implode("\n", $lignes);
            $this->assertStringContainsString('PdfAncien', $contenuXlsx);
            $this->assertStringNotContainsString('PdfRecent', $contenuXlsx);
        } finally {
            $this->travelBack();
        }
    }

    public function test_depose_depuis_valide_strictement_partout(): void
    {
        $this->dossier('Valide', $this->faculteA->id);

        foreach ([0, -1, 3651, 'abc'] as $valeur) {
            $this->actingAs($this->scolarite)
                ->get(route('admin.etudiants.index', ['depose_depuis' => $valeur]))
                ->assertSessionHasErrors('depose_depuis');

            $this->actingAs($this->scolarite)
                ->get(route('admin.listes.exporter', ['format' => 'csv', 'depose_depuis' => $valeur]))
                ->assertSessionHasErrors('depose_depuis');

            $this->actingAs($this->scolarite)
                ->get(route('admin.listes.preview', ['depose_depuis' => $valeur]))
                ->assertSessionHasErrors('depose_depuis');
        }
    }

    public function test_depose_depuis_combine_faculte_statut_et_recherche(): void
    {
        $this->travelTo(Carbon::parse('2026-10-22 12:00:00'));

        try {
            $cible = $this->dossier('CibleCombo', $this->faculteA->id);
            $this->dater($cible, now()->subDays(3));

            $autreStatut = $this->dossier('CibleValidee', $this->faculteA->id);
            $autreStatut->forceFill(['statut' => 'valide'])->save();
            $this->dater($autreStatut, now()->subDays(3));

            $autreFaculte = $this->dossier('CibleFaculteB', $this->faculteB->id);
            $this->dater($autreFaculte, now()->subDays(3));

            $recent = $this->dossier('CibleRecente', $this->faculteA->id);
            $this->dater($recent, now()->subDay());

            $response = $this->actingAs($this->scolarite)->get(route('admin.etudiants.index', [
                'depose_depuis' => 2,
                'faculte_id' => $this->faculteA->id,
                'statut' => 'en_attente',
                'search' => 'Cible',
            ]))->assertOk();

            $this->assertSame(['CibleCombo'], $this->noms($response));
        } finally {
            $this->travelBack();
        }
    }

    public function test_filtre_faculte_effectif_pour_un_compte_central(): void
    {
        $this->dossier('EtudiantA', $this->faculteA->id);
        $this->dossier('EtudiantB', $this->faculteB->id);

        $liste = $this->actingAs($this->scolarite)
            ->get(route('admin.etudiants.index', ['faculte_id' => $this->faculteA->id]))
            ->assertOk();
        $this->assertSame(['EtudiantA'], $this->noms($liste));

        $csv = $this->actingAs($this->scolarite)
            ->get(route('admin.listes.exporter', ['format' => 'csv', 'faculte_id' => $this->faculteA->id]))
            ->assertOk();
        $contenu = $csv->streamedContent();
        $this->assertStringContainsString('EtudiantA', $contenu);
        $this->assertStringNotContainsString('EtudiantB', $contenu);
    }

    public function test_export_differe_conserve_depose_depuis(): void
    {
        $this->travelTo(Carbon::parse('2026-10-22 12:00:00'));

        try {
            $ancien = $this->dossier('DiffereAncien', $this->faculteA->id);
            $recent = $this->dossier('DiffereRecent', $this->faculteA->id);
            $this->dater($ancien, now()->subDays(4));
            $this->dater($recent, now()->subDay());

            $rapport = ImportReport::create([
                'user_id' => $this->scolarite->id, 'integration' => 'listes', 'direction' => 'export',
                'type' => 'listes.etudiants', 'format' => 'csv', 'external_reference' => 'EXP-HARMO-01',
                'status' => 'pending', 'preview' => ['filtres' => ['depose_depuis' => 2]],
                'expires_at' => now()->addHours(168),
            ]);

            app(GeneratePopulationExport::class, [
                'rapportId' => $rapport->id, 'userId' => $this->scolarite->id, 'format' => 'csv',
                'filtres' => ['depose_depuis' => 2], 'colonnes' => ['numero', 'nom'], 'regroupement' => null,
            ])->handle(app(ListeEtudiantsService::class), app(NotificationService::class));

            $rapport->refresh();
            $this->assertSame('completed', $rapport->status);
            $contenu = Storage::disk('local')->get($rapport->file_path);
            $this->assertStringContainsString('DiffereAncien', $contenu);
            $this->assertStringNotContainsString('DiffereRecent', $contenu);
        } finally {
            $this->travelBack();
        }
    }

    public function test_reinitialisation_visible_pour_tous_les_filtres_acceptes(): void
    {
        $etudiant = $this->dossier('Reset', $this->faculteA->id);
        $etudiant->forceFill([
            'programme_id' => $this->programmeA->id,
            'groupe_id' => $this->groupeA->id,
        ])->save();

        $filtres = [
            ['search' => 'Reset'],
            ['faculte_id' => $this->faculteA->id],
            ['programme_id' => $this->programmeA->id],
            ['niveau' => 'L1'],
            ['groupe_id' => $this->groupeA->id],
            ['sous_groupe_id' => $this->sousGroupeA->id],
            ['annee_universitaire_id' => $this->annee->id],
            ['statut' => 'en_attente'],
            ['sans_groupe' => 1],
            ['depose_depuis' => 7],
            ['tri' => 'created_at'],
            ['sens' => 'desc'],
        ];

        foreach ($filtres as $filtre) {
            $this->actingAs($this->scolarite)
                ->get(route('admin.etudiants.index', $filtre))
                ->assertOk()
                ->assertSee('aria-label="Réinitialiser les filtres"', false);
        }

        // Valeurs vides ou égales aux valeurs par défaut : aucun filtre actif.
        foreach ([
            ['search' => '', 'programme_id' => '', 'sans_groupe' => 0],
            ['tri' => 'nom', 'sens' => 'asc'],
        ] as $filtre) {
            $this->actingAs($this->scolarite)
                ->get(route('admin.etudiants.index', $filtre))
                ->assertOk()
                ->assertDontSee('aria-label="Réinitialiser les filtres"', false);
        }

        $combine = $this->actingAs($this->scolarite)->get(route('admin.etudiants.index', [
            'programme_id' => $this->programmeA->id,
            'groupe_id' => $this->groupeA->id,
            'depose_depuis' => 7,
        ]))->assertOk();
        $combine->assertSee('aria-label="Réinitialiser les filtres"', false);
        $combine->assertSee(route('admin.etudiants.index', ['reset' => 1]), false);
    }

    public function test_reinitialisation_traverse_une_vue_par_defaut(): void
    {
        $ancien = $this->dossier('VueAncien', $this->faculteA->id);
        $ancien->forceFill(['programme_id' => $this->programmeA->id])->save();
        $this->dater($ancien, now()->subDays(5));
        $recent = $this->dossier('VueRecent', $this->faculteA->id);
        $recent->forceFill(['programme_id' => $this->programmeA->id])->save();

        VueSauvegardee::create([
            'user_id' => $this->scolarite->id, 'tableau' => 'etudiants', 'nom' => 'Anciens',
            'filtres' => ['depose_depuis' => 2, 'programme_id' => $this->programmeA->id],
            'par_defaut' => true,
        ]);

        $defaut = $this->actingAs($this->scolarite)->get(route('admin.etudiants.index'))->assertOk();
        $this->assertSame(['VueAncien'], $this->noms($defaut));
        $this->assertGreaterThan(0, $defaut->viewData('filtresActifs'));
        $defaut->assertSee('aria-label="Réinitialiser les filtres"', false);

        // Le lien de réinitialisation ne doit pas réappliquer la vue par défaut.
        $reset = $this->actingAs($this->scolarite)
            ->get(route('admin.etudiants.index', ['reset' => 1]))->assertOk();
        $this->assertSame(0, $reset->viewData('filtresActifs'));
        $this->assertCount(2, $this->noms($reset));
        $reset->assertDontSee('aria-label="Réinitialiser les filtres"', false);
    }

    public function test_vue_par_defaut_invalide_ignoree_et_perimetre_faculte_preserve(): void
    {
        $this->dossier('PerimetreA', $this->faculteA->id);
        $this->dossier('PerimetreB', $this->faculteB->id);

        VueSauvegardee::create([
            'user_id' => $this->agentFaculteA->id, 'tableau' => 'etudiants', 'nom' => 'Hors périmètre',
            'filtres' => ['faculte_id' => $this->faculteB->id, 'depose_depuis' => 'abc', 'statut' => 'inconnu'],
            'par_defaut' => true,
        ]);

        $response = $this->actingAs($this->agentFaculteA)
            ->get(route('admin.etudiants.index'))->assertOk();

        $this->assertSame(['PerimetreA'], $this->noms($response));
        $this->assertSame($this->faculteA->id, (int) $response->viewData('filtres')['faculte_id']);
        $this->assertSame(0, $response->viewData('filtresActifs'));
    }

    public function test_compteur_file_inclut_categorie_et_exclut_presentation(): void
    {
        $this->dossier('File', $this->faculteA->id);

        $sans = $this->actingAs($this->scolarite)
            ->get(route('admin.inscriptions.file-traitement'))->assertOk();
        $this->assertSame(0, $sans->viewData('filtresActifs'));

        foreach (array_keys(FileTraitementService::CATEGORIES_FILE) as $categorie) {
            $reponse = $this->actingAs($this->scolarite)->get(route('admin.inscriptions.file-traitement', [
                'categorie' => $categorie,
            ]))->assertOk();
            $this->assertSame(1, $reponse->viewData('filtresActifs'), "Catégorie {$categorie} non comptée.");
        }

        $combine = $this->actingAs($this->scolarite)->get(route('admin.inscriptions.file-traitement', [
            'categorie' => FileTraitementService::CATEGORIE_BLOCKED,
            'statut' => 'en_attente',
            'search' => 'File',
        ]))->assertOk();
        $this->assertSame(3, $combine->viewData('filtresActifs'));
        $combine->assertSee('3 filtres actifs', false);

        // Tri et sens sont de la présentation : jamais comptés.
        $presentation = $this->actingAs($this->scolarite)->get(route('admin.inscriptions.file-traitement', [
            'tri' => 'created_at', 'sens' => 'desc',
        ]))->assertOk();
        $this->assertSame(0, $presentation->viewData('filtresActifs'));
    }

    public function test_indicateurs_file_suivent_tous_les_filtres(): void
    {
        $this->dossier('KpiA', $this->faculteA->id);
        $this->dossier('KpiB', $this->faculteB->id);

        $global = $this->actingAs($this->scolarite)
            ->get(route('admin.inscriptions.file-traitement'))->assertOk();
        $this->assertSame(2, $global->viewData('kpis')['total']);
        $this->assertSame(2, $global->viewData('etudiants')->total());

        // La faculté délimite le périmètre des indicateurs.
        $parFaculte = $this->actingAs($this->scolarite)->get(route('admin.inscriptions.file-traitement', [
            'faculte_id' => $this->faculteA->id,
        ]))->assertOk();
        $this->assertSame(1, $parFaculte->viewData('kpis')['total']);
        $this->assertSame(1, $parFaculte->viewData('etudiants')->total());

        // D-01 : les KPI décrivent exactement la liste filtrée, y compris
        // pour les filtres autres que la faculté.
        foreach ([
            ['statut' => 'en_attente', 'attendu' => 2],
            ['search' => 'KpiA', 'attendu' => 1],
            ['depose_depuis' => 7, 'attendu' => 0],
            ['categorie' => FileTraitementService::CATEGORIE_BLOCKED, 'attendu' => 0],
        ] as $cas) {
            $attendu = $cas['attendu'];
            unset($cas['attendu']);

            $reponse = $this->actingAs($this->scolarite)
                ->get(route('admin.inscriptions.file-traitement', $cas))->assertOk();

            $this->assertSame($attendu, $reponse->viewData('kpis')['total'], 'KPI total pour '.json_encode($cas));
            $this->assertSame($attendu, $reponse->viewData('etudiants')->total(), 'Liste pour '.json_encode($cas));
        }
    }

    public function test_actions_non_autorisees_masquees_mais_refusees_serveur(): void
    {
        $etudiant = $this->dossier('Actions', $this->faculteA->id);
        $urlSuppression = route('admin.etudiants.destroy', $etudiant);
        $urlExport = route('admin.listes.exporter', ['format' => 'pdf']);
        $urlNote = route('admin.inscriptions.file-traitement.commenter', $etudiant);

        $autorise = $this->actingAs($this->scolarite)
            ->get(route('admin.etudiants.index'))->assertOk();
        $autorise->assertSee('suppressionNom = ', false);
        // Desktop (menu) + mobile (carte) : les deux points d'entrée restent gatés.
        $this->assertSame(2, substr_count($autorise->getContent(), 'suppressionNom = '));
        $autorise->assertSee($urlExport, false);
        $this->actingAs($this->scolarite)
            ->get(route('admin.inscriptions.file-traitement'))
            ->assertOk()->assertSee($urlNote, false);

        $lecteur = $this->actingAs($this->consultation)
            ->get(route('admin.etudiants.index'))->assertOk();
        // Marqueur propre aux boutons de suppression : l'URL de destroy est
        // identique à celle de la fiche (show), donc inutilisable ici.
        $lecteur->assertDontSee('suppressionNom = ', false);
        $lecteur->assertDontSee($urlExport, false);

        // Le composant d'exports partagé masque aussi les actions sur l'aperçu.
        $this->actingAs($this->consultation)
            ->get(route('admin.listes.preview'))->assertOk()
            ->assertDontSee($urlExport, false);
        $this->actingAs($this->scolarite)
            ->get(route('admin.listes.preview'))->assertOk()
            ->assertSee($urlExport, false);
        $this->actingAs($this->consultation)
            ->get(route('admin.inscriptions.file-traitement'))
            ->assertOk()->assertDontSee($urlNote, false);

        $this->actingAs($this->consultation)
            ->delete($urlSuppression)->assertForbidden();
        $this->actingAs($this->consultation)
            ->get($urlExport)->assertForbidden();
        $this->actingAs($this->consultation)
            ->post($urlNote, ['commentaire' => 'Note non autorisée'])->assertForbidden();
    }

    public function test_suppression_inter_faculte_refusee(): void
    {
        $etudiantB = $this->dossier('HorsPerimetre', $this->faculteB->id);

        $this->actingAs($this->agentFaculteA)
            ->delete(route('admin.etudiants.destroy', $etudiantB))
            ->assertForbidden();

        $this->assertDatabaseHas('etudiants', ['id' => $etudiantB->id]);
    }

    public function test_libelles_recherche_et_indicateurs_de_perimetre(): void
    {
        $this->dossier('Libelle', $this->faculteA->id);

        $registre = $this->actingAs($this->scolarite)
            ->get(route('admin.etudiants.index'))->assertOk();
        $registre->assertSee('placeholder="Nom, prénom, CIN, N° inscription, email, téléphone..."', false);
        $registre->assertDontSee('Recherche instantanée');
        $registre->assertDontSee('ville...');
        $registre->assertSee('Indicateurs de périmètre — non affectés par les filtres de recherche.', false);

        $this->actingAs($this->scolarite)
            ->get(route('admin.inscriptions.file-traitement'))
            ->assertOk()
            ->assertSee('Indicateurs calculés sur les filtres actifs', false)
            ->assertSee('placeholder="Nom, prénom, CIN, matricule, email, téléphone..."', false);
    }
}
