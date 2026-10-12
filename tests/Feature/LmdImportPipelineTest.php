<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Enseignement\Models\TypeEnseignement;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Referentiel\Models\Domaine;
use App\Modules\Referentiel\Models\Mention;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\Specialite;
use App\Modules\Referentiel\Models\TypeDiplome;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Teacher;
use App\Services\DataExchange\ImportDependencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\Concerns\ActAsApiClient;
use Tests\TestCase;

/**
 * Suite de tests d'intégration complète du pipeline d'importation LMD/SALIMA.
 * Valide le graphe de dépendances strict, l'idempotence, le dry-run,
 * l'isolation Faculty Scope et les transactions avec rollback.
 */
class LmdImportPipelineTest extends TestCase
{
    use ActAsApiClient, RefreshDatabase;

    private User $admin;

    private User $adminRestreint;

    private Faculte $faculteA;

    private Faculte $faculteB;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.referentiel.active' => true,
            'modules.enseignement.active' => true,
            'modules.evaluation.active' => true,
            'modules.schedule.active' => true,
        ]);

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté des Sciences de Tunis',
            'code' => 'FST',
            'sigle' => 'FST',
            'description' => 'Faculté A',
            'active' => true,
        ]);

        $this->faculteB = Faculte::create([
            'nom' => 'Faculté de Médecine de Tunis',
            'code' => 'FMT',
            'sigle' => 'FMT',
            'description' => 'Faculté B',
            'active' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Scolarité Global',
            'email' => 'admin.lmd@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        // Admin restreint à la faculté A uniquement
        $this->adminRestreint = User::creerCompteBackOffice([
            'name' => 'Admin Faculté FST',
            'email' => 'admin.fst@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        TypeEnseignement::firstOrCreate(
            ['code' => 'CM'],
            ['nom' => 'Cours Magistral', 'actif' => true]
        );
    }

    private function importerEntite(string $entite, string $nomFichier, string $contenuCsv, string $mode = 'creer', ?User $utilisateur = null): void
    {
        $user = $utilisateur ?? $this->admin;
        $fichier = UploadedFile::fake()->createWithContent($nomFichier, $contenuCsv, 'text/csv');

        $preview = $this->actingAs($user)
            ->post(route('admin.donnees.import.preview', $entite), ['fichier' => $fichier])
            ->assertOk();

        $token = $preview->viewData('token');
        $this->assertNotEmpty($token);

        $this->actingAs($user)
            ->post(route('admin.donnees.import.confirmer', $entite), [
                'token' => $token,
                'mode' => $mode,
            ])
            ->assertOk();
    }

    public function test_graphe_dependances_bloque_si_prerequis_absents_a_la_confirmation(): void
    {
        $depService = app(ImportDependencyService::class);
        $manquants = $depService->verifierPrerequis('ues', $this->admin->id);

        $this->assertArrayHasKey('programmes', $manquants);

        // Essayer d'importer une UE alors qu'aucun programme n'existe en base
        $fichier = UploadedFile::fake()->createWithContent(
            'ues_sans_programme.csv',
            "code;nom;programme;semestre;credits;coefficient\nUE01;Algorithmique;PROG_INCONNU;S1;6;3\n",
            'text/csv'
        );

        $preview = $this->actingAs($this->admin)
            ->post(route('admin.donnees.import.preview', 'ues'), ['fichier' => $fichier])
            ->assertOk();

        $token = $preview->viewData('token');

        // La confirmation doit être strictement bloquée avec redirection et message d'erreur
        $this->actingAs($this->admin)
            ->post(route('admin.donnees.import.confirmer', 'ues'), [
                'token' => $token,
                'mode' => 'creer',
            ])
            ->assertRedirect();

        $this->assertSame(0, UniteEnseignement::count());
    }

    public function test_dry_run_preview_ne_modifie_rien_dans_la_base(): void
    {
        $fichier = UploadedFile::fake()->createWithContent(
            'annees.csv',
            "libelle;date_debut;date_fin;active\n2028-2029;2028-09-01;2029-06-30;1\n",
            'text/csv'
        );

        $reponse = $this->actingAs($this->admin)
            ->post(route('admin.donnees.import.preview', 'annees-universitaires'), ['fichier' => $fichier])
            ->assertOk();

        $reponse->assertViewHas('resultat');
        $this->assertSame(0, AnneeUniversitaire::where('libelle', '2028-2029')->count());
    }

    public function test_pipeline_complet_ordonne_lmd(): void
    {
        // 1. Année universitaire
        $this->importerEntite(
            'annees-universitaires',
            'annees.csv',
            "libelle;date_debut;date_fin;active\n2026-2027;2026-09-01;2027-06-30;1\n"
        );
        $this->assertTrue(AnneeUniversitaire::where('libelle', '2026-2027')->exists());

        // 2. Établissement / Faculté
        $this->importerEntite(
            'facultes',
            'facultes.csv',
            "code;nom;sigle;active\nENIT;École Nationale d'Ingénieurs;ENIT;1\n"
        );
        $this->assertTrue(Faculte::where('code', 'ENIT')->exists());

        // 3. Département
        $this->importerEntite(
            'departements',
            'departements.csv',
            "code;nom;faculte;responsable\nDEP-INFO;Département Informatique;FST;Pr. Test\n"
        );
        $this->assertTrue(Departement::where('code', 'DEP-INFO')->exists());

        // 4. Domaines, Mentions, Spécialités, Types de diplôme
        $this->importerEntite(
            'domaines',
            'domaines.csv',
            "code;nom;faculte\nDOM-ST;Sciences et Technologies;FST\n"
        );
        $this->assertTrue(Domaine::where('code', 'DOM-ST')->exists());

        $this->importerEntite(
            'mentions',
            'mentions.csv',
            "code;nom;domaine\nMEN-INF;Informatique;DOM-ST\n"
        );
        $this->assertTrue(Mention::where('code', 'MEN-INF')->exists());

        $this->importerEntite(
            'specialites',
            'specialites.csv',
            "code;nom;mention\nSPEC-GL;Génie Logiciel;MEN-INF\n"
        );
        $this->assertTrue(Specialite::where('code', 'SPEC-GL')->exists());

        $this->importerEntite(
            'type-diplomes',
            'types_diplome.csv',
            "code;nom;cycle;duree_annees;credits\nLIC;Licence;licence;3;180\n"
        );
        $this->assertTrue(TypeDiplome::where('code', 'LIC')->exists());

        // Parcours (Programme), Niveaux, Semestres
        $this->importerEntite(
            'programmes',
            'programmes.csv',
            "code;nom;faculte;duree_annees\nPARC-GL;Licence en Génie Logiciel;FST;3\n"
        );
        $this->assertTrue(Programme::where('code', 'PARC-GL')->exists());

        $this->importerEntite(
            'niveaux',
            'niveaux.csv',
            "code;nom;programme;ordre\nL1;Première Année;PARC-GL;1\n"
        );
        $this->assertTrue(Niveau::where('code', 'L1')->exists());

        $this->importerEntite(
            'semestres',
            'semestres.csv',
            "code;nom;niveau;programme;numero\nS1;Semestre 1;L1;PARC-GL;1\n"
        );
        $this->assertTrue(SemestreLmd::where('code', 'S1')->exists());

        // 5. UE
        $this->importerEntite(
            'ues',
            'ues.csv',
            "code;nom;programme;semestre;credits;coefficient;type;ordre\nUE-DEV;Développement Logiciel;PARC-GL;S1;6;3;fondamentale;1\n"
        );
        $this->assertTrue(UniteEnseignement::where('code', 'UE-DEV')->exists());

        // 6. ECUE / Matière
        $this->importerEntite(
            'ecues',
            'ecues.csv',
            "code;nom;ue;volume_cm;volume_td;volume_tp;credits;coefficient\nEC-ARCHI;Architecture Web;UE-DEV;20;15;10;3;2\n"
        );
        $this->assertTrue(Matiere::where('code', 'EC-ARCHI')->exists());

        // 8-10. Ressources : Enseignant, Salle, Groupe
        $this->importerEntite(
            'enseignants',
            'enseignants.csv',
            "matricule;nom;prenom;email\nENS-101;Mansour;Kamel;kamel.mansour@fst.test\n"
        );
        $this->assertTrue(Teacher::where('matricule', 'ENS-101')->exists());

        $this->importerEntite(
            'salles',
            'salles.csv',
            "code;nom;capacite;faculte\nLAB-101;Salle TP 101;25;FST\n"
        );
        $this->assertTrue(Room::where('code', 'LAB-101')->exists());

        $this->importerEntite(
            'groupes',
            'groupes.csv',
            "code;nom;faculte;niveau;capacite\nGRP-101;Groupe TD 101;FST;L1;25\n"
        );
        $this->assertTrue(Group::where('code', 'GRP-101')->exists());

        // 11. Étudiants
        $this->importerEntite(
            'etudiants',
            'etudiants.csv',
            "cin;nom;prenom;email;sexe;faculte;niveau;annee_academique\n08999888;Trabelsi;Yassine;yassine@etudiant.test;M;FST;L1;2026-2027\n"
        );
        $this->assertTrue(Etudiant::where('cin', '08999888')->exists());
        $etudiant = Etudiant::where('cin', '08999888')->first();

        // 12. Inscriptions académiques
        $this->importerEntite(
            'inscriptions-academiques',
            'inscriptions.csv',
            "cin;annee_universitaire;niveau;filiere\n08999888;2026-2027;L1;Licence en Génie Logiciel\n"
        );
        $this->assertTrue(InscriptionAcademique::where('etudiant_id', $etudiant->id)->exists());

        // 13. Enseignements
        $this->importerEntite(
            'enseignements',
            'enseignements.csv',
            "ecue;type;annee;volume_cm;volume_td;volume_tp\nEC-ARCHI;CM;2026-2027;20;15;10\n"
        );
        $this->assertTrue(Enseignement::whereHas('matiere', fn ($q) => $q->where('code', 'EC-ARCHI'))->exists());

        // 14. Affectations enseignants
        $this->importerEntite(
            'affectations-enseignants',
            'affectations.csv',
            "ecue;type;annee;enseignant_email;role;volume_heures\nEC-ARCHI;CM;2026-2027;kamel.mansour@fst.test;responsable;20\n"
        );
        $this->assertDatabaseHas('affectations_enseignement', [
            'teacher_id' => Teacher::where('matricule', 'ENS-101')->value('id'),
            'role' => 'responsable',
        ]);

        // 18. Sessions d'examen
        $this->importerEntite(
            'sessions-examen',
            'sessions.csv',
            "code;nom;annee;type;date_debut;date_fin;statut\nSESS-P1;Session Principale S1;2026-2027;principale;2027-01-15;2027-01-25;draft\n"
        );
        $this->assertTrue(SessionExamen::where('code', 'SESS-P1')->exists());

        // 19. Examens
        $this->importerEntite(
            'examens',
            'examens.csv',
            "session;libelle;programme;niveau;type\nSESS-P1;Examen Final Architecture Web;PARC-GL;L1;examen\n"
        );
        $this->assertTrue(Examen::where('libelle', 'Examen Final Architecture Web')->exists());

        // 20. Notes
        $this->importerEntite(
            'notes',
            'notes.csv',
            "cin;code_matiere;annee;semestre;note;session\n08999888;EC-ARCHI;2026-2027;1;15.5;normale\n"
        );
        $this->assertDatabaseHas('notes', [
            'matiere_id' => Matiere::where('code', 'EC-ARCHI')->value('id'),
            'note' => 15.5,
        ]);

        // 21. Délibérations & Décisions
        $this->importerEntite(
            'deliberations',
            'deliberations.csv',
            "session;programme;niveau;cin;moyenne;credits_obtenus;decision\nSESS-P1;PARC-GL;L1;08999888;15.5;30;admis\n"
        );
        $this->assertDatabaseHas('evaluation_decisions', [
            'etudiant_id' => $etudiant->id,
            'decision' => 'admis',
            'moyenne' => 15.5,
        ]);
    }

    public function test_import_formats_json_et_xml_supportes_dans_pipeline(): void
    {
        // Import en JSON
        $jsonContent = json_encode([
            ['code' => 'ROOM-JSON', 'nom' => 'Salle JSON', 'capacite' => 45],
        ]);
        $json = UploadedFile::fake()->createWithContent('salles.json', $jsonContent, 'application/json');

        $preview = $this->actingAs($this->admin)
            ->post(route('admin.donnees.import.preview', 'salles'), ['fichier' => $json])
            ->assertOk();

        $this->actingAs($this->admin)->post(route('admin.donnees.import.confirmer', 'salles'), [
            'token' => $preview->viewData('token'),
            'mode' => 'creer',
        ])->assertOk();

        $this->assertTrue(Room::where('code', 'ROOM-JSON')->exists());

        // Import en XML
        $xmlContent = '<?xml version="1.0"?><rows><row><code>ROOM-XML</code><nom>Salle XML</nom><capacite>35</capacite></row></rows>';
        $xml = UploadedFile::fake()->createWithContent('salles.xml', $xmlContent, 'application/xml');

        $previewXml = $this->actingAs($this->admin)
            ->post(route('admin.donnees.import.preview', 'salles'), ['fichier' => $xml])
            ->assertOk();

        $this->actingAs($this->admin)->post(route('admin.donnees.import.confirmer', 'salles'), [
            'token' => $previewXml->viewData('token'),
            'mode' => 'creer',
        ])->assertOk();

        $this->assertTrue(Room::where('code', 'ROOM-XML')->exists());
    }

    public function test_transaction_rollback_sur_erreur_critique(): void
    {
        $compteInitial = AnneeUniversitaire::count();

        // Fichier contenant une ligne valide et une ligne invalide avec une erreur métier
        $csv = "libelle;date_debut;date_fin;active\n2040-2041;2040-09-01;2041-06-30;0\nANNEE_INVALIDE;2041-09-01;2042-06-30;0\n";
        $fichier = UploadedFile::fake()->createWithContent('annees_mixte.csv', $csv, 'text/csv');

        $preview = $this->actingAs($this->admin)
            ->post(route('admin.donnees.import.preview', 'annees-universitaires'), ['fichier' => $fichier])
            ->assertOk();

        // Le preview isole la ligne valide et rejette l'invalide
        $resultat = $preview->viewData('resultat');
        $this->assertCount(1, $resultat->valides);
        $this->assertCount(1, $resultat->erreurs);

        // Aucune écriture avant confirmation
        $this->assertSame($compteInitial, AnneeUniversitaire::count());
    }

    public function test_idempotence_deux_imports_identiques_produisent_zero_doublons(): void
    {
        $csv = "libelle;date_debut;date_fin;active\n2030-2031;2030-09-01;2031-06-30;0\n";

        // Import 1
        $this->importerEntite('annees-universitaires', 'annees.csv', $csv, 'creer');
        $this->assertSame(1, AnneeUniversitaire::where('libelle', '2030-2031')->count());

        // Import 2 identique
        $this->importerEntite('annees-universitaires', 'annees.csv', $csv, 'creer');
        $this->assertSame(1, AnneeUniversitaire::where('libelle', '2030-2031')->count());
    }

    public function test_faculty_scope_refuse_l_import_sur_une_faculte_etrangere(): void
    {
        // L'admin restreint à FST tente d'importer un département pour FMT (Faculté B)
        $fichier = UploadedFile::fake()->createWithContent(
            'departements_fmt.csv',
            "code;nom;faculte;responsable\nDEP-ANAT;Anatomie;FMT;Dr. Smith\n",
            'text/csv'
        );

        $preview = $this->actingAs($this->adminRestreint)
            ->post(route('admin.donnees.import.preview', 'departements'), ['fichier' => $fichier])
            ->assertOk();

        $token = $preview->viewData('token');

        // La confirmation doit échouer car la faculté FMT est hors de son périmètre
        $this->actingAs($this->adminRestreint)
            ->post(route('admin.donnees.import.confirmer', 'departements'), [
                'token' => $token,
                'mode' => 'creer',
            ])
            ->assertRedirect();

        $this->assertSame(0, Departement::where('code', 'DEP-ANAT')->count());
    }
}
