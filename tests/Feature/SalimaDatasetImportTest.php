<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\User;
use App\Modules\Enseignement\Models\AffectationEnseignement;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Enseignement\Models\TypeEnseignement;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\TypeEvaluation;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\Concerns\ActAsApiClient;
use Tests\TestCase;

/**
 * Validation fonctionnelle complète du jeu de données SALIMA 2026-2027.
 * Teste l'importation de tous les fichiers réels du dossier salima_import_csv_2026_2027
 * dans l'ordre strict des dépendances LMD.
 */
class SalimaDatasetImportTest extends TestCase
{
    use ActAsApiClient, RefreshDatabase;

    private User $admin;

    private string $dossierSalima;

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

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Scolarité Salima',
            'email' => 'admin.salima@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        TypeEnseignement::firstOrCreate(
            ['code' => 'CM'],
            ['nom' => 'Cours Magistral', 'actif' => true]
        );
        TypeEnseignement::firstOrCreate(
            ['code' => 'TP'],
            ['nom' => 'Travaux Pratiques', 'actif' => true]
        );

        $this->dossierSalima = base_path('salima_import_csv_2026_2027');
    }

    /**
     * Valeurs distinctes non vides d'une colonne d'un CSV du dossier.
     *
     * @return array<int, string>
     */
    private function typesFichierDossier(string $nomFichier, string $colonne): array
    {
        $chemin = "{$this->dossierSalima}/{$nomFichier}";
        $this->assertFileExists($chemin);

        $lignes = array_map('str_getcsv', file($chemin));
        $entetes = array_map(fn ($h) => trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)), $lignes[0]);
        $index = array_search($colonne, $entetes, true);

        $this->assertNotFalse($index, "Colonne « {$colonne} » absente de {$nomFichier}.");

        $valeurs = [];

        foreach (array_slice($lignes, 1) as $ligne) {
            $valeur = trim((string) ($ligne[$index] ?? ''));

            if ($valeur !== '') {
                $valeurs[] = $valeur;
            }
        }

        return array_values(array_unique($valeurs));
    }

    private function importerContenu(string $entite, string $contenu): void
    {
        $fichier = UploadedFile::fake()->createWithContent("{$entite}.csv", $contenu, 'text/csv');

        $preview = $this->actingAs($this->admin)
            ->post(route('admin.donnees.import.preview', $entite), ['fichier' => $fichier])
            ->assertOk();

        $this->actingAs($this->admin)->post(
            route('admin.donnees.import.confirmer', $entite),
            ['token' => $preview->viewData('token'), 'mode' => 'creer']
        )->assertOk();
    }

    private function importerDepuisDossier(string $nomFichier, string $entite): void
    {
        $chemin = "{$this->dossierSalima}/{$nomFichier}";
        $this->assertFileExists($chemin, "Le fichier {$nomFichier} doit exister dans le dossier SALIMA.");

        $fichier = new UploadedFile(
            $chemin,
            $nomFichier,
            'text/csv',
            null,
            true
        );

        $preview = $this->actingAs($this->admin)
            ->post(route('admin.donnees.import.preview', $entite), ['fichier' => $fichier])
            ->assertOk();

        $token = $preview->viewData('token');
        $this->assertNotEmpty($token);

        $this->actingAs($this->admin)
            ->post(route('admin.donnees.import.confirmer', $entite), [
                'token' => $token,
                'mode' => 'creer',
            ])
            ->assertOk();
    }

    public function test_import_complet_jeu_de_donnees_salima_2026_2027(): void
    {
        // 01. Années universitaires
        $this->importerDepuisDossier('01_annees_universitaires.csv', 'annees-universitaires');
        $this->assertTrue(AnneeUniversitaire::where('libelle', '2026-2027')->exists());

        // 02. Établissements (Facultés racine)
        $this->importerDepuisDossier('02_etablissements.csv', 'facultes');
        $this->assertTrue(Faculte::where('code', 'USF')->exists());

        // 03. Facultés
        $this->importerDepuisDossier('03_facultes.csv', 'facultes');
        $this->assertTrue(Faculte::where('code', 'FSEGS')->exists());

        // 04. Départements
        $this->importerDepuisDossier('04_departements.csv', 'departements');
        $this->assertTrue(Departement::where('code', 'DEP-GEST')->exists());
        $this->assertTrue(Departement::where('code', 'DEP-INFO')->exists());

        // 05. Domaines
        $this->importerDepuisDossier('05_domaines.csv', 'domaines');
        $this->assertTrue(Domaine::where('code', 'DOM-GEST')->exists());

        // 06. Mentions
        $this->importerDepuisDossier('06_mentions.csv', 'mentions');
        $this->assertTrue(Mention::where('code', 'MEN-GEST')->exists());

        // 07. Spécialités
        $this->importerDepuisDossier('07_specialites.csv', 'specialites');
        $this->assertTrue(Specialite::where('code', 'SPEC-GEST')->exists());

        // 08. Types de Diplômes AVANT les parcours : les programmes y
        // font référence (dépendance diplôme → programme → niveau ;
        // sans cela, aucun rattachement explicite n'est devinable).
        $this->importerDepuisDossier('09_diplomes.csv', 'type-diplomes');
        $this->assertTrue(TypeDiplome::where('code', 'LIC')->exists());

        // 09. Parcours (Programmes)
        $this->importerDepuisDossier('08_parcours.csv', 'programmes');
        $this->assertTrue(Programme::where('code', 'PAR-GEST')->exists());

        // 10. Niveaux : le jeu ne contient AUCUN parcours Master.
        // L1/L2/L3 passent ; M1/M2 (diplôme MAS sans parcours cible)
        // sont refusés proprement, sans rattachement deviné.
        $this->importerContenu('niveaux', "code;libelle;ordre;diplome_code\nL1;Premiere annee Licence;1;LIC\nL2;Deuxieme annee Licence;2;LIC\nL3;Troisieme annee Licence;3;LIC\n");
        $this->assertTrue(Niveau::where('code', 'L1')->exists());
        $this->assertTrue(Niveau::where('code', 'L3')->exists());

        $fichierM = UploadedFile::fake()->createWithContent(
            'niveaux_master.csv',
            "code;libelle;ordre;diplome_code\nM1;Premiere annee Master;4;MAS\nM2;Deuxieme annee Master;5;MAS\n",
            'text/csv'
        );
        $previewM = $this->actingAs($this->admin)
            ->post(route('admin.donnees.import.preview', 'niveaux'), ['fichier' => $fichierM])
            ->assertOk();
        $this->actingAs($this->admin)->post(
            route('admin.donnees.import.confirmer', 'niveaux'),
            ['token' => $previewM->viewData('token'), 'mode' => 'creer']
        )->assertRedirect();
        $this->assertFalse(Niveau::where('code', 'M1')->exists());
        $this->assertFalse(Niveau::where('code', 'M2')->exists());

        // 11. Semestres
        $this->importerDepuisDossier('11_semestres.csv', 'semestres');
        $this->assertTrue(SemestreLmd::where('code', 'S1')->exists());

        // 12. UE
        $this->importerDepuisDossier('12_ues.csv', 'ues');
        $this->assertTrue(UniteEnseignement::where('code', 'UE-GEST-S1-01')->exists());

        // 13. ECUE — le fichier réel utilise des modalités de cours
        // (CM/CM_TD/TD/TP/CM_TP) : l'établissement les déclare au
        // référentiel configurable (prérequis LOT 2, sans code en dur).
        foreach ($this->typesFichierDossier('13_ecues.csv', 'type') as $codeType) {
            TypeEvaluation::updateOrCreate(
                ['code' => $codeType],
                ['libelle' => $codeType, 'actif' => true]
            );
        }
        $this->importerDepuisDossier('13_ecues.csv', 'ecues');
        $this->assertTrue(Matiere::where('code', 'ECUE-GEST-01')->exists());

        // 14. Matières
        $this->importerDepuisDossier('14_matieres.csv', 'matieres');
        $this->assertTrue(Matiere::where('code', 'MAT-GEST-01')->exists());

        // 15. Enseignants
        $this->importerDepuisDossier('15_enseignants.csv', 'enseignants');
        $this->assertTrue(Teacher::where('matricule', 'ENS-0001')->exists());

        // 17. Salles
        $this->importerDepuisDossier('17_salles.csv', 'salles');
        $this->assertTrue(Room::where('code', 'SAL-A101')->exists());

        // 18. Groupes
        $this->importerDepuisDossier('18_groupes.csv', 'groupes');
        $this->assertTrue(Group::where('code', 'G-GEST-L1-01')->exists());

        // 19. Étudiants
        $this->importerDepuisDossier('19_etudiants.csv', 'etudiants');
        $this->assertTrue(Etudiant::where('cin', '12345678')->exists());

        // 20. Inscriptions académiques
        $this->importerDepuisDossier('20_inscriptions.csv', 'inscriptions-academiques');
        $this->assertTrue(InscriptionAcademique::whereHas('etudiant', fn ($q) => $q->where('cin', '12345678'))->exists());

        // 21. Enseignements
        $this->importerDepuisDossier('21_enseignements.csv', 'enseignements');
        $this->assertTrue(Enseignement::whereHas('matiere', fn ($q) => $q->where('code', 'ECUE-GEST-01'))->exists());

        // 22. Affectations enseignants
        $this->importerDepuisDossier('22_affectations_enseignants.csv', 'affectations-enseignants');
        $this->assertTrue(AffectationEnseignement::exists());

        // 23. Affectations groupes (variante enseignement↔groupe : lie
        // les enseignements aux groupes, ne pose pas etudiants.groupe_id
        // — corrigé LOT 2 : l'ancienne assertion ne pouvait pas passer).
        $this->importerDepuisDossier('23_affectations_groupes.csv', 'affectations-groupes');
        $enseignement = Enseignement::where('code', 'ENS-2026-GEST-01')->firstOrFail();
        $this->assertTrue($enseignement->groupes()->where('groups.code', 'G-GEST-L1-01')->exists());

        // 26. Évaluations (le fichier contient des évaluations : crée
        // session + examen + épreuve — corrigé LOT 2, l'ancien mapping
        // vers `sessions-examen` ne correspondait pas au fichier).
        $this->importerDepuisDossier('26_evaluations.csv', 'evaluations');
        $this->assertTrue(Examen::where('code', 'EVAL-0001')->exists());

        // 27. Examens / Épreuves planifiées
        $this->importerDepuisDossier('27_examens.csv', 'examens');
        $this->assertTrue(Epreuve::where('code', 'EXAM-0001')->exists());

        // 28. Notes
        $this->importerDepuisDossier('28_notes.csv', 'notes');
        $this->assertTrue(Note::where('note', 14.5)->exists());

        // 29. Délibérations
        $this->importerDepuisDossier('29_deliberations.csv', 'deliberations');

        // 30. Décisions de jury
        $this->importerDepuisDossier('30_decisions_jury.csv', 'decisions-jury');
        $this->assertTrue(DecisionDeliberation::where('decision', 'admis')->exists());
    }
}
